# Development

Andora is built on the XYO.Web framework: the folder tree is the router, pages
are trees of components that can refresh themselves by AJAX, and the data
layer generates SQL for SQLite, MySQL and PostgreSQL from PHP descriptors.

## Layout

```
source/                         the site (copied over the framework into output/)
  index.php                     entry point -> \Andora\Web\Main\run()
  authorization.php             every route -> /setup until the site is configured
  page.php                      home page (/) -> \Andora\Web\Page\Home
  layout.php                    layout of / and the error pages -> \Andora\Web\Layout\Base
  _site/andora/web/             the andora/web package (one folder, replaced on update)
    route/                      its routes, mounted by main.php (package.json "route"):
      setup/                    setup wizard (/setup), its authorization.php opens it
      user/<page>/              login, logout, sign-up, email-confirmation,
                                forgot-password, password-reset, avatar
      admin/                    admin area, authorization.php guards admin/*
        layout.php              sidebar + header shell
        dashboard/ user/ user-group/ permission/ package/ settings/ profile/
    andora.php, main.php        bootstrap (defines ANDORA, ANDORA_PATH, mounts the routes)
    authorization.php           \Andora\Web\Authorization, base of every authorization.php
    client/                     CSS (Tailwind + daisyUI) and JS
    component/                  reusable components (forms, inputs, data table, dialog, ...)
    data-source/                database tables (table-*.php) and joined queries (query-*.php)
    layout/setup.php            centered layout of setup and user pages
    layout/base.php             plain layout with the Andora styles (root layout.php)
    page/home.php               default home page: Andora logo + login link
    model/                      User, Acl, Setup, Sidebar, Dashboard
    package/                    packages: Manifest, Manager, Base, Core, Installer, Zip, Signature, cli.php, tool.php
    package.json, package.php   andora/web as a package (version set by the build)
  _site/vendor/                 PHPMailer (Composer)
fabricare/                      build scripts (make, vendor, test)
template/package/               developer kit: a package project with the example acme/example
test/                           tests
docs/                           this documentation
output/, temp/, vendor/         build results, not in git
```

## Conventions

- **Edit `source/`, never `output/`.** `fabricare make` overwrites `output/`.
- **Class name = file path.** The autoloader turns the class name into a
  kebab-case path, tried under `_site/` and then under the site root:
  `\Andora\Web\DataSource\TableUser` → `_site/andora/web/data-source/table-user.php`,
  `\Andora\Web\Route\User\SignUp\Form` → `_site/andora/web/route/user/sign-up/form.php`
  (the route `user/sign-up` is mounted from that folder). A namespace that does
  not match the folder makes the page fail with "Class not found" (501).
- Every PHP file starts with `defined("ANDORA") or die("Forbidden");` after the
  namespace. `page.php` and `layout.php` return the class name.
- Escape every printed value with `htmlspecialchars($value, ENT_QUOTES)`. The
  AJAX client runs the `<script>` tags of a response with the page nonce, so an
  unescaped value is script injection even with the Content Security Policy.
- Language files: `language/en-us.php` and `language/ro-ro.php` next to the page
  return `key => text`; both must have the same keys. A missing key is shown as
  the key itself.
- Password rules are in one place: `User::checkPasswordPolicy()`.

The project skill **andora-web** (`.claude/skills/andora-web/SKILL.md`)
describes the features and how to use and extend them; **andora-web-admin**
has the recipes for admin pages.

## Add a page

1. Create `source/<route>/page.php`:

   ```php
   <?php
   namespace Hello;

   defined("ANDORA") or die("Forbidden");

   class Page extends \XYO\Web\Page
   {
       public function init($options = null)
       {
           $this->setTitle("Hello");
       }

       public function render($options = null)
       {
           echo "<h1>Hello</h1>";
       }
   }

   return Page::class;
   ```

2. Pick the look with a `layout.php` in the same folder
   (`return \Andora\Web\Layout\Setup::class;` for a centered page).
3. `fabricare make` and open `/<route>`.

`admin`, `user` and `setup` are routes of the andora/web package: a folder
with one of these names in the site root is not used. Pages below them
(`admin/invoice`) come from a package that mounts them ([Packages](package.md#routes));
they get the admin layout and the access check of `admin/`. For admin pages
with a table and add / edit / delete dialogs, copy
`_site/andora/web/route/admin/permission/` into the package and follow the
andora-web-admin skill.

## Replace the home page

After the setup, `/` shows the Andora home page: the logo, a login link and
the theme menu. The root `page.php` only returns `\Andora\Web\Page\Home`
(`_site/andora/web/page/home.php`, texts in
`_site/andora/web/page/language/<language>/home.php`), and the root
`layout.php` returns `\Andora\Web\Layout\Base`, so a system built on Andora
puts its own home page in place without changing the Andora files:

- its own root `page.php` (any page class, or one that extends
  `\Andora\Web\Page\Home`);
- its own root `layout.php` if it needs another look. This layout is also used
  by the error pages (`http-404.php`, ...) and by every page folder without a
  `layout.php`.

The root `authorization.php` still applies: before the setup `/` redirects to
`/setup`.

## Model API (`\Andora\Web\Model\User`)

| Function | Use |
|---|---|
| `addUser($web, $name, $email, $password, &$reason)` | Create an account (validates name, e-mail, password, unique e-mail). |
| `signUp(...)` | `addUser` + confirmation e-mail. |
| `login($web, $email, $password, &$reason)` | Check the password, open a session, set the cookies. |
| `checkCurrentSession($web, &$reason)` | Validate the login cookies; fills `User::$user__id`, `$user__name`, `$user__email`. |
| `logoutCurrentSession($web, &$reason)` | Close the session, remove the cookies. |
| `isAdministrator($web, $userId)` | `Acl::can($web, "system.admin", $userId)`. |
| `grantAdministrator($web, $userId, &$reason)` | Create `system.admin` if needed and grant it directly to a user. |
| `emailConfirm`, `forgotPassword`, `checkPasswordResetRequest`, `passwordReset` | E-mail confirmation and password recovery. |
| `sendMail($web, $email, $name, $subject, $message, $link, $linkText, &$reason)` | Send an e-mail with the SMTP settings. |

Functions return `true` / `false`; on `false`, `$reason` is a short code
(`invalid-password`, `user-not-found`, `configuration-error`, ...).

## Access control (`\Andora\Web\Model\Acl`)

The rules are in [Administration](administration.md#levels). `$userId`
defaults to the logged in user (after `User::checkCurrentSession`), 0 is a
visitor.

| Function | Use |
|---|---|
| `can($web, $permission, $userId = null)` | The permission check. |
| `level($web, $userId = null)`, `isRoot(...)` | `Acl::LEVEL_PUBLIC` (0) ... `Acl::LEVEL_ROOT` (3). |
| `canManageUser($web, $targetUserId, $allowSelf = false)` | The current user may change the target user. |
| `canManageGroup($web, $groupId)` | The current user may change / give the group. |
| `canGrantPermission($web, $permission)` | The current user has the permission, so may give it. |
| `resolve($web, $userId = null)` | `{level, groups, permission}`, loaded once per request and user. |
| `reset()` | Forget the loaded data (after changes in the same request). |

`resolve` uses the Query data sources `QueryUserXUserGroup` (the groups of
the user with their enabled flag and level),
`QueryUserGroupXPermission` and `QueryUserXPermission` (links joined to the
permission name).

Until `configured` is set, `\Andora\Web\Authorization` (used by the root
`authorization.php`) redirects every page to `/setup` (AJAX, JSON and API
requests get 401). A folder with its own `authorization.php` extends it (or a
class that does, like `\Andora\Web\Route\Admin\Authorization`) and calls
`parent::checkAuthorization()` first, so it keeps that redirect. It also
answers 503 for the route of a package whose files are newer than the
installed version (an upgrade is pending).

An admin page that needs a permission gets its own `authorization.php`, here
in a package `acme/invoice` mounting `admin/invoice` from `route/admin/invoice`:

```php
namespace Acme\Invoice\Route\Admin\Invoice;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Acl;

class Authorization extends \Andora\Web\Route\Admin\Authorization
{
    protected $permission = "invoice.manage";
}

return Authorization::class;
```

and its menu entry, registered once (for example from a setup step):

```php
Setup::registerAdminMenu($web, "admin.invoice", "admin.sidebar", "admin.home",
    "Invoice", "receipt", "admin/invoice", "invoice.manage", Acl::LEVEL_PUBLIC, 500);
```

## Tests

```bash
fabricare test
```

or, after `fabricare make`:

```bash
php test/_run-all.php
php test/0002.user.php
```

Each `test/000N.*.php` file runs in its own process. It copies `output/` to the
temporary folder (without configuration and database), starts the PHP built-in
web server on a free port and drives the site like a browser: cookies, CSRF
token, AJAX components and forms (`test/_lib/site.php`). Then it checks the
pages, the database and `_log/*.router.log`.

| File | Covers |
|---|---|
| `0001.setup.php` | Pages before setup (redirect to `/setup`), the setup wizard, the lock after setup, the home page, configuration written without code injection. |
| `0002.user.php` | Login, admin access by permission, sign-up, password rules, e-mail confirmation, forgot / reset password, logout. |
| `0003.admin.php` | Users table (paging, page size, sort, search, escaping), edit and delete dialogs, cascade delete. |
| `0004.acl.php` | Levels, grant / deny order, no groups inside groups, admin menu per account, level rules of the admin pages, root users. |

A new test: copy one of them, use `new TestSite()`, `->start()`, `->setup(...)`,
`->browser()`, `$browser->submit($path, $html, $formId, $fields)` and the
`check*` functions from `test/_lib/harness.php`; end with `t_done()`.

To run the tests against another build, set `ANDORA_TEST_OUTPUT` to its folder.

## Licensing

The project follows the [REUSE](https://reuse.software) specification:
license texts are in `LICENSES/`, files without an SPDX header are covered by
`REUSE.toml`. Check with:

```bash
python -m reuse lint
```

`source/` is Apache-2.0; PHPMailer in `source/_site/vendor/phpmailer/` is
LGPL-2.1-only and the Composer autoloader is MIT. Build scripts, tooling and
the skills are Unlicense.
