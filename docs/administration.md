# Administration

The admin area is under `/admin/`. The main menu of the sidebar has
**Dashboard** (and the pages of the installed packages). At the bottom of the
sidebar, the **Administration** popup has **User**, **User group**,
**Permission**, **Package**, **Database**, **Settings**, **System** and **About**, followed by the
user menu (**Profile**, see the [User guide](user-guide.md), and **Log out**).
Each entry is shown only to the accounts that can open it; the Administration
popup is hidden when none of its entries is.

## Levels

Every account has a level, given by the groups the setup creates (the
*system groups*):

| Level | Group | Who |
|---|---|---|
| 0 | `public` | Visitors that are not logged in. |
| 1 | `authenticated` | Every logged in account, without being added to the group. |
| 1 | `user` | Every new account is added to it (sign-up, **+** Add, setup). It can be removed from an account, unlike `authenticated`. |
| 2 | `administrator` | Administrative work: users, groups, settings. |
| 3 | `root` | The owner: every permission, can change everybody. The setup account is in `root`, `administrator` and `user`. |

The level of an account is the highest level of its enabled groups. There
are no groups inside groups: an account has exactly the groups it is added to
(plus the implicit `authenticated`). A new group is at level 1; add an account
to the `administrator` group to give it the administrator level.

Rules, checked on the server (a forged request is refused too):

- You can change an account (edit, delete, enable / disable, confirm, groups,
  permissions) only if its level is lower than yours. `root` can change
  everybody. An administrator can not change a `root` account or another
  administrator.
- You can edit your own name, e-mail and password, but not your own groups or
  permissions, and you can not delete or disable yourself.
- You can give, link or change only groups with a lower level than yours
  (`root`: all), and give only the permissions you have.
- The last enabled member of `root` can not be disabled, deleted or removed
  from `root`.
- The system groups can not be deleted, renamed or disabled.

## Permissions of an account

A permission is checked by name, for example `system.admin`. An account has it
when (the first rule that applies decides):

1. it is in `root` → granted;
2. a direct link user → permission exists → **granted** or **denied**;
3. one of its groups **denies** it → denied;
4. one of its groups **grants** it → granted;
5. otherwise → denied.

A disabled permission or group gives nothing. `public` and `authenticated`
can hold permissions too (every visitor / every logged in account).

The permissions used by Andora (*system permissions*, they can not be
deleted, renamed or disabled):

| Permission | Opens | Given by the setup to |
|---|---|---|
| `system.admin` | `/admin` (dashboard) | `administrator` |
| `user.manage` | `/admin/user` | `administrator` |
| `user.delete` | deleting users | `administrator` |
| `user-group.manage` | `/admin/user-group` | `administrator` |
| `system.settings` | `/admin/settings` | `administrator` |
| `permission.manage` | `/admin/permission` | nobody (`root` only) |
| `package.manage` | `/admin/package` | nobody (`root` only) |
| `database.manage` | `/admin/database` | nobody (`root` only) |

Not logged in → redirect to `/user/login`. Logged in without the permission of
the page → 401.

Everything an account has is loaded once per request (4 database queries, 2
for a visitor); every later check is a lookup in memory.

## Tables

Every admin list is the same table: search box, **+** to add, column headers
to sort (click again to reverse), paging (first, previous, page number, next,
last), page size (10, 25, 50, 100, all) and a **⋮** menu on every row. The
check / cross columns are switches: click to change the value.

## Dashboard — `/admin/dashboard`

The first page after login. It shows:

- for the accounts with `package.manage`: a notice when a package needs
  attention (upgrade pending, the last upgrade failed, files missing, older
  files, broken), with the packages and a link to `/admin/package`; nothing
  when every package is fine;
- the panels of the account: short information cards registered by the
  packages (`Base::dashboardPanels`). Andora has two: **Users** (the number
  of accounts and the enabled ones, `user.manage`) and **Packages** (the
  number installed, the active ones and the ones that need attention,
  `package.manage`). A panel is shown only to the accounts that have its
  permission. A panel that fails shows "This panel could not be shown" and
  the error is in `_log/<date>.package.log`.

Every account has its own dashboard. Until it is changed, the dashboard shows
the default panels of the active packages; a panel added later by a package
is then on it as well. The dashboard is changed from the **Administration**
popup (**Dashboard**).

## Dashboard edit — `/admin/dashboard/edit`

In the **Administration** popup (**Dashboard**, permission `system.admin`,
the same as the dashboard). The dashboard of the account in edit mode, every
change is saved at once:

- **drag and drop** a panel to change the order (or the arrow buttons of the
  panel, also with the keyboard);
- **Size**: 1 - one column, 2 - two columns, 3 - the full row (on a narrow
  screen every panel is one column);
- **x** removes a panel, **Panels you can add** lists the ones not on the
  dashboard (**Add** puts it at the end, with its default size);
- **Reset** goes back to the default panels.

Once changed, the dashboard is the list of the account (table
`user-dashboard-panel`): the panels a package adds later are not shown, they
are in **Panels you can add**. The rows of an account are deleted with it.

## Users — `/admin/user`

Columns: name, description, e-mail, **Enabled**, **Is confirmed**.

| Action | Notes |
|---|---|
| **+** Add | Full name, e-mail, password (see the password rules in the [User guide](user-guide.md)). |
| Edit | Change name or e-mail; fill the password fields only to set a new password. The e-mail must not belong to another account. A new password for another account logs that account out everywhere. |
| Groups | Add the user to groups or remove it. |
| Permissions | Give permissions to the user. Each one has a switch: granted / denied (a denied permission is shown crossed out). |
| Delete | Removes the account, its group and permission links, its settings and its picture. You can not delete your own account. |
| Enabled | A disabled account can not log in. You can not disable your own account. |
| Is confirmed | The e-mail address was confirmed. |

## User groups — `/admin/user-group`

Columns: name, description, level, enabled. Names are unique.

| Action | Notes |
|---|---|
| Edit | Name and description (a level group keeps its name). |
| Permissions | Permissions granted or denied to every member of the group. |
| Delete | Removes the group and every link to it (members, permissions, settings). Not for the system groups. |

## Permissions — `/admin/permission`

A permission is a name (unique), a description and an enabled flag. Grant it
to users or groups from their **Permissions** menu. Deleting a permission
removes it from every user and group.

Applications built on Andora add their own permissions (dotted names, for
example `invoice.delete`) and check them with
`\Andora\Web\Model\Acl::can($web, "invoice.delete")`, see
[Development](development.md).

## Packages — `/admin/package`

The packages found in `_site/<vendor>/<name>/` (a folder with a
`package.json`), with the version of the files, the installed version and the
state. Root only (`package.manage`): a package is code.

| Action | Notes |
|---|---|
| + (upload) | Upload a package zip, see what it does (install, upgrade, replace), its requirements (each one with what the site has, the missing ones in red) and its signature, confirm (**I trust this package** when it is not signed by a trusted key): the folder is replaced, the old one kept as a backup, then the package is installed / upgraded. An install or upgrade whose requirements are not met is refused before the folder is replaced. |
| Details | Title, description, license, author, requirements, newest backup, signature, the reason of a broken package or of a failed upgrade. |
| Install | A package found but not installed (copied after the setup, or its requirements were missing at setup). |
| Upgrade | The folder was replaced by a newer version (**Upgrade pending**): runs the migrations of the new version. |
| Reset | Back to the state right after install: the data of the package is deleted. Not for `andora/web`. |
| Export | Download the package folder as a zip. |
| Restore | Put the newest backup folder back (after a failed upgrade, or an uninstall). |
| Uninstall | Remove the package (its tables unless **Keep the data**, permissions, menu entries); the folder is moved to the backups. Not for `andora/web`, nor while an installed package requires it. |
| Enabled ✓/✗ | Disable / enable an installed package (its permissions and menu entries too). Refused while an enabled package requires it, and for `andora/web`. |

An error of an operation stays on screen until **Close**: **Copy** puts its
text (the reason, its code and detail) on the clipboard. The reasons of a
package's own `check()` / hooks are shown as their code (`<vendor>-<name>.*`)
with the detail the package gives (`Manager::$detail`).

The **Catalog** tab (`/admin/package/catalog`) lists the packages of the
package registries (the official one when it is set, and the ones added with
**Add a registry**: a name and the https URL of its index; **Remove**). The
indexes are downloaded when the tab is opened. **Install** / **Upgrade**
download the zip (checked: https, public addresses only, size, sha256 of the
index), then the same confirm as an upload. Without a registry the tab says
**Not configured**. See [Remote catalog](package.md#remote-catalog).

To update a package: upload its new zip, install it from the catalog, or
delete its folder, copy the new version, open this page, **Upgrade**. `package.upload => false` turns upload,
export, restore and uninstall off (see [Packages](package.md)). `andora/web` itself is upgraded by the first admin request after
its files change. Every operation is written to `_log/<date>.package.log`.
Writing packages: [Packages](package.md).

## Database — `/admin/database`

Backups of the data, root only (`database.manage`): a backup holds the
accounts and their password hashes.

| Action | Notes |
|---|---|
| Export all | Download every table of the database: `database.all.<date-time>.zip`. |
| Export (row menu) | Download the tables of one package (its `datasource` list): `database.<vendor>.<name>.<date-time>.zip`. |
| + (import) | Upload a backup, see what it holds (date, packages and versions, each table: restored or skipped), choose every table or one package of it, confirm: the rows of these tables are replaced. |

The list shows the packages with tables (their tables and rows); below it,
the tables no package declares (only in **Export all**) and the `package`
table, which is never in a backup. Before a restore the current rows are
saved in `_repository/database/backup/` (the newest 3 copies); the restore
runs in one transaction and is refused for the data of a package newer than
its installed version. After a restore of the users you may have to log in
again. `database.import => false` turns the import off. Every export and
import is written to `_log/<date>.database.log`. See
[Database backups](package.md#database-backups).

## Admin menu

The sidebar is read from the `andora-web-admin-menu` table. An entry has a `location`
(`admin.sidebar` - the main menu, `admin.panel` - the Administration popup,
`admin.bottom` - links above the popup), a parent (a section heading of the
main menu), title, icon, path, a `permission` (empty = no permission needed), a
minimum `level`, a `position` and an enabled flag. An entry is shown when the
account has its permission and level; a section with no visible entry is
hidden. Give an entry the same permission as its page, so the menu and the
access always agree.

## Settings — `/admin/settings`

The SMTP account used for the sign-up, forgot password and password changed
e-mails:

| Field | Meaning |
|---|---|
| Name | Sender name. |
| Username | SMTP login; also the sender address and the address of the test e-mail. |
| Password | SMTP password (asked again on every save). |
| Server | SMTP server name. |
| Port | Usually 465. The connection uses implicit TLS (SMTPS). |

**Save configuration** writes `config.05.smtp.php`. **Test configuration**
sends a test e-mail; the status shows *Not configured*, *Configured, not
tested* or *Configured and tested*. Errors are logged in
`_log/<date>.smtp.log`.

## System — `/admin/system`

What the server gives Andora, to find why something does not work on a
hosting: the system check of the setup (PHP version, required and optional
extensions, database drivers, the folders PHP must write, the upload and
session folders, whether `_repository/` and `_log/` are private), the
`php.ini` limits (`memory_limit`, `upload_max_filesize`, `post_max_size`,
`max_execution_time`, `display_errors`, `date.timezone`, `open_basedir`, ...),
the server (SAPI, the PHP user and the owner of the files, HTTPS, `siteURL`
compared with the current address, free disk space), the database (type,
version, connection) and the newest files of `_log/` with the end of the last
`router.log`. Each row is OK, an error, a warning or not available, with the
reason. Needs `system.settings`.

## About — `/admin/about`

The Andora logo, the version, build and build date (from `version.json`, set
by the build in `_site/andora/web/andora.php`), the license (Apache License,
Version 2.0) and the third party components with their licenses. Every
account that opens the admin area (`system.admin`) can see it.

## Good practice

- Keep at least two accounts in `root`.
- Give the administrator level through the `administrator` group instead of
  permission by permission; use a direct **denied** link to exclude one member
  from one permission.
- Back up the database (`_repository/andora.sqlite` for SQLite) together with
  the `config.*.php` files; they contain the database and SMTP passwords, keep
  the backup private.
