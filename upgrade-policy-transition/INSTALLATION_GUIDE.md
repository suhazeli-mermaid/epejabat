# Pengaktifan polisi sekali sahaja — maksimum tiga langkah

Status: READY untuk semakan dan pengaktifan hosting terkawal. Production belum diubah dan belum diluluskan untuk deployment. Semakan langsung pada hosting tetap wajib; jika BLOCKED, jangan teruskan atau memadam lock.

Baseline: Application/Core **1.2.0**, Training **1.0.5**, e-Forms **1.3.0**. Selepas peralihan: Application/Core **1.2.1**. Selepas ZIP Phase 3A: public_programs **0.1.0**; versi Training dan e-Forms kekal.

1. Selepas kelulusan deployment, pentadbir hosting upload dan extract `epejabat_UPGRADE_POLICY_1.2.1_HOSTING_TRANSITION.zip` ke `/home/epejabat/pkdkb.epejabat.net/`. ZIP hanya mencipta folder `upgrade-policy-transition/`; ia tidak terus overwrite fail aplikasi. Login Super Admin, buka https://pkdkb.epejabat.net/upgrade-policy-transition/checker.php , masukkan Installation Key, pilih **Deployment Check**. Jika PASS, masukkan key dan pilih **Authorise Hosting Activation**. Kebenaran sah 10 minit dan sekali guna.
2. Pentadbir hosting yang diluluskan menjalankan satu arahan berikut menggunakan PHP CLI 8.2 atau lebih baharu. Tiada salinan payload, SQL manual, publisher key atau signing service diperlukan. Tool mengulangi preflight, mengambil backup SQL dan lima fail, memasang fail tetap, mengaktifkan polisi terakhir dan merekod sejarah. Tunggu **ACTIVATED**; jika BLOCKED, simpan rujukan ralat.

   `php /home/epejabat/pkdkb.epejabat.net/upgrade-policy-transition/activate.php activate`

   Ini ialah operasi pentadbiran hosting sekali sahaja yang disengajakan, bukan laluan ZIP memintas polisi sendiri. Pengguna tidak perlu melaksanakan arahan ini bagi upgrade seterusnya. Jika PHP lalai bukan 8.2, pentadbir hosting hendaklah menggunakan executable PHP 8.2 yang tersedia; tool menolak versi tidak serasi.
3. Buka **https://pkdkb.epejabat.net/upgrade**. Upload `epejabat_PHASE3A_0.1.0_OWNER_UPGRADE.zip`, masukkan Installation Key dan sahkan pemasangan selepas preflight PASS. Sistem mengendalikan backup, lapan fail, migration 220/221 dan verifikasi. Semak **VERIFIED_LOCAL_INSTALL**; ujian operasi production berasingan masih diperlukan. Jangan extract ZIP Phase 3A ke root. Simpan laporan dan backup. Selepas acceptance, pentadbir hosting boleh membuang folder peralihan dari web root; simpan ZIP asal secara selamat untuk recovery terkawal.

## Rollback

**Peralihan sahaja, sebelum sebarang upgrade bergantung padanya dipasang:** Login Super Admin pada checker, masukkan key dan pilih **Authorise Hosting Rollback**. Dalam 10 minit, pentadbir hosting menjalankan:

`php /home/epejabat/pkdkb.epejabat.net/upgrade-policy-transition/activate.php rollback`

Rollback memeriksa hash semasa/backup, mengekalkan audit, memulihkan lima fail dan versi 1.2.0 serta membuang marker polisi. Ia menolak drift, lock daripada deployment lain, pemasangan baharu yang bergantung pada polisi, atau migration Phase 3A yang sudah applied. Ia tidak mereset akaun, key atau database. Jika peralihan pernah di-rollback, jangan padam state untuk cuba semula; perlukan semakan recovery pentadbiran.

**Selepas Phase 3A atau kegagalan migration:** Jangan gunakan rollback polisi. Hentikan writes dan gunakan backup SQL/files daripada run berkaitan melalui recovery pentadbiran yang disemak. Restore dahulu ke database terasing dan sahkan sebelum sebarang restore production diluluskan. DDL MySQL/MariaDB tidak dijamin rollback transaksi. Jangan padam `blocked.lock`/`maintenance.lock` untuk memaksa retry.

Backup tersimpan dalam `storage/backups/`; state dan backup fail peralihan dalam `storage/upgrades/policy-transition/`; backup fail Phase 3A dalam direktori run Upgrade Manager. Tiada credentials dibekalkan dalam ZIP.
