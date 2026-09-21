-- Let the administrator decide each duplicate the member import detects.
--
-- The import used to drop every row it judged a duplicate before staging, so a
-- parent "Jessie" and a child "Jessie James" sharing an email, phone and
-- address became one person and the parent vanished with nothing to undo.
-- Every row is now staged; rows that look like the same person share a
-- duplicate_group, and the batch's duplicate_report records the decision for
-- each group (keep one row, or keep all as different people).
--
-- match_rules records what the importer required to agree beyond the name
-- (full first name, birthday, email, phone, member type). NULL is the
-- original name-only rule.

ALTER TABLE member_import_batches
  ADD COLUMN match_rules JSON NULL AFTER duplicate_report;

ALTER TABLE member_import_rows
  ADD COLUMN duplicate_group SMALLINT UNSIGNED NULL AFTER notes;
