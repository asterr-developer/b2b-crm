<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InboundReplies
{
    public static function messageId(int $proposal): string
    {
        return 'crm-proposal-'.$proposal.'-'.hash_hmac('sha256', (string) $proposal, (string) config('app.key')).'@crm.invalid';
    }

    public function receive(int $department, array $data, ?string $event = null, ?string $hash = null): array
    {
        return DB::transaction(function () use ($department, $data, $event, $hash) {
            Department::lockForUpdate()->findOrFail($department);
            if ($event !== null && ($previous = DB::table('inbound_replies')->where('department_id', $department)->where('event_key', $event)->first())) {
                abort_unless(hash_equals($previous->body_hash, $hash), 409);

                return [$previous->id, false];
            }
            $data['sender'] = mb_strtolower($data['sender']);
            $data['in_reply_to'] = trim($data['in_reply_to'] ?? '', " <>\t\r\n") ?: null;
            $id = DB::table('inbound_replies')->insertGetId([
                ...$data, 'department_id' => $department, 'event_key' => $event, 'body_hash' => $hash, 'received_at' => now(),
            ]);
            if ($event !== null && preg_match('/^crm-proposal-(\d{1,18})-[a-f0-9]{64}@crm\.invalid$/D', $data['in_reply_to'] ?? '', $parts)
                && hash_equals(self::messageId((int) $parts[1]), $data['in_reply_to'])) {
                $delivery = DB::table('proposal_deliveries')->where('department_id', $department)
                    ->where('proposal_id', (int) $parts[1])->where('status', 'sent')->first();
                if ($delivery && mb_strtolower($delivery->recipient) === $data['sender']) {
                    $this->link($department, $id, (int) $parts[1], null);
                }
            }

            return [$id, true];
        }, 5);
    }

    public function link(int $department, int $id, int $proposalId, ?User $actor): void
    {
        DB::transaction(function () use ($department, $id, $proposalId, $actor) {
            $reply = DB::table('inbound_replies')->where('department_id', $department)->lockForUpdate()->find($id);
            abort_unless($reply, 404);
            if ($reply->proposal_id !== null) {
                if ((int) $reply->proposal_id === $proposalId) {
                    return;
                }
                throw ValidationException::withMessages(['proposal' => 'Ответ уже связан с предложением.']);
            }
            $proposal = Proposal::find($proposalId);
            $deal = $proposal ? Deal::where('department_id', $department)
                ->when($actor, fn ($query) => $query->visibleTo($actor))->lockForUpdate()->find($proposal->deal_id) : null;
            if (! $deal) {
                abort_if($actor, 404);

                return;
            }
            $proposal->refresh();
            if (! in_array($proposal->status, ['sent', 'answered'], true)) {
                if ($actor) {
                    throw ValidationException::withMessages(['proposal' => 'Выберите отправленную версию предложения.']);
                }

                return;
            }
            DB::table('inbound_replies')->where('id', $id)->update(['proposal_id' => $proposalId, 'linked_at' => now()]);
            if ($proposal->status === 'sent') {
                $proposal->update(['status' => 'answered']);
                DealActivity::where('automation_key', 'proposal-follow-up:'.$proposalId)->whereNull('completed_at')->update(['completed_at' => now()]);
            }
            SalesEvent::record($actor, 'proposal.reply_received', ['version' => $proposal->version, 'reply_id' => $id], $deal->id);
        }, 5);
    }
}
