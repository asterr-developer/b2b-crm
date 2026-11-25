<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\GroupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_archives_source_and_keeps_deal_and_request_links(): void
    {
        $lead = User::factory()->create();
        $manager = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Groups', 'role' => 'lead'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Groups'])->assertSuccessful();

        $this->actingAs($manager->refresh());
        $input = ['title' => 'Конференция', 'source' => 'email', 'contact_name' => 'Анна', 'email' => 'anna@example.test'];
        $this->post(route('group-requests.store'), [...$input, 'company_name' => 'Nord Travel'])->assertRedirect();
        $group = GroupRequest::sole();
        $source = $group->company;
        $oldContact = $group->contact_id;
        $this->post(route('group-requests.store'), [...$input, 'company_name' => 'Nord Travel LLC', 'confirmed_distinct' => true])->assertRedirect();
        $target = Company::whereKeyNot($source->id)->sole();
        $newContact = $target->contacts()->sole()->id;
        $path = route('clients.merge', $source);
        $payload = ['target' => $target->id, 'confirmed' => true];
        $this->post($path, $payload)->assertForbidden();

        $this->patch(route('group-requests.qualify', $group), [
            'arrival_on' => '2027-02-10', 'departure_on' => '2027-02-12', 'guests' => 24,
        ])->assertRedirect();
        $this->post(route('group-requests.convert', $group))->assertRedirect();
        $deal = Deal::sole();

        $this->actingAs($lead->refresh())->post($path, [...$payload, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post($path, $payload)->assertRedirect(route('clients.show', $target));
        $this->get(route('clients.show', $source))->assertRedirect(route('clients.show', $target));
        $this->get(route('clients.index', ['search' => 'Nord Travel']))->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));
        $this->assertSame($deal->id, $group->refresh()->deal_id);
        $this->assertSame($target->id, $group->company_id);
        $this->assertSame($newContact, $deal->refresh()->contact_id);
        $this->assertDatabaseHas('contacts', ['id' => $oldContact, 'company_id' => $source->id]);
        $this->assertDatabaseHas('sales_events', ['action' => 'deal.client_merged', 'deal_id' => $deal->id]);
        $this->assertDatabaseCount('deals', 1);
    }
}
