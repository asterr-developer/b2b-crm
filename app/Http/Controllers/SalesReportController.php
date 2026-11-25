<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Services\SalesForecast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    public function __invoke(Request $request, SalesForecast $forecast): Response
    {
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'capture' => ['nullable', 'date_format:Y-m-d'],
            'metric' => ['nullable', Rule::in(['response', 'client_reply', 'approval', 'overdue', 'lost'])],
        ]);
        $visible = Deal::visibleTo($request->user());
        $visible->when($period['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $visible->when($period['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        $overdue = DealActivity::query()->whereIn('deal_id', (clone $visible)->select('id'))
            ->where('kind', 'task')->whereNull('completed_at')->where('due_at', '<', now());
        $transitions = DB::table('deal_transitions')
            ->whereIn('deal_id', (clone $visible)->select('id'))
            ->selectRaw('to_stage, COUNT(DISTINCT deal_id) AS reached')
            ->groupBy('to_stage')->pluck('reached', 'to_stage');

        $total = (clone $visible)->count();
        $previous = $total;
        $labels = PipelineController::stageLabels($request->user());
        $conversion = [];
        foreach (['new', 'qualified', 'proposal', 'won'] as $stage) {
            $reached = $stage === 'new' ? $total : (int) ($transitions[$stage] ?? 0);
            $conversion[] = ['stage' => $labels[$stage], 'count' => $reached, 'percent' => $previous ? round(100 * $reached / $previous, 1) : null];
            $previous = $reached;
        }

        $losses = (clone $visible)->where('stage', 'lost')
            ->selectRaw('loss_reason, COUNT(*) AS count')->groupBy('loss_reason')->orderByDesc('count')->get();
        $actual = (clone $visible)->where('stage', 'won')->whereNotNull('closed_at')
            ->selectRaw('substr(CAST(closed_at AS TEXT), 1, 7) AS month, SUM(amount) AS amount, COUNT(*) AS count')
            ->groupBy('month')->orderBy('month')->get();

        $events = DB::table('sales_events')->whereColumn('deal_id', 'deals.id')->selectRaw('MIN(created_at)');
        $firstReply = DB::table('inbound_replies')->join('proposals', 'proposals.id', '=', 'inbound_replies.proposal_id')
            ->whereColumn('proposals.deal_id', 'deals.id')->whereNotNull('proposals.sent_at')
            ->whereColumn('inbound_replies.received_at', '>=', 'proposals.sent_at')
            ->orderBy('inbound_replies.received_at')->orderBy('inbound_replies.id')->limit(1);
        $timing = (clone $visible)->select('id', 'title', 'created_at')
            ->selectSub((clone $events)->where('action', 'proposal.send'), 'first_response')
            ->selectSub((clone $events)->where('action', 'proposal.created'), 'first_submission')
            ->selectSub((clone $events)->where('action', 'proposal.approve'), 'first_approval')
            ->selectSub((clone $firstReply)->select('proposals.sent_at'), 'reply_sent')
            ->selectSub((clone $firstReply)->select('inbound_replies.received_at'), 'first_reply')
            ->toBase();
        $metrics = [
            'response' => ['start' => 'created_at', 'end' => 'first_response', 'label' => 'До первой отправки КП'],
            'client_reply' => ['start' => 'reply_sent', 'end' => 'first_reply', 'label' => 'До первого письма клиента'],
            'approval' => ['start' => 'first_submission', 'end' => 'first_approval', 'label' => 'До первого согласования КП'],
        ];
        $selected = $period['metric'] ?? null;
        $details = null;
        foreach ($metrics as $key => &$metric) {
            $timed = DB::query()->fromSub($timing, 'timed')->whereNotNull($metric['start'])->whereNotNull($metric['end'])
                ->whereColumn($metric['end'], '>=', $metric['start']);
            $count = 0;
            $seconds = 0;
            foreach ($timed->cursor() as $row) {
                $seconds += Carbon::parse($row->{$metric['start']})->diffInSeconds(Carbon::parse($row->{$metric['end']}));
                $count++;
            }
            $metric += ['count' => $count, 'minutes' => $count ? round($seconds / ($count * 60), 1) : null];
            if ($selected === $key) {
                $details = $timed->select(['id', 'title', $metric['start'].' as started_at', $metric['end'].' as ended_at'])
                    ->orderByDesc('id')->paginate(20)->withQueryString();
            }
        }
        unset($metric);
        if ($selected === 'overdue') {
            $details = (clone $overdue)->join('deals', 'deals.id', '=', 'deal_activities.deal_id')
                ->orderBy('deal_activities.due_at')->orderBy('deal_activities.id')
                ->paginate(20, ['deals.id', 'deals.title', 'deal_activities.body as description', 'deal_activities.due_at as started_at'])->withQueryString();
        }
        if ($selected === 'lost') {
            $details = (clone $visible)->where('stage', 'lost')->orderByDesc('id')
                ->paginate(20, ['id', 'title', 'loss_reason as description', 'closed_at as ended_at'])->withQueryString();
        }

        return Inertia::render('Reports', [
            'conversion' => $conversion,
            'losses' => $losses,
            'forecast' => (object) $forecast->current($visible),
            'forecastHistory' => $forecast->history($request->user(), $period['capture'] ?? null),
            'actual' => $actual,
            'period' => $period,
            'bottlenecks' => $metrics,
            'bottleneckDetails' => $details,
            'overdueCount' => (clone $overdue)->count(),
            'overdueTasks' => $overdue->with('deal:id,title')->orderBy('due_at')->orderBy('id')->limit(10)->get(),
        ]);
    }
}
