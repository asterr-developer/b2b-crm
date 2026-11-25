<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\SalesEvent;
use App\Models\SalesPipeline;
use App\Services\MoveDealStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomPipelineController extends Controller
{
    private const CATEGORIES = ['new', 'qualified', 'proposal', 'won', 'lost'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->department_id === null, 403);

        return Inertia::render('Pipelines', [
            'pipelines' => SalesPipeline::where('department_id', $user->department_id)->orderBy('name')->paginate(20, ['id', 'name']),
            'canManage' => $user->leadsSalesDepartment(),
        ]);
    }

    public function show(Request $request, int $pipeline): Response
    {
        $user = $request->user();
        $board = SalesPipeline::where('department_id', $user->department_id)->findOrFail($pipeline);
        $visible = Deal::visibleTo($user);
        $available = $user->leadsSalesDepartment() ? (clone $visible)->whereNull('sales_pipeline_id')
            ->where('stage', 'new')->latest('id')->limit(50)->get(['id', 'title', 'revision']) : [];
        $deals = [];
        $counts = [];
        foreach ($board->stages as $step => $stage) {
            $column = (clone $visible)->where('sales_pipeline_id', $pipeline)->where('pipeline_step', $step);
            $counts[$step] = (clone $column)->count();
            $deals[$step] = $column->with('assignee:id,name')->latest('id')->limit(50)
                ->get(['id', 'title', 'revision', 'amount', 'assignee_id']);
        }

        return Inertia::render('CustomPipeline', [
            'pipeline' => ['id' => $board->id, 'name' => $board->name, 'stages' => $board->stages],
            'canManage' => $user->leadsSalesDepartment(),
            'structureLocked' => Deal::where('sales_pipeline_id', $pipeline)->exists(),
            'deals' => $deals, 'counts' => $counts, 'available' => $available,
        ]);
    }

    public function save(Request $request, ?int $pipeline = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $nameRule = Rule::unique('sales_pipelines', 'name')->where('department_id', $user->department_id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $pipeline === null ? $nameRule : $nameRule->ignore($pipeline)],
            'stages' => ['required', 'array', 'list', 'between:5,12'],
            'stages.*' => ['required', 'array:name,kind,requires_date'],
            'stages.*.name' => ['required', 'string', 'max:50', 'distinct'],
            'stages.*.kind' => ['required', Rule::in(self::CATEGORIES)],
            'stages.*.requires_date' => ['required', 'boolean'],
        ]);
        $kinds = array_column($data['stages'], 'kind');
        $ordered = $kinds;
        usort($ordered, fn ($left, $right) => array_search($left, self::CATEGORIES, true) <=> array_search($right, self::CATEGORIES, true));
        if (array_values(array_unique($kinds)) !== self::CATEGORIES
            || count(array_filter($kinds, fn ($kind) => in_array($kind, ['new', 'won', 'lost'], true))) !== 3
            || $kinds !== $ordered) {
            throw ValidationException::withMessages(['stages' => 'Нужны начальный этап, квалификация, предложение, выигрыш и проигрыш в указанном порядке. Дополнительные этапы могут быть квалификацией или предложением.']);
        }

        $id = DB::transaction(function () use ($user, $pipeline, $data, $kinds) {
            if ($pipeline === null) {
                $board = SalesPipeline::create([...$data, 'department_id' => $user->department_id]);
            } else {
                $board = SalesPipeline::where('department_id', $user->department_id)->lockForUpdate()->findOrFail($pipeline);
                if (Deal::where('sales_pipeline_id', $pipeline)->exists() && array_column($board->stages, 'kind') !== $kinds) {
                    throw ValidationException::withMessages(['stages' => 'Структура занята сделками. Создайте новую воронку, чтобы изменить состав этапов.']);
                }
                $board->update($data);
            }
            SalesEvent::record($user, 'pipeline.saved', ['pipeline_id' => $board->id, 'department_id' => $user->department_id]);

            return $board->id;
        }, 3);

        return to_route('pipelines.show', $id);
    }

    public function attach(Request $request, int $pipeline, MoveDealStage $mover): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate(['deal' => ['required', 'integer'], 'revision' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($pipeline, $user, $data, $mover) {
            $board = SalesPipeline::where('department_id', $user->department_id)->lockForUpdate()->findOrFail($pipeline);
            $deal = Deal::visibleTo($user)->lockForUpdate()->findOrFail($data['deal']);
            if ($deal->sales_pipeline_id !== null || $deal->stage !== 'new' || (int) $data['revision'] !== (int) $deal->revision) {
                throw ValidationException::withMessages(['stage' => 'Перенести можно только актуальную новую сделку из основной воронки.']);
            }
            $mover->handle($deal, $user, 'new', (bool) $board->stages[0]['requires_date'], ['sales_pipeline_id' => $pipeline, 'pipeline_step' => 0]);
            SalesEvent::record($user, 'pipeline.attached', ['pipeline_id' => $pipeline, 'pipeline' => $board->name], $deal->id);
        }, 3);

        return to_route('pipelines.show', $pipeline);
    }

    public function move(Request $request, int $pipeline, int $deal, MoveDealStage $mover): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['step' => ['required', 'integer', 'min:0'], 'revision' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($user, $pipeline, $deal, $data, $mover) {
            $board = SalesPipeline::where('department_id', $user->department_id)->lockForUpdate()->findOrFail($pipeline);
            $current = Deal::visibleTo($user)->where('sales_pipeline_id', $pipeline)->lockForUpdate()->findOrFail($deal);
            $target = $board->stages[$data['step']] ?? null;
            if ($target === null || (int) $data['revision'] !== (int) $current->revision
                || in_array($current->stage, ['won', 'lost'], true)
                || ((int) $data['step'] !== $current->pipeline_step + 1 && $target['kind'] !== 'lost')) {
                throw ValidationException::withMessages(['stage' => 'Переход недоступен или сделка изменилась.']);
            }

            $previous = $board->stages[$current->pipeline_step]['name'];
            $mover->handle($current, $user, $target['kind'], (bool) $target['requires_date'], ['pipeline_step' => (int) $data['step']]);
            SalesEvent::record($user, 'pipeline.moved', ['from' => $previous, 'to' => $target['name']], $current->id);
        }, 3);

        return to_route('pipelines.show', $pipeline);
    }
}
