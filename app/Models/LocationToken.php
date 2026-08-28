<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class LocationToken extends Model
{
    use HasFactory, ScopedByLocation;

    protected $fillable = ['location_id', 'token', 'tokenable_type', 'tokenable_id', 'kind'];

    protected $casts = [
        'kind' => 'string',
    ];

    

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model): void {
            $locationId = static::locationIdFromTokenable($model);

            if ($locationId !== null) {
                $model->setAttribute('location_id', $locationId);
            }
        });
    }

    

    private static function locationIdFromTokenable(Model $model): ?int
    {
        $tokenableType = $model->getAttribute('tokenable_type');
        $tokenableId = $model->getAttribute('tokenable_id');

        if (! is_string($tokenableType) || $tokenableType === '' || $tokenableId === null) {
            return null;
        }

        $tokenable = $tokenableType::query()
            ->withoutGlobalScopes()
            ->whereKey($tokenableId)
            ->first();

        $locationId = $tokenable?->getAttribute('location_id');

        if ($locationId === null && $tokenable instanceof Location) {
            $locationId = $tokenable->getKey();
        }

        return $locationId !== null ? (int) $locationId : null;
    }

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeCheckin(): self
    {
        return $this->where('kind', 'checkin');
    }

    public function scopeSignup(): self
    {
        return $this->where('kind', 'signup');
    }
}
