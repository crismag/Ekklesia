-- @connection: churchcrm
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.

-- Tags for events.
--
-- Event types already answer "what kind of thing is this, and who may see it" —
-- one type per event, and it decides audience. Tags answer a different
-- question, and answer it many times over: this service is Christmas *and*
-- family *and* music. Overloading the type to carry that would mean either one
-- label per event or an audience decided by whichever tag sorted first.
--
-- Two tables rather than a comma-separated column, because the moment tags are
-- worth having they are worth filtering by, and "WHERE tags LIKE '%music%'"
-- matches "musicians", cannot use an index, and has no answer for renaming a
-- tag everywhere at once.
--
-- The slug is what makes a tag one tag. "Christmas", "christmas" and
-- " Christmas " are the same thing to anyone reading a calendar, and a
-- directory that disagrees produces three tags nobody meant to create. The
-- label keeps whatever casing was typed first, so the display stays human.

CREATE TABLE IF NOT EXISTS `event_tag` (
    `tag_id`     INT(11)      NOT NULL AUTO_INCREMENT,
    `tag_slug`   VARCHAR(64)  NOT NULL COMMENT 'lowercased, punctuation collapsed; identity',
    `tag_label`  VARCHAR(64)  NOT NULL COMMENT 'as first typed; display only',
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`tag_id`),
    UNIQUE KEY `ux_event_tag_slug` (`tag_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The join. Deleting an event must not leave its tags attached to nothing, and
-- deleting a tag must not delete the events that carried it — so the rows here
-- go, and nothing else does.
CREATE TABLE IF NOT EXISTS `event_tag_map` (
    `event_id` INT(11) NOT NULL,
    `tag_id`   INT(11) NOT NULL,
    PRIMARY KEY (`event_id`, `tag_id`),
    KEY `ix_event_tag_map_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
