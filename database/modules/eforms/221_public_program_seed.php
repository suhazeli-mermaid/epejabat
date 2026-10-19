<?php
declare(strict_types=1);
return static function(PDO $p):void {
 $q=fn($s,$a=[])=>\EPejabat\Core\Database::query($p,$s,$a);
 $q("INSERT INTO module_registry(module_key,name,status,navigation_path,permission_namespace) VALUES('public_programs','Program Awam','active','/public-program.php?admin=1','public_programs')");
 foreach(['manage','participants.view','participants.export'] as $a)$q('INSERT INTO permissions(code,module_code,name) VALUES(?,?,?)',['public_programs.'.$a,'public_programs','Program Awam '.$a]);
 $q("INSERT INTO notification_templates(template_key,subject,body) VALUES('public_program_receipt','Permohonan program diterima','Permohonan diterima: {{received}} anak dalam kuota permohonan, {{waiting}} anak dalam senarai menunggu. Ini bukan jaminan tempat, kelulusan klinikal atau subsidi. Simpan pautan status sulit: {{url}}. Pautan tamat: {{expiry}} UTC.')");
 $q("INSERT INTO notification_templates(template_key,subject,body) VALUES('public_program_update','Status permohonan program dikemaskini','ID Peserta {{participant}} kini dalam kuota permohonan. Ini bukan jaminan tempat atau kelulusan klinikal. Gunakan pautan status sulit dalam e-mel penerimaan asal; hubungi urus setia jika pautan tamat.')");
 $q("INSERT INTO system_settings(setting_key,setting_value) VALUES('module_version.public_programs','0.1.0-rc1')");
};
