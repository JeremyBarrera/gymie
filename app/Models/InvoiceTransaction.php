<?php

namespace App\Models;

use App\Models\Concerns\ScopedByLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class InvoiceTransaction extends Model
{
    
    use HasFactory, ScopedByLocation;

    

    protected $fillable = [
        'location_id',
        'invoice_id',
        'type',
        'amount',
        'occurred_at',
        'payment_method',
        'note',
        'reference_id',
        'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    

    protected static function booted(): void
    {
        static::saved(function (self $transaction): void {
            if ($transaction->invoice instanceof Invoice) {
                $transaction->invoice->syncFromTransactions();
            }
        });

        static::deleted(function (self $transaction): void {
            if ($transaction->invoice instanceof Invoice) {
                $transaction->invoice->syncFromTransactions();
            }
        });
    }
}
