<?php
/**
 * Service layer untuk query read-only data SIMRS Khanza guna sinkronisasi ke ObaPay.
 */

class ObapayService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Endpoint: GET /api/obapay/sync/{appointment_id}
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function syncAppointment(string $appointmentId, ?string $serviceType = null): array
    {
        $row = $this->findRegistration($appointmentId);

        if (!$row) {
            return [
                'status' => 404,
                'body' => [
                    'code' => 404,
                    'message' => "Data appointment dengan ID {$appointmentId} tidak ditemukan.",
                ],
            ];
        }

        $noRawat = (string) $row['no_rawat'];
        $statusLanjut = (string) ($row['status_lanjut'] ?? 'Ralan');
        $isRanap = strcasecmp($statusLanjut, 'Ranap') === 0
            || strcasecmp((string) $serviceType, 'rawat_inap') === 0
            || strcasecmp((string) $serviceType, 'ranap') === 0;

        $sepNumber = $this->findLatestSep($noRawat);
        $exam = $this->findLatestExamination($noRawat, $isRanap);
        $kamarInap = $isRanap ? $this->findLatestKamarInap($noRawat) : null;

        $paymentType = obapay_map_payment_type($row['kd_pj'] ?? null, $row['png_jawab'] ?? null);
        $appointmentDate = obapay_normalize_date($row['tgl_registrasi'] ?? null) ?? date('Y-m-d');
        $checkinTime = obapay_normalize_time($row['jam_reg'] ?? null, '08:00:00');
        $birthDate = obapay_normalize_date($row['tgl_lahir'] ?? null);
        $gender = strtoupper((string) ($row['jk'] ?? 'L')) === 'P' ? 'P' : 'L';
        $weight = obapay_extract_weight($exam['berat'] ?? null);
        $allergy = obapay_format_allergy($exam['alergi'] ?? null);

        $kdDokter = (string) ($row['kd_dokter'] ?? '');
        $doctorId = obapay_resolve_numeric_id($kdDokter, OBAPAY_DOCTOR_ID_MAP, 1);
        $doctorName = trim((string) ($row['nm_dokter'] ?? '')) ?: 'Dokter Pemeriksa';

        // Prioritaskan poliklinik (poli tujuan kunjungan), fallback ke spesialis dokter
        $kdPoli = trim((string) ($row['kd_poli'] ?? ''));
        $kdSps = trim((string) ($row['kd_sps'] ?? ''));
        $specialistCode = $kdPoli !== '' ? $kdPoli : $kdSps;
        $specialistId = obapay_resolve_numeric_id($specialistCode, OBAPAY_SPECIALIST_ID_MAP, 1);
        $specialistName = trim((string) ($row['nm_poli'] ?? ''))
            ?: trim((string) ($row['nm_sps'] ?? ''))
            ?: 'Poli Umum';

        $insuranceName = trim((string) ($row['png_jawab'] ?? ''));
        if ($insuranceName === '') {
            $insuranceName = $paymentType === 'BPJS' ? 'BPJS Kesehatan' : 'Umum';
        }

        $numericAppointmentId = obapay_no_rawat_to_numeric_id($noRawat);
        $medicalRecordNo = (string) ($row['no_rkm_medis'] ?? '');

        $data = [
            'id' => $numericAppointmentId,
            'registration_number' => $noRawat,
            'medical_record_no' => $medicalRecordNo,
            'date' => $appointmentDate,
            'checkin_time' => $checkinTime,
            'shift' => obapay_determine_shift($row['jam_reg'] ?? null),
            'payment_type' => $paymentType,
            'los' => obapay_calculate_los($kamarInap),
            'insurance_provider' => [
                'id' => obapay_resolve_numeric_id($row['kd_pj'] ?? null, [], $paymentType === 'BPJS' ? 1 : 2),
                'code' => $row['kd_pj'] ?? null,
                'name' => $insuranceName,
            ],
            'sep_number' => $sepNumber !== '' ? $sepNumber : null,
            'patient' => [
                'ihs_number' => null,
                'medical_record_no' => $medicalRecordNo,
                'name' => trim((string) ($row['nm_pasien'] ?? '')) ?: 'Pasien',
                'birth_date' => $birthDate,
                'gender' => $gender,
                'address' => ($row['alamat'] ?? null) !== '' ? $row['alamat'] : null,
                'phone' => ($row['no_tlp'] ?? null) !== '' ? $row['no_tlp'] : null,
                'weight' => $weight,
                'allergy' => $allergy,
            ],
            'doctor' => [
                'id' => $doctorId,
                'code' => $kdDokter !== '' ? $kdDokter : null,
                'name' => $doctorName,
                'bpjs_code' => ($row['kd_dokter_bpjs'] ?? null) !== '' ? $row['kd_dokter_bpjs'] : null,
                'specialist' => [
                    'id' => $specialistId,
                    'code' => $specialistCode !== '' ? $specialistCode : null,
                    'name' => $specialistName,
                    'bpjs_code' => ($row['kd_poli_bpjs'] ?? null) !== '' ? $row['kd_poli_bpjs'] : null,
                ],
            ],
        ];

        return [
            'status' => 200,
            'body' => [
                'code' => 200,
                'message' => 'berhasil mengambil data pasien',
                'data' => $data,
            ],
        ];
    }

    /**
     * Endpoint: GET /api/obapay/last-room/{appointment_id}
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function lastRoom(string $appointmentId): array
    {
        $row = $this->findRegistration($appointmentId);

        if (!$row) {
            return [
                'status' => 200,
                'body' => [
                    'code' => 200,
                    'message' => 'berhasil mengambil ruangan terakhir pasien',
                    'data' => null,
                ],
            ];
        }

        $noRawat = (string) $row['no_rawat'];
        $numericAppointmentId = obapay_no_rawat_to_numeric_id($noRawat);
        $kamarInap = $this->findLatestKamarInap($noRawat);

        if ($kamarInap) {
            $bangsalName = trim((string) ($kamarInap['nm_bangsal'] ?? ''));
            $kdKamar = trim((string) ($kamarInap['kd_kamar'] ?? ''));
            $roomName = $bangsalName !== ''
                ? ($kdKamar !== '' ? "{$bangsalName} ({$kdKamar})" : $bangsalName)
                : ($kdKamar !== '' ? $kdKamar : 'Ruang Rawat Inap');
            $roomCode = trim((string) ($kamarInap['kd_bangsal'] ?? '')) ?: $kdKamar;

            $payload = [
                'appointment_id' => $numericAppointmentId,
                'room_id' => obapay_resolve_numeric_id($roomCode, [], 1),
                'room_name' => $roomName,
            ];
        } else {
            $kdPoli = trim((string) ($row['kd_poli'] ?? ''));
            $nmPoli = trim((string) ($row['nm_poli'] ?? '')) ?: 'Poli Rawat Jalan';

            $payload = [
                'appointment_id' => $numericAppointmentId,
                'room_id' => obapay_resolve_numeric_id($kdPoli, OBAPAY_SPECIALIST_ID_MAP, 1),
                'room_name' => $nmPoli,
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'code' => 200,
                'message' => 'berhasil mengambil ruangan terakhir pasien',
                'data' => $payload,
            ],
        ];
    }

    /**
     * Endpoint: GET /api/obapay/last-sep/{appointment_id}
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function lastSep(string $appointmentId): array
    {
        $row = $this->findRegistration($appointmentId);
        $sep = $row ? $this->findLatestSep((string) $row['no_rawat']) : '';

        return [
            'status' => 200,
            'body' => [
                'code' => 200,
                'message' => 'berhasil mengambil sep',
                'data' => $sep,
            ],
        ];
    }

    /**
     * Cari data reg_periksa berdasarkan appointment_id (numeric no_rawat, no_rawat asli, atau no_sep).
     *
     * @return array<string, mixed>|null
     */
    private function findRegistration(string $appointmentId): ?array
    {
        $parsed = obapay_parse_appointment_id($appointmentId);
        $baseSelect = <<<'SQL'
            SELECT
                rp.no_reg,
                rp.no_rawat,
                rp.tgl_registrasi,
                rp.jam_reg,
                rp.kd_dokter,
                rp.no_rkm_medis,
                rp.kd_poli,
                rp.status_lanjut,
                rp.kd_pj,
                p.nm_pasien,
                p.jk,
                p.tgl_lahir,
                p.alamat,
                p.no_tlp,
                d.nm_dokter,
                d.kd_sps,
                s.nm_sps,
                pl.nm_poli,
                pj.png_jawab,
                pj.nama_perusahaan,
                md.kd_dokter_bpjs,
                mp.kd_poli_bpjs
            FROM reg_periksa rp
            INNER JOIN pasien p ON p.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN dokter d ON d.kd_dokter = rp.kd_dokter
            LEFT JOIN spesialis s ON s.kd_sps = d.kd_sps
            LEFT JOIN poliklinik pl ON pl.kd_poli = rp.kd_poli
            LEFT JOIN penjab pj ON pj.kd_pj = rp.kd_pj
            LEFT JOIN maping_dokter_dpjpvclaim md ON md.kd_dokter = rp.kd_dokter
            LEFT JOIN maping_poli_bpjs mp ON mp.kd_poli_rs = rp.kd_poli
        SQL;

        // 1. Exact lookup dengan index PRIMARY KEY (paling cepat)
        if ($parsed['formatted_no_rawat'] !== null) {
            $stmt = $this->pdo->prepare($baseSelect . ' WHERE rp.no_rawat = :no_rawat LIMIT 1');
            $stmt->execute([':no_rawat' => $parsed['formatted_no_rawat']]);
            $found = $stmt->fetch();
            if ($found) {
                return $found;
            }
        }

        // 2. Exact match langsung dengan nilai raw (misal no_rawat non-standar)
        if ($parsed['raw'] !== '' && $parsed['raw'] !== $parsed['formatted_no_rawat']) {
            $stmt = $this->pdo->prepare($baseSelect . ' WHERE rp.no_rawat = :raw LIMIT 1');
            $stmt->execute([':raw' => $parsed['raw']]);
            $found = $stmt->fetch();
            if ($found) {
                return $found;
            }
        }

        // 3. Fallback lookup berdasarkan no_sep (digunakan fitur klaim BPJS ObaPay)
        if ($parsed['raw'] !== '') {
            $stmt = $this->pdo->prepare(
                $baseSelect
                . ' INNER JOIN bridging_sep bs ON bs.no_rawat = rp.no_rawat'
                . ' WHERE bs.no_sep = :no_sep'
                . ' ORDER BY bs.tglsep DESC LIMIT 1'
            );
            $stmt->execute([':no_sep' => $parsed['raw']]);
            $found = $stmt->fetch();
            if ($found) {
                return $found;
            }
        }

        // 4. Fallback terakhir: bandingkan no_rawat tanpa '/'
        if ($parsed['compact_digits'] !== '') {
            $stmt = $this->pdo->prepare(
                $baseSelect . " WHERE REPLACE(rp.no_rawat, '/', '') = :compact LIMIT 1"
            );
            $stmt->execute([':compact' => $parsed['compact_digits']]);
            $found = $stmt->fetch();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Ambil nomor SEP terbaru untuk no_rawat.
     */
    private function findLatestSep(string $noRawat): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT no_sep FROM bridging_sep WHERE no_rawat = :no_rawat ORDER BY tglsep DESC LIMIT 1'
        );
        $stmt->execute([':no_rawat' => $noRawat]);
        $sep = $stmt->fetchColumn();

        return $sep !== false && $sep !== null ? trim((string) $sep) : '';
    }

    /**
     * Ambil pemeriksaan terbaru (berat badan & alergi) dari pemeriksaan_ranap / pemeriksaan_ralan.
     *
     * @return array{berat: mixed, alergi: mixed}|null
     */
    private function findLatestExamination(string $noRawat, bool $preferRanap): ?array
    {
        $tables = $preferRanap
            ? ['pemeriksaan_ranap', 'pemeriksaan_ralan']
            : ['pemeriksaan_ralan', 'pemeriksaan_ranap'];

        foreach ($tables as $table) {
            $sql = "SELECT berat, alergi FROM {$table}"
                . ' WHERE no_rawat = :no_rawat'
                . ' ORDER BY tgl_perawatan DESC, jam_rawat DESC LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':no_rawat' => $noRawat]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Ambil data kamar inap terbaru untuk no_rawat.
     *
     * @return array<string, mixed>|null
     */
    private function findLatestKamarInap(string $noRawat): ?array
    {
        $sql = <<<'SQL'
            SELECT
                ki.kd_kamar,
                ki.tgl_masuk,
                ki.jam_masuk,
                ki.tgl_keluar,
                ki.lama,
                k.kd_bangsal,
                b.nm_bangsal
            FROM kamar_inap ki
            LEFT JOIN kamar k ON k.kd_kamar = ki.kd_kamar
            LEFT JOIN bangsal b ON b.kd_bangsal = k.kd_bangsal
            WHERE ki.no_rawat = :no_rawat
            ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':no_rawat' => $noRawat]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
