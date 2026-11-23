<?php
/**
 * Entrypoint API Integrasi SIMRS Khanza <-> ObaPay (api-obapay)
 *
 * Route yang didukung (kompatibel dengan ErmApiService di ObaPay):
 *   GET /api/obapay/sync/{appointment_id}
 *   GET /api/obapay/last-room/{appointment_id}
 *   GET /api/obapay/last-sep/{appointment_id}
 *   GET /health
 *
 * Route alias tanpa prefix "api/obapay/" juga didukung:
 *   GET /sync/{appointment_id}
 *   GET /last-room/{appointment_id}
 *   GET /last-sep/{appointment_id}
 */

header('X-Robots-Tag: noindex', true);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Signature, X-Timestamp');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/conf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hmac.php';
require_once __DIR__ . '/ObapayService.php';

// Resolve relative route path (works with Apache .htaccess ?url=..., Nginx, and PHP built-in server)
if (isset($_GET['url']) && is_string($_GET['url'])) {
    $routePath = trim($_GET['url'], '/');
} else {
    $requestUriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($requestUriPath, $scriptDir . '/')) {
        $requestUriPath = substr($requestUriPath, strlen($scriptDir));
    }
    $routePath = trim($requestUriPath, '/');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Health check (tanpa autentikasi HMAC)
if ($method === 'GET' && ($routePath === '' || $routePath === 'health')) {
    obapay_json_response([
        'code' => 200,
        'message' => 'SIMRS Khanza api-obapay service is running.',
    ], 200);
}

if ($method !== 'GET') {
    obapay_json_response([
        'code' => 405,
        'message' => 'Method not allowed. Gunakan HTTP GET.',
    ], 405);
}

// Match route: (api/obapay/)?(sync|last-room|last-sep)/{appointment_id}
if (!preg_match('#^(?:api/obapay/)?(sync|last-room|last-sep)/(.+)$#', $routePath, $matches)) {
    obapay_json_response([
        'code' => 404,
        'message' => 'Endpoint tidak ditemukan.',
    ], 404);
}

$action = $matches[1];
$appointmentId = $matches[2];

// Verifikasi HMAC signature menggunakan path yang diminta oleh client
$rawBody = (string) file_get_contents('php://input');
$auth = obapay_verify_hmac($routePath, $rawBody);
if (!$auth['ok']) {
    obapay_json_response([
        'code' => $auth['status'],
        'message' => $auth['message'],
    ], $auth['status']);
}

try {
    $pdo = obapay_db();
    $service = new ObapayService($pdo);

    $serviceType = isset($_GET['service_type']) ? trim((string) $_GET['service_type']) : null;

    $result = match ($action) {
        'sync' => $service->syncAppointment($appointmentId, $serviceType),
        'last-room' => $service->lastRoom($appointmentId),
        'last-sep' => $service->lastSep($appointmentId),
    };

    obapay_json_response($result['body'], $result['status']);
} catch (PDOException $e) {
    error_log('[api-obapay] Database error: ' . $e->getMessage());
    obapay_json_response([
        'code' => 500,
        'message' => 'Gagal terhubung atau mengambil data dari database SIMRS Khanza.',
    ], 500);
} catch (Throwable $e) {
    error_log('[api-obapay] Unexpected error: ' . $e->getMessage());
    obapay_json_response([
        'code' => 500,
        'message' => 'Terjadi kesalahan internal pada layanan api-obapay.',
    ], 500);
}
