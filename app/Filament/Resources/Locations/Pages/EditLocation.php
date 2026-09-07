<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            LocationResource::getUrl('index') => LocationResource::getNavigationLabel(),
            $this->record->name,
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->currencyChanged())
            ->modalHeading(__('app.currency.change_heading'))
            ->modalDescription(__('app.currency.change_description'))
            ->modalSubmitActionLabel(__('app.currency.confirm_change'));
    }

    public function currencyChanged(): bool
    {
        $submitted = strtoupper(trim((string) ($this->form->getRawState()['currency'] ?? '')));
        $stored = strtoupper(trim((string) ($this->record->currency ?? '')));

        return $submitted !== $stored;
    }
}
