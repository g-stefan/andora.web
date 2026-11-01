---
name: andora-web
description: What Andora is and how to use it — the PHP user accounts + admin area application on XYO.Web in this repository. Covers building and running it (fabricare make, PHP built-in server with test/_lib/router.php), the setup wizard (/setup, config.01..06 files, siteURL, lock after setup), the user pages (sign-up, e-mail confirmation, login, logout, forgot password, password reset with 24h single-use links, password rules), user levels (public / authenticated / user / administrator / root groups) and permissions (system.admin, user.manage, ...; Acl::can, grant/deny order, no groups inside groups, server-side level rules, andora-web-admin-menu table, schema upgrade), the admin pages (users, user groups, permissions, SMTP settings), configuration keys, logs and troubleshooting (501 -> _log/*.router.log, 401 on /admin), the User model API, and the test suite (fabricare test, test/_lib/site.php). Use when someone asks what Andora does, how to install, configure, operate or test it, why a page or e-mail fails, or before changing user/session/permission behaviour; use andora-web-admin for the recipes to build admin pages and andora-web-package to write a package.
---

# Andora (how to use the software)

Andora gives a PHP site its **user accounts and administration area**: a
setup wizard, sign-up / login / password recovery pages, and an admin panel for
users, user groups, permissions and SMTP settings. It runs on the XYO.Web
framework. Full documentation: `docs/` (start at `docs/README.md`).

Related skills: **andora-web-admin** (recipes for admin pages),
**andora-web-package** (writing a package),
**xyo-web-ui** / **xyo-web-datasource** (framework), **fabricare** (build).

## Build, run, test

```bash
fabricare make                                        # source/ + framework -> output/, compiles the CSS
php -S 127.0.0.1:8080 -t output test/_lib/router.php  # local run (router = what .htaccess does)
fabricare test                                        # make + php test/_run-all.php
```

- Edit `source/`, never `output/` (overwritten by every make).
- Production: copy `output/` to an Apache web root (mod_rewrite,
  mod_access_compat for the `Order/Deny` lines) or rewrite every non-file path
  to `index.php?__=<path>` and deny `config.*`, `_repository/`, `_log/`,
  `_site/vendor/`. PHP must be able to write the site root, `_repository/`, `_log/`.

## Setup wizard — `/setup`

1. Language (`en-US` / `ro-RO`) → `config.01.language.php`
2. Database: SQLite (`_repository/andora.sqlite`) or MySQL / PostgreSQL (db must
   exist) → `config.02.datasource.php`, `config.03.user.php` (random salts),
   `config.06.schema.php` (schemaVersion 1); system groups, system permissions, admin menu seeded;
   tables are created.
3. Administrator account → member of `root`, e-mail confirmed;
   then `config.04.configured.php` (`configured => true`, `siteURL`).

Before step 3 every page redirects (302) to `/setup` (AJAX / JSON / API: 401):
root `authorization.php` = `\Andora\Web\Authorization`, which every
`authorization.php` extends (`parent::checkAuthorization()` first);
`setup/authorization.php` opens the wizard. After step 3 "Thank you!" goes to `user/login` after 3 s (MessageOk `redirectURLAfterTimeout`); then `/setup` only says "Already configured!" (same redirect) — every setup step is
refused. Re-run = delete `config.*.php` (+ database). Config files are written
with `var_export` (values are data, never code).

## Pages and what they do

| Route | Behaviour |
|---|---|
| `/` | Home page: Andora logo + Login button. Root `page.php` → `\Andora\Web\Page\Home` (`_site/andora/web/page/home.php`), root `layout.php` → `\Andora\Web\Layout\Base` (also the error pages' layout); a system on Andora replaces either root file. |
| `/user/sign-up` | Creates the account (no admin access), sends the confirmation e-mail. Without SMTP: account created, page shows `configuration-error`. |
| `/user/email-confirmation?uuid=` | Marks the e-mail confirmed (`already-confirmed` the second time). |
| `/user/login` | E-mail + password; enabled accounts only. With `system.admin` (or an older schema, upgraded by `/admin`) → `admin/dashboard` after 1.5 s (MessageOk `redirectURLAfterTimeout`), others stay on "Login successfully". Cookies `_user__id` + `_user_session` (HttpOnly, SameSite=Strict). |
| `/user/forgot-password` | Same answer for known / unknown e-mails; sets `is_forgot_password`, e-mails the reset link. |
| `/user/password-reset?uuid=` | Only after a request, once, within 24h (`invalid-request` / `request-expired`). Closes all sessions. |
| `/user/logout` | Clears the session and cookies. |
| `/admin/profile` | User menu → Profile: own avatar (crop in the browser), description, new password (current password required, session kept). |
| `/user/avatar?id=&v=` | Avatar PNG of a user, logged in users only (404 if none). |
| `/admin/dashboard` | Notice for `package.manage` when a package needs attention (upgrade pending, failed upgrade, files missing, older files, broken; `Manager::needAttention`), then the panels of the user (`Dashboard::layout`: the `default` panels of `Base::dashboardPanels` until the user changes them, then the rows of `user-dashboard-panel`; `Component\DashboardPanel`, sizes 1-3 columns, a failing panel is logged, shows an error card). Core panels `andora/web/users` (`user.manage`), `andora/web/packages` (`package.manage`). |
| `/admin/dashboard/edit` | Administration popup → Dashboard (`system.admin`): the user's panels in edit mode (`Editor` component, AJAX actions `remove`, `add`, `size`, `move-left` / `move-right`, `order` from drag and drop, `reset`), saved at once (`Dashboard::saveLayout`, removed panels kept with `enabled` 0). `test/0016.dashboard.php`. |
| `/admin/user`, `/admin/user-group`, `/admin/permission`, `/admin/package`, `/admin/package/catalog`, `/admin/database`, `/admin/settings`, `/admin/about` | Admin area. |

Password rules (everywhere, `User::checkPasswordPolicy`): ≥ 12 characters,
upper + lower case letter, digit, one of `#?!@$%^&*-_`.

## Levels and permissions (`\Andora\Web\Model\Acl`)

Levels = system groups (`user-group.is_system = 1`, found by name -
`Acl::GROUP_*`, `user-group.level`): `public` 0 (visitors, implicit),
`authenticated` 1 (every logged in user, implicit), `user` 1 (every new
account joins it in `User::addUser`, removable), `administrator` 2, `root` 3
(owner, every permission; the setup account). A user's level = max level of its enabled groups; **no groups inside
groups**. The setup
account is in root, administrator and user. New groups are level 1.

`Acl::can($web, "name")`: root → yes; direct `user-x-permission` decides
(grant / deny); else any group deny → no; any group grant → yes; else no.
Disabled permission / group gives nothing. Resolved once per request
(4 queries: implicit group, `QueryUserXUserGroup`,
`QueryUserGroupXPermission`, `QueryUserXPermission`), then array lookups.

System permissions (`permission.is_system = 1`, no delete / rename / disable):
`system.admin` (`/admin`), `user.manage`, `user.delete`, `user-group.manage`,
`system.settings`, `permission.manage`, `package.manage`, `database.manage` (root only by default; the others are
granted to the `administrator` group by `Setup::dsSeed`).

`admin/authorization.php`: upgrade (`Setup::upgrade`: storageUpdateTable of
the andora/web tables + `dsSeed`) if `config.06.schema.php` is older than
`Setup::SCHEMA_VERSION`, not logged
in → 302 to `/user/login`, no `system.admin` → **401**; a page folder's
`authorization.php` extends `\Andora\Web\Route\Admin\Authorization` and sets `$permission`.
Routes `admin`, `user`, `setup` live in `_site/andora/web/route/` (mounted by
`main.php` from package.json "route", XYO.Web `Router::mount`); a root folder
`admin/` is not used.

Server-side rules: change a user / group only below your level (root: all,
`Acl::canManageUser` / `canManageGroup`), not your own groups / permissions,
give only permissions you have (`canGrantPermission`), the last enabled root
member stays, system groups are protected.

Admin menu = `andora-web-admin-menu` table (location, parent section, title, icon, path,
permission, level, position); `Setup::registerAdminMenu(...)` adds an entry once.
Locations: `admin.sidebar` = main menu (Dashboard + package pages),
`admin.panel` = the **Administration** popup at the bottom of the sidebar
(Dashboard edit, User, User group, Permission, Package, Database, Settings, About; component
`SidebarPanel`, hidden when empty), `admin.bottom` = links above it.

## Admin pages (operation)

- Tables: search, + add, sortable headers, paging, page size 10/25/50/100/all,
  row ⋮ menu, ✓/✗ switches (Enabled, Is confirmed).
- Users: e-mail must be unique (edit refuses another account's e-mail); new
  password for another user logs them out; cannot delete/disable yourself;
  delete removes group/permission links and settings.
- Groups / permissions: names unique; delete cascades to every link.
- Settings: SMTP name, username (= sender and test recipient), password,
  server, port (SMTPS, usually 465) → `config.05.smtp.php`; "Test configuration"
  sends a test mail and sets `isTested`.

## Packages (operation; writing one: **andora-web-package**)

A package = folder `_site/<vendor>/<name>/` with `package.json` (+ optional
`package.php` → class extending `\Andora\Web\Package\Base`). Full reference
`docs/package.md`, plan `docs/design/package-system.md`, developer kit +
example `acme/example` in `template/package/`.

- **States** (`Manager::discover`): not-installed, installed, disabled,
  upgrade (files newer), downgrade (older files), missing, broken (bad
  package.json), unmanaged (no package.json). `Manager::needAttention` drives
  the dashboard notice.
- **Who installs**: setup runs `Manager::installAll` (andora/web = `Core`,
  then the others by `require`); `admin/authorization.php` runs
  `Manager::syncCore` (andora/web upgraded at once); others wait for Upgrade
  on `/admin/package` (`package.manage`, root only by default). xyo/* folders
  are packages required by andora/web.
- **Zips** (`Installer`, own `Zip` reader / writer without ext-zip): upload
  (+) → stage `_repository/package/staging/` (one `_site/<vendor>/<name>/`,
  no `..` / links / `.htaccess`, ≤ 10000 files, 64 MB / file, 256 MB) →
  confirm (install / upgrade / replace; downgrade refused) → commit (old
  folder → `_repository/package/backup/`, 2 kept; SQLite copied before an
  upgrade) → `form-migrate` posted as a new request runs `Manager::migrate`.
  Export `admin/package/export?id=<vendor>_<name>`, Uninstall (Keep the
  data), Restore; lock `_repository/package/lock` (`busy`);
  `package.upload => false` turns the file operations off (not the CLI).
- **CLI** `php _site/andora/web/package/cli.php list | deploy <zip> [--trust] | migrate | install | upgrade | reset | enable | disable | uninstall [--keep-data] | restore | export | verify | trust ... | registry ... | event list|rebuild | database ...`.
- **Registries** (config files written by `Manager::done` after every
  operation, setup included): routes `config.08.route.php` (`route.mount`,
  `packageRoute`; `checkRoutes`; disabled → 404, pending upgrade → 503 in
  `\Andora\Web\Authorization`), events `config.11.event.php`
  (`EventRegistry`, `Event::dispatch` reads it, no DB), tables
  `config.12.datasource.php` (`DataSourceRegistry`).
- **Events** of andora/web: `user.create` (`User::addUser`), `user.delete`
  (admin user delete), `user.login`, `user.logout` (data `userId`).
- **Dashboard panels**: `Base::dashboardPanels()`, id `<vendor>/<name>/<panel>`;
  core panels in `Core` (`users`, `packages`).
- **Signatures** (`Signature`, `tool.php keygen|sign|verify|fingerprint`;
  `package.sig` = ECDSA P-256 over the file list): trusted keys
  `cli.php trust add <name> <pem> [--vendor=...]` → `config.09`; upload:
  trusted → accepted, unsigned / unknown → "I trust this package"
  (`trust=1`, `deploy --trust`), invalid or pinned vendor without its key →
  refused; `package.requireSignature`.
- **Remote catalog** `admin/package/catalog` (tab of admin/package, shares
  its form-confirm / form-migrate): official `Registry::OFFICIAL_URL`
  (empty; config `package.registry`) + added registries (`config.10`);
  index fetched only by the POST AJAX of the `catalog` component, cached 1 h
  in `_repository/package/catalog/`; `Remote::get` (curl): https only,
  public resolved addresses pinned with CURLOPT_RESOLVE, 3 checked
  redirects, 1 MB index / 64 MB zip, sha256 then `Installer::stage`, the
  stage must be the indexed code + version. `package.registryAllowLocal`
  (tests only).
- **Database backups** (`DatabaseBackup`): `/admin/database`
  (`database.manage`, root only; Export all, row Export, + import →
  form-restore: all or one package) and `cli.php database tables|export|import|backups`;
  zip `database.<all|vendor.name>.<YYYYMMDD-HHMMSS>.zip` = `backup.json` +
  `table/<n>-<p>.jsonl` (≤ 32 MB parts, sha256); table `andora-web-package`
  never included; restore refuses `backup-newer`, writes
  `_repository/database/backup/*.before-restore.zip` (3 kept), one
  transaction (common columns, rollback on error), then `seed()` of the
  restored active packages; `database.import => false` turns the web import off.
- **Logs**: `_log/<date>.package.log`, `_log/<date>.database.log`.
- **Tests**: `test/0007.package.php` (states, hooks), `0008` (zip),
  `0009` (routes), `0010` (signatures), `0011` (example + kit), `0012`
  (dashboard notice and panels, user.* events), `0013` (catalog, registry on
  a second `php -S`, `TestServer`), `0014` (event registry), `0015` (table
  registry, database backups), `0016` (dashboard of the users, edit page).

## Configuration keys

`language`, `dataSource.connection.db.{type,database,user,password,server,port,prefix}`,
`user.{passwordRecoSalt,loginSalt}`, `configured`, `siteURL` (base of e-mail
links — fix it when the site moves or sits behind a proxy), `smtp.{name,username,password,server,port,isTested}`,
`package.{upload,requireSignature,registry,registryAllowLocal}`, `packageTrust`
(config.09), `packageRegistry` (config.10), `packageEvent` (config.11),
`packageDataSource` (config.12), `database.import`.
Details: `docs/configuration.md`.

## Troubleshooting

| Symptom | Check |
|---|---|
| 501 | `_log/<date>.router.log` (message + stack trace). "Class not found" = namespace does not match the file path. |
| 401 on `/admin` | Account lacks `system.admin` or the page permission (see above). |
| 302 to /setup | `configured` missing (`config.04.configured.php`). |
| 302 loop to login | Not logged in. |
| No e-mails | `_log/<date>.smtp.log`; `/admin/settings` → Test configuration; `siteURL`. |
| No styles | CSS not compiled: `fabricare make` needs Node.js/npx. |
| DB data questions | Open the SQLite file with PDO (`_repository/andora.sqlite`), tables `andora-web-user`, `andora-web-user-group`, `andora-web-permission`, `andora-web-user-x-permission`, `andora-web-user-x-user-group`, `andora-web-user-group-x-permission`, ... (every andora/web table starts with `andora-web-`, a package table with `<vendor>-<name>-`) |

## Model API (`\Andora\Web\Model\User`)

`addUser`, `signUp`, `login`, `checkCurrentSession` (fills `User::$user__id`,
`$user__name`, `$user__email`, `$user__avatar`), `logoutCurrentSession`, `isAdministrator`,
`grantAdministrator`, `emailConfirm`, `forgotPassword`,
`checkPasswordResetRequest`, `passwordReset`, `sendMail`, `getSiteURL`,
`checkPasswordPolicy`, `changePassword` (needs the current password),
`setDescription`, `saveAvatar` / `deleteAvatar` / `deleteAvatarFiles`,
`getAvatarURL` / `getCurrentUserAvatarURL`. They return true/false and set `&$reason` to a short
code (`invalid-password`, `user-not-found`, `configuration-error`, ...).
Avatars: `user.avatar` = `<user id>-<32 hex>.png` in `_repository/avatar/`
(256 x 256 PNG, re-encoded with GD), served by `user/avatar/api.php`
(`?id=&v=`, logged in only); the browser crops it (`Component\InputAvatar`).
`Setup::writeConfigFile($file, $array, $reason)` writes any config file safely.

## Tests

`test/000N.*.php`, each in its own process: copies `output/` to the temp
folder, starts `php -S` on a free port, drives it like a browser
(`test/_lib/site.php`: `TestSite::start/setup/browser/db/userByEmail/routerLog`,
`TestBrowser::get/submit/postComponent/component/login`) and checks with
`check`, `check_eq`, `check_contains` (`test/_lib/harness.php`), ending with
`t_done()`. Forms are found by form id (`form_form`, `datatable_form`,
`dialog.form-edit_form`, `container.<step>_form`). `ANDORA_TEST_OUTPUT=<dir>`
tests another build. Add a test for every behaviour change.

## Rules when changing Andora

- Escape output (`htmlspecialchars(..., ENT_QUOTES)`); AJAX responses run
  their `<script>` tags with the page nonce.
- Never build PHP source from input (use `Setup::writeConfigFile`).
- Keep en-us and ro-ro language files in sync.
- Files are CRLF (some LF): keep each file's line endings; no `sed -i`.
- After changes: `fabricare test` and `python -m reuse lint`.
