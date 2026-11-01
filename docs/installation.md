# Installation and setup

## Requirements

- PHP 8 (8.2 or newer recommended) with the extensions `pdo`, `json`,
  `mbstring`, `openssl`, `session`, `filter`, `hash`, and the PDO driver of
  the database: `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`. Optional: `gd`
  (avatars), `curl` (remote package catalog), `zlib` or `zip` (package zips,
  database backups), `intl`, `fileinfo`, OPcache. The setup wizard checks
  them (see below).
- A web server that runs `index.php` for every path that is not a file:
  - **Apache** with `mod_rewrite`. The `.htaccess` files in the site do the
    rewrite and deny access to the private folders. They use the
    `Order` / `Deny` directives, so Apache 2.4 needs `mod_access_compat`.
  - **Other servers** (nginx, IIS, ...): rewrite every non-file request to
    `index.php?__=<path>`, and deny `config.*`, `_repository/`, `_log/`,
    `_site/vendor/` and `_site/composer.*`.
- The site folder must be writable by PHP: the setup wizard and the SMTP
  settings write `config.*.php` files in the site root, SQLite keeps its
  database in `_repository/`, and logs go to `_log/`.
- Database (optional): MySQL 8.0+ / MariaDB 10.5+ or PostgreSQL. SQLite needs
  no server.
- An SMTP account (SMTPS, usually port 465) to send the sign-up and password
  e-mails. Without it everything works except the e-mails.

Build tools (only to build from source): [fabricare](https://github.com/g-stefan/fabricare),
`7z`, `curl`, Node.js with `npm` / `npx` (Tailwind CSS and daisyUI).

## Build

From the repository root:

```bash
fabricare make
```

`fabricare make`:

1. Downloads the vendor bundles into `vendor/` if they are missing: the
   XYO.Web framework and library (versions in `fabricare.json`) and a Tailwind
   CSS / daisyUI package.
2. Unpacks the framework into `output/`.
3. Copies `source/` over it.
4. Compiles `output/_site/andora/web/client/andora.css` with Tailwind CSS.

`output/` is the complete site. The other commands:

| Command | What it does |
|---|---|
| `fabricare make` | Build `output/`. |
| `fabricare test` | Build, then run the tests (see [Development](development.md)). |
| `fabricare clean` | Remove the build. |
| `fabricare release` | Package the release. |

## Deploy

Copy the content of `output/` to the web root (or to a sub folder, the site
works under any path). Do not copy any `config.*.php` from a development
machine: the setup wizard writes them.

## Setup wizard

Open the site: until the setup is done every page redirects to
`https://your-site/setup`. It has three steps:

1. **Language** of the installation (English or Romanian). Written to
   `config.01.language.php`. Below it, the **System check**: the PHP version,
   the folders PHP must write (the site root, `_repository/`, `_log/`, and
   `_site/` for packages from a zip, tested by writing a file), the upload
   and session folders, the required extensions, the database drivers, the
   optional extensions, the main `php.ini` limits and, from the browser,
   whether `_repository/` and `_log/` are private (a `200` means `.htaccess`
   is not used). **Begin** is disabled until the requirements are met: fix
   them (cPanel: *MultiPHP Manager* / *Select PHP Version* for the version
   and the extensions, *File Manager* for the permissions), then
   **Check again**.
2. **Database.** Only the types PHP has a driver for are listed.
   - *SQLite*: the database is `_repository/andora.sqlite`.
   - *MySQL / PostgreSQL*: database name, user name, password, server, port
     and an optional table prefix. The database must already exist.

   The wizard checks the connection and creates the tables, the system groups
   (`public`, `authenticated`, `user`, `administrator`, `root`), the system
   permissions and the admin menu. It writes `config.02.datasource.php`,
   `config.03.user.php` (random salts) and `config.06.schema.php`.
3. **Administrator account**: full name, e-mail and password (see the password
   rules in the [User guide](user-guide.md)). The account is added to the
   `root`, `administrator` and `user` groups (the owner), and its e-mail is marked as confirmed.

At the end the wizard writes `config.04.configured.php`. It holds
`configured => true` and `siteURL`, the address the setup was opened with.
`siteURL` is used for the links in the e-mails; change it there if the site
moves (see [Configuration](configuration.md)).

The wizard then shows "Thank you!" and, after 3 seconds, opens the login
page. From then on `/setup` only shows "Already configured!" and also
opens the login page after 3 seconds. To run the setup
again, delete the `config.*.php` files (and the database).

## After the setup

1. Log in at `/user/login` with the administrator account.
2. Go to `/admin/settings` and fill in the SMTP account, then use
   **Test configuration**. A test e-mail is sent to the SMTP user name.
3. Create users, groups and permissions in the admin area, or let people sign
   up at `/user/sign-up`. See [Administration](administration.md).

## Local run without Apache

```bash
php -S 127.0.0.1:8080 -t output test/_lib/router.php
```

`test/_lib/router.php` does what the `.htaccess` files do. Use it only for
development: the PHP built-in server is single threaded and not hardened.

## Troubleshooting

| Symptom | Where to look |
|---|---|
| Something does not work on a hosting | `/admin/system` (Administration → System): the system check, the PHP limits, the server, the database and the end of the last `router.log`. |
| A page answers **501** | `_log/<date>.router.log` has the error and the stack trace. |
| E-mails are not sent | `_log/<date>.smtp.log` has the SMTP error. Check the settings in `/admin/settings`. |
| Database errors | `_log/<date>.<driver>-connection.log`. |
| `/admin/...` answers **401** | You are logged in with an account without the `admin` permission. |
| The site has no styles | The CSS was not compiled: run `fabricare make` with Node.js available. |
