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

/**
 * A location is the top-level tenant: a gym or branch. Every business record
 * belongs to exactly one location, and no location can see another location's
 * data. The `owner` role operates across every location.
 */
class Location extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
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

    /**
     * Get the user who manages this location.
     */
    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    /**
     * Get the non-deletable admin account created alongside this location.
     */
    public function foundingAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'founding_admin_user_id');
    }

    /**
     * Whether the given user is the founding admin of this location.
     */
    public function isFoundingAdmin(?User $user): bool
    {
        return $user !== null
            && $this->founding_admin_user_id !== null
            && (int) $this->founding_admin_user_id === (int) $user->id;
    }

    /**
     * Get the members whose subscriptions are at this location.
     *
     * A member's location is derived from the plans of its subscriptions,
     * so the members at a location are the members behind its subscriptions.
     */
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

    /**
     * Get the users who have access to this location.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_locations')
            ->withTimestamps();
    }

    /**
     * Get the services available at this location.
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the plans available at this location.
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Get the public tokens for this location.
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(LocationToken::class, 'tokenable');
    }

    /**
     * Get the queue entries for this location.
     */
    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    /**
     * Get the effective theme color (location override or global default).
     */
    public function getEffectiveThemeColor(): string
    {
        return $this->theme_color
            ?? Helpers::getSettings()['general']['theme_color'] ?? null
            ?? '#2563eb';
    }

    /**
     * Get the effective background color for the scanner UI.
     * Falls back to the theme color when no dedicated background is set.
     */
    public function getEffectiveBackgroundColor(): string
    {
        return $this->background_color
            ?? $this->getEffectiveThemeColor();
    }

    /**
     * Get the effective accent color for the scanner UI.
     * Falls back to the theme color when no dedicated accent is set.
     */
    public function getEffectiveAccentColor(): string
    {
        return $this->accent_color
            ?? $this->getEffectiveThemeColor();
    }
}
