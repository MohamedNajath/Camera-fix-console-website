# Camera Fix Console

PHP/MySQL camera-fix register for administrators and SI (contractor) accounts.
Administrators can manage cameras, assign sites, and verify fixes. SI users see
only their assigned sites and receive notifications when a camera needs more
work.

## Requirements

- XAMPP with Apache and MySQL running.
- PHP 8.1 or later with `pdo_mysql`, `zip`, and DOM/XML enabled.
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
To add cameras from a separate same-structure workbook, sign in as admin and
click **Import new cameras**. Select the `.xlsx` file and review the preview of
new camera IDs and sites. Confirm to import; IDs already in MySQL are skipped,
and new rows are appended to the master workbook. Newly imported sites start
unassigned and can then be assigned to an SI from the site page. Keep the
master workbook closed in Excel while importing.
When an admin verifies a fix, the source workbook is updated by camera ID with
the final status and date. Each admin refix response fills the next Remark 2,
Remark 3, or Remark 4 field and its date; further responses are appended to
Remark 4. Keep `source-data/camera-fix-register.xlsx` closed in Excel while
admin verification actions run. If the workbook cannot be updated, the action
is rejected and the database change is rolled back. **Download Excel** exports
the cameras visible to the signed-in account using the same status and remark
columns.
