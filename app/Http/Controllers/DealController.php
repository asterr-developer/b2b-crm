<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\Proposal;
use App\Models\SalesEvent;
use App\Models\SalesPipeline;
use App\Models\User;
use App\Services\ProposalPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mime\Email;

class DealController extends Controller
{
    public function show(Request $request, int $deal): Response
    {
        $current = Deal::visibleTo($request->user())->with('contact.company', 'assignee:id,name')->findOrFail($deal);
        $group = GroupRequest::where('deal_id', $deal)->first(['id', 'arrival_on', 'departure_on', 'guests', 'source']);
        $booking = $group ? DB::table('hotel_allocations as allocation')->join('hotel_room_types as category', 'category.id', '=', 'allocation.hotel_room_type_id')
            ->where('allocation.group_request_id', $group->id)->where('allocation.status', 'booked')
            ->where('allocation.departure_on', '>', today()->toDateString())
            ->orderByDesc('allocation.id')->get(['allocation.id', 'allocation.rooms', 'category.property', 'category.name']) : collect();
        $pipeline = $current->sales_pipeline_id === null ? null
            : SalesPipeline::where('department_id', $current->department_id)->find($current->sales_pipeline_id);

        return Inertia::render('Deal', [
            'deal' => $current,
            'contacts' => DB::table('deal_contacts')->join('contacts', 'contacts.id', '=', 'deal_contacts.contact_id')
                ->where('deal_id', $deal)->orderBy('contacts.name')->get(['contacts.id', 'name', 'email', 'role']),
            'activities' => DealActivity::where('deal_id', $deal)->latest('id')->get(),
            'history' => DB::table('deal_transitions')->leftJoin('users', 'users.id', '=', 'deal_transitions.user_id')
                ->where('deal_id', $deal)->latest('deal_transitions.id')->get(['deal_transitions.*', 'users.name']),
            'proposals' => Proposal::where('deal_id', $deal)->latest('version')->get(),
            'payments' => DB::table('deal_payments')->where('deal_id', $deal)->get()->keyBy('kind'),
            'paymentToday' => today('Europe/Moscow')->toDateString(),
            'replies' => DB::table('inbound_replies as reply')->join('proposals as proposal', 'proposal.id', '=', 'reply.proposal_id')
                ->where('proposal.deal_id', $deal)->orderByDesc('reply.id')->limit(20)
                ->get(['reply.id', 'reply.sender', 'reply.subject', 'reply.body', 'reply.received_at', 'proposal.version']),
            'emailEnabled' => (bool) config('crm.proposal_email') && $current->department_id !== null,
            'deliveries' => DB::table('proposal_deliveries')
                ->whereIn('proposal_id', Proposal::where('deal_id', $deal)->select('id'))
                ->get(['proposal_id', 'status', 'attempts', 'recipient'])->keyBy('proposal_id'),
            'canApprove' => $request->user()->leadsSalesDepartment(),
            'viewerId' => $request->user()->id,
            'assignees' => $request->user()->leadsSalesDepartment()
                ? User::where('department_id', $current->department_id)->where('sales_role', '!=', 'lodging')->orderBy('name')->get(['id', 'name'])
                : [],
            'catalog' => $current->department_id === null ? [] : CatalogItem::where('department_id', $current->department_id)
                ->orderBy('name')->limit(50)->get(['id', 'name', 'price']),
            'stages' => PipelineController::stageLabels($request->user()),
            'groupRequest' => $group,
            'activeBookingIds' => $booking->pluck('id'),
            'booking' => $booking,
            'pipeline' => $pipeline ? ['id' => $pipeline->id, 'name' => $pipeline->name, 'stage' => $pipeline->stages[$current->pipeline_step]['name']] : null,
            'events' => SalesEvent::query()->leftJoin('users', 'users.id', '=', 'sales_events.user_id')
                ->where(fn ($events) => $events->where('sales_events.deal_id', $deal)
                    ->when($group, fn ($query) => $query->orWhere('sales_events.group_request_id', $group->id)))
                ->latest('sales_events.id')->limit(40)
                ->get(['sales_events.id', 'sales_events.action', 'sales_events.details', 'sales_events.created_at', 'users.name']),
        ]);
    }

    public function update(Request $request, int $deal): RedirectResponse
    {
        $data = $request->validate([
            'expected_close_on' => ['nullable', 'date_format:Y-m-d'],
            'loss_reason' => ['nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'revision' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $deal, $data) {
            $current = Deal::visibleTo($request->user())->lockForUpdate()->findOrFail($deal);
            if (isset($data['revision']) && (int) $data['revision'] !== (int) $current->revision) {
                throw ValidationException::withMessages(['revision' => 'Сделка изменилась. Обновите страницу.']);
            }
            if ($current->closed_at && isset($data['amount']) && bccomp((string) $data['amount'], $current->amount, 2) !== 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма закрытой сделки зафиксирована.']);
            }
            $changes = array_diff_key($data, ['revision' => true]);
            $current->increment('revision', 1, $changes);
            SalesEvent::record($request->user(), 'deal.updated', $changes, $deal);
        });

        return to_route('deals.show', $deal);
    }

    public function contact(Request $request, int $deal): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $deal, $data) {
            $current = Deal::visibleTo($request->user())->lockForUpdate()->findOrFail($deal);
            $contact = $current->contact->company->contacts()->firstOrCreate(
                ['email' => strtolower($data['email'])],
                ['name' => $data['name']],
            );
            DB::table('deal_contacts')->upsert([
                ['deal_id' => $deal, 'contact_id' => $contact->id, 'role' => $data['role']],
            ], ['deal_id', 'contact_id'], ['role']);
            SalesEvent::record($request->user(), 'deal.contact', ['role' => $data['role'], 'contact_id' => $contact->id], $deal);
        });

        return to_route('deals.show', $deal);
    }

    public function activity(Request $request, int $deal): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['note', 'task'])],
            'body' => ['required', 'string', 'max:5000'],
            'due_at' => ['exclude_unless:kind,task', 'nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        DB::transaction(function () use ($request, $deal, $data) {
            Deal::visibleTo($request->user())->lockForUpdate()->findOrFail($deal);
            DealActivity::create([...$data, 'deal_id' => $deal, 'user_id' => $request->user()->id]);
            SalesEvent::record($request->user(), 'deal.activity', ['kind' => $data['kind']], $deal);
        });

        return to_route('deals.show', $deal);
    }

    public function complete(Request $request, int $deal, int $activity): RedirectResponse
    {
        DB::transaction(function () use ($request, $deal, $activity) {
            Deal::visibleTo($request->user())->lockForUpdate()->findOrFail($deal);
            $task = DealActivity::where('deal_id', $deal)->where('kind', 'task')->findOrFail($activity);
            if ($task->completed_at === null) {
                $task->update(['completed_at' => now()]);
                SalesEvent::record($request->user(), 'task.completed', ['task_id' => $task->id], $deal);
            }
        });

        if ($filter = $request->enum('task_filter', TaskFilter::class)) {
            return to_route('tasks', ['filter' => $filter->value]);
        }

        return to_route('deals.show', $deal);
    }

    public function proposal(Request $request, int $deal): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:name,quantity,price'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'discount' => ['required', 'integer', 'between:0,100'],
        ]);

        DB::transaction(function () use ($request, $deal, $data) {
            $current = Deal::visibleTo($request->user())->lockForUpdate()->findOrFail($deal);
            if (in_array($current->stage, ['won', 'lost'], true)) {
                throw ValidationException::withMessages(['items' => 'Сделка закрыта.']);
            }
            $version = Proposal::where('deal_id', $deal)->max('version') + 1;
            if (DB::table('proposal_deliveries')->where('status', 'sending')
                ->whereIn('proposal_id', Proposal::where('deal_id', $deal)->select('id'))->exists()) {
                throw ValidationException::withMessages(['items' => 'Дождитесь завершения отправки текущей версии.']);
            }
            $subtotal = 0;
            foreach ($data['items'] as &$item) {
                $item['price_minor'] = (int) bcmul((string) $item['price'], '100');
                unset($item['price']);
                $subtotal += $item['price_minor'] * $item['quantity'];
            }
            unset($item);

            Proposal::where('deal_id', $deal)->whereIn('status', ['pending', 'approved'])->update(['status' => 'superseded']);
            Proposal::create([
                'deal_id' => $deal,
                'version' => $version,
                'snapshot' => [
                    ...$data,
                    'company' => $current->contact->company->name,
                    'recipient_email' => $current->contact->email,
                    'title' => $current->title,
                    'subtotal_minor' => $subtotal,
                    'total_minor' => intdiv($subtotal * (100 - $data['discount']) + 50, 100),
                ],
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);
            SalesEvent::record($request->user(), 'proposal.created', ['version' => $version], $deal);
        });

        return to_route('deals.show', $deal);
    }

    public function proposalStatus(Request $request, int $deal, int $proposal): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'send', 'answer', 'decision'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:255'],
            'decision' => ['exclude_unless:action,decision', 'required', Rule::in(Proposal::CUSTOMER_DECISIONS)],
        ]);
        $user = $request->user();
        abort_if(in_array($data['action'], ['approve', 'reject'], true) && ! $user->leadsSalesDepartment(), 403);

        DB::transaction(function () use ($deal, $proposal, $data, $user) {
            $current = Deal::visibleTo($user)->lockForUpdate()->findOrFail($deal);
            $version = Proposal::where('deal_id', $deal)->findOrFail($proposal);
            if ($current->stage === 'lost' || ($current->stage === 'won' && ! isset($version->snapshot['booking_change']))) {
                throw ValidationException::withMessages(['action' => 'Сделка закрыта.']);
            }
            if ($data['action'] === 'decision') {
                if ($version->status !== 'answered' || $version->id !== Proposal::where('deal_id', $deal)->latest('version')->value('id')) {
                    throw ValidationException::withMessages(['decision' => 'Зафиксируйте ответ по актуальной версии КП.']);
                }
                if ($version->customer_decision === $data['decision']) {
                    return;
                }
                if (DB::table('hotel_allocations')->where('confirmed_proposal_id', $version->id)->where('status', 'booked')->exists()) {
                    throw ValidationException::withMessages(['decision' => 'КП уже применено к брони. Оформите перенос или отмену.']);
                }
                $version->update(['customer_decision' => $data['decision']]);
                $taskKey = 'proposal-revise:'.$version->id;
                if ($data['decision'] === 'changes_requested') {
                    DealActivity::updateOrCreate(['automation_key' => $taskKey], [
                        'deal_id' => $deal, 'kind' => 'task', 'body' => 'Подготовить изменения к предложению №'.$version->version,
                        'due_at' => now()->addDay(), 'completed_at' => null,
                    ]);
                } else {
                    DealActivity::where('automation_key', $taskKey)->whereNull('completed_at')->update(['completed_at' => now()]);
                }
                SalesEvent::record($user, 'proposal.decision', ['version' => $version->version, 'decision' => $data['decision']], $deal);

                return;
            }
            abort_if(in_array($data['action'], ['approve', 'reject'], true) && $version->created_by === $user->id, 403);
            $expected = match ($data['action']) {
                'send' => 'approved',
                'answer' => 'sent',
                default => 'pending',
            };
            if ($version->status !== $expected) {
                throw ValidationException::withMessages(['action' => 'Действие недоступно для этой версии.']);
            }
            if ($data['action'] === 'send' && DB::table('proposal_deliveries')->where('proposal_id', $proposal)
                ->whereIn('status', ['queued', 'sending'])->exists()) {
                throw ValidationException::withMessages(['action' => 'Письмо уже в обработке.']);
            }

            $days = (int) (Department::find($current->department_id)?->pipeline_settings['reminder_days'] ?? 0);
            $sent = now();
            $version->update(match ($data['action']) {
                'approve' => ['status' => 'approved', 'approved_by' => $user->id],
                'reject' => ['status' => 'rejected', 'rejection_reason' => $data['reason']],
                'send' => ['status' => 'sent', 'sent_at' => $sent, 'follow_up_at' => $days ? $sent->copy()->addDays($days) : null],
                'answer' => ['status' => 'answered'],
            });
            SalesEvent::record($user, 'proposal.'.$data['action'], [
                'version' => $version->version,
                ...($data['action'] === 'reject' ? ['reason' => $data['reason']] : []),
            ], $deal);

            if ($data['action'] === 'answer') {
                DealActivity::where('automation_key', 'proposal-follow-up:'.$version->id)
                    ->whereNull('completed_at')->update(['completed_at' => now()]);
            }
        });

        return to_route('deals.show', $deal);
    }

    public function proposalDocument(Request $request, int $deal, int $proposal, ProposalPdf $pdf): \Illuminate\Http\Response
    {
        Deal::visibleTo($request->user())->findOrFail($deal);
        $version = Proposal::where('deal_id', $deal)->findOrFail($proposal);

        return response($pdf->render($version), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($version).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function proposalDraft(Request $request, int $deal, int $proposal, ProposalPdf $pdf): \Illuminate\Http\Response
    {
        $current = Deal::visibleTo($request->user())->with('contact:id,email')->findOrFail($deal);
        $version = Proposal::where('deal_id', $deal)->whereIn('status', ['approved', 'sent', 'answered'])->findOrFail($proposal);
        $message = (new Email)
            ->from($request->user()->email)
            ->to($version->snapshot['recipient_email'] ?? $current->contact->email)
            ->subject('Коммерческое предложение №'.$version->version.' — '.$version->snapshot['title'])
            ->text("Здравствуйте!\n\nПрикладываем коммерческое предложение на размещение. Ответьте на это письмо, если потребуется уточнить условия.")
            ->attach($pdf->render($version), $pdf->filename($version), 'application/pdf');
        $message->getHeaders()->addTextHeader('X-Unsent', '1');

        return response($message->toString(), 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="proposal-'.$deal.'-'.$version->version.'.eml"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
