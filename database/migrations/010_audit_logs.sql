CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL,
 organisation_id BIGINT UNSIGNED NULL, event VARCHAR(100) NOT NULL, subject VARCHAR(190) NULL,
 ip_hash CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (organisation_id) REFERENCES organisations(id),
 INDEX (created_at), INDEX (user_id,created_at), INDEX (organisation_id,created_at), INDEX (event,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
