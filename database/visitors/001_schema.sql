-- =============================================================================
-- Christlikeness visitors database — schema v1 (SQLite)
--
-- Everyone who has not yet become a church record: guest sign-ups, visitor
-- registrations and RSVPs from people who are not members. Kept apart from the
-- member database on purpose: anyone can create these, and nothing here is
-- trusted until a person reviews it.
--
-- When a registration is vetted, it is *promoted*: a person is created (or
-- matched) in the member database, and visitor_promotions records which one.
-- Ids from the member database (people.id, events.id, event_occurrences.id)
-- are stored as plain numbers; there are no cross-database foreign keys.
-- =============================================================================

PRAGMA foreign_keys = ON;

-- A guest or visitor who filled in the sign-up form, registered for an event,
-- or was entered by a greeter.
CREATE TABLE visitor_registrations (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  first_name            TEXT NOT NULL,
  last_name             TEXT NOT NULL,
  middle_name           TEXT,
  preferred_name        TEXT,
  gender                TEXT CHECK (gender IN ('male','female')),
  email                 TEXT,
  phone                 TEXT,
  birth_year            INTEGER,
  birth_month           INTEGER CHECK (birth_month BETWEEN 1 AND 12),
  birth_day             INTEGER CHECK (birth_day BETWEEN 1 AND 31),
  address_line1         TEXT,
  address_line2         TEXT,
  city                  TEXT,
  region                TEXT,
  postal_code           TEXT,
  country               TEXT,
  latitude              REAL,
  longitude             REAL,
  facebook              TEXT,
  linkedin              TEXT,
  twitter               TEXT,
  is_married            INTEGER CHECK (is_married IN (0,1)),
  member_type_name      TEXT,
  reason_for_visit      TEXT,
  invited_by            TEXT,
  church_background     TEXT,
  visit_notes           TEXT,
  -- How they reached us.
  source                TEXT NOT NULL DEFAULT 'signup' CHECK (source IN ('signup','rsvp','greeter','import')),
  -- Member database events.id they signed up at, if any.
  source_event_id       INTEGER,
  -- new → reviewed → promoted, or duplicate / rejected.
  status                TEXT NOT NULL DEFAULT 'new'
                          CHECK (status IN ('new','reviewed','promoted','duplicate','rejected')),
  -- Member database people.id this looks like (duplicate check) or became.
  matched_person_id     INTEGER,
  reviewer_notes        TEXT,
  reviewed_at           TEXT,
  created_at            TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX ix_visitor_registrations_status ON visitor_registrations (status);
CREATE INDEX ix_visitor_registrations_name ON visitor_registrations (last_name, first_name);

-- Someone's answer to "are you coming?" for one event date. Either a visitor
-- registration or a member (member database people.id), with what they gave us.
CREATE TABLE visitor_rsvps (
  id                         INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id                   INTEGER NOT NULL,
  occurrence_id              INTEGER,
  visitor_registration_id    INTEGER REFERENCES visitor_registrations (id) ON DELETE SET NULL,
  person_id                  INTEGER,
  first_name                 TEXT,
  last_name                  TEXT,
  email                      TEXT,
  phone                      TEXT,
  city                       TEXT,
  response                   TEXT NOT NULL DEFAULT 'yes' CHECK (response IN ('yes','no','maybe')),
  attendance                 TEXT NOT NULL DEFAULT 'registered'
                               CHECK (attendance IN ('registered','checked_in','no_show','cancelled')),
  party_size                 INTEGER NOT NULL DEFAULT 1 CHECK (party_size >= 1),
  notes                      TEXT,
  created_at                 TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at                 TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX ix_visitor_rsvps_event ON visitor_rsvps (event_id, occurrence_id);

-- The record of a registration becoming a church record.
CREATE TABLE visitor_promotions (
  id                        INTEGER PRIMARY KEY AUTOINCREMENT,
  visitor_registration_id   INTEGER NOT NULL REFERENCES visitor_registrations (id) ON DELETE RESTRICT,
  -- Member database people.id created or matched.
  person_id                 INTEGER NOT NULL,
  outcome                   TEXT NOT NULL CHECK (outcome IN ('created','matched_existing')),
  promoted_by_account_id    INTEGER,
  promoted_at               TEXT NOT NULL DEFAULT (datetime('now')),
  notes                     TEXT
);

-- Short-lived access codes for the sign-up and RSVP admin screens.
CREATE TABLE visitor_admin_access_codes (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  module      TEXT NOT NULL CHECK (module IN ('signup','rsvp')),
  code        TEXT NOT NULL,
  issued_at   TEXT NOT NULL,
  expires_at  TEXT NOT NULL,
  note        TEXT
);
