-- @connection: churchcrm
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
--
-- Calendar inclusion and assignment-scheduling inclusion are separate
-- concerns. Until this migration, the assignment scheduler loaded every
-- campus-valid occurrence in the date range, so adding ordinary calendar
-- activities flooded the grid.
--
-- Two explicit properties replace title matching:
--   events_event.assignment_scheduling_enabled
--     The event may appear in the scheduler's available-event pool.
--   church_campus.default_assignment_event_id
--     Which eligible event is selected when Assignment Scheduling opens
--     for that campus (typically that campus's Sunday Service).
--
-- Ministries live in the portal database while events and campuses live
-- alongside person_per, so default_assignment_event_id is a plain nullable
-- id rather than a foreign key — the same posture as events_event.ministry_id
-- in 008.
--
-- The Sunday Service title match below is a one-time bootstrap heuristic.
-- Runtime scheduling must not recognise events by name.
--
-- Idempotent and safe to re-run. Uses MariaDB's IF NOT EXISTS rather than a
-- PREPARE/EXECUTE block: the block is correct when piped to the mysql client
-- but breaks any runner that splits the file on semicolons.

ALTER TABLE `events_event`
  ADD COLUMN IF NOT EXISTS `assignment_scheduling_enabled` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `ministry_id`;

CREATE INDEX IF NOT EXISTS `idx_events_assignment_scheduling`
  ON `events_event` (`assignment_scheduling_enabled`);

ALTER TABLE `church_campus`
  ADD COLUMN IF NOT EXISTS `default_assignment_event_id` INT NULL DEFAULT NULL
  AFTER `is_main`;

CREATE INDEX IF NOT EXISTS `idx_campus_default_assignment_event`
  ON `church_campus` (`default_assignment_event_id`);

-- Bootstrap: existing Sunday Service events become eligible. The LIKE is
-- confined to this migration; it is not a runtime rule. Already-enabled
-- rows are left alone so an administrator's later "off" is not overwritten
-- if this file is reapplied after a manual rollback of the history row.
UPDATE `events_event`
   SET `assignment_scheduling_enabled` = 1
 WHERE `assignment_scheduling_enabled` = 0
   AND LOWER(`event_title`) LIKE '%sunday service%';

-- Each campus's default is the lowest-id eligible Sunday Service linked to
-- that campus, and only when no default has been configured yet.
UPDATE `church_campus` c
  JOIN (
    SELECT ec.campus_id, MIN(e.event_id) AS event_id
      FROM `events_event` e
      JOIN `events_event_campus` ec ON ec.event_id = e.event_id
     WHERE e.assignment_scheduling_enabled = 1
       AND LOWER(e.event_title) LIKE '%sunday service%'
     GROUP BY ec.campus_id
  ) picked ON picked.campus_id = c.campus_id
   SET c.default_assignment_event_id = picked.event_id
 WHERE c.default_assignment_event_id IS NULL;
