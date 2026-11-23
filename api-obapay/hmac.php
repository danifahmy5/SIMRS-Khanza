<?php
/**
 * Verifikasi HMAC-SHA256 untuk request server-to-server dari ObaPay.
 *
 * Kompatibel dengan App\Services\Integrations\ErmApiService::signedRequest() di ObaPay:
 *   - Header X-Timestamp: Unix timestamp (toleransi ±300 detik)
 *   - Header X-Signature: HMAC-SHA256 hex dari "{timestamp}.{normalizedPath}.{rawBody}"
 */

/**
 * Ambil semua HTTP request headers dengan key lowercase.
 * Bekerja di Apache, Nginx/PHP-FPM, dan PHP built-in server.
 *
 * @return array<string, string>
 */
function obapay_request_headers(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $raw = getallheaders();
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                $headers[strtolower((string) $key)] = (string) $value;
            }
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = (string) $value;
            }
        }
    }

    return $headers;
}

/**
 * Verifikasi HMAC signature dari request masuk.
 *
 * @param string $normalizedPath Path relatif tanpa leading slash (mis. "api/obapay/sync/20261123000001")
 * @param string $rawBody        Raw request body (kosong "" untuk GET)
 * @return array{ok: bool, status: int, message: string}
 */
function obapay_verify_hmac(string $normalizedPath, string $rawBody = ''): array
{
    $headers = obapay_request_headers();
    $signature = $headers['x-signature'] ?? '';
    $timestamp = $headers['x-timestamp'] ?? '';

    if (OBAPAY_ALLOW_LOCAL_BYPASS && $signature === '') {
        return ['ok' => true, 'status' => 200, 'message' => 'OK'];
    }

    if ($signature === '' || $timestamp === '') {
        return [
            'ok' => false,
            'status' => 401,
            'message' => 'Missing authentication headers (X-Signature, X-Timestamp).',
        ];
    }

    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > OBAPAY_TIMESTAMP_TOLERANCE) {
        return [
            'ok' => false,
            'status' => 401,
            'message' => 'Request timestamp expired or invalid.',
        ];
    }

    $secret = OBAPAY_SHARED_SECRET;
    if ($secret === '') {
        return [
            'ok' => false,
            'status' => 500,
            'message' => 'Server integration is not properly configured (OBAPAY_SHARED_SECRET kosong).',
        ];
    }

    $cleanPath = ltrim($normalizedPath, '/');
    $signingString = $timestamp . '.' . $cleanPath . '.' . $rawBody;
    $expectedSignature = hash_hmac('sha256', $signingString, $secret);

    if (!hash_equals($expectedSignature, $signature)) {
        return [
            'ok' => false,
            'status' => 401,
            'message' => 'Invalid signature.',
        ];
    }

    return ['ok' => true, 'status' => 200, 'message' => 'OK'];
}
