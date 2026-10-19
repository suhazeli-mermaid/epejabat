CREATE TABLE staff_profiles (
 staff_identity_id BIGINT UNSIGNED PRIMARY KEY,
 full_name VARCHAR(190) NOT NULL, organisation_id BIGINT UNSIGNED NULL,
 phone VARCHAR(30) NULL, job_title VARCHAR(190) NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (staff_identity_id) REFERENCES staff_identities(id),
 FOREIGN KEY (organisation_id) REFERENCES organisations(id), INDEX (organisation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
