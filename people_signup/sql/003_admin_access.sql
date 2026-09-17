-- Rotating admin access codes for the sign-up / RSVP admin areas.
-- The effective access code is a fixed prefix ("ChristLikeness") + a WORD ID,
-- valid until expires_at. One table serves both modules (module column).
CREATE TABLE IF NOT EXISTS signup_admin_access (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module     VARCHAR(20)  NOT NULL,            -- 'signup' | 'rsvp'
    word       VARCHAR(64)  NOT NULL,            -- the assignable/generated WORD ID
    issued_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    note       VARCHAR(120)  NULL,
    KEY module_idx (module, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
