<?php

namespace App\Services;

use App\Models\GroupRequest;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HotelInventory
{
    public static function rules(?int $department): array
    {
        $settings = $department === null ? [] : (Department::find($department)?->pipeline_settings ?? []);

        return ($settings['hospitality'] ?? []) + ['hold_hours' => 24, 'handoff_task' => true];
    }

    public function calendar(object $category, string $arrival, string $departure, array $excluded = []): array
    {
        $active = DB::table('hotel_allocations')->where('hotel_room_type_id', $category->id)
            ->whereNotIn('id', $excluded)->where('arrival_on', '<', $departure)->where('departure_on', '>', $arrival)
            ->where(fn ($query) => $query->where('status', 'booked')->orWhere(fn ($hold) => $hold
                ->where('status', 'held')->where('expires_at', '>', now())))->get();
        $overrides = DB::table('hotel_capacity_overrides')->where('hotel_room_type_id', $category->id)
            ->where('night', '>=', $arrival)->where('night', '<', $departure)->get()->keyBy('night');
        $changes = [];
        foreach ($active as $allocation) {
            $start = max($arrival, $allocation->arrival_on);
            $end = min($departure, $allocation->departure_on);
            $changes[$start] = ($changes[$start] ?? 0) + $allocation->rooms;
            $changes[$end] = ($changes[$end] ?? 0) - $allocation->rooms;
        }
        $nights = [];
        $used = 0;
        for ($night = CarbonImmutable::parse($arrival); $night->toDateString() < $departure; $night = $night->addDay()) {
            $date = $night->toDateString();
            $used += $changes[$date] ?? 0;
            $override = $overrides->get($date);
            $capacity = (int) ($override->capacity ?? $category->capacity);
            $nights[] = ['night' => $date, 'capacity' => $capacity, 'used' => $used, 'available' => max(0, $capacity - $used), 'adjusted' => $override !== null];
        }

        return $nights;
    }

    public function setCapacity(User $user, array $data): void
    {
        abort_unless($user->leadsSalesDepartment(), 403);
        DB::transaction(function () use ($user, $data) {
            $category = DB::table('hotel_room_types')->where('department_id', $user->department_id)->lockForUpdate()->find($data['category']);
            abort_unless($category, 404);
            $night = $data['night'];
            $capacity = $data['capacity'] ?? (int) $category->capacity;
            $row = $this->calendar($category, $night, CarbonImmutable::parse($night)->addDay()->toDateString())[0];
            if ($capacity < $row['used']) {
                throw ValidationException::withMessages(['capacity' => 'На эту ночь уже занято '.$row['used'].' ном. Сначала измените действующие брони или резервы.']);
            }
            $key = ['hotel_room_type_id' => $category->id, 'night' => $night];
            if ($data['capacity'] === null) {
                DB::table('hotel_capacity_overrides')->where($key)->delete();
            } else {
                DB::table('hotel_capacity_overrides')->updateOrInsert($key, ['capacity' => $capacity, 'reason' => $data['reason']]);
            }
            SalesEvent::record($user, 'hotel.capacity_updated', [...$key, 'previous_capacity' => $row['capacity'], 'capacity' => $capacity, 'reason' => $data['reason']]);
            $this->log($user->department_id, 'hotel.capacity_updated', (string) $category->id, 'done', $night.' · '.$capacity.' ном. · '.$data['reason']);
        }, 5);
    }

    public function reserve(User $user, int $groupId, array $data): void
    {
        DB::transaction(function () use ($user, $groupId, $data) {
            $group = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($groupId);
            if ($group->deal_id && Deal::whereKey($group->deal_id)->lockForUpdate()->firstOrFail()->closed_at) {
                throw ValidationException::withMessages(['rooms' => 'Сделка по заявке уже закрыта.']);
            }
            if (! $group->arrival_on || ! $group->departure_on || $group->arrival_on < today()->toDateString()
                || CarbonImmutable::parse($group->arrival_on)->diffInDays($group->departure_on) > 366) {
                throw ValidationException::withMessages(['rooms' => 'Уточните даты проживания. Резерв доступен для будущего проживания до 366 ночей.']);
            }
            if (DB::table('hotel_allocations')->where('group_request_id', $groupId)
                ->where(fn ($query) => $query->where('status', 'booked')->orWhere(fn ($hold) => $hold
                    ->where('status', 'held')->where('expires_at', '>', now())))->exists()) {
                throw ValidationException::withMessages(['rooms' => 'У заявки уже есть действующий резерв или бронь.']);
            }
            $positions = $data['positions'] ?? [['category' => $data['category'], 'rooms' => $data['rooms']]];
            $categories = $this->lockCategories($group, $positions);
            $this->checkCapacity($categories, $positions, $group->arrival_on, $group->departure_on);
            $ids = $this->allocate($group, $positions, $group->arrival_on, $group->departure_on, 'held', now()->addHours($data['hours']));
            SalesEvent::record($user, 'hotel.held', ['allocation_id' => $ids[0], 'allocation_ids' => $ids], $group->deal_id, $groupId);
        }, 5);
    }

    public function change(User $user, int $id, string $action): void
    {
        DB::transaction(function () use ($user, $id, $action) {
            $allocation = DB::table('hotel_allocations')->find($id);
            abort_unless($allocation, 404);
            $group = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($allocation->group_request_id);
            $deal = $action === 'book' && $group->deal_id ? Deal::visibleTo($user)->lockForUpdate()->find($group->deal_id) : null;
            $held = DB::table('hotel_allocations')->where('group_request_id', $group->id)
                ->where('status', 'held')->where('expires_at', '>', now())->orderBy('id')->get();
            $this->lockCategories($group, [], $held->pluck('hotel_room_type_id')->all());
            $allocation = DB::table('hotel_allocations')->lockForUpdate()->find($id);
            if ($allocation->status === 'booked' && $action === 'book' && $allocation->confirmed_proposal_id !== null) {
                return;
            }
            $legacy = $action === 'book' && $allocation->status === 'booked';
            if (! $legacy && ($allocation->status !== 'held' || $allocation->expires_at <= now()->toDateTimeString())) {
                throw ValidationException::withMessages(['allocation' => 'Резерв больше не действует.']);
            }
            if ($action === 'book') {
                $proposal = $deal ? Proposal::where('deal_id', $deal->id)->latest('version')->first() : null;
                if (! $deal || in_array($deal->stage, ['won', 'lost'], true) || $proposal?->customer_decision !== 'accepted'
                    || isset($proposal->snapshot['booking_change'])) {
                    throw ValidationException::withMessages(['allocation' => 'Зафиксируйте согласие клиента по актуальной версии предложения.']);
                }
                if ($allocation->arrival_on !== $group->arrival_on || $allocation->departure_on !== $group->departure_on) {
                    throw ValidationException::withMessages(['allocation' => 'Даты заявки изменились. Освободите резерв и создайте новый.']);
                }
                if ($legacy) {
                    DB::table('hotel_allocations')->where('group_request_id', $group->id)->where('status', 'booked')
                        ->update(['confirmed_proposal_id' => $proposal->id]);
                } elseif (self::rules($group->department_id)['handoff_task']) {
                    DealActivity::firstOrCreate(['automation_key' => 'hotel-handoff:'.$held->first()->id], [
                        'deal_id' => $deal->id, 'kind' => 'task',
                        'body' => 'Передать подтверждённую бронь службе размещения',
                        'due_at' => now()->addDay()->startOfDay()->min(CarbonImmutable::parse($allocation->arrival_on)->endOfDay()),
                    ]);
                    $this->log($group->department_id, 'hotel.handoff', (string) $id, 'done');
                }
            }
            DB::table('hotel_allocations')->whereIn('id', $held->pluck('id'))->update($action === 'book'
                ? ['status' => 'booked', 'confirmed_proposal_id' => $proposal->id]
                : ['status' => 'released']);
            SalesEvent::record($user, 'hotel.'.$action, ['allocation_id' => $id, 'proposal_id' => $proposal->id ?? null], $group->deal_id, $group->id);
            $this->log($group->department_id, 'hotel.'.$action, (string) $id, 'done');
        }, 5);
    }

    public function amend(User $user, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $id, $data) {
            $anchor = DB::table('hotel_allocations')->find($id);
            abort_unless($anchor, 404);
            $group = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($anchor->group_request_id);
            $deal = Deal::visibleTo($user)->lockForUpdate()->findOrFail($group->deal_id);
            $booked = DB::table('hotel_allocations')->where('group_request_id', $group->id)->where('status', 'booked')->orderBy('id')->get();
            $ids = $booked->pluck('id')->all();
            $proposal = Proposal::where('deal_id', $deal->id)->latest('version')->first();
            $terms = $proposal?->snapshot['booking_change'] ?? null;
            if ($data['action'] === 'move' && $proposal?->id !== (int) $data['proposal_id']) {
                throw ValidationException::withMessages(['action' => 'Версия КП изменилась. Обновите страницу.']);
            }
            if ($data['action'] === 'move' && $terms
                && DB::table('hotel_allocations')->where('group_request_id', $group->id)->where('status', 'booked')->where('confirmed_proposal_id', $proposal->id)->exists()
                && in_array($id, $terms['booking_ids'])) {
                return;
            }
            if (! in_array($id, $ids) || $booked->first()->arrival_on < today()->toDateString()) {
                throw ValidationException::withMessages(['action' => 'Бронь уже изменена или проживание началось. Обновите страницу.']);
            }
            if ($data['action'] === 'propose' || $data['action'] === 'move') {
                if ($deal->stage === 'lost') {
                    throw ValidationException::withMessages(['action' => 'Сделка проиграна.']);
                }
            }
            if ($data['action'] === 'propose') {
                $this->proposeMove($user, $group, $deal, $ids, $booked->pluck('hotel_room_type_id')->all(), $proposal, $data);

                return;
            }
            $move = $data['action'] === 'move';
            if ($move) {
                if (! $terms || $terms['booking_ids'] !== array_map('intval', $ids) || $proposal->customer_decision !== 'accepted') {
                    throw ValidationException::withMessages(['action' => 'Нужно согласие клиента на актуальное КП по переносу этой брони.']);
                }
                if ($terms['arrival_on'] < today()->toDateString()) {
                    throw ValidationException::withMessages(['action' => 'Новая дата заезда уже прошла.']);
                }
                $data = [...$terms, 'action' => 'move'];
            } else {
                $drafts = Proposal::where('deal_id', $deal->id)->whereNotNull('snapshot->booking_change');
                if (DB::table('proposal_deliveries')->where('status', 'sending')->whereIn('proposal_id', (clone $drafts)->select('id'))->exists()) {
                    throw ValidationException::withMessages(['action' => 'Дождитесь завершения отправки КП по переносу.']);
                }
                DealActivity::whereIn('automation_key', (clone $drafts)->pluck('id')->map(fn ($proposalId) => 'proposal-follow-up:'.$proposalId))
                    ->whereNull('completed_at')->update(['completed_at' => now()]);
                $drafts->whereIn('status', ['pending', 'approved'])->update(['status' => 'superseded']);
            }
            $positions = $data['positions'] ?? [];
            $categories = $this->lockCategories($group, $positions, $booked->pluck('hotel_room_type_id')->all());
            if ($move) {
                $this->checkCapacity($categories, $positions, $data['arrival_on'], $data['departure_on'], $ids);
            }
            DB::table('hotel_allocations')->whereIn('id', $ids)->update(['status' => $move ? 'replaced' : 'cancelled']);
            $replacement = $move ? $this->allocate($group, $positions, $data['arrival_on'], $data['departure_on'], 'booked', now()) : [];
            $previousAmount = $deal->amount;
            if ($move) {
                DB::table('hotel_allocations')->whereIn('id', $replacement)->update(['confirmed_proposal_id' => $proposal->id]);
                $group->update(['arrival_on' => $data['arrival_on'], 'departure_on' => $data['departure_on'], 'guests' => $data['guests']]);
                $deal->increment('revision', 1, ['amount' => bcdiv((string) $proposal->snapshot['total_minor'], '100', 2)]);
            }
            DealActivity::whereIn('automation_key', array_map(fn ($old) => 'hotel-handoff:'.$old, $ids))
                ->whereNull('completed_at')->update(['completed_at' => now()]);
            if (self::rules($group->department_id)['handoff_task']) {
                DealActivity::create([
                    'deal_id' => $deal->id, 'kind' => 'task', 'due_at' => now(),
                    'automation_key' => $move ? 'hotel-handoff:'.$replacement[0] : 'hotel-amend:'.$ids[0],
                    'body' => $move ? 'Передать службе размещения новые даты и состав брони' : 'Сообщить службе размещения об отмене брони',
                ]);
            }
            SalesEvent::record($user, 'hotel.'.$data['action'], [
                'previous_ids' => $ids, 'replacement_ids' => $replacement, 'reason' => $data['reason'],
                'previous_arrival' => $booked->first()->arrival_on, 'previous_departure' => $booked->first()->departure_on,
                ...($move ? ['arrival_on' => $data['arrival_on'], 'departure_on' => $data['departure_on'], 'guests' => $data['guests'],
                    'proposal_id' => $proposal->id, 'previous_amount' => $previousAmount, 'total_minor' => $proposal->snapshot['total_minor']] : []),
            ], $deal->id, $group->id);
            $this->log($group->department_id, 'hotel.'.$data['action'], (string) $id, 'done');
        }, 5);
    }

    private function proposeMove(User $user, GroupRequest $group, Deal $deal, array $ids, array $existing, ?Proposal $latest, array $data): void
    {
        $positions = array_map(fn ($position) => ['category' => (int) $position['category'], 'rooms' => (int) $position['rooms']], $data['positions']);
        usort($positions, fn ($left, $right) => $left['category'] <=> $right['category']);
        $categories = $this->lockCategories($group, $positions, $existing);
        $this->checkCapacity($categories, $positions, $data['arrival_on'], $data['departure_on'], $ids);
        $terms = [
            'booking_ids' => array_map('intval', $ids), 'arrival_on' => $data['arrival_on'], 'departure_on' => $data['departure_on'],
            'guests' => (int) $data['guests'], 'positions' => $positions, 'reason' => $data['reason'],
        ];
        $amount = (int) bcmul((string) $data['amount'], '100');
        if ($latest && ($latest->snapshot['booking_change'] ?? null) == $terms && $latest->snapshot['total_minor'] === $amount
            && ! in_array($latest->status, ['rejected', 'superseded'], true) && ! in_array($latest->customer_decision, ['declined', 'changes_requested'], true)) {
            return;
        }
        $proposals = Proposal::where('deal_id', $deal->id);
        if (DB::table('proposal_deliveries')->where('status', 'sending')->whereIn('proposal_id', (clone $proposals)->select('id'))->exists()) {
            throw ValidationException::withMessages(['action' => 'Дождитесь завершения отправки текущего КП.']);
        }
        if ($latest && isset($latest->snapshot['booking_change'])) {
            DealActivity::where('automation_key', 'proposal-follow-up:'.$latest->id)
                ->whereNull('completed_at')->update(['completed_at' => now()]);
        }
        $proposals->whereIn('status', ['pending', 'approved'])->update(['status' => 'superseded']);
        $version = ($latest?->version ?? 0) + 1;
        Proposal::create([
            'deal_id' => $deal->id, 'version' => $version, 'status' => 'pending', 'created_by' => $user->id,
            'snapshot' => [
                'booking_change' => $terms,
                'stay_rooms' => array_map(fn ($position) => [
                    'name' => $categories[$position['category']]->property.' · '.$categories[$position['category']]->name,
                    'rooms' => $position['rooms'],
                ], $positions),
                'company' => $deal->contact->company->name, 'title' => $deal->title, 'recipient_email' => $deal->contact->email,
                'items' => [['name' => 'Размещение по новым условиям', 'quantity' => 1, 'price_minor' => $amount]],
                'discount' => 0, 'subtotal_minor' => $amount, 'total_minor' => $amount,
            ],
        ]);
        SalesEvent::record($user, 'proposal.created', ['version' => $version, 'booking_change' => true], $deal->id, $group->id);
    }

    private function lockCategories(GroupRequest $group, array $positions, array $existing = []): \Illuminate\Support\Collection
    {
        $ids = array_unique([...array_column($positions, 'category'), ...$existing]);
        $categories = DB::table('hotel_room_types')->where('department_id', $group->department_id)
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($categories->count() === count($ids), 404);

        return $categories;
    }

    public function handoff(User $actor, int $allocationId, string $decision, ?string $reason): void
    {
        abort_unless($actor->leadsSalesDepartment() || $actor->worksInLodging(), 403);
        DB::transaction(function () use ($actor, $allocationId, $decision, $reason) {
            $allocation = DB::table('hotel_allocations')->find($allocationId);
            abort_unless($allocation, 404);
            $group = GroupRequest::where('department_id', $actor->department_id)->lockForUpdate()->findOrFail($allocation->group_request_id);
            $deal = $group->deal_id ? Deal::where('department_id', $actor->department_id)->lockForUpdate()->findOrFail($group->deal_id) : null;
            $booking = DB::table('hotel_allocations')->where('group_request_id', $group->id)->where('status', 'booked');
            if (! $deal || ! (clone $booking)->where('id', $allocationId)->exists()) {
                throw ValidationException::withMessages(['decision' => 'Бронь уже изменилась. Обновите страницу.']);
            }
            $reference = (string) (clone $booking)->max('id');
            $prior = DB::table('hospitality_runs')->where('department_id', $group->department_id)
                ->where('action', 'hotel.handoff_review')->where('reference', $reference)->latest('id')->first();
            if ($prior && $prior->status === $decision && $prior->message === $reason) {
                return;
            }
            $taskKey = 'hotel-handoff:'.$booking->min('id');
            if ($decision === 'accepted') {
                DealActivity::where('automation_key', $taskKey)->whereNull('completed_at')->update(['completed_at' => now()]);
            } else {
                DealActivity::updateOrCreate(['automation_key' => $taskKey], [
                    'deal_id' => $deal->id, 'kind' => 'task', 'body' => 'Уточнить данные брони после возврата службой размещения',
                    'due_at' => now(), 'completed_at' => null,
                ]);
            }
            $this->log($group->department_id, 'hotel.handoff_review', $reference, $decision, $reason);
            SalesEvent::record($actor, 'hotel.handoff_'.$decision, ['allocation_id' => (int) $reference, 'reason' => $reason], $deal->id, $group->id);
        }, 5);
    }

    private function checkCapacity($categories, array $positions, string $arrival, string $departure, array $excluded = []): void
    {
        foreach ($positions as $position) {
            $category = $categories[$position['category']];
            foreach ($this->calendar($category, $arrival, $departure, $excluded) as $night) {
                if ($position['rooms'] > $night['available']) {
                    throw ValidationException::withMessages(['rooms' => 'На одну или несколько ночей не хватает номеров. '.$category->name.' · '.$night['night'].': доступно '.$night['available'].'.']);
                }
            }
        }
    }

    private function allocate(GroupRequest $group, array $positions, string $arrival, string $departure, string $status, $expires): array
    {
        $ids = [];
        foreach ($positions as $position) {
            $ids[] = DB::table('hotel_allocations')->insertGetId([
                'hotel_room_type_id' => $position['category'], 'group_request_id' => $group->id, 'rooms' => $position['rooms'],
                'arrival_on' => $arrival, 'departure_on' => $departure, 'status' => $status,
                'expires_at' => $expires, 'created_at' => now(),
            ]);
        }

        return $ids;
    }

    public function expire(): void
    {
        DB::table('hotel_allocations')->where('status', 'held')->where('expires_at', '<=', now())->eachById(function ($row) {
            DB::transaction(function () use ($row) {
                $category = DB::table('hotel_room_types')->lockForUpdate()->find($row->hotel_room_type_id);
                $changed = DB::table('hotel_allocations')->where('id', $row->id)->where('status', 'held')->update(['status' => 'expired']);
                if ($changed) {
                    $this->log($category->department_id, 'hotel.expired', (string) $row->id, 'done');
                }
            }, 3);
        });
    }

    public function log(int $department, string $action, string $reference, string $status, ?string $message = null): void
    {
        DB::table('hospitality_runs')->insert([
            'department_id' => $department, 'action' => $action, 'reference' => $reference, 'status' => $status,
            'message' => $message, 'created_at' => now(),
        ]);
    }
}
