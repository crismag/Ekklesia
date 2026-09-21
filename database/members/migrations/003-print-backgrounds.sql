-- Pictures uploaded to sit behind a printed calendar (the print studio's
-- "Background picture"). The image itself is a file in storage/private,
-- outside the web root, re-encoded on upload; this row is its record. Never
-- stored as base64 inside a saved view: a view refers to one by id.
--
-- Owned by the account that uploaded it. Anyone who can open a saved view that
-- uses it may see it; only its owner or a portal administrator may delete it,
-- and not while a saved view still uses it.
CREATE TABLE print_backgrounds (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id     INT UNSIGNED NOT NULL,
  label          VARCHAR(120) NOT NULL,
  original_name  VARCHAR(190) NULL,
  width_px       SMALLINT UNSIGNED NOT NULL,
  height_px      SMALLINT UNSIGNED NOT NULL,
  bytes          INT UNSIGNED NOT NULL,
  sha256         CHAR(64) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_print_backgrounds_account (account_id),
  CONSTRAINT fk_print_backgrounds_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
