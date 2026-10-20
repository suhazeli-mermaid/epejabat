# Aliran Kerja GitHub dan Deployment cPanel

Dokumen ini meliputi deployment domain akar `https://pkdkb.epejabat.net` tanpa awalan `/epejabat`. PHP minimum ialah 8.2. Produksi memerlukan MySQL 8.0.16+ atau MariaDB 10.6+.

## 1. Localhost ke GitHub

Jalankan dari `C:\xampp\htdocs\epejabat`:

```powershell
git status --short --branch
git diff --check
git add -A
git status --short
git commit -m "feat: portal responsif, routing dan deployment e-Forms"
git push origin main
```

Branch repositori ini ialah `main`. Semak `git remote -v` sebelum push jika URL remote pernah berubah. Fail `.env`, `.env.production`, `config/app.php`, `config/core.key`, `config/setup.php`, sesi, backup dan pakej ZIP dikecualikan oleh `.gitignore`.

Untuk membina semula artefak selepas commit:

```powershell
C:\xampp\php\php.exe bin\build-deployment-sql.php
powershell -ExecutionPolicy Bypass -File bin\build-cpanel-package.ps1
```

Pakej ZIP dan checksum SHA-256 akan berada dalam `deployment/packages/`. Penjana ZIP enggan berjalan jika working tree belum bersih, supaya pakej sentiasa sepadan dengan commit Git.

## 2. Kandungan deployment

- `deployment/sql/epejabat_clean_install.sql` — 87 jadual dan 54 rekod ledger migrasi daripada Phase 0, Phase 1, Training, e-Forms Phase 2 serta Public Programs. Hanya data benih bukan peribadi dimasukkan; jadual `users` kekal kosong. Semak fail ini menggunakan `deployment/sql/SHA256SUMS.txt` sebelum import.
- `.env.production.example` — templat pembolehubah produksi tanpa rahsia.
- `config/app.php.production` — konfigurasi produksi yang membaca kredensial daripada `.env` dan mengesahkan domain/instance.
- `.htaccess.production` — aturan domain akar, HTTPS, HSTS, CSP, sekatan direktori dan front controller.
- `.user.ini.production` — memaksa `display_errors=Off`, `log_errors=On` dan cookie sesi selamat pada PHP cPanel.
- `bin/generate-core-key.php` — menjana kunci 256-bit sekali sahaja tanpa memaparkan nilainya.

Pakej SQL mesti diimport ke pangkalan data kosong. Jangan import fail ini di atas pangkalan data live sedia ada.

## 3. Deployment baharu melalui cPanel

### A. Sediakan domain dan fail

1. Dalam cPanel, tetapkan Document Root subdomain `pkdkb.epejabat.net` kepada direktori yang akan mengandungi `index.php`, contohnya `/home/CPANEL_USER/pkdkb.epejabat.net`. Jangan ekstrak ke satu lagi subfolder `epejabat`.
2. Buka File Manager, muat naik ZIP daripada `deployment/packages/`, kemudian Extract terus ke Document Root.
3. Pakej binaan sudah mengaktifkan kandungan `.htaccess.production` sebagai `.htaccess` dan `.user.ini.production` sebagai `.user.ini`. Jika memuat naik fail Git secara manual, salin kedua-dua templat produksi itu ke nama aktif dan gantikan versi XAMPP.
4. Pastikan `storage/logs`, `storage/files`, `storage/sessions`, `storage/documents`, `storage/imports`, `storage/backups` dan `storage/uploads` wujud serta boleh ditulis oleh proses PHP. Gunakan permission paling ketat yang berfungsi; lazimnya direktori `0750` dan fail rahsia `0600`.

### B. Cipta dan import pangkalan data

1. Dalam **MySQL Databases**, cipta database dan pengguna khusus, kemudian beri `ALL PRIVILEGES` kepada pengguna itu untuk database tersebut.
2. Dalam **phpMyAdmin**, pilih database kosong itu, pilih **Import**, pilih `deployment/sql/epejabat_clean_install.sql`, gunakan UTF-8/utf8mb4, kemudian tekan **Import/Go**.
3. Import akan memaparkan nilai `APP_INSTANCE`. Jika hasil itu terlepas, jalankan tab SQL:

```sql
SELECT setting_value AS APP_INSTANCE
FROM system_settings
WHERE setting_key = 'installation_instance';
```

4. Sahkan keadaan awal:

```sql
SELECT COUNT(*) AS users_must_be_zero FROM users;
SELECT setting_value FROM system_settings WHERE setting_key='installation_status';
SELECT COUNT(*) AS applied_migrations FROM database_migrations WHERE status='applied';
```

Nilai yang dijangka ialah `0`, `pending_admin`, dan `54`.

### C. Konfigurasi produksi

1. Salin `.env.production` (atau `.env.production.example`) kepada `.env`.
2. Isi `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, dan nilai `APP_INSTANCE` daripada phpMyAdmin. Kekalkan:

```dotenv
APP_ENV=production
APP_URL=https://pkdkb.epejabat.net
APP_DISPLAY_ERRORS=0
APP_LOG_ERRORS=1
```

3. Salin `config/app.php.production` kepada `config/app.php`. Templat ini sengaja tidak menyimpan kata laluan secara terus; ia membaca kredensial cPanel daripada `.env`.
4. Melalui cPanel Terminal, jana kunci aplikasi sekali sahaja:

```bash
php bin/generate-core-key.php
chmod 600 .env config/app.php config/core.key
```

Jangan jana semula `core.key` selepas data terenkripsi wujud. Simpan salinan kunci itu dalam backup rahsia di luar Document Root.

5. Salin `config/setup.example.php` kepada `config/setup.php`. Jana token rawak dengan `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, masukkan token itu dalam `setup.php`, dan kekalkan URL produksi.

### D. Lengkapkan installer

1. Buka `https://pkdkb.epejabat.net/install` dan masukkan token setup.
2. Installer akan bermula pada langkah penciptaan Super Admin kerana schema telah diimport.
3. Cipta Super Admin dan organisasi awal, sahkan kata laluan, kemudian pilih **Selesai & kunci installer**.
4. Pastikan `storage/installed.lock` wujud dan `config/setup.php` telah dibuang oleh installer.
5. Uji `/`, `/login`, `/eforms`, `/public-program.php`, aset CSS/JS, muat naik portal Admin, dan respons mudah alih. HTTP mesti dilencongkan ke HTTPS; akses terus ke `/config`, `/database`, `/storage` dan fail `.env` mesti menerima 403.

## 4. Kemas kini pemasangan live sedia ada

Jangan import `epejabat_clean_install.sql` pada live sedia ada. Ambil backup penuh database, `storage/`, `.env`, `config/app.php` dan `config/core.key`; muat naik kod baharu sambil mengekalkan fail rahsia tersebut; gantikan `.htaccess` dengan versi produksi; kemudian jalankan aliran `/upgrade` sebagai Super Admin. Semak pelan/checksum migrasi sebelum Apply dan gunakan skrin migrasi e-Forms untuk migrasi Phase 2 yang masih pending.

Rollback kod dibuat dengan mengembalikan release ZIP sebelumnya. Rollback schema/data mesti menggunakan backup database yang diambil sebelum upgrade; jangan padam jadual secara manual.

## 5. Pemeriksaan keselamatan selepas deployment

```bash
curl -I http://pkdkb.epejabat.net/
curl -I https://pkdkb.epejabat.net/
curl -I https://pkdkb.epejabat.net/.env
curl -I https://pkdkb.epejabat.net/config/app.php
```

Jangkaan: HTTP memberi 308 ke HTTPS; HTTPS mengandungi HSTS, CSP, `X-Content-Type-Options`, `X-Frame-Options` dan `Referrer-Policy`; fail rahsia memberi 403. Selepas semuanya lulus, padam ZIP daripada Document Root dan simpan backup/checksum di lokasi bukan awam.
