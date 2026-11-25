<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GroupRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_queue_tracks_first_response_and_enforces_department_visibility(): void
    {
        $this->travelTo(now()->startOfMinute());
        $manager = User::factory()->create();
        $lead = User::factory()->create();
        $colleague = User::factory()->create();
        foreach ([[$manager, 'manager'], [$lead, 'lead'], [$colleague, 'manager']] as [$user, $role]) {
            $this->artisan('crm:team-member', ['email' => $user->email, 'department' => 'Groups', 'role' => $role])->assertSuccessful();
            $user->refresh();
        }
        $this->actingAs($manager)->patch(route('work.settings'), ['first_response_hours' => 1])->assertForbidden();
        $this->actingAs($lead)->patch(route('work.settings'), ['first_response_hours' => 1])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('group-requests.store'), [
            'title' => 'Делегация', 'source' => 'phone', 'company_name' => 'Example',
            'contact_name' => 'Тест', 'email' => 'reply@example.test',
        ])->assertSessionHasNoErrors();
        $group = GroupRequest::sole();
        $this->get(route('work.index'))->assertInertia(fn (Assert $page) => $page->component('Work')
            ->where('waiting', 1)->where('late', 0)->where('hours', 1));
        $this->travel(90)->minutes();
        $this->actingAs($colleague)->get(route('work.index', ['filter' => 'late']))
            ->assertInertia(fn (Assert $page) => $page->where('late', 0)->has('requests.data', 0));
        $contact = route('work.contact', $group);
        $data = ['channel' => 'phone', 'summary' => 'Уточнили дату заезда'];
        $this->post($contact, $data)->assertNotFound();
        $this->actingAs($lead)->get(route('work.index', ['filter' => 'late']))
            ->assertInertia(fn (Assert $page) => $page->where('late', 1)->where('metrics.breached', 1)
                ->where('requests.data.0.id', $group->id));
        $this->actingAs($manager)->post($contact, ['channel' => 'phone'])->assertSessionHasErrors('summary');
        $this->post($contact, $data)->assertSessionHasNoErrors();
        $this->post($contact, [...$data, 'summary' => 'Повтор'])->assertSessionHasNoErrors();
        $event = SalesEvent::where('action', 'request.first_contact')->sole();
        $this->assertEquals($manager->id, $event->user_id);
        $this->assertSame($data, $event->details);
        $this->get(route('work.index', ['filter' => 'contacted']))->assertInertia(fn (Assert $page) => $page
            ->where('waiting', 0)->where('late', 0)->where('metrics.contacted', 1)->where('metrics.breached', 1)
            ->where('metrics.median', 90)->where('metrics.p90', 90)->where('requests.data.0.late', true));
    }

    public function test_intake_reuses_team_client_and_converts_only_once(): void
    {
        $manager = User::factory()->create();
        $colleague = User::factory()->create();
        $lead = User::factory()->create();
        $outsider = User::factory()->create();
        foreach ([[$manager, 'manager'], [$colleague, 'manager'], [$lead, 'lead']] as [$user, $role]) {
            $this->artisan('crm:team-member', ['email' => $user->email, 'department' => 'Groups', 'role' => $role])->assertSuccessful();
        }

        $payload = [
            'title' => 'Выездная конференция', 'source' => 'email', 'company_name' => 'Nord Travel',
            'contact_name' => 'Вера Орлова', 'email' => 'groups@example.test',
        ];
        $this->actingAs($manager->refresh())->post(route('group-requests.store'), $payload)->assertRedirect(route('group-requests.index'));
        $group = GroupRequest::sole();
        $company = Company::sole();

        $this->actingAs($colleague->refresh())->get(route('group-requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('companies', 1)->has('requests.data', 0));
        $this->post(route('group-requests.store'), [...$payload, 'company_name' => 'NORD TRAVEL'])->assertSessionHasErrors('company_name');
        $this->post(route('group-requests.store'), [...$payload, 'company_id' => $company->id, 'title' => 'Вторая группа'])->assertRedirect();
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('deals', 0);

        $this->actingAs($outsider)->post(route('group-requests.store'), [...$payload, 'company_id' => $company->id])->assertNotFound();
        $this->get(route('group-requests.index'))->assertInertia(fn (Assert $page) => $page->has('companies', 0)->has('requests.data', 0));

        $convert = route('group-requests.convert', $group);
        $this->post($convert)->assertNotFound();
        $this->actingAs($manager)->post($convert)->assertSessionHasErrors('conversion');
        $qualify = route('group-requests.qualify', $group);
        $this->patch($qualify, ['arrival_on' => '2027-02-02', 'departure_on' => '2027-02-01', 'guests' => 0])->assertSessionHasErrors(['departure_on', 'guests']);
        $this->patch($qualify, ['arrival_on' => '2027-02-02', 'departure_on' => '2027-02-04', 'guests' => 24])->assertRedirect();

        $this->post($convert)->assertRedirect();
        $deal = Deal::sole();
        $this->actingAs($lead->refresh())->post($convert)->assertRedirect(route('deals.show', $deal));
        $this->assertDatabaseCount('deals', 1);
        $this->assertSame(1, SalesEvent::where('action', 'request.converted')->count());
        $this->get(route('deals.show', $deal))->assertInertia(fn (Assert $page) => $page
            ->where('groupRequest.guests', 24)->where('events.0.action', 'request.converted'));
        $this->patch(route('deals.update', $deal), ['amount' => '120000.50', 'revision' => 0])->assertRedirect();
        $this->patch(route('deals.update', $deal), ['amount' => '1', 'revision' => 0])->assertSessionHasErrors('revision');
        $this->assertSame('120000.50', $deal->refresh()->amount);
        $this->actingAs($colleague)->get(route('deals.show', $deal))->assertNotFound();
    }
}
