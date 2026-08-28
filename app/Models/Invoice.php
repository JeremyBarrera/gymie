<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Concerns\ScopedByLocation;
use App\Support\AppConfig;
use App\Support\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    
    use HasFactory, ScopedByLocation, SoftDeletes;

    

    protected $fillable = [
        'location_id',
        'number',
        'subscription_id',
        'date',
        'due_date',
        'payment_method',
        'status',
        'tax',
        'tax_percent',
        'discount',
        'discount_amount',
        'discount_note',
        'paid_amount',
        'total_amount',
        'due_amount',
        'subscription_fee',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'status' => Status::class,
        'tax_percent' => 'decimal:2',
    ];

    

    

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    

    public function effectiveStatus(): ?Status
    {
        $status = $this->status;

        if ($status === null) {
            return null;
        }

        if (in_array($status, [Status::Issued, Status::Partial], true)
            && (float) $this->due_amount > 0
            && $this->due_date
            && Carbon::parse($this->due_date)->lt(Carbon::today(AppConfig::timezone()))) {
            return Status::Overdue;
        }

        return $status;
    }

    

    public static function markOverdue(): int
    {
        return static::query()
            ->whereIn('status', ['issued', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today(AppConfig::timezone()))
            ->where('due_amount', '>', 0)
            ->update(['status' => 'overdue']);
    }

    

    public function getDisplayStatusLabel(): string
    {
        return $this->effectiveStatus()?->getLabel() ?? '';
    }

    

    

    public function transactions(): HasMany
    {
        return $this->hasMany(InvoiceTransaction::class);
    }

    

    public function syncFromTransactions(): void
    {
        $paymentsTotal = (float) $this->transactions()
            ->where('type', 'payment')
            ->sum('amount');

        $refundsTotal = (float) $this->transactions()
            ->where('type', 'refund')
            ->sum('amount');

        $paymentsTotal = max($paymentsTotal, 0);
        $refundsTotal = min(max($refundsTotal, 0), $paymentsTotal);

        $total = (float) ($this->total_amount ?? 0);
        $total = max($total, 0);

        $netPaid = max($paymentsTotal - $refundsTotal, 0);
        $netPaid = min($netPaid, $total);

        $status = $this->status->value ?? 'issued';
        $due = max($total - $netPaid, 0);

        if ($status === 'cancelled') {
            $due = 0;
        } elseif ($refundsTotal > 0) {
            $status = 'refund';
            $due = 0;
        } elseif ($due <= 0 && $netPaid > 0) {
            $status = 'paid';
            $due = 0;
        } elseif ($netPaid > 0) {
            $status = 'partial';
        } else {
            $status = 'issued';
        }

        $isDueOver = $due > 0
            && $this->due_date
            && Carbon::parse($this->due_date)->lt(Carbon::today(AppConfig::timezone()));

        if ($isDueOver) {
            $status = 'overdue';
        }

        $this->newQuery()
            ->whereKey($this->getKey())
            ->update([
                'paid_amount' => $netPaid,
                'due_amount' => $due,
                'status' => $status,
            ]);

        $this->refresh();
    }

    

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $invoice): void {
            if (! $invoice->number) {
                $invoice->number = Helpers::generateLastNumber('invoice', Invoice::class, $invoice->date);
            }

            $taxRate = $invoice->tax_percent ?? Helpers::getTaxRate();
            $summary = InvoiceCalculator::summary(
                fee: (float) ($invoice->subscription_fee ?? 0),
                taxRatePercent: $taxRate,
                discountAmount: (float) ($invoice->discount_amount ?? 0),
                paidAmount: (float) ($invoice->paid_amount ?? 0),
            );

            $invoice->subscription_fee = $summary['fee'];
            $invoice->discount_amount = $summary['discount_amount'];
            $invoice->tax = $summary['tax'];
            $invoice->total_amount = $summary['total'];
            $invoice->paid_amount = $summary['paid'];
        });

        static::created(function (self $invoice): void {
            $paid = (float) ($invoice->paid_amount ?? 0);
            $paid = max($paid, 0);
            $paid = min($paid, (float) ($invoice->total_amount ?? 0));

            if ($paid > 0) {
                $transaction = new InvoiceTransaction([
                    'type' => 'payment',
                    'amount' => $paid,
                    'occurred_at' => now()->timezone(AppConfig::timezone()),
                    'payment_method' => $invoice->payment_method,
                    'note' => 'Initial payment',
                    'created_by' => auth()->id(),
                ]);

                $locationId = $invoice->getAttribute('location_id');

                if (filled($locationId)) {
                    $transaction->setAttribute('location_id', $locationId);
                }

                $transaction->invoice()->associate($invoice);
                $transaction->saveQuietly();
            }

            $invoice->syncFromTransactions();
        });

        static::updated(function (self $invoice): void {
            $invoice->syncFromTransactions();
        });
    }
}
