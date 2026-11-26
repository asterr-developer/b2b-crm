<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\GroupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EstimateBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_sends_a_draft_request_and_displays_the_accepted_estimate(): void
    {
        $manager = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Estimate Test'])->assertSuccessful();
        $manager->refresh();
        $this->actingAs($manager)->post(route('group-requests.store'), [
            'title' => 'Group', 'source' => 'email', 'company_name' => 'Client',
            'contact_name' => 'Guest', 'email' => 'guest@example.test',
        ])->assertSessionHasNoErrors();
        $group = GroupRequest::sole();
        $this->patch(route('group-requests.qualify', $group), [
            'arrival_on' => today()->addDays(2)->toDateString(),
            'departure_on' => today()->addDays(4)->toDateString(), 'guests' => 2,
        ])->assertSessionHasNoErrors();
        $this->post(route('group-requests.convert', $group))->assertSessionHasNoErrors();
        $deal = Deal::sole();
        $token = str_repeat('x', 40);
        config(['services.estimates' => [
            'url' => 'https://estimate.example.test', 'token' => $token,
            'source' => 'portfolio', 'department_id' => $manager->department_id,
        ]]);
        $url = 'https://estimate.example.test/api/crm/v1/estimates/portfolio:'.$deal->id;
        $this->getJson('/api/estimate/v1/deals')->assertUnauthorized();
        $this->getJson('/api/estimate/v1/deals', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.0.id', $deal->id)->assertDontSee('guest@example.test');
        Http::preventStrayRequests();
        Http::fake([$url => Http::sequence()->pushStatus(404)->push(['draft_id' => 7])->push([
            'draft_id' => 7, 'accepted' => ['number' => '0015', 'total_minor' => 25100, 'token' => str_repeat('a', 64)],
        ])->pushStatus(503)]);
        $this->get(route('deals.show', $deal))->assertOk()->assertInertia(fn (Assert $page) => $page->where('estimate.status', 'new'));
        $this->post(route('deals.estimate', $deal))->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.$token)
            && $request['client'] === 'Client' && !isset($request['email']));
        $this->get(route('deals.show', $deal))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('estimate.url', 'https://estimate.example.test/?draft=7')
            ->where('estimate.accepted.total_minor', 25100));
        $this->get(route('deals.show', $deal))->assertOk()->assertInertia(fn (Assert $page) => $page->where('estimate.status', 'unavailable'));
        $this->actingAs(User::factory()->create())->post(route('deals.estimate', $deal))->assertNotFound();
        Http::assertSentCount(4);
    }
}
