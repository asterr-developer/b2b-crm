<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesForecastSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_forecast_snapshot_freezes_amount_and_is_scoped_to_the_captured_assignment(): void
    {
        $manager = User::factory()->create();
        $lead = User::factory()->create();
        $other = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Groups'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Groups', 'role' => 'lead'])->assertSuccessful();

        $this->travelTo(now()->setDate(2026, 11, 25));
        $this->actingAs($manager->refresh())->post(route('deals.store'), [
            'title' => 'Группа', 'company' => 'Туроператор', 'contact' => 'Вера', 'email' => 'group@example.test', 'amount' => '1000',
        ])->assertRedirect();
        $deal = Deal::sole();
        $this->patch(route('deals.update', $deal), ['expected_close_on' => '2027-01-01'])->assertRedirect();

        $this->artisan('crm:capture-forecast')->assertSuccessful();
        $this->patch(route('deals.update', $deal), ['amount' => '2000'])->assertRedirect();
        $this->artisan('crm:capture-forecast')->assertSuccessful();
        $this->assertDatabaseCount('forecast_snapshots', 1);

        $this->actingAs($lead->refresh())->get(route('reports'))
            ->assertInertia(fn (Assert $page) => $page->where('forecast.2027-01', 20000)
                ->where('forecastHistory.active', '2026-11-25')->where('forecastHistory.totals.2027-01', 10000));

        $this->actingAs($other)->get(route('reports', ['capture' => '2026-11-25']))
            ->assertInertia(fn (Assert $page) => $page->has('forecastHistory.days', 0)->has('forecastHistory.totals', 0));
    }
}
