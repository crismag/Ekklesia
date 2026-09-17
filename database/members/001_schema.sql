-- =============================================================================
-- Christlikeness member database — schema v1
--
-- One MySQL/MariaDB database for committed church records: people, where they
-- belong and serve, the church calendar and its serving schedule, and the
-- accounts that sign in. Guests, visitor sign-ups and RSVPs live in the separate
-- visitors database (database/visitors) until they are vetted and promoted.
--
-- Conventions
--   * plural snake_case table names; join tables name both sides
--   * every table has `id` (except pure join tables) and real foreign keys
--   * choices are ENUMs or small named tables, never numbered lists
--   * records people edit carry created_at / updated_at; long-lived records
--     are archived (archived_at) rather than deleted
--   * ids are preserved from the legacy ChurchCRM/portal tables on migration,
--     so photos, links and printed references keep working
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Organisation
-- -----------------------------------------------------------------------------

CREATE TABLE campuses (
  id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                         VARCHAR(150) NOT NULL,
  code                         VARCHAR(40)  NULL,
  address_line1                VARCHAR(150) NULL,
  address_line2                VARCHAR(150) NULL,
  city                         VARCHAR(100) NULL,
  region                       VARCHAR(50)  NULL,
  postal_code                  VARCHAR(20)  NULL,
  country                      VARCHAR(100) NULL,
  phone                        VARCHAR(50)  NULL,
  email                        VARCHAR(120) NULL,
  website                      VARCHAR(200) NULL,
  time_zone                    VARCHAR(64)  NOT NULL DEFAULT 'America/Toronto',
  latitude                     DECIMAL(10,7) NULL,
  longitude                    DECIMAL(10,7) NULL,
  is_main                      TINYINT(1)   NOT NULL DEFAULT 0,
  is_active                    TINYINT(1)   NOT NULL DEFAULT 1,
  -- The event the serving schedule opens on for this campus (usually Sunday service).
  default_scheduling_event_id  INT UNSIGNED NULL,
  notes                        TEXT NULL,
  created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campuses_name (name),
  CONSTRAINT fk_campuses_default_event FOREIGN KEY (default_scheduling_event_id) REFERENCES events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Radical, Trailblazer, G&A: the church's life-stage grouping of members.
CREATE TABLE member_types (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(60) NOT NULL,
  description  VARCHAR(255) NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A person's standing with the church (Member, Regular Attender, Guest, …).
-- Administrators edit this list on the Options page.
CREATE TABLE membership_statuses (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(60) NOT NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_membership_statuses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How a person relates to their household (Husband, Wife, Child, …).
-- Administrators edit this list on the Options page.
CREATE TABLE household_roles (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(60) NOT NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_household_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ministries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  short_name   VARCHAR(40)  NULL,
  slug         VARCHAR(100) NOT NULL,
  description  TEXT NULL,
  -- NULL means the ministry serves every campus.
  campus_id    INT UNSIGNED NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ministries_slug (slug),
  KEY ix_ministries_campus (campus_id),
  CONSTRAINT fk_ministries_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The roles a ministry schedules people into: Server, Set-Up, Teacher - NextGen…
CREATE TABLE serving_roles (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ministry_id         INT UNSIGNED NOT NULL,
  name                VARCHAR(120) NOT NULL,
  description         VARCHAR(255) NULL,
  recommended_count   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  -- Someone in this role cannot also be scheduled elsewhere at the same time.
  blocks_other_roles  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order          SMALLINT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_serving_roles_name (ministry_id, name),
  CONSTRAINT fk_serving_roles_ministry FOREIGN KEY (ministry_id) REFERENCES ministries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- People
-- -----------------------------------------------------------------------------

-- A household groups people who live together. Addresses live on each person:
-- in the legacy data every family address was a copy of its members' addresses.
-- A family as the church records it: its own name, address and home phone,
-- which a member may or may not share (a person can live elsewhere).
CREATE TABLE households (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100) NOT NULL,
  email            VARCHAR(120) NULL,
  home_phone       VARCHAR(40)  NULL,
  address_line1    VARCHAR(150) NULL,
  address_line2    VARCHAR(150) NULL,
  city             VARCHAR(100) NULL,
  region           VARCHAR(50)  NULL,
  postal_code      VARCHAR(20)  NULL,
  country          VARCHAR(60)  NULL,
  latitude         DECIMAL(10,7) NULL,
  longitude        DECIMAL(10,7) NULL,
  wedding_date     DATE NULL,
  send_newsletter  TINYINT(1) NOT NULL DEFAULT 0,
  -- NULL while the family is active.
  deactivated_on   DATE NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_households_name (name),
  KEY ix_households_address (address_line1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Families that belong together although a person is in only one household:
-- a married child's family and the parents', or two families under one roof.
-- For parent_child, household_id is the parents' family.
CREATE TABLE household_links (
  household_id          INT UNSIGNED NOT NULL,
  related_household_id  INT UNSIGNED NOT NULL,
  relationship          ENUM('parent_child','extended','same_residence') NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (household_id, related_household_id),
  KEY ix_household_links_related (related_household_id),
  CONSTRAINT fk_household_links_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_household_links_related FOREIGN KEY (related_household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE people (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name         VARCHAR(60)  NOT NULL,
  middle_name        VARCHAR(60)  NULL,
  last_name          VARCHAR(60)  NOT NULL,
  preferred_name     VARCHAR(60)  NULL,
  suffix             VARCHAR(20)  NULL,
  gender             ENUM('male','female') NULL,
  -- Split so a birthday without a known year can still be kept.
  birth_year         SMALLINT UNSIGNED NULL,
  birth_month        TINYINT UNSIGNED NULL,
  birth_day          TINYINT UNSIGNED NULL,
  email              VARCHAR(120) NULL,
  mobile_phone       VARCHAR(40)  NULL,
  home_phone         VARCHAR(40)  NULL,
  address_line1      VARCHAR(150) NULL,
  address_line2      VARCHAR(150) NULL,
  city               VARCHAR(100) NULL,
  region             VARCHAR(50)  NULL,
  postal_code        VARCHAR(20)  NULL,
  country            VARCHAR(60)  NULL,
  latitude           DECIMAL(10,7) NULL,
  longitude          DECIMAL(10,7) NULL,
  campus_id          INT UNSIGNED NULL,
  household_id       INT UNSIGNED NULL,
  household_role_id  TINYINT UNSIGNED NULL,
  member_type_id     TINYINT UNSIGNED NULL,
  membership_status_id TINYINT UNSIGNED NULL,
  member_since       DATE NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at        DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_people_name (last_name, first_name),
  KEY ix_people_email (email),
  KEY ix_people_campus (campus_id),
  KEY ix_people_household (household_id),
  KEY ix_people_birthday (birth_month, birth_day),
  CONSTRAINT fk_people_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE SET NULL,
  CONSTRAINT fk_people_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE SET NULL,
  CONSTRAINT fk_people_member_type FOREIGN KEY (member_type_id) REFERENCES member_types (id) ON DELETE SET NULL,
  CONSTRAINT fk_people_household_role FOREIGN KEY (household_role_id) REFERENCES household_roles (id) ON DELETE SET NULL,
  CONSTRAINT fk_people_membership_status FOREIGN KEY (membership_status_id) REFERENCES membership_statuses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who belongs to which ministry. Leading is a role here, not a second table.
CREATE TABLE ministry_members (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ministry_id  INT UNSIGNED NOT NULL,
  person_id    INT UNSIGNED NOT NULL,
  role         ENUM('member','leader') NOT NULL DEFAULT 'member',
  status       ENUM('pending','confirmed','ended') NOT NULL DEFAULT 'confirmed',
  started_on   DATE NULL,
  ended_on     DATE NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ministry_members (ministry_id, person_id),
  KEY ix_ministry_members_person (person_id),
  CONSTRAINT fk_ministry_members_ministry FOREIGN KEY (ministry_id) REFERENCES ministries (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ministry_members_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a member does inside the ministry ("Usher", "Emcee"), as the church's
-- sheet records it. A label for people, not a scheduling rule: the schedule
-- uses serving_roles. A member may hold several.
CREATE TABLE ministry_member_positions (
  ministry_member_id  INT UNSIGNED NOT NULL,
  name                VARCHAR(60) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ministry_member_id, name),
  CONSTRAINT fk_ministry_member_positions_member FOREIGN KEY (ministry_member_id) REFERENCES ministry_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Calendar and serving schedule
-- -----------------------------------------------------------------------------

CREATE TABLE event_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64) NOT NULL,
  name        VARCHAR(100) NOT NULL,
  -- Who may see events of this type.
  audience    ENUM('public','members','leaders') NOT NULL DEFAULT 'members',
  color       CHAR(7) NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An event is the series. Its repeat rule generates event_occurrences, one row
-- per date, which assignments and changes to a single date attach to.
CREATE TABLE events (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type_id          INT UNSIGNED NOT NULL,
  ministry_id            INT UNSIGNED NULL,
  title                  VARCHAR(255) NOT NULL,
  summary                VARCHAR(255) NULL,
  details                TEXT NULL,
  location_name          VARCHAR(255) NULL,
  location_address       VARCHAR(255) NULL,
  web_link               VARCHAR(500) NULL,
  contact_person_id      INT UNSIGNED NULL,
  starts_on              DATE NOT NULL,
  start_time             TIME NULL,
  end_time               TIME NULL,
  all_day                TINYINT(1) NOT NULL DEFAULT 0,
  repeat_frequency       ENUM('none','daily','weekly','biweekly','monthly','yearly') NOT NULL DEFAULT 'none',
  repeat_interval        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  repeat_weekdays        VARCHAR(20) NULL,
  repeat_week_of_month   TINYINT NULL,
  repeat_until           DATE NULL,
  repeat_count           SMALLINT UNSIGNED NULL,
  -- Whether the serving schedule offers this event for assignments.
  uses_serving_schedule  TINYINT(1) NOT NULL DEFAULT 0,
  -- Where the event was created, and its id there (e.g. an Oikonomia schedule
  -- entry), so a calendar sync can find it again.
  source_app             VARCHAR(32) NOT NULL DEFAULT 'portal',
  external_id            VARCHAR(64) NULL,
  is_active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at            DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_external (source_app, external_id),
  KEY ix_events_type (event_type_id),
  KEY ix_events_ministry (ministry_id),
  CONSTRAINT fk_events_type FOREIGN KEY (event_type_id) REFERENCES event_types (id) ON DELETE RESTRICT,
  CONSTRAINT fk_events_ministry FOREIGN KEY (ministry_id) REFERENCES ministries (id) ON DELETE SET NULL,
  CONSTRAINT fk_events_contact FOREIGN KEY (contact_person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_campuses (
  event_id   INT UNSIGNED NOT NULL,
  campus_id  INT UNSIGNED NOT NULL,
  is_host    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (event_id, campus_id),
  CONSTRAINT fk_event_campuses_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_event_campuses_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_tags (
  event_id  INT UNSIGNED NOT NULL,
  slug      VARCHAR(64) NOT NULL,
  label     VARCHAR(64) NOT NULL,
  PRIMARY KEY (event_id, slug),
  KEY ix_event_tags_slug (slug),
  CONSTRAINT fk_event_tags_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per date. A change to one date edits this row; the id never changes,
-- so assignments stay attached. Occurrences are cancelled, not deleted, once
-- anyone is scheduled on them.
CREATE TABLE event_occurrences (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id            INT UNSIGNED NOT NULL,
  original_starts_at  DATETIME NOT NULL,
  starts_at           DATETIME NOT NULL,
  ends_at             DATETIME NOT NULL,
  status              ENUM('scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
  title_override      VARCHAR(255) NULL,
  details_override    TEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_event_occurrences_original (event_id, original_starts_at),
  KEY ix_event_occurrences_starts (starts_at),
  CONSTRAINT fk_event_occurrences_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assignments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurrence_id     INT UNSIGNED NOT NULL,
  serving_role_id   INT UNSIGNED NOT NULL,
  -- NULL with no assignee_name means the role is still open on that date.
  person_id         INT UNSIGNED NULL,
  -- A helper who is not in the database, typed on the serving grid.
  assignee_name     VARCHAR(255) NULL,
  status            ENUM('open','assigned','confirmed','declined','completed') NOT NULL DEFAULT 'open',
  notes             TEXT NULL,
  assigned_at       DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_assignments_occurrence (occurrence_id),
  KEY ix_assignments_person (person_id),
  KEY ix_assignments_role (serving_role_id),
  CONSTRAINT fk_assignments_occurrence FOREIGN KEY (occurrence_id) REFERENCES event_occurrences (id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_role FOREIGN KEY (serving_role_id) REFERENCES serving_roles (id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dates a person cannot serve.
-- A printable serving roster for a date range: dated or weekday slots, each
-- with the people (or free-text names) serving in it. Used by /rosters and the
-- ministry schedule printable.
CREATE TABLE rosters (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title                  VARCHAR(180) NOT NULL,
  subtitle               VARCHAR(255) NULL,
  ministry_id            INT UNSIGNED NULL,
  campus_id              INT UNSIGNED NULL,
  starts_on              DATE NOT NULL,
  ends_on                DATE NOT NULL,
  notes                  TEXT NULL,
  is_published           TINYINT(1) NOT NULL DEFAULT 1,
  created_by_account_id  INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rosters_dates (starts_on, ends_on),
  KEY ix_rosters_ministry (ministry_id),
  CONSTRAINT fk_rosters_ministry FOREIGN KEY (ministry_id) REFERENCES ministries (id) ON DELETE SET NULL,
  CONSTRAINT fk_rosters_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE SET NULL,
  CONSTRAINT fk_rosters_account FOREIGN KEY (created_by_account_id) REFERENCES user_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roster_slots (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  roster_id        INT UNSIGNED NOT NULL,
  slot_date        DATE NULL,
  -- Day of week (0 = Sunday) for a slot that repeats every week of the roster.
  slot_weekday     TINYINT NULL,
  label            VARCHAR(180) NULL,
  location         VARCHAR(255) NULL,
  serving_role_id  INT UNSIGNED NULL,
  sort_order       INT NOT NULL DEFAULT 0,
  notes            TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_roster_slots_roster (roster_id, sort_order),
  KEY ix_roster_slots_date (slot_date),
  CONSTRAINT fk_roster_slots_roster FOREIGN KEY (roster_id) REFERENCES rosters (id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_slots_role FOREIGN KEY (serving_role_id) REFERENCES serving_roles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roster_assignments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_id       INT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED NULL,
  -- Shown when the person is not in the database, or to override their name.
  display_name  VARCHAR(255) NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_roster_assignments_slot (slot_id, sort_order),
  KEY ix_roster_assignments_person (person_id),
  CONSTRAINT fk_roster_assignments_slot FOREIGN KEY (slot_id) REFERENCES roster_slots (id) ON DELETE CASCADE,
  CONSTRAINT fk_roster_assignments_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE unavailability (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id              INT UNSIGNED NOT NULL,
  starts_on              DATE NOT NULL,
  ends_on                DATE NOT NULL,
  reason                 VARCHAR(255) NULL,
  created_by_account_id  INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_unavailability_person (person_id, starts_on),
  CONSTRAINT fk_unavailability_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE,
  CONSTRAINT fk_unavailability_account FOREIGN KEY (created_by_account_id) REFERENCES user_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Accounts and access
-- -----------------------------------------------------------------------------

CREATE TABLE user_accounts (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- The person this login belongs to. Not unique yet: legacy data has test
  -- logins sharing a person (see the migration report).
  person_id             INT UNSIGNED NULL,
  email                 VARCHAR(190) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  display_name          VARCHAR(100) NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at         DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_accounts_email (email),
  KEY ix_user_accounts_person (person_id),
  CONSTRAINT fk_user_accounts_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What an account may do, optionally within one campus or ministry.
CREATE TABLE account_roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id   INT UNSIGNED NOT NULL,
  role         ENUM('admin','leader','scheduler','member') NOT NULL,
  campus_id    INT UNSIGNED NULL,
  ministry_id  INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_account_roles_account (account_id),
  CONSTRAINT fk_account_roles_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_account_roles_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE CASCADE,
  CONSTRAINT fk_account_roles_ministry FOREIGN KEY (ministry_id) REFERENCES ministries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_sessions (
  token_hash    CHAR(64) NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  revoked_at    DATETIME NULL,
  PRIMARY KEY (token_hash),
  KEY ix_account_sessions_account (account_id),
  CONSTRAINT fk_account_sessions_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_tokens (
  token_hash   CHAR(64) NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  purpose      ENUM('password_reset','invite','api') NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME NOT NULL,
  used_at      DATETIME NULL,
  payload      JSON NULL,
  PRIMARY KEY (token_hash),
  KEY ix_account_tokens_account (account_id),
  CONSTRAINT fk_account_tokens_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved filters for the calendar page.
CREATE TABLE calendar_views (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  visibility      ENUM('private','shared') NOT NULL DEFAULT 'private',
  config_version  SMALLINT NOT NULL DEFAULT 1,
  config          JSON NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_calendar_views_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Member spreadsheet import (the church's Excel format), and history
-- -----------------------------------------------------------------------------

CREATE TABLE member_import_batches (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campus_id              INT UNSIGNED NULL,
  status                 ENUM('staging','applied') NOT NULL DEFAULT 'staging',
  source_label           VARCHAR(190) NULL,
  -- The workbook's two campus sheets, by name, and when each was last updated.
  hub_sheet              VARCHAR(120) NULL,
  ny_sheet               VARCHAR(120) NULL,
  hub_updated            VARCHAR(190) NULL,
  ny_updated             VARCHAR(190) NULL,
  warnings               JSON NULL,
  duplicate_report       JSON NULL,
  created_by_account_id  INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at             DATETIME NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_member_import_batches_campus FOREIGN KEY (campus_id) REFERENCES campuses (id) ON DELETE SET NULL,
  CONSTRAINT fk_member_import_batches_account FOREIGN KEY (created_by_account_id) REFERENCES user_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_import_rows (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id           INT UNSIGNED NOT NULL,
  last_name          VARCHAR(60) NULL,
  first_name         VARCHAR(60) NULL,
  middle_name        VARCHAR(60) NULL,
  preferred_name     VARCHAR(60) NULL,
  email              VARCHAR(120) NULL,
  phone              VARCHAR(40) NULL,
  address_raw        VARCHAR(255) NULL,
  address_line1      VARCHAR(150) NULL,
  city               VARCHAR(100) NULL,
  region             VARCHAR(50) NULL,
  postal_code        VARCHAR(20) NULL,
  country            VARCHAR(60) NULL,
  birth_year         SMALLINT UNSIGNED NULL,
  birth_month        TINYINT UNSIGNED NULL,
  birth_day          TINYINT UNSIGNED NULL,
  member_since       VARCHAR(40) NULL,
  member_type        VARCHAR(60) NULL,
  ministry           VARCHAR(255) NULL,
  confirmed          VARCHAR(40) NULL,
  source             VARCHAR(60) NULL,
  filled_from        VARCHAR(255) NULL,
  status             ENUM('draft','ready','skip','applied') NOT NULL DEFAULT 'draft',
  matched_person_id  INT UNSIGNED NULL,
  notes              TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_member_import_rows_batch (batch_id),
  CONSTRAINT fk_member_import_rows_batch FOREIGN KEY (batch_id) REFERENCES member_import_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_import_rows_person FOREIGN KEY (matched_person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  account_id   INT UNSIGNED NULL,
  person_id    INT UNSIGNED NULL,
  action       VARCHAR(80) NOT NULL,
  target_type  VARCHAR(60) NULL,
  target_id    VARCHAR(80) NULL,
  summary      VARCHAR(255) NULL,
  details      JSON NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_audit_log_target (target_type, target_id),
  KEY ix_audit_log_occurred (occurred_at),
  CONSTRAINT fk_audit_log_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_log_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which files in database/members/migrations have been applied (tools/migrate.php).
CREATE TABLE schema_migrations (
  filename    VARCHAR(190) NOT NULL,
  checksum    CHAR(64) NOT NULL,
  applied_at  DATETIME NOT NULL,
  adopted     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  `key`       VARCHAR(100) NOT NULL,
  value       JSON NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
