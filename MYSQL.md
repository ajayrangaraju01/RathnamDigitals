# MySQL setup and migration

The application now uses MySQL through PDO. Enable the `pdo_mysql` PHP extension and configure these environment variables for the PHP process:

- `DB_HOST` (defaults to `127.0.0.1`)
- `DB_PORT` (defaults to `3306`)
- `DB_NAME` (required; create this database first with `utf8mb4`)
- `DB_USER` (required)
- `DB_PASSWORD` (may be empty for a local development database)

Do not commit database credentials. Set them in the hosting environment or the process manager that starts PHP.

For local development, copy `.env.example` to `.env` in the project root and set `DB_USER` and `DB_PASSWORD` to a MySQL account that can create tables in the database. The application loads those values on startup; environment variables already set by the PHP process take precedence. Restart the PHP server after changing `.env`.

Create the database before starting the application, for example:

```sql
CREATE DATABASE rathnam_digitals CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Import existing SQLite data

Before switching production traffic, back up `db.sqlite3` and the `media/` directory. Create an empty MySQL database and set the variables above, then run from the project root:

```powershell
php scripts\migrate-sqlite-to-mysql.php
```

The importer requires the MySQL tables to be empty, copies existing rows and IDs from `db.sqlite3` when present, and reports the row count for each table. It rolls back the import if an error occurs. If the SQLite database predates the packages feature, the importer seeds the original three homepage packages. The SQLite database is retained as the migration source; the application itself no longer connects to it.

After the import, keep the MySQL settings available to the PHP web server and start/restart the application. On a fresh MySQL install, the application seeds the original three homepage packages on first startup.

## Enable PDO MySQL

The PHP runtime must have `pdo_mysql` enabled. In `php.ini`, enable the extension with:

```ini
extension=php_pdo_mysql.dll
```

Restart PHP after editing `php.ini`. The application displays a specific setup error if the extension is missing.
