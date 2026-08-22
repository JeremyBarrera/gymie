<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\ScopedByLocation;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, ScopedByLocation;

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'amount',
        'date',
        'due_date',
        'paid_at',
        'category',
        'status',
        'vendor',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'status' => Status::class,
    ];
}
