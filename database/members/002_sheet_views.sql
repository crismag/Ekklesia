-- =============================================================================
-- Spreadsheet views
--
-- The church keeps its records in Excel. These views present the member
-- database in that same flat shape (one row per person; one row per person per
-- ministry; one row per ministry role), for exporting and for checking an
-- import against. They replace the legacy bridging tables
-- christlikeness_people_tbl, member_group_and_ministry_roles_tbl and
-- ministry_roles_tbl, which held copies of the data rather than views of it.
-- =============================================================================

CREATE OR REPLACE VIEW sheet_people AS
SELECT
  p.id                                   AS people_id,
  p.last_name,
  p.first_name,
  p.middle_name,
  p.suffix,
  p.address_line1                        AS address_1,
  p.address_line2                        AS address_2,
  p.city,
  p.region                               AS state,
  p.postal_code                          AS zip,
  p.country,
  p.home_phone,
  p.mobile_phone                         AS cell_phone,
  p.email,
  CASE WHEN p.birth_year IS NOT NULL AND p.birth_month IS NOT NULL AND p.birth_day IS NOT NULL
       THEN STR_TO_DATE(CONCAT(p.birth_year,'-',p.birth_month,'-',p.birth_day), '%Y-%c-%e') END AS birth_date,
  p.member_since                         AS membership_date,
  mt.name                                AS member_type,
  CASE p.gender WHEN 'male' THEN 'M' WHEN 'female' THEN 'F' END AS gender,
  c.name                                 AS campus,
  h.name                                 AS household,
  p.is_active
FROM people p
LEFT JOIN member_types mt ON mt.id = p.member_type_id
LEFT JOIN campuses c      ON c.id  = p.campus_id
LEFT JOIN households h    ON h.id  = p.household_id
WHERE p.archived_at IS NULL;

CREATE OR REPLACE VIEW sheet_ministry_members AS
SELECT
  mm.person_id   AS people_id,
  p.last_name,
  p.first_name,
  m.id           AS ministry_id,
  m.name         AS ministry,
  mm.role,
  mm.status,
  (SELECT GROUP_CONCAT(pos.name ORDER BY pos.name SEPARATOR ', ')
     FROM ministry_member_positions pos WHERE pos.ministry_member_id = mm.id) AS member_group_associations
FROM ministry_members mm
JOIN people p     ON p.id = mm.person_id
JOIN ministries m ON m.id = mm.ministry_id;

CREATE OR REPLACE VIEW sheet_serving_roles AS
SELECT
  m.id     AS ministry_id,
  m.name   AS ministry,
  r.id     AS role_id,
  r.name   AS role_name,
  r.description AS role_description,
  r.recommended_count
FROM serving_roles r
JOIN ministries m ON m.id = r.ministry_id;
