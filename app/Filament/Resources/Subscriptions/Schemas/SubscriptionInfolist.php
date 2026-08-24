<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\Filament\InvoiceSummaryRows;
use App\Support\Filament\SubscriptionDetails;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionInfolist
{
    /**
     * Configure the subscription "view" infolist schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                SubscriptionDetails::section(
                    fn (Subscription $record): Subscription => $record,
                ),

                Section::make(__('app.titles.summary'))
                    ->schema(InvoiceSummaryRows::rows(
                        fn (Subscription $record): ?Invoice => $record->invoices->last(),
                    ))
                    ->columns(1)
                    ->hidden(fn (Subscription $record): bool => $record->invoices->isEmpty()),
            ]);
    }
}
