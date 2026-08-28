<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class FollowUp extends Model
{
    
    use HasFactory, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'location_id',
        'enquiry_id',
        'user_id',
        'schedule_date',
        'method',
        'outcome',
        'status',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'status' => Status::class,
    ];

    
    protected $dates = ['deleted_at'];

    

    

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    

    

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
