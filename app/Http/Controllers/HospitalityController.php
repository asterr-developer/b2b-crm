<?php

namespace App\Http\Controllers;

use App\Models\GroupRequest;
use App\Models\Department;
use App\Services\HotelInventory;
use App\Services\IntakeSuggestions;
use App\Services\ProposalDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class HospitalityController extends Controller
{
    public function index(Request $request, HotelInventory $inventory)
    {
        $user = $request->user();
        $dates = $request->validate([
            'arrival' => ['nullable', 'date_format:Y-m-d', 'required_with:departure'],
            'departure' => ['nullable', 'date_format:Y-m-d', 'required_with:arrival', 'after:arrival'],
            'calendar_category' => ['nullable', 'integer', 'min:1'],
        ]);
        if (isset($dates['arrival'], $dates['departure']) && CarbonImmutable::parse($dates['arrival'])->diffInDays($dates['departure']) > 366) {
            throw ValidationException::withMessages(['departure' => 'Выберите период до 366 ночей.']);
        }

        $lodging = $user->worksInLodging();
        if (! $lodging && empty($dates['arrival']) && empty($dates['departure'])) {
            $dates['arrival'] = today()->toDateString();
            $dates['departure'] = today()->addDays(7)->toDateString();
        }
        $categories = $lodging ? [] : DB::table('hotel_room_types')->where('department_id', $user->department_id)->orderBy('property')->orderBy('name')->get();
        $calendar = [];
        if (! $lodging && isset($dates['arrival'], $dates['departure'])) {
            $selected = (int) ($dates['calendar_category'] ?? $categories->first()?->id);
            foreach ($categories as $category) {
                $nights = $inventory->calendar($category, $dates['arrival'], $dates['departure']);
                $category->available = min(array_column($nights, 'available'));
                $category->limiting_night = collect($nights)->firstWhere('available', $category->available)['night'];
                if ($category->id === $selected) {
                    $calendar = $nights;
                }
            }
        }
        $visible = $lodging ? GroupRequest::where('department_id', $user->department_id) : GroupRequest::visibleTo($user);
        $recent = DB::table('hotel_allocations')->whereIn('group_request_id', (clone $visible)->select('id'))
            ->orderByDesc('id')->limit(100)->select('id');
        $allocations = DB::table('hotel_allocations as allocation')
            ->join('group_requests as groups', 'groups.id', '=', 'allocation.group_request_id')
            ->leftJoin('deals', 'deals.id', '=', 'groups.deal_id')
            ->join('hotel_room_types as category', 'category.id', '=', 'allocation.hotel_room_type_id')
            ->whereIn('allocation.group_request_id', (clone $visible)->select('id'))
            ->when($lodging, fn ($query) => $query->where('allocation.status', 'booked')->where('allocation.departure_on', '>', today()->toDateString()))
            ->where(fn ($query) => $query->whereIn('allocation.id', $recent)
                ->orWhere(fn ($active) => $active->where('allocation.departure_on', '>', today()->toDateString())
                    ->where(fn ($status) => $status->where('allocation.status', 'booked')
                        ->orWhere(fn ($hold) => $hold->where('allocation.status', 'held')->where('allocation.expires_at', '>', now())))))
            ->orderByDesc('allocation.id')
            ->get(['allocation.*', 'groups.title', 'groups.deal_id', 'groups.guests', 'deals.closed_at as deal_closed_at', 'category.property', 'category.name']);

        return Inertia::render('Hospitality', [
            'categories' => $categories,
            'calendar' => $calendar,
            'allocations' => $allocations,
            'lodging' => $lodging,
            'handoffs' => DB::table('hospitality_runs')->where('department_id', $user->department_id)
                ->where('action', 'hotel.handoff_review')->whereIn('reference', $allocations->where('status', 'booked')->pluck('id')->map(fn ($id) => (string) $id))
                ->orderBy('id')->get(['reference', 'status', 'message'])->keyBy('reference'),
            'requests' => $lodging ? [] : $visible->whereDate('arrival_on', '>=', today())->whereNotNull('departure_on')
                ->where(fn ($query) => $query->whereNull('deal_id')->orWhereNotIn('deal_id', DB::table('deals')->whereNotNull('closed_at')->select('id')))
                ->latest('id')->limit(100)->get(['id', 'title', 'arrival_on', 'departure_on']),
            'dates' => $dates,
            'rules' => HotelInventory::rules($user->department_id),
            'canManage' => $user->leadsSalesDepartment(),
            'runs' => $user->leadsSalesDepartment() ? DB::table('hospitality_runs')->where('department_id', $user->department_id)->where('action', '!=', 'mailbox.sync')->latest('id')->limit(60)->get() : [],
        ]);
    }

    public function capacity(Request $request, HotelInventory $inventory)
    {
        abort_unless($request->user()->leadsSalesDepartment(), 403);
        $data = $request->validate([
            'category' => ['required', 'integer', 'min:1'],
            'night' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'capacity' => ['present', 'nullable', 'integer', 'between:0,10000'],
            'reason' => ['required', 'string', 'max:160'],
        ]);
        $inventory->setCapacity($request->user(), $data);

        return back();
    }

    public function rules(Request $request)
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate(['hold_hours' => ['required', 'integer', 'between:1,72'], 'handoff_task' => ['required', 'boolean']]);
        DB::transaction(function () use ($user, $data) {
            $department = Department::lockForUpdate()->findOrFail($user->department_id);
            $department->forceFill(['pipeline_settings' => [...($department->pipeline_settings ?? []), 'hospitality' => $data]])->save();
            app(HotelInventory::class)->log($department->id, 'hotel.rules', (string) $user->id, 'done');
        });

        return to_route('hospitality.index');
    }

    public function category(Request $request)
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate([
            'property' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', Rule::unique('hotel_room_types')->where('department_id', $user->department_id)->where('property', $request->input('property'))],
            'capacity' => ['required', 'integer', 'between:1,10000'],
        ]);
        DB::table('hotel_room_types')->insert([...$data, 'department_id' => $user->department_id]);

        return to_route('hospitality.index');
    }

    public function reserve(Request $request, HotelInventory $inventory)
    {
        $data = $request->validate([
            'group' => ['required', 'integer'], 'category' => ['required_without:positions', 'integer'],
            'rooms' => ['required_without:positions', 'integer', 'between:1,10000'], 'hours' => ['required', 'integer', 'between:1,72'],
            'positions' => ['sometimes', 'required', 'array', 'list', 'between:1,20'],
            'positions.*.category' => ['required', 'integer', 'distinct'],
            'positions.*.rooms' => ['required', 'integer', 'between:1,10000'],
        ]);
        $inventory->reserve($request->user(), $data['group'], $data);

        return to_route('hospitality.index');
    }

    public function amend(Request $request, int $allocation, HotelInventory $inventory)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['propose', 'move', 'cancel'])],
            'proposal_id' => ['exclude_unless:action,move', 'required', 'integer', 'min:1'],
            'reason' => ['exclude_if:action,move', 'required', 'string', 'max:500'],
            'arrival_on' => ['exclude_unless:action,propose', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'departure_on' => ['exclude_unless:action,propose', 'required', 'date_format:Y-m-d', 'after:arrival_on'],
            'guests' => ['exclude_unless:action,propose', 'required', 'integer', 'between:1,10000'],
            'amount' => ['exclude_unless:action,propose', 'required', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'positions' => ['exclude_unless:action,propose', 'required', 'array', 'list', 'between:1,20'],
            'positions.*.category' => ['exclude_unless:action,propose', 'required', 'integer', 'distinct'],
            'positions.*.rooms' => ['exclude_unless:action,propose', 'required', 'integer', 'between:1,10000'],
        ]);
        if ($data['action'] === 'propose' && CarbonImmutable::parse($data['arrival_on'])->diffInDays($data['departure_on']) > 366) {
            throw ValidationException::withMessages(['departure_on' => 'Выберите период до 366 ночей.']);
        }
        $inventory->amend($request->user(), $allocation, $data);

        if ($data['action'] === 'propose') {
            $deal = DB::table('hotel_allocations as allocation')->join('group_requests as groups', 'groups.id', '=', 'allocation.group_request_id')
                ->where('allocation.id', $allocation)->value('groups.deal_id');

            return to_route('deals.show', $deal);
        }
        return to_route('hospitality.index');
    }

    public function allocation(Request $request, int $allocation, HotelInventory $inventory)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['book', 'release'])]]);
        $inventory->change($request->user(), $allocation, $data['action']);

        return to_route('hospitality.index');
    }

    public function handoff(Request $request, int $allocation, HotelInventory $inventory)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'returned'])],
            'reason' => ['exclude_unless:decision,returned', 'required', 'string', 'max:255'],
        ]);
        $inventory->handoff($request->user(), $allocation, $data['decision'], $data['reason'] ?? null);

        return to_route('hospitality.index');
    }

    public function suggestions(Request $request, IntakeSuggestions $suggestions)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        return response()->json($suggestions->extract($data['text']));
    }

    public function email(Request $request, int $deal, int $proposal, ProposalDelivery $delivery)
    {
        $request->validate(['retry' => ['sometimes', 'boolean']]);
        $delivery->enqueue($request->user(), $deal, $proposal, $request->boolean('retry'));

        return to_route('deals.show', $deal);
    }
}
