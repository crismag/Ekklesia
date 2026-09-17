-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- Portal-owned leader tags for ministries. Leadership is tracked SEPARATELY
-- from a member's work/assignment role (person2group2role), so tagging a
-- leader never changes their role. A person is a leader of a ministry if a row
-- exists here (in addition to any ChurchCRM role-name-based leaders).
CREATE TABLE IF NOT EXISTS ministry_leaders (
    ministry_group_id MEDIUMINT UNSIGNED NOT NULL,
    person_id         INT NOT NULL,
    tagged_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ministry_group_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
