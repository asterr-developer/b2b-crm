<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\MoveDealStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    private const STAGES = [
        'new' => 'Новая',
        'qualified' => 'Квалификация',
        'proposal' => 'Предложение',
        'won' => 'Выиграна',
        'lost' => 'Проиграна',
    ];

    private const TRANSITIONS = [
        'new' => ['qualified', 'lost'],
        'qualified' => ['proposal', 'lost'],
        'proposal' => ['won', 'lost'],
        'won' => [],
        'lost' => [],
    ];

    public static function stageLabels(User $user): array
    {
        $settings = Department::find($user->department_id)?->pipeline_settings;

        return array_replace(self::STAGES, $settings['labels'] ?? []);
    }

    public function settings(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate([
            'labels' => ['required', 'array:new,qualified,proposal,won,lost', 'required_array_keys:new,qualified,proposal,won,lost'],
            'labels.*' => ['required', 'string', 'max:50'],
            'close_date_for_proposal' => ['required', 'boolean'],
            'reason_for_loss' => ['required', 'boolean'],
            'reminder_days' => ['required', 'integer', 'between:0,90'],
            'auto_task_stage' => ['sometimes', 'nullable', Rule::in(['qualified', 'proposal'])],
            'auto_task_title' => ['required_with:auto_task_stage', 'nullable', 'string', 'max:255'],
            'auto_task_days' => ['required_with:auto_task_stage', 'nullable', 'integer', 'between:0,90'],
        ]);

        DB::transaction(function () use ($user, $data) {
            $department = Department::lockForUpdate()->findOrFail($user->department_id);
            $department->forceFill(['pipeline_settings' => [...($department->pipeline_settings ?? []), ...$data]])->save();
            SalesEvent::record($user, 'automation.settings_saved', ['department_id' => $department->id, 'settings' => $data]);
        }, 3);

        return to_route('dashboard');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '';
        $visible = Deal::visibleTo($user)->whereNull('sales_pipeline_id');
        if ($search !== '') {
            $visible->where(fn ($query) => $query->whereLike('title', '%'.$search.'%')
                ->orWhereHas('contact.company', fn ($company) => $company->whereLike('name', '%'.$search.'%')));
        }
        $counts = (clone $visible)->selectRaw('stage, COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage');
        $deals = collect();
        foreach (array_keys(self::STAGES) as $stage) {
            $deals = $deals->concat((clone $visible)->where('stage', $stage)
                ->select(['id', 'contact_id', 'assignee_id', 'title', 'amount', 'stage', 'revision', 'created_at'])
                ->with(['contact:id,company_id,name', 'contact.company:id,name', 'assignee:id,name'])
                ->withCount(['activities as open_tasks_count' => fn ($tasks) => $tasks->where('kind', 'task')->whereNull('completed_at')])
                ->withMin(['activities as next_task_due_at' => fn ($tasks) => $tasks->where('kind', 'task')->whereNull('completed_at')], 'due_at')
                ->addSelect(['stage_entered_at' => DB::table('deal_transitions')->select('created_at')
                    ->whereColumn('deal_id', 'deals.id')->latest('id')->limit(1)])
                ->latest('id')->limit(50)->get());
        }

        return Inertia::render('Dashboard', [
            'deals' => $deals,
            'stages' => self::stageLabels($user),
            'transitions' => self::TRANSITIONS,
            'stageCounts' => (object) $counts->all(),
            'search' => $search,
            'hasDepartment' => $user->department_id !== null,
            'settings' => $user->leadsSalesDepartment() ? Department::find($user->department_id)?->pipeline_settings ?? [] : null,
            'assignees' => $user->leadsSalesDepartment()
                ? User::where('department_id', $user->department_id)->where('sales_role', '!=', 'lodging')->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            if ($user->department_id !== null) {
                Department::lockForUpdate()->findOrFail($user->department_id);
                abort_unless(User::whereKey($user->id)->where('department_id', $user->department_id)->exists(), 403);
            } else {
                User::lockForUpdate()->findOrFail($user->id);
            }
            $matches = Company::availableTo($user)->matchingName($data['company'])->limit(2)->get();
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['company' => 'Найдено несколько компаний. Выберите нужную при приёме заявки.']);
            }
            $company = $matches->first() ?? Company::create([
                'user_id' => $user->id, 'name' => $data['company'], 'department_id' => $user->department_id,
            ]);
            $contact = $company->contacts()->firstOrCreate(
                ['email' => strtolower($data['email'])],
                ['name' => $data['contact']],
            );
            $deal = $contact->deals()->create([
                'title' => $data['title'],
                'amount' => $data['amount'],
                'assignee_id' => $request->user()->id,
                'department_id' => $request->user()->department_id,
            ]);
            SalesEvent::record($user, 'deal.created', [], $deal->id);
        });

        return to_route('dashboard');
    }

    public function move(Request $request, int $deal, MoveDealStage $mover): RedirectResponse
    {
        $user = $request->user();
        Deal::visibleTo($user)->findOrFail($deal, ['id']);
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(self::STAGES))],
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($deal, $data, $user, $mover) {
            $current = Deal::visibleTo($user)->whereNull('sales_pipeline_id')->lockForUpdate()->findOrFail($deal);
            if ((int) $data['revision'] !== (int) $current->revision) {
                throw ValidationException::withMessages(['stage' => 'Сделка уже изменилась. Обновите страницу.']);
            }
            if (! in_array($data['stage'], self::TRANSITIONS[$current->stage], true)) {
                throw ValidationException::withMessages(['stage' => 'Переход на этот этап недоступен.']);
            }
            $mover->handle($current, $user, $data['stage']);
        });

        return to_route('dashboard');
    }

    public function assign(Request $request, int $deal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $current = Deal::visibleTo($user)->findOrFail($deal);
        $data = $request->validate([
            'assignee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('department_id', $user->department_id)->whereNot('sales_role', 'lodging')],
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($user, $current, $data) {
            Department::lockForUpdate()->findOrFail($user->department_id);
            $updated = Deal::visibleTo($user)->whereKey($current->id)
                ->where('revision', $data['revision'])
                ->whereExists(User::query()->selectRaw('1')->whereKey($data['assignee_id'])->where('department_id', $user->department_id)->where('sales_role', '!=', 'lodging'))
                ->increment('revision', 1, ['assignee_id' => $data['assignee_id']]);

            if ($updated !== 1) {
                throw ValidationException::withMessages(['assignee_id' => 'Данные изменились. Обновите страницу.']);
            }

            SalesEvent::record($user, 'deal.assigned', ['previous' => $current->assignee_id, 'current' => $data['assignee_id']], $current->id);
            GroupRequest::where('deal_id', $current->id)->update(['assignee_id' => $data['assignee_id']]);
        });

        if ($request->query('return_to') === 'deal') {
            return to_route('deals.show', $current->id);
        }

        return to_route('dashboard');
    }
}
