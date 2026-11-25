<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Department;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;
use Throwable;

class ProposalDelivery
{
    public function enqueue(User $user, int $dealId, int $proposalId, bool $retry): void
    {
        abort_unless(config('crm.proposal_email'), 403);
        DB::transaction(function () use ($user, $dealId, $proposalId, $retry) {
            $deal = Deal::visibleTo($user)->lockForUpdate()->findOrFail($dealId);
            $proposal = Proposal::where('deal_id', $dealId)->findOrFail($proposalId);
            $delivery = DB::table('proposal_deliveries')->where('proposal_id', $proposalId)->lockForUpdate()->first();
            if ($proposal->status !== 'approved' || $deal->department_id === null || $deal->stage === 'lost'
                || ($deal->stage === 'won' && ! isset($proposal->snapshot['booking_change']))) {
                throw ValidationException::withMessages(['delivery' => 'Для отправки нужна согласованная версия открытой сделки отдела.']);
            }
            if ($delivery && in_array($delivery->status, ['queued', 'sending'], true)) {
                return;
            }
            if ($delivery && (! $retry || ! in_array($delivery->status, ['failed', 'unknown'], true))) {
                throw ValidationException::withMessages(['delivery' => 'Проверьте доставку перед повторной отправкой.']);
            }

            $recipient = $delivery->recipient ?? $proposal->snapshot['recipient_email'] ?? $deal->contact->email;
            DB::table('proposal_deliveries')->updateOrInsert(['proposal_id' => $proposalId], [
                'department_id' => $deal->department_id, 'user_id' => $user->id, 'recipient' => $recipient,
                'status' => 'queued', 'updated_at' => now(), 'created_at' => $delivery->created_at ?? now(),
            ]);
            app(HotelInventory::class)->log($deal->department_id, 'proposal.delivery', (string) $proposalId, $retry ? 'retried' : 'queued');
        }, 3);
    }

    public function drain(ProposalPdf $pdf, HotelInventory $journal): void
    {
        if (! config('crm.proposal_email')) {
            return;
        }
        DB::table('proposal_deliveries')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))
            ->eachById(function ($row) use ($journal) {
                DB::transaction(function () use ($row, $journal) {
                    if (DB::table('proposal_deliveries')->where('id', $row->id)->where('status', 'sending')
                        ->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'unknown', 'updated_at' => now()])) {
                        $journal->log($row->department_id, 'proposal.delivery', (string) $row->proposal_id, 'unknown', 'Проверьте журнал почтового сервера.');
                    }
                });
            });
        foreach (DB::table('proposal_deliveries')->where('status', 'queued')->orderBy('id')->limit(20)->get() as $row) {
            $this->deliver($row, $pdf, $journal);
        }
    }

    private function deliver(object $row, ProposalPdf $pdf, HotelInventory $journal): void
    {
        $claimed = DB::transaction(function () use ($row, $journal) {
            $proposal = Proposal::findOrFail($row->proposal_id);
            $deal = Deal::lockForUpdate()->findOrFail($proposal->deal_id);
            $proposal->refresh();
            $delivery = DB::table('proposal_deliveries')->where('id', $row->id)->lockForUpdate()->first();
            if ($delivery->status !== 'queued') {
                return false;
            }
            if ($proposal->status !== 'approved' || $deal->department_id !== $row->department_id || $deal->stage === 'lost'
                || ($deal->stage === 'won' && ! isset($proposal->snapshot['booking_change']))) {
                DB::table('proposal_deliveries')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                $journal->log($row->department_id, 'proposal.delivery', (string) $row->proposal_id, 'cancelled', 'Согласованная версия или сделка больше не доступна.');
                return false;
            }
            DB::table('proposal_deliveries')->where('id', $row->id)->increment('attempts', 1, ['status' => 'sending', 'updated_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }

        $proposal = Proposal::findOrFail($row->proposal_id);
        $sending = false;
        try {
            $message = (new Email)->from(config('mail.from.address'))->to($row->recipient)
                ->subject('Коммерческое предложение №'.$proposal->version.' — '.$proposal->snapshot['title'])
                ->text('Здравствуйте! Прикладываем согласованное предложение на размещение.')
                ->attach($pdf->render($proposal), $pdf->filename($proposal), 'application/pdf');
            $message->getHeaders()->addIdHeader('Message-ID', InboundReplies::messageId($proposal->id));
            $sending = true;
            Mail::mailer()->getSymfonyTransport()->send($message);
        } catch (Throwable $error) {
            report($error);
            $status = $sending ? 'unknown' : 'failed';
            DB::transaction(function () use ($row, $journal, $status) {
                DB::table('proposal_deliveries')->where('id', $row->id)->where('status', 'sending')->update(['status' => $status, 'updated_at' => now()]);
                $journal->log($row->department_id, 'proposal.delivery', (string) $row->proposal_id, $status, 'Проверьте настройки почты и журнал перед повтором.');
            });
            return;
        }

        DB::transaction(function () use ($row, $proposal, $journal) {
            $deal = Deal::lockForUpdate()->findOrFail($proposal->deal_id);
            $updated = DB::table('proposal_deliveries')->where('id', $row->id)->where('status', 'sending')
                ->update(['status' => 'sent', 'updated_at' => now()]);
            if (! $updated) {
                return;
            }
            $days = (int) (Department::find($row->department_id)?->pipeline_settings['reminder_days'] ?? 0);
            $sent = now();
            Proposal::whereKey($proposal->id)->where('status', 'approved')->update([
                'status' => 'sent', 'sent_at' => $sent, 'follow_up_at' => $days ? $sent->copy()->addDays($days) : null,
            ]);
            SalesEvent::record(User::find($row->user_id), 'proposal.send', ['version' => $proposal->version, 'channel' => 'email'], $deal->id);
            $journal->log($row->department_id, 'proposal.delivery', (string) $row->proposal_id, 'sent');
        });
    }
}
