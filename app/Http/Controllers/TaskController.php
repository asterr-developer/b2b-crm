<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Services\TaskCalendarFile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filter = $request->enum('filter', TaskFilter::class) ?? TaskFilter::Open;
        $visible = Deal::visibleTo($request->user())->select('id');

        $tasks = DealActivity::query()
            ->whereIn('deal_id', $visible)
            ->where('kind', 'task')
            ->with(['deal:id,title,assignee_id', 'deal.assignee:id,name']);

        if ($filter === TaskFilter::Completed) {
            $tasks->whereNotNull('completed_at')->orderByDesc('completed_at');
        } else {
            $tasks->whereNull('completed_at')
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at');

            if ($filter === TaskFilter::Overdue) {
                $tasks->where('due_at', '<', now());
            }
        }

        return Inertia::render('Tasks', [
            'tasks' => $tasks->orderByDesc('id')->paginate(25)->appends(['filter' => $filter->value]),
            'filter' => $filter->value,
            'now' => now()->toISOString(),
        ]);
    }

    public function calendar(Request $request, int $task, TaskCalendarFile $calendar): \Illuminate\Http\Response
    {
        $visible = Deal::visibleTo($request->user())->select('id');
        $entry = DealActivity::whereIn('deal_id', $visible)->where('kind', 'task')->whereNotNull('due_at')
            ->with('deal:id,title')->findOrFail($task);

        return response($calendar->render($entry), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="crm-task-'.$entry->id.'.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
