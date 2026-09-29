# Camera Fix Console

PHP/MySQL camera-fix register for administrators and SI (contractor) accounts.
Administrators can manage cameras, assign sites, and verify fixes. SI users see
only their assigned sites and receive notifications when a camera needs more
work.

## Requirements

- XAMPP with Apache and MySQL running.
- PHP 8.1 or later with `pdo_mysql` enabled.
- MySQL 8 or MariaDB 10.4 or later.

No Node.js or npm installation is required. The browser interface uses
JavaScript; the web server and API run on PHP.

## Run with XAMPP

The project folder should be `C:\xampp\htdocs\camera-fix-console`.

1. In the XAMPP Control Panel, start **Apache** and **MySQL**.
2. Open `http://localhost/phpmyadmin/`, create a database named
   `camera_fix_console`, then select it and import `schema.sql`.
3. Copy `config.example.php` to `config.php` in the project folder. Set
   `DB_NAME`, `DB_USER`, and `DB_PASS` to match your local MySQL account. Keep
   `COOKIE_SECURE` set to `false` when using local HTTP.
4. Open PowerShell in the project folder and import the existing camera data:

   ```powershell
   & C:\xampp\php\php.exe tools\import_existing.php
   ```

   The importer brings in cameras, account records, assignments, fix history,
   and notifications from `data/`. It generates replacement temporary
   passwords and prints them once. Save the admin password securely; imported
   accounts cannot use their old passwords. The importer only runs on an empty
   database.
5. Open `http://localhost/camera-fix-console/public/`, sign in as `admin`, and
   change the temporary password from the user menu.

The included Apache rules block direct web access to configuration, migration
scripts, and source data. Keep `config.php` and the data files private, and back
up the MySQL database regularly.

## Production hosting

Use PHP 8.1+, Apache (or another PHP-capable web server), and MySQL/MariaDB.
Configure the site's document root to `public/`; keep `config.php`, `tools/`,
and `data/` outside the public web root. Import `schema.sql`, configure the
database connection, and run `php tools/import_existing.php` once from the
command line. Set `COOKIE_SECURE` to `true` only when the site is served over
HTTPS.

## Data and workflow

MySQL stores live cameras, accounts, assignments, notifications, and fix
history. The JSON files in `data/` are retained as the one-time import source.
The admin verifies SI-marked fixes; only an admin's **Verified OK** action
changes the camera status to OK. The **Download Excel** action exports the
cameras visible to the signed-in account.
