<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Enquiry extends Model
{
    
    use CascadesSoftDeletes, HasFactory, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'contact',
        'date',
        'gender',
        'dob',
        'status',
        'address',
        'country',
        'city',
        'state',
        'pincode',
        'interested_in',
        'source',
        'goal',
        'start_by',
    ];

    protected $casts = [
        'interested_in' => 'array',
        'date' => 'date',
        'dob' => 'date',
        'start_by' => 'date',
        'status' => Status::class,
    ];

    
    protected $dates = ['deleted_at'];

    

    

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    

    

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    

    protected static function relationsToCascade(): array
    {
        return ['followUps'];
    }
}
