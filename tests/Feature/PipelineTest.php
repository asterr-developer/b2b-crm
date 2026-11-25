<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_can_reassign_a_deal_within_the_department(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $lead = User::factory()->create();
        $outsider = User::factory()->create();

        foreach ([[$first, 'Sales', 'manager'], [$second, 'Sales', 'manager'], [$lead, 'Sales', 'lead'], [$outsider, 'Other', 'lead']] as [$user, $department, $role]) {
            $this->artisan('crm:team-member', ['email' => $user->email, 'department' => $department, 'role' => $role])
                ->assertSuccessful();
        }

        foreach ([$first, $second, $outsider] as $user) {
            $this->actingAs($user->refresh())->post(route('deals.store'), [
                'company' => 'Вектор',
                'contact' => 'Анна',
                'email' => 'anna@example.com',
                'title' => 'Внедрение',
                'amount' => '100',
            ])->assertRedirect(route('dashboard'));
        }

        $deal = Deal::where('assignee_id', $first->id)->sole();
        $path = route('deals.assign', $deal);
        $assignment = ['assignee_id' => $second->id, 'revision' => 0];

        $this->actingAs($first)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('deals', 1)->has('assignees', 0));
        $this->patch($path, $assignment)->assertForbidden();
        $this->actingAs($outsider)->patch($path, $assignment)->assertNotFound();
        $this->actingAs($lead->refresh())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('deals', 2)->has('assignees', 3));
        $this->patch($path, [...$assignment, 'assignee_id' => $outsider->id])->assertSessionHasErrors('assignee_id');
        $this->patch($path, $assignment)->assertRedirect(route('dashboard'));
        $this->patch($path, $assignment)->assertSessionHasErrors('assignee_id');
        $this->assertSame($second->id, $deal->refresh()->assignee_id);

        $this->actingAs($first)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('deals', 0));
        $this->patch(route('deals.move', $deal), ['stage' => 'qualified', 'revision' => 1])->assertNotFound();
        $this->actingAs($second)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('deals', 2));
        $this->patch(route('deals.move', $deal), ['stage' => 'qualified', 'revision' => 1])->assertRedirect(route('dashboard'));
    }

    public function test_owner_can_create_and_progress_a_deal_with_an_audit_trail(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $data = [
            'company' => 'Вектор',
            'contact' => 'Анна Смирнова',
            'email' => 'anna@example.com',
            'title' => 'Внедрение CRM',
            'amount' => '120000.50',
        ];

        $this->post(route('deals.store'), $data)->assertRedirect(route('dashboard'));
        $deal = Deal::sole();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('deals.0.contact.company.name', 'Вектор')
            ->where('deals.0.amount', '120000.50'));

        $this->post(route('deals.store'), [...$data, 'title' => 'Поддержка'])->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('deals', 2);

        $path = route('deals.move', $deal);
        $this->patch($path, ['stage' => 'won', 'revision' => 0])->assertSessionHasErrors('stage');
        $this->patch($path, ['stage' => 'qualified', 'revision' => 0])->assertRedirect(route('dashboard'));
        $this->patch($path, ['stage' => 'proposal', 'revision' => 0])->assertSessionHasErrors('stage');
        $this->assertDatabaseCount('deal_transitions', 1);
        $this->assertDatabaseHas('deal_transitions', [
            'deal_id' => $deal->id,
            'user_id' => $owner->id,
            'from_stage' => 'new',
            'to_stage' => 'qualified',
        ]);
        $this->assertSame('qualified', $deal->refresh()->stage);

        $this->patch($path, ['stage' => 'proposal', 'revision' => 1])->assertRedirect(route('dashboard'));
        $this->patch($path, ['stage' => 'won', 'revision' => 2])->assertRedirect(route('dashboard'));
        $this->assertSame('won', $deal->refresh()->stage);

        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('deals', 0));
        $this->patch($path, ['stage' => 'lost', 'revision' => 3])->assertNotFound();
        $this->assertDatabaseCount('deal_transitions', 3);
    }
}
