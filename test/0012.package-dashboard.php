<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The dashboard and the packages: the events user.create (setup, sign-up,
// /admin/user), user.login, user.delete, user.logout; the panels of the active packages
// (render callback, component with AJAX, permission, a failing panel, a
// failing package, not valid panels, a panel not on the default dashboard); the notice "packages need attention" (upgrade pending,
// failed upgrade, older files, files missing, broken), only with package.manage.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function password_hash_andora($password)
{
    $salt = bin2hex(random_bytes(32));
    return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $password), false);
}

function add_user($db, $name, $email, $password, $groupName)
{
    $statement = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"is_confirmed\", \"password\", \"uuid\") VALUES (?, ?, 1, 1, ?, ?)");
    $statement->execute([$name, $email, password_hash_andora($password), "uuid-" . $email]);
    $userId = intval($db->lastInsertId());
    $group = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $group->execute([$groupName]);
    $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, intval($group->fetchColumn())]);
    return $userId;
}

function write_files($root, $files)
{
    foreach ($files as $name => $content) {
        $file = $root . "/" . $name;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }
}

function manifest($vendor, $name, $version, $extra = [])
{
    return json_encode(array_merge(["vendor" => $vendor, "name" => $name, "version" => $version, "title" => ucfirst($name), "require" => ["andora/web" => ">=0.1"]], $extra), JSON_UNESCAPED_SLASHES);
}

function set_version($site, $code, $version)
{
    $file = $site->root . "/_site/" . $code . "/package.json";
    $data = json_decode(file_get_contents($file), true);
    $data["version"] = $version;
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
}

// acme/hooks: writes the events it gets to _repository/acme/hooks/events.log, panels
function hooks_files()
{
    $guard = "defined(\"ANDORA\") or die(\"Forbidden\");\n";
    return [
        "_site/acme/hooks/package.json" => manifest("acme", "hooks", "1.0.0", ["event" => ["listen" => [
            "user.create" => "onUserCreate",
            "user.login" => "onLogin",
            "user.logout" => "onLogout",
            "user.delete" => "onUserDelete"
        ]]]),
        "_site/acme/hooks/index.html" => "<!DOCTYPE html><html><body></body></html>",
        "_site/acme/hooks/package.php" => "<?php\n" . $guard . "return \\Acme\\Hooks\\Package\\Setup::class;\n",
        "_site/acme/hooks/package/setup.php" => "<?php\nnamespace Acme\\Hooks\\Package;\n\n" . $guard . <<<'PHP'

class Setup extends \Andora\Web\Package\Base
{
    public function seed(&$reason)
    {
        return $this->registerPermission("acme-hooks.secret", "The secret panel", $reason);
    }

    protected function event($line)
    {
        file_put_contents($this->dataPath() . "events.log", $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function onUserCreate($event)
    {
        $this->event("onUserCreate " . $event->get("userId"));
    }

    public function onLogin($event)
    {
        $this->event("onLogin " . $event->get("userId"));
    }

    public function onLogout($event)
    {
        $this->event("onLogout " . $event->get("userId") . " " . $event->name . " " . $event->source);
    }

    public function onUserDelete($event)
    {
        $this->event("onUserDelete " . $event->get("userId"));
    }

    public function dashboardPanels()
    {
        return array(
            "render" => array("title" => "Hooks <render>", "default" => true, "render" => function ($web) {
                echo "<p id=\"hooks-render-panel\">render panel of " . htmlspecialchars($web->get(\XYO\Web\Info::class)->site, ENT_QUOTES) . "</p>";
            }),
            "component" => array("title" => "Hooks component", "default" => true, "component" => \Acme\Hooks\Component\Widget::class),
            "secret" => array("title" => "Hooks secret", "default" => true, "permission" => "acme-hooks.secret", "render" => function ($web) {
                echo "<p id=\"hooks-secret-panel\">secret</p>";
            }),
            "failing" => array("title" => "Hooks failing", "default" => true, "render" => function ($web) {
                echo "partial-output-of-the-failing-panel";
                throw new \Exception("the panel render failed");
            }),
            "extra" => array("title" => "Hooks extra", "render" => function ($web) {
                echo "<p id=\"hooks-extra-panel\">extra</p>";
            }),
            "no-render" => array("title" => "Not a panel: no render", "default" => true),
            "Bad Name" => array("title" => "Not a panel: bad name", "default" => true, "render" => function ($web) {
                echo "<p id=\"hooks-bad-name-panel\">bad name</p>";
            }),
            "not-an-array" => "not an array"
        );
    }
}

PHP,
        "_site/acme/hooks/component/widget.php" => "<?php\nnamespace Acme\\Hooks\\Component;\n\n" . $guard . <<<'PHP'

class Widget extends \XYO\Web\Component
{
    public function renderAJAX($options = null)
    {
        echo "<p id=\"hooks-component-panel\">component panel " . ($this->isComponentAJAX() ? "ajax" : "page") . "</p>";
    }

    public function renderContainer($options = null)
    {
        echo "<div id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}

PHP
    ];
}

// acme/broken-widgets: dashboardPanels() and onLogin throw
function broken_widgets_files()
{
    $guard = "defined(\"ANDORA\") or die(\"Forbidden\");\n";
    return [
        "_site/acme/broken-widgets/package.json" => manifest("acme", "broken-widgets", "1.0.0", ["event" => ["listen" => ["user.login" => "onLogin"]]]),
        "_site/acme/broken-widgets/index.html" => "<!DOCTYPE html><html><body></body></html>",
        "_site/acme/broken-widgets/package.php" => "<?php\n" . $guard . "return \\Acme\\BrokenWidgets\\Package\\Setup::class;\n",
        "_site/acme/broken-widgets/package/setup.php" => "<?php\nnamespace Acme\\BrokenWidgets\\Package;\n\n" . $guard . <<<'PHP'

class Setup extends \Andora\Web\Package\Base
{
    public function onLogin($event)
    {
        throw new \Exception("the login hook failed");
    }

    public function dashboardPanels()
    {
        throw new \Exception("the panels failed");
    }
}

PHP
    ];
}

// acme/fails: its upgrade hook fails
function fails_files()
{
    $guard = "defined(\"ANDORA\") or die(\"Forbidden\");\n";
    return [
        "_site/acme/fails/package.json" => manifest("acme", "fails", "1.0.0"),
        "_site/acme/fails/index.html" => "<!DOCTYPE html><html><body></body></html>",
        "_site/acme/fails/package.php" => "<?php\n" . $guard . "return \\Acme\\Fails\\Package\\Setup::class;\n",
        "_site/acme/fails/package/setup.php" => "<?php\nnamespace Acme\\Fails\\Package;\n\n" . $guard . <<<'PHP'

class Setup extends \Andora\Web\Package\Base
{
    public function upgrade($fromVersion, &$reason)
    {
        $reason = "custom-upgrade-error";
        return false;
    }
}

PHP
    ];
}

function events($site)
{
    $file = $site->root . "/_repository/acme/hooks/events.log";
    return is_file($file) ? array_values(array_filter(explode("\n", file_get_contents($file)))) : [];
}

function package_log($site)
{
    $content = "";
    foreach (glob($site->root . "/_log/*.package.log") as $file) {
        $content .= file_get_contents($file);
    }
    return $content;
}

function cli($site, $arguments)
{
    $command = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($site->root . "/_site/andora/web/package/cli.php");
    foreach ($arguments as $argument) {
        $command .= " " . escapeshellarg($argument);
    }
    $output = [];
    exec($command . " 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

// The text of the notice, "" when there is none
function notice($html)
{
    $start = strpos($html, "id=\"package-notice\"");
    if ($start === false) {
        return "";
    }
    $end = strpos($html, "</ul>", $start);
    return preg_replace("/\\s+/", " ", html_entity_decode(strip_tags(substr($html, $start, $end - $start)), ENT_QUOTES));
}

$site = (new TestSite())->start();
// bundled with the site: installed by the setup
write_files($site->root, hooks_files());
write_files($site->root, broken_widgets_files());
write_files($site->root, fails_files());

t_section("user.create: the administrator of the setup");

$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$ownerId = intval($site->userByEmail("owner@example.test")["id"]);
$db = $site->db();
check_eq("the setup installed the packages", 3, intval($db->query("SELECT COUNT(*) FROM \"andora-web-package\" WHERE \"code\" IN ('acme/hooks', 'acme/broken-widgets', 'acme/fails') AND \"enabled\" = 1")->fetchColumn()));
$db = null;
check_eq("onUserCreate of the setup account", ["onUserCreate " . $ownerId], events($site));

t_section("user.login");

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
check_eq("logged in although a hook of another package failed", 200, $owner->get("admin/dashboard")->code);
check_eq("onLogin", "onLogin " . $ownerId, events($site)[1] ?? "");
check_contains("the failing hook is logged", "the login hook failed", package_log($site));
check_contains("logged as onLogin of acme/broken-widgets", "\"package\": \"acme\\/broken-widgets\"", package_log($site));

t_section("user.create: sign-up and /admin/user");

$visitor = $site->browser();
$page = $visitor->get("user/sign-up");
$visitor->submit("user/sign-up", $page->body, "form_form", ["name" => "Signed Up", "email" => "signed@example.test", "password" => $password, "passwordConfirmation" => $password]);
$signedId = intval($site->userByEmail("signed@example.test")["id"]);
check("sign-up made the account", $signedId > 0);
check_eq("onUserCreate of the sign-up", "onUserCreate " . $signedId, events($site)[2] ?? "");
$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-add"]);
$owner->submit("admin/user", $response->body, "dialog.form-add_form", ["name" => "Added", "email" => "added@example.test", "password" => $password, "passwordConfirmation" => $password]);
$addedId = intval($site->userByEmail("added@example.test")["id"]);
check("admin added the account", $addedId > 0);
check_eq("onUserCreate of /admin/user", "onUserCreate " . $addedId, events($site)[3] ?? "");

t_section("user.delete");

$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $addedId]);
$owner->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check_eq("onUserDelete", "onUserDelete " . $addedId, events($site)[4] ?? "");

t_section("dashboard panels");

$page = $owner->get("admin/dashboard");
check_eq("dashboard is 200", 200, $page->code);
check_contains("render panel", "id=\"hooks-render-panel\">render panel of /", $page->body);
check_contains("the title is escaped", "Hooks &lt;render&gt;", $page->body);
check_contains("component panel", "component panel page", $page->body);
check_contains("root sees the panel of acme-hooks.secret", "id=\"hooks-secret-panel\"", $page->body);
check_contains("the failing panel shows an error", "This panel could not be shown", $page->body);
check_not_contains("the output of the failing panel is dropped", "partial-output-of-the-failing-panel", $page->body);
check_not_contains("a panel that is not default is not shown", "id=\"hooks-extra-panel\"", $page->body);
check_not_contains("a panel with a bad name is left out", "id=\"hooks-bad-name-panel\"", $page->body);
check_not_contains("a panel without render is left out", "Not a panel", $page->body);
check_contains("the panel id", "data-panel=\"acme/hooks/render\"", $page->body);
check_contains("the failing panel is logged", "the panel render failed", package_log($site));
check_contains("the failing package is logged", "the panels failed", package_log($site));
check_eq("no notice: every package is fine", "", notice($page->body));
$response = $owner->component("admin/dashboard", "panel-acme--hooks--component/content");
check_eq("AJAX of a component panel: 200", 200, $response->code);
check_contains("AJAX of a component panel", "component panel ajax", $response->body);

$page = $owner->get("admin/dashboard/edit");
check_eq("edit: 200", 200, $page->code);
check_contains("edit: the panel that is not default can be added", "data-available=\"acme/hooks/extra\"", $page->body);
check_not_contains("edit: no panel with a bad name", "Not a panel", $page->body);

$db = $site->db();
add_user($db, "Administrator", "administrator@example.test", $password, "administrator");
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
$page = $administrator->get("admin/dashboard");
check_eq("administrator: dashboard is 200", 200, $page->code);
check_contains("administrator: render panel", "id=\"hooks-render-panel\"", $page->body);
check_not_contains("administrator: no panel of acme-hooks.secret", "id=\"hooks-secret-panel\"", $page->body);
check_not_contains("administrator: can not add the panel of acme-hooks.secret", "acme/hooks/secret", $administrator->get("admin/dashboard/edit")->body);

t_section("notice: packages need attention");

set_version($site, "acme/hooks", "1.1.0");
$page = $owner->get("admin/dashboard");
$text = notice($page->body);
check_contains("upgrade pending", "acme/hooks Hooks - Upgrade pending (1.0.0 -> 1.1.0)", $text);
check_not_contains("only the packages that need attention", "acme/fails", $text);
check_contains("link to admin/package", "admin/package\"", $page->body);
check_not_contains("an upgrade pending: its panels are not shown", "id=\"hooks-render-panel\"", $page->body);
check_eq("administrator (no package.manage): no notice", "", notice($administrator->get("admin/dashboard")->body));
list($code, $output) = cli($site, ["upgrade", "acme/hooks"]);
check_eq("upgrade: " . $output, 0, $code);
check_eq("after the upgrade: no notice", "", notice($owner->get("admin/dashboard")->body));

set_version($site, "acme/fails", "1.1.0");
list($code, $output) = cli($site, ["upgrade", "acme/fails"]);
check_eq("the upgrade of acme/fails fails", 1, $code);
$text = notice($owner->get("admin/dashboard")->body);
check_contains("failed upgrade", "acme/fails Fails - Upgrade pending (1.0.0 -> 1.1.0); the last upgrade failed: custom-upgrade-error", $text);

set_version($site, "acme/hooks", "1.0.5");
check_contains("older files", "acme/hooks Hooks - Older files (1.1.0 -> 1.0.5)", notice($owner->get("admin/dashboard")->body));
set_version($site, "acme/hooks", "1.1.0");

rename($site->root . "/_site/acme/fails", $site->root . "/_log/fails-moved");
check_contains("files missing", "acme/fails - Files missing", notice($owner->get("admin/dashboard")->body));
rename($site->root . "/_log/fails-moved", $site->root . "/_site/acme/fails");

write_files($site->root, ["_site/acme/bad/package.json" => "{not json"]);
check_contains("broken", "acme/bad - Broken; package.json is not valid", notice($owner->get("admin/dashboard")->body));

t_section("user.logout");

$administratorId = intval($site->userByEmail("administrator@example.test")["id"]);
check_contains("logout", "Logout successfully", $administrator->get("user/logout")->body);
check_eq("user.logout: the event, its name and the package that provides it", "onLogout " . $administratorId . " user.logout andora/web", events($site)[count(events($site)) - 1] ?? "");

t_section("user.create without the event registry");

// The listeners are read from config.11.event.php, not from the database
// (an older site before its first package operation has no registry)
unlink($site->root . "/config.11.event.php");
$count = count(events($site));
$page = $visitor->get("user/sign-up");
$response = $visitor->submit("user/sign-up", $page->body, "form_form", ["name" => "Early", "email" => "early@example.test", "password" => $password, "passwordConfirmation" => $password]);
check_eq("sign-up without the registry: 200", 200, $response->code);
check("sign-up without the registry: account made", $site->userByEmail("early@example.test") !== false);
check_eq("no event without the registry", $count, count(events($site)));

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
