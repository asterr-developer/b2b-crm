<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MergeClients
{
    public function handle(User $actor, int $sourceId, int $targetId): void
    {
        abort_unless($actor->leadsSalesDepartment(), 403);
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target' => 'Выберите другую компанию.']);
        }

        DB::transaction(function () use ($actor, $sourceId, $targetId) {
            Department::lockForUpdate()->findOrFail($actor->department_id);
            $source = Company::availableTo($actor)->findOrFail($sourceId);
            $target = Company::availableTo($actor)->findOrFail($targetId);
            $sourceContacts = $source->contacts()->orderBy('id')->get(['id', 'name', 'email']);
            $contactIds = $sourceContacts->modelKeys();

            $requests = GroupRequest::where('company_id', $sourceId)->orderBy('id')->lockForUpdate()
                ->get(['id', 'department_id', 'contact_id']);
            $deals = Deal::where(fn ($query) => $query->whereIn('contact_id', $contactIds)
                ->orWhereIn('id', DB::table('deal_contacts')->select('deal_id')->whereIn('contact_id', $contactIds)))
                ->orderBy('id')->lockForUpdate()->get(['id', 'department_id']);

            if ($requests->contains(fn ($row) => $row->department_id !== $actor->department_id || ! in_array($row->contact_id, $contactIds, true))
                || $deals->contains(fn ($row) => $row->department_id !== $actor->department_id)) {
                throw ValidationException::withMessages(['target' => 'Среди связей есть записи другой области. Требуется проверка администратора.']);
            }

            $mapping = [];
            foreach ($sourceContacts as $contact) {
                $replacement = $target->contacts()->firstOrCreate(['email' => $contact->email], ['name' => $contact->name]);
                $mapping[$contact->id] = $replacement->id;

                $overlap = DB::table('deal_contacts as old_pivot')
                    ->join('deal_contacts as new_pivot', 'old_pivot.deal_id', '=', 'new_pivot.deal_id')
                    ->where('old_pivot.contact_id', $contact->id)->where('new_pivot.contact_id', $replacement->id);
                if ((clone $overlap)->whereColumn('old_pivot.role', '!=', 'new_pivot.role')->exists()) {
                    throw ValidationException::withMessages(['target' => 'Один контакт имеет разные роли в одной сделке. Сначала уточните роли.']);
                }

                $oldRelations = DB::table('deal_contacts')->where('contact_id', $contact->id);
                (clone $oldRelations)->whereIn('deal_id', $overlap->select('old_pivot.deal_id'))->delete();
                $oldRelations->update(['contact_id' => $replacement->id]);
                Deal::where('contact_id', $contact->id)->increment('revision', 1, ['contact_id' => $replacement->id]);
                GroupRequest::where('company_id', $sourceId)->where('contact_id', $contact->id)
                    ->update(['company_id' => $targetId, 'contact_id' => $replacement->id]);
            }

            Company::where('merged_into_id', $sourceId)->update(['merged_into_id' => $targetId]);
            $source->forceFill(['merged_into_id' => $targetId, 'archived_at' => now()])->save();
            $details = ['source_company_id' => $sourceId, 'target_company_id' => $targetId, 'contacts' => $mapping];
            SalesEvent::record($actor, 'client.merged', $details);
            foreach ($deals as $deal) {
                SalesEvent::record($actor, 'deal.client_merged', $details, $deal->id);
            }
        }, 5);
    }
}
