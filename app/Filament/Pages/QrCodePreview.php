<?php

namespace App\Filament\Pages;

use App\Models\Location;
use App\Models\User;
use App\Services\QrCodeService;
use App\Support\Locations\LocationAccess;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class QrCodePreview extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $slug = 'qr-code-preview';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.qr-code-preview';

    public ?int $locationId = null;

    public string $type = 'checkin';

    public string $format = 'png';

    public int $size = 300;

    public ?string $dataUri = null;

    public ?string $downloadUrl = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->locationId = (int) (request()->query('location_id', 0));
        $this->type = in_array(request()->query('type'), ['checkin', 'signup'], true)
            ? (string) request()->query('type')
            : 'checkin';
        $this->format = request()->query('format') === 'svg' ? 'svg' : 'png';
        $this->size = max((int) request()->query('size', 300), 100);

        if ($this->locationId <= 0) {
            $this->error = __('app.reception.qr_error_no_location');

            return;
        }

        if (! LocationAccess::canAccess(Auth::user(), $this->locationId)) {
            $this->error = __('app.reception.qr_error_no_location');

            return;
        }

        $token = app(QrCodeService::class)->tokenForLocation($this->locationId, $this->type);

        if ($token === null) {
            $this->error = __('app.reception.qr_error_no_token');

            return;
        }

        try {
            $payload = app(QrCodeService::class)->build($token, $this->format, $this->size);

            $this->dataUri = 'data:'.$payload['mimeType'].';base64,'.$payload['content'];

            $this->downloadUrl = route('qr-codes.download', [
                'location_id' => $this->locationId,
                'type' => $this->type,
                'format' => $payload['format'],
                'size' => $this->size,
            ]);
        } catch (\Throwable $e) {
            Log::error('QR code preview failed', ['error' => $e->getMessage()]);

            $this->error = __('app.reception.qr_error_generation');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('app.reception.qr_preview_title');
    }

    public function getHeader(): ?View
    {
        return view('filament.pages.partials.qr-preview-header', [
            'heading' => $this->getHeading(),
        ]);
    }

    public function getLocation(): ?Location
    {
        if ($this->locationId === null) {
            return null;
        }

        return Location::query()->find($this->locationId);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $locationId = (int) (request()->query('location_id', 0));

        return LocationAccess::canAccess($user, $locationId > 0 ? $locationId : null);
    }
}
