-- Two more ways into an account that already exists: Google, and a sign-in
-- link sent to the account's own address.
--
-- Neither creates anything. No person, no account, no role, no ministry
-- assignment — they authenticate a user_accounts row that an administrator
-- already provisioned, and every permission still comes from account_roles.

-- A proof of identity an account accepts, besides its password.
--
-- Google's `sub` is the durable identity, not the email: an address can be
-- renamed or change hands, and matching on one forever is how somebody
-- inherits an account that was never theirs. The email is only ever used once,
-- to associate the two, and only when exactly one account has it.
CREATE TABLE account_credentials (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  provider      ENUM('google') NOT NULL,

  -- The provider's stable identifier for this person (Google's `sub`).
  subject       VARCHAR(191) NOT NULL,

  -- What the provider said the address was when it was linked. Kept for the
  -- administrator's screen and for audit; never matched on after linking.
  linked_email  VARCHAR(190) NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME NULL,
  PRIMARY KEY (id),
  -- One identity belongs to one account, and one account has one identity per
  -- provider: both directions, so neither can be quietly duplicated.
  UNIQUE KEY uq_account_credentials_subject (provider, subject),
  UNIQUE KEY uq_account_credentials_account (account_id, provider),
  CONSTRAINT fk_account_credentials_account FOREIGN KEY (account_id) REFERENCES user_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in links live in the table that already holds single-use account
-- tokens, hashed, with an expiry and a used_at — rather than a second token
-- system beside it.
ALTER TABLE account_tokens
  MODIFY purpose ENUM('password_reset','invite','api','magic_login') NOT NULL;

-- What has been tried lately, so a password, a sign-in link or a Google
-- callback can be slowed down when it is tried over and over.
--
-- The bucket is a hash: the identifier somebody typed is not kept here in the
-- clear, and this table is never a place to look up who exists.
CREATE TABLE auth_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket       CHAR(64) NOT NULL,
  kind         ENUM('password','magic_link','google') NOT NULL,
  occurred_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_auth_attempts_bucket (kind, bucket, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
