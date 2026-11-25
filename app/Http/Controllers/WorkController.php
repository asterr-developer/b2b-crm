<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\Proposal;
use App\Models\SalesEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WorkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $filter = $request->validate(['filter' => ['nullable', Rule::in(['waiting', 'late', 'contacted'])]])['filter'] ?? 'waiting';
        $now = now();
        $hours = (int) (Department::find($user->department_id)?->pipeline_settings['first_response_hours'] ?? 24);
        $cutoff = $now->copy()->subHours($hours);
        $deals = Deal::visibleTo($user);
        $contact = DB::table('sales_events')->whereColumn('group_request_id', 'group_requests.id')
            ->where('action', 'request.first_contact')->selectRaw('MIN(created_at)');
        $requests = GroupRequest::visibleTo($user)->select(['id', 'title', 'created_at', 'assignee_id', 'deal_id', 'status'])
            ->selectSub($contact, 'contacted_at');
        $queue = DB::query()->fromSub($requests, 'requests')->leftJoin('users', 'users.id', '=', 'requests.assignee_id')
            ->select('requests.*', 'users.name as assignee');
        $waiting = (clone $queue)->whereNull('contacted_at')->where(fn ($query) => $query->whereNull('requests.deal_id')
            ->orWhereNotIn('requests.deal_id', (clone $deals)->whereNotNull('closed_at')->select('id')));
        $filtered = match ($filter) {
            'contacted' => (clone $queue)->whereNotNull('contacted_at')->orderByDesc('contacted_at'),
            'late' => (clone $waiting)->where('requests.created_at', '<', $cutoff)->orderBy('requests.created_at'),
            default => (clone $waiting)->orderBy('requests.created_at'),
        };
        $rows = $filtered->orderBy('requests.id')->paginate(20)->withQueryString()->through(function ($row) use ($hours, $now) {
            $deadline = Carbon::parse($row->created_at)->addHours($hours);
            $row->deadline = $deadline->toISOString();
            $row->late = ($row->contacted_at ? Carbon::parse($row->contacted_at) : $now)->gt($deadline);
            $row->contacted_at = $row->contacted_at ? Carbon::parse($row->contacted_at)->toISOString() : null;

            return $row;
        });
        $recent = (clone $queue)->where('requests.created_at', '>=', $now->copy()->subDays(30));
        $durations = (clone $recent)->whereNotNull('contacted_at')->select('requests.created_at', 'contacted_at')->cursor()
            ->map(fn ($row) => max(0, Carbon::parse($row->created_at)->diffInMinutes(Carbon::parse($row->contacted_at))))->sort()->values();
        $durations = $durations->collect();
        $count = $durations->count();
        $breached = $durations->filter(fn ($minutes) => $minutes > $hours * 60)->count()
            + (clone $waiting)->where('requests.created_at', '>=', $now->copy()->subDays(30))
                ->where('requests.created_at', '<', $cutoff)->count();
        $tasks = DealActivity::whereIn('deal_id', (clone $deals)->select('id'))->where('kind', 'task')->whereNull('completed_at')
            ->with('deal:id,title')->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('id')->limit(8)->get();
        $approvals = Proposal::query()->join('deals', 'deals.id', '=', 'proposals.deal_id')
            ->whereIn('deals.id', (clone $deals)->select('id'))->where('proposals.status', 'pending')
            ->when($user->leadsSalesDepartment(), fn ($query) => $query->where('proposals.created_by', '!=', $user->id))
            ->orderBy('proposals.id');
        $holds = DB::table('hotel_allocations as allocation')->join('group_requests as request', 'request.id', '=', 'allocation.group_request_id')
            ->whereIn('request.id', GroupRequest::visibleTo($user)->select('id'))->where('allocation.status', 'held')
            ->whereBetween('allocation.expires_at', [$now, $now->copy()->addDay()])
            ->selectRaw('request.id, request.title, MIN(allocation.expires_at) as expires_at')
            ->groupBy('request.id', 'request.title')->orderBy('expires_at');

        return Inertia::render('Work', [
            'requests' => $rows, 'filter' => $filter, 'hours' => $hours,
            'waiting' => (clone $waiting)->count(), 'late' => (clone $waiting)->where('requests.created_at', '<', $cutoff)->count(),
            'metrics' => ['total' => $recent->count(), 'contacted' => $count, 'breached' => $breached,
                'median' => $durations->median(), 'p90' => $count ? $durations[(int) ceil($count * .9) - 1] : null],
            'tasks' => $tasks,
            'approvalCount' => (clone $approvals)->reorder()->count(),
            'holdCount' => DB::query()->fromSub((clone $holds)->reorder(), 'group_holds')->count(),
            'approvals' => $approvals->limit(8)->get(['proposals.id', 'proposals.deal_id', 'proposals.version', 'deals.title']),
            'holds' => $holds->limit(8)->get(), 'canManage' => $user->leadsSalesDepartment(),
        ]);
    }

    public function contact(Request $request, int $group)
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'phone', 'other'])],
            'summary' => ['required', 'string', 'max:500'],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($group, $user, $data) {
            $record = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($group);
            if (! SalesEvent::where('group_request_id', $group)->where('action', 'request.first_contact')->exists()) {
                SalesEvent::record($user, 'request.first_contact', $data, $record->deal_id, $group);
            }
        }, 3);

        return back();
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate(['first_response_hours' => ['required', 'integer', 'between:1,168']]);
        DB::transaction(function () use ($user, $data) {
            $department = Department::lockForUpdate()->findOrFail($user->department_id);
            $department->forceFill(['pipeline_settings' => [...($department->pipeline_settings ?? []), ...$data]])->save();
            SalesEvent::record($user, 'automation.first_response_settings', $data);
        }, 3);

        return back();
    }
}
