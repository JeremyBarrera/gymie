<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Database\Factories\PlanCheckInFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PlanCheckIn extends Model
{
    
    use HasFactory, ScopedByLocation;

    

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

    

    protected $casts = [
        'checked_in_at' => 'datetime',
        'override' => 'boolean',
    ];

    

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }
}
