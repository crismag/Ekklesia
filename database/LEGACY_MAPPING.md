# Legacy → new database mapping

How every legacy table and column used by the Church Portal code maps onto the
member database (`members/001_schema.sql`) and the visitors database
(`visitors/001_schema.sql`). The migration scripts in `migrate/` implement the
data side; this is the reference for code.

## Conventions for code

- **One MySQL connection:** `App\Core\Database\MembersConnection`. The visitors
  SQLite file is `App\Core\Database\VisitorsConnection`.
- **New names everywhere.** SQL, PHP array keys, JSON sent to the browser, form
  field names and view variables use the new column names. No `per_`, `fam_`,
  `grp_`, `p2g2r_`, `lst_`, `portal_user_id`, `churchcrm` names remain.
- **Adapters** move from `app/Adapters/ChurchCRM/ChurchCrm*Adapter` and
  `app/Adapters/Portal/Portal*Adapter` to `app/Adapters/Sql/Sql*Adapter`
  (namespace `App\Adapters\Sql`). They keep implementing the same contracts.
- **Ids are unchanged** for every migrated record.
- A missing value is `NULL`, never `''` or `0` (`household_id`, `campus_id`,
  birth parts, gender).
- Record history that ChurchCRM kept in `*_EnteredBy` / `*_EditedBy` columns or
  `note_nte` is written to `audit_log`.

## People and households

| Legacy | New |
| --- | --- |
| `person_per` | `people` |
| `per_ID` | `id` |
| `per_FirstName`, `per_MiddleName`, `per_LastName`, `per_Suffix` | `first_name`, `middle_name`, `last_name`, `suffix` |
| — | `preferred_name` |
| `per_Title`, `per_WorkPhone`, `per_WorkEmail`, `per_Facebook`, `per_Twitter`, `per_LinkedIn`, `per_Envelope`, `per_FriendDate`, `per_Flags` | dropped (never used) |
| `per_Address1`, `per_Address2`, `per_City`, `per_State`, `per_Zip`, `per_Country` | `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country` |
| `per_HomePhone`, `per_CellPhone`, `per_Email` | `home_phone`, `mobile_phone`, `email` |
| `per_BirthYear`, `per_BirthMonth`, `per_BirthDay` | `birth_year`, `birth_month`, `birth_day` |
| `per_MembershipDate` | `member_since` |
| `per_Gender` 1 / 2 / 0 | `gender` `'male'` / `'female'` / `NULL` |
| `per_fam_ID` (0 = none) | `household_id` (`NULL` = none) |
| `per_fmr_ID` → `list_lst` 2 | `household_role_id` → `household_roles` |
| `per_cls_ID` → `list_lst` 1 | `membership_status_id` → `membership_statuses` |
| `person_custom.c1` → `list_lst` 13 | `member_type_id` → `member_types` |
| `person_campus_affiliation` (one row per person) | `people.campus_id` |
| `per_DateEntered`, `per_DateLastEdited` | `created_at`, `updated_at` |
| — | `is_active`, `archived_at` |
| `family_fam` | `households` |
| `fam_ID`, `fam_Name`, `fam_Email`, `fam_HomePhone` | `id`, `name`, `email`, `home_phone` |
| `fam_Address1`, `fam_Address2`, `fam_City`, `fam_State`, `fam_Zip`, `fam_Country` | `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country` |
| `fam_Latitude`, `fam_Longitude` | `latitude`, `longitude` |
| `fam_WeddingDate` | `wedding_date` |
| `fam_SendNewsLetter` `'TRUE'`/`'FALSE'` | `send_newsletter` 1 / 0 |
| `fam_DateDeactivated` | `deactivated_on` |
| `fam_DateEntered`, `fam_DateLastEdited` | `created_at`, `updated_at` |
| `config/related-families.json` `{a, b, rel}` | `household_links` (`household_id` = a, `related_household_id` = b; `parent-child` → `parent_child` with a = parents, `extended`, `household` → `same_residence`) |
| `list_lst` (`lst_ID`, `lst_OptionID`, `lst_OptionName`, `lst_OptionSequence`) | `membership_statuses` (list 1), `household_roles` (list 2), `member_types` (list 13): `id`, `name`, `sort_order` |
| `note_nte` | `audit_log` |

## Campuses

| Legacy `church_campus` | New `campuses` |
| --- | --- |
| `campus_id`, `campus_name`, `campus_code` | `id`, `name`, `code` |
| `address1`, `address2`, `city`, `state`, `zip`, `country` | `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country` |
| `phone`, `email`, `website`, `time_zone`, `latitude`, `longitude`, `is_active`, `is_main`, `notes` | same names |
| `default_assignment_event_id` | `default_scheduling_event_id` |
| `date_entered`, `date_last_edited` | `created_at`, `updated_at` |
| `entered_by`, `edited_by` | `audit_log` |

## Ministries and serving roles

| Legacy | New |
| --- | --- |
| `group_grp` + `ministry_registry` | `ministries` |
| `grp_ID`, `grp_Name`, `grp_Description` | `id`, `name`, `description` |
| `grp_active`, `ministry_registry.is_ministry` | `is_active` |
| `ministry_registry.campus_id` | `campus_id` |
| `grp_Type`, `grp_RoleListID`, `grp_DefaultRole`, `grp_hasSpecialProps`, `grp_include_email_export` | dropped. Every group is a ministry |
| — | `short_name`, `slug`, `archived_at` |
| `person2group2role_p2g2r` (`p2g2r_per_ID`, `p2g2r_grp_ID`, `p2g2r_rle_ID`) | `ministry_members` (`person_id`, `ministry_id`, `role`) |
| `p2g2r_rle_ID` 2 ("Leader" in the group's role list) | `role = 'leader'` |
| `p2g2r_rle_ID` 0 or anything else | `role = 'member'` |
| `ministry_leaders` (leader tag) | `ministry_members.role = 'leader'` |
| — | `ministry_members.status` (`pending`, `confirmed`, `ended`), `started_on`, `ended_on` |
| workbook ministry words that are positions ("Usher", "Emcee"; `ministry-catalog.json` `roles`) | `ministry_member_positions` (`ministry_member_id`, `name`) |
| `roles` | `serving_roles` |
| `role_id`, `ministry_group_id`, `role_name`, `role_description` | `id`, `ministry_id`, `name`, `description` |
| `role_order`, `recommended_count`, `is_blocking`, `active` | `sort_order`, `recommended_count`, `blocks_other_roles`, `is_active` |

Membership roles in code and in the browser are the strings `member` and
`leader`, not ChurchCRM role-list ids.

## Calendar and serving schedule

| Legacy | New |
| --- | --- |
| `event_types` | `event_types` |
| `type_id`, `portal_slug`, `portal_label` (or `type_name`) | `id`, `slug`, `name` |
| `portal_audience`, `portal_color`, `portal_sort`, `portal_is_default`, `type_active` | `audience`, `color`, `sort_order`, `is_default`, `is_active` |
| `type_defstarttime`, `type_defrecur*`, `type_grpid` | dropped |
| `events_event` + `event_recurrence` | `events` |
| `event_id`, `event_type`, `ministry_id` | `id`, `event_type_id`, `ministry_id` |
| `event_title`, `event_desc`, `event_text` | `title`, `summary`, `details` |
| `event_start`, `event_end` (datetimes) | `starts_on` (date), `start_time`, `end_time`, `all_day` |
| `inactive` | `is_active` (inverted) |
| `assignment_scheduling_enabled` | `uses_serving_schedule` |
| `custom_location_name`, `custom_location_address`, `event_url` | `location_name`, `location_address`, `web_link` |
| `primary_contact_person_id` | `contact_person_id` |
| `secondary_contact_person_id`, `location_id` | dropped (never used) |
| `is_multi_campus` | derived: more than one `event_campuses` row |
| `recurrence_type`, `recurrence_interval`, `recurrence_days_of_week`, `recurrence_week_of_month`, `recurrence_until`, `recurrence_count` | `repeat_frequency` (`none` when no rule), `repeat_interval`, `repeat_weekdays`, `repeat_week_of_month`, `repeat_until`, `repeat_count` |
| — | `source_app` (`portal`, later `oikonomia`), `external_id` |
| `events_event_campus` | `event_campuses` (same columns) |
| `event_tag` + `event_tag_map` | `event_tags` (`event_id`, `slug`, `label`) |
| `event_occurrence` | `event_occurrences` |
| `occurrence_id`, `occurrence_start`, `occurrence_end` | `id`, `starts_at`, `ends_at` |
| — | `original_starts_at`: set to `starts_at` when the date is created, never changed |
| `is_cancelled` | `status = 'cancelled'` (else `'scheduled'`) |
| `is_modified` | derived: a title or details override is set (a moved time is not a modification, as before) |
| `override_title`, `override_desc` | `title_override`, `details_override` |
| `assignment` | `assignments` |
| `assignment_id`, `role_id` | `id`, `serving_role_id` |
| `status` (`open`, `assigned`, `declined`, `completed`) | same values, plus `confirmed` |
| `assignee_name` | `assignee_name` (a typed-in helper who is not in the database) |
| `portal_unavailability` | `unavailability` (`unavailability_id` → `id`, `created_by_portal_user_id` → `created_by_account_id`) |
| `schedule_roster` | `rosters` (`roster_id` → `id`, `created_by` → `created_by_account_id`) |
| `schedule_roster_slot` | `roster_slots` (`slot_id` → `id`, `slot_dow` → `slot_weekday`, `role_id` → `serving_role_id`, `display_order` → `sort_order`) |
| `schedule_roster_assignment` | `roster_assignments` (`assignment_id` → `id`, `display_order` → `sort_order`) |
| `calendar_saved_view` | `calendar_views` (`view_id` → `id`, `owner_user_id` → `account_id`) |

## Logins and access

| Legacy | New |
| --- | --- |
| `portal_users` | `user_accounts` |
| `portal_user_id` | `id` (as a reference elsewhere: `account_id`) |
| `churchcrm_person_id` + `portal_user_person_links` | `person_id` (one person per login; several logins may share a person) |
| `email`, `password_hash`, `display_name`, `is_active`, `must_change_password`, `last_login_at` | same names |
| `portal_user_roles` | `account_roles` (`role_id` → `id`, `portal_user_id` → `account_id`, `scope_campus_id` → `campus_id`, `scope_ministry_id` → `ministry_id`) |
| `portal_sessions` | `account_sessions`: `session_token` → `token_hash` stores `hash('sha256', token)`; the browser keeps the raw token. `portal_user_id` → `account_id` |
| `portal_tokens` | `account_tokens`: `token` → `token_hash` (sha256), `consumed_at` → `used_at`, `payload_json` → `payload` |
| `portal_audit_log` | `audit_log` (`audit_id` → `id`, `at` → `occurred_at`, `actor_user_id` → `account_id`, `actor_person_id` → `person_id`, `payload_json` → `details`) |

## Member import

| Legacy | New |
| --- | --- |
| `member_import_batch` | `member_import_batches` (`created_by` → `created_by_account_id`; other columns unchanged) |
| `member_import_row` | `member_import_rows` (`address1` → `address_line1`, `state` → `region`, `zip` → `postal_code`; other columns unchanged) |
| `christlikeness_people_tbl`, `member_group_and_ministry_roles_tbl`, `ministry_roles_tbl`, `member_type_sync` | views `sheet_people`, `sheet_ministry_members`, `sheet_serving_roles` |

## Visitors (SQLite)

| Legacy (MySQL) | New (SQLite) |
| --- | --- |
| `people_signup_temp` | `visitor_registrations` |
| `nick_name` | `preferred_name` |
| `address`, `address2`, `state`, `zip` | `address_line1`, `address_line2`, `region`, `postal_code` |
| `gender` 1 / 2 | `'male'` / `'female'` |
| `member_type` (id) | `member_type_name` |
| `migration_status` (`new`, `reviewed`, `migrated`, `duplicate`, `rejected`) | `status` (`migrated` → `promoted`) |
| `matched_member_id`, `admin_notes` | `matched_person_id`, `reviewer_notes` |
| — | `reviewed_at` |
| promoting a registration | creates or matches `people` in the member database, sets `status = 'promoted'` and `matched_person_id`, and inserts `visitor_promotions` |
| `rsvp_attendance` | `visitor_rsvps` |
| `signup_id`, `member_id` | `visitor_registration_id`, `person_id` |
| `first_name_snapshot`, `last_name_snapshot`, `email_snapshot`, `phone_snapshot`, `city_snapshot` | `first_name`, `last_name`, `email`, `phone`, `city` |
| `rsvp_status`, `attendance_status`, `party_count` | `response`, `attendance`, `party_size` |
| `event_source`, `person_type`, `visitor_id` | dropped: an RSVP is for a member-database event; a member RSVP has `person_id` |
| `rsvp_events` | dropped |
| `signup_admin_access` (`word`) | `visitor_admin_access_codes` (`code`) |

SQLite dialect: `datetime('now')` instead of `NOW()`, no `ON DUPLICATE KEY`
(use `INSERT … ON CONFLICT`), no `CONCAT` (use `||`).
