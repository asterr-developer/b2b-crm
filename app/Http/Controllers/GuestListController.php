<?php

namespace App\Http\Controllers;

use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GuestListController extends Controller
{
    public function show(Request $request, int $allocation)
    {
        [$group, $rooms, $anchor] = $this->booking($request->user(), $allocation);
        $versions = DB::table('hotel_guest_lists as lists')->leftJoin('users', 'users.id', '=', 'lists.created_by')
            ->where('lists.allocation_id', $anchor)->orderByDesc('lists.version')->get(['version', 'lists.created_at', 'users.name']);
        $version = $request->validate(['version' => ['nullable', 'integer', 'min:1']])['version'] ?? $versions->first()?->version;
        $current = $version === null ? null : DB::table('hotel_guest_lists')->where('allocation_id', $anchor)->where('version', $version)->first();
        abort_if($version !== null && ! $current, 404);

        return Inertia::render('GuestList', [
            'booking' => ['id' => $anchor, 'title' => $group->title, 'guests' => $group->guests, 'arrival_on' => $group->arrival_on, 'departure_on' => $group->departure_on],
            'rooms' => $rooms, 'versions' => $versions, 'selectedVersion' => $current?->version,
            'guests' => $current ? json_decode($current->guests, true, flags: JSON_THROW_ON_ERROR) : [],
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, int $allocation)
    {
        [$group, $rooms, $anchor] = $this->booking($request->user(), $allocation);
        $csv = $request->validate(['csv' => ['required', 'string', 'max:150000']])['csv'];

        return response()->json([
            'guests' => $this->parse($csv, $rooms->pluck('rooms', 'id')->all(), (int) $group->guests),
            'version' => (int) DB::table('hotel_guest_lists')->where('allocation_id', $anchor)->max('version'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $allocation)
    {
        $data = $request->validate(['csv' => ['required', 'string', 'max:150000'], 'version' => ['required', 'integer', 'min:0']]);
        $user = $request->user();
        $anchor = DB::transaction(function () use ($user, $data, $allocation) {
            [$group, $rooms, $anchor] = $this->booking($user, $allocation, true);
            $guests = $this->parse($data['csv'], $rooms->pluck('rooms', 'id')->all(), (int) $group->guests);
            $latest = DB::table('hotel_guest_lists')->where('allocation_id', $anchor)->latest('version')->first();
            if ($latest && json_decode($latest->guests, true, flags: JSON_THROW_ON_ERROR) === $guests) {
                return $anchor;
            }
            if ((int) ($latest?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['csv' => 'Список уже обновили. Повторите предпросмотр перед сохранением.']);
            }
            $version = (int) ($latest?->version ?? 0) + 1;
            DB::table('hotel_guest_lists')->insert([
                'allocation_id' => $anchor, 'version' => $version, 'guests' => json_encode($guests, JSON_THROW_ON_ERROR),
                'created_by' => $user->id, 'created_at' => now(),
            ]);
            SalesEvent::record($user, 'hotel.guest_list_saved', ['version' => $version, 'count' => count($guests)], $group->deal_id, $group->id);

            return $anchor;
        }, 5);

        return to_route('hospitality.guests', $anchor);
    }

    private function booking(User $user, int $allocation, bool $lock = false): array
    {
        $row = DB::table('hotel_allocations')->find($allocation);
        abort_unless($row, 404);
        $allowed = $user->worksInLodging() ? GroupRequest::where('department_id', $user->department_id) : GroupRequest::visibleTo($user);
        $group = $allowed->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($row->group_request_id);
        $rooms = DB::table('hotel_allocations as allocation')->join('hotel_room_types as category', 'category.id', '=', 'allocation.hotel_room_type_id')
            ->where('allocation.group_request_id', $group->id)->where('allocation.status', 'booked')
            ->where('allocation.departure_on', '>', today()->toDateString())->orderBy('allocation.id')
            ->get(['allocation.id', 'allocation.rooms', 'category.property', 'category.name']);
        abort_unless($rooms->contains('id', $allocation), 404);

        return [$group, $rooms, $rooms->last()->id];
    }

    private function parse(string $csv, array $rooms, int $maximum): array
    {
        if (! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw ValidationException::withMessages(['csv' => 'Сохраните CSV в кодировке UTF-8.']);
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $file = fopen('php://temp', 'w+');
        fwrite($file, $csv);
        rewind($file);
        try {
            $header = fgetcsv($file, escape: '');
            if ($header !== ['name', 'allocation', 'room']) {
                throw ValidationException::withMessages(['csv' => 'Первая строка должна содержать name,allocation,room.']);
            }
            $guests = [];
            $line = 1;
            while (($row = fgetcsv($file, escape: '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                [$name, $allocation, $room] = array_pad(array_map(fn ($column) => trim((string) $column), $row), 3, '');
                if (count($row) !== 3 || $name === '' || mb_strlen($name) > 160 || preg_match('/[\p{Cc}\p{Cf}]/u', $name)
                    || ! isset($rooms[$allocation]) || ! ctype_digit($room) || (int) $room < 1 || (int) $room > $rooms[$allocation]) {
                    throw ValidationException::withMessages(['csv' => 'Запись '.$line.': проверьте имя, позицию брони и номер комнаты в пределах позиции.']);
                }
                $guests[] = ['name' => $name, 'allocation' => (int) $allocation, 'room' => (int) $room];
                if (count($guests) > $maximum) {
                    throw ValidationException::withMessages(['csv' => 'Число гостей в CSV превышает число гостей в брони.']);
                }
            }
            if ($guests === []) {
                throw ValidationException::withMessages(['csv' => 'Добавьте хотя бы одного гостя.']);
            }

            return $guests;
        } finally {
            fclose($file);
        }
    }
}
