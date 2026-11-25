<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskListTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_filters_and_completion_follow_deal_permissions(): void
    {
        $manager = User::factory()->create();
        $lead = User::factory()->create();
        $outsider = User::factory()->create();

        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Sales'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Sales', 'role' => 'lead'])->assertSuccessful();

        $this->actingAs($manager->refresh())->post(route('deals.store'), [
            'company' => 'Вектор', 'contact' => 'Анна', 'email' => 'anna@example.com', 'title' => 'Внедрение', 'amount' => '100',
        ])->assertRedirect();

        $deal = Deal::sole();
        $overdue = DealActivity::create(['deal_id' => $deal->id, 'kind' => 'task', 'body' => 'Связаться', 'due_at' => now()->subDay()]);
        DealActivity::create(['deal_id' => $deal->id, 'kind' => 'task', 'body' => 'Подготовить материалы']);
        DealActivity::create(['deal_id' => $deal->id, 'kind' => 'note', 'body' => 'Заметка']);

        $this->get(route('tasks'))->assertInertia(fn (Assert $page) => $page
            ->component('Tasks')->where('filter', 'open')->has('tasks.data', 2)->where('tasks.data.0.id', $overdue->id));

        $complete = route('deals.complete', ['deal' => $deal->id, 'activity' => $overdue->id, 'task_filter' => 'overdue']);
        $this->actingAs($outsider)->get(route('tasks'))->assertInertia(fn (Assert $page) => $page->has('tasks.data', 0));
        $this->patch($complete)->assertNotFound();
        $this->get(route('tasks.calendar', $overdue))->assertNotFound();

        $this->actingAs($lead->refresh())->get(route('tasks', ['filter' => 'overdue']))
            ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.deal.assignee.name', $manager->name));
        $this->get(route('tasks.calendar', $overdue))->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')
            ->assertSee('BEGIN:VEVENT', false)->assertSee('SUMMARY:Связаться', false);
        $this->patch($complete)->assertRedirect(route('tasks', ['filter' => 'overdue']));
        $this->get(route('tasks', ['filter' => 'overdue']))->assertInertia(fn (Assert $page) => $page->has('tasks.data', 0));
        $this->get(route('tasks', ['filter' => 'completed']))
            ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.id', $overdue->id));
    }
}
