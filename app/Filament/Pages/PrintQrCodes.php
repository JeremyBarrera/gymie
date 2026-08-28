<?php

namespace App\Filament\Pages;

use App\Models\Location;
use App\Models\LocationToken;
use App\Models\User;
use App\Services\QrCodeService;
use App\Support\Permissions\PermissionFeatureFlags;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PrintQrCodes extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $slug = 'qr-codes';

    protected string $view = 'filament.pages.print-qr-codes';

    

    public $locations;

    public ?int $locationId = null;

    public function mount(): void
    {
        $this->refreshLocations();

        $this->locationId = $this->locationId ?? $this->locations->first()?->id;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole(PermissionFeatureFlags::OWNER_ROLE);
    }

    public function getTitle(): string|Htmlable
    {
        return __('app.reception.qr_codes');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.reception.qr_codes');
    }

    

    public function qrDataUri(LocationToken $token): ?string
    {
        try {
            $payload = app(QrCodeService::class)->build($token, 'png', 160);

            return 'data:'.$payload['mimeType'].';base64,'.$payload['content'];
        } catch (\Throwable) {
            return null;
        }
    }

    

    public function downloadUrl(int $locationId, string $type): string
    {
        return route('qr-codes.download', [
            'location_id' => $locationId,
            'type' => $type,
            'format' => 'png',
            'size' => 300,
        ]);
    }

    public function generate(string $type): void
    {
        $locationId = $this->locationId ?? $this->locations->first()?->id;

        if ($locationId === null) {
            $message = $this->locations->isEmpty()
                ? __('app.reception.qr_error_no_locations')
                : __('app.reception.qr_error_no_location');

            Notification::make()
                ->title(__('app.reception.qr_error'))
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        $token = app(QrCodeService::class)->tokenForLocation($locationId, $type);

        if (! $token) {
            Notification::make()
                ->title(__('app.reception.qr_error'))
                ->body(__('app.reception.qr_error_no_token'))
                ->danger()
                ->send();

            return;
        }

        $this->redirect(QrCodePreview::getUrl([
            'location_id' => $locationId,
            'type' => $type,
        ]));
    }

    public function deleteToken(int $tokenId): void
    {
        $token = LocationToken::query()->find($tokenId);

        if ($token === null) {
            return;
        }

        $token->delete();
        $this->refreshLocations();

        Notification::make()
            ->title(__('app.reception.qr_token_deleted_title'))
            ->body(__('app.reception.qr_token_deleted_body'))
            ->success()
            ->send();
    }

    public function createToken(int $locationId, string $type): void
    {
        $token = app(QrCodeService::class)->tokenForLocation($locationId, $type);

        if (! $token) {
            LocationToken::query()->create([
                'location_id' => $locationId,
                'token' => Str::random(40),
                'tokenable_type' => Location::class,
                'tokenable_id' => $locationId,
                'kind' => $type,
            ]);

            $this->refreshLocations();
        }

        $this->redirect(QrCodePreview::getUrl([
            'location_id' => $locationId,
            'type' => $type,
        ]));
    }

    public function previewUrl(int $locationId, string $type): string
    {
        return QrCodePreview::getUrl([
            'location_id' => $locationId,
            'type' => $type,
        ]);
    }

    private function refreshLocations(): void
    {
        $this->locations = Location::query()
            ->with('tokens')
            ->orderBy('name')
            ->get();
    }
}
