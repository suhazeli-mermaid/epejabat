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

## 3. Deployment automatik melalui cPanel Terminal (disyorkan)

Skrip `bin/cpanel-deploy.php` hanya boleh dijalankan melalui CLI. Ia memerlukan arahan dijalankan terus dari Document Root domain akar, mengambil snapshot penuh database dan fail runtime yang akan berubah, mengaktifkan konfigurasi produksi, menjalankan migrasi secara berurutan, kemudian menuntut kadar lulus regresi 100%. Kata laluan database dibaca daripada `.env` dan tidak boleh diberi sebagai argumen CLI.

### A. Persediaan satu kali

```bash
cd /home/CPANEL_USER/pkdkb.epejabat.net
cp .env.production.example .env
chmod 600 .env
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
```

Masukkan nilai rawak terakhir sebagai `APP_INSTANCE`, kemudian lengkapkan `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` dan `DB_PASSWORD` dalam `.env`. Pastikan database untuk mode `fresh` benar-benar kosong. Jangan letak projek di subfolder `epejabat`.

Semak prasyarat tanpa mengubah aplikasi:

```bash
php bin/cpanel-deploy.php preflight
```

Semua semakan mesti `PASS`, termasuk PHP 8.2+, `pdo_mysql`, GD, cURL, OpenSSL, Fileinfo, Mbstring, ZIP, direktori `storage/`, `mysql`, `mysqldump`, MySQL 8.0.16+ atau MariaDB 10.6+, serta konfigurasi domain akar.

### B. Pemasangan baharu (`fresh`)

```bash
export EPEJABAT_ADMIN_NAME='Nama Pentadbir'
export EPEJABAT_ADMIN_USERNAME='superadmin'
export EPEJABAT_ADMIN_EMAIL='admin@example.gov.my'
export EPEJABAT_ORG_CODE='PKDKB'
export EPEJABAT_ORG_NAME='Pejabat Kesihatan Daerah Kota Bharu'
php bin/cpanel-deploy.php deploy --mode=fresh --confirm=pkdkb.epejabat.net
unset EPEJABAT_ADMIN_NAME EPEJABAT_ADMIN_USERNAME EPEJABAT_ADMIN_EMAIL EPEJABAT_ORG_CODE EPEJABAT_ORG_NAME
```

Skrip meminta kata laluan Super Admin secara tersembunyi. Untuk terminal bukan interaktif sahaja, bekalkan `EPEJABAT_ADMIN_PASSWORD` melalui environment dan padamkannya sebaik proses selesai; jangan masukkannya sebagai argumen atau commit ke Git.

Urutan migrasi ialah Phase 0, Phase 1 Shared Core, Training T1–T6, kemudian Phase 2 e-Forms & Public Programs. Selepas semakan skema/RBAC awal lulus, skrip menjana `config/core.key`, menetapkan `installation_status=complete`, menghasilkan `storage/installed.lock`, membuka maintenance, dan menjalankan ujian HTTP serta matriks 403.

### C. Kemas kini pemasangan sedia ada (`upgrade`)

```bash
php bin/cpanel-deploy.php deploy --mode=upgrade --actor=ID_SUPER_ADMIN --confirm=pkdkb.epejabat.net
```

`--actor` boleh ditinggalkan hanya apabila tepat satu Super Admin aktif wujud. Mode ini mengekalkan `core.key`, menjalankan API migrasi rasmi secara idempoten, dan menolak database yang belum mempunyai pemasangan lengkap atau `APP_INSTANCE` yang sepadan.

### D. Regresi dan laporan

Suite regresi boleh dijalankan berasingan tanpa migrasi:

```bash
php bin/cpanel-deploy.php regression --origin=https://pkdkb.epejabat.net --actor=ID_SUPER_ADMIN
```

Ia menguji front controller, login, Public Programs, e-Forms, redirect 303, laluan terlindung, penolakan 403 untuk awam dan pengguna biasa, ledger/checksum migrasi, RBAC, serta integriti skema e-Forms/Public Programs. Fixture pengguna biasa dicipta sementara dan dibersihkan semula.

Setiap deployment menyimpan:

- snapshot SQL dan `.sha256` dalam `storage/backups/`;
- log terperinci dalam `storage/logs/cpanel-deploy-<run-id>.log`;
- snapshot fail, laporan JSON rasmi dan checksum SHA-256 dalam `storage/deployments/<run-id>/`;
- laporan terakhir dalam `storage/deployments/latest-report.json`.

Jika mana-mana migrasi, finalization atau ujian gagal, skrip mengaktifkan maintenance, memulihkan keseluruhan database daripada snapshot, dan memulihkan fail `.env`, `.htaccess`, `.user.ini`, `config/app.php`, `core.key`, `setup.php` serta `installed.lock`. Jika rollback itu sendiri gagal, `storage/upgrades/blocked.lock` dikekalkan dan aplikasi mesti diperiksa secara manual sebelum trafik dibuka.

## 4. Deployment baharu manual melalui cPanel

### A. Sediakan domain dan fail

1. Dalam cPanel, tetapkan Document Root subdomain `pkdkb.epejabat.net` kepada direktori yang akan mengandungi `index.php`, contohnya `/home/CPANEL_USER/pkdkb.epejabat.net`. Jangan ekstrak ke satu lagi subfolder `epejabat`.
2. Buka File Manager, muat naik ZIP daripada `deployment/packages/`, kemudian Extract terus ke Document Root.
3. Pakej binaan sudah mengaktifkan kandungan `.htaccess.production` sebagai `.htaccess` dan `.user.ini.production` sebagai `.user.ini`. Jika memuat naik fail Git secara manual, salin kedua-dua templat produksi itu ke nama aktif dan gantikan versi XAMPP.
4. Pastikan `storage/logs`, `storage/files`, `storage/sessions`, `storage/documents`, `storage/imports`, `storage/backups`, `storage/uploads`, `storage/upgrades`, `storage/deployments`, `storage/eforms-originals` dan `storage/eforms-packages` wujud serta boleh ditulis oleh proses PHP. Gunakan permission paling ketat yang berfungsi; lazimnya direktori `0750` dan fail rahsia `0600`.

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

## 5. Kemas kini pemasangan live sedia ada secara manual

Jangan import `epejabat_clean_install.sql` pada live sedia ada. Ambil backup penuh database, `storage/`, `.env`, `config/app.php` dan `config/core.key`; muat naik kod baharu sambil mengekalkan fail rahsia tersebut; gantikan `.htaccess` dengan versi produksi; kemudian jalankan aliran `/upgrade` sebagai Super Admin. Semak pelan/checksum migrasi sebelum Apply dan gunakan skrin migrasi e-Forms untuk migrasi Phase 2 yang masih pending.

Rollback kod dibuat dengan mengembalikan release ZIP sebelumnya. Rollback schema/data mesti menggunakan backup database yang diambil sebelum upgrade; jangan padam jadual secara manual.

## 6. Pemeriksaan keselamatan selepas deployment

```bash
curl -I http://pkdkb.epejabat.net/
curl -I https://pkdkb.epejabat.net/
curl -I https://pkdkb.epejabat.net/.env
curl -I https://pkdkb.epejabat.net/config/app.php
```

Jangkaan: HTTP memberi 308 ke HTTPS; HTTPS mengandungi HSTS, CSP, `X-Content-Type-Options`, `X-Frame-Options` dan `Referrer-Policy`; fail rahsia memberi 403. Selepas semuanya lulus, padam ZIP daripada Document Root dan simpan backup/checksum di lokasi bukan awam.
