-- New-people signup table (flat, migration-friendly). Lives in the ChurchCRM DB
-- alongside person_per so admin review/matching/migration use one connection.
CREATE TABLE IF NOT EXISTS people_signup_temp (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name        VARCHAR(60)  NOT NULL,
    last_name         VARCHAR(60)  NOT NULL,
    middle_name       VARCHAR(60)  NULL,
    nick_name         VARCHAR(60)  NULL,
    gender            TINYINT      NULL,             -- 1=Male 2=Female (ChurchCRM per_Gender)
    email             VARCHAR(120) NULL,
    phone             VARCHAR(40)  NULL,
    birth_year        SMALLINT     NULL,
    birth_month       TINYINT      NULL,
    birth_day         TINYINT      NULL,
    city              VARCHAR(80)  NOT NULL,         -- Town/City
    address           VARCHAR(160) NULL,
    state             VARCHAR(60)  NULL,
    zip               VARCHAR(20)  NULL,
    country           VARCHAR(60)  NULL,
    reason_for_visit  VARCHAR(120) NULL,
    invited_by        VARCHAR(120) NULL,
    church_background VARCHAR(255) NULL,
    visit_notes       VARCHAR(255) NULL,
    source_event_id   INT          NULL,             -- set when created via an RSVP
    source            VARCHAR(30)  NOT NULL DEFAULT 'signup', -- signup | rsvp
    migration_status  ENUM('new','reviewed','migrated','duplicate','rejected') NOT NULL DEFAULT 'new',
    matched_member_id INT          NULL,             -- person_per.per_ID once linked
    admin_notes       TEXT         NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (migration_status),
    INDEX idx_name (last_name, first_name),
    INDEX idx_event (source_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
