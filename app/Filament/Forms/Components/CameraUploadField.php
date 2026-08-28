<?php

namespace App\Filament\Forms\Components;

use App\Helpers\Helpers;
use Filament\Forms\Components\Field;

class CameraUploadField extends Field
{
    protected string $view = 'filament.forms.components.camera-upload-field';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrateStateUsing(function (CameraUploadField $component, ?string $state): ?string {
            $previous = $component->getRecord()?->getRawOriginal('photo');

            if (str_starts_with((string) $state, 'data:')) {
                $stored = Helpers::storePhotoDataUrl($state);

                static::deleteStoredPhoto($previous, $stored);

                return $stored;
            }

            if ($state !== $previous) {
                static::deleteStoredPhoto($previous);
            }

            return $state;
        });
    }

    

    protected static function deleteStoredPhoto(?string $path, ?string $keep = null): void
    {
        if (blank($path) || $path === $keep) {
            return;
        }

        Helpers::deleteStoredPhoto($path);
    }
}
