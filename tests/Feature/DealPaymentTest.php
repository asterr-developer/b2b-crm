<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_plan_is_scoped_and_receipt_closes_one_reminder(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($owner)->post(route('deals.store'), [
            'company' => 'Демо', 'contact' => 'Гость', 'email' => 'sample@example.test', 'title' => 'Размещение', 'amount' => '10000',
        ])->assertSessionHasNoErrors();
        $deal = Deal::sole();
        $deposit = route('deals.payments', [$deal->id, 'deposit']);
        $balance = route('deals.payments', [$deal->id, 'balance']);
        $date = today('Europe/Moscow')->addDays(2)->toDateString();
        $plan = ['action' => 'plan', 'revision' => 0, 'amount' => '2500.25', 'due_on' => $date];
        $this->actingAs($outsider)->patch($deposit, $plan)->assertNotFound();
        $this->actingAs($owner)->patch($deposit, $plan)->assertRedirect(route('deals.show', $deal));
        $this->get(route('deals.show', $deal))->assertInertia(fn (Assert $page) => $page
            ->where('payments.deposit.due_on', $date)->where('payments.deposit.revision', 1)->where('payments.deposit.paid_at', null));
        $task = DealActivity::sole();
        $this->assertSame($date.' 20:59:59', $task->due_at->toDateTimeString());
        $this->patch($balance, [...$plan, 'amount' => '7500'])->assertSessionHasErrors('amount');
        $this->patch($deposit, $plan)->assertSessionHasErrors('action');
        $this->patch($balance, [...$plan, 'amount' => '7499.75'])->assertSessionHasNoErrors();
        $paid = ['action' => 'paid', 'revision' => 1, 'reference' => 'Демо-поручение №17'];
        $this->patch($deposit, $paid)->assertSessionHasNoErrors();
        $this->patch($deposit, $paid)->assertSessionHasNoErrors();
        $this->assertNotNull($task->refresh()->completed_at);
        $this->assertSame(1, SalesEvent::where('action', 'payment.paid')->count());
        $this->patch($deposit, [...$plan, 'revision' => 2])->assertSessionHasErrors('action');
        $this->travel(3)->days();
        $this->get(route('tasks', ['filter' => 'overdue']))->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)->where('tasks.data.0.body', 'Проверить остаток оплаты'));
        $this->patch(route('deals.move', $deal), ['stage' => 'lost', 'revision' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, DealActivity::whereNull('completed_at')->count());
    }
}
