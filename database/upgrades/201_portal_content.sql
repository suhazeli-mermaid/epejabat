CREATE TABLE portal_banners (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 content_type VARCHAR(20) NOT NULL,
 slot TINYINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 caption TEXT NOT NULL,
 image_path VARCHAR(500) NOT NULL DEFAULT '',
 target_url VARCHAR(500) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY portal_content_slot (content_type,slot),
 INDEX portal_content_active (content_type,is_active,slot),
 CONSTRAINT fk_portal_content_actor FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL,
 CHECK(content_type IN ('HERO','ACTIVITY','QUICK_LINK')),
 CHECK(slot BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(code,module_code,name)
VALUES('core.portal.manage','core','Urus kandungan portal utama')
ON DUPLICATE KEY UPDATE module_code=VALUES(module_code),name=VALUES(name);

INSERT INTO roles(code,name,module_code,is_system,is_active)
VALUES('portal_admin','Portal Administrator','core',1,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),module_code=VALUES(module_code),is_system=1,is_active=1;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code IN ('super_admin','portal_admin') AND p.code='core.portal.manage';

INSERT INTO portal_banners(content_type,slot,title,caption,image_path,target_url) VALUES
('HERO',1,'Kesihatan komuniti, diperkasa secara digital.','Satu pintu masuk untuk program, borang dan perkhidmatan digital PKD Kota Bharu.','/assets/images/portal/hero-default.svg','/training/portal'),
('ACTIVITY',1,'Program & latihan','Temui program terkini untuk warga kesihatan.','/assets/images/portal/activity-default.svg','/training/portal'),
('ACTIVITY',2,'Daftar program','Daftar penyertaan dan urus rekod program anda.','/assets/images/portal/activity-default.svg','/training'),
('ACTIVITY',3,'Semak status','Semak status permohonan melalui portal awam.','/assets/images/portal/activity-default.svg','/public-program.php'),
('ACTIVITY',4,'Borang digital','Akses borang dan permohonan dalam talian.','/assets/images/portal/activity-default.svg','/eforms'),
('ACTIVITY',5,'Direktori staf','Cari maklumat organisasi dan perkhidmatan staf.','/assets/images/portal/activity-default.svg','/staff'),
('ACTIVITY',6,'Akaun saya','Urus profil, keselamatan dan rekod peribadi.','/assets/images/portal/activity-default.svg','/profile'),
('QUICK_LINK',1,'Portal Awam','','','/training/portal'),
('QUICK_LINK',2,'Daftar Program','','','/training'),
('QUICK_LINK',3,'Senarai Program','','','/training'),
('QUICK_LINK',4,'Borang Digital','','','/eforms');
