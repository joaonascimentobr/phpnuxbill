# Environment variable configuration

PHPNuxBill can read its application URL and MariaDB connection settings from the process environment. This supports container platforms such as Docker, Dokploy, Portainer, and Kubernetes without requiring `vlucas/phpdotenv`.

Existing installations can continue using `config.php`. Environment variables override matching values in that file only when the variable is present. An explicitly empty value is not treated as absent: empty values are rejected for required fields, while an empty `DB_PASSWORD` is allowed for databases configured without a password.

## Variables

| Variable | Required for ENV-only mode | Default / behavior |
| --- | --- | --- |
| `APP_URL` | Yes | Absolute `http://` or `https://` URL, without credentials, query, or fragment. Overrides the `APP_URL` constant from `config.php` when set. |
| `APP_ENV` | No | `production` or `development`. ENV-only mode defaults to `production`; with `config.php`, an unset variable preserves `$_app_stage`. `production` maps to the application's `Live` stage and disables displayed PHP errors; `development` maps to `Dev`. |
| `DB_HOST` | Yes | MariaDB host. Overrides `$db_host`. |
| `DB_PORT` | No | Integer from `1` to `65535`. ENV-only mode defaults to `3306`. With `config.php`, an unset variable preserves the previous connection behavior; when set, the port is included in the PDO/ORM DSN. |
| `DB_NAME` | Yes | Database name. Overrides `$db_name`. |
| `DB_USER` | Yes | Database user. Overrides `$db_user`. |
| `DB_PASSWORD` | Yes | Database password. Overrides `$db_pass` and the legacy `$db_password` alias. It must be present in ENV-only mode; an empty value is accepted for passwordless database accounts. |

“ENV-only” means `config.php` does not exist. The required variables are `APP_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`; `APP_ENV` and `DB_PORT` are optional. When starting in ENV-only mode, the app validates the configuration before attempting a database connection. If required variables are missing on a web request, the existing installer remains available.

## Docker and Dokploy example

Use placeholders in configuration files and set real values through your deployment platform's environment or secret settings:

```yaml
services:
  phpnuxbill:
    image: your-phpnuxbill-image
    environment:
      APP_ENV: production
      APP_URL: https://isp.example.invalid
      DB_HOST: mariadb
      DB_PORT: "3306"
      DB_NAME: phpnuxbill
      DB_USER: phpnuxbill
      DB_PASSWORD: ${PHN_PASSWORD_FROM_SECRET_STORE}
```

In Dokploy, create the same variables in the application's environment settings and mark `DB_PASSWORD` as a secret where supported. Replace every example value before deployment. The app reads variables supplied to PHP by the container; no `.env` parser is installed or required.

## Installer behavior

- **Traditional installation:** Leave these environment variables unset and use the existing web installer. It continues to create `config.php` and import the existing application and optional FreeRADIUS SQL files as before.
- **ENV-only installation:** Supply all five required variables before starting the app. The app skips the installer redirect and connects using ENV values. Database creation and schema import remain separate manual/deployment steps; this feature does not create databases or run SQL imports.
- **Optional FreeRADIUS:** Existing `$radius_host`, `$radius_name`, `$radius_user`, `$radius_pass`, and `$radius_password` values in `config.php` remain untouched. The new variables configure the main PHPNuxBill database only; they do not silently repoint or enable FreeRADIUS.

If `config.php` is absent and ENV is incomplete, web requests continue to the installer. For a traditional install, keep partially configured application variables unset until the installer has created `config.php`; once it exists, any supplied supported variable takes precedence and must be valid.

## Secret storage and migration

- Store `DB_PASSWORD` in the deployment platform's secret manager or protected environment settings. Do not commit real credentials, paste them into issue/PR descriptions, or bake them into an image.
- Restrict access to deployment settings and to any local `.env` file. If using a local file with Compose, keep it out of version control and set filesystem permissions so only the deployment operator can read it.
- The application does not log resolved ENV values. Validation errors identify variable names but never include their values.
- To migrate an existing installation, first record the current URL and database settings in the deployment platform's protected environment settings. Start with variables that should override `config.php`, verify the site and cron jobs, then move the remaining values. Remove `config.php` only after all required ENV-only variables are present and verified. Keep a protected backup outside the image/repository until the migration is confirmed.
- To roll back, restore the protected `config.php` and unset the corresponding ENV variables. Unset `DB_PORT` to retain the original legacy connection behavior.

## Running the configuration tests

The repository has no PHPUnit or other test framework. The focused regression suite has no external dependencies and can be run with:

```sh
php tests/environment_configuration_test.php
```
