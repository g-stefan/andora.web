<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The dashboard of the users: the panels of Andora (users, packages), the
// default panels, admin/dashboard/edit (remove, add, size, move, order by
// drag and drop, reset), the permissions of the panels, one dashboard per
// user, the rows of a deleted user and the upgrade from schema 9.

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

function table_exists($db, $name)
{
    $statement = $db->prepare("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" = ?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}

// The panels shown, in their order (data-panel of the dashboard grid or of the editor grid)
function panels($html, $gridId)
{
    $start = strpos($html, "id=\"" . $gridId . "\"");
    if ($start === false) {
        return "";
    }
    $html = substr($html, $start);
    $end = strpos($html, "id=\"dashboard-available\"");
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    preg_match_all("/<div class=\"card [^\"]*\"[^>]*data-panel=\"([^\"]+)\"/", $html, $match);
    return implode(",", $match[1]);
}

function dashboard($browser)
{
    return panels($browser->get("admin/dashboard")->body, "dashboard-panels");
}

function edit($browser, $payload)
{
    $browser->get("admin/dashboard/edit");
    return $browser->postComponent("admin/dashboard/edit", "editor", $payload);
}

function rows($site, $userId)
{
    $statement = $site->db()->prepare("SELECT \"panel\", \"size\", \"enabled\" FROM \"andora-web-user-dashboard-panel\" WHERE \"user__id\" = ? ORDER BY \"enabled\" DESC, \"position\", \"panel\"");
    $statement->execute([$userId]);
    $list = [];
    foreach ($statement->fetchAll() as $row) {
        $list[] = $row["panel"] . ":" . $row["size"] . ":" . $row["enabled"];
    }
    return implode(",", $list);
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$ownerId = intval($site->userByEmail("owner@example.test")["id"]);

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");

t_section("the default panels");

check("the table andora-web-user-dashboard-panel", table_exists($site->db(), "andora-web-user-dashboard-panel"));
$page = $owner->get("admin/dashboard");
check_eq("dashboard is 200", 200, $page->code);
check_eq("the default panels", "andora/web/users,andora/web/packages", panels($page->body, "dashboard-panels"));
check_contains("users: the count", "id=\"panel-users\"", $page->body);
check_contains("users: one account", "data-count=\"1\"", $page->body);
check_contains("users: the link", "admin/user\"", $page->body);
check_contains("packages: the count", "id=\"panel-packages\"", $page->body);
check_not_contains("no edit button: the dashboard is edited from the administration popup", "id=\"dashboard-edit\"", $page->body);
check_contains("the edit page is in the administration popup", "admin/dashboard/edit\"", $page->body);
check_eq("no rows: the default dashboard", "", rows($site, $ownerId));

$page = $owner->get("admin/dashboard/edit");
check_eq("edit: 200", 200, $page->code);
check_contains("edit: the default panels", "id=\"dashboard-default\"", $page->body);
check_eq("edit: the panels", "andora/web/users,andora/web/packages", panels($page->body, "editor_grid"));
check_contains("edit: nothing to add", "Every panel is on your dashboard.", $page->body);
check_contains("edit: reset is disabled", "id=\"dashboard-reset\" disabled", $page->body);
check_contains("edit: drag and drop", "draggable=\"true\"", $page->body);

t_section("remove, add");

$response = edit($owner, ["action" => "remove", "panel" => "andora/web/users"]);
check_eq("remove: 200", 200, $response->code);
check_eq("remove: the editor", "andora/web/packages", panels($response->body, "editor_grid"));
check_contains("remove: the panel can be added", "data-available=\"andora/web/users\"", $response->body);
check_not_contains("remove: no longer the default panels", "id=\"dashboard-default\"", $response->body);
check_eq("remove: the rows", "andora/web/packages:1:1,andora/web/users:1:0", rows($site, $ownerId));
check_eq("remove: the dashboard", "andora/web/packages", dashboard($owner));

$response = edit($owner, ["action" => "add", "panel" => "andora/web/users"]);
check_eq("add: at the end", "andora/web/packages,andora/web/users", panels($response->body, "editor_grid"));
check_eq("add: the dashboard", "andora/web/packages,andora/web/users", dashboard($owner));
edit($owner, ["action" => "add", "panel" => "andora/web/users"]);
check_eq("add again: nothing changes", "andora/web/packages:1:1,andora/web/users:1:1", rows($site, $ownerId));
edit($owner, ["action" => "add", "panel" => "acme/none/panel"]);
check_eq("add a panel that does not exist: nothing changes", "andora/web/packages:1:1,andora/web/users:1:1", rows($site, $ownerId));
edit($owner, ["action" => "unknown", "panel" => "andora/web/users"]);
check_eq("an unknown action: nothing changes", "andora/web/packages:1:1,andora/web/users:1:1", rows($site, $ownerId));

t_section("size, move, order");

$response = edit($owner, ["action" => "size", "panel" => "andora/web/users", "size" => "3"]);
check_eq("size: saved", "andora/web/packages:1:1,andora/web/users:3:1", rows($site, $ownerId));
check_contains("size: the full row", "md:col-span-2 xl:col-span-3\" id=\"panel-andora--web--users\"", $owner->get("admin/dashboard")->body);
edit($owner, ["action" => "size", "panel" => "andora/web/users", "size" => "9"]);
check_eq("size: a size that is not valid is 1", "andora/web/packages:1:1,andora/web/users:1:1", rows($site, $ownerId));
edit($owner, ["action" => "size", "panel" => "andora/web/users", "size" => "2"]);

edit($owner, ["action" => "move-left", "panel" => "andora/web/users"]);
check_eq("move left", "andora/web/users,andora/web/packages", dashboard($owner));
edit($owner, ["action" => "move-left", "panel" => "andora/web/users"]);
check_eq("move left: already the first", "andora/web/users,andora/web/packages", dashboard($owner));
edit($owner, ["action" => "move-right", "panel" => "andora/web/users"]);
check_eq("move right", "andora/web/packages,andora/web/users", dashboard($owner));

$response = edit($owner, ["action" => "order", "order" => "andora/web/users,andora/web/packages"]);
check_eq("order (drag and drop)", "andora/web/users,andora/web/packages", panels($response->body, "editor_grid"));
check_eq("order: the dashboard", "andora/web/users,andora/web/packages", dashboard($owner));
check_eq("order: the size is kept", "andora/web/users:2:1,andora/web/packages:1:1", rows($site, $ownerId));
$response = edit($owner, ["action" => "order", "order" => "andora/web/packages"]);
check_contains("order: not the same panels", "The dashboard was changed in the meantime", $response->body);
check_eq("order: not changed", "andora/web/users,andora/web/packages", dashboard($owner));
edit($owner, ["action" => "order", "order" => "andora/web/packages,andora/web/packages"]);
check_eq("order: a panel twice, not changed", "andora/web/users,andora/web/packages", dashboard($owner));

t_section("an empty dashboard, reset");

edit($owner, ["action" => "remove", "panel" => "andora/web/users"]);
$response = edit($owner, ["action" => "remove", "panel" => "andora/web/packages"]);
check_contains("edit: no panels", "id=\"dashboard-edit-empty\"", $response->body);
$page = $owner->get("admin/dashboard");
check_eq("the dashboard is empty", "", panels($page->body, "dashboard-panels"));
check_contains("the dashboard says so", "id=\"dashboard-empty\"", $page->body);
check_eq("the rows say the panels were removed (default size)", "andora/web/packages:1:0,andora/web/users:1:0", rows($site, $ownerId));

$response = edit($owner, ["action" => "reset"]);
check_contains("reset: the default panels", "id=\"dashboard-default\"", $response->body);
check_eq("reset: no rows", "", rows($site, $ownerId));
check_eq("reset: the dashboard", "andora/web/users,andora/web/packages", dashboard($owner));

t_section("permissions, one dashboard per user");

$db = $site->db();
$administratorId = add_user($db, "Administrator", "administrator@example.test", $password, "administrator");
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("administrator (no package.manage): only the users panel", "andora/web/users", dashboard($administrator));
check_contains("administrator: the edit page is in the administration popup", "admin/dashboard/edit\"", $administrator->get("admin/dashboard")->body);
edit($administrator, ["action" => "add", "panel" => "andora/web/packages"]);
check_eq("administrator: can not add the packages panel", "", rows($site, $administratorId));
edit($administrator, ["action" => "remove", "panel" => "andora/web/users"]);
check_eq("administrator: the users panel removed", "andora/web/users:1:0", rows($site, $administratorId));
check_eq("administrator: an empty dashboard", "", dashboard($administrator));
check_eq("the owner: the dashboard is not changed", "andora/web/users,andora/web/packages", dashboard($owner));

$plain = $site->browser();
$db = $site->db();
add_user($db, "Plain", "plain@example.test", $password, "user");
$db = null;
$plain->login("plain@example.test", $password);
check_eq("a user without system.admin: edit page is 401", 401, $plain->get("admin/dashboard/edit")->code);

t_section("the rows of a deleted user");

$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $administratorId]);
$owner->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check_eq("the user is deleted", false, $site->userByEmail("administrator@example.test"));
check_eq("the rows of the user are deleted", "", rows($site, $administratorId));

t_section("schema upgrade");

// A database of an older schema version: a table and a menu entry are missing
edit($owner, ["action" => "remove", "panel" => "andora/web/packages"]);
$db = $site->db();
$db->exec("DROP TABLE \"andora-web-user-dashboard-panel\"");
$db->exec("DELETE FROM \"andora-web-admin-menu\" WHERE \"code\" = 'admin.dashboard-edit'");
$db = null;
file_put_contents($site->root . "/config.06.schema.php", "<?php\ndefined(\"XYO_WEB\") or die(\"Forbidden\");\nreturn array('schemaVersion' => 0);\n");
$page = $owner->get("admin/dashboard");
check_eq("upgrade: the dashboard", 200, $page->code);
check_contains("upgrade: schema version 1 written", "'schemaVersion' => 1", $site->config("config.06.schema.php"));
check("upgrade: the table is created", table_exists($site->db(), "andora-web-user-dashboard-panel"));
check_contains("upgrade: the edit page in the administration popup", "admin/dashboard/edit\"", $page->body);
check_eq("upgrade: the default panels", "andora/web/users,andora/web/packages", panels($page->body, "dashboard-panels"));

check_eq("no 501", "", $site->routerLog());

t_done();
