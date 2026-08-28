<?php

namespace App\Support\Filament;

use App\Helpers\Helpers;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class InvoiceSummaryRows
{
    

    public static function rows(callable $amountsFor): array
    {
        $amount = static fn (string $key): Closure => static fn ($record): string => Helpers::formatCurrency($amountsFor($record)?->{$key});
        $hiddenWhenEmpty = static fn (string $key): Closure => static fn ($record): bool => empty($amountsFor($record)?->{$key});

        return [
            self::row(__('app.fields.fee'), 'subscription_fee', $amount('subscription_fee')),
            self::row(
                fn (): string => __('app.fields.tax_with_rate', ['rate' => Helpers::getTaxRate()]),
                'tax',
                $amount('tax'),
                $hiddenWhenEmpty('tax'),
            ),
            self::row(
                static fn ($record): string => (($source = $amountsFor($record))?->discount)
                    ? __('app.fields.discount_with_rate', ['rate' => $source->discount])
                    : __('app.fields.discount'),
                'discount_amount',
                $amount('discount_amount'),
                $hiddenWhenEmpty('discount_amount'),
            ),
            self::row(__('app.fields.total'), 'total_amount', $amount('total_amount')),
            self::row(__('app.fields.paid'), 'paid_amount', $amount('paid_amount'), $hiddenWhenEmpty('paid_amount')),
            self::row(__('app.fields.due'), 'due_amount', $amount('due_amount'), $hiddenWhenEmpty('due_amount')),
        ];
    }

    

    public static function row(string|callable $label, string $key, Closure $amount, ?Closure $hidden = null): Flex
    {
        $row = Flex::make([
            TextEntry::make("{$key}_summary_label")
                ->hiddenLabel()
                ->state($label)
                ->size(TextSize::Small)
                ->color('gray')
                ->grow(false)
                ->extraEntryWrapperAttributes(['class' => 'shrink-0'])
                ->extraAttributes(['class' => 'whitespace-nowrap']),
            TextEntry::make($key)
                ->hiddenLabel()
                ->state($amount)
                ->size(TextSize::Small)
                ->weight(FontWeight::Medium)
                ->grow(false)
                ->extraEntryWrapperAttributes(['class' => 'min-w-0 [&>*]:min-w-0 [&_*]:min-w-0'])
                ->extraAttributes(['class' => 'truncate']),
        ])
            ->alignBetween()
            ->extraAttributes(['class' => 'min-w-0 items-baseline gap-3 [&>*]:min-w-0']);

        if ($hidden !== null) {
            $row->hidden($hidden);
        }

        return $row;
    }
}
