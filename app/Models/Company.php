<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['user_id', 'department_id', 'name'];

    public static function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    public function setNameAttribute(string $name): void
    {
        $this->attributes['name'] = $name;
        $this->attributes['normalized_name'] = self::normalizeName($name);
    }

    public function scopeAvailableTo(Builder $query, User $user): Builder
    {
        return $query->accessibleTo($user)->whereNull('archived_at');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $user->department_id === null
            ? $query->whereNull('department_id')->where('user_id', $user->id)
            : $query->where('department_id', $user->department_id);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function scopeMatchingName(Builder $query, string $name): Builder
    {
        $normalized = self::normalizeName($name);

        return $query->where(fn (Builder $candidate) => $candidate->where('normalized_name', $normalized)
            ->orWhereHas('aliases', fn (Builder $alias) => $alias->where('normalized_name', $normalized)));
    }

    public function scopeSearchName(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $candidate) => $candidate->whereLike('name', '%'.$search.'%')
            ->orWhereHas('aliases', fn (Builder $alias) => $alias->whereLike('name', '%'.$search.'%')));
    }
}
