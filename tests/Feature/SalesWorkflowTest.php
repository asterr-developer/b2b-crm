<?php

namespace Tests\Feature;

use App\Jobs\CreateProposalReminders;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_workflow_from_contacts_through_reminders_and_reporting(): void
    {
        $manager = User::factory()->create();
        $lead = User::factory()->create();
        $outsider = User::factory()->create();

        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Sales'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Sales', 'role' => 'lead'])->assertSuccessful();
        $settings = [
            'labels' => ['new' => 'Входящие', 'qualified' => 'Квалификация', 'proposal' => 'Предложение', 'won' => 'Победа', 'lost' => 'Проигрыш'],
            'close_date_for_proposal' => true,
            'reason_for_loss' => true,
            'reminder_days' => 3,
        ];
        $this->actingAs($lead->refresh())->patch(route('pipeline.settings'), $settings)->assertRedirect();
        $this->actingAs($manager->refresh())->patch(route('pipeline.settings'), $settings)->assertForbidden();
        $this->post(route('deals.store'), [
            'company' => 'Вектор', 'contact' => 'Анна', 'email' => 'anna@example.com', 'title' => 'Внедрение', 'amount' => '1000',
        ])->assertRedirect();
        $id = Deal::sole()->id;
        $this->post(route('deals.contact', $id), [
            'name' => 'Иван', 'email' => 'ivan@example.com', 'role' => 'Закупки',
        ])->assertRedirect();
        $this->post(route('deals.activity', $id), [
            'kind' => 'task', 'body' => 'Позвонить', 'due_at' => '2027-01-10T10:00',
        ])->assertRedirect();
        $task = DealActivity::sole();
        $this->patch(route('deals.complete', [$id, $task->id]))->assertRedirect();
        $this->assertNotNull($task->refresh()->completed_at);
        $this->patch(route('deals.move', $id), ['stage' => 'qualified', 'revision' => 0])->assertRedirect();
        $this->patch(route('deals.move', $id), ['stage' => 'proposal', 'revision' => 1])->assertSessionHasErrors('stage');
        $this->patch(route('deals.update', $id), ['expected_close_on' => '2027-01-10', 'loss_reason' => null])->assertRedirect();
        $this->patch(route('deals.move', $id), ['stage' => 'proposal', 'revision' => 2])->assertRedirect();

        $offer = ['items' => [['name' => 'Внедрение', 'quantity' => 2, 'price' => '100.25']], 'discount' => 10];
        $this->post(route('deals.proposal', $id), $offer)->assertRedirect();
        $first = Proposal::sole();
        $this->assertSame(18045, $first->snapshot['total_minor']);
        $this->patch(route('deals.proposal.status', [$id, $first->id]), ['action' => 'approve'])->assertForbidden();

        $this->actingAs($lead)->patch(route('deals.proposal.status', [$id, $first->id]), ['action' => 'approve'])->assertRedirect();
        $this->actingAs($manager)->post(route('deals.proposal', $id), [...$offer, 'discount' => 15])->assertRedirect();
        $second = Proposal::where('version', 2)->sole();
        $this->assertSame('superseded', $first->refresh()->status);
        $path = route('deals.proposal.status', [$id, $second->id]);
        $this->patch($path, ['action' => 'send'])->assertSessionHasErrors('action');
        $this->actingAs($lead)->patch($path, ['action' => 'approve'])->assertRedirect();
        $this->actingAs($manager)->patch($path, ['action' => 'send'])->assertRedirect();
        $this->get(route('deals.proposal.draft', [$id, $second->id]))->assertOk()
            ->assertHeader('Content-Type', 'message/rfc822')->assertSee('application/pdf', false)->assertSee('X-Unsent: 1', false);
        $pdf = $this->get(route('deals.proposal.pdf', [$id, $second->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('deals.show', $id))->assertInertia(fn (Assert $page) => $page
            ->component('Deal')->where('contacts.0.role', 'Закупки')->has('history', 2)->has('proposals', 2));
        $this->get(route('reports'))->assertInertia(fn (Assert $page) => $page
            ->component('Reports')->where('forecast.2027-01', 70000)->where('conversion.2.count', 1));

        $this->actingAs($outsider)->get(route('deals.proposal.pdf', [$id, $second->id]))->assertNotFound();
        $this->get(route('deals.proposal.draft', [$id, $second->id]))->assertNotFound();
        $this->get(route('reports'))->assertInertia(fn (Assert $page) => $page->where('conversion.0.count', 0));

        $this->travel(2)->days();
        CreateProposalReminders::dispatchSync();
        $this->assertDatabaseCount('deal_activities', 1);
        $this->travel(2)->days();
        CreateProposalReminders::dispatchSync();
        CreateProposalReminders::dispatchSync();
        $this->assertDatabaseCount('deal_activities', 2);
        $this->actingAs($manager)->patch($path, ['action' => 'answer'])->assertRedirect();
        $this->assertNotNull(DealActivity::whereNotNull('automation_key')->sole()->completed_at);

        $this->patch(route('deals.move', $id), ['stage' => 'lost', 'revision' => 3])->assertSessionHasErrors('stage');
        $this->patch(route('deals.update', $id), ['expected_close_on' => '2027-01-10', 'loss_reason' => 'Сроки'])->assertRedirect();
        $this->patch(route('deals.move', $id), ['stage' => 'lost', 'revision' => 4])->assertRedirect();
        $this->get(route('reports'))->assertInertia(fn (Assert $page) => $page
            ->where('losses.0.loss_reason', 'Сроки')->where('losses.0.count', 1));
    }
}
