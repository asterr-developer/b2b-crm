<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_invitation_is_bound_to_email_one_time_and_only_issued_by_leads(): void
    {
        $lead = User::factory()->create();
        $manager = User::factory()->create();
        $outside = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Groups', 'role' => 'lead'])->assertSuccessful();

        $invite = route('team.invite');
        $this->actingAs($manager)->post($invite, ['email' => $outside->email])->assertForbidden();
        $this->actingAs($lead->refresh())->post($invite, ['email' => $manager->email])->assertRedirect(route('team.index'));
        $url = session('team_invite');
        $this->assertNotEmpty($url);

        $this->actingAs($outside)->get($url)->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->actingAs($manager)->get($url)->assertOk();
        $this->post($url)->assertRedirect(route('group-requests.index'));
        $this->assertSame($lead->department_id, $manager->refresh()->department_id);
        $this->post($url)->assertNotFound();
        $this->get(route('team.index'))->assertForbidden();

        $this->actingAs($lead)->delete(route('team.remove', $manager))->assertRedirect(route('team.index'));
        $this->assertNull($manager->refresh()->department_id);
        $this->assertDatabaseHas('sales_events', ['action' => 'team.joined', 'user_id' => $manager->id]);
        $this->assertDatabaseHas('sales_events', ['action' => 'team.removed', 'user_id' => $lead->id]);
    }
}
