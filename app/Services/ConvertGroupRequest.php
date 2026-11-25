<?php

namespace App\Services;

use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertGroupRequest
{
    public function handle(User $user, int $group): int
    {
        return DB::transaction(function () use ($group, $user) {
            $record = GroupRequest::visibleTo($user)->lockForUpdate()->findOrFail($group);
            if ($record->deal_id !== null) {
                return $record->deal_id;
            }
            if ($record->status !== 'qualified') {
                throw ValidationException::withMessages(['conversion' => 'Укажите даты и число гостей перед конвертацией.']);
            }

            $deal = $record->contact->deals()->create([
                'title' => $record->title,
                'amount' => '0',
                'assignee_id' => $record->assignee_id,
                'department_id' => $record->department_id,
            ]);
            $record->update(['status' => 'converted', 'deal_id' => $deal->id]);
            SalesEvent::record($user, 'request.converted', [], $deal->id, $group);

            return $deal->id;
        }, 5);
    }
}
