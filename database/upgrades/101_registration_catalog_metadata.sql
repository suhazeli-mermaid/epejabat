-- Reserved, previously absent allowlisted migration slot. Requires release 1.1.2 and applied 102 registration migration.
-- No destructive SQL; no automatic staff/organisation import or rename.
CREATE TABLE staff_profile_extensions (
 staff_identity_id BIGINT UNSIGNED PRIMARY KEY,
 residential_cipher LONGTEXT NULL,
 workplace_json TEXT NULL,
 professional_json TEXT NULL,
 professional_verified_json TEXT NULL,
 professional_status VARCHAR(30) NOT NULL DEFAULT 'SELF_DECLARED',
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(professional_status IN ('SELF_DECLARED','PENDING_VERIFICATION','VERIFIED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE staff_profile_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_identity_id BIGINT UNSIGNED NOT NULL,
 organisation_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 proposed_json TEXT NOT NULL,
 evidence_document_id BIGINT UNSIGNED NULL,
 evidence_version INT UNSIGNED NOT NULL,
 evidence_checksum CHAR(64) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',
 reviewed_by BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT,
 FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE RESTRICT,
 FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(evidence_document_id) REFERENCES documents(id) ON DELETE RESTRICT,
 FOREIGN KEY(evidence_document_id,evidence_version) REFERENCES document_versions(document_id,version) ON DELETE RESTRICT,
 INDEX(organisation_id,status), UNIQUE(staff_identity_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE professional_master_catalog (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 category VARCHAR(30) NOT NULL,
 code VARCHAR(64) NOT NULL,
 name VARCHAR(190) NOT NULL,
 profession_id BIGINT UNSIGNED NULL,
 parent_id BIGINT UNSIGNED NULL,
 source_reference VARCHAR(1000) NOT NULL,
 verification_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
 is_active TINYINT NOT NULL DEFAULT 0,
 verified_by BIGINT UNSIGNED NULL,
 verified_at DATETIME NULL,
 UNIQUE(category,code), INDEX(category,profession_id,parent_id,is_active),
 FOREIGN KEY(profession_id) REFERENCES master_data(id) ON DELETE RESTRICT,
 FOREIGN KEY(parent_id) REFERENCES professional_master_catalog(id) ON DELETE RESTRICT,
 FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(category IN ('speciality','subspeciality','administrative_position','top_grade','unit_template')),
 CHECK(verification_status IN ('PENDING','VERIFIED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE staff_email_contacts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_identity_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NOT NULL,
 email_type VARCHAR(16) NOT NULL,
 token_hash CHAR(64) NULL,
 expires_at DATETIME NULL,
 verified_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'PENDING_EMAIL_VERIFICATION',
 is_notification_primary TINYINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(email), INDEX(staff_identity_id,status),
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id) ON DELETE RESTRICT,
 FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(email_type IN ('official','personal')),
 CHECK(status IN ('PENDING_EMAIL_VERIFICATION','PENDING_ADMIN_APPROVAL','APPROVED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE organisation_source_reviews (
 local_code VARCHAR(64) PRIMARY KEY,
 parent_id BIGINT UNSIGNED NOT NULL,
 source_reference VARCHAR(1000) NOT NULL,
 verification_status VARCHAR(20) NOT NULL DEFAULT 'HOLD',
 reviewed_by BIGINT UNSIGNED NOT NULL,
 reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reason VARCHAR(500) NOT NULL,
 FOREIGN KEY(parent_id) REFERENCES organisations(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(verification_status IN ('VERIFIED','PARTIAL','HOLD','INCOMPLETE','INVALID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE organisation_search_aliases (
 organisation_id BIGINT UNSIGNED NOT NULL,
 alias VARCHAR(190) NOT NULL,
 PRIMARY KEY(organisation_id,alias),
 FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO permissions(code,module_code,name) VALUES ('core.staff.professional.verify','core','Verify scoped professional profile'),('core.staff.private_address','core','Explicit private residential address access');
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='core.staff.professional.verify' WHERE r.module_code='core' AND r.code IN ('super_admin','pkd_admin','clinic_admin','unit_admin');
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='core.staff.private_address' WHERE r.module_code='core' AND r.code='super_admin';

ALTER TABLE organisation_directory_details ADD city VARCHAR(190) NULL, ADD street VARCHAR(190) NULL, ADD address_state VARCHAR(190) NULL, ADD address_verified_by BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_directory_address_verifier FOREIGN KEY(address_verified_by) REFERENCES users(id) ON DELETE RESTRICT;
