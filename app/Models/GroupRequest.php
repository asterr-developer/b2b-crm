<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupRequest extends Model
{
    protected $fillable = [
        'department_id', 'assignee_id', 'company_id', 'contact_id', 'deal_id', 'title',
        'source', 'status', 'arrival_on', 'departure_on', 'guests', 'notes',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->leadsSalesDepartment()
            ? $query->where('department_id', $user->department_id)
            : $query->where('assignee_id', $user->id)->where('department_id', $user->department_id);
    }
}
