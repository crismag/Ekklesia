-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- =============================================================================
-- Portal Auth Schema (B / portal-owned tables)
-- =============================================================================
-- Target database: u471078694_christlike_mdb (portal connection per
-- config/database.php). All tables are portal-owned and live in the portal
-- DB exclusively. They reference ChurchCRM person IDs but never copy person
-- data — ChurchCRM person_per remains the source of truth for identity.
--
-- Idempotent: re-running this script is a no-op once tables exist.
-- =============================================================================


-- portal_users — login credential records.
-- One row per portal account. NOT a copy of person_per.
CREATE TABLE IF NOT EXISTS `portal_users` (
  `portal_user_id`  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(190)   NOT NULL,
  `password_hash`   VARCHAR(255)   NOT NULL,                  -- Argon2id PHC string
  `is_active`       TINYINT(1)     NOT NULL DEFAULT 1,
  `display_name`    VARCHAR(100)   DEFAULT NULL,              -- optional friendly label
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at`   DATETIME       DEFAULT NULL,
  PRIMARY KEY (`portal_user_id`),
  UNIQUE KEY `ux_portal_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- portal_user_person_links — maps a portal account to a ChurchCRM person.
-- A single portal user may link to multiple people (e.g. parent managing a
-- family). Each link may also be flagged as primary (the "this is me" one).
-- The person_id is a foreign reference into ChurchCRM's person_per — we do
-- NOT enforce it as a SQL FK because the person_per table lives in a
-- different database (u471078694_churchcrm_v0).
CREATE TABLE IF NOT EXISTS `portal_user_person_links` (
  `link_id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `portal_user_id`  INT UNSIGNED   NOT NULL,
  `person_id`       MEDIUMINT      NOT NULL,                  -- references churchcrm.person_per.per_ID
  `is_primary`      TINYINT(1)     NOT NULL DEFAULT 0,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `ux_user_person` (`portal_user_id`, `person_id`),
  KEY `idx_pup_person` (`person_id`),
  CONSTRAINT `fk_pup_user`
    FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`portal_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- portal_user_roles — a portal user's roles, optionally scoped.
-- A user may carry multiple rows (e.g. leader of two ministries). The scope
-- columns reference ChurchCRM IDs (church_campus.campus_id, group_grp.grp_ID)
-- but are not enforced as cross-database FKs.
--
-- role:
--   admin             unrestricted within portal scope (still bounded by portal scope!)
--   leader            ministry/function-group leader; scope_ministry_id required
--   scheduler         scheduling rights; scope_ministry_id optional
--   member            base member; sees own assignments only
CREATE TABLE IF NOT EXISTS `portal_user_roles` (
  `role_id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `portal_user_id`     INT UNSIGNED   NOT NULL,
  `role`               ENUM('admin','leader','scheduler','member') NOT NULL,
  `scope_campus_id`    INT            DEFAULT NULL,           -- references church_campus.campus_id
  `scope_ministry_id`  MEDIUMINT      DEFAULT NULL,           -- references group_grp.grp_ID
  `created_at`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `ux_user_role_scope` (`portal_user_id`, `role`, `scope_campus_id`, `scope_ministry_id`),
  KEY `idx_pur_role` (`role`),
  CONSTRAINT `fk_pur_user`
    FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`portal_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- portal_sessions — server-side session tokens. Revocable.
CREATE TABLE IF NOT EXISTS `portal_sessions` (
  `session_token`   CHAR(64)       NOT NULL,                  -- random hex/base64
  `portal_user_id`  INT UNSIGNED   NOT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME       NOT NULL,
  `last_seen_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address`      VARCHAR(45)    DEFAULT NULL,              -- IPv4 or IPv6
  `user_agent`      VARCHAR(255)   DEFAULT NULL,
  `revoked_at`      DATETIME       DEFAULT NULL,
  PRIMARY KEY (`session_token`),
  KEY `idx_ps_user` (`portal_user_id`),
  KEY `idx_ps_expires` (`expires_at`),
  CONSTRAINT `fk_ps_user`
    FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`portal_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- portal_tokens — single-use tokens for password reset, invite, API access.
CREATE TABLE IF NOT EXISTS `portal_tokens` (
  `token`           CHAR(64)       NOT NULL,
  `portal_user_id`  INT UNSIGNED   DEFAULT NULL,              -- nullable for invite-by-email-before-account
  `purpose`         ENUM('password_reset','invite','api') NOT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME       NOT NULL,
  `consumed_at`     DATETIME       DEFAULT NULL,
  `payload_json`    TEXT           DEFAULT NULL,              -- e.g. invite metadata
  PRIMARY KEY (`token`),
  KEY `idx_pt_user` (`portal_user_id`),
  KEY `idx_pt_purpose_expires` (`purpose`, `expires_at`),
  CONSTRAINT `fk_pt_user`
    FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`portal_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- portal_audit_log — every portal-originated write is recorded here.
-- This is portal-scope auditing; ChurchCRM's own audit (if any) remains separate.
CREATE TABLE IF NOT EXISTS `portal_audit_log` (
  `audit_id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actor_user_id`   INT UNSIGNED   DEFAULT NULL,              -- nullable for system actions
  `actor_person_id` MEDIUMINT      DEFAULT NULL,              -- references person_per (informational)
  `action`          VARCHAR(80)    NOT NULL,                  -- e.g. 'schedule.assignments.save'
  `target_type`     VARCHAR(60)    DEFAULT NULL,              -- e.g. 'event_occurrence', 'ministry'
  `target_id`       VARCHAR(80)    DEFAULT NULL,
  `summary`         VARCHAR(255)   DEFAULT NULL,
  `payload_json`    TEXT           DEFAULT NULL,
  `ip_address`      VARCHAR(45)    DEFAULT NULL,
  `user_agent`      VARCHAR(255)   DEFAULT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `idx_pal_at` (`at`),
  KEY `idx_pal_actor_user` (`actor_user_id`),
  KEY `idx_pal_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
