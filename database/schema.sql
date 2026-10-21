-- epejabat-schema-version: 1.0.0
-- Bootstrap idempotent untuk Upgrade Manager cPanel.
-- Skema aplikasi penuh kekal dikendalikan oleh database/migrations,
-- database/upgrades dan database/modules.
CREATE TABLE IF NOT EXISTS system_settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value TEXT NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
