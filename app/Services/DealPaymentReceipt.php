<?php

namespace App\Services;

use App\Models\DealActivity;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DealPaymentReceipt
{
    public function record(int $deal, string $kind, object $payment, string $reference, ?User $actor = null): void
    {
        DB::table('deal_payments')->where('deal_id', $deal)->where('kind', $kind)
            ->increment('revision', 1, ['paid_at' => now(), 'reference' => $reference, 'updated_at' => now()]);
        DealActivity::where('automation_key', 'payment:'.$deal.':'.$kind)->whereNull('completed_at')->update(['completed_at' => now()]);
        SalesEvent::record($actor, 'payment.paid', ['kind' => $kind, 'amount' => $payment->amount, 'source' => $actor ? 'manual' : 'erp'], $deal);
    }
}
