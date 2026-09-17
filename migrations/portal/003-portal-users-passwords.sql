-- @connection: portal
--
-- Which database this migration belongs to. migrations/portal/ holds
-- migrations for BOTH the portal database and the ChurchCRM database
-- (member import staging and events live alongside person_per), so the
-- target cannot be inferred from the directory name.
-- =============================================================================
-- portal_users: must_change_password + churchcrm_person_id
-- =============================================================================
-- Lets the portal auto-provision a portal_users row from a ChurchCRM identity
-- (email or mobile match against person_per) using the default password
-- ChristLike#<FNI><LNI>#2026!. The flag forces a password change on first
-- login. churchcrm_person_id makes the link discoverable without joining
-- through portal_user_person_links every time.
--
-- Idempotent: ALTER TABLE ... ADD COLUMN IF NOT EXISTS (MySQL 8.0.29+ /
-- MariaDB 10.0.2+). Re-running is a no-op on schemas that already carry it.
-- =============================================================================

ALTER TABLE `portal_users`
  ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1)  NOT NULL DEFAULT 0  AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `churchcrm_person_id`  MEDIUMINT   DEFAULT NULL        AFTER `display_name`;

-- An email is no longer required when we provision an identity that only has a
-- mobile number; allow stub emails like 'phone:+15551234567' as a fallback.
-- (The UNIQUE index already lets us key by either, since stubs are unique.)
