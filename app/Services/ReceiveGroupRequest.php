<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveGroupRequest
{
    public function handle(User $user, array $data): GroupRequest
    {
        return DB::transaction(function () use ($data, $user) {
            if ($user->department_id !== null) {
                Department::lockForUpdate()->findOrFail($user->department_id);
                abort_unless(User::whereKey($user->id)->where('department_id', $user->department_id)->exists(), 403);
            } else {
                $user = User::lockForUpdate()->findOrFail($user->id);
                abort_if($user->department_id !== null, 409);
            }

            $available = Company::availableTo($user);
            $email = strtolower($data['email']);
            if (! empty($data['company_id'])) {
                $company = $available->findOrFail($data['company_id']);
            } else {
                $name = $data['company_name'];
                $matches = $available->where(fn ($query) => $query
                    ->matchingName($name)
                    ->orWhereHas('contacts', fn ($contacts) => $contacts->where('email', $email)))
                    ->orderBy('id')->limit(5)->pluck('name');

                if ($matches->isNotEmpty() && empty($data['confirmed_distinct'])) {
                    throw ValidationException::withMessages([
                        'company_name' => 'Проверьте совпадения: '.$matches->implode(', ').'. Выберите существующую компанию или подтвердите создание отдельной.',
                    ]);
                }

                $company = Company::firstOrCreate(
                    ['user_id' => $user->id, 'name' => $name],
                    ['department_id' => $user->department_id],
                );
                if (! $company->wasRecentlyCreated) {
                    throw ValidationException::withMessages(['company_name' => 'Выберите существующую компанию или уточните название отдельной компании.']);
                }
            }

            $contact = $company->contacts()->firstOrCreate(['email' => $email], ['name' => $data['contact_name']]);
            $group = GroupRequest::create([
                'title' => $data['title'], 'source' => $data['source'], 'notes' => $data['notes'] ?? null,
                'company_id' => $company->id, 'contact_id' => $contact->id, 'assignee_id' => $user->id,
                'department_id' => $user->department_id, 'status' => 'received',
            ]);
            SalesEvent::record($user, 'request.received', [
                'source' => $group->source,
                'company_id' => $company->id,
                'confirmed_distinct' => (bool) ($data['confirmed_distinct'] ?? false),
                'integration' => $data['integration'] ?? null,
            ], groupRequest: $group->id);

            return $group;
        }, 3);
    }
}
