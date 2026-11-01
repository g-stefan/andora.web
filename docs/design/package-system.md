# Package system (plan)

Status: decisions taken (section 11). Phases 0 to 4 are implemented (see
[../package.md](../package.md)), with the remote catalog and the events of
section 8. Phase 4 choices:
OpenSSL ECDSA P-256 (libsodium is often missing), `package.sig` inside the
package folder signing the list of its files, unsigned uploads allowed with
"I trust this package" (or refused with `package.requireSignature`), keys
pinned to vendors (`--vendor`); the example package is `acme/example` in
`template/package/` (the developer kit), not shipped with Andora. Differences from this
plan: the CLI is `_site/andora/web/package/cli.php` (a service route,
`serviceRun`), the export is the API route `admin/package/export`, an
uninstalled package with backups is listed as **Uninstalled** (Restore), and
the database copy before an upgrade is not restored automatically.
Remote catalog: `admin/package/catalog`, registries = one JSON index each
(`{"packages": [{vendor, name, version, title, description, url, sha256}]}`),
the official URL `Registry::OFFICIAL_URL` (empty until it is published, config
`package.registry`), registries added on the page / `cli.php registry` in
`config.10.package-registry.php`; the index is fetched only by the AJAX
request of the page; downloads by `Remote` (curl): https only, every resolved
address public and pinned (SSRF), redirects checked, timeouts, 1 MB / 64 MB
limits, sha256 of the index, then `Installer::stage` and the upload confirm.
Events: declared in package.json (`event.provide` / `event.listen`),
written to the registry `config.11.event.php` by the package manager and
dispatched with `Event::dispatch` (no database query; an exception is logged,
never breaks the request); `dashboardPanels()`, plus a notice on the
dashboard for `package.manage` when a package needs attention.

Goal: anyone can write a module / component / plugin for Andora and the site
can install, upgrade, reset, disable, export and uninstall it **from the admin
area**, without the build. The update method stays the same as today:
**a package is one folder, `_site/<vendor>/<package>/`; to update it, the
folder is deleted and replaced**. The build (fabricare `7z x ... -ooutput`)
keeps working with the same zip files.

## 1. What exists today (and what it means for the design)

| Fact | Consequence |
|---|---|
| The autoloader maps `\Vendor\Package\X\Y` to `_site/vendor/package/x/y.php`. | A package folder is already self-contained for classes. Namespace = folder. |
| Vendor zips (`xyo.web.v0.147.0.zip`, `xyo.web.library.v0.21.0.zip`) mirror the site root: `_site/xyo/web/...`, `_site/xyo/inter-font/...`. | The package zip format is the same: paths start with `_site/<vendor>/<package>/`. The build and the runtime installer read the same zip. |
| The xyo.web zip also has root files (`index.php`, `.htaccess`, `_log/`) that Andora's `source/` overwrites. | Root files belong to the **site**, never to a package. A runtime install never writes them. |
| Routes are physical folders from the root (`admin/`, `user/`, `setup/`); `findPage` / `findAPI` use `<path>/page.php`, `findSlug` / `findAuthorization` walk up the parents. | A package cannot bring routes inside its own folder today. If a plugin puts `admin/blog/` inside Andora's `admin/`, replacing Andora's `admin/` deletes the plugin. This is the main gap (section 5). |
| Andora's schema state is `config.06.schema.php` (`schemaVersion`) + `Setup::upgrade`, checked in `admin/authorization.php`. | The same idea generalised: a recorded version per package, compared with the version on disk. |
| `Setup::registerAdminMenu`, system permissions, `Acl::can`. | Packages add menus and permissions through these, tagged with the package so uninstall can remove them. |
| CSS is compiled by Tailwind at build time (Node.js). | Nothing can be compiled at runtime: a package ships its **compiled** CSS / JS. |
| `_site/` is web readable (assets are served from it), `_repository/` and `_log/` are denied. | Staging, backups and package data go under `_repository/`; package PHP files keep the `defined("ANDORA") or die("Forbidden");` guard and `index.html` in every folder. |
| Local PHP 8.5 has **no `ext-zip`** (`ZipArchive` missing), zlib is present. | Do not depend on `ZipArchive` only (section 7). |

## 2. Package layout

```
_site/acme/blog/
    package.json        manifest (data, read without running code)
    package.php         return \Acme\Blog\Package::class;  (lifecycle hooks)
    index.html
    route/              routes of the package (section 5)
        blog/page.php
        admin/blog/page.php
        admin/blog/authorization.php
    component/ data-source/ model/ page/ layout/
    language/en-us/ language/ro-ro/
    client/blog.css     compiled CSS, blog.js
```

Rules:

- The package never stores runtime data in its folder (it is deleted on every
  update). Data goes to the database or to `_repository/<vendor>/<package>/`.
- Settings go to `config.package.<vendor>.<package>.php`, written with
  `Setup::writeConfigFile` (values are data, never code), keys
  `<vendor>.<package>.*`.
- `package.php` follows the framework idiom (`page.php`, `authorization.php`):
  it returns the class name, so the class can live anywhere in the package.

### `package.json`

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
        "andora/web": ">=1.0.0 <2.0.0"
    },
    "route": {
        "blog": "route/blog",
        "admin/blog": "route/admin/blog"
    },
    "protected": false
}
```

- `vendor` / `name`: `[a-z0-9]+(-[a-z0-9]+)*`, must equal the folder names.
  Reserved vendors: `vendor` (composer lives in `_site/vendor/`).
- `version`: `major.minor.patch`, compared with `version_compare`.
- `require`: other packages, PHP version, extensions. Checked before install
  and before upgrade; install order = dependency order.
- `protected: true` for `xyo/web` and `andora/web`: can be upgraded, never
  disabled or uninstalled.

The framework packages (`xyo/web`, `xyo/inter-font`, `xyo/lucide-icons`,
`xyo/overlay-scrollbars`) get a `package.json` in their own builds (xyo.web,
xyo.web.library). Asset-only packages need no `package.php`.

### Lifecycle class

```php
namespace Acme\Blog\Package;

// implemented as \Andora\Web\Package\Base: _site/andora/web/package.php is
// the lifecycle file of andora/web, so no class can be \Andora\Web\Package
class Setup extends \Andora\Web\Package\Base
{
    // Requirements the manifest can not express (writable folder, a setting ...)
    public function check(&$reason) { return true; }

    // First install: tables, seed rows, permissions, admin menu, default settings
    public function install(&$reason) { ... }

    // Files replaced by a newer version: migrate from $fromVersion
    public function upgrade($fromVersion, &$reason) { ... }

    // Back to the state right after install (data wiped, seed rows again)
    public function reset(&$reason) { ... }

    // Remove what install made; $keepData leaves the tables / _repository data
    public function uninstall($keepData, &$reason) { ... }

    public function enable(&$reason) { return true; }
    public function disable(&$reason) { return true; }

    // Events (section 8): the methods named in package.json event.listen
    public function onUserDelete($event) { }
}
```

Same contract as the existing model functions: return `true` / `false`, short
reason code in `&$reason`. The base class gives helpers that tag what they
create with the package code, so uninstall can find it:

- `registerPermission($name, $description)` → `permission.package`
- `registerAdminMenu(...)` → `admin-menu.package` (wraps `Setup::registerAdminMenu`)
- `createTables([TableX::class, ...])`, `updateTables(...)`, `dropTables(...)`
- `writeConfig($array)`, `getConfig($key, $default)`
- `dataPath()` → `_repository/<vendor>/<package>/`

## 3. Recorded state

New table `andora-web-package`:

| column | |
|---|---|
| `code` | `acme/blog`, unique |
| `version` | version whose `install` / `upgrade` ran last |
| `enabled` | 0 / 1 |
| `error` | reason of the last failed upgrade, empty = none (implemented instead of `status`) |
| `installed_at`, `updated_at` | |

Plus the `package` columns of `andora-web-permission` and
`andora-web-admin-menu` (empty = Andora core).

Discovery compares the folders with the table (scan `_site/*/*/package.json`,
two levels, only on admin requests):

| Folder | Table | State | Action offered |
|---|---|---|---|
| present | missing | not installed | Install |
| version newer | older | **upgrade pending** | Upgrade |
| version older | newer | downgrade | refused (restore a backup instead) |
| same | same | installed / disabled | Reset, Disable / Enable, Export, Uninstall |
| missing | present | files missing | Upload files again, or Forget (+ drop data) |
| bad manifest | any | broken | details only |

This is what makes **"delete and replace the folder"** work without the
build: copy a new folder by FTP, open `/admin/package`, click Upgrade (the
same check as the `schemaVersion` upgrade in `admin/authorization.php` can
show a banner for root accounts). Until the upgrade runs, the routes of that
package answer 503 (section 5) so new code never runs on an old database.

`andora/web` becomes a package too: `install` = `Setup::dsCreateStorage` +
`dsSeed`, `upgrade` = `Setup::upgrade`; the setup wizard installs every
package found on disk in dependency order (so packages bundled by the build
are installed at setup). `config.06.schema.php` can stay as it is for
compatibility and later be replaced by the `andora/web` row.

## 4. Install / upgrade from a zip (inside the system)

Admin page `/admin/package` (Dialog + DataTable + Form pattern, permission
`package.manage`, system permission, root only by default):

1. **Upload** (`+` button): file input, size limit (`upload_max_filesize`;
   the library zip is ~6 MB).
2. **Stage**: extract to `_repository/package/staging/<random>/` (denied
   folder, same disk as the site so a rename is atomic). Validate:
   - every entry under exactly one `_site/<vendor>/<package>/` (+ nothing else),
   - no `..`, no absolute / drive paths, no `\`, no NUL, no symlinks,
     no `config.*`, `.htaccess`, `.user.ini`,
   - entry count and total uncompressed size limits (zip bomb),
   - `package.json` valid, folder names = manifest, requirements met.
3. **Preview**: show title, version on disk → new version (new install /
   upgrade / same version re-install / downgrade refused), requirements,
   dependents. Root confirms.
4. **Swap** (maintenance lock `_repository/package/lock` while it runs):
   rename `_site/acme/blog` → `_repository/package/backup/acme.blog/1.1.0-<time>/`,
   rename staging → `_site/acme/blog`, `opcache_invalidate` the files (or
   `opcache_reset`). On any error rename back.
5. **Migrate in a new request** (redirect to `/admin/package?run=acme/blog`):
   `install` or `upgrade($fromVersion)` runs with the new code already loaded
   by a fresh PHP process. This also makes upgrading `andora/web` and
   `xyo/web` themselves safe: the request that swaps the files never runs
   code from the new version, the next request never runs the old one.
6. On hook failure: status `failed`, routes stay 503, page offers "Restore
   previous version" (swap back from backup). For SQLite, copy
   `_repository/andora.sqlite` next to the backup before migrating (cheap,
   automatic); MySQL / PostgreSQL: warn to back up first.

Keep the last 2 backups per package.

**Export**: row menu → Export → `acme.blog.v1.2.0.zip`, the package folder
with paths `_site/acme/blog/...`, same name pattern as the existing vendor
zips, so it can go into `vendor/` and `fabricare.json` of another project.
Code only; data export is a separate feature if needed.

**Reset**: runs `reset()`; confirm dialog states the data is lost.

**Uninstall**: refused if another installed package requires it or it is
protected; runs `uninstall($keepData)`, removes tagged permissions / menus,
moves the folder to backup, deletes the `package` row (or keeps it with
"files missing" when data is kept).

**CLI** (deployments, CI): the same operations through a service route,
for example `php index.php` service `admin/package/service` with
`install <zip>` / `upgrade <code>` / `list`, so a deploy script can copy
folders and run the migrations without a browser.

## 5. Routes inside the package (router change in xyo.web)

To keep "one package = one folder", the router needs **route mounts**: a map
from a URL prefix to a folder.

```php
// config.07.route.php, generated from the enabled packages' manifests
return ["route" => ["mount" => [
    "blog"       => "_site/acme/blog/route/blog",
    "admin/blog" => "_site/acme/blog/route/admin/blog",
]]];
```

Rule: a virtual path is mapped to a physical folder by the **longest
matching mount prefix**, else it stays a root folder. `findPage`, `findAPI`,
`findService` map the path; `buildPathSearch` maps every parent separately,
so `admin/blog/post` looks for `slug.php` / `authorization.php` in
`_site/acme/blog/route/admin/blog/post/`, `.../admin/blog/`, then the real
`admin/` (Andora's admin check still applies) and the root.

- A mount may not shadow a real root folder or another package's mount
  (checked at install; conflict = refused).
- Disabled / upgrade-pending packages are left out of the generated map
  (404) or mapped to a 503 page.
- The map is a config file (read every request with the other `config.*`),
  regenerated on install / upgrade / enable / disable / uninstall.

In phase 3 Andora's own `admin/`, `user/`, `setup/` move into
`_site/andora/web/route/` with mounts, so upgrading Andora is also one folder
(the root keeps only the site files: `index.php`, `.htaccess`, root
`authorization.php` / `page.php` / `layout.php`). The mounts of protected
packages (`andora/web`) are always in the map, even before the database
exists (the setup wizard must be reachable).

## 6. Security

Installing a zip is installing PHP code (= full control of the site). So:

- Only `package.manage` (root by default), POST + CSRF as every form.
- Config switch `package.upload` (default on; off disables upload / export /
  uninstall, keeps the list and the Upgrade button for FTP-copied folders).
  Hosts that do not want code written by PHP turn it off.
- Strict zip validation (section 4 step 2), extraction only under
  `_repository/`, nothing executable is reachable before validation.
- Every operation logged to `_log/<date>.package.log` (who, what, versions).
- Later: signed packages. `package.sig` = signature of the zip's file list
  hashes (`openssl_verify`, or xyo-cryptography), trusted public keys in
  `config.package-trust.php`; unsigned packages need an explicit
  "I trust this package" checkbox; `xyo/*` and `andora/*` upgrades only
  with the XYO key.

## 7. Zip support

`ZipArchive` (`ext-zip`) is often missing (it is missing here). Decided: a
small reader / writer in `\Andora\Web\Package\Zip` (stored + deflate entries,
`gzinflate` / `gzdeflate` from zlib, no zip64, no encryption; about 300
lines), used when `ZipArchive` is missing. zlib is almost always present;
without both, `/admin/package` refuses upload / export with a clear message.

## 8. Extension points for plugins

Needed so plugins can integrate, not only add pages:

- Admin menu entries and permissions (exist, tagged per package).
- Events (done): andora/web provides `user.create`, `user.delete` (cascade
  cleanup of package tables, like the existing user delete cascade),
  `user.login`, `user.logout`; a package provides its own events and
  listens to the others in package.json (`event`), the registry
  `config.11.event.php` is written after every operation (like the routes)
  and read with the configuration by `Event::dispatch`;
  `dashboardPanels()` for `/admin/dashboard`, chosen by every user on
  `/admin/dashboard/edit` (done).
- Tables (done): a package lists its Table classes in package.json
  (`datasource`), the registry `config.12.datasource.php` (table => package)
  is written after every operation; `/admin/database` and `cli.php database`
  export every table or the tables of one package and restore them
  (`\Andora\Web\Package\DatabaseBackup`, see docs/package.md#database-backups).
- Language files per package (`loadLanguageFromPath` already works).
- Assets: compiled CSS / JS registered through the View. For Tailwind,
  compile only the package sources, without preflight, ideally with a
  prefix (`@import "tailwindcss" prefix(blog)`) to avoid clashes with
  `andora.css`; use the Andora CSS variables for colours.

## 9. Developer kit

- `docs/package.md`: how to write a package (layout, manifest, hooks, rules).
- An example package `andora/example` (one public page, one admin CRUD page,
  one permission, one table) used by the tests and as a template.
- A fabricare script for package projects: `make` (copy + compile CSS +
  version in `package.json`) and `release` (zip `<vendor>.<name>.v<version>.zip`),
  same as the xyo.web / xyo.web.library releases.

## 10. Phases

| Phase | Content | Result |
|---|---|---|
| 0 (done) | `package.json` in xyo.web and xyo.web.library builds; andora/web `package.json` + `package.php` | every installed folder is described |
| 1 (done) | `package` table + schema 7 columns, `\Andora\Web\Package\Base`, discovery, `/admin/package` list with Install / Upgrade / Reset / Enable / Disable, setup wizard installs bundled packages, events `onUserDelete` | **replace folder by FTP → click Upgrade** works |
| 2 (done) | Upload + staging + validation + swap + two-request migrate + backup / restore, Export, Uninstall, Zip fallback, log, CLI | install / upgrade / export as zip from the admin area |
| 3 (done) | Route mounts in the xyo.web router, packages with routes, move Andora routes into its package (optional) | one package = one folder, including pages |
| 4 (done) | Signatures / trusted keys, example package + developer docs + fabricare package template, remote catalog (`admin/package/catalog`: registries, index fetched by the page, safe download, sha256, same confirm as an upload) | third parties can publish packages |

Tests for each phase (test harness in `test/`): example package fixtures
v1.0.0 / v1.1.0; install, upgrade, reset, uninstall, re-upload; refused zips
(`../`, absolute path, second package, `config.*`, bad manifest, missing
requirement, downgrade, mount conflict); non-root gets 401; routes 503 while
an upgrade is pending; rollback after a failing `upgrade`.

## 11. Decisions (taken 2026-10-30)

1. **Routes: route mounts in the xyo.web router** (section 5). The
   "root folders owned by the manifest" alternative is dropped.
2. **State: the `andora-web-package` table** plus the `package` columns on
   `andora-web-permission` and `andora-web-admin-menu` (section 3).
3. **Upload: on by default**, `package.upload => false` disables upload /
   export / uninstall (section 6).
4. **Zip: own fallback** reader / writer in `\Andora\Web\Package\Zip`,
   `ZipArchive` used when present (section 7, option 2).
5. **Andora routes move**: `admin/`, `user/`, `setup/` go to
   `_site/andora/web/route/` with mounts, in phase 3 after the router
   supports them; the root keeps only the site files.
