CREATE DATABASE IF NOT EXISTS `__ASTRO_DB__`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `__ASTRO_DB__`;

CREATE TABLE IF NOT EXISTS observations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    observation_key VARCHAR(255) NOT NULL,
    target_name VARCHAR(255) NULL,
    target_type VARCHAR(100) NULL,
    ra_deg DOUBLE NULL,
    dec_deg DOUBLE NULL,
    observation_time DATETIME NULL,
    telescope VARCHAR(255) NULL,
    camera VARCHAR(255) NULL,
    filter_used VARCHAR(100) NULL,
    exposure_time DOUBLE NULL,
    gain_value INT NULL,
    integration_time DOUBLE NULL,
    simbad_oid VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    object_class VARCHAR(100) NULL,
    phoca_category VARCHAR(500) NULL,
    project_name VARCHAR(255) NULL,
    phoca_uploaded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_observation_key (observation_key),
    KEY idx_target (target_name),
    KEY idx_date (observation_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS observation_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    observation_id INT UNSIGNED NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(512) NULL,
    file_path TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY observation_id (observation_id),
    CONSTRAINT observation_files_ibfk_1
        FOREIGN KEY (observation_id) REFERENCES observations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
