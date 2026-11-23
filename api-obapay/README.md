# api-obapay (Integrasi SIMRS Khanza ↔ ObaPay)

Folder ini terisolasi sepenuhnya dari modul lain di SIMRS Khanza (hanya melakukan query **read-only** ke database `sik`), mengikuti pola folder `api-bpjsfktl`, `api-bpjsfktp`, dan `api-bridgingradiologi`.

## Endpoint

Semua endpoint dilindungi autentikasi **HMAC-SHA256** (`X-Timestamp` dan `X-Signature`) yang kompatibel langsung dengan `App\Services\Integrations\ErmApiService` di ObaPay:

| Method | Path | Fungsi |
| :--- | :--- | :--- |
| `GET` | `/api/obapay/sync/{appointment_id}` | Sinkronisasi data registrasi, pasien, dokter, poli/spesialis, SEP, berat badan, alergi, dan LOS |
| `GET` | `/api/obapay/last-room/{appointment_id}` | Mengambil ruangan terakhir pasien (Bangsal/Kamar Ranap jika ada, fallback ke Poliklinik) |
| `GET` | `/api/obapay/last-sep/{appointment_id}` | Mengambil nomor SEP terakhir dari `bridging_sep` |
| `GET` | `/health` | Health check tanpa autentikasi |

> **Catatan Format `appointment_id`**:
> ObaPay memvalidasi `appointment_id` sebagai integer (`min:1`), sedangkan `reg_periksa.no_rawat` di SIMRS Khanza berformat `YYYY/MM/DD/NNNNNN` (contoh: `2026/11/23/000001`).
> Gunakan versi numerik tanpa `/` yaitu `20261123000001` saat memanggil dari ObaPay — `api-obapay` otomatis mengonversinya kembali ke `2026/11/23/000001` (serta mendukung lookup berdasarkan `no_sep`).

## Konfigurasi

### 1. Di SIMRS Khanza (`api-obapay/conf.php`)
Sesuaikan konfigurasi database dan `OBAPAY_SHARED_SECRET` di `api-obapay/conf.php` (atau via environment variables):

```bash
OBAPAY_DB_HOST=localhost
OBAPAY_DB_PORT=3306
OBAPAY_DB_USER=root
OBAPAY_DB_PASS=
OBAPAY_DB_NAME=sik
OBAPAY_SHARED_SECRET=ganti_dengan_secret_yang_sama_dengan_obapay
```

Jika dokter atau poliklinik sudah memiliki ID tetap di ObaPay, Anda dapat memetakannya di `OBAPAY_DOCTOR_ID_MAP` dan `OBAPAY_SPECIALIST_ID_MAP` pada `conf.php` tanpa perlu mengubah tabel SIMRS Khanza.

### 2. Di ObaPay (`.env` & `config/integrations.php`)
Tambahkan issuer `SIMRS_KHANZA` di `config/integrations.php` (bagian `erm.secrets`), lalu atur `.env` ObaPay:

```env
ERM_API_URL=http://host-khanza/api-obapay
ERM_ISSUER=SIMRS_KHANZA
INTEGRATION_ERM_SECRET_SIMRS_KHANZA=ganti_dengan_secret_yang_sama_dengan_obapay
```

## Contoh Uji Coba (PHP Built-in Server + cURL)

```bash
# Jalankan server lokal untuk testing
OBAPAY_SHARED_SECRET=test-secret php -S 127.0.0.1:8088 -t api-obapay

# Uji dengan HMAC signature
TS=$(date +%s)
PATH_SIGN="api/obapay/sync/20261123000001"
SIG=$(printf "%s.%s." "$TS" "$PATH_SIGN" | openssl dgst -sha256 -hmac "test-secret" | awk '{print $2}')

curl -i \
  -H "Accept: application/json" \
  -H "X-Timestamp: $TS" \
  -H "X-Signature: $SIG" \
  "http://127.0.0.1:8088/$PATH_SIGN"
```
