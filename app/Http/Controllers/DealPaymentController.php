<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\SalesEvent;
use App\Services\DealPaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealPaymentController extends Controller
{
    public function update(Request $request, int $deal, string $kind, DealPaymentReceipt $receipts)
    {
        abort_unless(in_array($kind, ['deposit', 'balance'], true), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(['plan', 'paid'])],
            'revision' => ['required', 'integer', 'min:0'],
            'amount' => ['exclude_unless:action,plan', 'required', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'due_on' => ['exclude_unless:action,plan', 'required', 'date_format:Y-m-d'],
            'reference' => ['exclude_unless:action,paid', 'required', 'string', 'max:255'],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($deal, $kind, $data, $user, $receipts) {
            $record = Deal::visibleTo($user)->lockForUpdate()->findOrFail($deal);
            $payments = DB::table('deal_payments')->where('deal_id', $deal);
            $payment = (clone $payments)->where('kind', $kind)->first();
            if ($payment?->paid_at && $data['action'] === 'paid' && $payment->reference === $data['reference']) {
                return;
            }
            if ($record->stage === 'lost' || $payment?->paid_at || (int) ($payment?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['action' => 'Платёж уже изменён, получен или сделка закрыта как проигранная.']);
            }
            $label = $kind === 'deposit' ? 'депозит' : 'остаток оплаты';
            $key = ['deal_id' => $deal, 'kind' => $kind];
            $task = 'payment:'.$deal.':'.$kind;
            if ($data['action'] === 'plan') {
                $other = (clone $payments)->where('kind', '!=', $kind)->value('amount') ?? '0';
                if (bccomp(bcadd((string) $other, (string) $data['amount'], 2), $record->amount, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Плановые платежи превышают сумму сделки.']);
                }
                DB::table('deal_payments')->updateOrInsert($key, [
                    'amount' => $data['amount'], 'due_on' => $data['due_on'], 'revision' => (int) ($payment?->revision ?? 0) + 1,
                    'created_at' => $payment?->created_at ?? now(), 'updated_at' => now(),
                ]);
                DealActivity::updateOrCreate(['automation_key' => $task], [
                    'deal_id' => $deal, 'kind' => 'task', 'body' => 'Проверить '.$label,
                    'due_at' => Carbon::parse($data['due_on'], 'Europe/Moscow')->endOfDay()->utc(), 'completed_at' => null,
                ]);
                SalesEvent::record($user, 'payment.plan', ['kind' => $kind, 'amount' => $data['amount']], $deal);
            } else {
                if (! $payment) {
                    throw ValidationException::withMessages(['action' => 'Сначала запланируйте платёж.']);
                }
                $receipts->record($deal, $kind, $payment, $data['reference'], $user);
            }
        }, 5);

        return to_route('deals.show', $deal);
    }
}
