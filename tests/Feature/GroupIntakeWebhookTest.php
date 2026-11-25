<?php

namespace Tests\Feature;

use App\Models\GroupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupIntakeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_intake_is_idempotent_and_rejects_modified_requests(): void
    {
        $actor = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $actor->email, 'department' => 'Groups'])->assertSuccessful();
        $secret = str_repeat('synthetic-key-', 4);
        config(['crm.intake_webhook_secret' => $secret, 'crm.intake_webhook_assignee' => $actor->email]);

        $body = json_encode(['title' => 'Группа', 'company_name' => 'Nord', 'contact_name' => 'Анна', 'email' => 'anna@example.test'], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $event = 'local-form-21';
        $request = function (string $payload, string $at, ?string $signature = null) use ($event, $secret) {
            return $this->call('POST', route('api.intake.group-requests'), server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CRM_TIMESTAMP' => $at,
                'HTTP_X_CRM_EVENT' => $event,
                'HTTP_X_CRM_SIGNATURE' => $signature ?? hash_hmac('sha256', $at.'.'.$event.'.'.$payload, $secret),
            ], content: $payload);
        };

        $request($body, $timestamp, 'forged')->assertUnauthorized();
        $request($body, (string) now()->subMinutes(6)->timestamp)->assertUnauthorized();
        $request($body, $timestamp)->assertCreated()->assertJsonPath('created', true);
        $group = GroupRequest::sole();
        $request($body, $timestamp)->assertOk()->assertExactJson(['request_id' => $group->id, 'created' => false]);
        $request(str_replace('Nord', 'South', $body), $timestamp)->assertConflict();
        $this->assertSame('form', $group->source);
        $this->assertDatabaseCount('group_requests', 1);
        $this->assertDatabaseCount('intake_events', 1);
    }
}
