-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- =============================================================================
-- Roster Schedules (portal-owned)
-- =============================================================================
-- Replaces the earlier ministry_duty model — rosters are not 1-rule-per-row;
-- they're a container (e.g. "MTE Pick-Up Schedule Apr 27–May 1") that holds
-- many slots (each with a label, location, optional role) and each slot
-- holds 1+ assignees.
--
-- Three tables:
--   schedule_roster              container
--   schedule_roster_slot         dated or DOW row inside a roster
--   schedule_roster_assignment   one person on one slot (multi-person ok)
--
-- Person-pool filtering happens at editor time:
--   - optional ministry_id seeds a default member list (still filterable)
--   - person_custom.c1 (Member Type list, lst_ID=13: Radical/Trailblazer/G&A)
--     drives the classification chips; G&A unchecked by default
--   - "Include all members" toggle bypasses the ministry filter
--   - campus filter still applies
--
-- These references stay loose (no cross-DB FKs) — same posture as availability
-- and the prior duty model.
-- =============================================================================

-- Drop the abandoned duty model. No production data was stored in this table
-- (it was added < 1 day ago and never written to from any UI).
DROP TABLE IF EXISTS `ministry_duty`;

CREATE TABLE IF NOT EXISTS `schedule_roster` (
  `roster_id`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(180)  NOT NULL,
  `subtitle`      VARCHAR(255)  DEFAULT NULL,
  `ministry_id`   MEDIUMINT     DEFAULT NULL,            -- optional anchor for member filter
  `campus_id`     INT           DEFAULT NULL,            -- optional campus pin
  `starts_on`     DATE          NOT NULL,
  `ends_on`       DATE          NOT NULL,                -- inclusive
  `notes`         TEXT          DEFAULT NULL,
  `is_published`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`    INT UNSIGNED  DEFAULT NULL,            -- portal_users.portal_user_id
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`roster_id`),
  KEY `idx_sr_dates` (`starts_on`, `ends_on`),
  KEY `idx_sr_ministry` (`ministry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schedule_roster_slot` (
  `slot_id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `roster_id`      INT UNSIGNED  NOT NULL,
  `slot_date`      DATE          DEFAULT NULL,           -- specific date, OR …
  `slot_dow`       TINYINT       DEFAULT NULL,           -- … day-of-week (0-6, Sun=0); recurs each week in the roster's window
  `label`          VARCHAR(180)  DEFAULT NULL,           -- e.g. "Shoppers Markville", "SB Downtown"
  `location`       VARCHAR(255)  DEFAULT NULL,           -- e.g. "10 AM – 6 PM", "Scarb drop-off"
  `role_id`        MEDIUMINT     DEFAULT NULL,           -- optional ChurchCRM roles.role_id
  `display_order`  INT           NOT NULL DEFAULT 0,
  `notes`          TEXT          DEFAULT NULL,
  PRIMARY KEY (`slot_id`),
  KEY `idx_slot_roster` (`roster_id`, `display_order`),
  KEY `idx_slot_date`   (`slot_date`),
  CONSTRAINT `fk_slot_roster`
    FOREIGN KEY (`roster_id`) REFERENCES `schedule_roster` (`roster_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schedule_roster_assignment` (
  `assignment_id`  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `slot_id`        INT UNSIGNED  NOT NULL,
  `person_id`      MEDIUMINT     DEFAULT NULL,           -- ChurchCRM ref; NULL when display_name is the only label
  `display_name`   VARCHAR(255)  DEFAULT NULL,           -- guest, family ("Adams Fam"), or override label
  `display_order`  INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`assignment_id`),
  KEY `idx_assn_slot`   (`slot_id`, `display_order`),
  KEY `idx_assn_person` (`person_id`),
  CONSTRAINT `fk_assn_slot`
    FOREIGN KEY (`slot_id`) REFERENCES `schedule_roster_slot` (`slot_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
