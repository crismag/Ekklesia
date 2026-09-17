-- Optional module-owned events (used only when config event_source = rsvp_events;
-- by default RSVP reads ChurchCRM's events_event table).
CREATE TABLE IF NOT EXISTS rsvp_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    slug        VARCHAR(80)  NULL,
    event_date  DATE         NULL,
    event_time  TIME         NULL,
    location    VARCHAR(160) NULL,
    description TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- RSVP / attendance. Links to an existing member (person_per) OR to a new signup
-- (people_signup_temp). Snapshots keep the record readable if the source changes.
CREATE TABLE IF NOT EXISTS rsvp_attendance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_source        VARCHAR(20)  NOT NULL DEFAULT 'churchcrm',  -- churchcrm | rsvp_events
    event_id            INT          NOT NULL,
    person_type         ENUM('member','visitor','new_signup') NOT NULL,
    member_id           INT          NULL,          -- person_per.per_ID
    visitor_id          INT          NULL,          -- reserved (visitors are person_per cls=Guest)
    signup_id           INT UNSIGNED NULL,          -- people_signup_temp.id
    first_name_snapshot VARCHAR(60)  NULL,
    last_name_snapshot  VARCHAR(60)  NULL,
    city_snapshot       VARCHAR(80)  NULL,
    email_snapshot      VARCHAR(120) NULL,
    phone_snapshot      VARCHAR(40)  NULL,
    rsvp_status         ENUM('yes','no','maybe') NOT NULL DEFAULT 'yes',
    attendance_status   ENUM('registered','checked_in','no_show','cancelled') NOT NULL DEFAULT 'registered',
    party_count         INT          NOT NULL DEFAULT 1,
    notes               VARCHAR(255) NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event (event_source, event_id),
    INDEX idx_member (member_id),
    INDEX idx_signup (signup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
