-- @connection: churchcrm
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- Give event types a portal-side identity: an audience, a colour, a calendar
-- layer slug and a sort order.
--
-- events_event.event_type and the event_types table have existed since
-- ChurchCRM, but the portal ignored both: ChurchCrmEventAdapter::createEvent()
-- omitted event_type from its INSERT, so every portal-created event carries
-- event_type = 0 — an id matching no row. There was therefore no way to say
-- "this is a leadership meeting" and no way to keep one off a member's
-- calendar.
--
-- These columns extend the ChurchCRM table rather than living in a parallel
-- portal table because the audience filter has to be a WHERE clause on the
-- same query that reads events_event. Cross-database joins are impossible
-- (see 008), and a PHP-side post-filter is a rule that a future query can
-- silently forget — which is precisely the failure mode this feature exists
-- to prevent. Every column is additive and nullable, so ChurchCRM's own
-- EditEventTypes.php keeps working untouched.
--
-- portal_audience is VARCHAR rather than ENUM on purpose: an ENUM would make
-- adding a future audience level an ALTER TABLE on a table ChurchCRM also
-- owns. The permitted values are validated in EventTypeService.
--
-- Idempotent and safe to re-run. Uses MariaDB's IF NOT EXISTS rather than a
-- PREPARE/EXECUTE block: the block is correct when piped to the mysql client
-- but breaks any runner that splits the file on semicolons, which is how 008
-- failed on first application to production.

ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_slug`       VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_label`      VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_audience`   VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_color`      CHAR(7)     NULL DEFAULT NULL;
ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_sort`       SMALLINT    NULL DEFAULT NULL;
ALTER TABLE `event_types` ADD COLUMN IF NOT EXISTS `portal_is_default` TINYINT(1)  NULL DEFAULT NULL;

-- portal_slug is the calendar layer key and is rendered into a CSS class name,
-- so it must be unique and constrained to [a-z0-9-] (enforced on write). NULL
-- is allowed and repeatable, which is what every ChurchCRM-only type holds.
CREATE UNIQUE INDEX IF NOT EXISTS `uq_event_types_portal_slug` ON `event_types` (`portal_slug`);
CREATE INDEX IF NOT EXISTS `idx_event_types_portal_audience` ON `event_types` (`portal_audience`);

-- The audience predicate joins event_types on every event read path, so the
-- foreign-key-by-convention column needs an index of its own.
CREATE INDEX IF NOT EXISTS `idx_events_event_type` ON `events_event` (`event_type`);

-- Types 1 and 2 already exist (Church Service, Sunday School). They are MAPPED
-- into portal layers, not duplicated: inserting a second "Church Service"
-- would leave every ChurchCRM-side event pointing at the original while the
-- portal used the copy. The IS NULL guard is what makes this re-runnable
-- without stamping over an admin's later edits.
UPDATE `event_types`
   SET `portal_slug` = 'general', `portal_label` = 'General',
       `portal_audience` = 'members', `portal_color` = '#2c6ea5',
       `portal_sort` = 10, `portal_is_default` = 1
 WHERE `type_id` = 1 AND `portal_slug` IS NULL;

UPDATE `event_types`
   SET `portal_slug` = 'sunday-school', `portal_label` = 'Sunday School',
       `portal_audience` = 'members', `portal_color` = '#5b6d8a',
       `portal_sort` = 40, `portal_is_default` = 0
 WHERE `type_id` = 2 AND `portal_slug` IS NULL;

-- Ministry and Leadership share the 'leaders' audience: they are the same
-- access level (both are for ministry leaders) and differ only as categories
-- for filtering a calendar or a listing.
-- INSERT IGNORE is idempotent against uq_event_types_portal_slug.
INSERT IGNORE INTO `event_types`
  (`type_name`, `type_defrecurtype`, `type_active`,
   `portal_slug`, `portal_label`, `portal_audience`, `portal_color`, `portal_sort`, `portal_is_default`)
VALUES
  ('Ministry event', 'none', 1, 'ministry',   'Ministry events', 'leaders', '#117b6d', 20, 0),
  ('Leadership',     'none', 1, 'leadership', 'Leadership',      'leaders', '#7b2445', 30, 0);

-- Backfill. Every portal-created event carries event_type = 0, and a type row
-- deleted in ChurchCRM leaves the same dangling state. Both become the default
-- type.
--
-- This is not cosmetic: once the calendar's source key becomes
-- events:<slug>, an event whose type resolves to nothing lands in no layer at
-- all, so the entire back catalogue would disappear from the calendar the
-- moment layer filtering goes live.
--
-- General/members is the conservative landing place — these events were
-- already visible to everyone, so it exposes nothing and hides nothing. Events
-- that ought to be Leadership must be re-typed by hand; no title heuristic is
-- trustworthy enough to hide data automatically, and a wrong guess in the
-- hiding direction makes a genuine church-wide event silently vanish.
--
-- LEFT JOIN finds the dangling rows without a subquery against the table being
-- updated, which MariaDB rejects.
UPDATE `events_event` e
  LEFT JOIN `event_types` t ON t.`type_id` = e.`event_type`
       JOIN `event_types` d ON d.`portal_is_default` = 1
   SET e.`event_type` = d.`type_id`
 WHERE t.`type_id` IS NULL;
