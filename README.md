# SIMRS-Khanza (Integrasi ObaPay)

Repository ini merupakan fork dari [mas-elkhanza/SIMRS-Khanza](https://github.com/mas-elkhanza/SIMRS-Khanza) yang disesuaikan dengan integrasi **ObaPay** (API HMAC-SHA256, SSO, dan integrasi resep).

## Kustomisasi Utama

- **Branch kerja:** `obapay`
- **Modul Integrasi ObaPay:** [`api-obapay/`](api-obapay/README.md) — API PHP terisolasi (*read-only* ke database `sik`) dengan autentikasi **HMAC-SHA256** untuk sinkronisasi data registrasi, ruangan terakhir, dan SEP ke ObaPay. Lihat dokumentasi lengkap di [`api-obapay/README.md`](api-obapay/README.md).

## Struktur Remote Git

- `origin` → `https://github.com/danifahmy5/SIMRS-Khanza.git` (fork ini)
- `upstream` → `https://github.com/mas-elkhanza/SIMRS-Khanza.git` (repository resmi SIMRS-Khanza)

### Mengambil Update dari SIMRS-Khanza (`upstream`)

Biarkan branch `master` mengikuti `upstream`, dan lakukan seluruh pengembangan integrasi di branch `obapay`:

```bash
git fetch upstream
git checkout obapay
git merge upstream/master
git push origin obapay
```

## Catatan Keamanan (Repository Public)

> **Penting:** Karena repository ini bersifat **Public**, pastikan hal berikut tidak pernah ikut ter-commit:
> - Perubahan kredensial di `setting/database.xml`
> - Secret produksi di `api-obapay/conf.php` (gunakan *environment variable* `OBAPAY_DB_*` dan `OBAPAY_SHARED_SECRET`)
> - Data pasien, file hasil export `.pdf`/`.html`/`.jpg`, atau dump `.sql`

Untuk mengabaikan perubahan lokal pada `setting/database.xml` agar tidak ikut ter-commit:

```bash
git update-index --skip-worktree setting/database.xml
```

## Lisensi & Atribusi Asli

Software dasar **SIMRS-Khanza** dikembangkan oleh **Khanza.Soft Media** dan menganut lisensi [Aladdin Free Public License](https://en.wikipedia.org/wiki/Aladdin_Free_Public_License).

- Detail informasi asli, panduan wiki, tautan build, dan donasi tersedia di [`README.TXT`](README.TXT).
- Wiki resmi: https://github.com/mas-elkhanza/SIMRS-Khanza/wiki
