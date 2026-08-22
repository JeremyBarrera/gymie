<?php

namespace App\Filament\Forms\Components;

use App\Helpers\Helpers;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Storage;

/**
 * A photo field that lets the user either upload an image file or capture one
 * with the webcam. Camera captures and uploads both land in the field state as
 * base64 data URLs; on dehydrate they are stored on the public disk (reusing
 * the same validation/size rules as the reception sign-up flow), and the
 * previously stored file is removed when the photo is replaced or cleared.
 */
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

    /**
     * Remove a previously stored photo file from the public disk, ignoring
     * absolute/data URLs that were never stored by this component.
     */
    protected static function deleteStoredPhoto(?string $path, ?string $keep = null): void
    {
        if (blank($path) || $path === $keep) {
            return;
        }

        if (filter_var($path, FILTER_VALIDATE_URL) !== false || str_starts_with($path, 'data:')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
