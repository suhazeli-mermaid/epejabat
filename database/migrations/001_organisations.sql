CREATE TABLE organisations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL,
 type VARCHAR(20) NOT NULL, parent_id BIGINT UNSIGNED NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (parent_id) REFERENCES organisations(id), INDEX (parent_id),
 CHECK (type IN ('national','state','pkd','clinic','unit'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
