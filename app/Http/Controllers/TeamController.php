<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor->leadsSalesDepartment(), 403);

        return Inertia::render('Team', [
            'department' => Department::findOrFail($actor->department_id)->name,
            'members' => User::where('department_id', $actor->department_id)->orderBy('name')->orderBy('id')
                ->paginate(25, ['id', 'name', 'email', 'sales_role']),
            'invite' => $request->session()->get('team_invite'),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->leadsSalesDepartment(), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower($data['email']);
        $token = Str::random(64);

        DB::transaction(function () use ($actor, $email, $token) {
            Department::lockForUpdate()->findOrFail($actor->department_id);
            if (User::whereEmail($email)->whereNotNull('department_id')->exists()) {
                throw ValidationException::withMessages(['email' => 'Этот сотрудник уже состоит в отделе.']);
            }
            TeamInvitation::where('department_id', $actor->department_id)->where('email', $email)
                ->whereNull('accepted_at')->update(['expires_at' => now()]);
            $invite = TeamInvitation::create([
                'department_id' => $actor->department_id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDay(),
            ]);
            SalesEvent::record($actor, 'team.invited', ['invitation_id' => $invite->id, 'department_id' => $actor->department_id]);
        });

        return to_route('team.index')->with('team_invite', route('team.invitation', $token));
    }

    public function invitation(Request $request, string $token): Response
    {
        $invitation = $this->lookup($request, $token)->firstOrFail();

        return Inertia::render('TeamInvitation', [
            'token' => $token,
            'department' => Department::findOrFail($invitation->department_id)->name,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        DB::transaction(function () use ($request, $token) {
            $candidate = $this->lookup($request, $token)->firstOrFail();
            Department::lockForUpdate()->findOrFail($candidate->department_id);
            $invite = $this->lookup($request, $token)->lockForUpdate()->firstOrFail();
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless(Str::lower($actor->email) === $invite->email, 404);
            if ($actor->department_id !== null || Company::availableTo($actor)->exists()
                || GroupRequest::visibleTo($actor)->exists() || Deal::visibleTo($actor)->exists()) {
                throw ValidationException::withMessages(['membership' => 'Для аккаунта с отделом или личными записями нужен явный перенос администратором.']);
            }
            $actor->forceFill(['department_id' => $invite->department_id, 'sales_role' => 'manager'])->save();
            $invite->update(['accepted_at' => now()]);
            SalesEvent::record($actor, 'team.joined', ['invitation_id' => $invite->id, 'department_id' => $invite->department_id]);
        }, 3);

        return to_route('group-requests.index')->with('success', 'Вы присоединились к отделу.');
    }

    public function remove(Request $request, int $member): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->leadsSalesDepartment(), 403);

        DB::transaction(function () use ($actor, $member) {
            Department::lockForUpdate()->findOrFail($actor->department_id);
            $employee = User::where('department_id', $actor->department_id)
                ->where('sales_role', 'manager')->lockForUpdate()->findOrFail($member);
            if (Deal::where('assignee_id', $employee->id)->where('department_id', $actor->department_id)->exists()
                || GroupRequest::where('assignee_id', $employee->id)->where('department_id', $actor->department_id)->exists()) {
                throw ValidationException::withMessages(['member' => 'Перед исключением передайте сделки и заявки сотрудника.']);
            }

            $employee->forceFill(['department_id' => null, 'sales_role' => 'manager'])->save();
            TeamInvitation::where('department_id', $actor->department_id)->where('email', $employee->email)
                ->whereNull('accepted_at')->update(['expires_at' => now()]);
            SalesEvent::record($actor, 'team.removed', ['member_id' => $employee->id, 'department_id' => $actor->department_id]);
        }, 3);

        return to_route('team.index');
    }

    private function lookup(Request $request, string $token): \Illuminate\Database\Eloquent\Builder
    {
        return TeamInvitation::where('token_hash', hash('sha256', $token))
            ->where('email', Str::lower($request->user()->email))
            ->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
