-- Legacy count beside new count. "expected" is the legacy figure after the
-- documented filters (test data kept, dangling references dropped).
SELECT area, legacy, migrated, IF(legacy = migrated, 'ok', 'CHECK') AS result FROM (
  SELECT 'campuses' area, (SELECT COUNT(*) FROM {{CRM}}.church_campus) legacy, (SELECT COUNT(*) FROM {{MEMBERS}}.campuses) migrated
  UNION ALL SELECT 'member types', (SELECT COUNT(*) FROM {{CRM}}.list_lst WHERE lst_ID=13), (SELECT COUNT(*) FROM {{MEMBERS}}.member_types)
  UNION ALL SELECT 'ministries', (SELECT COUNT(*) FROM {{CRM}}.group_grp), (SELECT COUNT(*) FROM {{MEMBERS}}.ministries)
  UNION ALL SELECT 'serving roles', (SELECT COUNT(*) FROM {{CRM}}.roles), (SELECT COUNT(*) FROM {{MEMBERS}}.serving_roles)
  UNION ALL SELECT 'households', (SELECT COUNT(*) FROM {{CRM}}.family_fam), (SELECT COUNT(*) FROM {{MEMBERS}}.households)
  UNION ALL SELECT 'households with an address', (SELECT COUNT(*) FROM {{CRM}}.family_fam WHERE NULLIF(TRIM(fam_Address1),'') IS NOT NULL), (SELECT COUNT(*) FROM {{MEMBERS}}.households WHERE address_line1 IS NOT NULL)
  UNION ALL SELECT 'active households', (SELECT COUNT(*) FROM {{CRM}}.family_fam WHERE fam_DateDeactivated IS NULL), (SELECT COUNT(*) FROM {{MEMBERS}}.households WHERE deactivated_on IS NULL)
  UNION ALL SELECT 'people in a household', (SELECT COUNT(*) FROM {{CRM}}.person_per p JOIN {{CRM}}.family_fam f ON f.fam_ID = p.per_fam_ID), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE household_id IS NOT NULL)
  UNION ALL SELECT 'people with an address', (SELECT COUNT(*) FROM {{CRM}}.person_per WHERE NULLIF(TRIM(per_Address1),'') IS NOT NULL), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE address_line1 IS NOT NULL)
  UNION ALL SELECT 'people', (SELECT COUNT(*) FROM {{CRM}}.person_per), (SELECT COUNT(*) FROM {{MEMBERS}}.people)
  UNION ALL SELECT 'people with a campus', (SELECT COUNT(DISTINCT person_id) FROM {{CRM}}.person_campus_affiliation), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE campus_id IS NOT NULL)
  UNION ALL SELECT 'membership statuses', (SELECT COUNT(*) FROM {{CRM}}.list_lst WHERE lst_ID=1), (SELECT COUNT(*) FROM {{MEMBERS}}.membership_statuses)
  UNION ALL SELECT 'household roles', (SELECT COUNT(*) FROM {{CRM}}.list_lst WHERE lst_ID=2), (SELECT COUNT(*) FROM {{MEMBERS}}.household_roles)
  UNION ALL SELECT 'people with a status', (SELECT COUNT(*) FROM {{CRM}}.person_per p JOIN {{CRM}}.list_lst l ON l.lst_ID=1 AND l.lst_OptionID=p.per_cls_ID), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE membership_status_id IS NOT NULL)
  UNION ALL SELECT 'people with a household role', (SELECT COUNT(*) FROM {{CRM}}.person_per p JOIN {{CRM}}.list_lst l ON l.lst_ID=2 AND l.lst_OptionID=p.per_fmr_ID), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE household_role_id IS NOT NULL)
  UNION ALL SELECT 'people with a member type', (SELECT COUNT(*) FROM {{CRM}}.person_custom WHERE c1 IS NOT NULL), (SELECT COUNT(*) FROM {{MEMBERS}}.people WHERE member_type_id IS NOT NULL)
  UNION ALL SELECT 'ministry members', (SELECT COUNT(*) FROM (SELECT p2g2r_grp_ID, p2g2r_per_ID FROM {{CRM}}.person2group2role_p2g2r UNION SELECT ministry_group_id, person_id FROM {{PORTAL}}.ministry_leaders) x), (SELECT COUNT(*) FROM {{MEMBERS}}.ministry_members)
  UNION ALL SELECT 'ministry leaders', (SELECT COUNT(*) FROM {{CRM}}.person2group2role_p2g2r WHERE p2g2r_rle_ID=2), (SELECT COUNT(*) FROM {{MEMBERS}}.ministry_members WHERE role='leader')
  UNION ALL SELECT 'event types', (SELECT COUNT(*) FROM {{CRM}}.event_types), (SELECT COUNT(*) FROM {{MEMBERS}}.event_types)
  UNION ALL SELECT 'events', (SELECT COUNT(*) FROM {{CRM}}.events_event), (SELECT COUNT(*) FROM {{MEMBERS}}.events)
  UNION ALL SELECT 'repeating events', (SELECT COUNT(*) FROM {{CRM}}.event_recurrence), (SELECT COUNT(*) FROM {{MEMBERS}}.events WHERE repeat_frequency<>'none')
  UNION ALL SELECT 'event campuses', (SELECT COUNT(*) FROM {{CRM}}.events_event_campus), (SELECT COUNT(*) FROM {{MEMBERS}}.event_campuses)
  UNION ALL SELECT 'event tags (on existing events)', (SELECT COUNT(*) FROM {{CRM}}.event_tag_map tm JOIN {{CRM}}.events_event e ON e.event_id = tm.event_id), (SELECT COUNT(*) FROM {{MEMBERS}}.event_tags)
  UNION ALL SELECT 'occurrences', (SELECT COUNT(*) FROM {{CRM}}.event_occurrence), (SELECT COUNT(*) FROM {{MEMBERS}}.event_occurrences)
  UNION ALL SELECT 'assignments', (SELECT COUNT(*) FROM {{CRM}}.assignment), (SELECT COUNT(*) FROM {{MEMBERS}}.assignments)
  UNION ALL SELECT 'rosters', (SELECT COUNT(*) FROM {{PORTAL}}.schedule_roster), (SELECT COUNT(*) FROM {{MEMBERS}}.rosters)
  UNION ALL SELECT 'roster slots', (SELECT COUNT(*) FROM {{PORTAL}}.schedule_roster_slot), (SELECT COUNT(*) FROM {{MEMBERS}}.roster_slots)
  UNION ALL SELECT 'roster assignments', (SELECT COUNT(*) FROM {{PORTAL}}.schedule_roster_assignment), (SELECT COUNT(*) FROM {{MEMBERS}}.roster_assignments)
  UNION ALL SELECT 'accounts from the portal', (SELECT COUNT(*) FROM {{PORTAL}}.portal_users), (SELECT COUNT(*) FROM {{MEMBERS}}.user_accounts a WHERE EXISTS (SELECT 1 FROM {{PORTAL}}.portal_users u WHERE u.portal_user_id = a.id))
  UNION ALL SELECT 'account roles from the portal', (SELECT COUNT(*) FROM {{PORTAL}}.portal_user_roles), (SELECT COUNT(*) FROM {{PORTAL}}.portal_user_roles r WHERE EXISTS (SELECT 1 FROM {{MEMBERS}}.account_roles n WHERE n.account_id = r.portal_user_id AND CAST(n.role AS CHAR) = CONVERT(r.role USING utf8mb4) COLLATE utf8mb4_unicode_ci AND n.campus_id <=> r.scope_campus_id AND n.ministry_id <=> r.scope_ministry_id))
  UNION ALL SELECT 'saved calendar views', (SELECT COUNT(*) FROM {{PORTAL}}.calendar_saved_view), (SELECT COUNT(*) FROM {{MEMBERS}}.calendar_views)
  UNION ALL SELECT 'unavailability', (SELECT COUNT(*) FROM {{PORTAL}}.portal_unavailability), (SELECT COUNT(*) FROM {{MEMBERS}}.unavailability)
  UNION ALL SELECT 'import batches', (SELECT COUNT(*) FROM {{CRM}}.member_import_batch), (SELECT COUNT(*) FROM {{MEMBERS}}.member_import_batches)
  UNION ALL SELECT 'import rows (same status)', (SELECT COUNT(*) FROM {{CRM}}.member_import_row r JOIN {{MEMBERS}}.member_import_rows n ON n.id = r.id AND CAST(n.status AS CHAR) = CONVERT(r.status USING utf8mb4) COLLATE utf8mb4_unicode_ci), (SELECT COUNT(*) FROM {{MEMBERS}}.member_import_rows)
  UNION ALL SELECT 'import rows', (SELECT COUNT(*) FROM {{CRM}}.member_import_row), (SELECT COUNT(*) FROM {{MEMBERS}}.member_import_rows)
  UNION ALL SELECT 'audit entries', (SELECT COUNT(*) FROM {{CRM}}.note_nte) + (SELECT COUNT(*) FROM {{PORTAL}}.portal_audit_log), (SELECT COUNT(*) FROM {{MEMBERS}}.audit_log)
  UNION ALL SELECT 'sheet positions (distinct)', (SELECT COUNT(DISTINCT people_id, ministry_id, TRIM(member_group_associations)) FROM {{PORTAL}}.member_group_and_ministry_roles_tbl WHERE NULLIF(TRIM(member_group_associations),'') IS NOT NULL), (SELECT COUNT(*) FROM {{MEMBERS}}.ministry_member_positions)
) counts;

-- Things a person should look at, not failures.
SELECT 'Accounts sharing one person' AS review, person_id AS id, COUNT(*) AS n, GROUP_CONCAT(id) AS detail
  FROM {{MEMBERS}}.user_accounts WHERE person_id IS NOT NULL GROUP BY person_id HAVING COUNT(*) > 1
UNION ALL SELECT 'Accounts without a person', id, 1, email FROM {{MEMBERS}}.user_accounts WHERE person_id IS NULL
UNION ALL SELECT 'People without a campus', id, 1, CONCAT(first_name, ' ', last_name) FROM {{MEMBERS}}.people WHERE campus_id IS NULL
UNION ALL SELECT 'Email used by several people', NULL, COUNT(*), GROUP_CONCAT(id) FROM {{MEMBERS}}.people WHERE email IS NOT NULL GROUP BY email HAVING COUNT(*) > 1
UNION ALL SELECT 'Inactive ministries', id, 1, name FROM {{MEMBERS}}.ministries WHERE is_active = 0
UNION ALL SELECT CONCAT('Access from ChurchCRM user: ', r.role), a.person_id, 1, CONCAT(a.email, IF(a.must_change_password = 1 AND NOT EXISTS (SELECT 1 FROM {{PORTAL}}.portal_users u WHERE u.portal_user_id = a.id), ' (new login, default password)', ''))
  FROM {{MEMBERS}}.account_roles r JOIN {{MEMBERS}}.user_accounts a ON a.id = r.account_id
  WHERE r.role IN ('admin', 'scheduler')
    AND NOT EXISTS (SELECT 1 FROM {{PORTAL}}.portal_user_roles o WHERE o.portal_user_id = r.account_id AND CONVERT(o.role USING utf8mb4) COLLATE utf8mb4_unicode_ci = CAST(r.role AS CHAR))
UNION ALL SELECT 'Sheet person matching nobody by name', c.people_id, 1, CONCAT(c.first_name, ' ', c.last_name)
  FROM {{PORTAL}}.christlikeness_people_tbl c
  WHERE NOT EXISTS (SELECT 1 FROM {{MEMBERS}}.people p WHERE LOWER(TRIM(p.first_name)) = LOWER(TRIM(c.first_name)) AND LOWER(TRIM(p.last_name)) = LOWER(TRIM(c.last_name)))
UNION ALL SELECT 'Legacy event tag on a missing event', tm.event_id, 1, t.tag_label
  FROM {{CRM}}.event_tag_map tm JOIN {{CRM}}.event_tag t ON t.tag_id = tm.tag_id
  WHERE NOT EXISTS (SELECT 1 FROM {{CRM}}.events_event e WHERE e.event_id = tm.event_id);
