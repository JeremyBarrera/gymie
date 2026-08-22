<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $location_id
 * @property string $kind
 * @property array $payload
 * @property string|null $identifier_type
 * @property string $status
 * @property int|null $claimed_by_user_id
 * @property Carbon|null $claimed_at
 * @property bool $override
 * @property int|null $override_by_user_id
 * @property string|null $denied_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 * @property-read User|null $claimedBy
 * @property-read User|null $overrideBy
 */
class QueueEntry extends Model
{
    use HasFactory, ScopedByLocation;

    protected $fillable = [
        'uuid',
        'location_id',
        'kind',
        'payload',
        'identifier_type',
        'status',
        'claimed_by_user_id',
        'claimed_at',
        'override',
        'override_by_user_id',
        'denied_reason',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'override' => 'boolean',
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }

    protected function member(): Attribute
    {
        return Attribute::make(
            get: fn () => Member::find($this->payload['member_id'] ?? null),
        );
    }

    protected function plan(): Attribute
    {
        return Attribute::make(
            get: fn () => Subscription::find($this->payload['subscription_id'] ?? null)?->plan,
        );
    }

    protected function isWaiting(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'waiting',
        );
    }

    protected function isAttending(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'attending',
        );
    }

    protected function isApproved(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'approved',
        );
    }

    protected function isDenied(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'denied',
        );
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'expired',
        );
    }

    /**
     * The entry's current position among active (waiting/attending) entries
     * of the same kind at its location.
     */
    public function position(): int
    {
        return static::query()
            ->where('location_id', $this->location_id)
            ->where('kind', $this->kind)
            ->whereIn('status', ['waiting', 'attending'])
            ->where('id', '<=', $this->id)
            ->count();
    }
}
