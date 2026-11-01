<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The example package of the developer kit (template/package, acme/example):
// uploaded, its public page, its admin page (add / edit / enable / delete
// items), its permission, its events (user.*, acme-example.item-add),
// uninstall; then the kit itself (fabricare make, sign, release) when
// fabricare is on the PATH.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";
$template = realpath(__DIR__ . "/../template/package");

function password_hash_andora($password)
{
    $salt = bin2hex(random_bytes(32));
    return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $password), false);
}

function add_user($db, $name, $email, $password, $groupName = null)
{
    $statement = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"is_confirmed\", \"password\", \"uuid\") VALUES (?, ?, 1, 1, ?, ?)");
    $statement->execute([$name, $email, password_hash_andora($password), "uuid-" . $email]);
    $userId = intval($db->lastInsertId());
    if (!is_null($groupName)) {
        $group = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
        $group->execute([$groupName]);
        $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, intval($group->fetchColumn())]);
    }
    return $userId;
}

function table_exists($db, $name)
{
    $statement = $db->prepare("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" = ?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}

function items($db)
{
    return $db->query("SELECT \"name\", \"description\", \"enabled\", \"user__id\" FROM \"acme-example-item\" ORDER BY \"id\"")->fetchAll(PDO::FETCH_ASSOC);
}

// The zip of the example (source of the template, version $version)
function example_zip($site, $template, $version)
{
    $base = $template . "/source";
    $files = [];
    $temporary = $site->root . "/_log/example-" . $version;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        $name = substr(str_replace("\\", "/", $item->getPathname()), strlen(str_replace("\\", "/", $base)) + 1);
        $files[$name] = $item->getPathname();
    }
    $manifest = str_replace("#{VERSION_VERSION}", $version, file_get_contents($base . "/_site/acme/example/package.json"));
    @mkdir($temporary, 0777, true);
    file_put_contents($temporary . "/package.json", $manifest);
    $files["_site/acme/example/package.json"] = $temporary . "/package.json";
    $zip = $site->root . "/_log/acme.example.v" . $version . ".zip";
    $reason = "";
    check("example zip written", \Andora\Web\Package\Zip::write($zip, $files, $reason));
    return $zip;
}

function deploy($browser, $zip, $fields = ["trust" => "1"])
{
    $form = $browser->component("admin/package", "dialog", ["component" => "form-upload"]);
    $response = $browser->submitFile("admin/package", $form->body, "dialog.form-upload_form", [], "package", $zip);
    $stageId = preg_match("/\"stage__id\",\"([0-9a-f]{32})\"/", $response->body, $match) ? $match[1] : null;
    check("upload accepted: " . basename($zip), !is_null($stageId));
    $confirm = $browser->postComponent("admin/package", "dialog", ["component" => "form-confirm", "init" => "true", "stage__id" => $stageId]);
    $commit = $browser->submit("admin/package", $confirm->body, "dialog.form-confirm_form", $fields);
    $migrate = new TestResponse();
    if (preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $commit->body, $match)) {
        $migrate = $browser->postComponent("admin/package", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
    }
    return [$confirm, $migrate];
}

function menu_links($html)
{
    $start = strpos($html, "id=\"sidebar\"");
    if ($start === false) {
        return "";
    }
    $html = substr($html, $start);
    $end = strpos($html, "id=\"sidebar-user-menu\"");
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    preg_match_all("/href=\"[^\"]*\\/(admin\\/[a-z\\-]+)\"/", $html, $match);
    return implode(",", $match[1]);
}

function item_id($db, $name)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"acme-example-item\" WHERE \"name\" = ?");
    $statement->execute([$name]);
    return intval($statement->fetchColumn());
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
\Andora\Web\Package\Zip::$useZipArchive = false;
$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$ownerId = intval($site->userByEmail("owner@example.test")["id"]);

t_section("install the example");

list($confirm, $migrate) = deploy($owner, example_zip($site, $template, "1.0.0"));
check_contains("confirm: the example", "Example package: a public page", $confirm->body);
check_contains("confirm: unsigned", "Unsigned", $confirm->body);
check_contains("installed", "install-ok", $migrate->body);
$db = $site->db();
check("its table", table_exists($db, "acme-example-item"));
check_eq("its permission, granted to administrator", 1, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-group-x-permission\" AS \"l\" JOIN \"andora-web-permission\" AS \"p\" ON \"p\".\"id\" = \"l\".\"permission__id\" JOIN \"andora-web-user-group\" AS \"g\" ON \"g\".\"id\" = \"l\".\"user_group__id\" WHERE \"p\".\"name\" = 'acme-example.manage' AND \"g\".\"name\" = 'administrator'")->fetchColumn()));
$db = null;
check_contains("routes mounted", "'example' => '_site/acme/example/route/example'", file_get_contents($site->root . "/config.08.route.php"));

t_section("public page");

$anonymous = $site->browser();
$page = $anonymous->get("example");
check_eq("example is 200", 200, $page->code);
check_contains("example: its title (language file of the route)", "<h1 class=\"text-4xl font-bold\">Example</h1>", $page->body);
check_contains("example: no items yet", "No items yet.", $page->body);

t_section("admin page");

check_eq("admin/example not logged in: login", 302, $anonymous->get("admin/example")->code);
$page = $owner->get("admin/example");
check_eq("root: admin/example is 200", 200, $page->code);
check_contains("root: admin layout", "id=\"sidebar\"", $page->body);
check_contains("root: menu entry", "admin/example", menu_links($page->body));
$db = $site->db();
$administratorId = add_user($db, "Administrator", "administrator@example.test", $password, "administrator");
add_user($db, "Plain", "plain@example.test", $password, "user");
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("administrator (granted by the package): 200", 200, $administrator->get("admin/example")->code);
$plain = $site->browser();
$plain->login("plain@example.test", $password);
check_eq("plain user: 401", 401, $plain->get("admin/example")->code);

t_section("dashboard panel and events");

$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-add"]);
$owner->submit("admin/user", $response->body, "dialog.form-add_form", ["name" => "Created", "email" => "created@example.test", "password" => $password, "passwordConfirmation" => $password]);
$activity = json_decode("" . @file_get_contents($site->root . "/_repository/acme/example/activity.json"), true);
check_eq("onLogin: the last login", intval($site->userByEmail("plain@example.test")["id"]), intval($activity["lastLogin"]["user"] ?? 0));
check_eq("onUserCreate: counted", 1, intval($activity["usersCreated"] ?? 0));
$page = $owner->get("admin/dashboard");
check_contains("the panel of the example", "id=\"acme-example-widget\">Items: 0</p>", $page->body);
check_contains("the panel: accounts created", "Accounts created: 1", $page->body);
check_contains("administrator (has acme-example.manage): the panel", "id=\"acme-example-widget\"", $administrator->get("admin/dashboard")->body);

$form = $owner->component("admin/example", "dialog", ["component" => "form-add"]);
check_contains("add form", "dialog.form-add_form", $form->body);
$response = $owner->submit("admin/example", $form->body, "dialog.form-add_form", ["name" => "First item", "description" => "<b>bold</b>"]);
check_contains("add", "add-ok", $response->body);
$form = $administrator->component("admin/example", "dialog", ["component" => "form-add"]);
$administrator->submit("admin/example", $form->body, "dialog.form-add_form", ["name" => "Item of the administrator", "description" => ""]);
$db = $site->db();
$list = items($db);
check_eq("2 items", 2, count($list));
$activity = json_decode("" . @file_get_contents($site->root . "/_repository/acme/example/activity.json"), true);
check_eq("acme-example.item-add: its own event, counted", 2, intval($activity["itemsAdded"] ?? 0));
check_eq("acme-example.item-add: the item of the event", item_id($db, "Item of the administrator"), intval($activity["lastItemAdded"] ?? 0));
check_contains("the event of the example is registered", "'acme-example.item-add' => 'acme/example'", file_get_contents($site->root . "/config.11.event.php"));
check_eq("the item has its user", $ownerId, intval($list[0]["user__id"]));
$firstId = item_id($db, "First item");
$db = null;
$page = $anonymous->get("example");
check_contains("public page lists the item", "First item", $page->body);
check_not_contains("public page escapes", "<b>bold</b>", $page->body);
check_contains("public page escaped description", "&lt;b&gt;bold&lt;/b&gt;", $page->body);
check_contains("admin table lists the item", "First item", $owner->get("admin/example")->body);

$form = $owner->postComponent("admin/example", "dialog", ["component" => "form-edit", "init" => "true", "item__id" => $firstId]);
check_contains("edit form has the name", "First item", $form->body);
$response = $owner->submit("admin/example", $form->body, "dialog.form-edit_form", ["name" => "Renamed item", "description" => "new"]);
check_contains("edit", "save-ok", $response->body);
$owner->postComponent("admin/example", "datatable.enabled=enabled_" . $firstId . "/enabled_" . $firstId, ["id" => $firstId]);
$db = $site->db();
check_eq("renamed and disabled", ["Renamed item", "new", 0], (function ($item) {
    return [$item["name"], $item["description"], intval($item["enabled"])];
})(items($db)[0]));
$db = null;
check_not_contains("a disabled item is not on the public page", "Renamed item", $anonymous->get("example")->body);
$form = $owner->postComponent("admin/example", "dialog", ["component" => "form-delete", "init" => "true", "item__id" => $firstId]);
$response = $owner->submit("admin/example", $form->body, "dialog.form-delete_form", []);
check_contains("delete", "delete-ok", $response->body);

t_section("onUserDelete");

$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $administratorId]);
$owner->submit("admin/user", $response->body, "dialog.form-delete_form", []);
$db = $site->db();
check_eq("the items of a deleted user are deleted", 0, count(items($db)));
$db = null;

t_section("upgrade and uninstall");

list($confirm, $migrate) = deploy($owner, example_zip($site, $template, "1.1.0"));
check_contains("upgrade to 1.1.0", "upgrade-ok", $migrate->body);
$response = $owner->postComponent("admin/package", "dialog", ["component" => "form-uninstall", "init" => "true", "package__id" => "acme_example"]);
$response = $owner->submit("admin/package", $response->body, "dialog.form-uninstall_form", []);
check_contains("uninstall", "uninstall-ok", $response->body);
$db = $site->db();
check("uninstall: table dropped", !table_exists($db, "acme-example-item"));
$db = null;
check_eq("uninstall: example is 404", 404, $anonymous->get("example")->code);

t_section("developer kit: fabricare make, sign, release");

$output = [];
$code = 0;
// the Windows build of fabricare: not the settings of a Git Bash / MSYS shell
putenv("NoDefaultCurrentDirectoryInExePath");
putenv("MSYSTEM");
exec("fabricare --version 2>&1", $output, $code);
if ($code !== 0) {
    t_skip("developer kit", "fabricare is not on the PATH");
} else {
    $kit = $site->root . "/_log/kit";
    TestSite::copyDir($template, $kit);
    $tool = $site->root . "/_site/andora/web/package/tool.php";
    exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($tool) . " keygen " . escapeshellarg($site->root . "/_log/kit-private.pem") . " " . escapeshellarg($site->root . "/_log/kit-public.pem") . " 2>&1", $output, $code);
    check_eq("kit: keygen", 0, $code);
    putenv("ANDORA_TOOL=" . $tool);
    putenv("ANDORA_PACKAGE_KEY=" . $site->root . "/_log/kit-private.pem");
    $cwd = getcwd();
    chdir($kit);
    foreach (["make", "sign", "release"] as $action) {
        $output = [];
        exec("fabricare " . $action . " 2>&1", $output, $code);
        check_eq("kit: fabricare " . $action, 0, $code);
        if ($code !== 0) {
            echo implode("\n", $output) . "\n";
        }
    }
    chdir($cwd);
    check_contains("kit: the version in package.json", "\"version\": \"1.0.0\"", "" . @file_get_contents($kit . "/output/_site/acme/example/package.json"));
    check("kit: package.sig", is_file($kit . "/output/_site/acme/example/package.sig"));
    $zip = $kit . "/release/acme.example.v1.0.0.zip";
    check("kit: release zip", is_file($zip));
    if (is_file($zip)) {
        exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($site->root . "/_site/andora/web/package/cli.php") . " trust add acme " . escapeshellarg($site->root . "/_log/kit-public.pem") . " --vendor=acme 2>&1", $output, $code);
        list($confirm, $migrate) = deploy($owner, $zip, []);
        check_contains("kit: the release zip is signed by the trusted key", "Signed by a trusted key: acme", $confirm->body);
        check_contains("kit: the release zip installs", "install-ok", $migrate->body);
        check_eq("kit: example is 200", 200, $anonymous->get("example")->code);
    }
}

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
