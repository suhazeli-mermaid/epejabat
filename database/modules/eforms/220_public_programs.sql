CREATE TABLE public_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organisation_id BIGINT UNSIGNED NOT NULL,
 public_code CHAR(32) NOT NULL UNIQUE, title VARCHAR(190) NOT NULL, location VARCHAR(255) NOT NULL,
 event_date DATE NOT NULL, opens_at DATETIME NOT NULL, closes_at DATETIME NOT NULL,
 quota INT UNSIGNED NOT NULL, min_age INT UNSIGNED NOT NULL, max_age INT UNSIGNED NOT NULL,
 is_open TINYINT NOT NULL DEFAULT 0, waiting_enabled TINYINT NOT NULL DEFAULT 1,
 version_id BIGINT UNSIGNED NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(organisation_id) REFERENCES organisations(id), FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(version_id) REFERENCES eforms_versions(id), INDEX(organisation_id,is_open),
 CHECK(min_age<=max_age), CHECK(opens_at<closes_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE public_guardians (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, program_id BIGINT UNSIGNED NOT NULL,
 contact_cipher LONGTEXT NOT NULL, access_hash CHAR(64) NOT NULL UNIQUE, access_expires DATETIME NOT NULL,
 request_hash CHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(program_id) REFERENCES public_programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE public_participants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, participant_code CHAR(24) NOT NULL UNIQUE,
 program_id BIGINT UNSIGNED NOT NULL, guardian_id BIGINT UNSIGNED NOT NULL,
 identity_hash CHAR(64) NOT NULL, profile_cipher LONGTEXT NOT NULL,
 registration_status VARCHAR(20) NOT NULL, clinical_status VARCHAR(20) NOT NULL DEFAULT 'NOT_ASSESSED',
 payment_status VARCHAR(20) NOT NULL DEFAULT 'NOT_ASSESSED', place_status VARCHAR(20) NOT NULL DEFAULT 'UNCONFIRMED',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(program_id) REFERENCES public_programs(id), FOREIGN KEY(guardian_id) REFERENCES public_guardians(id),
 UNIQUE(program_id,identity_hash), INDEX(program_id,registration_status,id),
 CHECK(registration_status IN ('RECEIVED','WAITLIST','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE public_program_attempts (
 bucket_hash CHAR(64) PRIMARY KEY, attempts INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
