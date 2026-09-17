-- @connection: churchcrm
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.

-- Let a schedule say "first Sunday of every month".
--
-- This is the pattern a church wants most and the one event_recurrence could
-- not express. Its recurrence_type enum offers monthly, and
-- recurrence_days_of_week offers a weekday, but nothing says *which* of that
-- weekday in the month — there is no BYSETPOS equivalent. So a monthly rule
-- could only mean "the same date each month", and Communion Sunday, which
-- moves between the 1st and the 7th, had no representation at all.
--
-- Rather than offer the preset and quietly store something else, the portal
-- left it out until the column existed. This is that column.
--
-- 1..4 select the first through fourth of that weekday in the month. -1 means
-- the last, which is not the same as the fourth: some months have five
-- Sundays. NULL keeps the existing meaning of a monthly rule, "the same date
-- each month", so every row already stored keeps behaving exactly as it did.

ALTER TABLE event_recurrence
    ADD COLUMN recurrence_week_of_month TINYINT NULL DEFAULT NULL
        COMMENT '1-4 = nth weekday of the month, -1 = last, NULL = same date each month'
        AFTER recurrence_days_of_week;
