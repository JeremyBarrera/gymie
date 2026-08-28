<?php

namespace App\Filament\Forms\Components;

use App\Helpers\Helpers;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class PhoneField extends Field
{
    protected string $view = 'filament.forms.components.phone-field';

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(function (PhoneField $component, ?string $state, Set $set): void {
            [$dialCode, $local] = Helpers::parsePhoneField($state);

            $set("{$component->getName()}_dial_code", $dialCode);
            $set($component->getName(), $local);
        });

        $this->dehydrateStateUsing(function (PhoneField $component, ?string $state, Get $get): ?string {
            if (blank($state)) {
                return null;
            }

            $dialCode = $get("{$component->getName()}_dial_code")
                ?? Helpers::getPhoneCountryCodePlaceholder();

            return Helpers::combinePhoneField($dialCode, (string) $state);
        });
    }
}
