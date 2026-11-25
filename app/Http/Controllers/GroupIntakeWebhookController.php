<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Services\ReceiveGroupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupIntakeWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveGroupRequest $intake): JsonResponse
    {
        $secret = config('crm.intake_webhook_secret');
        $email = config('crm.intake_webhook_assignee');
        abort_unless(is_string($secret) && strlen($secret) >= 32 && $email, 404);

        $timestamp = (string) $request->header('X-CRM-Timestamp');
        $event = (string) $request->header('X-CRM-Event');
        $signature = (string) $request->header('X-CRM-Signature');
        $body = $request->getContent();
        abort_if(strlen($body) > 64 * 1024, 413);
        abort_unless(
            preg_match('/^\d{1,12}$/D', $timestamp)
            && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $event)
            && abs(now()->timestamp - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$event.'.'.$body, $secret), $signature),
            401,
        );
        abort_unless($request->isJson(), 415);

        $actor = User::whereEmail($email)->whereNotNull('department_id')->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'company_name' => ['required_without:company_id', 'nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $hash = hash('sha256', $body);

        [$id, $created] = DB::transaction(function () use ($actor, $event, $data, $hash, $intake) {
            Department::lockForUpdate()->findOrFail($actor->department_id);
            abort_unless(User::whereKey($actor->id)->where('department_id', $actor->department_id)->exists(), 403);
            $previous = DB::table('intake_events')->where('department_id', $actor->department_id)->where('event_key', $event)->first();

            if ($previous) {
                abort_unless(hash_equals($previous->body_hash, $hash), 409, 'The event key is already bound to another payload.');

                return [$previous->group_request_id, false];
            }

            $group = $intake->handle($actor, [...$data, 'source' => 'form', 'integration' => 'webhook']);
            DB::table('intake_events')->insert([
                'department_id' => $actor->department_id, 'event_key' => $event,
                'body_hash' => $hash, 'group_request_id' => $group->id, 'created_at' => now(),
            ]);

            return [$group->id, true];
        }, 3);

        return response()->json(['request_id' => $id, 'created' => $created], $created ? 201 : 200);
    }
}
