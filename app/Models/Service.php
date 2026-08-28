<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'location_id',
        'name',
        'description',
    ];

    
    protected $dates = ['deleted_at'];

    

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    

    

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_services');
    }

    

    protected static function relationsToCascade(): array
    {
        return ['plans'];
    }
}
