<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MemberApplication extends Model
{
    use HasFactory, ScopedByLocation;

    protected $fillable = [
        'location_id',
        'identifier_type',
        'identifier_value',
        'payload',
        'status',
        'created_member_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => 'string',
    ];

    public function createdMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_member_id');
    }

    public function scopePending(): self
    {
        return $this->where('status', 'pending');
    }

    public function scopeApproved(): self
    {
        return $this->where('status', 'approved');
    }

    public function scopeRejected(): self
    {
        return $this->where('status', 'rejected');
    }
}
