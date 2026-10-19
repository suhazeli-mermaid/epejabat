CREATE TABLE database_migrations (
 version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL, started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 applied_at TIMESTAMP NULL, CHECK (status IN ('started','applied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
