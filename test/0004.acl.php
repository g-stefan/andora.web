<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Access control: user levels (public, authenticated, administrator, root),
// permissions (user grant / deny, group deny, group grant), no groups inside
// groups, the admin menu from the admin-menu table and the level rules of the
// admin pages.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function password_hash_andora($password)
{
    $salt = bin2hex(random_bytes(32));
    return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $password), false);
}

function add_user($db, $name, $email, $password)
{
    $statement = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"is_confirmed\", \"password\", \"uuid\") VALUES (?, ?, 1, 1, ?, ?)");
    $statement->execute([$name, $email, password_hash_andora($password), "uuid-" . $email]);
    return intval($db->lastInsertId());
}

function group_id($db, $name)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $statement->execute([$name]);
    return intval($statement->fetchColumn());
}

function add_group($db, $name)
{
    $db->prepare("INSERT INTO \"andora-web-user-group\" (\"name\", \"description\", \"enabled\", \"level\", \"is_system\") VALUES (?, '', 1, 1, 0)")->execute([$name]);
    return intval($db->lastInsertId());
}

function permission_id($db, $name)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"andora-web-permission\" WHERE \"name\" = ?");
    $statement->execute([$name]);
    return intval($statement->fetchColumn());
}

function add_to_group($db, $userId, $groupId)
{
    $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, $groupId]);
}

function grant_group($db, $groupId, $permission, $isGranted = 1)
{
    $db->prepare("INSERT INTO \"andora-web-user-group-x-permission\" (\"user_group__id\", \"permission__id\", \"is_granted\", \"enabled\") VALUES (?, ?, ?, 1)")->execute([$groupId, permission_id($db, $permission), $isGranted]);
}

function grant_user($db, $userId, $permission, $isGranted = 1)
{
    $db->prepare("INSERT INTO \"andora-web-user-x-permission\" (\"user__id\", \"permission__id\", \"is_granted\", \"enabled\") VALUES (?, ?, ?, 1)")->execute([$userId, permission_id($db, $permission), $isGranted]);
}

function user_groups($db, $userId)
{
    $statement = $db->prepare("SELECT \"g\".\"name\" FROM \"andora-web-user-x-user-group\" AS \"l\" JOIN \"andora-web-user-group\" AS \"g\" ON \"g\".\"id\" = \"l\".\"user_group__id\" WHERE \"l\".\"user__id\" = ? ORDER BY \"g\".\"name\"");
    $statement->execute([$userId]);
    return implode(",", $statement->fetchAll(PDO::FETCH_COLUMN));
}

function user_field($db, $userId, $field)
{
    $statement = $db->prepare("SELECT \"" . $field . "\" FROM \"andora-web-user\" WHERE \"id\" = ?");
    $statement->execute([$userId]);
    return $statement->fetchColumn();
}

function login_as($site, $email, $password)
{
    $browser = $site->browser();
    $browser->login($email, $password);
    return $browser;
}

// The links of the admin sidebar, in order (without the user menu: Profile, Log out)
function menu_links($html)
{
    $start = strpos($html, "id=\"sidebar\"");
    if ($start === false) {
        return "";
    }
    $html = substr($html, $start);
    $end = strpos($html, "id=\"sidebar-user-menu\"");
    if ($end === false) {
        $end = strpos($html, "id=\"mobile-overlay\"");
    }
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    preg_match_all("/href=\"[^\"]*?\\/(admin\\/[a-z\\-\\/]+)\"/", $html, $match);
    return implode(",", $match[1]);
}

// The links of the main menu (before the administration popup)
function main_menu_links($html)
{
    $end = strpos($html, "id=\"sidebar-panel-menu\"");
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    return menu_links($html);
}

// The links of the administration popup
function panel_links($html)
{
    $start = strpos($html, "id=\"sidebar-panel-menu\"");
    if ($start === false) {
        return "";
    }
    $html = substr($html, $start);
    $end = strpos($html, "id=\"sidebar-user-menu\"");
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    preg_match_all("/href=\"[^\"]*?\\/(admin\\/[a-z\\-\\/]+)\"/", $html, $match);
    return implode(",", $match[1]);
}

// An item of the add list (InputAutocomplete): ["<id>","<name>"] in its JSON
function offered($id)
{
    return "[\"" . $id . "\",";
}

function open_dialog($browser, $path, $component, $field, $id)
{
    return $browser->postComponent($path, "dialog", ["component" => $component, "init" => "true", $field => $id]);
}

function toggle($browser, $path, $column, $id)
{
    return $browser->postComponent($path, "datatable." . $column . "=" . $column . "_" . $id . "/" . $column . "_" . $id, ["id" => $id]);
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$db = $site->db();

$ownerId = intval($site->userByEmail("owner@example.test")["id"]);
$administratorGroupId = group_id($db, "administrator");
$rootGroupId = group_id($db, "root");

$adminId = add_user($db, "Admin", "admin@example.test", $password);
add_to_group($db, $adminId, $administratorGroupId);
$plainId = add_user($db, "Plain", "plain@example.test", $password);
$viewerId = add_user($db, "Viewer", "viewer@example.test", $password);
$viewersGroupId = add_group($db, "Viewers");
add_to_group($db, $viewerId, $viewersGroupId);
grant_group($db, $viewersGroupId, "system.admin");

t_section("levels and the admin menu");

$owner = login_as($site, "owner@example.test", "Owner-Password-12");
$page = $owner->get("admin/dashboard");
check_eq("root: dashboard is 200", 200, $page->code);
check_eq("root: every menu entry", "admin/dashboard,admin/dashboard/edit,admin/user,admin/user-group,admin/permission,admin/package,admin/database,admin/settings,admin/system,admin/about", menu_links($page->body));
check_eq("root: main menu has only the dashboard", "admin/dashboard", main_menu_links($page->body));
check_eq("root: the administration popup", "admin/dashboard/edit,admin/user,admin/user-group,admin/permission,admin/package,admin/database,admin/settings,admin/system,admin/about", panel_links($page->body));
check_eq("root: permission page is 200", 200, $owner->get("admin/permission")->code);

$admin = login_as($site, "admin@example.test", $password);
$page = $admin->get("admin/dashboard");
check_eq("administrator: dashboard is 200", 200, $page->code);
check_eq("administrator: menu without permission and package (root only)", "admin/dashboard,admin/dashboard/edit,admin/user,admin/user-group,admin/settings,admin/system,admin/about", menu_links($page->body));
check_eq("administrator: the administration popup", "admin/dashboard/edit,admin/user,admin/user-group,admin/settings,admin/system,admin/about", panel_links($page->body));
check_eq("administrator: user page is 200", 200, $admin->get("admin/user")->code);
check_eq("administrator: settings page is 200", 200, $admin->get("admin/settings")->code);
check_eq("administrator: system page is 200", 200, $admin->get("admin/system")->code);
check_eq("administrator: permission page is 401", 401, $admin->get("admin/permission")->code);
check_eq("administrator: AJAX of the permission page is 401", 401, $admin->postComponent("admin/permission", "dialog", ["component" => "form-add"])->code);

$plain = login_as($site, "plain@example.test", $password);
check_eq("authenticated: dashboard is 401", 401, $plain->get("admin/dashboard")->code);

$viewer = login_as($site, "viewer@example.test", $password);
$page = $viewer->get("admin/dashboard");
check_eq("group with system.admin: dashboard is 200", 200, $page->code);
check_eq("group with system.admin: the dashboard, its edit page and About in the menu", "admin/dashboard,admin/dashboard/edit,admin/about", menu_links($page->body));
check_eq("group with system.admin: only the dashboard edit page and About in the administration popup", "admin/dashboard/edit,admin/about", panel_links($page->body));
check_eq("group with system.admin: user page is 401", 401, $viewer->get("admin/user")->code);
check_eq("group with system.admin: system page is 401 (system.settings)", 401, $viewer->get("admin/system")->code);

$page = $viewer->get("admin/about");
check_eq("group with system.admin: About page is 200", 200, $page->code);
check_contains("About: the logo", "logo-andora", $page->body);
check_contains("About: the version", "id=\"about-version\"", $page->body);
check_contains("About: the license", "Apache License, Version 2.0", $page->body);
check_eq("authenticated: About page is 401", 401, $plain->get("admin/about")->code);

t_section("grant and deny");

grant_user($db, $viewerId, "system.admin", 0);
check_eq("user deny wins over the group grant", 401, $viewer->get("admin/dashboard")->code);
$db->prepare("DELETE FROM \"andora-web-user-x-permission\" WHERE \"user__id\" = ?")->execute([$viewerId]);

$denyGroupId = add_group($db, "No dashboard");
grant_group($db, $denyGroupId, "system.admin", 0);
add_to_group($db, $viewerId, $denyGroupId);
check_eq("group deny wins over group grant", 401, $viewer->get("admin/dashboard")->code);

grant_user($db, $viewerId, "system.admin", 1);
check_eq("user grant wins over the group deny", 200, $viewer->get("admin/dashboard")->code);
$db->prepare("DELETE FROM \"andora-web-user-x-permission\" WHERE \"user__id\" = ?")->execute([$viewerId]);
$db->prepare("DELETE FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = ? AND \"user_group__id\" = ?")->execute([$viewerId, $denyGroupId]);

$db->prepare("UPDATE \"andora-web-user-group\" SET \"enabled\" = 0 WHERE \"id\" = ?")->execute([$viewersGroupId]);
check_eq("disabled group gives nothing", 401, $viewer->get("admin/dashboard")->code);
$db->prepare("UPDATE \"andora-web-user-group\" SET \"enabled\" = 1 WHERE \"id\" = ?")->execute([$viewersGroupId]);

$customPermissionId = permission_id($db, "system.admin");
$db->prepare("UPDATE \"andora-web-permission\" SET \"enabled\" = 0 WHERE \"id\" = ?")->execute([$customPermissionId]);
check_eq("disabled permission: not granted", 401, $viewer->get("admin/dashboard")->code);
check_eq("disabled permission: root still has it", 200, $owner->get("admin/dashboard")->code);
$db->prepare("UPDATE \"andora-web-permission\" SET \"enabled\" = 1 WHERE \"id\" = ?")->execute([$customPermissionId]);

t_section("no groups inside groups");

check_not_contains("user groups have no Linked groups action", "Linked groups", $owner->get("admin/user-group")->body);
check_not_contains("the popup of linked groups is gone", "dialog.form-linked_form", open_dialog($owner, "admin/user-group", "form-linked", "user_group__id", $viewersGroupId)->body);

$nestedId = add_user($db, "Nested", "nested@example.test", $password);
$nested = login_as($site, "nested@example.test", $password);
add_to_group($db, $nestedId, $administratorGroupId);
check_eq("administrator member: user page is 200", 200, $nested->get("admin/user")->code);
$db->prepare("DELETE FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = ?")->execute([$nestedId]);

t_section("administrator can not change root");

$response = open_dialog($admin, "admin/user", "form-edit", "user__id", $ownerId);
$admin->submit("admin/user", $response->body, "dialog.form-edit_form", [
    "name" => "Changed by admin",
    "email" => "owner@example.test",
    "password" => "",
    "passwordConfirmation" => ""
]);
check_eq("administrator can not edit a root user", "Owner", user_field($db, $ownerId, "name"));

$response = open_dialog($admin, "admin/user", "form-delete", "user__id", $ownerId);
$admin->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check("administrator can not delete a root user", $site->userByEmail("owner@example.test") !== false);

toggle($admin, "admin/user", "enabled", $ownerId);
check_eq("administrator can not disable a root user", 1, intval(user_field($db, $ownerId, "enabled")));

$response = open_dialog($admin, "admin/user", "form-linked", "user__id", $plainId);
check_not_contains("root is not offered to an administrator", offered($rootGroupId), $response->body);
check_not_contains("administrator is not offered to an administrator", offered($administratorGroupId), $response->body);
check_contains("a custom group is offered", offered($viewersGroupId), $response->body);
check_contains("the user group is offered", offered(group_id($db, "user")), $response->body);
check_not_contains("authenticated (implicit) is not offered", offered(group_id($db, "authenticated")), $response->body);
$admin->submit("admin/user", $response->body, "dialog.form-linked_form", ["group__id" => $rootGroupId, "action" => "add"]);
check_eq("administrator can not give root (forged request)", "", user_groups($db, $plainId));
$admin->submit("admin/user", $response->body, "dialog.form-linked_form", ["group__id" => $viewersGroupId, "action" => "add"]);
check_eq("administrator can give a lower group", "Viewers", user_groups($db, $plainId));

$response = open_dialog($admin, "admin/user", "form-linked", "user__id", $adminId);
$admin->submit("admin/user", $response->body, "dialog.form-linked_form", ["group__id" => $viewersGroupId, "action" => "add"]);
check_eq("nobody changes their own groups", "administrator", user_groups($db, $adminId));

$response = open_dialog($admin, "admin/user-group", "form-linked-permission", "user_group__id", $administratorGroupId);
$admin->submit("admin/user-group", $response->body, "dialog.form-linked-permission_form", ["permission__id" => permission_id($db, "permission.manage"), "action" => "add"]);
check_eq("administrator can not change the administrator group", 0, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-group-x-permission\" WHERE \"permission__id\" = " . permission_id($db, "permission.manage"))->fetchColumn()));

$response = open_dialog($admin, "admin/user", "form-linked-permission", "user__id", $plainId);
check_not_contains("a permission you do not have is not offered", offered(permission_id($db, "permission.manage")), $response->body);
$admin->submit("admin/user", $response->body, "dialog.form-linked-permission_form", ["permission__id" => permission_id($db, "permission.manage"), "action" => "add"]);
check_eq("a permission you do not have can not be given (forged request)", 0, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-x-permission\" WHERE \"user__id\" = " . $plainId)->fetchColumn()));
$admin->submit("admin/user", $response->body, "dialog.form-linked-permission_form", ["permission__id" => permission_id($db, "system.admin"), "action" => "add"]);
check_eq("a permission you have can be given", 1, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-x-permission\" WHERE \"user__id\" = " . $plainId)->fetchColumn()));

t_section("administrator manages lower users");

$response = $admin->postComponent("admin/user", "dialog", ["component" => "form-add"]);
$admin->submit("admin/user", $response->body, "dialog.form-add_form", [
    "name" => "Added by admin",
    "email" => "added@example.test",
    "password" => $password,
    "passwordConfirmation" => $password
]);
$added = $site->userByEmail("added@example.test");
check("administrator adds a user", $added !== false);
check_eq("a new user is in the user group", "user", user_groups($db, intval($added["id"])));

$response = open_dialog($admin, "admin/user", "form-edit", "user__id", $plainId);
$admin->submit("admin/user", $response->body, "dialog.form-edit_form", [
    "name" => "Plain Changed",
    "email" => "plain@example.test",
    "password" => "",
    "passwordConfirmation" => ""
]);
check_eq("administrator edits an authenticated user", "Plain Changed", user_field($db, $plainId, "name"));

$response = open_dialog($admin, "admin/user", "form-edit", "user__id", $adminId);
$admin->submit("admin/user", $response->body, "dialog.form-edit_form", [
    "name" => "Admin Self",
    "email" => "admin@example.test",
    "password" => "",
    "passwordConfirmation" => ""
]);
check_eq("administrator edits their own account", "Admin Self", user_field($db, $adminId, "name"));

$otherAdminId = add_user($db, "Other admin", "other-admin@example.test", $password);
add_to_group($db, $otherAdminId, $administratorGroupId);
toggle($admin, "admin/user", "enabled", $otherAdminId);
check_eq("administrator can not disable another administrator", 1, intval(user_field($db, $otherAdminId, "enabled")));

toggle($admin, "admin/user", "enabled", $plainId);
check_eq("administrator disables an authenticated user", 0, intval(user_field($db, $plainId, "enabled")));
toggle($admin, "admin/user", "enabled", $plainId);

$deleteDenyGroupId = add_group($db, "No delete");
grant_group($db, $deleteDenyGroupId, "user.delete", 0);
add_to_group($db, $adminId, $deleteDenyGroupId);
$response = open_dialog($admin, "admin/user", "form-delete", "user__id", $nestedId);
$admin->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check("without user.delete a user is not deleted", $site->userByEmail("nested@example.test") !== false);
$db->prepare("DELETE FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = ? AND \"user_group__id\" = ?")->execute([$adminId, $deleteDenyGroupId]);

$response = open_dialog($admin, "admin/user", "form-delete", "user__id", $nestedId);
$admin->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check("administrator deletes an authenticated user", $site->userByEmail("nested@example.test") === false);

t_section("level groups and system permissions");

$response = open_dialog($owner, "admin/user-group", "form-delete", "user_group__id", $administratorGroupId);
$owner->submit("admin/user-group", $response->body, "dialog.form-delete_form", []);
check_eq("a level group can not be deleted", $administratorGroupId, group_id($db, "administrator"));

$response = open_dialog($owner, "admin/user-group", "form-edit", "user_group__id", $administratorGroupId);
$owner->submit("admin/user-group", $response->body, "dialog.form-edit_form", ["name" => "admins", "description" => "x"]);
check_eq("a level group can not be renamed", $administratorGroupId, group_id($db, "administrator"));

toggle($owner, "admin/user-group", "enabled", $rootGroupId);
check_eq("a level group can not be disabled", 1, intval($db->query("SELECT \"enabled\" FROM \"andora-web-user-group\" WHERE \"id\" = " . $rootGroupId)->fetchColumn()));

$systemAdminId = permission_id($db, "system.admin");
$response = open_dialog($owner, "admin/permission", "form-delete", "permission__id", $systemAdminId);
$owner->submit("admin/permission", $response->body, "dialog.form-delete_form", []);
check_eq("a system permission can not be deleted", $systemAdminId, permission_id($db, "system.admin"));

$response = open_dialog($owner, "admin/permission", "form-edit", "permission__id", $systemAdminId);
$owner->submit("admin/permission", $response->body, "dialog.form-edit_form", ["name" => "admin", "description" => "x"]);
check_eq("a system permission can not be renamed", $systemAdminId, permission_id($db, "system.admin"));

toggle($owner, "admin/permission", "enabled", $systemAdminId);
check_eq("a system permission can not be disabled", 1, intval($db->query("SELECT \"enabled\" FROM \"andora-web-permission\" WHERE \"id\" = " . $systemAdminId)->fetchColumn()));

t_section("root users");

// A root user always keeps root: you can not change your own groups, delete
// or disable yourself, and only root can change another root user.
$secondRootId = add_user($db, "Second root", "root2@example.test", $password);
add_to_group($db, $secondRootId, $rootGroupId);

$ownerRootLinkId = intval($db->query("SELECT \"id\" FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = " . $ownerId . " AND \"user_group__id\" = " . $rootGroupId)->fetchColumn());
$response = open_dialog($owner, "admin/user", "form-linked", "user__id", $ownerId);
$owner->submit("admin/user", $response->body, "dialog.form-linked_form", ["link__id" => $ownerRootLinkId, "action" => "remove"]);
check_eq("root can not remove root from themselves", "administrator,root,user", user_groups($db, $ownerId));

toggle($owner, "admin/user", "enabled", $secondRootId);
check_eq("root disables another root user", 0, intval(user_field($db, $secondRootId, "enabled")));
toggle($owner, "admin/user", "enabled", $secondRootId);
check_eq("root enables another root user", 1, intval(user_field($db, $secondRootId, "enabled")));

$linkId = intval($db->query("SELECT \"id\" FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = " . $secondRootId . " AND \"user_group__id\" = " . $rootGroupId)->fetchColumn());
$response = open_dialog($owner, "admin/user", "form-linked", "user__id", $secondRootId);
$owner->submit("admin/user", $response->body, "dialog.form-linked_form", ["link__id" => $linkId, "action" => "remove"]);
check_eq("root removes root from another root user", "", user_groups($db, $secondRootId));

check_eq("no router errors", "", $site->routerLog());
$site->stop();

t_done();
