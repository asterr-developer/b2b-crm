<?php

namespace App\Jobs;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Services\HotelInventory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CreateProposalReminders implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $settings = Department::all()->pluck('pipeline_settings', 'id');
        Proposal::where('status', 'sent')->where('sent_at', '<=', now()->subDay())
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('proposals as newer')
                    ->whereColumn('newer.deal_id', 'proposals.deal_id')
                    ->whereColumn('newer.version', '>', 'proposals.version')
                    ->whereIn('newer.status', ['sent', 'answered']);
            })->eachById(function (Proposal $proposal) use ($settings) {
                DB::transaction(function () use ($proposal, $settings) {
                    $deal = Deal::with('assignee:id,department_id')->lockForUpdate()->find($proposal->deal_id);
                    $booking = $proposal->snapshot['booking_change'] ?? null;
                    if (! $deal?->assignee || ($deal->stage !== 'proposal' && ! ($booking && $deal->stage === 'won'))) {
                        return;
                    }
                    if ($booking && (Proposal::where('deal_id', $deal->id)->latest('version')->value('id') !== $proposal->id
                        || ! DB::table('hotel_allocations')->whereIn('id', $booking['booking_ids'])->where('status', 'booked')->exists())) {
                        return;
                    }

                    $days = (int) ($settings[$deal->department_id]['reminder_days'] ?? 0);
                    $deadline = $proposal->follow_up_at;
                    if ($deadline === null && $proposal->created_by === null && $days > 0) {
                        $deadline = $proposal->sent_at->copy()->addDays($days);
                    }
                    if ($deadline === null || $deadline->isFuture()
                        || ! Proposal::whereKey($proposal->id)->where('status', 'sent')->exists()
                        || Proposal::where('deal_id', $deal->id)->whereIn('status', ['sent', 'answered'])->where('version', '>', $proposal->version)->exists()) {
                        return;
                    }

                    $task = DealActivity::firstOrCreate(['automation_key' => 'proposal-follow-up:'.$proposal->id], [
                        'deal_id' => $deal->id,
                        'kind' => 'task',
                        'body' => 'Уточнить ответ клиента по предложению №'.$proposal->version,
                        'due_at' => $deadline,
                    ]);
                    if ($task->wasRecentlyCreated) {
                        SalesEvent::record(null, 'automation.reminder_created', ['version' => $proposal->version, 'task_id' => $task->id], $deal->id);
                        if ($deal->department_id !== null) {
                            app(HotelInventory::class)->log($deal->department_id, 'proposal.reminder', (string) $proposal->id, 'done');
                        }
                    }
                });
            });
    }
}
