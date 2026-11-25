<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = ['department_id', 'email', 'token_hash', 'expires_at', 'accepted_at'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
}
