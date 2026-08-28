<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    
    use HasFactory, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'location_id',
        'renewed_from_subscription_id',
        'member_id',
        'plan_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => Status::class,
    ];

    
    protected $dates = ['deleted_at', 'start_date', 'end_date'];

    

    

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_subscription_id');
    }

    

    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewed_from_subscription_id');
    }

    

    

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    

    

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
