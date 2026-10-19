CREATE TABLE eforms_package_stages (
 token CHAR(48) PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 organisation_id BIGINT UNSIGNED NOT NULL,
 package_sha256 CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_template_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(actor_id) REFERENCES users(id),
 FOREIGN KEY(organisation_id) REFERENCES organisations(id),
 FOREIGN KEY(consumed_template_id) REFERENCES eforms_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE eforms_package_forms (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organisation_id BIGINT UNSIGNED NOT NULL,
 form_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 template_id BIGINT UNSIGNED NULL,
 UNIQUE KEY package_identity(organisation_id,form_key),
 FOREIGN KEY(organisation_id) REFERENCES organisations(id),
 FOREIGN KEY(template_id) REFERENCES eforms_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE eforms_package_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 package_form_id BIGINT UNSIGNED NOT NULL,
 package_revision INT UNSIGNED NOT NULL,
 version_id BIGINT UNSIGNED NOT NULL,
 package_sha256 CHAR(64) NOT NULL,
 description TEXT NOT NULL,
 imported_by BIGINT UNSIGNED NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY package_revision(package_form_id,package_revision),
 FOREIGN KEY(package_form_id) REFERENCES eforms_package_forms(id),
 FOREIGN KEY(version_id) REFERENCES eforms_versions(id),
 FOREIGN KEY(imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
