---
name: andora-web-admin
description: Build and modify the Andora web app — an XYO.Web PHP project. Concrete recipes for admin CRUD pages, the Dialog+DataTable+Form pattern, junction-table "linked" popups (InputAutocomplete search with paging, add/remove, grant/deny toggle), cascade deletes, language files, sidebar / Administration popup entries, and the source→output fabricare build. Use when adding or editing any admin page (source/_site/andora/web/route/admin/ or the route/admin/ folder of a package), a data source, or the admin navigation in this repo; use andora-web-package for the package side (package.json, lifecycle class, events, panels).
---

# Andora Web (admin pages & conventions)

Andora is a PHP web app built on the **XYO.Web** framework. This skill captures
the *project-specific* conventions and copy-ready recipes. For the framework
primitives themselves, lean on the sibling skills:
- **xyo-web-ui** — Page/Layout/Component/ComponentForm lifecycle, the View, AJAX components.
- **xyo-web-datasource** — Table/Query descriptors and the load/save/where/order API.
- **fabricare** — the build tool.
- **andora-web-package** — an admin page shipped by a package (manifest, lifecycle, seed of its menu / permission).

## Golden rules (read first)

1. **Edit `source/`, never `output/`.** `output/` is generated. `fabricare make` copies `source/` → `output/` and rebuilds CSS. Editing `output/` is thrown away on the next build.
2. **After any change, run the build** (from the repo root):
   ```bash
   fabricare make
   ```
   It (a) unpacks the XYO.Web vendor bundles into `output/`, (b) copies `source/`→`output/`, (c) runs Tailwind/daisyUI over `output/` producing `output/_site/andora/web/client/andora.css`. Tailwind is **JIT and scans `output/`**, so any utility/daisyUI class you use in a new source file is only generated *after* a build — verify new component classes (e.g. `toggle`) actually appear in the compiled CSS.
3. **Naming = file path (autoloader).** Classes load by converting the namespace+class to a kebab path. `Andora\Web\Route\Admin\UserGroup\FormLinkedPermission` ⇒ `_site/andora/web/route/admin/user-group/form-linked-permission.php`; `Andora\Web\DataSource\TableUserGroupXPermission` ⇒ `_site/andora/web/data-source/table-user-group-x-permission.php`. Get the file name/location wrong and the class silently won't load. This is why sibling form files need no `require`/`use`-of-file — just match names.
4. **Guard every PHP file:** `defined("ANDORA") or die("Forbidden");` after the namespace. `page.php` and `layout.php` **return the class name** (`return Page::class;`).
5. **Always `php -l` new files, then `fabricare make`.** Confirm files land in `output/` and wiring strings are present.

## Where things live

```
source/_site/andora/web/
  route/admin/               # mounted at admin/ (package.json "route", main.php)
    authorization.php        # admin guard: logged-in session + `system.admin` (Acl::can); <area>/authorization.php sets $permission
    layout.php               # admin shell (sidebar + header + content)
    <area>/                  # one folder per admin page (route = admin/<area>)
      page.php               # returns Page::class
      form-add.php form-edit.php form-delete.php [form-linked*.php]
      index.html             # placeholder (copy from a sibling)
      language/{en-us,ro-ro,index.html}.php
  route/user/ route/setup/   # mounted at user/, setup/
  data-source/table-*.php    # the DB tables (namespace Andora\Web\DataSource), query-*.php joined reads
  component/*.php            # reusable components (namespace Andora\Web\Component)
  model/*.php                # Acl, Dashboard, Setup, Sidebar, User
  package/*.php              # package manager (Base, Core, Manager, Installer, Event, ...)
_site/<vendor>/<name>/route/admin/<area>/   # admin page of a package (namespace <Vendor>\<Name>\Route\Admin\<Area>)
output/                       # BUILD OUTPUT (do not edit)
  _repository/andora.sqlite   # the live SQLite DB (config.02.datasource.php points here)
```
Namespaces: admin pages `Andora\Web\Route\Admin\<Area>`; data sources `Andora\Web\DataSource`;
components `Andora\Web\Component`; models `Andora\Web\Model`.

## Data sources (existing tables)

The table names are `andora-web-<name>` (`table-user-group.php` ->
`andora-web-user-group`); a new andora/web table is named the same way and
added to `Setup::$tableList` and to `datasource` of
`_site/andora/web/package.json` (the package manager refuses a table that
does not start with `<vendor>-<name>-`: `datasource-prefix`).

`table-user`, `table-user-group`, `table-permission`, `table-admin-menu`,
`table-package`, `table-user-dashboard-panel`, and the junctions
`table-user-x-user-group`, `table-user-x-permission`,
`table-user-group-x-permission`; joined reads `query-user-x-user-group`,
`query-user-x-permission`, `query-user-group-x-permission` (used by `Acl`). The simple entity tables
(`user-group`, `permission`) share the shape `id, name, description, enabled`.
Junction tables carry `id, <owner>__id, <target>__id[, is_granted], enabled`.

## Recipe A — a CRUD admin page (model: `admin/user-group`, `admin/permission`)

A page for a `id/name/description/enabled` table = a `DataTable` + a `Dialog`
hosting add/edit/delete forms and ok/error message screens. **Fastest path:
copy `source/_site/andora/web/route/admin/permission/` to `.../route/admin/<area>/` and rename**
(an admin page of another package goes in its own `route/admin/<area>/`, mounted by its package.json "route")
(class namespace `Andora\Web\Route\Admin\<Area>`, the `Table*` class, the JS function names,
language strings). The moving parts:

- **`page.php`** (`Andora\Web\Route\Admin\<Area>\Page extends \XYO\Web\Page`):
  - `init()`: `setTitle(...)`, `Dashboard::setApplicationTitle(...)`, register the `Dialog` and its AJAX sub-components via `setAJAXComponent(name, fn)` (`placeholder`, `form-add`, `form-edit`, `form-delete`, `message-ok`, `message-error`), then register the `DataTable`.
  - Wire forms to page callbacks: `$form->setOnInit([$this,"on…Init"])` (seed edit/delete), `$form->setOnSuccess([$this,"on…Process"])` (do the DB write).
  - `render()`: `renderComponent("datatable")`, `renderComponent("dialog")`, and emit JS `functionName(record){ … renderJSRequestPost({component:"form-edit",init:"true"}, {<owner>__id:"record.id"}); renderJSOpen(); }` for each row action.
  - Success/failure surface through the shared `messageOk($form,$key)` / `messageError($form,$reason)` helpers (they flip the dialog to a message screen, refresh the datatable, then auto-close). The `$key`/`$reason` are language keys (`add-ok`, `save-error`, …).
  - `onToggleEnabled($id)`: flips `enabled`, returns the new value (or `2` on failure) — used by the `DataTableToggle` column.
- **DataTable descriptor** (in `init()`), column types: `selector` (the primary-key checkbox/id, `"primaryKey"=>true`), `text` (with `"sort"`, `"search"`), `component` (embed `DataTableToggle` for booleans or `DataTableAction` for the row `⋮` menu). Options: `"datasource"=>Table…::class`, `"has-search"=>true`, `"has-add"=>true`, `"addFn"=>fn`.
- **Row actions** = a `DataTableAction` component whose options are rows:
  `array("js", "<lucide-icon>", "<Label>", "<jsFunctionName>"[, $linkClass, $iconClass])`,
  `array("separator")`, or `array("link", …)`. The `js` handler is called with `{id: <primaryKey>}`.
- **Forms** extend `\Andora\Web\Component\Form` (see Recipe C). Add/edit use
  `InputName` (name, min 2) + `InputText` (description); delete just shows the
  record and a confirm button.

### Add/edit/delete logic (in the page)
`getDataSource(Table…::class)` → `clear()` → set key/fields → `load(0,1)` /
`save()` / `delete()`. For edit/delete the selected id rides in the form
**selector**: `onInit` does `$form->setSelector($id)` after loading; `onProcess`
reads `$form->getSelector(0)`. Add: `clear()`, check duplicate name, `save()`.

## Recipe B — a junction-table "linked" popup (add/remove + optional grant/deny)

Used to manage a many-to-many from one entity's row (e.g. groups on a user,
permissions on a group). Reference implementations:
`source/_site/andora/web/route/admin/user/form-linked.php` (user↔group),
`source/_site/andora/web/route/admin/{user,user-group}/form-linked-permission.php`
(permissions, with the `is_granted` toggle).

It's a **self-managing `Form` component** (no page `onSuccess` wiring): it reads
an `action` request param and mutates its own junction table, then re-renders.

- **Register** it as another dialog AJAX component:
  `$this->comDialog->setAJAXComponent("form-linked-permission", fn($d,$c){ FormLinkedPermission::register($d,$c); });`
  add a row action + a JS opener that posts `{component:"…", init:"true"}` and `{<owner>__id:"record.id"}`.
- **Owner id round-trip (critical):** on the initial open (`isInit()` true) read the owner id from the posted `<owner>__id` and `setSelector($id)`. On later add/remove/toggle requests read it back with `intval($this->getSelector(0))`. The selector is embedded in the component's AJAX request id, so it survives every refresh — no session needed. This is the same mechanism edit/delete forms use.
- **Actions** (handled in `formInit`, only when `isPOST() && !isInit() && ownerId>0`):
  - `add`: read the hidden `<target>__id`, guard empty/duplicate/not-found (set `$this->alert = "<lang-key>"`), insert `{owner, target, is_granted:1, enabled:1}`.
  - `remove`: read `link__id`, delete by primary key **after** confirming the row belongs to this owner.
  - `toggle` (grant/deny): load by `link__id`+owner, flip `is_granted`, `save()`.
- **loadData** builds: the current owner's name (heading), `linkedList` (assigned rows → names resolved from the target table in one `IN` query, `id = array(...)`), and `availableList` (all targets except already-linked).
- **Search to add** (the "select what to add" control): the
  `InputAutocomplete` component. Register it in `formInitComponents` (it runs
  after `formInit`, so `availableList` is ready):
  `InputAutocomplete::register($this, "search", array("name" => "<target>__id", "list" => $this->availableList))`
  and render it inside `renderFormAJAX` with
  `$this->renderComponent("search", array("legend" => …, "placeholder" => …))`.
  It searches after `minChars` (3) characters, shows
  `pageSize` (7) matches per page in the page flow (visible inside the dialog)
  with a first/prev/next/last pager and `1-7 of N`, supports arrow keys / Enter /
  Escape / PageUp / PageDown, and writes the chosen id into the hidden
  `name="<target>__id"` field. Two modes, chosen by `clientMaxItems` (default 49):
  - **client** (list ≤ clientMaxItems): the list is embedded as JSON
    `[["<id>","<name>"],…]` and searched in the browser (tests look for `["<id>",`).
  - **server** (list > clientMaxItems, `0` = always): nothing is embedded; each
    search / page change (debounced 250 ms) posts `autocomplete_q`,
    `autocomplete_page`, `autocomplete_sequence` to the component's own
    request id (`…/<form>.<owner id>/search`). The router re-inits the parent
    chain, so the form's `formInit`/`loadData` rebuild `availableList` with the
    ACL rules, then `renderAJAX` answers with a script calling
    `window.AndoraInputAutocomplete[id].receive({items, total, page, sequence})`
    (stale answers are dropped by `sequence`). The component marks an Andora
    `Form` parent as init for that request, so `process`/`onSuccess` do not run;
    actions read in `formInit` (the linked popups' `action`) still do, with the
    same checks as a normal post. See `test/0006.autocomplete.php`.

  It does no validation; **do not use `InputSelect`**
  here — it auto-validates on every POST and would block remove/toggle. The
  `<form>` also holds `<input type="hidden" name="action" value="add">`.
- **Rows** render name + (optional) `is_granted` `toggle toggle-sm toggle-success`
  checkbox + a trash button; a denied row shows the name `opacity-50 line-through`.
  Each control's JS calls `$this->renderJSRequestPost({action:…, link__id:…})`,
  which swaps the component's container with the fresh render.
- `renderContainer()` wraps `renderAJAX()` in `<div class="w-full max-w-[400px] flex flex-col" id="<id>">`.

## Recipe C — the Form base (`Andora\Web\Component\Form`)

Extends `\XYO\Web\ComponentForm`. Override:
`formInit()` (seed `$this->value->*`, read selector/params),
`formInitComponents()` (`register` child inputs + `LucideIcons`),
`formProcess()` (cross-field validation),
`formRenderAJAX()` (emit the heading + `renderFormAJAX(fn, "flex flex-col gap-2")`).
Lifecycle flags: `isInit()` (open-to-seed request, no processing),
`isPOST()`; state helpers `setElementError/Alert`, `hasError/hasAlert`,
`getSelector/setSelector`, `getElementValueString/Number`.
`renderFormAJAX` auto-wires AJAX submit + CSRF — never add a manual token.
Inputs available: `InputName`, `InputText`, `InputEmail`, `InputPassword`,
`InputPasswordText`, `InputSelect`. Reusable dialog pieces: `Dialog`,
`Placeholder`, `MessageOk`, `MessageError`, `DataTable`, `DataTableAction`,
`DataTableToggle`.

## Cascade deletes (convention)

When deleting an entity, clear its junction rows so no orphans remain — but
**don't touch "creator" back-references.** Add a
`cascadeDelete($id)` called only after the main `delete()` succeeds. Established:
- Delete **user** → clear `user-x-user-group`, `user-x-permission`, `user-dashboard-panel`, the avatar files, then `Event::dispatch($web, "user.delete", ["userId" => $id])` so packages clean their rows. **Don't** touch other users' `user__id` creator field. (`route/admin/user/page.php`)
- Delete **user-group** → clear `user-x-user-group`, `user-group-x-permission`. (`route/admin/user-group/page.php`)
- Delete **permission** → clear `user-x-permission`, `user-group-x-permission`. (`route/admin/permission/page.php`)

(paths relative to `source/_site/andora/web/`)

Bulk delete = `clear()` → set the reference field → `delete()` (deletes all rows
matching the non-key example field). There is no transaction wrapper exposed, so
delete the authoritative row first, then cascade.

## Sidebar / navigation

The sidebar is the `andora-web-admin-menu` table (`TableAdminMenu`), filtered per user by
`Sidebar::getMenu()` (permission + level, `Acl`). Locations: `admin.sidebar`
(main menu: Dashboard and the pages of packages, under a section such as
`admin.home`), `admin.panel` (the **Administration** popup, component
`SidebarPanel`: the system pages, listed in `Setup::$adminPanelMenu`, no
parent), `admin.bottom` (links above the popup). Register the entry once (by code: a later call does not update it), e.g.
in `Setup::dsSeed` (runs at setup and upgrade; a package does it in its
`seed()` with `$this->registerAdminMenu(...)`, see andora-web-package):
`Setup::registerAdminMenu($web, "admin.<area>", "admin.sidebar", "admin.home", "<Label>", "<lucide-icon>", "admin/<area>", "<permission>", Acl::LEVEL_PUBLIC, <position>)`.
Give the page folder an `authorization.php` extending `\Andora\Web\Route\Admin\Authorization`
with the same `$permission`, so the menu and the access agree. A new seed for
existing sites needs `Setup::SCHEMA_VERSION` raised (the upgrade runs `dsSeed`).

## Language files

Each page has `language/en-us.php` and `language/ro-ro.php` returning a flat
`key => string` map (Romanian strings are unaccented ASCII in this repo).
Inputs read `"<name>.legend"` / `"<name>.placeholder"`. Message screens read the
key you pass to `messageOk/messageError` (`add-ok`, `add-error`, `save-ok`,
`save-error`, `delete-ok`, `delete-error`, `an-error-occurred`). Row-action
labels in the DataTableAction descriptor are plain literals (e.g. `"Edit"`),
matching existing pages. Copy `language/index.html` from a sibling.

## Icons

`renderComponent("icons", array("icon"=>"<name>", "class"=>"…"))` renders a
Lucide icon; the name maps to `lucide-<name>` in
`output/_site/xyo/lucide-icons/lucide-icons.min.css`. Verify an icon exists there
before using it (used so far: `user`, `users`, `link`, `shield-check`,
`square-pen`, `trash-2`, `triangle-alert`, `ellipsis-vertical`, `check`, `x`).

## Gotchas learned

- **Empty add list is often correct**, not a bug: already linked targets,
  implicit groups (public, authenticated) and groups at or above your level
  are left out. Surface a `no-…` empty-state message.
- **New daisyUI component classes** (e.g. `toggle`) aren't in the CSS until a
  build picks them up from `output/`; always rebuild and grep the compiled CSS.
- **Escape interpolated values** with `htmlspecialchars(..., ENT_QUOTES)`.
- **The DB is real** at `output/_repository/andora.sqlite`; inspect with `php -r`
  + PDO when debugging data issues rather than guessing.

## New-page checklist

1. `source/_site/andora/web/route/admin/<area>/` with `page.php` (`return Page::class;`), forms, `index.html`, `language/{en-us,ro-ro,index.html}.php`.
2. Namespace `Andora\Web\Route\Admin\<Area>`; class/file names must kebab-match (Golden rule 3).
3. Data already has a `table-*`? If not, add it per **xyo-web-datasource**.
4. Register the page in the sidebar.
5. `php -l` each file → `fabricare make` → confirm files in `output/` and (for new CSS classes) grep the compiled `andora.css`.
