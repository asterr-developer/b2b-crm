<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Services\DealPaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpController extends Controller
{
    public function bookings(Request $request)
    {
        $token = config('crm.erp.read_token');
        abort_unless(is_string($token) && strlen($token) >= 32, 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);
        $department = $this->department();
        $after = $request->validate(['after' => ['nullable', 'integer', 'min:0']])['after'] ?? 0;

        $groups = DB::table('group_requests as groups')->join('deals', 'deals.id', '=', 'groups.deal_id')
            ->where('groups.department_id', $department)->where('deals.department_id', $department)
            ->where('groups.id', '>', $after)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('hotel_allocations as allocation')
                ->whereColumn('allocation.group_request_id', 'groups.id')->where('allocation.status', 'booked'))
            ->orderBy('groups.id')->limit(101)->select(['groups.id', 'groups.deal_id', 'groups.arrival_on', 'groups.departure_on', 'groups.guests', 'deals.amount']);
        $bookings = DB::query()->fromSub($groups, 'booking')
            ->join('hotel_allocations as allocation', 'allocation.group_request_id', '=', 'booking.id')
            ->join('hotel_room_types as category', 'category.id', '=', 'allocation.hotel_room_type_id')
            ->leftJoin('deal_payments as payment', 'payment.deal_id', '=', 'booking.deal_id')
            ->where('allocation.status', 'booked')->where('category.department_id', $department)
            ->orderBy('booking.id')->orderBy('allocation.id')->orderBy('payment.id')
            ->get(['booking.*', 'allocation.id as position_id', 'allocation.rooms', 'category.property', 'category.name as category',
                'payment.kind', 'payment.amount as payment_amount', 'payment.due_on', 'payment.paid_at', 'payment.revision'])
            ->groupBy('id');
        $page = $bookings->take(100);

        return response()->json([
            'bookings' => $page->values()->map(function ($rows) {
                $row = $rows->first();

                return [
                    'booking_id' => $row->id, 'deal_id' => $row->deal_id,
                    'arrival_on' => $row->arrival_on, 'departure_on' => $row->departure_on, 'guests' => $row->guests,
                    'amount' => bcadd((string) $row->amount, '0', 2), 'currency' => 'RUB',
                    'rooms' => $rows->unique('position_id')->values()->map(fn ($position) => [
                        'id' => $position->position_id, 'property' => $position->property, 'category' => $position->category, 'rooms' => $position->rooms,
                    ]),
                    'payments' => $rows->whereNotNull('kind')->unique('kind')->values()->map(fn ($payment) => [
                        'kind' => $payment->kind, 'amount' => bcadd((string) $payment->payment_amount, '0', 2), 'due_on' => $payment->due_on,
                        'paid' => $payment->paid_at !== null, 'revision' => $payment->revision,
                    ]),
                ];
            }),
            'next_after' => $bookings->count() > 100 ? $page->keys()->last() : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function payment(Request $request, int $deal, string $kind, DealPaymentReceipt $receipts)
    {
        $secret = config('crm.erp.write_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 404);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 4096, 413);
        $timestamp = (string) $request->header('X-CRM-Timestamp');
        $signature = (string) $request->header('X-CRM-Signature');
        $signed = $timestamp.".POST./api/erp/v1/deals/{$deal}/payments/{$kind}.".$raw;
        abort_unless(preg_match('/^\d{1,12}$/D', $timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $signed, $secret), $signature), 401);
        abort_unless($request->isJson(), 415);
        $department = $this->department();
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'decimal:2', 'min:0.01', 'max:999999999999.99'],
            'currency' => ['required', 'in:RUB'],
            'reference' => ['required', 'string', 'max:255'],
        ]);

        $applied = DB::transaction(function () use ($department, $deal, $kind, $data, $receipts) {
            $record = Deal::where('department_id', $department)->lockForUpdate()->findOrFail($deal);
            $payment = DB::table('deal_payments')->where('deal_id', $deal)->where('kind', $kind)->first();
            abort_unless($payment, 404);
            abort_unless(bccomp((string) $payment->amount, $data['amount'], 2) === 0, 409);
            if ($payment->paid_at && $payment->reference === $data['reference']) {
                return false;
            }
            abort_if($record->stage === 'lost' || $payment->paid_at || (int) $payment->revision !== (int) $data['revision'], 409);
            $receipts->record($deal, $kind, $payment, $data['reference']);

            return true;
        }, 5);

        return response()->json(['paid' => true, 'applied' => $applied])->header('Cache-Control', 'private, no-store');
    }

    private function department(): int
    {
        $department = filter_var(config('crm.erp.department_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($department && DB::table('departments')->where('id', $department)->exists(), 404);

        return $department;
    }
}
