<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service is owned by a location: it exists only within the location that
 * created it, and its plans belong to the same location.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'description',
    ];

    /** @var list<string> */
    protected $dates = ['deleted_at'];

    /**
     * Get the location this service belongs to.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the plans for the service.
     */
    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Relationship method names to cascade when deleting/restoring.
     *
     * @return list<string>
     */
    protected static function relationsToCascade(): array
    {
        return ['plans'];
    }
}
