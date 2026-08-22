<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $kind
 * @property int|null $location_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $tokenable
 */
class LocationToken extends Model
{
    use HasFactory, ScopedByLocation;

    protected $fillable = ['location_id', 'token', 'tokenable_type', 'tokenable_id', 'kind'];

    protected $casts = [
        'kind' => 'string',
    ];

    /**
     * A token's location is its tokenable's, not the creating account's
     * default location. The ScopedByLocation creating hook fills location_id
     * from the default location, so re-derive it from the tokenable here
     * (listeners registered in this boot run after the trait's).
     */
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

    /**
     * The location owning the tokenable: a Location's own id, or another
     * tokenable model's location_id. Mirrors SetCurrentLocation.
     */
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
