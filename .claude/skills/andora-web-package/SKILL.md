---
name: andora-web-package
description: Write an Andora package (a plugin of the Andora PHP app on XYO.Web) — from the developer kit template/package (acme/example) to an advanced package with several tables, schema and data migrations, its own permissions, admin sidebar section, admin and public routes, events it provides and listens to (cross-package integration without a hard require), dashboard panels (render callback or AJAX component), runtime data, own CSS, signing, release zip and registry index. Covers package.json (vendor, name, version, require constraints, route, event, datasource, validation patterns), the lifecycle class extending \Andora\Web\Package\Base (check, install, upgrade($fromVersion), reset, enable, disable, uninstall($keepData), seed, helpers registerPermission / grantPermissionToGroup / registerAdminMenu / dataPath / getDataSource), what storageUpdateTable does to columns, the reason codes (route-conflict, datasource-conflict, event-listener-missing, permission-in-use, ...), and how to install, test and debug a package. Use when creating, extending, upgrading, signing or releasing a package, editing a package.json / package.php / package/setup.php, or when a package does not install, upgrade or show up.
---

# Andora packages (writing one)

A package = one folder `_site/<vendor>/<name>/` with a `package.json`, plus an
optional lifecycle class. Andora finds it, installs it from `/admin/package`
(or `cli.php`), mounts its routes, registers its events, tables, permissions,
menu entries and dashboard panels. Full reference: `docs/package.md`; the
working example: `template/package/` (`acme/example`); its test:
`test/0011.package-example.php`.

Related skills: **andora-web** (operating Andora, ACL, the package manager
from the admin side), **andora-web-admin** (Dialog + DataTable + Form recipes
for the admin pages of a package), **xyo-web-ui**, **xyo-web-datasource**,
**fabricare**.

## 1. Start from the developer kit

Copy `template/package/` to a new project folder and rename (its `README.md`
has the full list):

| What | From | To |
|---|---|---|
| folder | `source/_site/acme/example/` | `source/_site/<vendor>/<name>/` |
| namespaces | `Acme\Example\...` | `<Vendor>\<Name>\...` (kebab folder ↔ PascalCase: `my-shop` ↔ `MyShop`) |
| `package.json` | `vendor`, `name`, `route`, `event`, `datasource` | yours |
| table / permission / menu code / events | `acme-example-item`, `acme-example.manage`, `acme-example`, `acme-example.*` | `<vendor>-<name>-...`, `<vendor>-<name>.*` |
| `fabricare.json` `name`, `version.json` key | `acme.example` | `<vendor>.<name>` |

```bash
fabricare make       # source/ -> output/, version.json -> package.json "version" (#{VERSION_VERSION})
fabricare sign       # optional: output/_site/<vendor>/<name>/package.sig (ANDORA_TOOL, ANDORA_PACKAGE_KEY)
fabricare release    # release/<vendor>.<name>.v<version>.zip
```

The kit's `make.js` only copies and stamps the version: it compiles no CSS
(see §9).

## 2. Layout and naming rules

```
_site/<vendor>/<name>/
    package.json            manifest (data only)
    package.php             return \<Vendor>\<Name>\Package\Setup::class;  (only the name)
    package/setup.php       lifecycle class, extends \Andora\Web\Package\Base
    data-source/table-*.php Table classes (all listed in package.json "datasource")
    model/ component/       classes used by routes and panels
    route/<path>/           pages of a route (package.json "route")
    route/admin/<path>/     admin pages (route admin/<path>)
    client/                 compiled CSS / JS / images (served as /_site/<vendor>/<name>/client/...)
    index.html              in EVERY folder (copy a sibling's)
```

- Class name = file path (autoloader): `\Acme\Blog\Route\Admin\Blog\FormAdd`
  is `_site/acme/blog/route/admin/blog/form-add.php`. Wrong name → class
  silently not found (501, "Class not found" in `_log/<date>.router.log`).
- Every PHP file: `namespace ...;` then `defined("ANDORA") or die("Forbidden");`.
  `_site/` is readable from the web and a zip may not contain `.htaccess`,
  `.htpasswd`, `.user.ini`, `php.ini`, `web.config`: the guard is the only protection.
- `package.php` is included more than once: it only returns the class name.
- Runtime data never in the package folder (replaced on every upgrade, moved
  to the backups on uninstall): database, or `$this->dataPath()` =
  `_repository/<vendor>/<name>/` (created, not served).
- Files are CRLF in the XYO repositories; no `sed -i`.

## 3. `package.json`

```json
{
    "vendor": "acme", "name": "blog", "version": "#{VERSION_VERSION}",
    "title": "Blog", "description": "Articles and categories",
    "license": "Apache-2.0", "author": "ACME <office@acme.example>",
    "require": { "php": ">=8.1", "extension": ["gd"], "andora/web": ">=0.4 <1.0", "acme/core": ">=1.0" },
    "route": { "blog": "route/blog", "admin/blog": "route/admin/blog" },
    "event": {
        "provide": { "acme-blog.post-publish": "A post was published: postId, userId" },
        "listen":  { "user.delete": "onUserDelete", "acme-shop.order-paid": "onOrderPaid" }
    },
    "datasource": [ "Acme\\Blog\\DataSource\\TablePost", "Acme\\Blog\\DataSource\\TableCategory" ]
}
```

Validation (`\Andora\Web\Package\Manifest`; a bad value = package **Broken**, reason in Details):

| Key | Rule |
|---|---|
| `vendor`, `name` | `^[a-z0-9]+(-[a-z0-9]+)*$`, ≤ 64, equal to the folder names; vendor `vendor` reserved |
| `version` | `major.minor.patch` exactly (`1.2.0`, not `1.2`) |
| `require` | `php`, `extension` (list), `<vendor>/<name>`: constraint parts separated by spaces, all must match: `>=1.0.0 <2.0`, `!=1.3.0`, `=1.2.3` / `1.2.3`, `*` / empty = any; `1.2` = `1.2.0`. A required package must be installed **and enabled**; framework folders without package.json (`xyo/*`) only have to exist |
| `route` | path ⇒ folder, both `^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)*$`, ≤ 255 |
| `event` names | `^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)+$`, ≤ 128; prefix with `<vendor>-<name>.` |
| `event.listen` values | method names `^[A-Za-z_][A-Za-z0-9_]*$` |
| `datasource` | fully qualified class names (`\\` in JSON), classes inside the package folder |
| `protected` | only andora/web |

`require` is a hard dependency (install order, cannot disable the required
one). For an **optional** integration don't require: listen to the other
package's events (§6), or test `Manager::isActive($web, "acme/shop")` in
admin code (it scans the folders: not in hot paths).

## 4. Lifecycle class (`package/setup.php`)

```php
<?php
namespace Acme\Blog\Package;

defined("ANDORA") or die("Forbidden");

use \Acme\Blog\DataSource\TablePost;
use \Andora\Web\Model\Acl;

class Setup extends \Andora\Web\Package\Base
{
    public const PERMISSION_MANAGE = "acme-blog.manage";
    public const PERMISSION_PUBLISH = "acme-blog.publish";

    public function check(&$reason)        // what package.json can not express
    {
        if (!function_exists("imagecreatetruecolor")) { $reason = "acme-blog.gd-missing"; return false; }
        return true;
    }

    public function seed(&$reason)         // idempotent: after install, upgrade, reset, database restore
    {
        return $this->registerPermission(self::PERMISSION_MANAGE, "Manage the blog", $reason)
            && $this->registerPermission(self::PERMISSION_PUBLISH, "Publish posts", $reason)
            && $this->grantPermissionToGroup(self::PERMISSION_MANAGE, Acl::GROUP_ADMINISTRATOR, $reason)
            // own sidebar section (no path, no parent) + entries below it
            && $this->registerAdminMenu("acme-blog", "admin.sidebar", "", "Blog", "", "", "", Acl::LEVEL_PUBLIC, 900, $reason)
            && $this->registerAdminMenu("acme-blog.post", "admin.sidebar", "acme-blog", "Posts", "newspaper", "admin/blog/post", self::PERMISSION_MANAGE, Acl::LEVEL_PUBLIC, 100, $reason)
            && $this->registerAdminMenu("acme-blog.category", "admin.sidebar", "acme-blog", "Categories", "folder", "admin/blog/category", self::PERMISSION_MANAGE, Acl::LEVEL_PUBLIC, 200, $reason);
    }

    public function upgrade($fromVersion, &$reason)
    {
        // 1. BEFORE parent::upgrade: renames (storageUpdateTable would drop the old column)
        if (version_compare($fromVersion, "1.1.0") < 0) {
            gc_collect_cycles();
            if (!$this->getDataSource(TablePost::class)->storageRenameField("body", "content")) { $reason = "database-error"; return false; }
        }
        // 2. new tables / columns of $tables, then seed()
        if (!parent::upgrade($fromVersion, $reason)) { return false; }
        // 3. data migrations, by the version installed before
        if (version_compare($fromVersion, "1.2.0") < 0) { /* fill the new column ... */ }
        return true;
    }
}
```

| Hook | Called | Default |
|---|---|---|
| `check(&$reason)` | before install and upgrade | true |
| `install(&$reason)` | first install | `createTables($tables)` + `seed` |
| `upgrade($fromVersion, &$reason)` | files newer than installed (`Upgrade`, `migrate`, upload) | `updateTables($tables)` + `seed` |
| `reset(&$reason)` | Reset (data lost) | `recreateTables` + `seed` |
| `enable` / `disable(&$reason)` | before enable / disable | true |
| `uninstall($keepData, &$reason)` | Uninstall | `dropTables` unless `$keepData` |
| `seed(&$reason)` | after install, upgrade, reset, database restore | true |
| `dashboardPanels()` | `/admin/dashboard` | `array()` |
| listeners | `event.listen` methods, `public function x($event)` | — |

Order of an install / upgrade (`Manager`): state check → `require` → routes
(`route-conflict`, `route-folder-missing`) → lock → events check → datasource
check → `check()` → (upgrade: SQLite db copied to
`_repository/package/backup/<vendor>.<name>/`) → hook → `package` record →
registries written (routes `config.08`, events `config.11`, tables `config.12`).

Rules for hooks:
- Return true / false, set `&$reason` to a short code (shown on
  `/admin/package`, written to `_log/<date>.package.log`). Prefix own codes
  (`acme-blog.*`).
- **Upgrade is not transactional** and a failed one keeps the old installed
  version, so **Upgrade can run again on a half-migrated database**: every
  step must be re-runnable (check the column / row before changing it).
  MySQL / PostgreSQL get no automatic copy.
- `$fromVersion` is the installed version, `$this->getVersion()` the files'.
- Disabled package: its tagged permissions and menu entries are disabled,
  routes 404, listeners and panels off. Pending upgrade: routes 503.
- Uninstall removes the tagged permissions (and grants) and menu entries;
  with `$keepData` the tables and the record stay (state Files missing).

## 5. Tables, permissions, menu

**Tables** — one `\XYO\Web\DataSource\Table` per file in `data-source/`, all
listed in `datasource` (else `datasource-undeclared` when the class sets
`$tables`, `invalid-datasource` when outside the folder, `datasource-missing`
when not loadable / not a Table, `datasource-prefix` when the table name is
not `<vendor>-<name>-<thing>` (`<thing>` lower case letters, digits, `-`),
`datasource-conflict` when another package or andora/web declares the same
table; andora/web's own tables are `andora-web-*`).
Descriptor format: **xyo-web-datasource** (and `template/.../table-item.php`).
- Reference users with `user__id` (bigint unsigned, indexed) and clean up in
  a `user.delete` listener (andora/web deletes only its own rows).
- `storageUpdateTable()` makes the live table match the descriptor: adds
  columns, **drops columns no longer in the descriptor** (SQLite rebuilds the
  table copying the common columns), retypes, reconciles indexes. Renaming =
  `storageRenameField($old, $new)` first; a renamed table = new class + copy +
  drop the old one in `upgrade`. Call `gc_collect_cycles()` before (SQLite can't
  rebuild while a query is open; the Base helpers do).
- The tables are in the database backups of `/admin/database` (by package);
  after a restore `seed()` runs again.

**Permissions** — `registerPermission($name, $description, $reason)`: a
system permission tagged with the package (no rename / delete / disable in
the admin pages); fails `permission-in-use` if the name belongs to Andora or
another package. `grantPermissionToGroup($name, Acl::GROUP_ADMINISTRATOR, ...)`
once (root has every permission anyway). New permissions go into `seed()`, so
an upgrade adds them. Check them server-side: `Acl::can($this->web, Setup::PERMISSION_X)`
in every action, not only through the menu.

**Admin menu** — `registerAdminMenu($code, $location, $parentCode, $title, $icon, $path, $permission, $level, $position, $reason)`:
- locations: `admin.sidebar` (main menu; a row with no parent and no path is
  a **section**, entries point to it with `$parentCode`; core section
  `admin.home`, position 100), `admin.panel` (the Administration popup,
  no parent; core entries 50-1000), `admin.bottom`.
- **Added once by `$code`**: a later version changing title / icon / path /
  position does not update the row; change it yourself in `upgrade()`
  (`TableAdminMenu`, `code` = your code) or use a new code.
- Titles are stored literals (not translated); icons are Lucide names
  (check `output/_site/xyo/lucide-icons/lucide-icons.min.css`).
- A section with no visible entry is hidden; an entry is shown when
  `permission` is empty or `Acl::can`, and the level allows.

## 6. Events — the integration points

```php
use \Andora\Web\Package\Event;
// after the change is saved; returns "<vendor>/<name>" => what each listener returned
$results = Event::dispatch($this->web, "acme-blog.post-publish", array("postId" => $id, "userId" => $userId));

public function onOrderPaid($event)          // in Setup, listed in event.listen
{
    $orderId = intval($event->get("orderId", 0));   // $event->name, ->source, ->data
}
```

- andora/web provides `user.create`, `user.delete`, `user.login`,
  `user.logout` (data `userId`).
- Listening to an event nobody provides is allowed (kept, registered when the
  provider is installed): optional integration without `require`.
- The return values make events usable as **extension points** of your own
  package: e.g. provide `acme-blog.post-actions`, let other packages return
  arrays, merge `$results` (each listener's return is keyed by its package).
- Conflicts: an event provided twice (`event-conflict`), a listener that is
  not a public method or is a Base hook name (`event-listener-missing`).
- A listener exception is logged, the others still run, the request goes
  on: never rely on a listener for something that must happen. Listeners of
  a package with a pending upgrade are skipped. No registry (site copied
  without config) → `cli.php event rebuild`.
- `Event::dispatch` reads `config.11.event.php` (no DB query): cheap enough
  for every request.

## 7. Routes and pages

- `"route": {"blog": "route/blog"}` serves `/blog/**` from the folder
  (`page.php`, `api.php`, `slug.php`, `service.php`, `language/`; sub
  folders are sub routes: `route/blog/archive/` = `/blog/archive`). Namespace
  follows the folder: `\Acme\Blog\Route\Blog\Archive\Page`.
- Layout and authorization are searched from the route folder up to the site
  root: a public route gets the root layout (`\Andora\Web\Layout\Base`)
  and root `authorization.php` unless the folder has its own. An own
  `authorization.php` extends `\Andora\Web\Authorization` and calls
  `parent::checkAuthorization()` first (setup redirect, 404 / 503 of packages).
- Below `admin/`: admin layout, login + `system.admin` check; the folder's
  `authorization.php` extends `\Andora\Web\Route\Admin\Authorization` and sets
  `protected $permission = Setup::PERMISSION_MANAGE;` (401 without it).
  Several admin pages: `"admin/blog": "route/admin/blog"` with sub folders
  `post/`, `category/`, each with its own `authorization.php` if the
  permission differs.
- Admin page pattern (DataTable + Dialog + forms, `messageOk/messageError`,
  linked popups, InputAutocomplete): **andora-web-admin**, ready to copy in
  `template/.../route/admin/example/page.php`. Call
  `Dashboard::setApplicationTitle(...)` in `init()`.
- Language: each route folder has `language/en-us.php`, `ro-ro.php`
  (`key => string`), read with `$this->language->get("key")`; keep both in
  sync. Components outside a route: `new \XYO\Web\Language()` +
  `includeFile(...)` (see `Model\Dashboard::language`).
- Conflicts: a route used by another enabled package, andora/web (`admin`,
  `user`, `setup` and their pages) or a site root folder → `route-conflict`.
  `/_site/<vendor>/<name>/route/...` is never reachable directly.
- Current user: `Acl::currentUserId()`; site base URL for links:
  `$web->get(\XYO\Web\Info::class)->site`. Escape everything you echo
  (`htmlspecialchars(..., ENT_QUOTES)`); forms through `renderFormAJAX` carry
  the CSRF token; posted ids are untrusted (load the row, check ownership /
  `Acl::canManageUser` before changing it).

## 8. Dashboard panels

```php
public function dashboardPanels()
{
    return array(
        "posts" => array(                              // id acme/blog/posts: keep it stable
            "title" => "Blog", "description" => "Posts and drafts", "icon" => "newspaper",
            "permission" => self::PERMISSION_MANAGE, "size" => 1, "default" => true,
            "render" => function ($web) { echo "<p>Posts: " . intval($this->countPosts()) . "</p>"; }
        ),
        "stats" => array(
            "title" => "Blog statistics", "size" => 2,
            "component" => \Acme\Blog\Component\StatsPanel::class   // \XYO\Web\Component, may use AJAX
        )
    );
}
```

Name `[a-z0-9]` words joined by `-`, ≤ 64; without valid name, title, and
`render` or `component` the panel is dropped. A component is registered as
`panel-<vendor>--<name>--<panel>` / `content` with no options (AJAX request id
`<that>/content`). Exceptions are logged and the card shows an error. Users
pick panels on `/admin/dashboard/edit`; `default` only affects users who
never changed their dashboard. Keep `render` fast (runs on every dashboard view).
Test: `test/0012.package-dashboard.php`, `test/0016.dashboard.php`.

## 9. CSS, JS, assets

`andora.css` is Tailwind + daisyUI compiled **at the build of the site**,
scanning that site's `output/`. A package uploaded later only gets the
classes that already exist in it:
- a package bundled in the site's build (zip in `vendor/`, extracted before
  the Tailwind step) is scanned and works with any class;
- an uploaded package: use classes already in
  `output/_site/andora/web/client/andora.css` (grep it), or ship its own
  compiled CSS in `client/` and register it in `init()`:
  `$this->view->cssLinks->set("acme.blog", $this->site . "_site/acme/blog/client/blog.css");`
  (JS: `$this->view->jsLinks->set("acme.blog", ..., "defer")`). Compile only the
  package sources, without preflight, with a prefix
  (`@import "tailwindcss" prefix(blog)`), colours from the daisyUI variables;
  add that step to the kit's `make.js`.
- Inline scripts go through `$this->view->renderJS(...)` / `jsSource(...)`
  (CSP nonce); never raw `<script>` in AJAX output.

## 10. Settings and runtime data

There is no package settings API. Recommended: a key/value table of the
package (in `datasource`: backed up, restored, removed on uninstall) edited by
an admin page with its own permission. Files: `$this->dataPath()`, write with
`flock` (see `updateActivity` in the template). Don't write `config.*.php`
files of the site.

## 11. Sign, release, publish

- Keys: `php <andora output>/_site/andora/web/package/tool.php keygen private.pem public.pem`
  (`*.pem` in `.gitignore`, private key never in the project or on a site).
- `set ANDORA_TOOL=...tool.php`, `set ANDORA_PACKAGE_KEY=...private.pem`,
  `fabricare make && fabricare sign && fabricare release`. Signing covers
  every file + `<vendor>/<name>`: sign after the last change.
- Site owners: `cli.php trust add <vendor> public.pem --vendor=<vendor>`.
- New version: bump `version.json`, add the migration to `upgrade()`, new
  permissions / menus / panels in `seed()` / `dashboardPanels()`, make, sign, release.
- Registry: a static `index.json`
  `{"packages":[{"vendor","name","version","title","description","url","sha256"}]}`
  (`url` https or relative to the index; `sha256` of the zip:
  `php -r "echo hash_file('sha256', 'release/acme.blog.v1.2.0.zip');"`).

## 12. Install, test, debug

- Dev loop on a local Andora (`fabricare make`, `php -S 127.0.0.1:8080 -t output test/_lib/router.php`):
  copy `output/_site/<vendor>/<name>/` of the package into Andora's
  `output/_site/` → `/admin/package` → Install / Upgrade pending → Upgrade.
  Or upload the release zip (+), or
  `php output/_site/andora/web/package/cli.php deploy <zip> --trust`, then
  `cli.php list`. Re-copying the same version changes nothing: bump the
  version to test `upgrade()`.
- Log: `_log/<date>.package.log` (operations, reasons, listener / panel
  exceptions), `_log/<date>.router.log` (501s).
- A test in Andora's `test/` (model: `test/0011.package-example.php`, which
  zips the template source, uploads it, drives the pages with `TestBrowser`
  and checks the SQLite tables) — run with `fabricare test`.

| Reason | Meaning / fix |
|---|---|
| `invalid-manifest`, `invalid-vendor`, `invalid-name`, `invalid-version`, `invalid-require`, `invalid-route`, `invalid-event`, `invalid-datasource` | package.json (§3) |
| `invalid-package-class` | `package.php` does not return a loadable class extending Base (namespace ≠ path) |
| `requires-php`, `requires-extension`, `requires-package` | `require` not met (package missing or disabled) |
| `route-conflict`, `route-folder-missing` | route used elsewhere / folder missing |
| `event-conflict`, `event-listener-missing` | §6 |
| `datasource-missing`, `datasource-prefix`, `datasource-conflict`, `datasource-undeclared` | §5 |
| `permission-in-use` | permission name owned by Andora / another package |
| `database-error`, `database-backup-error` | a table operation / the SQLite copy before upgrade failed |
| `required-by` | another enabled package requires it (disable / uninstall) |
| `invalid-state` | operation not allowed in this state (Older files, Broken, ...) |
| `busy` | another operation holds `_repository/package/lock` |

## Checklist for an advanced package

1. Names: vendor / name, table prefix `<vendor>-<name>-`, event prefix
   `<vendor>-<name>.`, permission prefix `<vendor>-<name>.`.
2. `package.json`: every Table in `datasource`, every route, every event
   provided / listened, hard `require` only where the code needs it.
3. `seed()` idempotent: permissions, grants, menu section + entries.
4. `upgrade()`: renames before `parent::upgrade`, re-runnable data steps,
   menu row changes.
5. `user.delete` listener for every table with `user__id`.
6. Every admin folder: `authorization.php` with `$permission`; server-side
   `Acl::can` in actions; escaped output; en-us + ro-ro language files.
7. CSS decided (§9), `index.html` in every folder, PHP guard in every file.
8. `php -l` every file, make, install on a local Andora, upgrade from the
   previous release, uninstall (with and without Keep the data), sign, release.
