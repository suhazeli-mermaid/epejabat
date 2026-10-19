CREATE TABLE user_role_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL, organisation_id BIGINT UNSIGNED NULL,
 scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(organisation_id,0)) STORED,
 include_descendants TINYINT(1) NOT NULL DEFAULT 0,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (role_id) REFERENCES roles(id), FOREIGN KEY (organisation_id) REFERENCES organisations(id),
 UNIQUE KEY assignment_unique (user_id,role_id,scope_key), INDEX (organisation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
