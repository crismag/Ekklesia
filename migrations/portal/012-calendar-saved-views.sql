-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database, so the
-- target cannot be inferred from the directory name. Saved views belong to
-- portal_users, so they live in the portal database beside them.

-- A reusable recipe for a printed calendar.
--
-- Not a snapshot. "Scarborough Monthly Ministry Calendar" says *this month*,
-- and reopening it in December must print December — so the configuration
-- stores the date *mode*, never a resolved range. The events themselves are
-- read live at print time and are not stored here at all.
--
-- The configuration is one JSON column rather than forty. These settings are
-- heterogeneous, optional and still growing: a column per checkbox would mean a
-- migration every time somebody adds a toggle, and nothing here is ever
-- filtered or sorted on. The two things that *are* queried — who owns it and
-- who may see it — are real columns with real indexes.
--
-- config_version travels with the row so a future breaking change can be
-- migrated deliberately. Additive settings need no migration at all: the
-- configuration model fills anything absent with its default.

CREATE TABLE IF NOT EXISTS `calendar_saved_view` (
    `view_id`        INT(11)      NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(120) NOT NULL,
    `owner_user_id`  INT(11)      NOT NULL COMMENT 'portal_users.id',
    -- private: the owner only.
    -- shared:  any portal user who may reach the print screen at all.
    -- Two scopes, because a third nobody asked for is a permission model
    -- nobody has tested.
    `visibility`     ENUM('private','shared') NOT NULL DEFAULT 'private',
    `config_version` SMALLINT(6)  NOT NULL DEFAULT 1,
    `config`         MEDIUMTEXT   NOT NULL COMMENT 'JSON; see App\\Services\\Calendar\\PrintConfig',
    `created_at`     DATETIME     NOT NULL,
    `updated_at`     DATETIME     NOT NULL,
    PRIMARY KEY (`view_id`),
    KEY `ix_saved_view_owner` (`owner_user_id`),
    KEY `ix_saved_view_visibility` (`visibility`),
    -- One name per owner: two views both called "Sunday Wall Calendar" in the
    -- same list is a support question waiting to happen.
    UNIQUE KEY `ux_saved_view_owner_name` (`owner_user_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
