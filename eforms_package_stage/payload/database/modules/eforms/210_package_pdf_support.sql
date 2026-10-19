CREATE TABLE eforms_pdf_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organisation_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 storage_key CHAR(48) NOT NULL UNIQUE,
 sha256 CHAR(64) NOT NULL,
 byte_size INT UNSIGNED NOT NULL,
 original_name VARCHAR(190) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (organisation_id) REFERENCES organisations(id),
 FOREIGN KEY (created_by) REFERENCES users(id), INDEX(organisation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE eforms_visual_versions (
 version_id BIGINT UNSIGNED PRIMARY KEY,
 source_id BIGINT UNSIGNED NULL,
 definition_json LONGTEXT NOT NULL,
 checksum CHAR(64) NOT NULL,
 FOREIGN KEY(version_id) REFERENCES eforms_versions(id),
 FOREIGN KEY(source_id) REFERENCES eforms_pdf_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE eforms_delegations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 submission_id BIGINT UNSIGNED NOT NULL,
 step_index INT UNSIGNED NOT NULL,
 delegated_to BIGINT UNSIGNED NOT NULL,
 delegated_by BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(submission_id) REFERENCES eforms_submissions(id),
 FOREIGN KEY(delegated_to) REFERENCES users(id),
 FOREIGN KEY(delegated_by) REFERENCES users(id), INDEX(submission_id,step_index,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE eforms_print_snapshots (
 submission_id BIGINT UNSIGNED PRIMARY KEY,
 data_json TEXT NOT NULL,
 FOREIGN KEY(submission_id) REFERENCES eforms_submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
