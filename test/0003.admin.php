<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Admin area: the users table (paging, page size, escaping), edit and delete
// dialogs of users and user groups.

require(__DIR__ . "/_lib/site.php");

$site = (new TestSite())->start();
$site->setup("Administrator", "admin@example.test", "Admin-Password-12");

$admin = $site->browser();
$admin->login("admin@example.test", "Admin-Password-12");

// 30 more users, one of them with HTML in the name
$db = $site->db();
$insert = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"password\", \"uuid\") VALUES (?, ?, 1, '', ?)");
for ($k = 1; $k <= 29; $k++) {
    $insert->execute([sprintf("User %02d", $k), sprintf("user%02d@example.test", $k), "uuid-" . $k]);
}
$insert->execute(["<script>alert(1)</script>", "html@example.test", "uuid-html"]);

function page_index($html)
{
    if (preg_match("/value=\"(\\d+)\"[^>]*name=\"pageIndex\"/", $html, $match)) {
        return intval($match[1]);
    }
    return null;
}

function pages_count($html)
{
    if (preg_match("/(\\d+)<\\/span>\\s*<span class=\"opacity-70\">pages/", $html, $match)) {
        return intval($match[1]);
    }
    return null;
}

function row_count($html)
{
    return substr_count($html, "name=\"selector[]\"");
}

t_section("users table");

$page = $admin->get("admin/user");
check_eq("admin/user is 200", 200, $page->code);
check_not_contains("names are escaped", "<script>alert(1)</script>", $page->body);
check_contains("escaped name is shown", "&lt;script&gt;alert(1)&lt;/script&gt;", $page->body);
check_eq("31 users, 25 per page: 2 pages", 2, pages_count($page->body));
check_contains("row checkboxes carry the id", "name=\"selector[]\" value=\"1\"", $page->body);

$fields = ["itemsCountPerPage" => "10", "pageIndex" => "1", "sortIndex" => "2", "sortType" => "1", "search" => ""];

$response = $admin->submit("admin/user", $page->body, "datatable_form", $fields);
check_eq("10 per page: 4 pages", 4, pages_count($response->body));
check_eq("10 rows on the first page", 10, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["nextPage" => "1"]));
check_eq("next page goes to page 2", 2, page_index($response->body));
check_eq("10 rows on page 2", 10, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["pageIndex" => "3", "previousPage" => "1"]));
check_eq("previous page from 3 goes to page 2", 2, page_index($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["lastPage" => "1"]));
check_eq("last page is page 4", 4, page_index($response->body));
check_eq("1 row on the last page", 1, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["pageIndex" => "99"]));
check_eq("a page past the end shows the last page", 4, page_index($response->body));
check_eq("rows of the last page", 1, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["itemsCountPerPage" => "0"]));
check_eq("page size 0 is ignored", 200, $response->code);
check_eq("default page size used", 25, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["itemsCountPerPage" => "*"]));
check_eq("all items on one page", 31, row_count($response->body));

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["search" => "\"><b>x</b>"]));
check_not_contains("search text is escaped", "\"><b>x</b>", $response->body);

$response = $admin->submit("admin/user", $page->body, "datatable_form", array_merge($fields, ["sortIndex" => "8", "sortType" => "1"]));
check_eq("sort by a non data column is ignored", 200, $response->code);

t_section("edit user");

$user01 = $site->userByEmail("user01@example.test");
$response = $admin->postComponent("admin/user", "dialog", ["component" => "form-edit", "init" => "true", "user__id" => $user01["id"]]);
check_contains("edit form opens", "dialog.form-edit_form", $response->body);
$admin->submit("admin/user", $response->body, "dialog.form-edit_form", [
    "name" => "User 01",
    "email" => "user02@example.test",
    "password" => "",
    "passwordConfirmation" => ""
]);
check_eq("e-mail of another account is refused", "user01@example.test", $site->userByEmail("user01@example.test")["email"]);

$response = $admin->postComponent("admin/user", "dialog", ["component" => "form-edit", "init" => "true", "user__id" => $user01["id"]]);
$admin->submit("admin/user", $response->body, "dialog.form-edit_form", [
    "name" => "User 01 Changed",
    "email" => "user01@example.test",
    "password" => "",
    "passwordConfirmation" => ""
]);
check_eq("name saved", "User 01 Changed", $site->userByEmail("user01@example.test")["name"]);

$response = $admin->component("admin/user", "dialog", ["component" => "message-error", "reason" => "<b>x</b>"]);
check_not_contains("message-error escapes the reason", "<b>x</b>", $response->body);
$response = $admin->component("admin/user", "dialog", ["component" => "message-ok", "message" => "<b>x</b>"]);
check_not_contains("message-ok does not echo the request", "<b>x</b>", $response->body);

$html = $site->userByEmail("html@example.test");
$response = $admin->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $html["id"]]);
check_not_contains("delete dialog escapes the name", "<script>alert(1)</script>", $response->body);

t_section("delete user and user group");

$user02 = $site->userByEmail("user02@example.test");
$db->exec("INSERT INTO \"andora-web-user-group\" (\"name\", \"description\", \"enabled\") VALUES ('Group A', '', 1)");
$groupId = intval($db->query("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = 'Group A'")->fetchColumn());
$db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$user02["id"], $groupId]);

$response = $admin->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $user02["id"]]);
$response = $admin->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check_eq("delete user answers 200", 200, $response->code);
check("user deleted", $site->userByEmail("user02@example.test") === false);
check_eq("group links of the user deleted", 0, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-x-user-group\" WHERE \"user__id\" = " . intval($user02["id"]))->fetchColumn()));

$response = $admin->postComponent("admin/user-group", "dialog", ["component" => "form-delete", "init" => "true", "user_group__id" => $groupId]);
$response = $admin->submit("admin/user-group", $response->body, "dialog.form-delete_form", []);
check_eq("delete user group answers 200", 200, $response->code);
check_eq("user group deleted", 0, intval($db->query("SELECT COUNT(*) FROM \"andora-web-user-group\" WHERE \"id\" = " . $groupId)->fetchColumn()));

check_eq("no router errors", "", $site->routerLog());

$site->stop();
t_done();
