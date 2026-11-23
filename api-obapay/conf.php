<?php
/**
 * Konfigurasi Integrasi SIMRS Khanza -> ObaPay (api-obapay)
 *
 * File ini terisolasi di folder api-obapay agar tidak mengganggu proses bisnis
 * lain di SIMRS Khanza. Semua konfigurasi dapat di-override via environment
 * variables tanpa mengubah kode.
 */

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('obapay_env')) {
    function obapay_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

// =========================================================================
// Konfigurasi Database SIMRS Khanza (Read-Only untuk integrasi ObaPay)
// =========================================================================
define('OBAPAY_DB_HOST', (string) obapay_env('OBAPAY_DB_HOST', 'localhost'));
define('OBAPAY_DB_PORT', (string) obapay_env('OBAPAY_DB_PORT', '3306'));
define('OBAPAY_DB_USER', (string) obapay_env('OBAPAY_DB_USER', 'root'));
define('OBAPAY_DB_PASS', (string) obapay_env('OBAPAY_DB_PASS', ''));
define('OBAPAY_DB_NAME', (string) obapay_env('OBAPAY_DB_NAME', 'sik'));

// =========================================================================
// Konfigurasi Autentikasi HMAC-SHA256 (harus sama dengan secret di ObaPay)
// =========================================================================
// Di ObaPay, set INTEGRATION_ERM_SECRET_SIMRS_KHANZA (atau issuer yang dipakai)
// dengan nilai yang sama persis.
define('OBAPAY_SHARED_SECRET', (string) obapay_env('OBAPAY_SHARED_SECRET', ''));

// Toleransi selisih waktu X-Timestamp dalam detik (default: 300 detik / 5 menit)
define('OBAPAY_TIMESTAMP_TOLERANCE', (int) obapay_env('OBAPAY_TIMESTAMP_TOLERANCE', 300));

// Set true HANYA untuk testing lokal tanpa header X-Signature
define(
    'OBAPAY_ALLOW_LOCAL_BYPASS',
    filter_var(obapay_env('OBAPAY_ALLOW_LOCAL_BYPASS', 'false'), FILTER_VALIDATE_BOOLEAN)
);

// =========================================================================
// Mapping Kategori Penjamin (kd_pj di tabel penjab SIMRS Khanza)
// =========================================================================
// Kode kd_pj yang dianggap sebagai BPJS atau UMUM (case-insensitive).
// Selain dari daftar di bawah (dan pengecekan kata kunci pada png_jawab),
// akan dikategorikan sebagai ASURANSI.
define('OBAPAY_BPJS_KODE_PJ', ['BPJ', 'BPJS', 'B01']);
define('OBAPAY_UMUM_KODE_PJ', ['A09', 'UMU', 'UMUM', 'A01']);

// =========================================================================
// Mapping Manual Opsional: Kode Khanza -> ID ObaPay (integer)
// =========================================================================
// Jika dokter/poli sudah ada di ObaPay dengan user_id / specialist_id tertentu,
// daftarkan di sini agar sinkronisasi langsung cocok tanpa membuat data baru.
// Jika tidak terdaftar di sini, sistem akan menghasilkan ID integer deterministik.
//
// Contoh:
//   'D0000001' => 12,
define('OBAPAY_DOCTOR_ID_MAP', [
    // 'kd_dokter_khanza' => obapay_user_id,
]);

define('OBAPAY_SPECIALIST_ID_MAP', [
    // 'kd_poli_atau_kd_sps_khanza' => obapay_specialist_id,
]);

/**
 * Buat koneksi PDO ke database SIMRS Khanza.
 *
 * @throws PDOException
 */
function obapay_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        OBAPAY_DB_HOST,
        OBAPAY_DB_PORT,
        OBAPAY_DB_NAME
    );

    $pdo = new PDO($dsn, OBAPAY_DB_USER, OBAPAY_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
