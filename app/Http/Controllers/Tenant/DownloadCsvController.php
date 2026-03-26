<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves CSV export files from private local storage with HMAC verification.
 */
class DownloadCsvController extends Controller
{
    /**
     * Download a CSV export file with verification.
     */
    public function __invoke(Tenant $tenant, Request $request): StreamedResponse
    {
        $file = $request->query('file', '');
        $expires = (int) $request->query('expires', '0');
        $signature = $request->query('signature', '');

        if (! $file || ! $expires || ! $signature) {
            throw new AccessDeniedHttpException('Missing required parameters.');
        }

        if ($expires < time()) {
            throw new AccessDeniedHttpException('Download link has expired.');
        }

        $expectedSignature = self::generateSignature($tenant->uuid, $file, $expires);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new AccessDeniedHttpException('Invalid download signature.');
        }

        $diskPath = $tenant->uuid.'/'.$file;
        $disk = Storage::disk(config('export.disk', 'exports'));

        if (! $disk->exists($diskPath)) {
            throw new NotFoundHttpException('File not found.');
        }

        return $disk->download($diskPath, $file, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Generate an HMAC signature for a download URL.
     */
    public static function generateSignature(string $tenantUuid, string $file, int $expires): string
    {
        $secret = config('export.download_secret');
        $payload = implode('|', [$tenantUuid, $file, $expires]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
