<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EstimateBridge
{
    public function exchange(Deal $deal, bool $create = false): ?array
    {
        $base = rtrim((string) config('services.estimates.url'), '/');
        $token = (string) config('services.estimates.token');
        $source = (string) config('services.estimates.source');
        $department = (int) config('services.estimates.department_id');
        if ($department < 1 || (int) $deal->department_id !== $department || !$base || strlen($token) < 32 || !preg_match('/^[a-z0-9-]{1,50}$/D', $source)) {
            return null;
        }
        $url = $base.'/api/crm/v1/estimates/'.$source.':'.$deal->id;
        $http = Http::acceptJson()->withToken($token)->connectTimeout(1)->timeout(2)->withoutRedirecting();
        try {
            $response = $create
                ? $http->post($url, [
                    'title' => mb_substr($deal->title, 0, 120),
                    'client' => mb_substr($deal->contact?->company?->name ?? $deal->contact?->name ?? 'Заказчик', 0, 100),
                ])
                : $http->get($url);
        } catch (ConnectionException) {
            return ['status' => 'unavailable'];
        }
        if (!$create && $response->notFound()) {
            return ['status' => 'new'];
        }
        $id = $response->json('draft_id');
        if (!$response->ok() || !is_int($id) || $id < 1) {
            return ['status' => 'unavailable'];
        }
        $estimate = $response->json('accepted');
        $accepted = null;
        if (is_array($estimate) && preg_match('/^[0-9]+$/D', (string) ($estimate['number'] ?? ''))
            && is_int($estimate['total_minor'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', (string) ($estimate['token'] ?? ''))) {
            $accepted = ['number' => $estimate['number'], 'total_minor' => $estimate['total_minor'], 'url' => $base.'/p/'.$estimate['token']];
        }

        return ['status' => 'linked', 'url' => $base.'/?draft='.$id, 'accepted' => $accepted];
    }
}
