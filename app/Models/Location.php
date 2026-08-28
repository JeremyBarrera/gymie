<?php

namespace App\Models;

use App\Helpers\Helpers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Location extends Model
{
    use HasFactory;

    

    protected $fillable = [
        'name',
        'address',
        'country',
        'state',
        'city',
        'pincode',
        'phone',
        'currency',
        'email',
        'logo',
        'financial_year_start',
        'financial_year_end',
        'managed_by',
        'founding_admin_user_id',
        'theme_color',
        'background_color',
        'accent_color',
    ];

    

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    

    public function foundingAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'founding_admin_user_id');
    }

    

    public function isFoundingAdmin(?User $user): bool
    {
        return $user !== null
            && $this->founding_admin_user_id !== null
            && (int) $this->founding_admin_user_id === (int) $user->id;
    }

    

    public function members(): HasManyThrough
    {
        return $this->hasManyThrough(
            Member::class,
            Subscription::class,
            'location_id',
            'id',
            'id',
            'member_id',
        );
    }

    

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_locations')
            ->withTimestamps();
    }

    

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    

    public function tokens(): MorphMany
    {
        return $this->morphMany(LocationToken::class, 'tokenable');
    }

    

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    

    public function getEffectiveThemeColor(): string
    {
        return $this->theme_color
            ?? Helpers::getSettings()['general']['theme_color'] ?? null
            ?? '#2563eb';
    }

    

    public function getEffectiveBackgroundColor(): string
    {
        return $this->background_color
            ?? $this->getEffectiveThemeColor();
    }

    

    public function getEffectiveAccentColor(): string
    {
        return $this->accent_color
            ?? $this->getEffectiveThemeColor();
    }
}
