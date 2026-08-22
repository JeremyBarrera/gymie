<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Database\Factories\PlanCheckInFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property int $subscription_id
 * @property int $plan_id
 * @property int $service_id
 * @property bool $override
 * @property int|null $override_by_user_id
 * @property string|null $override_reason
 * @property int|null $location_id
 * @property int|null $checked_in_by
 * @property Carbon $checked_in_at
 * @property-read Member $member
 * @property-read Subscription $subscription
 * @property-read Plan $plan
 * @property-read Service $service
 * @property-read Location|null $location
 * @property-read User|null $checkedInBy
 * @property-read User|null $overrideBy
 */
class PlanCheckIn extends Model
{
    /** @use HasFactory<PlanCheckInFactory> */
    use HasFactory, ScopedByLocation;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'subscription_id',
        'plan_id',
        'service_id',
        'override',
        'override_by_user_id',
        'override_reason',
        'location_id',
        'checked_in_by',
        'checked_in_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'checked_in_at' => 'datetime',
        'override' => 'boolean',
    ];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }
}
