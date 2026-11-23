<?php
/**
 * Helper functions untuk konversi dan normalisasi data SIMRS Khanza -> ObaPay.
 */

/**
 * Kirim JSON response dan hentikan eksekusi.
 *
 * @param array<string, mixed> $payload
 */
function obapay_json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Normalisasi input appointment_id menjadi kandidat pencarian di SIMRS Khanza.
 *
 * SIMRS Khanza menggunakan no_rawat berformat "YYYY/MM/DD/NNNNNN" (mis. "2026/11/23/000001"),
 * sedangkan ObaPay memvalidasi appointment_id sebagai integer (mis. "20261123000001").
 *
 * @return array{raw: string, formatted_no_rawat: ?string, compact_digits: string}
 */
function obapay_parse_appointment_id(string $input): array
{
    $raw = trim(urldecode($input));
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $formattedNoRawat = null;

    if (preg_match('/^\d{4}\/\d{2}\/\d{2}\/\d+$/', $raw)) {
        $formattedNoRawat = $raw;
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d+)$/', $raw, $m)) {
        $formattedNoRawat = "{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}";
    } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{4,})$/', $digits, $m)) {
        $formattedNoRawat = "{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}";
    }

    return [
        'raw' => $raw,
        'formatted_no_rawat' => $formattedNoRawat,
        'compact_digits' => $digits,
    ];
}

/**
 * Konversi no_rawat SIMRS Khanza ("2026/11/23/000001") menjadi numeric ID ("20261123000001")
 * yang kompatibel dengan kolom BIGINT appointments.id di ObaPay.
 */
function obapay_no_rawat_to_numeric_id(string $noRawat): string
{
    $digits = preg_replace('/\D+/', '', $noRawat) ?? '';
    if ($digits !== '' && ltrim($digits, '0') !== '') {
        return ltrim($digits, '0');
    }

    return (string) obapay_resolve_numeric_id($noRawat);
}

/**
 * Konversi kode string Khanza (kd_dokter, kd_poli, kd_sps, kd_bangsal) menjadi
 * positive integer ID yang stabil untuk ObaPay.
 *
 * @param array<string, int> $customMap
 */
function obapay_resolve_numeric_id(?string $code, array $customMap = [], int $fallback = 1): int
{
    $trimmed = trim((string) $code);
    if ($trimmed === '' || $trimmed === '-') {
        return $fallback;
    }

    if (isset($customMap[$trimmed])) {
        return (int) $customMap[$trimmed];
    }

    if (ctype_digit($trimmed) && (int) $trimmed > 0) {
        return (int) $trimmed;
    }

    // Deterministic positive 31-bit integer (1 .. 2147483647)
    $hash = ((int) sprintf('%u', crc32($trimmed))) & 0x7FFFFFFF;
    return $hash > 0 ? $hash : $fallback;
}

/**
 * Mapping penjab SIMRS Khanza -> kategori payment_type ObaPay (BPJS | UMUM | ASURANSI).
 */
function obapay_map_payment_type(?string $kdPj, ?string $pngJawab): string
{
    $code = strtoupper(trim((string) $kdPj));
    $name = strtolower(trim((string) $pngJawab));

    $bpjsCodes = array_map('strtoupper', OBAPAY_BPJS_KODE_PJ);
    if (in_array($code, $bpjsCodes, true)
        || str_contains($name, 'bpjs')
        || str_contains($name, 'jkn')
        || str_contains($name, 'kis')
    ) {
        return 'BPJS';
    }

    $umumCodes = array_map('strtoupper', OBAPAY_UMUM_KODE_PJ);
    if (in_array($code, $umumCodes, true)
        || str_contains($name, 'umum')
        || str_contains($name, 'pribadi')
        || str_contains($name, 'tunai')
        || ($code === '' && $name === '')
    ) {
        return 'UMUM';
    }

    return 'ASURANSI';
}

/**
 * Sanitasi tanggal MySQL (menghindari '0000-00-00' yang gagal validasi di ObaPay).
 */
function obapay_normalize_date(?string $value): ?string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
        return null;
    }

    try {
        return (new DateTimeImmutable($trimmed))->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Sanitasi jam MySQL ke format H:i:s.
 */
function obapay_normalize_time(?string $value, string $default = '08:00:00'): string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return $default;
    }

    try {
        return (new DateTimeImmutable($trimmed))->format('H:i:s');
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Tentukan shift ('1' = Pagi, '2' = Siang/Sore) dari jam registrasi.
 */
function obapay_determine_shift(?string $jamReg): string
{
    $time = obapay_normalize_time($jamReg, '08:00:00');
    return $time >= '14:00:00' ? '2' : '1';
}

/**
 * Ekstrak berat badan numerik yang valid (0..500) sesuai validator ObaPay.
 */
function obapay_extract_weight(mixed $rawWeight): ?float
{
    if ($rawWeight === null) {
        return null;
    }

    $clean = str_replace(',', '.', trim((string) $rawWeight));
    if ($clean === '') {
        return null;
    }

    if (preg_match('/\d+(?:\.\d+)?/', $clean, $matches)) {
        $weight = (float) $matches[0];
        if ($weight >= 0 && $weight <= 500) {
            return $weight;
        }
    }

    return null;
}

/**
 * Format alergi dari pemeriksaan_ralan/pemeriksaan_ranap ke struktur ObaPay.
 *
 * @return array{obat: ?string, reaksi_obat: ?string, medicine: ?string, medicine_reaction: ?string}|null
 */
function obapay_format_allergy(?string $rawAllergy): ?array
{
    $val = trim((string) $rawAllergy);
    $lower = strtolower($val);

    if ($val === ''
        || in_array($lower, ['-', '--', 'tidak', 'tidak ada', 'tdk ada', 'none', 'nil', 'null', '0'], true)
    ) {
        return null;
    }

    return [
        'obat' => $val,
        'reaksi_obat' => null,
        'medicine' => $val,
        'medicine_reaction' => null,
    ];
}

/**
 * Hitung Length of Stay (LOS) untuk pasien rawat inap.
 *
 * @param array<string, mixed>|null $kamarInap
 */
function obapay_calculate_los(?array $kamarInap): ?int
{
    if (!$kamarInap) {
        return null;
    }

    if (isset($kamarInap['lama']) && is_numeric($kamarInap['lama']) && (int) $kamarInap['lama'] > 0) {
        return (int) $kamarInap['lama'];
    }

    $tglMasuk = obapay_normalize_date($kamarInap['tgl_masuk'] ?? null);
    if (!$tglMasuk) {
        return null;
    }

    $tglKeluar = obapay_normalize_date($kamarInap['tgl_keluar'] ?? null) ?? date('Y-m-d');

    try {
        $start = new DateTimeImmutable($tglMasuk);
        $end = new DateTimeImmutable($tglKeluar);
        $diff = (int) $start->diff($end)->days;
        return max(1, $diff + 1);
    } catch (Throwable) {
        return null;
    }
}
