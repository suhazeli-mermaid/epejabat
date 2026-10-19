CREATE TABLE training_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL,
 start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, policy_json LONGTEXT NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id), INDEX(programme_id,start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_checkpoints (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id BIGINT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL,
 opens_at DATETIME NOT NULL, closes_at DATETIME NOT NULL, FOREIGN KEY(session_id) REFERENCES training_sessions(id), INDEX(session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_attendance_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id BIGINT UNSIGNED NOT NULL, checkpoint_id BIGINT UNSIGNED NULL,
 action VARCHAR(24) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, staff_identity_id BIGINT UNSIGNED NULL,
 expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, session_binding CHAR(64) NULL, issued_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(session_id) REFERENCES training_sessions(id), FOREIGN KEY(checkpoint_id) REFERENCES training_checkpoints(id),
 FOREIGN KEY(staff_identity_id) REFERENCES staff_identities(id), FOREIGN KEY(issued_by) REFERENCES users(id), INDEX(session_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_attendance_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id BIGINT UNSIGNED NOT NULL, registration_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(24) NOT NULL, checkpoint_key BIGINT UNSIGNED NOT NULL DEFAULT 0, happened_at DATETIME NOT NULL,
 original_at DATETIME NOT NULL, source VARCHAR(24) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, reason VARCHAR(500) NULL, notes TEXT NULL,
 UNIQUE(session_id,registration_id,action,checkpoint_key), FOREIGN KEY(session_id) REFERENCES training_sessions(id),
 FOREIGN KEY(registration_id) REFERENCES training_registrations(id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(registration_id,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_attendance_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id BIGINT UNSIGNED NOT NULL, requested_by BIGINT UNSIGNED NOT NULL,
 proposed_at DATETIME NOT NULL, previous_at DATETIME NOT NULL, reason VARCHAR(500) NOT NULL, notes TEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'PENDING', reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
 FOREIGN KEY(event_id) REFERENCES training_attendance_events(id), FOREIGN KEY(requested_by) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id), INDEX(status,event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_cpd_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 config_json LONGTEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'DRAFT', created_by BIGINT UNSIGNED NOT NULL,
 verified_by BIGINT UNSIGNED NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(programme_id,version), FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id), FOREIGN KEY(verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_cpd_records (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, registration_id BIGINT UNSIGNED NOT NULL, rule_id BIGINT UNSIGNED NULL,
 version INT UNSIGNED NOT NULL, points DECIMAL(10,3) NOT NULL, breakdown_json LONGTEXT NOT NULL, snapshot_json LONGTEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'PENDING', created_by BIGINT UNSIGNED NOT NULL, approved_by BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NOT NULL, reference_text VARCHAR(1000) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(registration_id,version), FOREIGN KEY(registration_id) REFERENCES training_registrations(id), FOREIGN KEY(rule_id) REFERENCES training_cpd_rules(id),
 FOREIGN KEY(created_by) REFERENCES users(id), FOREIGN KEY(approved_by) REFERENCES users(id), INDEX(status,registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_evaluations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, kind VARCHAR(24) NOT NULL, questions_json LONGTEXT NOT NULL, required_for_certificate TINYINT NOT NULL,
 pass_percent DECIMAL(5,2) NULL, opens_at DATETIME NOT NULL, closes_at DATETIME NOT NULL, published TINYINT NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NOT NULL, UNIQUE(programme_id,version), FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_evaluation_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, evaluation_id BIGINT UNSIGNED NOT NULL, registration_id BIGINT UNSIGNED NOT NULL,
 answers_json LONGTEXT NOT NULL, score DECIMAL(5,2) NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(evaluation_id,registration_id), FOREIGN KEY(evaluation_id) REFERENCES training_evaluations(id), FOREIGN KEY(registration_id) REFERENCES training_registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE core_signatories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organisation_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL, position_title VARCHAR(190) NOT NULL, signature_document_id BIGINT UNSIGNED NOT NULL,
 signature_version INT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL, is_active TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(organisation_id) REFERENCES organisations(id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(signature_document_id,signature_version) REFERENCES document_versions(document_id,version), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(24) NOT NULL,
 type_key VARCHAR(64) NOT NULL, title VARCHAR(190) NOT NULL, body TEXT NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_outputs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, programme_id BIGINT UNSIGNED NOT NULL, registration_id BIGINT UNSIGNED NULL,
 kind VARCHAR(24) NOT NULL, role_key VARCHAR(64) NOT NULL, template_id BIGINT UNSIGNED NOT NULL, reference_number VARCHAR(190) NOT NULL,
 snapshot_json LONGTEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'DRAFT', verification_key CHAR(32) NOT NULL UNIQUE,
 document_id BIGINT UNSIGNED NULL, file_checksum CHAR(64) NULL, created_by BIGINT UNSIGNED NOT NULL, approved_by BIGINT UNSIGNED NULL,
 replaces_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_at DATETIME NULL, void_reason VARCHAR(500) NULL,
 FOREIGN KEY(programme_id) REFERENCES training_programmes(id), FOREIGN KEY(registration_id) REFERENCES training_registrations(id),
 FOREIGN KEY(template_id) REFERENCES training_templates(id), FOREIGN KEY(document_id) REFERENCES documents(id),
 FOREIGN KEY(created_by) REFERENCES users(id), FOREIGN KEY(approved_by) REFERENCES users(id), FOREIGN KEY(replaces_id) REFERENCES training_outputs(id), INDEX(programme_id,kind,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_output_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, output_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 previous_status VARCHAR(24) NOT NULL, new_status VARCHAR(24) NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(output_id) REFERENCES training_outputs(id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(output_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE training_acknowledgements (
 registration_id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(24) NOT NULL, reason VARCHAR(500) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(registration_id) REFERENCES training_registrations(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE core_content (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(64) NOT NULL, organisation_id BIGINT UNSIGNED NOT NULL,
 type_key VARCHAR(24) NOT NULL, title VARCHAR(190) NOT NULL, body TEXT NOT NULL, cta_url VARCHAR(500) NULL,
 document_id BIGINT UNSIGNED NULL, document_version INT UNSIGNED NULL, status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
 starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, sort_order INT NOT NULL DEFAULT 0, created_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(organisation_id) REFERENCES organisations(id), FOREIGN KEY(document_id,document_version) REFERENCES document_versions(document_id,version),
 FOREIGN KEY(created_by) REFERENCES users(id), INDEX(module_code,status,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
