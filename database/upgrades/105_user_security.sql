ALTER TABLE users ADD email_verified_at DATETIME NULL, ADD credential_revision INT UNSIGNED NOT NULL DEFAULT 0;
