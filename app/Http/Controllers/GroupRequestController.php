<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\ConvertGroupRequest;
use App\Services\ReceiveGroupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GroupRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'request' => ['nullable', 'integer', 'min:1']]);
        $search = $filters['search'] ?? '';
        $clients = Company::availableTo($user);
        if ($search !== '') {
            $clients->searchName($search);
        }

        return Inertia::render('GroupRequests', [
            'requests' => GroupRequest::visibleTo($user)->with('company:id,name', 'contact:id,name,email', 'assignee:id,name')
                ->when($filters['request'] ?? null, fn ($query, $id) => $query->whereKey($id))
                ->latest('id')->paginate(20)->withQueryString(),
            'focusedRequest' => $filters['request'] ?? null,
            'companies' => $clients->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name']),
            'search' => $search,
            'success' => $request->session()->get('success'),
            'assignees' => $user->leadsSalesDepartment()
                ? User::where('department_id', $user->department_id)->where('sales_role', '!=', 'lodging')->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request, ReceiveGroupRequest $intake): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'source' => ['required', Rule::in(['email', 'phone', 'form', 'other'])],
            'company_id' => ['nullable', 'integer'],
            'company_name' => ['required_without:company_id', 'nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'confirmed_distinct' => ['sometimes', 'boolean'],
        ]);

        $intake->handle($request->user(), $data);

        return to_route('group-requests.index')->with('success', 'Заявка принята.');
    }

    public function qualify(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'arrival_on' => ['required', 'date_format:Y-m-d'],
            'departure_on' => ['required', 'date_format:Y-m-d', 'after:arrival_on'],
            'guests' => ['required', 'integer', 'between:1,10000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'arrival_on.required' => 'Укажите дату заезда.',
            'arrival_on.date_format' => 'Укажите корректную дату заезда.',
            'departure_on.required' => 'Укажите дату выезда.',
            'departure_on.date_format' => 'Укажите корректную дату выезда.',
            'departure_on.after' => 'Дата выезда должна быть позже даты заезда.',
            'guests.required' => 'Укажите количество гостей.',
            'guests.integer' => 'Укажите целое количество гостей.',
            'guests.between' => 'Укажите от 1 до 10 000 гостей.',
        ]);

        $user = $request->user();
        DB::transaction(function () use ($group, $user, $data) {
            $record = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($group);
            if ($record->deal_id !== null) {
                throw ValidationException::withMessages(['arrival_on' => 'Заявка уже конвертирована.']);
            }
            $record->update([...$data, 'status' => 'qualified']);
            SalesEvent::record($user, 'request.qualified', [
                ...array_diff_key($data, ['notes' => true]), 'notes_updated' => $record->wasChanged('notes'),
            ], groupRequest: $group);
        }, 3);

        return to_route('group-requests.index')->with('success', 'Заявка квалифицирована.');
    }

    public function convert(Request $request, int $group, ConvertGroupRequest $conversion): RedirectResponse
    {
        return to_route('deals.show', $conversion->handle($request->user(), $group));
    }

    public function assign(Request $request, int $group): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $data = $request->validate(['assignee_id' => ['required', 'integer']]);
        DB::transaction(function () use ($user, $group, $data) {
            Department::lockForUpdate()->findOrFail($user->department_id);
            $record = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($group);
            if ($record->deal_id !== null) {
                throw ValidationException::withMessages(['assignee_id' => 'Для конвертированной заявки измените ответственного в сделке.']);
            }
            if (! User::where('department_id', $user->department_id)->where('sales_role', '!=', 'lodging')->whereKey($data['assignee_id'])->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Ответственный должен работать в вашем отделе.']);
            }

            SalesEvent::record($user, 'request.assigned', ['previous' => $record->assignee_id, 'current' => $data['assignee_id']], groupRequest: $group);
            $record->update(['assignee_id' => $data['assignee_id']]);
        });

        return to_route('group-requests.index')->with('success', 'Ответственный изменён.');
    }
}
