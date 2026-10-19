CREATE TABLE upgrade_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 run_id VARCHAR(64) NOT NULL UNIQUE,
 module_key VARCHAR(32) NOT NULL,
 previous_version VARCHAR(32) NULL,
 target_version VARCHAR(32) NOT NULL,
 status VARCHAR(40) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 INDEX upgrade_module_date(module_key,created_at),
 CONSTRAINT fk_upgrade_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
