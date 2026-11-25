<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Proposal;
use App\Models\User;
use App\Services\InboundReplies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InboundReplyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $deals = Deal::visibleTo($user);
        $proposals = Proposal::whereIn('deal_id', (clone $deals)->select('id'));
        $messages = DB::table('inbound_replies as reply')->leftJoin('proposals as proposal', 'proposal.id', '=', 'reply.proposal_id')
            ->where('reply.department_id', $user->department_id)
            ->where(fn ($query) => $query->whereIn('reply.proposal_id', (clone $proposals)->select('id'))
                ->when($user->leadsSalesDepartment(), fn ($query) => $query->orWhereNull('reply.proposal_id')))
            ->orderByDesc('reply.id')->paginate(20, ['reply.id', 'reply.sender', 'reply.subject', 'reply.body', 'reply.received_at', 'proposal.deal_id']);

        return Inertia::render('InboundReplies', [
            'messages' => $messages, 'canReview' => $user->leadsSalesDepartment(),
            'sync' => $user->leadsSalesDepartment() ? $this->syncStatus($user->department_id) : null,
            'proposals' => $user->leadsSalesDepartment() ? $proposals->join('deals', 'deals.id', '=', 'proposals.deal_id')
                ->whereIn('proposals.status', ['sent', 'answered'])->orderByDesc('proposals.id')
                ->get(['proposals.id', 'version', 'title', 'deals.id as deal_id']) : [],
        ]);
    }

    public function store(Request $request, InboundReplies $inbox)
    {
        abort_unless($request->user()->leadsSalesDepartment(), 403);
        $inbox->receive($request->user()->department_id, $request->validate(self::rules()));

        return to_route('inbound-replies.index');
    }

    public function link(Request $request, int $reply, InboundReplies $inbox)
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate(['proposal' => ['required', 'integer']]);
        $inbox->link($user->department_id, $reply, $data['proposal'], $user);

        return to_route('inbound-replies.index');
    }

    public function webhook(Request $request, InboundReplies $inbox)
    {
        [$department, $event, $body] = $this->authenticateAdapter($request);
        [$id, $created] = $inbox->receive($department, $request->validate(self::rules()), $event, hash('sha256', $body));

        return response()->json(['reply_id' => $id, 'created' => $created], $created ? 201 : 200);
    }

    public function heartbeat(Request $request)
    {
        [$department, $event] = $this->authenticateAdapter($request);
        $data = $request->validate([
            'status' => ['required', 'in:done,failed'],
            'imported' => ['required', 'integer', 'between:0,25'],
            'duplicates' => ['required', 'integer', 'between:0,25'],
            'skipped' => ['required', 'integer', 'between:0,25'],
            'error' => ['required_if:status,failed', 'nullable', 'in:login,folder,connection,sync'],
        ]);
        DB::transaction(function () use ($department, $event, $data) {
            DB::table('departments')->lockForUpdate()->find($department);
            $runs = DB::table('hospitality_runs')->where('department_id', $department)->where('action', 'mailbox.sync');
            if (! (clone $runs)->where('reference', $event)->exists()) {
                app(\App\Services\HotelInventory::class)->log($department, 'mailbox.sync', $event, $data['status'], json_encode($data, JSON_THROW_ON_ERROR));
                $old = (clone $runs)->latest('id')->skip(200)->take(100)->pluck('id');
                DB::table('hospitality_runs')->whereIn('id', $old)->delete();
            }
        }, 3);

        return response()->json(['ok' => true]);
    }

    private function syncStatus(int $department): ?array
    {
        $runs = DB::table('hospitality_runs')->where('department_id', $department)->where('action', 'mailbox.sync')->latest('id');
        $latest = (clone $runs)->first();
        if (! $latest) {
            return null;
        }
        $success = (clone $runs)->where('status', 'done')->value('created_at');

        return [
            ...json_decode($latest->message, true, flags: JSON_THROW_ON_ERROR),
            'checked_at' => \Illuminate\Support\Carbon::parse($latest->created_at)->toISOString(),
            'last_success' => $success ? \Illuminate\Support\Carbon::parse($success)->toISOString() : null,
            'stale' => \Illuminate\Support\Carbon::parse($latest->created_at)->lt(now()->subMinutes(3)),
        ];
    }

    private function authenticateAdapter(Request $request): array
    {
        $secret = config('crm.reply_webhook_secret');
        $account = config('crm.reply_webhook_account');
        abort_unless(is_string($secret) && strlen($secret) >= 32 && $account, 404);
        $body = $request->getContent();
        abort_if(strlen($body) > 64 * 1024, 413);
        $at = (string) $request->header('X-CRM-Timestamp');
        $event = (string) $request->header('X-CRM-Event');
        abort_unless(preg_match('/^\d{1,12}$/D', $at) && abs(now()->timestamp - (int) $at) <= 300
            && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $event)
            && hash_equals(hash_hmac('sha256', $at.'.'.$event.'.'.$body, $secret), (string) $request->header('X-CRM-Signature')), 401);
        abort_unless($request->isJson(), 415);
        $department = User::whereEmail($account)->whereNotNull('department_id')->firstOrFail()->department_id;
        return [$department, $event, $body];
    }

    private static function rules(): array
    {
        return [
            'sender' => ['required', 'email', 'max:255'], 'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'], 'in_reply_to' => ['nullable', 'string', 'max:255'],
        ];
    }
}
