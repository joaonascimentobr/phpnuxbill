# Draft pull request: environment-based configuration

**Status:** saved for a future PR; not published.

**Suggested title:** `Support environment-based application and database configuration`

## Proposed description

```markdown
## Summary

Add backward-compatible environment-based configuration for the application URL and primary MariaDB connection. Existing installations can continue using `config.php`, while container deployments can configure the app without generating that file.

## Configuration behavior

- Supported variables: `APP_URL`, `APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.
- Present environment variables override their matching `config.php` values; unset variables retain legacy values.
- Without `config.php`, `APP_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` are required. `APP_ENV` defaults to `production` and `DB_PORT` defaults to `3306`.
- An explicitly empty password remains an empty password. Invalid required values and ports are rejected without printing configuration values.

## Compatibility

- The existing web installer and generated `config.php` remain supported.
- Web requests and cron jobs use the shared configuration loader.
- Existing optional FreeRADIUS settings remain unchanged and separate from the new primary database variables.
- No Dockerfile, SQL schema, database migration, or automatic SQL import changes are included.

## Documentation and tests

- Document environment variables, Docker/Dokploy setup, secret handling, installer behavior, and migration steps.
- Add a dependency-free PHP regression suite covering legacy config, ENV-only mode, overrides, validation, RADIUS preservation, and bootstrap wiring.

## Validation

Update these results after testing the `develop` deployment and before opening the PR:

- [ ] Run `php tests/environment_configuration_test.php` and record the result.
- [ ] Run PHP syntax checks on the changed PHP files and record the result.
- [x] `git diff --check` passed for the implementation commit.
- [ ] Record any manual runtime verification performed with test data; do not use a production database.
```

## Before opening

The PHP test suite and syntax checks were not runnable in the implementation environment because PHP was unavailable and Docker Desktop was stopped. Replace the validation checklist with actual results after testing on `develop`. Then create the PR from `joaonascimentobr:feat/environment-configuration` to `hotspotbilling/phpnuxbill:master`; do not use `develop` as the PR source because it also contains the independent SQL and Docker changes.
