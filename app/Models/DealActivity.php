<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealActivity extends Model
{
    protected $fillable = ['deal_id', 'user_id', 'kind', 'body', 'due_at', 'completed_at', 'automation_key'];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
