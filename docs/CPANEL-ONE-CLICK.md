# One-Click Deployment cPanel

Panduan ini untuk Document Root terus `https://pkdkb.epejabat.net/`; jangan ekstrak projek ke subfolder `/epejabat`.

## Selepas pelayan dibersihkan

1. Cipta database dan pengguna MySQL melalui cPanel. Berikan keizinan database yang diperlukan.
2. Ekstrak pakej ePejabat terus ke Document Root domain.
3. Sediakan konfigurasi:

```bash
cd /home/CPANEL_USER/pkdkb.epejabat.net
cp .env.production.example .env
chmod 600 .env
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
```

4. Edit `.env`. Gantikan `APP_INSTANCE`, `DB_USER`, `DB_PASSWORD` dan sesuaikan `DB_NAME` dengan nama sebenar cPanel. Tukar `DEFAULT_ADMIN_PASS=Password1234!` kepada kata laluan unik sekurang-kurangnya 12 aksara. Nilai yang mempunyai ruang hendaklah ditulis dalam petikan, contohnya:

```dotenv
DEFAULT_ORG_NAME="Pejabat Kesihatan Daerah Kota Bharu"
```

5. Jalankan pemasangan baharu:

```bash
sh bin/cpanel-one-click.sh fresh
```

Untuk upgrade pemasangan sedia ada:

```bash
sh bin/cpanel-one-click.sh upgrade ID_SUPER_ADMIN
```

Jika cPanel menggunakan binary PHP khusus:

```bash
PHP_BIN=/opt/cpanel/ea-php82/root/usr/bin/php sh bin/cpanel-one-click.sh fresh
```

Runner akan mencipta semua subfolder `storage/` dengan permission `0755`, mengambil snapshot database/fail, mengaktifkan konfigurasi production, menjalankan `database/schema.sql` serta semua migrasi Phase 0–2, mencipta Super Admin/organisasi untuk pemasangan baharu, dan menjalankan regresi. Deployment hanya dimuktamadkan pada kadar lulus 100%.

Sekiranya `proc_open` disekat, runner mencuba `exec`, `system`, kemudian `popen`. Jika semua fungsi proses atau utiliti MySQL tidak tersedia, snapshot dan restore menggunakan PDO terus. Fallback PDO menolak view, trigger, routine, event, enjin bukan InnoDB dan kolum spatial supaya rollback tidak memberi jaminan palsu.

Laporan rasmi dan checksum berada di `storage/deployments/`; snapshot berada di `storage/backups/`. Jika deployment gagal, rollback automatik dijalankan. Jangan buang `storage/upgrades/blocked.lock` secara manual jika rollback dilaporkan gagal.
