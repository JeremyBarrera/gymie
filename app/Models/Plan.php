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

class Plan extends Model
{
    
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    

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

    

    public function isEvergreen(): bool
    {
        return $this->days === null || (int) $this->days === 0;
    }

    

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'plan_services');
    }

    

    public function primaryService(): ?Service
    {
        return $this->services->sortBy('name')->first();
    }

    

    public function scopeForService(Builder $query, int $serviceId): Builder
    {
        return $query->whereHas('services', fn (Builder $related): Builder => $related->whereKey($serviceId));
    }

    

    

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    

    public function checkIns(): HasMany
    {
        return $this->hasMany(PlanCheckIn::class);
    }

    

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

    

    protected static function relationsToCascade(): array
    {
        return ['subscriptions'];
    }
}
