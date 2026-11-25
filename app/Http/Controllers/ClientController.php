<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Department;
use App\Services\MergeClients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '';
        $user = $request->user();
        $companies = Company::availableTo($user)
            ->when($search !== '', fn ($query) => $query->searchName($search))
            ->withCount('contacts')->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();

        return Inertia::render('Clients', [
            'companies' => $companies, 'search' => $search, 'canImport' => $user->leadsSalesDepartment(),
            'preview' => $request->session()->get('client_import_preview'),
            'imported' => $request->session()->get('client_import_result'),
        ]);
    }

    public function show(Request $request, int $company): Response|RedirectResponse
    {
        $user = $request->user();
        $client = Company::accessibleTo($user)->findOrFail($company, ['id', 'name', 'archived_at', 'merged_into_id']);
        if ($client->merged_into_id !== null) {
            return to_route('clients.show', $client->merged_into_id);
        }
        $visibleDeals = Deal::visibleTo($user)->whereHas('contact', fn ($contact) => $contact->where('company_id', $company));
        $search = $request->validate(['merge_search' => ['nullable', 'string', 'max:100']])['merge_search'] ?? '';

        return Inertia::render('Client', [
            'company' => $client,
            'contacts' => $client->contacts()->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name', 'email']),
            'deals' => $visibleDeals->latest('id')->limit(25)->get(['id', 'title', 'stage']),
            'stages' => PipelineController::stageLabels($user),
            'canMerge' => $user->leadsSalesDepartment(),
            'mergeSearch' => $search,
            'mergeCandidates' => ! $user->leadsSalesDepartment() ? [] : Company::availableTo($user)->whereKeyNot($company)
                ->when($search !== '', fn ($query) => $query->searchName($search))
                ->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name']),
            'aliases' => Company::accessibleTo($user)->where('merged_into_id', $company)->orderBy('name')->pluck('name'),
        ]);
    }

    public function merge(Request $request, int $company, MergeClients $merge): RedirectResponse
    {
        $data = $request->validate(['target' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        $merge->handle($request->user(), $company, (int) $data['target']);

        return to_route('clients.show', (int) $data['target']);
    }

    public function import(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->leadsSalesDepartment(), 403);
        $input = $request->validate([
            'file' => ['required', 'file', 'max:512'],
            'mode' => ['required', Rule::in(['preview', 'apply'])],
        ]);
        $hash = hash_file('sha256', $input['file']->getRealPath());
        $token = ['hash' => $hash, 'department' => $user->department_id];

        if ($input['mode'] === 'apply' && $request->session()->get('client_import_token') !== $token) {
            throw ValidationException::withMessages(['file' => 'Сначала проверьте этот CSV в режиме предпросмотра.']);
        }

        $rows = $this->readCsv($input['file']->getRealPath());
        $apply = $input['mode'] === 'apply';
        $report = DB::transaction(function () use ($rows, $user, $apply) {
            if ($apply) {
                Department::lockForUpdate()->findOrFail($user->department_id);
            }
            $report = ['total' => count($rows), 'valid' => 0, 'companies' => 0, 'contacts' => 0, 'errors' => []];
            $companiesByEmail = [];
            foreach ($rows as $line => $row) {
                if (count($row) !== 3 || ! mb_check_encoding($row, 'UTF-8')) {
                    $report['errors'][] = "Строка {$line} — нужны 3 поля в UTF-8.";
                    continue;
                }
                [$companyName, $contactName, $email] = array_map(trim(...), $row);
                if (Validator::make(compact('companyName', 'contactName', 'email'), [
                    'companyName' => 'required|string|max:255',
                    'contactName' => 'required|string|max:255',
                    'email' => 'required|email|max:255',
                ])->fails()) {
                    $report['errors'][] = "Строка {$line} — проверьте компанию, контакт и email.";
                    continue;
                }

                $email = strtolower($email);
                $normalizedName = Company::normalizeName($companyName);
                $company = Company::availableTo($user)
                    ->matchingName($companyName)
                    ->limit(2)->get();
                $sameEmailElsewhere = Company::availableTo($user)->whereNotIn('id', $company->modelKeys())
                    ->whereHas('contacts', fn ($contacts) => $contacts->where('email', $email))->exists();

                if ($company->count() > 1 || $sameEmailElsewhere || ($companiesByEmail[$email] ?? $normalizedName) !== $normalizedName) {
                    $report['errors'][] = "Строка {$line} — неоднозначное соответствие клиента. Проверьте вручную.";
                    continue;
                }

                $companiesByEmail[$email] = $normalizedName;
                $report['valid']++;
                if (! $apply) {
                    continue;
                }

                $record = $company->first() ?? Company::create([
                    'user_id' => $user->id, 'department_id' => $user->department_id, 'name' => $companyName,
                ]);
                $contact = $record->contacts()->firstOrCreate(['email' => $email], ['name' => $contactName]);
                $report['companies'] += (int) $record->wasRecentlyCreated;
                $report['contacts'] += (int) $contact->wasRecentlyCreated;
            }

            return $report;
        }, 3);

        if ($apply) {
            $request->session()->forget('client_import_token');

            return to_route('clients.index')->with('client_import_result', $report);
        }

        $request->session()->put('client_import_token', $token);

        return to_route('clients.index')->with('client_import_preview', $report);
    }

    private function readCsv(string $path): array
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать CSV.']);
        }
        try {
            $header = fgetcsv($file, 0, ',', '"', '');
            if ($header !== false && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }

            if ($header !== ['company', 'contact', 'email']) {
                throw ValidationException::withMessages(['file' => 'Первая строка должна быть company,contact,email.']);
            }
            $rows = [];
            for ($line = 2; ($row = fgetcsv($file, 0, ',', '"', '')) !== false; $line++) {
                if ($row === [null]) {
                    continue;
                }
                if (count($rows) >= 500) {
                    throw ValidationException::withMessages(['file' => 'Разделите CSV на файлы до 500 записей.']);
                }
                $rows[$line] = $row;
            }

            return $rows;
        } finally {
            fclose($file);
        }
    }
}
