<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationToken;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeService
{
    /**
     * The URL a phone lands on when scanning this token's QR code. QR_BASE_URL
     * wins when configured (phones scan from a different device than the
     * server, so APP_URL is often unreachable); blank falls back to APP_URL.
     */
    public function scanUrl(LocationToken $token): string
    {
        $path = $token->kind === 'signup'
            ? route('signup.scan', ['token' => $token->token], false)
            : route('checkin.scan', ['token' => $token->token], false);

        $base = rtrim((string) config('gymie.qr_base_url'), '/');

        return $base === '' ? url($path) : $base.$path;
    }

    public function tokenForLocation(int $locationId, string $kind): ?LocationToken
    {
        return LocationToken::query()
            ->where('tokenable_type', Location::class)
            ->where('tokenable_id', $locationId)
            ->where('kind', $kind)
            ->first();
    }

    /**
     * @return array{content: string, filename: string, mimeType: string, format: string}
     */
    public function build(LocationToken $token, string $format = 'png', int $size = 300): array
    {
        $resolvedFormat = $format === 'svg' ? 'svg' : 'png';

        if ($resolvedFormat === 'png' && ! extension_loaded('gd')) {
            $resolvedFormat = 'svg';
        }

        $writer = $resolvedFormat === 'svg' ? new SvgWriter : new PngWriter;

        $qrCode = (new Builder(
            writer: $writer,
            data: $this->scanUrl($token),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
            labelText: $token->kind === 'checkin'
                ? __('app.reception.qr_label_checkin')
                : __('app.reception.qr_label_signup'),
            labelFont: new OpenSans(14),
            labelAlignment: LabelAlignment::Center,
        ))->build();

        return [
            'content' => base64_encode($qrCode->getString()),
            'filename' => "qr-{$token->kind}-{$token->token}.{$resolvedFormat}",
            'mimeType' => $resolvedFormat === 'svg' ? 'image/svg+xml' : 'image/png',
            'format' => $resolvedFormat,
        ];
    }
}
