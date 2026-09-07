<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Contracts\SettingsRepository;
use App\Filament\Resources\Locations\LocationResource;
use App\Helpers\Helpers;
use App\Support\Billing\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\Rule;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            LocationResource::getUrl('index') => LocationResource::getNavigationLabel(),
        ];
    }

    public function mount(): void
    {
        parent::mount();

        if (Currency::isUnresolved(Helpers::getSettings())) {
            Notification::make()
                ->title(__('app.currency.setup_title'))
                ->body(__('app.currency.setup_description'))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('chooseCurrency')
                ->label(__('app.currency.setup_title'))
                ->modalHeading(__('app.currency.setup_title'))
                ->modalDescription(__('app.currency.setup_description'))
                ->modalSubmitActionLabel(__('app.currency.setup_save_default'))
                ->modalCancelActionLabel(__('app.currency.setup_decide_later'))
                ->form([
                    Select::make('currency')
                        ->label(__('app.fields.currency'))
                        ->options(Helpers::getCurrencies())
                        ->searchable()
                        ->preload()
                        ->default(Helpers::getCurrencyCode())
                        ->required()
                        ->rules(['required', 'string', 'size:3', Rule::in(Currency::codes())]),
                ])
                ->action(function (array $data): void {
                    $settings = Helpers::getSettings();
                    $settings['general']['currency'] = strtoupper(trim((string) ($data['currency'] ?? '')));
                    app(SettingsRepository::class)->put($settings);

                    Notification::make()
                        ->title(__('app.notifications.success'))
                        ->body(__('app.notifications.success_settings_save'))
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation(fn (): bool => $this->currencyConfirmRequired())
            ->modalHeading(__('app.currency.change_heading'))
            ->modalDescription(__('app.currency.change_description'))
            ->modalSubmitActionLabel(__('app.currency.confirm_change'));
    }

    public function currencyConfirmRequired(): bool
    {
        return filled($this->form->getRawState()['currency'] ?? null);
    }
}
