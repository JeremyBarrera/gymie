<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\QrCodeService;
use App\Support\Locations\LocationAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a generated QR code file for download.
 *
 * Rebuilds the QR server-side (same service the preview page uses) and sends
 * the raw bytes with `Content-Disposition: attachment`, so the browser offers
 * a real file download instead of relying on a client-side blob.
 */
class QrCodeDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $locationId = (int) $request->query('location_id', 0);

        if ($locationId <= 0 || ! LocationAccess::canAccess($user, $locationId)) {
            abort(403);
        }

        $type = in_array($request->query('type'), ['checkin', 'signup'], true)
            ? (string) $request->query('type')
            : 'checkin';

        $token = app(QrCodeService::class)->tokenForLocation($locationId, $type);

        if ($token === null) {
            abort(404);
        }

        $format = 'png';
        $size = max((int) $request->query('size', 300), 100);

        try {
            $payload = app(QrCodeService::class)->build($token, $format, $size);
        } catch (\Throwable) {
            abort(500);
        }

        $content = base64_decode($payload['content'], true);

        if ($content === false) {
            abort(500);
        }

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $payload['filename'], [
            'Content-Type' => $payload['mimeType'],
        ]);
    }
}
