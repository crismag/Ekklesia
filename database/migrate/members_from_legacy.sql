-- =============================================================================
-- Move the legacy records into the member database.
--
-- Reads (never writes) the two legacy databases:
--   {{CRM}}     ChurchCRM-based database (people, families, groups, events…)
--   {{PORTAL}}  portal database (logins, roles, availability, sheet tables)
-- and fills {{MEMBERS}}, created from database/members/001_schema.sql.
--
-- Ids are preserved. References that point at nothing are dropped rather than
-- guessed; database/verify/members.sql reports every count so nothing is lost
-- silently. Run with database/migrate/run.sh.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
USE {{MEMBERS}};

-- Empty strings in the legacy data mean "not recorded".
DELIMITER //
CREATE OR REPLACE FUNCTION blank_to_null(v TEXT) RETURNS TEXT DETERMINISTIC
BEGIN
  RETURN NULLIF(TRIM(v), '');
END //
DELIMITER ;

-- ---------------------------------------------------------------- campuses
INSERT INTO campuses (id, name, code, address_line1, address_line2, city, region, postal_code, country,
                      phone, email, website, time_zone, latitude, longitude, is_main, is_active, notes,
                      created_at, updated_at)
SELECT campus_id, campus_name, blank_to_null(campus_code), blank_to_null(address1), blank_to_null(address2),
       blank_to_null(city), blank_to_null(state), blank_to_null(zip), blank_to_null(country),
       blank_to_null(phone), blank_to_null(email), blank_to_null(website),
       COALESCE(blank_to_null(time_zone), 'America/Toronto'),
       NULLIF(latitude, 0), NULLIF(longitude, 0), is_main, is_active, blank_to_null(notes),
       COALESCE(date_entered, NOW()), COALESCE(date_last_edited, date_entered, NOW())
FROM {{CRM}}.church_campus;

-- ------------------------------------------------------------ member types
-- Legacy: the "Member Type" custom field, whose choices are list_lst list 13.
INSERT INTO member_types (id, name, sort_order)
SELECT lst_OptionID, lst_OptionName, lst_OptionSequence
FROM {{CRM}}.list_lst WHERE lst_ID = 13;

-- ------------------------------------------- membership statuses, household roles
-- Legacy list_lst lists 1 (classifications) and 2 (family roles).
INSERT INTO membership_statuses (id, name, sort_order)
SELECT lst_OptionID, lst_OptionName, lst_OptionSequence FROM {{CRM}}.list_lst WHERE lst_ID = 1;

INSERT INTO household_roles (id, name, sort_order)
SELECT lst_OptionID, lst_OptionName, lst_OptionSequence FROM {{CRM}}.list_lst WHERE lst_ID = 2;

-- -------------------------------------------------------------- ministries
-- Legacy: group_grp, marked as a ministry in ministry_registry. A group the
-- registry does not mark (a test group) is kept but inactive.
INSERT INTO ministries (id, name, short_name, slug, description, campus_id, is_active)
SELECT g.grp_ID,
       g.grp_Name,
       (SELECT blank_to_null(t.short_name) FROM {{PORTAL}}.ministry_tbl t WHERE t.name = g.grp_Name LIMIT 1),
       TRIM(BOTH '-' FROM LOWER(REGEXP_REPLACE(TRIM(g.grp_Name), '[^A-Za-z0-9]+', '-'))),
       blank_to_null(g.grp_Description),
       r.campus_id,
       CASE WHEN r.is_ministry = 1 AND COALESCE(g.grp_active, 1) = 1 THEN 1 ELSE 0 END
FROM {{CRM}}.group_grp g
LEFT JOIN {{CRM}}.ministry_registry r ON r.group_id = g.grp_ID;

-- ----------------------------------------------------------- serving roles
INSERT INTO serving_roles (id, ministry_id, name, description, recommended_count, blocks_other_roles,
                           sort_order, is_active)
SELECT r.role_id, r.ministry_group_id, r.role_name, blank_to_null(r.role_description),
       GREATEST(COALESCE(r.recommended_count, 1), 1), COALESCE(r.is_blocking, 1),
       COALESCE(r.role_order, 0), COALESCE(r.active, 1)
FROM {{CRM}}.roles r
WHERE EXISTS (SELECT 1 FROM ministries m WHERE m.id = r.ministry_group_id);

-- -------------------------------------------------------------- households
INSERT INTO households (id, name, email, home_phone, address_line1, address_line2, city, region, postal_code, country,
                        latitude, longitude, wedding_date, send_newsletter, deactivated_on, created_at, updated_at)
SELECT fam_ID, fam_Name, blank_to_null(fam_Email), blank_to_null(fam_HomePhone),
       blank_to_null(fam_Address1), blank_to_null(fam_Address2), blank_to_null(fam_City), blank_to_null(fam_State),
       blank_to_null(fam_Zip), blank_to_null(fam_Country), NULLIF(fam_Latitude, 0), NULLIF(fam_Longitude, 0),
       fam_WeddingDate, CASE WHEN UPPER(fam_SendNewsLetter) = 'TRUE' THEN 1 ELSE 0 END, fam_DateDeactivated,
       COALESCE(fam_DateEntered, NOW()), COALESCE(fam_DateLastEdited, fam_DateEntered, NOW())
FROM {{CRM}}.family_fam;

-- ------------------------------------------------------------------ people
-- Each person keeps their own address and phones; the household keeps its own.
INSERT INTO people (id, first_name, middle_name, last_name, suffix, gender,
                    birth_year, birth_month, birth_day, email, mobile_phone, home_phone,
                    address_line1, address_line2, city, region, postal_code, country,
                    campus_id, household_id, household_role_id, member_type_id, membership_status_id, member_since,
                    created_at, updated_at)
SELECT p.per_ID,
       COALESCE(blank_to_null(p.per_FirstName), '(no first name)'),
       blank_to_null(p.per_MiddleName),
       COALESCE(blank_to_null(p.per_LastName), '(no last name)'),
       blank_to_null(p.per_Suffix),
       CASE p.per_Gender WHEN 1 THEN 'male' WHEN 2 THEN 'female' END,
       NULLIF(p.per_BirthYear, 0), NULLIF(p.per_BirthMonth, 0), NULLIF(p.per_BirthDay, 0),
       blank_to_null(p.per_Email), blank_to_null(p.per_CellPhone), blank_to_null(p.per_HomePhone),
       blank_to_null(p.per_Address1), blank_to_null(p.per_Address2), blank_to_null(p.per_City),
       blank_to_null(p.per_State), blank_to_null(p.per_Zip), blank_to_null(p.per_Country),
       (SELECT a.campus_id FROM {{CRM}}.person_campus_affiliation a
         WHERE a.person_id = p.per_ID ORDER BY a.is_primary DESC, a.display_order, a.affiliation_id LIMIT 1),
       (SELECT h.id FROM households h WHERE h.id = p.per_fam_ID),
       (SELECT r.id FROM household_roles r WHERE r.id = p.per_fmr_ID),
       (SELECT mt.id FROM member_types mt WHERE mt.id = pc.c1),
       (SELECT st.id FROM membership_statuses st WHERE st.id = p.per_cls_ID),
       p.per_MembershipDate,
       COALESCE(p.per_DateEntered, NOW()),
       COALESCE(p.per_DateLastEdited, p.per_DateEntered, NOW())
FROM {{CRM}}.person_per p
LEFT JOIN {{CRM}}.person_custom pc ON pc.per_ID = p.per_ID;

-- -------------------------------------------------------- ministry members
-- Legacy: person2group2role_p2g2r (role 2 = Leader in the group's role list)
-- plus the portal's separate ministry_leaders tags.
INSERT INTO ministry_members (ministry_id, person_id, role, status)
SELECT x.p2g2r_grp_ID, x.p2g2r_per_ID, CASE WHEN x.p2g2r_rle_ID = 2 THEN 'leader' ELSE 'member' END, 'confirmed'
FROM {{CRM}}.person2group2role_p2g2r x
WHERE EXISTS (SELECT 1 FROM ministries m WHERE m.id = x.p2g2r_grp_ID)
  AND EXISTS (SELECT 1 FROM people p WHERE p.id = x.p2g2r_per_ID);

INSERT INTO ministry_members (ministry_id, person_id, role, status)
SELECT l.ministry_group_id, l.person_id, 'leader', 'confirmed'
FROM {{PORTAL}}.ministry_leaders l
WHERE EXISTS (SELECT 1 FROM ministries m WHERE m.id = l.ministry_group_id)
  AND EXISTS (SELECT 1 FROM people p WHERE p.id = l.person_id)
ON DUPLICATE KEY UPDATE role = 'leader';

-- Positions exist only in the church's sheet (member_group_and_ministry_roles_tbl),
-- which numbers people and ministries its own way. People are matched by
-- name, ministries by name; a row that matches nothing is reported by the
-- verification, not guessed.
INSERT IGNORE INTO ministry_member_positions (ministry_member_id, name)
SELECT mm.id, TRIM(s.member_group_associations)
FROM {{PORTAL}}.member_group_and_ministry_roles_tbl s
JOIN {{PORTAL}}.christlikeness_people_tbl c ON c.people_id = s.people_id
JOIN {{PORTAL}}.ministry_tbl t              ON t.ministry_id = s.ministry_id
JOIN ministries m                           ON m.name = t.name
JOIN people p ON LOWER(TRIM(p.first_name)) = LOWER(TRIM(c.first_name))
             AND LOWER(TRIM(p.last_name))  = LOWER(TRIM(c.last_name))
JOIN ministry_members mm ON mm.ministry_id = m.id AND mm.person_id = p.id
WHERE blank_to_null(s.member_group_associations) IS NOT NULL;

-- ------------------------------------------------------------- event types
INSERT INTO event_types (id, slug, name, audience, color, sort_order, is_default, is_active)
SELECT t.type_id,
       COALESCE(blank_to_null(t.portal_slug), TRIM(BOTH '-' FROM LOWER(REGEXP_REPLACE(t.type_name, '[^A-Za-z0-9]+', '-')))),
       COALESCE(blank_to_null(t.portal_label), t.type_name),
       CASE WHEN t.portal_audience IN ('public','members','leaders') THEN t.portal_audience ELSE 'members' END,
       blank_to_null(t.portal_color), COALESCE(t.portal_sort, 0), COALESCE(t.portal_is_default, 0), t.type_active
FROM {{CRM}}.event_types t;

-- ------------------------------------------------------------------ events
-- The legacy series row plus its event_recurrence rule, merged.
INSERT INTO events (id, event_type_id, ministry_id, title, summary, details, location_name, location_address,
                    web_link, contact_person_id, starts_on, start_time, end_time, all_day,
                    repeat_frequency, repeat_interval, repeat_weekdays, repeat_week_of_month, repeat_until, repeat_count,
                    uses_serving_schedule, source_app, is_active)
SELECT e.event_id, e.event_type,
       (SELECT m.id FROM ministries m WHERE m.id = e.ministry_id),
       e.event_title, blank_to_null(e.event_desc), blank_to_null(e.event_text),
       blank_to_null(e.custom_location_name), blank_to_null(e.custom_location_address),
       blank_to_null(e.event_url),
       (SELECT p.id FROM people p WHERE p.id = e.primary_contact_person_id),
       DATE(e.event_start),
       CASE WHEN TIME(e.event_start) = '00:00:00' AND TIME(e.event_end) = '00:00:00' THEN NULL ELSE TIME(e.event_start) END,
       CASE WHEN TIME(e.event_start) = '00:00:00' AND TIME(e.event_end) = '00:00:00' THEN NULL ELSE TIME(e.event_end) END,
       CASE WHEN TIME(e.event_start) = '00:00:00' AND TIME(e.event_end) = '00:00:00' THEN 1 ELSE 0 END,
       COALESCE(r.recurrence_type, 'none'), COALESCE(r.recurrence_interval, 1),
       blank_to_null(r.recurrence_days_of_week), r.recurrence_week_of_month, r.recurrence_until, r.recurrence_count,
       e.assignment_scheduling_enabled, 'portal', CASE WHEN e.inactive = 0 THEN 1 ELSE 0 END
FROM {{CRM}}.events_event e
LEFT JOIN {{CRM}}.event_recurrence r ON r.event_id = e.event_id;

INSERT INTO event_campuses (event_id, campus_id, is_host)
SELECT ec.event_id, ec.campus_id, ec.is_host
FROM {{CRM}}.events_event_campus ec
WHERE EXISTS (SELECT 1 FROM events e WHERE e.id = ec.event_id)
  AND EXISTS (SELECT 1 FROM campuses c WHERE c.id = ec.campus_id);

INSERT INTO event_tags (event_id, slug, label)
SELECT tm.event_id, t.tag_slug, t.tag_label
FROM {{CRM}}.event_tag_map tm
JOIN {{CRM}}.event_tag t ON t.tag_id = tm.tag_id
WHERE EXISTS (SELECT 1 FROM events e WHERE e.id = tm.event_id);

-- campuses.default_scheduling_event_id, now that events exist.
UPDATE campuses c
JOIN {{CRM}}.church_campus cc ON cc.campus_id = c.id
SET c.default_scheduling_event_id = (SELECT e.id FROM events e WHERE e.id = cc.default_assignment_event_id);

INSERT INTO event_occurrences (id, event_id, original_starts_at, starts_at, ends_at, status,
                               title_override, details_override)
SELECT o.occurrence_id, o.event_id, o.occurrence_start, o.occurrence_start, o.occurrence_end,
       CASE WHEN o.is_cancelled = 1 THEN 'cancelled' ELSE 'scheduled' END,
       blank_to_null(o.override_title), blank_to_null(o.override_desc)
FROM {{CRM}}.event_occurrence o
WHERE EXISTS (SELECT 1 FROM events e WHERE e.id = o.event_id);

INSERT INTO assignments (id, occurrence_id, serving_role_id, person_id, status, notes, assigned_at)
SELECT a.assignment_id, a.occurrence_id, a.role_id,
       (SELECT p.id FROM people p WHERE p.id = a.person_id),
       CASE WHEN a.person_id IS NULL THEN 'open' ELSE a.status END,
       blank_to_null(a.notes), a.assigned_at
FROM {{CRM}}.assignment a
WHERE EXISTS (SELECT 1 FROM event_occurrences o WHERE o.id = a.occurrence_id)
  AND EXISTS (SELECT 1 FROM serving_roles r WHERE r.id = a.role_id);

-- ---------------------------------------------------------------- accounts
-- One person link per account: the legacy account row's own link, else its
-- primary entry in portal_user_person_links.
INSERT INTO user_accounts (id, person_id, email, password_hash, display_name, is_active, must_change_password,
                           last_login_at, created_at, updated_at)
SELECT u.portal_user_id,
       (SELECT p.id FROM people p WHERE p.id = COALESCE(
          u.churchcrm_person_id,
          (SELECT l.person_id FROM {{PORTAL}}.portal_user_person_links l
            WHERE l.portal_user_id = u.portal_user_id ORDER BY l.is_primary DESC, l.link_id LIMIT 1))),
       u.email, u.password_hash, blank_to_null(u.display_name), u.is_active, u.must_change_password,
       u.last_login_at, u.created_at, u.updated_at
FROM {{PORTAL}}.portal_users u;

INSERT INTO account_roles (account_id, role, campus_id, ministry_id, created_at)
SELECT r.portal_user_id, r.role,
       (SELECT c.id FROM campuses c WHERE c.id = r.scope_campus_id),
       (SELECT m.id FROM ministries m WHERE m.id = r.scope_ministry_id),
       r.created_at
FROM {{PORTAL}}.portal_user_roles r
WHERE EXISTS (SELECT 1 FROM user_accounts a WHERE a.id = r.portal_user_id);

INSERT INTO account_tokens (token_hash, account_id, purpose, created_at, expires_at, used_at, payload)
SELECT SHA2(t.token, 256), t.portal_user_id, t.purpose, t.created_at, t.expires_at, t.consumed_at,
       CASE WHEN JSON_VALID(t.payload_json) THEN t.payload_json END
FROM {{PORTAL}}.portal_tokens t
WHERE EXISTS (SELECT 1 FROM user_accounts a WHERE a.id = t.portal_user_id);

INSERT INTO calendar_views (id, account_id, name, visibility, config_version, config, created_at, updated_at)
SELECT v.view_id, v.owner_user_id, v.name, v.visibility, v.config_version,
       CASE WHEN JSON_VALID(v.config) THEN v.config ELSE JSON_OBJECT() END, v.created_at, v.updated_at
FROM {{PORTAL}}.calendar_saved_view v
WHERE EXISTS (SELECT 1 FROM user_accounts a WHERE a.id = v.owner_user_id);

INSERT INTO unavailability (id, person_id, starts_on, ends_on, reason, created_by_account_id, created_at, updated_at)
SELECT u.unavailability_id, u.person_id, u.starts_on, u.ends_on, blank_to_null(u.reason),
       (SELECT a.id FROM user_accounts a WHERE a.id = u.created_by_portal_user_id),
       u.created_at, u.updated_at
FROM {{PORTAL}}.portal_unavailability u
WHERE EXISTS (SELECT 1 FROM people p WHERE p.id = u.person_id);

-- ----------------------------------------------------------------- rosters
INSERT INTO rosters (id, title, subtitle, ministry_id, campus_id, starts_on, ends_on, notes, is_published,
                     created_by_account_id, created_at, updated_at)
SELECT r.roster_id, r.title, blank_to_null(r.subtitle),
       (SELECT m.id FROM ministries m WHERE m.id = r.ministry_id),
       (SELECT c.id FROM campuses c WHERE c.id = r.campus_id),
       r.starts_on, r.ends_on, blank_to_null(r.notes), r.is_published,
       (SELECT a.id FROM user_accounts a WHERE a.id = r.created_by), r.created_at, r.updated_at
FROM {{PORTAL}}.schedule_roster r;

INSERT INTO roster_slots (id, roster_id, slot_date, slot_weekday, label, location, serving_role_id, sort_order, notes)
SELECT s.slot_id, s.roster_id, s.slot_date, s.slot_dow, blank_to_null(s.label), blank_to_null(s.location),
       (SELECT sr.id FROM serving_roles sr WHERE sr.id = s.role_id), s.display_order, blank_to_null(s.notes)
FROM {{PORTAL}}.schedule_roster_slot s;

INSERT INTO roster_assignments (id, slot_id, person_id, display_name, sort_order)
SELECT a.assignment_id, a.slot_id, (SELECT p.id FROM people p WHERE p.id = a.person_id),
       blank_to_null(a.display_name), a.display_order
FROM {{PORTAL}}.schedule_roster_assignment a;

-- ---------------------------------------------------------- member imports
INSERT INTO member_import_batches (id, campus_id, status, source_label, hub_sheet, ny_sheet, hub_updated, ny_updated, warnings, duplicate_report,
                                   created_by_account_id, created_at, applied_at)
SELECT b.id,
       (SELECT c.id FROM campuses c WHERE c.id = b.campus_id),
       CASE WHEN b.status IN ('staging','applied') THEN b.status ELSE 'staging' END,
       blank_to_null(b.source_label),
       b.hub_sheet, b.ny_sheet, b.hub_updated, b.ny_updated,
       CASE WHEN JSON_VALID(b.warnings) THEN b.warnings WHEN b.warnings IS NULL THEN NULL ELSE JSON_ARRAY(b.warnings) END,
       CASE WHEN JSON_VALID(b.duplicate_report) THEN b.duplicate_report WHEN b.duplicate_report IS NULL THEN NULL ELSE JSON_ARRAY(b.duplicate_report) END,
       (SELECT a.id FROM user_accounts a WHERE a.id = b.created_by),
       b.created_at, b.applied_at
FROM {{CRM}}.member_import_batch b;

INSERT INTO member_import_rows (id, batch_id, last_name, first_name, middle_name, preferred_name, email, phone,
                                address_raw, address_line1, city, region, postal_code, country,
                                birth_year, birth_month, birth_day, member_since, member_type, ministry, confirmed,
                                source, filled_from, status, matched_person_id, notes)
SELECT r.id, r.batch_id, r.last_name, r.first_name, r.middle_name, r.preferred_name, r.email, r.phone,
       r.address_raw, r.address1, r.city, r.state, r.zip, r.country,
       r.birth_year, r.birth_month, r.birth_day, r.member_since, r.member_type, r.ministry, r.confirmed,
       r.source, r.filled_from,
       CASE WHEN r.status IN ('draft','ready','skip','applied') THEN r.status ELSE 'draft' END,
       (SELECT p.id FROM people p WHERE p.id = r.matched_person_id), r.notes
FROM {{CRM}}.member_import_row r
WHERE EXISTS (SELECT 1 FROM member_import_batches b WHERE b.id = r.batch_id);

-- --------------------------------------------------------------- audit log
-- Legacy "notes" were change records ("Updated", "Added to group"), so they
-- join the audit log rather than becoming person notes.
INSERT INTO audit_log (occurred_at, person_id, action, target_type, target_id, summary, details)
SELECT COALESCE(n.nte_DateEntered, NOW()),
       (SELECT p.id FROM people p WHERE p.id = n.nte_EnteredBy),
       CONCAT('legacy.', COALESCE(blank_to_null(n.nte_Type), 'note')),
       CASE WHEN n.nte_per_ID > 0 THEN 'person' WHEN n.nte_fam_ID > 0 THEN 'household' END,
       CASE WHEN n.nte_per_ID > 0 THEN n.nte_per_ID WHEN n.nte_fam_ID > 0 THEN n.nte_fam_ID END,
       LEFT(n.nte_Text, 255),
       JSON_OBJECT('legacy_note_id', n.nte_ID, 'private', n.nte_Private, 'entered_by_legacy_person', n.nte_EnteredBy)
FROM {{CRM}}.note_nte n;

INSERT INTO audit_log (occurred_at, account_id, person_id, action, target_type, target_id, summary, details,
                       ip_address, user_agent)
SELECT a.at,
       (SELECT u.id FROM user_accounts u WHERE u.id = a.actor_user_id),
       (SELECT p.id FROM people p WHERE p.id = a.actor_person_id),
       a.action, CASE a.target_type WHEN 'portal_user' THEN 'user_account' ELSE a.target_type END, a.target_id, a.summary,
       CASE WHEN JSON_VALID(a.payload_json) THEN a.payload_json END, a.ip_address, a.user_agent
FROM {{PORTAL}}.portal_audit_log a;

DROP FUNCTION blank_to_null;
SET FOREIGN_KEY_CHECKS = 1;
