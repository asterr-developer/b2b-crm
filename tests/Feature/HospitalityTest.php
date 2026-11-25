<?php

namespace Tests\Feature;

use App\Models\DealActivity;
use App\Models\GroupRequest;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HospitalityTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $user->email, 'department' => 'Hotel', 'role' => $role])->assertSuccessful();

        return $user->refresh();
    }

    private function group(string $name): GroupRequest
    {
        $this->post(route('group-requests.store'), [
            'title' => $name, 'source' => 'email', 'company_name' => $name,
            'contact_name' => 'Demo', 'email' => strtolower($name).'@example.test',
        ])->assertRedirect();
        $group = GroupRequest::where('title', $name)->sole();
        $this->patch(route('group-requests.qualify', $group), [
            'arrival_on' => today()->addDays(5)->toDateString(), 'departure_on' => today()->addDays(7)->toDateString(), 'guests' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $group->refresh();
    }

    public function test_nightly_capacity_blocks_only_the_affected_night_and_preserves_existing_reservations(): void
    {
        $manager = $this->member('manager');
        $lead = $this->member('lead');
        $this->actingAs($lead)->post(route('hospitality.category'), ['property' => 'Demo', 'name' => 'Twin', 'capacity' => 2])->assertSessionHasNoErrors();
        $category = DB::table('hotel_room_types')->value('id');
        $this->actingAs($manager);
        $group = $this->group('Calendar');
        $night = today()->addDays(6)->toDateString();
        $override = ['category' => $category, 'night' => $night, 'capacity' => 0, 'reason' => 'Обслуживание'];
        $path = route('hospitality.capacity');
        $this->patch($path, $override)->assertForbidden();
        $this->actingAs($lead)->patch($path, $override)->assertSessionHasNoErrors();
        $this->actingAs($manager)->get(route('hospitality.index', [
            'arrival' => $group->arrival_on, 'departure' => $group->departure_on, 'calendar_category' => $category,
        ]))->assertInertia(fn (Assert $page) => $page->has('calendar', 2)
            ->where('categories.0.limiting_night', $night)->where('calendar.0.available', 2)
            ->where('calendar.1.available', 0)->where('calendar.1.adjusted', true));
        $reserve = ['group' => $group->id, 'category' => $category, 'rooms' => 1, 'hours' => 24];
        $this->post(route('hospitality.reserve'), $reserve)->assertSessionHasErrors(['rooms' => 'На одну или несколько ночей не хватает номеров. Twin · '.$night.': доступно 0.']);
        $this->actingAs($lead)->patch($path, [...$override, 'capacity' => null])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('hospitality.reserve'), $reserve)->assertSessionHasNoErrors();
        $this->actingAs($lead)->patch($path, $override)->assertSessionHasErrors('capacity');
        $this->assertDatabaseCount('hotel_capacity_overrides', 0);
        $this->assertDatabaseHas('hotel_allocations', ['group_request_id' => $group->id, 'status' => 'held']);
    }

    public function test_last_room_expiry_and_booking_handoff(): void
    {
        $manager = $this->member('manager');
        $lead = $this->member('lead');
        $other = $this->member('manager');
        $this->actingAs($manager)->patch(route('hospitality.rules'), [])->assertForbidden();
        $this->actingAs($lead)->patch(route('hospitality.rules'), ['hold_hours' => 12, 'handoff_task' => true])->assertRedirect();
        $this->post(route('hospitality.category'), ['property' => 'Demo', 'name' => 'Twin', 'capacity' => 1])->assertRedirect();
        $category = DB::table('hotel_room_types')->value('id');
        $this->actingAs($manager);
        $first = $this->group('Alpha');
        $second = $this->group('Beta');
        $reserve = ['category' => $category, 'rooms' => 1, 'hours' => 1];
        $this->post(route('hospitality.reserve'), [...$reserve, 'group' => $first->id])->assertSessionHasNoErrors();
        $this->post(route('hospitality.reserve'), [...$reserve, 'group' => $second->id])->assertSessionHasErrors('rooms');
        $this->get(route('hospitality.index', ['arrival' => $first->arrival_on, 'departure' => $first->departure_on]))
            ->assertInertia(fn (Assert $page) => $page->where('categories.0.available', 0)->where('rules.hold_hours', 12));
        $this->travel(61)->minutes();
        $this->post(route('hospitality.reserve'), [...$reserve, 'group' => $second->id])->assertSessionHasNoErrors();
        $this->artisan('crm:expire-reserves')->assertSuccessful();
        $this->assertDatabaseHas('hotel_allocations', ['group_request_id' => $first->id, 'status' => 'expired']);
        $id = DB::table('hotel_allocations')->where('group_request_id', $second->id)->value('id');
        $path = route('hospitality.allocation', $id);
        $this->actingAs($other)->patch($path, ['action' => 'release'])->assertNotFound();
        $this->actingAs($manager)->patch($path, ['action' => 'book'])->assertSessionHasErrors('allocation');
        $this->post(route('group-requests.convert', $second))->assertSessionHasNoErrors();
        $deal = $second->refresh()->deal_id;
        $this->post(route('deals.proposal', $deal), ['items' => [['name' => 'Twin', 'price' => '100', 'quantity' => 1]], 'discount' => 0])->assertSessionHasNoErrors();
        $proposal = Proposal::sole();
        $status = route('deals.proposal.status', [$deal, $proposal->id]);
        $this->actingAs($lead)->patch($status, ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->patch($status, ['action' => 'send'])->assertSessionHasNoErrors();
        $this->patch($status, ['action' => 'answer'])->assertSessionHasNoErrors();
        $this->patch($path, ['action' => 'book'])->assertSessionHasErrors('allocation');
        $this->patch($status, ['action' => 'decision', 'decision' => 'declined'])->assertSessionHasNoErrors();
        $this->patch($path, ['action' => 'book'])->assertSessionHasErrors('allocation');
        $this->patch($status, ['action' => 'decision', 'decision' => 'changes_requested'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deal_activities', ['automation_key' => 'proposal-revise:'.$proposal->id, 'completed_at' => null]);
        $this->patch($status, ['action' => 'decision', 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->patch(route('deals.update', $deal), ['amount' => '100', 'revision' => 0])->assertSessionHasNoErrors();
        $move = route('deals.move', $deal);
        $this->patch($move, ['stage' => 'qualified', 'revision' => 1])->assertSessionHasNoErrors();
        $this->patch($move, ['stage' => 'proposal', 'revision' => 2])->assertSessionHasNoErrors();
        $this->patch($move, ['stage' => 'won', 'revision' => 3])->assertSessionHasErrors(['stage' => 'Подтвердите бронь по актуальной версии КП.']);
        $this->patch($path, ['action' => 'book'])->assertSessionHasNoErrors();
        $this->patch($path, ['action' => 'book'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hotel_allocations', ['id' => $id, 'status' => 'booked', 'confirmed_proposal_id' => $proposal->id]);
        $this->assertSame(1, DealActivity::where('automation_key', 'hotel-handoff:'.$id)->count());
        $handoff = route('hospitality.handoff', $id);
        $this->patch($handoff, ['decision' => 'accepted'])->assertForbidden();
        $lodging = $this->member('lodging');
        $this->actingAs($lodging)->get(route('dashboard'))->assertRedirect(route('hospitality.index'));
        $this->get(route('deals.show', $deal))->assertForbidden();
        $this->get(route('inbound-replies.index'))->assertForbidden();
        $this->patch(route('hospitality.amend', $id), ['action' => 'cancel', 'reason' => 'Нет права'])->assertForbidden();
        $this->get(route('hospitality.index'))->assertInertia(fn (Assert $page) => $page->where('lodging', true)
            ->has('categories', 0)->has('requests', 0)->has('allocations', 1));
        $preview = route('hospitality.guests.preview', $id);
        $guestList = route('hospitality.guests', $id);
        $saveGuests = route('hospitality.guests.store', $id);
        $csv = "name,allocation,room\nАнна Тестова,{$id},1\n";
        $this->actingAs($other)->get($guestList)->assertNotFound();
        $this->postJson($preview, ['csv' => $csv])->assertNotFound();
        $this->actingAs($lodging)->postJson($preview, ['csv' => str_replace(',1', ',2', $csv)])->assertUnprocessable();
        $this->postJson($preview, ['csv' => $csv])->assertOk()->assertJsonPath('version', 0)->assertJsonPath('guests.0.name', 'Анна Тестова');
        $this->assertDatabaseCount('hotel_guest_lists', 0);
        $this->post($saveGuests, ['csv' => $csv, 'version' => 0])->assertRedirect($guestList);
        $this->post($saveGuests, ['csv' => $csv, 'version' => 0])->assertRedirect($guestList);
        $modified = str_replace('Анна', 'Мария', $csv);
        $this->post($saveGuests, ['csv' => $modified, 'version' => 0])->assertSessionHasErrors('csv');
        $this->post($saveGuests, ['csv' => $modified, 'version' => 1])->assertRedirect($guestList);
        $this->assertDatabaseCount('hotel_guest_lists', 2);
        $this->get(route('hospitality.guests', ['allocation' => $id, 'version' => 1]))
            ->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
                ->where('selectedVersion', 1)->where('guests.0.name', 'Анна Тестова')->has('versions', 2));
        $this->patch($handoff, ['decision' => 'returned'])->assertSessionHasErrors('reason');
        $this->patch($handoff, ['decision' => 'returned', 'reason' => 'Уточнить состав группы'])->assertSessionHasNoErrors();
        $this->get(route('hospitality.index'))->assertInertia(fn (Assert $page) => $page
            ->where('handoffs.'.$id.'.status', 'returned')->where('handoffs.'.$id.'.message', 'Уточнить состав группы'));
        $this->patch($handoff, ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->patch($handoff, ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertNotNull(DealActivity::where('automation_key', 'hotel-handoff:'.$id)->sole()->completed_at);
        $this->assertSame(1, DB::table('sales_events')->where('action', 'hotel.handoff_accepted')->count());
        $this->actingAs($manager);
        $this->patch($move, ['stage' => 'won', 'revision' => 3])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deals', ['id' => $deal, 'stage' => 'won']);
    }

    public function test_outbox_recovers_unconfirmed_attempt_and_sends_one_pdf_to_test_transport(): void
    {
        config(['crm.proposal_email' => true, 'mail.default' => 'array']);
        $manager = $this->member('manager');
        $lead = $this->member('lead');
        $this->actingAs($manager);
        $group = $this->group('Gamma');
        $this->post(route('group-requests.convert', $group))->assertSessionHasNoErrors();
        $deal = $group->refresh()->deal_id;
        $this->post(route('deals.proposal', $deal), ['items' => [['name' => 'Twin', 'price' => '100', 'quantity' => 1]], 'discount' => 0])->assertSessionHasNoErrors();
        $proposal = Proposal::sole();
        $status = route('deals.proposal.status', [$deal, $proposal->id]);
        $path = route('deals.proposal.email', [$deal, $proposal->id]);
        $this->post($path)->assertSessionHasErrors('delivery');
        $this->actingAs($lead)->patch($status, ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($path)->assertSessionHasNoErrors();
        $this->post($path)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('proposal_deliveries', 1);
        $this->patch($status, ['action' => 'send'])->assertSessionHasErrors('action');
        DB::table('proposal_deliveries')->update(['status' => 'sending', 'attempts' => 1, 'updated_at' => now()->subMinutes(16)]);
        $this->artisan('crm:deliver-proposals')->assertSuccessful();
        $this->assertDatabaseHas('proposal_deliveries', ['status' => 'unknown']);
        $this->post($path)->assertSessionHasErrors('delivery');
        $this->post($path, ['retry' => true])->assertSessionHasNoErrors();
        $this->artisan('crm:deliver-proposals')->assertSuccessful();
        $this->artisan('crm:deliver-proposals')->assertSuccessful();
        $this->assertDatabaseHas('proposal_deliveries', ['status' => 'sent', 'attempts' => 2]);
        $this->assertSame('sent', $proposal->refresh()->status);
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame('application/pdf', $message->getAttachments()[0]->getMediaType().'/'.$message->getAttachments()[0]->getMediaSubtype());
        $this->assertSame([\App\Services\InboundReplies::messageId($proposal->id)], $message->getHeaders()->get('Message-ID')->getBody());
    }

    public function test_mixed_booking_moves_atomically_and_can_be_cancelled(): void
    {
        $manager = $this->member('manager');
        $lead = $this->member('lead');
        $outsider = $this->member('manager');
        $this->actingAs($lead);
        foreach (['Twin', 'Single'] as $name) {
            $this->post(route('hospitality.category'), ['property' => 'Demo', 'name' => $name, 'capacity' => 1])->assertSessionHasNoErrors();
        }
        $categories = DB::table('hotel_room_types')->pluck('id', 'name');
        $positions = [['category' => $categories['Twin'], 'rooms' => 1], ['category' => $categories['Single'], 'rooms' => 1]];
        $this->actingAs($manager);
        $group = $this->group('Mixed');
        $other = $this->group('Blocker');
        $this->post(route('hospitality.reserve'), ['group' => $other->id, 'positions' => [$positions[1]], 'hours' => 24])->assertSessionHasNoErrors();
        $this->post(route('hospitality.reserve'), ['group' => $group->id, 'positions' => $positions, 'hours' => 24])->assertSessionHasErrors('rooms');
        $this->assertDatabaseMissing('hotel_allocations', ['group_request_id' => $group->id]);
        $this->patch(route('hospitality.allocation', DB::table('hotel_allocations')->value('id')), ['action' => 'release'])->assertSessionHasNoErrors();
        $this->post(route('hospitality.reserve'), ['group' => $group->id, 'positions' => $positions, 'hours' => 24])->assertSessionHasNoErrors();
        $this->post(route('group-requests.convert', $group))->assertSessionHasNoErrors();
        $deal = $group->refresh()->deal_id;
        $this->post(route('deals.proposal', $deal), ['items' => [['name' => 'Twin', 'price' => '100', 'quantity' => 1]], 'discount' => 0])->assertSessionHasNoErrors();
        $status = route('deals.proposal.status', [$deal, Proposal::sole()->id]);
        $this->actingAs($lead)->patch($status, ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->patch($status, ['action' => 'send'])->assertSessionHasNoErrors();
        $this->patch($status, ['action' => 'answer'])->assertSessionHasNoErrors();
        $this->patch($status, ['action' => 'decision', 'decision' => 'accepted'])->assertSessionHasNoErrors();
        $allocations = DB::table('hotel_allocations')->where('group_request_id', $group->id);
        $id = (clone $allocations)->max('id');
        $this->patch(route('hospitality.allocation', $id), ['action' => 'book'])->assertSessionHasNoErrors();
        $this->assertSame(2, (clone $allocations)->where('status', 'booked')->count());
        $this->patch(route('deals.update', $deal), ['amount' => '100', 'revision' => 0])->assertSessionHasNoErrors();
        $moveStage = route('deals.move', $deal);
        $this->patch($moveStage, ['stage' => 'qualified', 'revision' => 1])->assertSessionHasNoErrors();
        $this->patch($moveStage, ['stage' => 'proposal', 'revision' => 2])->assertSessionHasNoErrors();
        $this->patch($moveStage, ['stage' => 'won', 'revision' => 3])->assertSessionHasNoErrors();

        $arrival = today()->addDays(8)->toDateString();
        $departure = today()->addDays(10)->toDateString();
        $this->patch(route('group-requests.qualify', $other), ['arrival_on' => $arrival, 'departure_on' => $departure, 'guests' => 2])->assertSessionHasNoErrors();
        $path = route('hospitality.amend', $id);
        $move = ['action' => 'propose', 'reason' => 'Клиент сменил даты', 'arrival_on' => $arrival, 'departure_on' => $departure, 'guests' => 3, 'positions' => $positions, 'amount' => '450.25'];
        $this->actingAs($outsider)->patch($path, $move)->assertNotFound();
        $this->actingAs($manager)->patch($path, $move)->assertRedirect(route('deals.show', $deal));
        $this->patch($path, $move)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('proposals', 2);
        $draft = Proposal::latest('version')->firstOrFail();
        $apply = ['action' => 'move', 'proposal_id' => $draft->id];
        $this->patch($path, $apply)->assertSessionHasErrors('action');
        $approveMove = function (Proposal $proposal) use ($deal, $lead, $manager) {
            $status = route('deals.proposal.status', [$deal, $proposal->id]);
            $this->actingAs($lead)->patch($status, ['action' => 'approve'])->assertSessionHasNoErrors();
            $this->actingAs($manager)->patch($status, ['action' => 'send'])->assertSessionHasNoErrors();
            $this->patch($status, ['action' => 'answer'])->assertSessionHasNoErrors();
            $this->patch($status, ['action' => 'decision', 'decision' => 'accepted'])->assertSessionHasNoErrors();
        };
        $approveMove($draft);
        $this->post(route('hospitality.reserve'), ['group' => $other->id, 'positions' => [$positions[1]], 'hours' => 24])->assertSessionHasNoErrors();
        $this->patch($path, $apply)->assertSessionHasErrors('rooms');
        $this->assertSame(2, (clone $allocations)->where('status', 'booked')->where('arrival_on', $group->arrival_on)->count());
        $this->assertSame($group->arrival_on, GroupRequest::findOrFail($group->id)->arrival_on);
        $this->assertDatabaseHas('deals', ['id' => $deal, 'amount' => 100]);
        $this->patch($path, [...$move, 'positions' => [$positions[0]]])->assertSessionHasNoErrors();
        $this->patch($path, $apply)->assertSessionHasErrors('action');
        $revised = Proposal::latest('version')->firstOrFail();
        $approveMove($revised);
        $apply = ['action' => 'move', 'proposal_id' => $revised->id];
        $this->patch($path, $apply)->assertSessionHasNoErrors();
        $this->patch($path, $apply)->assertSessionHasNoErrors();
        $this->assertSame(2, (clone $allocations)->where('status', 'replaced')->count());
        $replacement = (clone $allocations)->where('status', 'booked')->sole();
        $this->assertSame($arrival, $replacement->arrival_on);
        $this->assertEquals($revised->id, $replacement->confirmed_proposal_id);
        $this->assertDatabaseHas('deals', ['id' => $deal, 'amount' => 450.25, 'stage' => 'won']);
        $this->patch($path, $move)->assertSessionHasErrors('action');
        $this->patch(route('hospitality.amend', $replacement->id), ['action' => 'cancel', 'reason' => 'Клиент отменил поездку'])->assertSessionHasNoErrors();
        $this->assertSame(0, (clone $allocations)->where('status', 'booked')->count());
        $this->assertDatabaseHas('hotel_allocations', ['id' => $replacement->id, 'status' => 'cancelled']);
        $this->assertSame(1, DealActivity::where('deal_id', $deal)->whereNull('completed_at')->count());
    }

    public function test_intake_suggestions_keep_source_and_never_save(): void
    {
        config(['crm.ollama_model' => null]);
        $this->actingAs($this->member('manager'));
        $path = route('hospitality.suggestions');
        $text = 'Заезд 2028-05-12, выезд 2028-05-14, 24 гостя.';
        $this->postJson($path, ['text' => $text])->assertOk()->assertJson([
            'arrival_on' => '2028-05-12', 'departure_on' => '2028-05-14', 'guests' => 24, 'source' => 'local',
        ]);
        config(['crm.ollama_model' => 'fixture']);
        Http::fake(['127.0.0.1:11434/*' => Http::response(['message' => ['content' => json_encode(['guests' => 24, 'evidence' => '24 гостя'])]])]);
        $this->postJson($path, ['text' => $text])->assertOk()->assertJson(['guests' => 24, 'source' => 'ollama', 'evidence' => '24 гостя']);
        $this->assertDatabaseCount('group_requests', 0);
    }

    public function test_signed_reply_links_to_its_version_and_unmatched_reply_requires_a_lead(): void
    {
        $lead = $this->member('lead');
        $manager = $this->member('manager');
        $other = $this->member('manager');
        $this->actingAs($manager);
        $group = $this->group('Reply');
        $this->post(route('group-requests.convert', $group))->assertSessionHasNoErrors();
        $deal = $group->refresh()->deal_id;
        $this->travel(30)->minutes();
        $this->post(route('deals.proposal', $deal), ['items' => [['name' => 'Twin', 'price' => '100', 'quantity' => 1]], 'discount' => 0])->assertSessionHasNoErrors();
        $proposal = Proposal::sole();
        $status = route('deals.proposal.status', [$deal, $proposal->id]);
        $this->travel(20)->minutes();
        $this->actingAs($lead)->patch($status, ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->travel(10)->minutes();
        $this->actingAs($manager)->patch($status, ['action' => 'send'])->assertSessionHasNoErrors();
        $task = DealActivity::create(['deal_id' => $deal, 'kind' => 'task', 'body' => 'Follow up', 'due_at' => now()->subDay(), 'automation_key' => 'proposal-follow-up:'.$proposal->id]);
        $secret = str_repeat('demo', 10);
        config(['crm.reply_webhook_secret' => $secret, 'crm.reply_webhook_account' => $lead->email]);
        $report = function (string $event, array $data, bool $valid = true) use ($secret) {
            $payload = json_encode($data);
            $at = (string) now()->timestamp;

            return $this->call('POST', route('api.intake.replies.heartbeat'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CRM_TIMESTAMP' => $at,
                'HTTP_X_CRM_EVENT' => $event, 'HTTP_X_CRM_SIGNATURE' => $valid ? hash_hmac('sha256', $at.'.'.$event.'.'.$payload, $secret) : 'invalid',
            ], $payload);
        };
        $success = ['status' => 'done', 'imported' => 1, 'duplicates' => 0, 'skipped' => 0];
        $report('sync-1', $success, false)->assertUnauthorized();
        $report('sync-1', $success)->assertOk();
        $report('sync-1', $success)->assertOk();
        $report('sync-2', [...$success, 'status' => 'failed', 'imported' => 0, 'error' => 'login'])->assertOk();
        $this->assertSame(2, DB::table('hospitality_runs')->where('action', 'mailbox.sync')->count());
        $this->actingAs($lead)->get(route('inbound-replies.index'))->assertInertia(fn (Assert $page) => $page
            ->where('sync.status', 'failed')->where('sync.error', 'login')->where('sync.stale', false)->has('sync.last_success'));
        $this->actingAs($manager)->get(route('inbound-replies.index'))->assertInertia(fn (Assert $page) => $page->where('sync', null));
        DB::table('proposal_deliveries')->insert([
            'proposal_id' => $proposal->id, 'department_id' => $lead->department_id, 'user_id' => $manager->id,
            'recipient' => 'reply@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('reports', ['metric' => 'approval']))->assertInertia(fn (Assert $page) => $page
            ->where('bottlenecks.approval.minutes', 20)->where('bottlenecks.response.minutes', 60)
            ->where('bottleneckDetails.data.0.id', $deal));
        $this->get(route('reports', ['metric' => 'overdue']))->assertInertia(fn (Assert $page) => $page
            ->where('bottleneckDetails.data.0.description', 'Follow up')->where('bottleneckDetails.data.0.id', $deal));
        $this->actingAs($other)->get(route('reports', ['metric' => 'approval']))->assertInertia(fn (Assert $page) => $page
            ->where('bottlenecks.approval.count', 0)->has('bottleneckDetails.data', 0));

        $this->travel(15)->minutes();
        $receive = function (string $event, string $sender, bool $valid = true) use ($proposal, $secret) {
            $body = json_encode([
                'sender' => $sender, 'subject' => 'Re: предложение', 'body' => 'Подтверждаем даты',
                'in_reply_to' => '<'.\App\Services\InboundReplies::messageId($proposal->id).'>',
            ]);
            $at = (string) now()->timestamp;

            return $this->call('POST', route('api.intake.replies'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CRM_TIMESTAMP' => $at,
                'HTTP_X_CRM_EVENT' => $event, 'HTTP_X_CRM_SIGNATURE' => $valid ? hash_hmac('sha256', $at.'.'.$event.'.'.$body, $secret) : 'invalid',
            ], $body);
        };
        $receive('msg-0', 'reply@example.test', false)->assertUnauthorized();
        $review = $receive('msg-1', 'alias@example.test')->assertCreated()->json('reply_id');
        $this->assertSame('sent', $proposal->refresh()->status);
        $this->actingAs($manager)->get(route('inbound-replies.index'))->assertInertia(fn (Assert $page) => $page->has('messages.data', 0));
        $this->patch(route('inbound-replies.link', $review), ['proposal' => $proposal->id])->assertForbidden();
        $linked = $receive('msg-2', 'reply@example.test')->assertCreated()->json('reply_id');
        $receive('msg-2', 'reply@example.test')->assertOk()->assertJsonPath('reply_id', $linked);
        $receive('msg-2', 'alias@example.test')->assertConflict();
        $this->assertSame('answered', $proposal->refresh()->status);
        $this->assertNull($proposal->customer_decision);
        $this->assertNotNull($task->refresh()->completed_at);
        $this->actingAs($lead)->get(route('inbound-replies.index'))->assertInertia(fn (Assert $page) => $page->has('messages.data', 2)->has('proposals', 1)->where('sync.stale', true));
        $this->patch(route('inbound-replies.link', $review), ['proposal' => $proposal->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get(route('deals.show', $deal))->assertInertia(fn (Assert $page) => $page->has('replies', 2));
        $this->get(route('reports', ['metric' => 'client_reply']))->assertInertia(fn (Assert $page) => $page
            ->where('bottlenecks.client_reply.minutes', 15)->where('bottleneckDetails.data.0.id', $deal));
        $this->actingAs($other)->get(route('inbound-replies.index'))->assertInertia(fn (Assert $page) => $page->has('messages.data', 0));
    }
}
