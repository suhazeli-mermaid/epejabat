CREATE TABLE training_programmes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_key CHAR(32) NOT NULL UNIQUE,
 owner_organisation_id BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'DRAFT', published_revision_id BIGINT UNSIGNED NULL,
 current_revision_id BIGINT UNSIGNED NULL, approved_revision_id BIGINT UNSIGNED NULL,
 is_published TINYINT NOT NULL DEFAULT 0, is_cancelled TINYINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(owner_organisation_id) REFERENCES organisations(id), FOREIGN KEY(created_by) REFERENCES users(id),
 INDEX(owner_organisation_id,status), CHECK(status IN ('DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL, config_json LONGTEXT NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(programme_id,revision_no), UNIQUE(programme_id,id),
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE training_programmes ADD FOREIGN KEY(id,current_revision_id) REFERENCES training_revisions(programme_id,id), ADD FOREIGN KEY(id,approved_revision_id) REFERENCES training_revisions(programme_id,id), ADD FOREIGN KEY(id,published_revision_id) REFERENCES training_revisions(programme_id,id);
CREATE TABLE training_approvals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, revision_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, decision VARCHAR(24) NOT NULL, reason VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(programme_id,revision_id) REFERENCES training_revisions(programme_id,id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(programme_id,revision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_access_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL, assigned_by BIGINT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL, reason VARCHAR(500) NOT NULL, UNIQUE(programme_id,user_id,role_id),
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(role_id) REFERENCES roles(id), FOREIGN KEY(assigned_by) REFERENCES users(id), INDEX(user_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_registrations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, staff_identity_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL, source VARCHAR(24) NOT NULL, wait_sequence BIGINT UNSIGNED NULL,
 snapshot_json LONGTEXT NOT NULL, eligibility_json LONGTEXT NOT NULL, assigned_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(programme_id,staff_identity_id), FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id), FOREIGN KEY(assigned_by) REFERENCES users(id),
 INDEX(programme_id,status,wait_sequence), INDEX(staff_identity_id,status), CHECK(status IN ('PENDING','CONFIRMED','WAITLISTED','WITHDRAWN','REJECTED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_registration_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, registration_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NULL,
 old_status VARCHAR(24) NULL, new_status VARCHAR(24) NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(registration_id) REFERENCES training_registrations(id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(registration_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_participation_roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL,
 is_active TINYINT NOT NULL DEFAULT 1, UNIQUE(programme_id,code), FOREIGN KEY(programme_id) REFERENCES training_programmes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_participation_assignments (
 registration_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, assigned_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(registration_id,role_id),
 FOREIGN KEY(registration_id) REFERENCES training_registrations(id), FOREIGN KEY(role_id) REFERENCES training_participation_roles(id), FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, staff_identity_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, issued_by BIGINT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id), FOREIGN KEY(issued_by) REFERENCES users(id), INDEX(programme_id,staff_identity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, public_key CHAR(32) NOT NULL UNIQUE,
 created_by BIGINT UNSIGNED NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id), INDEX(programme_id,revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_programme_documents (
 document_id BIGINT UNSIGNED PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, purpose VARCHAR(100) NOT NULL,
 FOREIGN KEY(document_id) REFERENCES documents(id), FOREIGN KEY(programme_id) REFERENCES training_programmes(id), INDEX(programme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_notification_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, recipient_user_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) NOT NULL UNIQUE, payload_cipher LONGTEXT NOT NULL, delivery_id BIGINT UNSIGNED NULL,
 processed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(recipient_user_id) REFERENCES users(id), FOREIGN KEY(delivery_id) REFERENCES notification_deliveries(id), INDEX(processed_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
