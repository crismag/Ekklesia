-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- Portal-owned table: members declare windows in which they are unavailable
-- to serve. Schedulers consult this when assigning roles. Read-only references
-- to ChurchCRM person_per by id; no cross-DB FK.
--
-- Design choices:
--   * Inclusive [starts_on, ends_on] date range (whole-day granularity is
--     enough for scheduling). Sub-day blocks can be added later as a separate
--     time-of-day column pair without breaking this row shape.
--   * `created_by_portal_user_id` records who entered the row, even when
--     a scheduler enters availability on behalf of a member.
--   * No soft-delete; entries are removed outright when no longer relevant.
--     We keep a portal_audit_log row instead (existing table from migration 001).

CREATE TABLE IF NOT EXISTS `portal_unavailability` (
    `unavailability_id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `person_id`                 INT UNSIGNED    NOT NULL,
    `starts_on`                 DATE            NOT NULL,
    `ends_on`                   DATE            NOT NULL,
    `reason`                    VARCHAR(255)        NULL,
    `created_by_portal_user_id` INT UNSIGNED    NOT NULL,
    `created_at`                DATETIME        NOT NULL,
    `updated_at`                DATETIME        NOT NULL,
    PRIMARY KEY (`unavailability_id`),
    KEY `idx_person_range`      (`person_id`, `starts_on`, `ends_on`),
    KEY `idx_ends_on`           (`ends_on`)  -- supports "active going forward" queries
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
