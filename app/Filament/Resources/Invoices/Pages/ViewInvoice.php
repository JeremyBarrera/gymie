<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\RecordPaymentAction;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(int|string $record): void
    {
        Invoice::markOverdue();

        parent::mount($record);
    }

    public function getTitle(): string
    {
        return __('app.titles.invoice_number', ['number' => $this->record->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make(),
            EditAction::make()
                ->hidden(fn (): bool => ! in_array($this->record->status?->value, ['issued', 'overdue'], true)),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.billing'),
            InvoiceResource::getUrl('index') => InvoiceResource::getNavigationLabel(),
            (string) $this->record->number,
        ];
    }
}
