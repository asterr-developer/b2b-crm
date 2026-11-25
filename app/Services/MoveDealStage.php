<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveDealStage
{
    public function handle(Deal $deal, User $actor, string $target, bool $requiresDate = false, array $extra = []): void
    {
        $previous = $deal->stage;
        $settings = Department::find($deal->department_id)?->pipeline_settings;

        if (in_array($target, ['won', 'lost'], true) && DB::table('proposal_deliveries')->where('status', 'sending')
            ->whereIn('proposal_id', Proposal::where('deal_id', $deal->id)->select('id'))->exists()) {
            throw ValidationException::withMessages(['stage' => 'Дождитесь завершения отправки предложения.']);
        }
        if ($target === 'won' && (float) $deal->amount <= 0) {
            throw ValidationException::withMessages(['stage' => 'Для выигрыша сделки нужна положительная сумма.']);
        }
        if ($target === 'won' && ($group = GroupRequest::where('deal_id', $deal->id)->first())) {
            $proposal = Proposal::where('deal_id', $deal->id)->latest('version')->first();
            if ($proposal?->customer_decision !== 'accepted') {
                throw ValidationException::withMessages(['stage' => 'Зафиксируйте согласие клиента по актуальной версии предложения.']);
            }
            if (! DB::table('hotel_allocations')->where('group_request_id', $group->id)->where('status', 'booked')
                ->where('confirmed_proposal_id', $proposal->id)->exists()) {
                throw ValidationException::withMessages(['stage' => 'Подтвердите бронь по актуальной версии КП.']);
            }
        }
        if (($requiresDate || ($target === 'proposal' && ($settings['close_date_for_proposal'] ?? false))) && ! $deal->expected_close_on) {
            throw ValidationException::withMessages(['stage' => 'Укажите плановую дату закрытия в карточке сделки.']);
        }
        if ($target === 'lost' && ($settings['reason_for_loss'] ?? false) && ! $deal->loss_reason) {
            throw ValidationException::withMessages(['stage' => 'Укажите причину проигрыша в карточке сделки.']);
        }

        $at = now();
        $deal->increment('revision', 1, [
            ...$extra,
            'stage' => $target,
            'closed_at' => in_array($target, ['won', 'lost'], true) ? $at : null,
        ]);
        if ($target === 'lost') {
            DealActivity::where('deal_id', $deal->id)->whereIn('automation_key', ['payment:'.$deal->id.':deposit', 'payment:'.$deal->id.':balance'])
                ->whereNull('completed_at')->update(['completed_at' => $at]);
        }

        if ($previous !== $target) {
            DB::table('deal_transitions')->insert([
                'deal_id' => $deal->id,
                'user_id' => $actor->id,
                'from_stage' => $previous,
                'to_stage' => $target,
                'created_at' => $at,
            ]);

            if (($settings['auto_task_stage'] ?? null) === $target && ! empty($settings['auto_task_title'])) {
                $task = DealActivity::firstOrCreate(['automation_key' => 'stage-task:'.$deal->id.':'.$target], [
                    'deal_id' => $deal->id, 'kind' => 'task', 'body' => $settings['auto_task_title'],
                    'due_at' => $at->copy()->addDays((int) ($settings['auto_task_days'] ?? 0)),
                ]);
                if ($task->wasRecentlyCreated) {
                    SalesEvent::record(null, 'automation.stage_task_created', ['task_id' => $task->id, 'trigger_stage' => $target], $deal->id);
                }
            }
        }
    }
}
