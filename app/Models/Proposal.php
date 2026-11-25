<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    public const CUSTOMER_DECISIONS = ['accepted', 'declined', 'changes_requested', 'needs_review'];

    protected $fillable = ['deal_id', 'version', 'snapshot', 'status', 'approved_by', 'sent_at', 'created_by', 'rejection_reason', 'follow_up_at', 'customer_decision'];

    protected $casts = ['snapshot' => 'array', 'sent_at' => 'datetime', 'follow_up_at' => 'datetime'];
}
