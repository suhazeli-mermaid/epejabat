CREATE TABLE organisation_hierarchy (
 ancestor_id BIGINT UNSIGNED NOT NULL, descendant_id BIGINT UNSIGNED NOT NULL,
 depth INT UNSIGNED NOT NULL, PRIMARY KEY (ancestor_id,descendant_id),
 FOREIGN KEY (ancestor_id) REFERENCES organisations(id), FOREIGN KEY (descendant_id) REFERENCES organisations(id),
 INDEX (descendant_id), CHECK ((ancestor_id=descendant_id AND depth=0) OR (ancestor_id<>descendant_id AND depth>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
