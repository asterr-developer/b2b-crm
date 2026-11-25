<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deal extends Model
{
    protected $fillable = ['title', 'amount', 'assignee_id', 'department_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DealActivity::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->leadsSalesDepartment()) {
            return $query->where('department_id', $user->department_id);
        }

        return $query->where('assignee_id', $user->id)->where('department_id', $user->department_id);
    }
}
