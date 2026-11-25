<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['group_request_id', 'deal_id', 'user_id', 'action', 'details'];

    protected $casts = ['details' => 'array'];

    public static function record(?User $actor, string $action, array $details, ?int $deal = null, ?int $groupRequest = null): void
    {
        static::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'details' => $details,
            'deal_id' => $deal,
            'group_request_id' => $groupRequest,
        ]);
    }
}
