<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use Illuminate\Http\Request;

class EstimateDealsController extends Controller
{
    public function __invoke(Request $request, ?int $deal = null)
    {
        $token = (string) config('services.estimates.token');
        $department = (int) config('services.estimates.department_id');
        abort_unless($department > 0 && strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 401);

        $query = Deal::where('department_id', $department)->with('contact.company');
        $serialize = fn (Deal $row) => [
            'id' => $row->id,
            'title' => mb_substr($row->title, 0, 120),
            'client' => mb_substr($row->contact?->company?->name ?? $row->contact?->name ?? 'Заказчик', 0, 100),
        ];
        $data = $deal !== null ? $serialize($query->findOrFail($deal)) : $query->latest('id')
            ->simplePaginate(20)->through($serialize);

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
