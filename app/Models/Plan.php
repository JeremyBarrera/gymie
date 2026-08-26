<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int|null $location_id
 * @property float|int|string|null $amount
 * @property int|float|string|null $days
 * @property Status|null $status
 * @property bool $limit_uses
 * @property int|null $uses_limit
 * @property-read Location|null $location
 * @property-read Collection<int, Service> $services
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read Collection<int, PlanCheckIn> $checkIns
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'code',
        'description',
        'amount',
        'days',
        'status',
        'limit_uses',
        'uses_limit',
    ];

    protected $casts = [
        'status' => Status::class,
        'limit_uses' => 'boolean',
        'uses_limit' => 'integer',
    ];

    /** @var list<string> */
    protected $dates = ['deleted_at'];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $plan): void {
            if (! $plan->limit_uses) {
                $plan->uses_limit = null;
            }
        });
    }

    /**
     * Plans without a day count never expire: subscriptions sold on them
     * have no end date and stay eligible indefinitely.
     */
    public function isEvergreen(): bool
    {
        return $this->days === null || (int) $this->days === 0;
    }

    /**
     * Get the location this plan belongs to.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the services the plan grants access to.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'plan_services');
    }

    /**
     * Deterministic single-service representative for surfaces that carry
     * exactly one service (check-in records, scan/API payloads): the plan's
     * alphabetically-first service.
     */
    public function primaryService(): ?Service
    {
        return $this->services->sortBy('name')->first();
    }

    /**
     * Plans that grant access to the given service.
     */
    public function scopeForService(Builder $query, int $serviceId): Builder
    {
        return $query->whereHas('services', fn (Builder $related): Builder => $related->whereKey($serviceId));
    }

    /**
     * Get the subscriptions for the plan.
     */
    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<PlanCheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(PlanCheckIn::class);
    }

    /**
     * Whether the plan may be used at the given location.
     *
     * A plan belongs to exactly one location, or to **every** location when
     * `location_id` is null ΓÇö the "All Locations" option, which also covers
     * locations created in the future.
     */
    public function availableAt(?int $locationId): bool
    {
        if ($locationId === null) {
            return true;
        }

        if ($this->location_id === null) {
            return true;
        }

        return (int) $this->location_id === $locationId;
    }

    /**
     * Location scoping for plans: a plan with no location ("All Locations")
     * is visible to every location-scoped account, and must never be
     * auto-assigned a default location at creation.
     */
    protected static function bootScopedByLocation(): void
    {
        static::addGlobalScope('location', function (Builder $builder): void {
            $locationIds = self::currentLocationIds();

            if ($locationIds === null) {
                return;
            }

            $builder->where(function (Builder $query) use ($locationIds): void {
                $query->whereIn('plans.location_id', $locationIds)
                    ->orWhereNull('plans.location_id');
            });
        });
    }

    /**
     * Relationship method names to cascade when deleting/restoring.
     *
     * @return list<string>
     */
    protected static function relationsToCascade(): array
    {
        return ['subscriptions'];
    }
}
