# Member database migrations

Changes to the member database after `../001_schema.sql`, one `.sql` file each,
named `NNN-what-it-does.sql` and applied in filename order by
`php tools/migrate.php --apply`. History is kept in `schema_migrations`.

- Never edit a migration that has been applied anywhere; add a new one.
- Guard drops with `IF EXISTS`; never `TRUNCATE`.
- Update `../001_schema.sql` in the same change, so a fresh install matches.
