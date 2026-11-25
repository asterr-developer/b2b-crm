<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_exports_only_its_bookings_and_reconciles_a_receipt_once(): void
    {
        $manager = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'ERP Test'])->assertSuccessful();
        $manager->refresh();
        $token = str_repeat('r', 48);
        $secret = str_repeat('w', 48);
        $export = route('api.erp.bookings');
        $this->getJson($export)->assertNotFound();
        config(['crm.erp.department_id' => $manager->department_id, 'crm.erp.read_token' => $token, 'crm.erp.write_secret' => $secret]);

        $this->actingAs($manager)->post(route('group-requests.store'), [
            'title' => 'ERP Group', 'source' => 'email', 'company_name' => 'ERP Client', 'contact_name' => 'Guest',
            'email' => 'guest@example.test',
        ])->assertSessionHasNoErrors();
        $group = GroupRequest::sole();
        $this->patch(route('group-requests.qualify', $group), [
            'arrival_on' => today()->addDays(2)->toDateString(), 'departure_on' => today()->addDays(4)->toDateString(), 'guests' => 2,
        ])->assertSessionHasNoErrors();
        $this->post(route('group-requests.convert', $group))->assertSessionHasNoErrors();
        $deal = Deal::sole();
        $this->patch(route('deals.update', $deal), ['amount' => '12000.00'])->assertSessionHasNoErrors();
        $category = DB::table('hotel_room_types')->insertGetId(['department_id' => $manager->department_id, 'property' => 'ERP Hotel', 'name' => 'Twin', 'capacity' => 1]);
        $allocation = DB::table('hotel_allocations')->insertGetId([
            'hotel_room_type_id' => $category, 'group_request_id' => $group->id, 'rooms' => 1, 'status' => 'booked',
            'arrival_on' => $group->refresh()->arrival_on, 'departure_on' => $group->departure_on, 'expires_at' => now(), 'created_at' => now(),
        ]);
        $this->patch(route('deals.payments', [$deal, 'deposit']), [
            'action' => 'plan', 'amount' => '3000.00', 'revision' => 0, 'due_on' => $group->arrival_on,
        ])->assertSessionHasNoErrors();

        $this->getJson($export)->assertUnauthorized();
        $this->getJson($export, ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('bookings.0.rooms.0.id', $allocation)->assertJsonPath('bookings.0.payments.0.amount', '3000.00')
            ->assertJsonPath('bookings.0.payments.0.revision', 1)->assertDontSee('guest@example.test');
        $this->getJson($export.'?after='.$group->id, ['Authorization' => 'Bearer '.$token])->assertJsonCount(0, 'bookings');
        $path = "/api/erp/v1/deals/{$deal->id}/payments/deposit";
        $submit = function (array $payload, string $key, int $lag = 0) use ($path) {
            $raw = json_encode($payload);
            $timestamp = (string) (now()->timestamp - $lag);

            return $this->call('POST', $path, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CRM_TIMESTAMP' => $timestamp,
                'HTTP_X_CRM_SIGNATURE' => hash_hmac('sha256', $timestamp.'.POST.'.$path.'.'.$raw, $key),
            ], $raw);
        };
        $receipt = ['revision' => 1, 'amount' => '3000.00', 'currency' => 'RUB', 'reference' => 'ERP-17'];
        $submit($receipt, $token)->assertUnauthorized();
        $submit($receipt, $secret, 301)->assertUnauthorized();
        $submit([...$receipt, 'amount' => '3000.01'], $secret)->assertConflict();
        $submit([...$receipt, 'revision' => 2], $secret)->assertConflict();
        $other = DB::table('departments')->insertGetId(['name' => 'Other']);
        config(['crm.erp.department_id' => $other]);
        $this->getJson($export, ['Authorization' => 'Bearer '.$token])->assertJsonCount(0, 'bookings');
        $submit($receipt, $secret)->assertNotFound();
        config(['crm.erp.department_id' => $manager->department_id]);
        $submit($receipt, $secret)->assertOk()->assertJsonPath('applied', true);
        $submit($receipt, $secret)->assertOk()->assertJsonPath('applied', false);
        $submit([...$receipt, 'reference' => 'ERP-18'], $secret)->assertConflict();
        $this->assertNotNull(DealActivity::sole()->completed_at);
        $this->assertSame('erp', SalesEvent::where('action', 'payment.paid')->sole()->details['source']);
        DB::table('hotel_allocations')->where('id', $allocation)->update(['status' => 'cancelled']);
        $this->getJson($export, ['Authorization' => 'Bearer '.$token])->assertJsonCount(0, 'bookings');
    }
}
