<?php

namespace App\Filament\Resources\Invoices\Actions;

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Support\AppConfig;
use App\Support\Billing\PaymentMethod;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Record a payment against an invoice as an InvoiceTransaction ledger row.
 * paid_amount, due_amount and status are derived by the model's transaction
 * sync — never write them directly.
 */
class RecordPaymentAction
{
    public static function make(): Action
    {
        return Action::make('add_payment')
            ->label(__('app.actions.add_payment'))
            ->color('info')
            ->icon('heroicon-s-banknotes')
            ->modalWidth('md')
            ->schema([
                TextInput::make('amount')
                    ->label(__('app.fields.amount_with_currency', ['currency' => Helpers::getCurrencyCode()]))
                    ->required()
                    ->numeric()
                    ->extraAttributes(['class' => 'verify-money-input'])
                    ->reactive()
                    ->default(fn (Invoice $record): float => (float) ($record->due_amount ?? 0))
                    ->placeholder(__('app.placeholders.enter_amount'))
                    ->validationAttribute('amount')
                    ->helperText(fn (Invoice $record): string => __('app.help.due_amount', ['amount' => Helpers::formatCurrency($record->due_amount)]))
                    ->maxValue(fn (Invoice $record): float => max((float) $record->due_amount, 0))
                    ->minValue(0.01)
                    ->afterStateUpdated(function ($livewire, TextInput $component) {
                        $livewire->validateOnly($component->getStatePath());
                    }),
                DateTimePicker::make('occurred_at')
                    ->label(__('app.fields.paid_at'))
                    ->seconds(false)
                    ->timezone(AppConfig::timezone())
                    ->default(fn (): string => now()->timezone(AppConfig::timezone())->format('Y-m-d H:i:s'))
                    ->required(),
                Select::make('payment_method')
                    ->label(__('app.fields.payment_method'))
                    ->options(PaymentMethod::options())
                    ->default(fn (Invoice $record): string => $record->payment_method ?: 'cash')
                    ->nullable(),
                Textarea::make('note')
                    ->label(__('app.fields.note'))
                    ->rows(2)
                    ->placeholder(__('app.placeholders.optional_note')),
            ])
            ->action(function (Invoice $record, array $data) {
                $amount = (float) ($data['amount'] ?? 0);
                $amount = min(max($amount, 0), (float) ($record->due_amount ?? 0));

                if ($amount <= 0) {
                    Notification::make()
                        ->title(__('app.notifications.invalid_payment_amount'))
                        ->danger()
                        ->send();

                    return;
                }

                $record->transactions()->create([
                    'type' => 'payment',
                    'amount' => $amount,
                    'occurred_at' => $data['occurred_at'] ?? now()->timezone(AppConfig::timezone()),
                    'payment_method' => $data['payment_method'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $record->refresh();

                $paidLabel = Helpers::formatCurrency($record->paid_amount);

                Notification::make()
                    ->title($record->status?->value === 'paid' ? __('app.notifications.invoice_paid') : __('app.notifications.payment_added'))
                    ->success()
                    ->body(__('app.notifications.invoice_paid_total', ['number' => $record->number, 'amount' => $paidLabel]))
                    ->send();
            })
            ->visible(fn (Invoice $record): bool => in_array($record->status?->value, ['issued', 'overdue', 'partial'], true) && (float) $record->due_amount > 0);
    }
}
