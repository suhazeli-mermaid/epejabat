-- e-Pejabat clean installation database
-- Generated only from versioned Phase 0, Phase 1, Training, e-Forms and Public Programs migrations.
-- Contains structure and non-personal seed data only. Import into an EMPTY database.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET @EPEJABAT_INSTANCE = LOWER(REPLACE(UUID(), '-', ''));

-- Table: account_tokens
CREATE TABLE `account_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `purpose` varchar(20) NOT NULL,
  `staff_identity_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `request_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `staff_identity_id` (`staff_identity_id`),
  KEY `user_id` (`user_id`),
  KEY `request_id` (`request_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `account_tokens_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `account_tokens_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `account_tokens_ibfk_3` FOREIGN KEY (`request_id`) REFERENCES `registration_requests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: audit_logs
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `organisation_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(100) NOT NULL,
  `subject` varchar(190) DEFAULT NULL,
  `ip_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `module_code` varchar(64) NOT NULL DEFAULT 'core',
  `reason` varchar(500) DEFAULT NULL,
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `integrity_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `user_id` (`user_id`,`created_at`),
  KEY `organisation_id` (`organisation_id`,`created_at`),
  KEY `event` (`event`,`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: core_content
CREATE TABLE `core_content` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_code` varchar(64) NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `type_key` varchar(24) NOT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `cta_url` varchar(500) DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `document_version` int(10) unsigned DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'DRAFT',
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `organisation_id` (`organisation_id`),
  KEY `document_id` (`document_id`,`document_version`),
  KEY `created_by` (`created_by`),
  KEY `module_code` (`module_code`,`status`,`starts_at`,`ends_at`),
  CONSTRAINT `core_content_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `core_content_ibfk_2` FOREIGN KEY (`document_id`, `document_version`) REFERENCES `document_versions` (`document_id`, `version`),
  CONSTRAINT `core_content_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: core_signatories
CREATE TABLE `core_signatories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `position_title` varchar(190) NOT NULL,
  `signature_document_id` bigint(20) unsigned NOT NULL,
  `signature_version` int(10) unsigned NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `organisation_id` (`organisation_id`),
  KEY `user_id` (`user_id`),
  KEY `signature_document_id` (`signature_document_id`,`signature_version`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `core_signatories_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `core_signatories_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `core_signatories_ibfk_3` FOREIGN KEY (`signature_document_id`, `signature_version`) REFERENCES `document_versions` (`document_id`, `version`),
  CONSTRAINT `core_signatories_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: database_migrations
CREATE TABLE `database_migrations` (
  `version` varchar(100) NOT NULL,
  `checksum` char(64) NOT NULL,
  `status` varchar(20) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `applied_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`version`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('started','applied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: documents
CREATE TABLE `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` bigint(20) unsigned NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `module_code` varchar(64) NOT NULL,
  `title` varchar(190) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `organisation_id` (`organisation_id`,`module_code`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('active','locked','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: document_versions
CREATE TABLE `document_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `storage_key` char(64) NOT NULL,
  `original_name` varchar(190) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `checksum` char(64) NOT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `storage_key` (`storage_key`),
  UNIQUE KEY `document_id` (`document_id`,`version`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `document_versions_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `document_versions_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: eforms_attachments
CREATE TABLE `eforms_attachments` (
  `submission_id` bigint(20) unsigned NOT NULL,
  `field_key` varchar(40) NOT NULL,
  `document_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`submission_id`,`field_key`),
  UNIQUE KEY `document_id` (`document_id`),
  CONSTRAINT `eforms_attachments_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eforms_submissions` (`id`),
  CONSTRAINT `eforms_attachments_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_delegations
CREATE TABLE `eforms_delegations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `step_index` int(10) unsigned NOT NULL,
  `delegated_to` bigint(20) unsigned NOT NULL,
  `delegated_by` bigint(20) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `delegated_to` (`delegated_to`),
  KEY `delegated_by` (`delegated_by`),
  KEY `submission_id` (`submission_id`,`step_index`,`id`),
  CONSTRAINT `eforms_delegations_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eforms_submissions` (`id`),
  CONSTRAINT `eforms_delegations_ibfk_2` FOREIGN KEY (`delegated_to`) REFERENCES `users` (`id`),
  CONSTRAINT `eforms_delegations_ibfk_3` FOREIGN KEY (`delegated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_history
CREATE TABLE `eforms_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  KEY `submission_id` (`submission_id`,`id`),
  CONSTRAINT `eforms_history_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eforms_submissions` (`id`),
  CONSTRAINT `eforms_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_package_forms
CREATE TABLE `eforms_package_forms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `form_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `package_identity` (`organisation_id`,`form_key`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `eforms_package_forms_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `eforms_package_forms_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `eforms_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_package_imports
CREATE TABLE `eforms_package_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `package_form_id` bigint(20) unsigned NOT NULL,
  `package_revision` int(10) unsigned NOT NULL,
  `version_id` bigint(20) unsigned NOT NULL,
  `package_sha256` char(64) NOT NULL,
  `description` text NOT NULL,
  `imported_by` bigint(20) unsigned NOT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `package_revision` (`package_form_id`,`package_revision`),
  KEY `version_id` (`version_id`),
  KEY `imported_by` (`imported_by`),
  CONSTRAINT `eforms_package_imports_ibfk_1` FOREIGN KEY (`package_form_id`) REFERENCES `eforms_package_forms` (`id`),
  CONSTRAINT `eforms_package_imports_ibfk_2` FOREIGN KEY (`version_id`) REFERENCES `eforms_versions` (`id`),
  CONSTRAINT `eforms_package_imports_ibfk_3` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_package_stages
CREATE TABLE `eforms_package_stages` (
  `token` char(48) NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `package_sha256` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_template_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`),
  KEY `actor_id` (`actor_id`),
  KEY `organisation_id` (`organisation_id`),
  KEY `consumed_template_id` (`consumed_template_id`),
  CONSTRAINT `eforms_package_stages_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `eforms_package_stages_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `eforms_package_stages_ibfk_3` FOREIGN KEY (`consumed_template_id`) REFERENCES `eforms_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_pdf_sources
CREATE TABLE `eforms_pdf_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `storage_key` char(48) NOT NULL,
  `sha256` char(64) NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `original_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `storage_key` (`storage_key`),
  KEY `created_by` (`created_by`),
  KEY `organisation_id` (`organisation_id`),
  CONSTRAINT `eforms_pdf_sources_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `eforms_pdf_sources_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_print_snapshots
CREATE TABLE `eforms_print_snapshots` (
  `submission_id` bigint(20) unsigned NOT NULL,
  `data_json` text NOT NULL,
  PRIMARY KEY (`submission_id`),
  CONSTRAINT `eforms_print_snapshots_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eforms_submissions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_submissions
CREATE TABLE `eforms_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version_id` bigint(20) unsigned NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `applicant_id` bigint(20) unsigned NOT NULL,
  `payload_cipher` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `step_index` int(10) unsigned NOT NULL DEFAULT 0,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledgement_hash` char(64) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `version_id` (`version_id`),
  KEY `organisation_id` (`organisation_id`,`status`),
  KEY `applicant_id` (`applicant_id`,`status`),
  CONSTRAINT `eforms_submissions_ibfk_1` FOREIGN KEY (`version_id`) REFERENCES `eforms_versions` (`id`),
  CONSTRAINT `eforms_submissions_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `eforms_submissions_ibfk_3` FOREIGN KEY (`applicant_id`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('draft','submitted','approved','rejected','returned'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_templates
CREATE TABLE `eforms_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `organisation_id` (`organisation_id`,`status`),
  CONSTRAINT `eforms_templates_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `eforms_templates_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('draft','published','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_versions
CREATE TABLE `eforms_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `schema_json` longtext NOT NULL,
  `workflow_json` text NOT NULL,
  `allow_self_approval` tinyint(4) NOT NULL DEFAULT 0,
  `acknowledgement` text NOT NULL,
  `checksum` char(64) NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_id` (`template_id`,`version`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `eforms_versions_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `eforms_templates` (`id`),
  CONSTRAINT `eforms_versions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: eforms_visual_versions
CREATE TABLE `eforms_visual_versions` (
  `version_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `definition_json` longtext NOT NULL,
  `checksum` char(64) NOT NULL,
  PRIMARY KEY (`version_id`),
  KEY `source_id` (`source_id`),
  CONSTRAINT `eforms_visual_versions_ibfk_1` FOREIGN KEY (`version_id`) REFERENCES `eforms_versions` (`id`),
  CONSTRAINT `eforms_visual_versions_ibfk_2` FOREIGN KEY (`source_id`) REFERENCES `eforms_pdf_sources` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: employment_history
CREATE TABLE `employment_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `previous_organisation_id` bigint(20) unsigned DEFAULT NULL,
  `organisation_id` bigint(20) unsigned DEFAULT NULL,
  `position_id` bigint(20) unsigned DEFAULT NULL,
  `grade_id` bigint(20) unsigned DEFAULT NULL,
  `change_reason` varchar(500) NOT NULL,
  `changed_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `previous_organisation_id` (`previous_organisation_id`),
  KEY `organisation_id` (`organisation_id`),
  KEY `position_id` (`position_id`),
  KEY `grade_id` (`grade_id`),
  KEY `changed_by` (`changed_by`),
  KEY `staff_identity_id` (`staff_identity_id`,`effective_from`),
  CONSTRAINT `employment_history_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `employment_history_ibfk_2` FOREIGN KEY (`previous_organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `employment_history_ibfk_3` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `employment_history_ibfk_4` FOREIGN KEY (`position_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `employment_history_ibfk_5` FOREIGN KEY (`grade_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `employment_history_ibfk_6` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`effective_until` is null or `effective_from` is null or `effective_until` >= `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: login_attempts
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) NOT NULL,
  `account_hash` char(64) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ip_hash` (`ip_hash`,`attempted_at`),
  KEY `account_hash` (`account_hash`,`attempted_at`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: master_data
CREATE TABLE `master_data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(40) NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `organisation_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category` (`category`,`code`),
  KEY `parent_id` (`parent_id`),
  KEY `organisation_id` (`organisation_id`),
  CONSTRAINT `master_data_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `master_data_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: master_scheme_grades
CREATE TABLE `master_scheme_grades` (
  `position_id` bigint(20) unsigned NOT NULL,
  `grade_id` bigint(20) unsigned NOT NULL,
  `profession_id` bigint(20) unsigned NOT NULL,
  `source_reference` varchar(1000) NOT NULL,
  `verified_at` date NOT NULL,
  `effective_from` date DEFAULT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `scheme_code` varchar(20) DEFAULT NULL,
  `ssm_grades_json` text DEFAULT NULL,
  `source_page` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`position_id`,`grade_id`,`profession_id`),
  KEY `grade_id` (`grade_id`),
  KEY `profession_id` (`profession_id`),
  CONSTRAINT `master_scheme_grades_ibfk_1` FOREIGN KEY (`position_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `master_scheme_grades_ibfk_2` FOREIGN KEY (`grade_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `master_scheme_grades_ibfk_3` FOREIGN KEY (`profession_id`) REFERENCES `master_data` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: module_registry
CREATE TABLE `module_registry` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'inactive',
  `navigation_path` varchar(190) DEFAULT NULL,
  `permission_namespace` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`),
  UNIQUE KEY `permission_namespace` (`permission_namespace`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: notifications
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `module_code` varchar(64) NOT NULL DEFAULT 'core',
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`read_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: notification_deliveries
CREATE TABLE `notification_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `template_key` varchar(64) NOT NULL,
  `payload_cipher` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'QUEUED',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(100) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `template_key` (`template_key`),
  KEY `status` (`status`,`next_attempt_at`),
  CONSTRAINT `notification_deliveries_ibfk_1` FOREIGN KEY (`template_key`) REFERENCES `notification_templates` (`template_key`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('QUEUED','SENT','FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: notification_templates
CREATE TABLE `notification_templates` (
  `template_key` varchar(64) NOT NULL,
  `subject` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisations
CREATE TABLE `organisations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `type` varchar(64) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type_id` bigint(20) unsigned DEFAULT NULL,
  `state_id` bigint(20) unsigned DEFAULT NULL,
  `address` text DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `parent_id` (`parent_id`),
  KEY `type_id` (`type_id`),
  KEY `state_id` (`state_id`),
  CONSTRAINT `organisations_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `organisations_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `organisations_ibfk_3` FOREIGN KEY (`state_id`) REFERENCES `master_data` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_change_history
CREATE TABLE `organisation_change_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `before_json` longtext NOT NULL,
  `after_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `organisation_id` (`organisation_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `organisation_change_history_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `organisation_change_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_directory_details
CREATE TABLE `organisation_directory_details` (
  `organisation_id` bigint(20) unsigned NOT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `official_code` varchar(100) DEFAULT NULL,
  `direct_phone` varchar(30) DEFAULT NULL,
  `extension` varchar(10) DEFAULT NULL,
  `official_whatsapp` varchar(30) DEFAULT NULL,
  `source_reference` varchar(1000) DEFAULT NULL,
  `source_date` date DEFAULT NULL,
  `verified_at` date DEFAULT NULL,
  `verification_status` varchar(24) NOT NULL DEFAULT 'NEEDS_ADMIN_VERIFICATION',
  `facility_kind` varchar(40) DEFAULT NULL,
  `phone_source_text` varchar(250) DEFAULT NULL,
  `phone_validation` varchar(80) DEFAULT NULL,
  `city` varchar(190) DEFAULT NULL,
  `street` varchar(190) DEFAULT NULL,
  `address_state` varchar(190) DEFAULT NULL,
  `address_verified_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`organisation_id`),
  KEY `fk_directory_address_verifier` (`address_verified_by`),
  CONSTRAINT `fk_directory_address_verifier` FOREIGN KEY (`address_verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `organisation_directory_details_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_hierarchy
CREATE TABLE `organisation_hierarchy` (
  `ancestor_id` bigint(20) unsigned NOT NULL,
  `descendant_id` bigint(20) unsigned NOT NULL,
  `depth` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ancestor_id`,`descendant_id`),
  KEY `descendant_id` (`descendant_id`),
  CONSTRAINT `organisation_hierarchy_ibfk_1` FOREIGN KEY (`ancestor_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `organisation_hierarchy_ibfk_2` FOREIGN KEY (`descendant_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`ancestor_id` = `descendant_id` and `depth` = 0 or `ancestor_id` <> `descendant_id` and `depth` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_requests
CREATE TABLE `organisation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `proposed_name` varchar(190) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_req_scope` (`parent_id`,`status`),
  KEY `requested_by` (`requested_by`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `organisation_requests_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `organisation_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `organisation_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_search_aliases
CREATE TABLE `organisation_search_aliases` (
  `organisation_id` bigint(20) unsigned NOT NULL,
  `alias` varchar(190) NOT NULL,
  PRIMARY KEY (`organisation_id`,`alias`),
  CONSTRAINT `organisation_search_aliases_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: organisation_source_reviews
CREATE TABLE `organisation_source_reviews` (
  `local_code` varchar(64) NOT NULL,
  `parent_id` bigint(20) unsigned NOT NULL,
  `source_reference` varchar(1000) NOT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'HOLD',
  `reviewed_by` bigint(20) unsigned NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` varchar(500) NOT NULL,
  PRIMARY KEY (`local_code`),
  KEY `parent_id` (`parent_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `organisation_source_reviews_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `organisation_source_reviews_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`verification_status` in ('VERIFIED','PARTIAL','HOLD','INCOMPLETE','INVALID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: permissions
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `module_code` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `module_code` (`module_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: portal_banners
CREATE TABLE `portal_banners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `content_type` varchar(20) NOT NULL,
  `slot` tinyint(3) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `caption` text NOT NULL,
  `image_path` varchar(500) NOT NULL DEFAULT '',
  `target_url` varchar(500) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_content_slot` (`content_type`,`slot`),
  KEY `portal_content_active` (`content_type`,`is_active`,`slot`),
  KEY `fk_portal_content_actor` (`updated_by`),
  CONSTRAINT `fk_portal_content_actor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `CONSTRAINT_1` CHECK (`content_type` in ('HERO','ACTIVITY','QUICK_LINK')),
  CONSTRAINT `CONSTRAINT_2` CHECK (`slot` between 1 and 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: professional_credentials
CREATE TABLE `professional_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `regulator_id` bigint(20) unsigned NOT NULL,
  `registration_number` varchar(100) NOT NULL,
  `additional_credentials` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_identity_id` (`staff_identity_id`,`regulator_id`,`registration_number`),
  KEY `regulator_id` (`regulator_id`),
  CONSTRAINT `professional_credentials_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `professional_credentials_ibfk_2` FOREIGN KEY (`regulator_id`) REFERENCES `master_data` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: professional_master_catalog
CREATE TABLE `professional_master_catalog` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(30) NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `profession_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `source_reference` varchar(1000) NOT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `is_active` tinyint(4) NOT NULL DEFAULT 0,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category` (`category`,`code`),
  KEY `category_2` (`category`,`profession_id`,`parent_id`,`is_active`),
  KEY `profession_id` (`profession_id`),
  KEY `parent_id` (`parent_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `professional_master_catalog_ibfk_1` FOREIGN KEY (`profession_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `professional_master_catalog_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `professional_master_catalog` (`id`),
  CONSTRAINT `professional_master_catalog_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`category` in ('speciality','subspeciality','administrative_position','top_grade','unit_template')),
  CONSTRAINT `CONSTRAINT_2` CHECK (`verification_status` in ('PENDING','VERIFIED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: public_flow_attempts
CREATE TABLE `public_flow_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `flow_key` varchar(30) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `account_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `flow_key` (`flow_key`,`ip_hash`,`created_at`),
  KEY `flow_key_2` (`flow_key`,`account_hash`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: public_guardians
CREATE TABLE `public_guardians` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `program_id` bigint(20) unsigned NOT NULL,
  `contact_cipher` longtext NOT NULL,
  `access_hash` char(64) NOT NULL,
  `access_expires` datetime NOT NULL,
  `request_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_hash` (`access_hash`),
  UNIQUE KEY `request_hash` (`request_hash`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `public_guardians_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `public_programs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: public_participants
CREATE TABLE `public_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `participant_code` char(24) NOT NULL,
  `program_id` bigint(20) unsigned NOT NULL,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `identity_hash` char(64) NOT NULL,
  `profile_cipher` longtext NOT NULL,
  `registration_status` varchar(20) NOT NULL,
  `clinical_status` varchar(20) NOT NULL DEFAULT 'NOT_ASSESSED',
  `payment_status` varchar(20) NOT NULL DEFAULT 'NOT_ASSESSED',
  `place_status` varchar(20) NOT NULL DEFAULT 'UNCONFIRMED',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `participant_code` (`participant_code`),
  UNIQUE KEY `program_id` (`program_id`,`identity_hash`),
  KEY `guardian_id` (`guardian_id`),
  KEY `program_id_2` (`program_id`,`registration_status`,`id`),
  CONSTRAINT `public_participants_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `public_programs` (`id`),
  CONSTRAINT `public_participants_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `public_guardians` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`registration_status` in ('RECEIVED','WAITLIST','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: public_programs
CREATE TABLE `public_programs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `public_code` char(32) NOT NULL,
  `title` varchar(190) NOT NULL,
  `location` varchar(255) NOT NULL,
  `event_date` date NOT NULL,
  `opens_at` datetime NOT NULL,
  `closes_at` datetime NOT NULL,
  `quota` int(10) unsigned NOT NULL,
  `min_age` int(10) unsigned NOT NULL,
  `max_age` int(10) unsigned NOT NULL,
  `is_open` tinyint(4) NOT NULL DEFAULT 0,
  `waiting_enabled` tinyint(4) NOT NULL DEFAULT 1,
  `version_id` bigint(20) unsigned DEFAULT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_code` (`public_code`),
  KEY `created_by` (`created_by`),
  KEY `version_id` (`version_id`),
  KEY `organisation_id` (`organisation_id`,`is_open`),
  CONSTRAINT `public_programs_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `public_programs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `public_programs_ibfk_3` FOREIGN KEY (`version_id`) REFERENCES `eforms_versions` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`min_age` <= `max_age`),
  CONSTRAINT `CONSTRAINT_2` CHECK (`opens_at` < `closes_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: public_program_attempts
CREATE TABLE `public_program_attempts` (
  `bucket_hash` char(64) NOT NULL,
  `attempts` int(10) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`bucket_hash`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: registration_requests
CREATE TABLE `registration_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(190) NOT NULL,
  `email` varchar(190) NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `profession_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',
  `reviewer_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_type` varchar(16) NOT NULL DEFAULT 'legacy',
  `email_verified_at` datetime DEFAULT NULL,
  `position_id` bigint(20) unsigned DEFAULT NULL,
  `grade_id` bigint(20) unsigned DEFAULT NULL,
  `mobile_phone` varchar(30) DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `existing_staff_identity_id` bigint(20) unsigned DEFAULT NULL,
  `request_kind` varchar(20) NOT NULL DEFAULT 'registration',
  PRIMARY KEY (`id`),
  KEY `profession_id` (`profession_id`),
  KEY `reviewer_id` (`reviewer_id`),
  KEY `email` (`email`,`status`),
  KEY `fk_reg_v12_existing_staff` (`existing_staff_identity_id`),
  KEY `fk_reg_v12_position` (`position_id`),
  KEY `fk_reg_v12_grade` (`grade_id`),
  KEY `fk_reg_v12_actor` (`requested_by`),
  KEY `fk_reg_v12_target` (`target_user_id`),
  KEY `idx_reg_v12_queue` (`organisation_id`,`status`,`email_verified_at`),
  KEY `idx_reg_v12_email` (`email`,`status`),
  CONSTRAINT `fk_reg_v12_actor` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reg_v12_existing_staff` FOREIGN KEY (`existing_staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `fk_reg_v12_grade` FOREIGN KEY (`grade_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `fk_reg_v12_position` FOREIGN KEY (`position_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `fk_reg_v12_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `registration_requests_ibfk_1` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `registration_requests_ibfk_2` FOREIGN KEY (`profession_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `registration_requests_ibfk_3` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_registration_v12_status` CHECK (`status` in ('PENDING_EMAIL_VERIFICATION','PENDING_VERIFICATION','APPROVED','REJECTED','REQUEST_CORRECTION'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: registration_review_history
CREATE TABLE `registration_review_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `registration_review_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `registration_requests` (`id`),
  CONSTRAINT `registration_review_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: roles
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `module_code` varchar(64) NOT NULL DEFAULT 'core',
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: role_assignment_history
CREATE TABLE `role_assignment_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `action` varchar(30) NOT NULL,
  `assignment_json` longtext NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `role_assignment_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `role_assignment_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: role_permissions
CREATE TABLE `role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_contact_preferences
CREATE TABLE `staff_contact_preferences` (
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `alternative_phone` varchar(30) DEFAULT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `whatsapp_consent` tinyint(4) NOT NULL DEFAULT 0,
  `visibility` varchar(16) NOT NULL DEFAULT 'private',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_identity_id`),
  CONSTRAINT `staff_contact_preferences_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_email_contacts
CREATE TABLE `staff_email_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `email` varchar(190) NOT NULL,
  `email_type` varchar(16) NOT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING_EMAIL_VERIFICATION',
  `is_notification_primary` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `staff_identity_id` (`staff_identity_id`,`status`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `staff_email_contacts_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_email_contacts_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`email_type` in ('official','personal')),
  CONSTRAINT `CONSTRAINT_2` CHECK (`status` in ('PENDING_EMAIL_VERIFICATION','PENDING_ADMIN_APPROVAL','APPROVED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_email_history
CREATE TABLE `staff_email_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `old_email` varchar(190) DEFAULT NULL,
  `new_email` varchar(190) NOT NULL,
  `email_type` varchar(16) NOT NULL,
  `changed_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_email_history` (`staff_identity_id`,`changed_at`),
  KEY `changed_by` (`changed_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `staff_email_history_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_email_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `staff_email_history_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_identities
CREATE TABLE `staff_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identity_uuid` char(36) NOT NULL,
  `staff_reference` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `identity_uuid` (`identity_uuid`),
  UNIQUE KEY `staff_reference` (`staff_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_import_batches
CREATE TABLE `staff_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PREVIEW',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `staff_import_batches_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('PREVIEW','COMMITTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_import_items
CREATE TABLE `staff_import_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `source_row` int(10) unsigned NOT NULL,
  `data_json` text NOT NULL,
  `result` varchar(500) NOT NULL,
  `staff_identity_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_id` (`batch_id`,`source_row`),
  KEY `staff_identity_id` (`staff_identity_id`),
  CONSTRAINT `staff_import_items_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `staff_import_batches` (`id`),
  CONSTRAINT `staff_import_items_ibfk_2` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_profiles
CREATE TABLE `staff_profiles` (
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(190) NOT NULL,
  `organisation_id` bigint(20) unsigned DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `job_title` varchar(190) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `official_email` varchar(190) DEFAULT NULL,
  `contact_address` text DEFAULT NULL,
  `identification_cipher` text DEFAULT NULL,
  `profession_id` bigint(20) unsigned DEFAULT NULL,
  `position_id` bigint(20) unsigned DEFAULT NULL,
  `grade_id` bigint(20) unsigned DEFAULT NULL,
  `employment_status_id` bigint(20) unsigned DEFAULT NULL,
  `facility_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`staff_identity_id`),
  UNIQUE KEY `official_email` (`official_email`),
  KEY `organisation_id` (`organisation_id`),
  KEY `profession_id` (`profession_id`),
  KEY `position_id` (`position_id`),
  KEY `grade_id` (`grade_id`),
  KEY `employment_status_id` (`employment_status_id`),
  KEY `facility_id` (`facility_id`),
  KEY `unit_id` (`unit_id`),
  CONSTRAINT `staff_profiles_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_profiles_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `staff_profiles_ibfk_3` FOREIGN KEY (`profession_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `staff_profiles_ibfk_4` FOREIGN KEY (`position_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `staff_profiles_ibfk_5` FOREIGN KEY (`grade_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `staff_profiles_ibfk_6` FOREIGN KEY (`employment_status_id`) REFERENCES `master_data` (`id`),
  CONSTRAINT `staff_profiles_ibfk_7` FOREIGN KEY (`facility_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `staff_profiles_ibfk_8` FOREIGN KEY (`unit_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_profile_extensions
CREATE TABLE `staff_profile_extensions` (
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `residential_cipher` longtext DEFAULT NULL,
  `workplace_json` text DEFAULT NULL,
  `professional_json` text DEFAULT NULL,
  `professional_verified_json` text DEFAULT NULL,
  `professional_status` varchar(30) NOT NULL DEFAULT 'SELF_DECLARED',
  `revision` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_by` bigint(20) unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_identity_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `staff_profile_extensions_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_profile_extensions_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`professional_status` in ('SELF_DECLARED','PENDING_VERIFICATION','VERIFIED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_profile_reviews
CREATE TABLE `staff_profile_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `organisation_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `revision` int(10) unsigned NOT NULL,
  `proposed_json` text NOT NULL,
  `evidence_document_id` bigint(20) unsigned DEFAULT NULL,
  `evidence_version` int(10) unsigned NOT NULL,
  `evidence_checksum` char(64) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_identity_id` (`staff_identity_id`,`revision`),
  KEY `requested_by` (`requested_by`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `evidence_document_id` (`evidence_document_id`,`evidence_version`),
  KEY `organisation_id` (`organisation_id`,`status`),
  CONSTRAINT `staff_profile_reviews_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_profile_reviews_ibfk_2` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `staff_profile_reviews_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `staff_profile_reviews_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `staff_profile_reviews_ibfk_5` FOREIGN KEY (`evidence_document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `staff_profile_reviews_ibfk_6` FOREIGN KEY (`evidence_document_id`, `evidence_version`) REFERENCES `document_versions` (`document_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: staff_registry
CREATE TABLE `staff_registry` (
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PRE_VERIFIED',
  `verified_by` bigint(20) unsigned NOT NULL,
  `verified_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`staff_identity_id`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `staff_registry_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `staff_registry_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('PRE_VERIFIED','CLAIMED','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: system_settings
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_access_assignments
CREATE TABLE `training_access_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `assigned_by` bigint(20) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`user_id`,`role_id`),
  KEY `role_id` (`role_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `user_id` (`user_id`,`expires_at`),
  CONSTRAINT `training_access_assignments_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_access_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `training_access_assignments_ibfk_3` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `training_access_assignments_ibfk_4` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_acknowledgements
CREATE TABLE `training_acknowledgements` (
  `registration_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`registration_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `training_acknowledgements_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_acknowledgements_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_approvals
CREATE TABLE `training_approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `revision_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `decision` varchar(24) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  KEY `programme_id` (`programme_id`,`revision_id`),
  CONSTRAINT `training_approvals_ibfk_1` FOREIGN KEY (`programme_id`, `revision_id`) REFERENCES `training_revisions` (`programme_id`, `id`),
  CONSTRAINT `training_approvals_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_attendance_corrections
CREATE TABLE `training_attendance_corrections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `proposed_at` datetime NOT NULL,
  `previous_at` datetime NOT NULL,
  `reason` varchar(500) NOT NULL,
  `notes` text NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'PENDING',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `requested_by` (`requested_by`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `status` (`status`,`event_id`),
  CONSTRAINT `training_attendance_corrections_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `training_attendance_events` (`id`),
  CONSTRAINT `training_attendance_corrections_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_attendance_corrections_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_attendance_events
CREATE TABLE `training_attendance_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `registration_id` bigint(20) unsigned NOT NULL,
  `action` varchar(24) NOT NULL,
  `checkpoint_key` bigint(20) unsigned NOT NULL DEFAULT 0,
  `happened_at` datetime NOT NULL,
  `original_at` datetime NOT NULL,
  `source` varchar(24) NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`,`registration_id`,`action`,`checkpoint_key`),
  KEY `actor_id` (`actor_id`),
  KEY `registration_id` (`registration_id`,`session_id`),
  CONSTRAINT `training_attendance_events_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`),
  CONSTRAINT `training_attendance_events_ibfk_2` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_attendance_events_ibfk_3` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_attendance_tokens
CREATE TABLE `training_attendance_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `checkpoint_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(24) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `staff_identity_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `session_binding` char(64) DEFAULT NULL,
  `issued_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `checkpoint_id` (`checkpoint_id`),
  KEY `staff_identity_id` (`staff_identity_id`),
  KEY `issued_by` (`issued_by`),
  KEY `session_id` (`session_id`,`expires_at`),
  CONSTRAINT `training_attendance_tokens_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`),
  CONSTRAINT `training_attendance_tokens_ibfk_2` FOREIGN KEY (`checkpoint_id`) REFERENCES `training_checkpoints` (`id`),
  CONSTRAINT `training_attendance_tokens_ibfk_3` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `training_attendance_tokens_ibfk_4` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_checkpoints
CREATE TABLE `training_checkpoints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `opens_at` datetime NOT NULL,
  `closes_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  CONSTRAINT `training_checkpoints_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_cpd_records
CREATE TABLE `training_cpd_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` bigint(20) unsigned NOT NULL,
  `rule_id` bigint(20) unsigned DEFAULT NULL,
  `version` int(10) unsigned NOT NULL,
  `points` decimal(10,3) NOT NULL,
  `breakdown_json` longtext NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'PENDING',
  `created_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `reference_text` varchar(1000) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration_id` (`registration_id`,`version`),
  KEY `rule_id` (`rule_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `status` (`status`,`registration_id`),
  CONSTRAINT `training_cpd_records_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_cpd_records_ibfk_2` FOREIGN KEY (`rule_id`) REFERENCES `training_cpd_rules` (`id`),
  CONSTRAINT `training_cpd_records_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_cpd_records_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_cpd_rules
CREATE TABLE `training_cpd_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `config_json` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'DRAFT',
  `created_by` bigint(20) unsigned NOT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`version`),
  KEY `created_by` (`created_by`),
  KEY `verified_by` (`verified_by`),
  CONSTRAINT `training_cpd_rules_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_cpd_rules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_cpd_rules_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_evaluations
CREATE TABLE `training_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `kind` varchar(24) NOT NULL,
  `questions_json` longtext NOT NULL,
  `required_for_certificate` tinyint(4) NOT NULL,
  `pass_percent` decimal(5,2) DEFAULT NULL,
  `opens_at` datetime NOT NULL,
  `closes_at` datetime NOT NULL,
  `published` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`version`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `training_evaluations_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_evaluations_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_evaluation_answers
CREATE TABLE `training_evaluation_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `evaluation_id` bigint(20) unsigned NOT NULL,
  `registration_id` bigint(20) unsigned NOT NULL,
  `answers_json` longtext NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `evaluation_id` (`evaluation_id`,`registration_id`),
  KEY `registration_id` (`registration_id`),
  CONSTRAINT `training_evaluation_answers_ibfk_1` FOREIGN KEY (`evaluation_id`) REFERENCES `training_evaluations` (`id`),
  CONSTRAINT `training_evaluation_answers_ibfk_2` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_invitations
CREATE TABLE `training_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_by` bigint(20) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `staff_identity_id` (`staff_identity_id`),
  KEY `issued_by` (`issued_by`),
  KEY `programme_id` (`programme_id`,`staff_identity_id`),
  CONSTRAINT `training_invitations_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_invitations_ibfk_2` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `training_invitations_ibfk_3` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_links
CREATE TABLE `training_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `public_key` char(32) NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_key` (`public_key`),
  KEY `created_by` (`created_by`),
  KEY `programme_id` (`programme_id`,`revoked_at`),
  CONSTRAINT `training_links_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_notification_events
CREATE TABLE `training_notification_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `recipient_user_id` bigint(20) unsigned NOT NULL,
  `event_key` char(64) NOT NULL,
  `payload_cipher` longtext NOT NULL,
  `delivery_id` bigint(20) unsigned DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `programme_id` (`programme_id`),
  KEY `recipient_user_id` (`recipient_user_id`),
  KEY `delivery_id` (`delivery_id`),
  KEY `processed_at` (`processed_at`,`id`),
  CONSTRAINT `training_notification_events_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_notification_events_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `training_notification_events_ibfk_3` FOREIGN KEY (`delivery_id`) REFERENCES `notification_deliveries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_outputs
CREATE TABLE `training_outputs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `registration_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(24) NOT NULL,
  `role_key` varchar(64) NOT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `reference_number` varchar(190) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'DRAFT',
  `verification_key` char(32) NOT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `file_checksum` char(64) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `replaces_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_at` datetime DEFAULT NULL,
  `void_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_key` (`verification_key`),
  KEY `registration_id` (`registration_id`),
  KEY `template_id` (`template_id`),
  KEY `document_id` (`document_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `replaces_id` (`replaces_id`),
  KEY `programme_id` (`programme_id`,`kind`,`status`),
  CONSTRAINT `training_outputs_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_outputs_ibfk_2` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_outputs_ibfk_3` FOREIGN KEY (`template_id`) REFERENCES `training_templates` (`id`),
  CONSTRAINT `training_outputs_ibfk_4` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `training_outputs_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_outputs_ibfk_6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_outputs_ibfk_7` FOREIGN KEY (`replaces_id`) REFERENCES `training_outputs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_output_history
CREATE TABLE `training_output_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `output_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `previous_status` varchar(24) NOT NULL,
  `new_status` varchar(24) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  KEY `output_id` (`output_id`,`id`),
  CONSTRAINT `training_output_history_ibfk_1` FOREIGN KEY (`output_id`) REFERENCES `training_outputs` (`id`),
  CONSTRAINT `training_output_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_participation_assignments
CREATE TABLE `training_participation_assignments` (
  `registration_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `assigned_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`registration_id`,`role_id`),
  KEY `role_id` (`role_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `training_participation_assignments_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_participation_assignments_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `training_participation_roles` (`id`),
  CONSTRAINT `training_participation_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_participation_roles
CREATE TABLE `training_participation_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`code`),
  CONSTRAINT `training_participation_roles_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_programmes
CREATE TABLE `training_programmes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_key` char(32) NOT NULL,
  `owner_organisation_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'DRAFT',
  `published_revision_id` bigint(20) unsigned DEFAULT NULL,
  `current_revision_id` bigint(20) unsigned DEFAULT NULL,
  `approved_revision_id` bigint(20) unsigned DEFAULT NULL,
  `is_published` tinyint(4) NOT NULL DEFAULT 0,
  `is_cancelled` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_key` (`public_key`),
  KEY `created_by` (`created_by`),
  KEY `owner_organisation_id` (`owner_organisation_id`,`status`),
  KEY `id` (`id`,`current_revision_id`),
  KEY `id_2` (`id`,`approved_revision_id`),
  KEY `id_3` (`id`,`published_revision_id`),
  CONSTRAINT `training_programmes_ibfk_1` FOREIGN KEY (`owner_organisation_id`) REFERENCES `organisations` (`id`),
  CONSTRAINT `training_programmes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `training_programmes_ibfk_3` FOREIGN KEY (`id`, `current_revision_id`) REFERENCES `training_revisions` (`programme_id`, `id`),
  CONSTRAINT `training_programmes_ibfk_4` FOREIGN KEY (`id`, `approved_revision_id`) REFERENCES `training_revisions` (`programme_id`, `id`),
  CONSTRAINT `training_programmes_ibfk_5` FOREIGN KEY (`id`, `published_revision_id`) REFERENCES `training_revisions` (`programme_id`, `id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_programme_documents
CREATE TABLE `training_programme_documents` (
  `document_id` bigint(20) unsigned NOT NULL,
  `programme_id` bigint(20) unsigned NOT NULL,
  `purpose` varchar(100) NOT NULL,
  PRIMARY KEY (`document_id`),
  KEY `programme_id` (`programme_id`),
  CONSTRAINT `training_programme_documents_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `training_programme_documents_ibfk_2` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_registrations
CREATE TABLE `training_registrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) NOT NULL,
  `source` varchar(24) NOT NULL,
  `wait_sequence` bigint(20) unsigned DEFAULT NULL,
  `snapshot_json` longtext NOT NULL,
  `eligibility_json` longtext NOT NULL,
  `assigned_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`staff_identity_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `programme_id_2` (`programme_id`,`status`,`wait_sequence`),
  KEY `staff_identity_id` (`staff_identity_id`,`status`),
  CONSTRAINT `training_registrations_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_registrations_ibfk_2` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`),
  CONSTRAINT `training_registrations_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` in ('PENDING','CONFIRMED','WAITLISTED','WITHDRAWN','REJECTED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_registration_history
CREATE TABLE `training_registration_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `old_status` varchar(24) DEFAULT NULL,
  `new_status` varchar(24) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  KEY `registration_id` (`registration_id`,`id`),
  CONSTRAINT `training_registration_history_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`),
  CONSTRAINT `training_registration_history_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_revisions
CREATE TABLE `training_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `revision_no` int(10) unsigned NOT NULL,
  `config_json` longtext NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `programme_id` (`programme_id`,`revision_no`),
  UNIQUE KEY `programme_id_2` (`programme_id`,`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `training_revisions_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_revisions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: training_sessions
CREATE TABLE `training_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `policy_json` longtext NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `programme_id` (`programme_id`,`start_at`),
  CONSTRAINT `training_sessions_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_sessions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: training_templates
CREATE TABLE `training_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `programme_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `type_key` varchar(64) NOT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `programme_id` (`programme_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `training_templates_ibfk_1` FOREIGN KEY (`programme_id`) REFERENCES `training_programmes` (`id`),
  CONSTRAINT `training_templates_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: upgrade_history
CREATE TABLE `upgrade_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(64) NOT NULL,
  `module_key` varchar(32) NOT NULL,
  `previous_version` varchar(32) DEFAULT NULL,
  `target_version` varchar(32) NOT NULL,
  `status` varchar(40) NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_id` (`run_id`),
  KEY `upgrade_module_date` (`module_key`,`created_at`),
  KEY `fk_upgrade_actor` (`actor_id`),
  CONSTRAINT `fk_upgrade_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: users
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `staff_identity_id` bigint(20) unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `credential_revision` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_identity_id` (`staff_identity_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`staff_identity_id`) REFERENCES `staff_identities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_role_assignments
CREATE TABLE `user_role_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `organisation_id` bigint(20) unsigned DEFAULT NULL,
  `scope_key` bigint(20) unsigned GENERATED ALWAYS AS (coalesce(`organisation_id`,0)) STORED,
  `include_descendants` tinyint(1) NOT NULL DEFAULT 0,
  `module_code` varchar(64) NOT NULL DEFAULT 'core',
  PRIMARY KEY (`id`),
  UNIQUE KEY `assignment_unique` (`user_id`,`role_id`,`scope_key`),
  KEY `role_id` (`role_id`),
  KEY `organisation_id` (`organisation_id`),
  CONSTRAINT `user_role_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_role_assignments_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `user_role_assignments_ibfk_3` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe seed data: module_registry
INSERT INTO `module_registry` (`id`,`module_key`,`name`,`status`,`navigation_path`,`permission_namespace`) VALUES
('1','core','Shared Core','active','/dashboard','core'),
('2','training','Training, Attendance & CPD','inactive',NULL,'training'),
('3','eforms','e-Forms & Digital Office','active','/eforms','eforms'),
('5','public_programs','Program Awam','active','/public-program.php?admin=1','public_programs');

-- Safe seed data: master_data
INSERT INTO `master_data` (`id`,`category`,`code`,`name`,`parent_id`,`organisation_id`,`is_active`) VALUES
('1','organisation_type','national','Malaysia / National',NULL,NULL,'1'),
('2','organisation_type','state','State',NULL,NULL,'1'),
('3','organisation_type','pkd','PKD',NULL,NULL,'1'),
('4','organisation_type','clinic','Clinic / Facility',NULL,NULL,'1'),
('5','organisation_type','facility','Other Facility',NULL,NULL,'1'),
('6','organisation_type','unit','Unit',NULL,NULL,'1'),
('7','employment_status','active','Active',NULL,NULL,'1'),
('8','employment_status','inactive','Inactive',NULL,NULL,'1'),
('9','employment_status','retired','Retired',NULL,NULL,'1'),
('10','employment_status','transferred','Transferred',NULL,NULL,'1');

-- Safe seed data: roles
INSERT INTO `roles` (`id`,`code`,`name`,`is_system`,`module_code`,`is_active`) VALUES
('1','super_admin','Super Administrator','1','core','1'),
('2','pkd_admin','Pkd Admin','1','core','1'),
('3','clinic_admin','Clinic Admin','1','core','1'),
('4','unit_admin','Unit Admin','1','core','1'),
('5','staff','Staff','1','core','1'),
('6','portal_admin','Portal Administrator','1','core','1'),
('7','training_pkd_admin','Training Pkd Admin','1','training','1'),
('8','training_clinic_admin','Training Clinic Admin','1','training','1'),
('9','training_unit_admin','Training Unit Admin','1','training','1'),
('10','training_programme_admin','Training Programme Admin','1','training','1'),
('11','training_approver','Training Approver','1','training','1'),
('12','training_participant','Training Participant','1','training','1'),
('13','training_attendance_officer','Attendance Officer','1','training','1'),
('14','training_cpd_admin','Cpd Admin','1','training','1'),
('15','training_documents_admin','Documents Admin','1','training','1'),
('16','training_evaluation_admin','Evaluation Admin','1','training','1'),
('17','training_reporter','Reporter','1','training','1'),
('18','training_content_admin','Content Admin','1','training','1'),
('19','eforms_staff','Eforms Staff','1','eforms','1'),
('20','eforms_reviewer','Eforms Reviewer','1','eforms','1'),
('21','eforms_admin','Eforms Admin','1','eforms','1');

-- Safe seed data: permissions
INSERT INTO `permissions` (`id`,`code`,`module_code`,`name`) VALUES
('1','core.dashboard.view','core','core.dashboard.view'),
('2','core.organisations.manage','core','core.organisations.manage'),
('3','core.users.manage','core','core.users.manage'),
('4','core.audit.view','core','core.audit.view'),
('5','core.settings.manage','core','core.settings.manage'),
('6','core.migrations.manage','core','core.migrations.manage'),
('7','core.staff.professional.verify','core','Verify scoped professional profile'),
('8','core.staff.private_address','core','Explicit private residential address access'),
('15','core.staff.view','core','core.staff.view'),
('16','core.staff.manage','core','core.staff.manage'),
('17','core.profile.self','core','core.profile.self'),
('18','core.registry.import','core','core.registry.import'),
('19','core.registrations.review','core','core.registrations.review'),
('20','core.roles.manage','core','core.roles.manage'),
('21','core.master.manage','core','core.master.manage'),
('22','core.notifications.self','core','core.notifications.self'),
('23','core.notifications.manage','core','core.notifications.manage'),
('24','core.documents.upload','core','core.documents.upload'),
('25','core.documents.view','core','core.documents.view'),
('26','core.documents.manage','core','core.documents.manage'),
('27','core.identification.view','core','core.identification.view'),
('28','core.modules.manage','core','core.modules.manage'),
('29','core.portal.manage','core','Urus kandungan portal utama'),
('30','training.dashboard.view','training','Training dashboard.view'),
('31','training.programmes.view','training','Training programmes.view'),
('32','training.programmes.create','training','Training programmes.create'),
('33','training.programmes.edit','training','Training programmes.edit'),
('34','training.programmes.submit','training','Training programmes.submit'),
('35','training.programmes.publish','training','Training programmes.publish'),
('36','training.approvals.review','training','Training approvals.review'),
('37','training.participants.view','training','Training participants.view'),
('38','training.participants.assign','training','Training participants.assign'),
('39','training.participants.manage','training','Training participants.manage'),
('40','training.registrations.self','training','Training registrations.self'),
('41','training.registrations.walkin','training','Training registrations.walkin'),
('42','training.roles.manage','training','Training roles.manage'),
('43','training.invitations.manage','training','Training invitations.manage'),
('44','training.links.manage','training','Training links.manage'),
('45','training.documents.view','training','Training documents.view'),
('46','training.documents.upload','training','Training documents.upload'),
('47','training.documents.manage','training','Training documents.manage'),
('48','training.attendance.manage','training','Training attendance.manage'),
('49','training.attendance.correct','training','Training attendance.correct'),
('50','training.attendance.view','training','Training attendance.view'),
('51','training.cpd.manage','training','Training cpd.manage'),
('52','training.cpd.approve','training','Training cpd.approve'),
('53','training.cpd.view','training','Training cpd.view'),
('55','training.outputs.manage','training','Training outputs.manage'),
('56','training.outputs.approve','training','Training outputs.approve'),
('57','training.signatories.manage','training','Training signatories.manage'),
('62','training.evaluations.manage','training','Training evaluations.manage'),
('63','training.reports.view','training','Training reports.view'),
('64','training.reports.export','training','Training reports.export'),
('67','training.content.manage','training','Training content.manage'),
('68','eforms.templates.manage','eforms','e-Forms templates.manage'),
('69','eforms.submissions.create','eforms','e-Forms submissions.create'),
('70','eforms.submissions.review','eforms','e-Forms submissions.review'),
('71','eforms.submissions.manage','eforms','e-Forms submissions.manage'),
('72','eforms.documents.upload','eforms','e-Forms documents.upload'),
('73','eforms.documents.view','eforms','e-Forms documents.view'),
('74','eforms.documents.manage','eforms','e-Forms documents.manage'),
('75','public_programs.manage','public_programs','Program Awam manage'),
('76','public_programs.participants.view','public_programs','Program Awam participants.view'),
('77','public_programs.participants.export','public_programs','Program Awam participants.export');

-- Safe seed data: role_permissions
INSERT INTO `role_permissions` (`role_id`,`permission_id`) VALUES
('1','1'),
('1','2'),
('1','3'),
('1','4'),
('1','5'),
('1','6'),
('1','7'),
('1','8'),
('1','15'),
('1','16'),
('1','17'),
('1','18'),
('1','19'),
('1','20'),
('1','21'),
('1','22'),
('1','23'),
('1','24'),
('1','25'),
('1','26'),
('1','27'),
('1','28'),
('1','29'),
('2','1'),
('2','2'),
('2','3'),
('2','15'),
('2','16'),
('2','17'),
('2','18'),
('2','19'),
('2','20'),
('2','22'),
('2','24'),
('2','25'),
('2','26'),
('3','1'),
('3','2'),
('3','3'),
('3','15'),
('3','16'),
('3','17'),
('3','18'),
('3','19'),
('3','20'),
('3','22'),
('3','24'),
('3','25'),
('3','26'),
('4','1'),
('4','2'),
('4','3'),
('4','15'),
('4','16'),
('4','17'),
('4','18'),
('4','19'),
('4','20'),
('4','22'),
('4','24'),
('4','25'),
('4','26'),
('5','1'),
('5','17'),
('5','22'),
('5','24'),
('5','25'),
('6','29'),
('7','30'),
('7','31'),
('7','32'),
('7','33'),
('7','34'),
('7','35'),
('7','36'),
('7','37'),
('7','38'),
('7','39'),
('7','40'),
('7','41'),
('7','42'),
('7','43'),
('7','44'),
('7','45'),
('7','46'),
('7','47'),
('7','48'),
('7','49'),
('7','50'),
('7','51'),
('7','52'),
('7','53'),
('7','55'),
('7','56'),
('7','57'),
('7','62'),
('7','63'),
('7','64'),
('7','67'),
('8','30'),
('8','31'),
('8','32'),
('8','33'),
('8','34'),
('8','35'),
('8','37'),
('8','38'),
('8','39'),
('8','40'),
('8','41'),
('8','42'),
('8','43'),
('8','44'),
('8','45'),
('8','46'),
('8','47'),
('9','30'),
('9','31'),
('9','32'),
('9','33'),
('9','34'),
('9','35'),
('9','37'),
('9','38'),
('9','39'),
('9','40'),
('9','41'),
('9','42'),
('9','43'),
('9','44'),
('9','45'),
('9','46'),
('9','47'),
('10','30'),
('10','31'),
('10','33'),
('10','34'),
('10','35'),
('10','37'),
('10','38'),
('10','39'),
('10','40'),
('10','41'),
('10','43'),
('10','44'),
('10','45'),
('10','46'),
('10','47'),
('11','30'),
('11','31'),
('11','36'),
('12','30'),
('12','40'),
('12','45'),
('13','48'),
('13','49'),
('13','50'),
('14','50'),
('14','51'),
('14','52'),
('14','53'),
('15','45'),
('15','46'),
('15','47'),
('15','50'),
('15','55'),
('15','56'),
('15','57'),
('16','62'),
('17','50'),
('17','53'),
('17','63'),
('17','64'),
('18','67'),
('19','69'),
('19','72'),
('19','73'),
('20','69'),
('20','70'),
('20','72'),
('20','73'),
('21','68'),
('21','69'),
('21','70'),
('21','71'),
('21','72'),
('21','73'),
('21','74');

-- Safe seed data: notification_templates
INSERT INTO `notification_templates` (`template_key`,`subject`,`body`,`is_active`) VALUES
('activation','Aktifkan akaun epejabat','Sila buka pautan pengesahan: {{url}}\nPautan tamat tempoh dalam 60 minit.','1'),
('correction','Pembetulan permohonan','Sila semak permohonan melalui pautan: {{url}}\nPautan tamat tempoh dalam 60 minit.','1'),
('eforms_status','e-Forms — Makluman status','Permohonan {{submission}}: {{status}}. Semak portal: {{url}}','1'),
('public_program_receipt','Permohonan program diterima','Permohonan diterima: {{received}} anak dalam kuota permohonan, {{waiting}} anak dalam senarai menunggu. Ini bukan jaminan tempat, kelulusan klinikal atau subsidi. Simpan pautan status sulit: {{url}}. Pautan tamat: {{expiry}} UTC.','1'),
('public_program_update','Status permohonan program dikemaskini','ID Peserta {{participant}} kini dalam kuota permohonan. Ini bukan jaminan tempat atau kelulusan klinikal. Gunakan pautan status sulit dalam e-mel penerimaan asal; hubungi urus setia jika pautan tamat.','1'),
('reset','Tetapkan semula kata laluan','Sila buka pautan: {{url}}\nPautan tamat tempoh dalam 60 minit.','1'),
('system','Notifikasi epejabat','{{message}}','1'),
('training_event','Makluman Program epejabat','{{message}}','1'),
('training_invitation','Jemputan Program epejabat','{{message}}\n{{url}}','1');

-- Safe seed data: portal_banners
INSERT INTO `portal_banners` (`id`,`content_type`,`slot`,`title`,`caption`,`image_path`,`target_url`,`is_active`,`updated_by`,`created_at`,`updated_at`) VALUES
('1','HERO','1','Kesihatan komuniti, diperkasa secara digital.','Satu pintu masuk untuk program, borang dan perkhidmatan digital PKD Kota Bharu.','/assets/images/portal/hero-default.svg','/training/portal','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('2','ACTIVITY','1','Program & latihan','Temui program terkini untuk warga kesihatan.','/assets/images/portal/activity-default.svg','/training/portal','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('3','ACTIVITY','2','Daftar program','Daftar penyertaan dan urus rekod program anda.','/assets/images/portal/activity-default.svg','/training','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('4','ACTIVITY','3','Semak status','Semak status permohonan melalui portal awam.','/assets/images/portal/activity-default.svg','/public-program.php','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('5','ACTIVITY','4','Borang digital','Akses borang dan permohonan dalam talian.','/assets/images/portal/activity-default.svg','/eforms','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('6','ACTIVITY','5','Direktori staf','Cari maklumat organisasi dan perkhidmatan staf.','/assets/images/portal/activity-default.svg','/staff','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('7','ACTIVITY','6','Akaun saya','Urus profil, keselamatan dan rekod peribadi.','/assets/images/portal/activity-default.svg','/profile','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('8','QUICK_LINK','1','Portal Awam','','','/training/portal','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('9','QUICK_LINK','2','Daftar Program','','','/training','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('10','QUICK_LINK','3','Senarai Program','','','/training','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34'),
('11','QUICK_LINK','4','Borang Digital','','','/eforms','1',NULL,'2026-10-21 14:11:34','2026-10-21 14:11:34');

-- Safe seed data: system_settings
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('app_name','epejabat.net','2026-10-21 14:11:31'),
('installation_instance',@EPEJABAT_INSTANCE,'2026-10-21 14:11:31'),
('installation_status','pending_admin','2026-10-21 14:11:35'),
('module_version.eforms','1.3.0','2026-10-21 14:11:35'),
('module_version.public_programs','0.1.0-rc1','2026-10-21 14:11:35'),
('phase','1','2026-10-21 14:11:34'),
('timezone','Asia/Kuala_Lumpur','2026-10-21 14:11:31'),
('training_full_version','1.0.0','2026-10-21 14:11:34'),
('training_t1_version','1.0.0','2026-10-21 14:11:34');

-- Safe seed data: database_migrations
INSERT INTO `database_migrations` (`version`,`checksum`,`status`,`started_at`,`applied_at`) VALUES
('000_database_migrations','87a93d9b8206507309ef4592c85839b28d9a443f767fc8c80ba245d9200b42d0','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('001_organisations','4506455425b042e6c9ebeaa44df5714914c041da215499af5a35f2aef0bed41b','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('002_organisation_hierarchy','672a961c43f033b7aaecdd587ec9f13d1b69e1d1a219d943d8e2f2426de66858','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('003_staff_identities','d2c491734083c6b9807c2fd44874e0a265294ce01974ec7c006cb7aaaac04ea4','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('004_staff_profiles','bf0e2c868781cff706649639b71f413cdc0fc1f20d24d301c154e209fa55fa70','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('005_users','d2b841a05f81de79e2d8e05529c974a1f7c51d926840816189d4893aad196abb','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('006_roles','de9901d205ebbcbc414fb02817efc975501772a041f3bef5c1b6197d0e49819b','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('007_permissions','a3fd1e6724fd3301f0be1aa976d7acf61e8a8aa4e95d39137627a7c7d6bc148a','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('008_role_permissions','7997161b96ece7ad12b52403fe8facfe5a77823b0d65c969ff9ed8c556d6b59e','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('009_user_role_assignments','5380f717eff67c70b30e754348ba88c7fb2dfb78bddbe8472242334de1fa69b3','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('010_audit_logs','e46e6ab1483a20c7fb9e21da619a2f22507e57c9329a65ab3f8dc11de2f03c18','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('011_system_settings','ba99e1c1816e93242aac038e7f5c5d1204eeca56e5a43aef1acf265d90f26fb4','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('012_login_attempts','8b987e4d0c54d4bb45e7ace320e4f2232f31bbb5a41fac5430a522c29ed979fb','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('100_modules.sql','2df5ed0f92a4e9f7e30ae3bf5e1f4cf7efa5116fc2ab9d3bc98140ee8271d155','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('101_master_data.sql','cf94b6f15381c9c3af725697f113c67b108c03f3981024f858a8e765f484dc1c','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('101_registration_catalog_metadata.sql','8f248907f59ba8dd76d8237220a527a6b7986cd55094c8764029b109caf5300c','applied','2026-10-21 14:11:33','2026-10-21 06:11:33'),
('102_organisation_fields.sql','55a374bf438a6bd958c7a33ce0754408b03f69d90619cce57957b98f68a3b60c','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('102_registration_status_policy.php','8ac47cbd621743e43609e5eecb7389894dbb7b000052815f35ad7c7c418d8770','applied','2026-10-21 14:11:32','2026-10-21 06:11:33'),
('103_organisation_type_check.php','f73f3a97d6e36205e42634ca62b779e34f9b97b5655a32c714cb5ad2c9f4c716','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('104_profile_fields.sql','4a550f672bb1f8894b03c8f507d2c0ab11e2f22c0568a56f92b0bba164b44d81','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('105_user_security.sql','657e682e7d1f98f880d9c3cd679e7f52307c98f41cb6b9351c2d8491a0c2a10a','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('106_role_module.sql','035ffe9cc0a48e35584e16d19f7f9ed75d57f4f81e6b5482e2e1b818053e67a8','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('107_assignment_module.sql','ab5a8b3dd736b64ff51299f3f4681519b25384f8aa595b3fd92a9bbec91f260e','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('108_audit_fields.sql','1c98f84c182e22519b88b982f10f67665fb783fb889d981e1e79a515ce1c561b','applied','2026-10-21 14:11:31','2026-10-21 06:11:31'),
('109_staff_registry.sql','7852fcc3f5bfa075a7148815d525f4e6e489a88887d3cd88976d50d9ae4be963','applied','2026-10-21 14:11:31','2026-10-21 06:11:32'),
('110_employment_history.sql','a7c8530fdb61e7bff5996f232f9d423701cfad0f400c212d6baeb744a209b513','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('111_credentials.sql','fd69e9c16773188ca6524261991e2c3a5de4fee252473fe4ad494c274e9f7f2b','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('112_organisation_history.sql','49dd5e4e88121ccfc61d2dca42a8f734bb28abbaa63e0f5512337cbb62e8e126','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('113_role_history.sql','1af47b3b79368348e093c9884b7ead013fd0a9b0a36ada597ef1492484c5aa43','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('114_registrations.sql','b8bf3655eeda7f9dd68d9a1aac77031586567ae8cb995e27d9cd2fbd3981a139','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('115_review_history.sql','2ed7aa81c4851a32d28eb998fb378f3ec71b551e90bebb4e850f369ac2edcfa0','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('116_tokens.sql','0a78810552ce257445d1311363cd59d925de9888ac3f07b139f827d67a73a31f','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('117_public_limits.sql','f6cf6c686c24d3a8fed4414df3d26fad246d666dd7eba6bd2fcf188a1f2a76c7','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('118_templates.sql','74bad538ff423db73feee52527870a7d3b8a062a7989c8492b7bdd6e9be35511','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('119_notifications.sql','06ef8087ceb2c0e8656544cf8638daf2baa729d73e553845432e926d7b5ef501','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('120_deliveries.sql','b6d28462b6f6d981bac5e318e4a576c0fddae908242a3d95efb96a20ba63d5d1','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('121_documents.sql','0f62c53ae88758e0d554a38e07361b7fdb633e6f8430591c4442869615bc94f4','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('122_document_versions.sql','cfbaed70155789d15c44f894837ab5bd29e307aadc9108e04e734fac854eed94','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('123_import_batches.sql','df4121f048d2f08536bff3322132e47ab642fed23a8805f343931bed9ee3df71','applied','2026-10-21 14:11:32','2026-10-21 06:11:32'),
('124_import_items.sql','f0dde8aead72f0bc155573d1549c346d614bc6f07443e5df405c9d13acd242e4','applied','2026-10-21 14:11:33','2026-10-21 06:11:33'),
('190_seed_core.php','5d4213b30b373a077365210d3d6948c2046982e9b36ee26e702e3b2b9b6fe515','applied','2026-10-21 14:11:33','2026-10-21 06:11:34'),
('200_platform_versioning.sql','a6c2a8ccbe314f8f3a65ec6e915adfcaeb22896eee9140633d772ce7d5b46981','applied','2026-10-21 14:11:34','2026-10-21 06:11:34'),
('201_portal_content.sql','21d216828e5a4d1d62696c533ab1441e7d4558c8ca38d3c4e32328c05ae6766e','applied','2026-10-21 14:11:34','2026-10-21 06:11:34'),
('eforms:Phase2:200_eforms_schema.sql','126d1337b65ef49498461b4970e73c005e74094924d7c7ee231175922c27a6d1','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:201_eforms_seed.php','901814a72b2658f8da55f37642689d0556df1efa0180cca8e7ce62aa26bf8948','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:210_package_pdf_support.sql','a23891fba02973af26ec347faf9f70b6c084c2ebba6710fc3eee92f2b9008cfa','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:211_form_packages.sql','42c79935184d85fe9af74e0a6bcd31dc4a4250ee68d1e9c11326556143ee04bc','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:212_package_version.php','8d13b6aa0219e02ced96d1ccef36254dc5bd943dba8c81c0a2705410cde7f661','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:220_public_programs.sql','0aee1752e3ab17b1b110d8b26662561163dfd547594c6b67ee824c0b3fbf22f7','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('eforms:Phase2:221_public_program_seed.php','353c8f2600a62ff82e6f91a342ae1d6a2488c4defbd81ad901dfa495dbe81c9f','applied','2026-10-21 14:11:35','2026-10-21 06:11:35'),
('training:FULL:001_tables.sql','9859fad3c4073d9fc5fe180e4c6687647ad16ec6214e1cbc3f67d40e571315d7','applied','2026-10-21 14:11:34','2026-10-21 06:11:34'),
('training:FULL:002_permissions.php','57054b93a62bc58c6dd636cc6c5a019ae4892247339f46b03e90c5f518e439d6','applied','2026-10-21 14:11:34','2026-10-21 06:11:34'),
('training:T1:001_tables.sql','c0558a9bf5602659bee6fdfa3af2868782c146720839243f6281ff8864ac5135','applied','2026-10-21 14:11:34','2026-10-21 06:11:34'),
('training:T1:002_seeds.php','f073c5f1c89c21d65cc7a03afa3dcd932ed5a9cd15cab4c81330850952470c3d','applied','2026-10-21 14:11:34','2026-10-21 06:11:34');

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Salin nilai yang dipaparkan ke APP_INSTANCE dalam .env produksi.
SELECT setting_value AS APP_INSTANCE
FROM system_settings
WHERE setting_key = 'installation_instance';
