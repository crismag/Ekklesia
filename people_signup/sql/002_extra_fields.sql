-- People Sign-Up: page-3 fields (social links, Member Type, richer address, geo).
-- Safe to run once. Columns are nullable so existing rows are unaffected.
ALTER TABLE people_signup_temp
    ADD COLUMN address2      VARCHAR(160) NULL AFTER address,
    ADD COLUMN facebook      VARCHAR(120) NULL AFTER visit_notes,
    ADD COLUMN linkedin      VARCHAR(120) NULL AFTER facebook,
    ADD COLUMN twitter       VARCHAR(120) NULL AFTER linkedin,   -- X / Twitter
    ADD COLUMN member_type   TINYINT      NULL AFTER twitter,    -- 1=Radical 2=Trailblazer 3=G&A (person_custom.c1)
    ADD COLUMN is_married     TINYINT(1)   NULL AFTER member_type,-- detection aid for Trailblazer
    ADD COLUMN latitude      DECIMAL(10,7) NULL AFTER is_married,
    ADD COLUMN longitude     DECIMAL(10,7) NULL AFTER latitude;
