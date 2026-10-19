<?php
declare(strict_types=1);
// Phase1D release consolidation: one existing allowlisted, versioned migration.
// Original three development migrations were never applied on this pinned production baseline.
return static function(PDO $pdo):void {
    $db=EPejabat\Core\Database::class;
    $schemaSql=<<<'PHASE1D_SCHEMA'
ALTER TABLE registration_requests
 ADD email_type VARCHAR(16) NOT NULL DEFAULT 'legacy',
 ADD email_verified_at DATETIME NULL,
 ADD position_id BIGINT UNSIGNED NULL,
 ADD grade_id BIGINT UNSIGNED NULL,
 ADD mobile_phone VARCHAR(30) NULL,
 ADD requested_by BIGINT UNSIGNED NULL,
 ADD target_user_id BIGINT UNSIGNED NULL,
 ADD existing_staff_identity_id BIGINT UNSIGNED NULL,
 ADD CONSTRAINT fk_reg_v12_existing_staff FOREIGN KEY(existing_staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT,
 ADD request_kind VARCHAR(20) NOT NULL DEFAULT 'registration',
 ADD CONSTRAINT fk_reg_v12_position FOREIGN KEY(position_id) REFERENCES master_data(id) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_reg_v12_grade FOREIGN KEY(grade_id) REFERENCES master_data(id) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_reg_v12_actor FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
 ADD CONSTRAINT fk_reg_v12_target FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 ADD INDEX idx_reg_v12_queue(organisation_id,status,email_verified_at),
 ADD INDEX idx_reg_v12_email(email,status);
CREATE TABLE staff_contact_preferences (
 staff_identity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 alternative_phone VARCHAR(30) NULL,
 whatsapp_number VARCHAR(30) NULL,
 whatsapp_consent TINYINT NOT NULL DEFAULT 0,
 visibility VARCHAR(16) NOT NULL DEFAULT 'private',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE master_scheme_grades (
 position_id BIGINT UNSIGNED NOT NULL,
 grade_id BIGINT UNSIGNED NOT NULL,
 profession_id BIGINT UNSIGNED NOT NULL,
 source_reference VARCHAR(1000) NOT NULL,
 verified_at DATE NOT NULL,
 effective_from DATE NULL,
 is_active TINYINT NOT NULL DEFAULT 1,
 PRIMARY KEY(position_id,grade_id,profession_id),
 FOREIGN KEY(position_id) REFERENCES master_data(id) ON DELETE RESTRICT,
 FOREIGN KEY(grade_id) REFERENCES master_data(id) ON DELETE RESTRICT,
 FOREIGN KEY(profession_id) REFERENCES master_data(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE organisation_directory_details (
 organisation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 postcode VARCHAR(10) NULL,
 district VARCHAR(100) NULL,
 official_code VARCHAR(100) NULL,
 direct_phone VARCHAR(30) NULL,
 extension VARCHAR(10) NULL,
 official_whatsapp VARCHAR(30) NULL,
 source_reference VARCHAR(1000) NULL,
 source_date DATE NULL,
 verified_at DATE NULL,
 verification_status VARCHAR(24) NOT NULL DEFAULT 'NEEDS_ADMIN_VERIFICATION',
 FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE organisation_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 parent_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 proposed_name VARCHAR(190) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',
 reviewed_by BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_org_req_scope(parent_id,status),
 FOREIGN KEY(parent_id) REFERENCES organisations(id) ON DELETE RESTRICT,
 FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE staff_email_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 staff_identity_id BIGINT UNSIGNED NOT NULL,
 old_email VARCHAR(190) NULL,
 new_email VARCHAR(190) NOT NULL,
 email_type VARCHAR(16) NOT NULL,
 changed_by BIGINT UNSIGNED NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_staff_email_history(staff_identity_id,changed_at),
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT,
 FOREIGN KEY(changed_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO system_settings(setting_key,setting_value)
SELECT 'registration_default_pkd',CAST(o.id AS CHAR) FROM organisations o
WHERE o.type='pkd' AND o.is_active=1 AND o.name IN ('PKD Kota Bharu','Pejabat Kesihatan Daerah Kota Bharu')
AND NOT EXISTS(SELECT 1 FROM system_settings s WHERE s.setting_key='registration_default_pkd')
ORDER BY o.id LIMIT 1;
PHASE1D_SCHEMA;
    $metadataSql=<<<'PHASE1D_METADATA'
ALTER TABLE master_scheme_grades
 ADD scheme_code VARCHAR(20) NULL,
 ADD ssm_grades_json TEXT NULL,
 ADD source_page SMALLINT UNSIGNED NULL;
ALTER TABLE organisation_directory_details
 ADD facility_kind VARCHAR(40) NULL,
 ADD phone_source_text VARCHAR(250) NULL,
 ADD phone_validation VARCHAR(80) NULL;
PHASE1D_METADATA;
    if(!EPejabat\Core\SqlPolicy::safe($schemaSql)||!EPejabat\Core\SqlPolicy::safe($metadataSql))throw new RuntimeException('Phase1D SQL policy failed.');
    // Prevalidate all table/column additions and the legacy status policy before any DDL.
    foreach(EPejabat\Core\SqlPolicy::tables($schemaSql) as $table)if($db::query($pdo,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn())throw new RuntimeException('Untracked Phase1D table: '.$table);
    preg_match_all('/ALTER TABLE ([a-z_]+)([^;]+);/i',$schemaSql,$alterations,PREG_SET_ORDER);
    foreach($alterations as $a){if(!$db::query($pdo,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$a[1]])->fetchColumn())throw new RuntimeException('Missing Phase1D alteration target: '.$a[1]);preg_match_all('/\bADD\s+(?:COLUMN\s+)?([a-z_]+)\s+(?:BIGINT|SMALLINT|TINYINT|VARCHAR|DATETIME|INT|TEXT|LONGTEXT|CHAR)\b/i',$a[2],$cols);foreach($cols[1] as $column)if($db::query($pdo,'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$a[1],$column])->fetchColumn())throw new RuntimeException('Untracked Phase1D column: '.$a[1].'.'.$column);}

    $allowed=['PENDING_EMAIL_VERIFICATION','PENDING_VERIFICATION','APPROVED','REJECTED','REQUEST_CORRECTION'];
    $rows=$db::query($pdo,"SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='registration_requests' AND tc.CONSTRAINT_TYPE='CHECK'")->fetchAll();
    $parts=[];$maria=str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB');
    foreach($rows as $row){
        $clause=str_replace(['`',"\\'"],['',"'"],$row['CHECK_CLAUSE']);
        if(!preg_match('/\bstatus\b/i',$clause))continue;
        if(!preg_match('/\A\s*\(*\s*status\s+in\s*\(([^)]+)\)\s*\)*\s*\z/i',$clause,$m))throw new RuntimeException('Unrecognized registration status constraint: stop and reconcile.');
        preg_match_all("/'([^']+)'/",$m[1],$values);
        if(!$values[1]||array_diff($values[1],$allowed)||!preg_match('/\A[A-Za-z0-9_]+\z/',$row['CONSTRAINT_NAME']))throw new RuntimeException('Conflicting registration status policy.');
        $parts[]='DROP '.($maria?'CONSTRAINT':'CHECK').' `'.$row['CONSTRAINT_NAME'].'`';
    }
    $marks=implode(',',array_fill(0,count($allowed),'?'));
    if($db::query($pdo,'SELECT 1 FROM registration_requests WHERE status NOT IN ('.$marks.') LIMIT 1',$allowed)->fetchColumn())throw new RuntimeException('Existing registration status conflicts with policy.');
    $parts[]="ADD CONSTRAINT chk_registration_v12_status CHECK(status IN ('PENDING_EMAIL_VERIFICATION','PENDING_VERIFICATION','APPROVED','REJECTED','REQUEST_CORRECTION'))";
    // DDL can commit implicitly; on any failure, leave the ledger started and retain recovery locks.
    $pdo->exec($schemaSql);
    $pdo->exec($metadataSql);
    $pdo->exec('ALTER TABLE registration_requests '.implode(', ',$parts));

};
