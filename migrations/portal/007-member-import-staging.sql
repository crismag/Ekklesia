-- @connection: churchcrm
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- Staging tables for the campus member workbook import.
-- Lives in the shared people database (same connection as person_per) so
-- review/edit happens before any CRM write. Idempotent.

CREATE TABLE IF NOT EXISTS `member_import_batch` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campus_id`     INT          NOT NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'staging',
  `source_label`  VARCHAR(190) DEFAULT NULL,
  `hub_sheet`     VARCHAR(120) DEFAULT NULL,
  `ny_sheet`      VARCHAR(120) DEFAULT NULL,
  `hub_updated`   VARCHAR(190) DEFAULT NULL,
  `ny_updated`    VARCHAR(190) DEFAULT NULL,
  `warnings`      TEXT         DEFAULT NULL,
  `duplicate_report` TEXT      DEFAULT NULL,
  `created_by`    INT          NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mib_campus` (`campus_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_import_row` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id`           INT UNSIGNED NOT NULL,
  `last_name`          VARCHAR(80)  NOT NULL,
  `first_name`         VARCHAR(80)  DEFAULT NULL,
  `middle_name`        VARCHAR(80)  DEFAULT NULL,
  `preferred_name`     VARCHAR(80)  DEFAULT NULL,
  `email`              VARCHAR(190) DEFAULT NULL,
  `phone`              VARCHAR(40)  DEFAULT NULL,
  `address_raw`        VARCHAR(255) DEFAULT NULL,
  `address1`           VARCHAR(160) DEFAULT NULL,
  `city`               VARCHAR(80)  DEFAULT NULL,
  `state`              VARCHAR(80)  DEFAULT NULL,
  `zip`                VARCHAR(20)  DEFAULT NULL,
  `country`            VARCHAR(40)  DEFAULT NULL,
  `birth_year`         SMALLINT     DEFAULT NULL,
  `birth_month`        TINYINT      DEFAULT NULL,
  `birth_day`          TINYINT      DEFAULT NULL,
  `member_since`       DATE         DEFAULT NULL,
  `member_type`        VARCHAR(80)  DEFAULT NULL,
  `ministry`           VARCHAR(255) DEFAULT NULL,
  `confirmed`          VARCHAR(40)  DEFAULT NULL,
  `source`             VARCHAR(20)  NOT NULL DEFAULT 'hub',
  `filled_from`        TEXT         DEFAULT NULL,
  `status`             VARCHAR(20)  NOT NULL DEFAULT 'draft',
  `matched_person_id`  INT          DEFAULT NULL,
  `notes`              VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mir_batch` (`batch_id`, `status`),
  CONSTRAINT `fk_mir_batch` FOREIGN KEY (`batch_id`) REFERENCES `member_import_batch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
