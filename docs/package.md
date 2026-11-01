# Packages

A package is one folder, `_site/<vendor>/<name>/`, with a `package.json`.
The classes of the package are found by the autoloader in the same folder
(`\Acme\Blog\Model\Post` is `_site/acme/blog/model/post.php`). To update a
package, delete its folder and copy the new one; the admin page
**Package** (`/admin/package`) then shows **Upgrade pending** and runs the
migrations of the new version. A package can also be uploaded as a zip
from the same page (or deployed with `cli.php`), installed from a
[package registry](#remote-catalog) (**Catalog** tab), exported as a zip and
uninstalled. The build (`fabricare make`) and the framework zips use the same
layout.

To start a package, copy the developer kit `template/package/` of this
repository: the example package `acme/example` and its build (see
[Developer kit](#developer-kit)). The plan of the package system is in
[design/package-system.md](design/package-system.md).

## Layout

```
_site/acme/blog/
    package.json        manifest (data)
    package.php         return \Acme\Blog\Package\Setup::class;
    index.html
    package/setup.php   lifecycle class (extends \Andora\Web\Package\Base)
    route/blog/         pages of the route blog (package.json "route")
    route/admin/blog/   an admin page: admin/blog
    data-source/ model/ component/ language/ client/
```

- Every PHP file starts with `defined("ANDORA") or die("Forbidden");` and every
  folder has an `index.html` (`_site/` is readable from the web for assets).
- Runtime data never goes in the package folder (it is replaced on every
  update): use the database or `_repository/<vendor>/<name>/`
  (`$this->dataPath()`).
- CSS is shipped compiled: nothing is compiled when a package is installed.

## `package.json`

```json
{
    "vendor": "acme",
    "name": "blog",
    "version": "1.2.0",
    "title": "Blog",
    "description": "Articles and categories",
    "license": "Apache-2.0",
    "author": "ACME <office@acme.example>",
    "require": {
        "php": ">=8.1",
        "extension": ["gd"],
        "andora/web": ">=0.4 <1.0",
        "xyo/web": "*"
    },
    "route": {
        "blog": "route/blog",
        "admin/blog": "route/admin/blog"
    },
    "event": {
        "provide": {
            "acme-blog.post-publish": "A post was published: postId"
        },
        "listen": {
            "user.delete": "onUserDelete"
        }
    },
    "datasource": [
        "Acme\\Blog\\DataSource\\TablePost"
    ]
}
```

| Key | |
|---|---|
| `vendor`, `name` | required, equal to the folder names, lower case letters and digits joined by `-`. `vendor` is reserved (composer). |
| `version` | required, `major.minor.patch`. |
| `title`, `description`, `license`, `author` | optional text. |
| `protected` | `true` only for `andora/web`: no reset, no disable. |
| `require` | `php` (version constraint), `extension` (list of PHP extensions), `<vendor>/<name>` (version constraint). |
| `route` | route path => folder of the package, see [Routes](#routes). Path and folder: segments of `a-z 0-9 . _ -` starting with a letter or digit. |
| `event` | `provide`: the events of the package (event => description), `listen`: event => method of the lifecycle class, see [Events](#events). |
| `datasource` | the `Table` classes of the package (list of class names, `\\` in JSON), see [Tables](#tables). Every package with tables must list them. |

A version constraint is a list of parts separated by spaces, all must match:
`>=1.0.0`, `<2.0`, `>1.2`, `<=1.4.0`, `!=1.3.0`, `=1.2.3` or `1.2.3`
(exact); `*` or empty is any version. `1.2` means `1.2.0`. A required package
must be installed and enabled; a framework folder without `package.json`
(`xyo/web`, `xyo/inter-font`, ...) only has to exist (`xyo/web` is checked
against `XYO_WEB_VERSION_STR`).

## Routes

The pages of a package stay in its folder: `"route": {"blog": "route/blog"}`
serves `/blog` (and everything below it) from `_site/acme/blog/route/blog/`
(`page.php`, `api.php`, `slug.php`, `service.php`, `language/`), with the
XYO.Web route mounts (`route.mount`). Andora writes them to
`config.08.route.php` after every package operation; the routes of
andora/web (`admin`, `user`, `setup`) are mounted by `main.php`, so they work
before the setup.

- A route below `admin/` (`admin/blog`) gets the admin layout and the access
  check of `admin/authorization.php`; its own `authorization.php` extends
  `\Andora\Web\Route\Admin\Authorization` and sets `$permission`.
- The classes of a route folder follow the folder:
  `_site/acme/blog/route/admin/blog/form-add.php` is
  `\Acme\Blog\Route\Admin\Blog\FormAdd`.
- The folder path (`/_site/acme/blog/route/blog`) is never a route (404): the
  pages are reached only through the route, with its access rules.
- A disabled or uninstalled package: 404. Files newer than the installed
  version (an upgrade is pending): 503 until **Upgrade**; a route added by
  the new version is mounted by the upgrade.
- Install, upgrade and enable refuse a route used by another enabled
  package, by andora/web, or by a folder of the site root
  (`route-conflict`), and a route folder that does not exist
  (`route-folder-missing`).

## Tables

The tables of a package are its `\XYO\Web\DataSource\Table` classes, listed
in `package.json`: `"datasource": ["Acme\\Blog\\DataSource\\TablePost"]`.
Andora knows from this list which tables belong to which package, so it can
back up and restore the data of one package
([Database backups](#database-backups)); the database itself only says which
tables exist.

- A class must be in the package folder: `Acme\Blog\DataSource\TablePost` is
  `_site/acme/blog/data-source/table-post.php` (else the manifest is
  `invalid-datasource`, the package broken).
- The default hooks of `Base` (install, upgrade, reset, uninstall) work with
  `$tables`, which is this list unless the class sets `$tables` itself; a
  class of `$tables` that is not in the list is refused
  (`datasource-undeclared`).
- A table name is `<vendor>-<name>-<table>`, `<table>` of lower case
  letters, digits and `-` (`acme-blog-post`; andora/web: `andora-web-user`).
- Install, upgrade and enable refuse a class that is missing or not a
  `Table` (`datasource-missing`), a table name that does not start with the
  vendor and name of the package (`datasource-prefix`) and a table already
  declared by andora/web or another installed package
  (`datasource-conflict`: `andora-web/user` with the table `group` is
  `andora-web-user-group`, a table of andora/web).
- After every operation (and the setup) the package manager writes the
  registry `config.12.datasource.php` (`packageDataSource`: table =>
  package, class): andora/web and every installed package. A package
  uninstalled with **Keep the data** (files missing) keeps its tables in the
  registry.
- **Details** on `/admin/package` shows the tables of a package.

## Lifecycle class

`package.php` only returns the class name (it can be included more than
once). Without `package.php` the package is files only (fonts, icons, a
library) and `\Andora\Web\Package\Base` is used as it is.

```php
<?php
namespace Acme\Blog\Package;

defined("ANDORA") or die("Forbidden");

use \Acme\Blog\DataSource\TablePost;
use \Andora\Web\Model\Acl;

class Setup extends \Andora\Web\Package\Base
{
    // $tables: the "datasource" of package.json (TablePost), created by
    // install, updated (new columns) by upgrade, emptied by reset, dropped
    // by uninstall

    // The rows the code needs, called after install, upgrade, reset and a
    // restore of the tables of the package from a database backup
    public function seed(&$reason)
    {
        return $this->registerPermission("acme-blog.manage", "Manage the blog", $reason)
            && $this->grantPermissionToGroup("acme-blog.manage", Acl::GROUP_ADMINISTRATOR, $reason)
            && $this->registerAdminMenu("acme-blog", "admin.sidebar", "admin.home", "Blog", "newspaper", "admin/blog", "acme-blog.manage", Acl::LEVEL_PUBLIC, 900, $reason);
    }

    public function upgrade($fromVersion, &$reason)
    {
        if (!parent::upgrade($fromVersion, $reason)) {
            return false;
        }
        if (version_compare($fromVersion, "1.1.0") < 0) {
            // data migration of 1.1.0
        }
        return true;
    }

    // package.json "event": {"listen": {"user.delete": "onUserDelete"}}
    public function onUserDelete($event)
    {
        $dsPost = $this->getDataSource(TablePost::class);
        $dsPost->clear();
        $dsPost->user__id = $event->get("userId");
        $dsPost->delete();
    }
}
```

| Hook | When |
|---|---|
| `check(&$reason)` | before install and upgrade, for requirements `package.json` can not express |
| `install(&$reason)` | first install (default: create `$tables`, `seed`) |
| `upgrade($fromVersion, &$reason)` | the files are newer than the installed version (default: update `$tables`, `seed`) |
| `reset(&$reason)` | back to the state right after install (default: recreate `$tables` empty, `seed`) |
| `enable(&$reason)`, `disable(&$reason)` | before the package is enabled / disabled |
| `uninstall($keepData, &$reason)` | for the uninstall of a next version (default: drop `$tables` unless `$keepData`) |
| `seed(&$reason)` | the rows the code needs, after install, upgrade, reset and a database restore of the tables of the package; safe to run again |
| `dashboardPanels()` | the panels of the package for `/admin/dashboard`, see [Dashboard panels](#dashboard-panels) |
| listeners | the methods named in `package.json` `event.listen`, see [Events](#events) |

Hooks return `true` / `false` and set `&$reason` to a short code, shown on the
admin page and written to `_log/<date>.package.log`. A hook can say more in
`\Andora\Web\Package\Manager::$detail` (a PHP or openssl error message, a
path): it follows the reason in the error dialog (which stays open, with
**Copy**) and in the log.

The `require` of `package.json` (PHP version, extensions, packages) is checked
before any hook: the upload confirm and the Install / Upgrade dialogs list
each requirement with what the site has, and refuse (before the files are
replaced) while one is missing. `check()` is for what `require` can not say
(an extension that is loaded but does not work, a writable folder).

### Events

A package **provides** events (it fires them) and **listens** to the events
of andora/web and of the other packages, both declared in `package.json`:

```json
"event": {
    "provide": {
        "acme-blog.post-publish": "A post was published: postId"
    },
    "listen": {
        "user.create": "onUserCreate",
        "user.delete": "onUserDelete",
        "acme-shop.order-paid": "onOrderPaid"
    }
}
```

A listener is a public method of the lifecycle class; it gets an
`\Andora\Web\Package\Event`: `$event->name`, `$event->source` (the package
that provides the event), `$event->data`, `$event->get($key, $default)`:

```php
public function onUserCreate($event)
{
    $this->createProfile(intval($event->get("userId")));
}
```

A package fires its events from any of its code, after the change is saved:

```php
use \Andora\Web\Package\Event;

$results = Event::dispatch($this->web, "acme-blog.post-publish", array("postId" => $postId));
// <vendor>/<name> of each listener => what its method returned
```

The events of andora/web (`data`: `userId`):

| Event | When |
|---|---|
| `user.create` | an account was created: sign-up, `/admin/user`, the administrator of the setup (`User::addUser`) |
| `user.delete` | a user was deleted in `/admin/user`: remove the rows that reference it |
| `user.login` | a user logged in (`User::login` succeeded) |
| `user.logout` | a user logged out (`User::logoutCurrentSession`) |

**The registry.** The package manager writes the events to
`config.11.event.php` (like the routes to `config.08.route.php`): the setup
and every package operation (install, upgrade, reset, enable, disable,
uninstall, restore, upload) write it again. It is read with the
configuration, so `Event::dispatch` makes no database query and scans no
folder: it reads the `package.json` of each listener it calls (once per
request) to check that its files are still the installed version.

- **provide**: andora/web and the enabled packages with files. An event
  name is segments of `a-z 0-9` joined by `-`, at least two joined by `.`;
  start the events of a package with its name (`acme-blog.`).
- **listen**: the **active** packages (installed, enabled, files of the
  installed version), for the events that are provided; in the order of the
  package codes. A listener of an event no package provides (a package that
  is not installed) is kept in `package.json` and registered when the
  provider is installed: a package listens to another one without
  requiring it.
- Install, upgrade and enable refuse an event already provided by
  andora/web or by another enabled package (`event-conflict`) and a
  listener that is not a public method of the lifecycle class or is a hook
  of `Base` (`install`, `seed`, ...: `event-listener-missing`); an invalid
  `event` in `package.json` makes the package **Broken** (`invalid-event`).
- An exception of a listener is written to `_log/<date>.package.log` and
  the other listeners still get the event: the request (the login, the
  sign-up) is not affected. While an upgrade is pending, the listeners of the
  package are not called. An event that no package provides is not
  dispatched (`undeclared-event` in the log).
- `php _site/andora/web/package/cli.php event list` shows the registry,
  `event rebuild` writes it again (a site copied without its config files).
  Without the registry no package gets an event.

**Details** on `/admin/package` shows the events a package provides and
listens to.

### Dashboard panels

A panel is a card of short information on `/admin/dashboard`. The users
choose the panels they want, their order and size (`/admin/dashboard/edit`).

```php
public function dashboardPanels()
{
    return array(
        "posts" => array(                         // the id is acme/blog/posts
            "title" => "Blog",                    // escaped by the dashboard
            "description" => "The posts of the blog.", // optional, shown on the edit page
            "icon" => "newspaper",                // optional, a lucide icon
            "permission" => "acme-blog.manage",   // optional: Acl::can
            "size" => 1,                          // optional: 1 (default), 2 columns, 3 - the full row
            "default" => true,                    // optional: on the dashboard of the users that did not change it
            "render" => function ($web) {         // echo the HTML, escape what you output
                echo "<p>Posts: " . intval($this->countPosts()) . "</p>";
            }
        ),
        "comments" => array(
            "title" => "Comments",
            "component" => \Acme\Blog\Component\CommentsPanel::class   // a \XYO\Web\Component
        )
    );
}
```

The key is the name of the panel (`[a-z0-9]` words joined by `-`, at most
64), the id of the panel is `<vendor>/<name>/<panel name>`, saved in the
dashboards of the users: keep the names when the package changes. A panel
without a valid name, title, `render` or `component` is left out.

A user that did not change the dashboard sees the `default` panels of the
active packages (in the order of the packages, then of the panels); once
changed, the panels are the list of the user and a new panel is only in
**Panels you can add**. A panel is shown only to the users that may see it
(`permission` empty or `Acl::can`), only while its package is active.

A `component` is registered on the dashboard as
`panel-<vendor>--<name>--<panel name>` (request id `<that>/content`), so it
can refresh itself with AJAX. An exception of `dashboardPanels()`, of the
render callback or of the component (init or render) is written to
`_log/<date>.package.log`; the card shows "This panel could not be shown"
and the rest of the dashboard is not affected. andora/web has the panels
`users` and `packages` (`\Andora\Web\Package\Core`), the example package
has one (`template/package`).

Helpers: `getDataSource`, `createTables` / `updateTables` / `recreateTables` /
`dropTables`, `registerPermission` (a system permission tagged with the
package; disabled with the package), `grantPermissionToGroup`,
`registerAdminMenu` (tagged; hidden while the package is disabled),
`dataPath`, `getCode`, `getVersion`.

## States

| State | Meaning | Operations |
|---|---|---|
| Not installed | folder found, never installed | Install |
| Installed | enabled, the files are the installed version | Reset, Disable |
| Disabled | | Reset, Enable |
| Upgrade pending | the files are newer | Upgrade |
| Older files | the files are older than the installed version | none: copy the newer files back |
| Files missing | installed, the folder is gone (or uninstalled keeping the data) | Restore, Uninstall; or upload the files again |
| Uninstalled | only the backups are left | Restore |
| Broken | invalid `package.json` (Details shows why) | Uninstall |
| No manifest | folder without `package.json` | none |

Every installed package can also be **exported** and **uninstalled** (not
`andora/web`); **Restore** is offered while a backup exists.

The users with `package.manage` see a notice on `/admin/dashboard` when a
package needs attention: upgrade pending, a failed last upgrade, files
missing, older files, broken (`Manager::needAttention`), with a link to
`/admin/package`. The folders are scanned for that only for these users.

A package that another enabled package requires can not be disabled; a
package can not be enabled while a package it requires is disabled. When the
last upgrade failed, the installed version stays, the reason is shown in
Details and Upgrade can be run again.

## Who installs what

- The setup wizard installs `andora/web` (the database), then every package
  found in `_site/`, each after the packages it requires. A package that can
  not be installed (missing requirement, failing hook) stays **Not
  installed** and the reason is in `_log/<date>.package.log`.
- `andora/web` is upgraded at once by the first admin request after its files
  change (the admin area needs it); the other packages wait for **Upgrade**.
- The framework folders are packages too: `xyo/web`, `xyo/inter-font`,
  `xyo/lucide-icons`, `xyo/overlay-scrollbars` (from xyo.web 0.148.0 and
  xyo.web.library 0.22.0). andora/web requires them, so they can not be
  disabled or uninstalled; they can be upgraded by uploading their zip
  (`xyo.web.v<version>.zip`). A site made before is given them by the
  upgrade of andora/web.
- `/admin/package` needs the permission `package.manage`: root only by
  default (a package is code, installing it is full control of the site).

`\Andora\Web\Package\Manager` has the same operations for code and scripts:
`discover`, `get`, `isActive`, `install`, `upgrade`, `reset`, `enable`,
`disable`, `uninstall`, `migrate`, `installAll`, `active`,
`dashboardPanels`, `needAttention` (`$reason`, `Manager::$detail`);
`\Andora\Web\Package\Event::dispatch` fires an event and
`\Andora\Web\Package\EventRegistry` has the registry: `load`, `provider`,
`listeners`, `check`, `build`, `write`;
`\Andora\Web\Package\Installer` has the file operations: `stage`, `commit`,
`restore`, `export`, `backups`; `\Andora\Web\Package\Registry` the
[catalog](#remote-catalog): `all`, `add`, `remove`, `fetch`, `cached`,
`download`.

## Zip files

A package zip has the layout of the site: every file under one
`_site/<vendor>/<name>/`, named `<vendor>.<name>.v<version>.zip` like the
framework zips (`xyo.web.v0.147.0.zip`). The same zip goes in `vendor/` of a
project and `fabricare make` extracts it, or it is uploaded to a running site.

### Upload (`/admin/package`, the **+** button)

1. **Upload**: the zip is extracted to `_repository/package/staging/<id>/`
   (not served) and checked: one package, only files under
   `_site/<vendor>/<name>/`, no `..`, absolute paths, `\`, drive letters,
   symbolic links or encrypted entries, no `.htaccess`, `.htpasswd`,
   `.user.ini`, `php.ini`, `web.config`, at most 10000 files, 64 MB per file
   and 256 MB in all (sizes and CRC checked while extracting), a valid
   `package.json` with the folder's vendor and name.
2. **Confirm**: what the upload does: **install** (new), **upgrade** (newer than
   the installed version), **replace** (same version: the files are copied
   again), or refused for an older version (**Restore** goes back instead);
   and its [signature](#signed-packages). A package not signed by a trusted
   key needs the **I trust this package** checkbox.
3. The folder is replaced: the old one is moved to
   `_repository/package/backup/<vendor>.<name>/<time>-<microseconds>-<version>/` (the 2
   newest are kept). Nothing of the new version runs in this request.
4. The answer asks for a **new request** that installs or upgrades the
   package with the new files (for SQLite the database is copied to
   `backup/<vendor>.<name>/database-<time>-<version>.sqlite` before an
   upgrade; MySQL / PostgreSQL: back up before).

A failed upgrade keeps the installed version and shows the reason; **Restore**
puts the newest backup folder back (the current one becomes a backup), then
the same migration runs. The database copy is not restored automatically.

The upload size is limited by PHP: `upload_max_filesize` and `post_max_size`.
Without `ext-zip`, the own zip reader / writer is used (zlib: stored and
deflate entries; no zip64).

### Export

Row menu → **Export** downloads the package folder as
`<vendor>.<name>.v<version>.zip` (`admin/package/export?id=<vendor>_<name>`).

### Uninstall

Runs the `uninstall` hook (default: drop `$tables`), deletes the permissions
(and their grants) and admin menu entries of the package and its record, and
moves the folder to the backups. **Keep the data**: the tables stay, the
record stays (disabled) and the package shows **Files missing**; uploading
the same files again brings it back, disabled. Refused for `andora/web` and
while an installed package requires it. A package found but never installed
only has its folder moved.

### Turning the file operations off

```php
// config.07.package.php
return array(
    'package' => array('upload' => false),
);
```

Upload, export, restore and uninstall are then refused on the admin page (the
list, Install, Upgrade, Reset and Enable / Disable stay, for folders copied by
FTP or by a deployment). The command line is not affected.

One operation runs at a time (`_repository/package/lock`); another one
gets **busy**.

## Command line

For deployments and CI, from any folder (the site must be configured):

```
php _site/andora/web/package/cli.php list
php _site/andora/web/package/cli.php deploy acme.blog.v1.2.0.zip [--trust]
php _site/andora/web/package/cli.php migrate acme/blog
php _site/andora/web/package/cli.php install|upgrade|reset|enable|disable acme/blog
php _site/andora/web/package/cli.php uninstall acme/blog [--keep-data]
php _site/andora/web/package/cli.php restore acme/blog
php _site/andora/web/package/cli.php export acme/blog [file.zip]
php _site/andora/web/package/cli.php verify acme/blog
php _site/andora/web/package/cli.php trust list
php _site/andora/web/package/cli.php trust add acme public.pem [--vendor=acme]
php _site/andora/web/package/cli.php trust remove acme
php _site/andora/web/package/cli.php registry list
php _site/andora/web/package/cli.php registry add acme https://packages.acme.example/index.json
php _site/andora/web/package/cli.php registry remove acme
php _site/andora/web/package/cli.php event list
php _site/andora/web/package/cli.php event rebuild
php _site/andora/web/package/cli.php database tables
php _site/andora/web/package/cli.php database export [all|acme/blog] [file.zip]
php _site/andora/web/package/cli.php database import file.zip [--package=acme/blog]
php _site/andora/web/package/cli.php database backups
```

`deploy` = upload + commit, then `migrate` runs in a new PHP process;
`--trust` accepts a package not signed by a trusted key (the "I trust this
package" of the admin page; a pinned vendor still needs its key). Exit
code 0 on success, 1 with `Error: <reason>` on stderr. `cli.php` answers 404
to web requests. The log records the user as `cli`.

## Database backups

The rows of the tables (not the files of the packages) as a zip, from the
admin page **Database** (`/admin/database`, permission `database.manage`,
root only by default) or `cli.php database ...`:

- **Export all**: every table of the database (with the prefix of the
  connection, if one is set), also the tables no package declares.
- **Export** in the menu of a package row: the tables of that package (its
  `datasource`, from the registry).
- The file is `database.<all | vendor.name>.<YYYYMMDD-HHMMSS>.zip`;
  `cli.php database export` writes it to `_repository/database/backup/`
  unless a file is given.
- The table `andora-web-package` (the state of the package files of the
  site) is never in a backup.

Inside: `backup.json` (`format` `andora-database`, `formatVersion`,
`created`, `andora`, `database`, `scope`, `packages`: code => installed
version, `tables`: name without the prefix, package, columns, rows, parts)
and `table/<n>-<p>.jsonl`: the rows, one JSON list per line in the order of
the columns, a value that is not UTF-8 as `{"base64": "..."}`, in parts of up
to 32 MB, each with its sha256.

**Import** (the **+** button, or `cli.php database import`): the zip is
checked and shown (content, date, the packages and their installed versions,
what happens to each table); a backup of every table can be restored whole
or for one of its packages (`--package=` on the command line). Then:

1. Refused: a backup of a newer format, the data of a package newer than its
   installed version (`backup-newer`), a package with no tables in the
   backup, no table of the backup in the database.
2. A copy of the current rows of the tables to restore is written to
   `_repository/database/backup/*.before-restore.zip` (the newest 3 are
   kept).
3. In one transaction, for each table of the backup that exists in the
   database: all its rows are deleted, the rows of the backup inserted
   (the columns the table still has; a newer column gets its default). A
   table missing from the database is skipped. Any error, or a changed table
   file (sha256), rolls everything back.
4. `seed()` of every active package whose tables were restored (andora/web:
   the system groups, permissions and menu).

The users are data like any other: restoring the tables of andora/web
brings back the accounts, groups, permissions and sessions of the backup
(log in again if yours changed). The passwords only work with the salts of
`config.03.user.php` of the site that made the backup. `database.import =>
false` (a config file) turns the import off on the admin page (the command
line still works). Log: `_log/<date>.database.log`. Test:
`test/0015.database-backup.php`.

## Signed packages

A package can carry `package.sig` in its folder: an ECDSA P-256 / SHA-256
(OpenSSL) signature of the list of its other files (SHA-256 of each file and
its path) and of its `<vendor>/<name>`. Any change of a file, an added or a
removed file makes the signature invalid.

The author signs with `tool.php` (no site needed):

```
php _site/andora/web/package/tool.php keygen private.pem public.pem
php _site/andora/web/package/tool.php sign <folder _site/<vendor>/<name>> private.pem
php _site/andora/web/package/tool.php verify <folder> public.pem
php _site/andora/web/package/tool.php fingerprint public.pem
```

(the developer kit runs `sign` between `make` and `release`). The private
key never goes in the package nor on the site. The site owner trusts the
public key:

```
php _site/andora/web/package/cli.php trust add acme public.pem --vendor=acme
```

(kept in `config.09.package-trust.php`, `packageTrust`). On upload:

| Signature | Upload |
|---|---|
| signed by a trusted key | accepted |
| unsigned, signed by an unknown key, or by a trusted key not given for this vendor | needs **I trust this package** (`deploy --trust`); refused with `package.requireSignature => true` |
| invalid (the files were changed, a broken `package.sig`) | refused |
| a **pinned vendor** (named by `--vendor` of a trusted key) not signed by a key that names it | refused, even with the confirmation |

Pin the framework and Andora with the key of their releases
(`trust add xyo <xyo public key> --vendor=xyo,andora`): their zips are then
accepted only signed by it. Folders copied by FTP or by a build are not
checked (Details shows their signature, `cli.php verify`); only uploads and
`deploy`.

## Remote catalog

`/admin/package/catalog` (the **Catalog** tab of `/admin/package`,
`package.manage`) lists the packages of the package registries, with the
version against the installed one: **Install** (not installed), **Upgrade**
(newer), **Installed** (same version), or a newer version is installed.
Install and Upgrade download the zip, check it, then show the same confirm as
an upload (signature, **I trust this package**) and run the migration in a
new request. Turned off with the other file operations (`package.upload =>
false`): the list stays, without the buttons.

### Registries

- The **official** registry: `Registry::OFFICIAL_URL` (empty for now: none),
  or the config `package.registry` (`config.07.package.php`; a mirror, an own
  registry). It can not be removed from the page.
- The registries added on the page (**Add a registry**: a name of lower case
  letters, digits and `-`, and the https URL of the index) or with
  `cli.php registry add <name> <url>`; kept in
  `config.10.package-registry.php`, removed with **Remove** /
  `cli.php registry remove <name>`.

Without any registry the page shows **Not configured**.

### Index

A registry is one JSON file (static hosting is enough):

```json
{
    "packages": [
        {
            "vendor": "acme",
            "name": "blog",
            "version": "1.2.0",
            "title": "Blog",
            "description": "Articles and categories",
            "url": "acme.blog.v1.2.0.zip",
            "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
        }
    ]
}
```

| Key | |
|---|---|
| `vendor`, `name` | as in `package.json` |
| `version` | `major.minor.patch`; for the same package the highest version is listed |
| `title`, `description` | optional text (128 / 512 characters) |
| `url` | the zip: an https URL, or relative to the index (`acme.blog.v1.2.0.zip`, `/files/...`) |
| `sha256` | of the zip, 64 hex digits |

An entry that is not valid is skipped; at most 1000 packages, 1 MB. Sign the
packages (`tool.php sign`): the sha256 only says the zip is the one of the
index, the signature says who made it.

### Downloads

The index is fetched only by the AJAX request the catalog page makes when it
is opened (POST with the CSRF token), never by other requests, and kept in
`_repository/package/catalog/<name>.json` for 1 hour: **Install** uses it
(`catalog-expired`: open the page again). `\Andora\Web\Package\Remote`
(needs ext-curl):

- https only (no user / password in the URL), certificate checked;
- no request to the network of the server (SSRF): the host is resolved and
  every address must be public: no loopback, private (10/8, 172.16/12,
  192.168/16, fc00::/7), link local (169.254/16, fe80::/10, the cloud
  metadata address), CGNAT, multicast, reserved, documentation, IPv4 mapped,
  NAT64, 6to4 or Teredo addresses (`address-not-allowed`); curl then connects
  to the address that was checked (no second DNS lookup), without a proxy;
- redirects are followed here, at most 3, each one checked the same way;
- timeouts: 10 s to connect, 20 s for the index, 300 s for a zip;
- size limits: 1 MB for the index, 64 MB for a zip (the transfer is
  stopped);
- the sha256 of the zip must be the one of the index (`checksum-mismatch`),
  then `Installer::stage` checks it like an upload, and the zip must be the
  package and version of the index (`catalog-mismatch`).

`package.registryAllowLocal => true` allows http and the loopback addresses
(private ones stay refused): it exists for the tests
(`test/0013.package-catalog.php`, a registry on a second `php -S`); never set
it on a site. Downloads and registry changes are written to
`_log/<date>.package.log`.

## Developer kit

`template/package/` of the Andora repository is a package project:

```
template/package/
    fabricare.json      solution acme.example (generic, release of output/)
    version.json        the version of the package
    fabricare/make.js   source/ -> output/, the version in package.json
    fabricare/sign.js   fabricare sign: package.sig (ANDORA_TOOL, ANDORA_PACKAGE_KEY)
    source/_site/acme/example/
        package.json    routes example and admin/example
        package.php, package/setup.php      table, permission (given to administrator), menu,
                                            listeners of user.*, acme-example.item-add, a dashboard panel
        data-source/table-item.php          acme-example-item
        route/example/                      public page, language files
        route/admin/example/                admin page: data table, add / edit / delete dialogs
```

```
fabricare make && fabricare sign && fabricare release    # release/acme.example.v1.0.0.zip
```

Its `README.md` lists what to rename for a new package (vendor, name,
namespaces, table, permission). The test `test/0011.package-example.php`
installs the example and builds the kit.
