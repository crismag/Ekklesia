-- Calendar themes designed in PowerPoint (the print studio's "PowerPoint
-- themes"). The uploaded .pptx, its re-encoded pictures and a thumbnail are
-- files in storage/private; these rows are their records.
--
-- A theme is versioned: replacing it adds a version, and a saved design keeps
-- the version it was made with, so re-uploading never changes a calendar
-- somebody already saved. `model` is the normalised description PptxThemeReader
-- produced (page, safe regions, artwork, palette): the application renders
-- from it and never re-reads the presentation.
CREATE TABLE print_themes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id       INT UNSIGNED NOT NULL,
  name             VARCHAR(120) NOT NULL,
  scope            ENUM('private','church') NOT NULL DEFAULT 'private',
  status           ENUM('active','archived') NOT NULL DEFAULT 'active',
  current_version  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_print_themes_account (account_id),
  CONSTRAINT fk_print_themes_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE print_theme_versions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  theme_id       INT UNSIGNED NOT NULL,
  version        SMALLINT UNSIGNED NOT NULL,
  original_name  VARCHAR(190) NULL,
  bytes          INT UNSIGNED NOT NULL,
  sha256         CHAR(64) NOT NULL,
  paper          VARCHAR(20) NOT NULL,
  orientation    ENUM('portrait','landscape') NOT NULL,
  model          MEDIUMTEXT NOT NULL,
  warnings       TEXT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_print_theme_versions (theme_id, version),
  CONSTRAINT fk_print_theme_versions_theme FOREIGN KEY (theme_id) REFERENCES print_themes (id) ON DELETE CASCADE,
  CONSTRAINT fk_print_theme_versions_account FOREIGN KEY (created_by) REFERENCES user_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
