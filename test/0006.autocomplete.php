<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// InputAutocomplete in the Groups popup of a user: up to 49 groups the
// list is searched in the browser, over 49 the search is an AJAX request
// to the component, answered with one page (7) of matches.

require(__DIR__ . "/_lib/site.php");

function group_id($db, $name)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $statement->execute([$name]);
    return intval($statement->fetchColumn());
}

function add_groups($db, $prefix, $count)
{
    $insert = $db->prepare("INSERT INTO \"andora-web-user-group\" (\"name\", \"description\", \"enabled\", \"level\", \"is_system\") VALUES (?, '', 1, 1, 0)");
    for ($k = 1; $k <= $count; $k++) {
        $insert->execute([sprintf("%s %02d", $prefix, $k)]);
    }
}

function open_groups($browser, $userId)
{
    return $browser->postComponent("admin/user", "dialog", ["component" => "form-linked", "init" => "true", "user__id" => $userId]);
}

// An item of the embedded list: ["<id>","<name>"]
function offered($id)
{
    return "[\"" . $id . "\",";
}

// Request id of the search: XYO.Web.Component.AJAX.post("<request id ending in /search>", ...
function search_request_id($html)
{
    if (preg_match("/XYO\\.Web\\.Component\\.AJAX\\.post\\((\"[^\"]*\\/search\")/", $html, $match)) {
        return json_decode($match[1]);
    }
    return null;
}

// The data the answer hands to the input: ...receive({...});
function search($browser, $requestId, $query, $page = 0, $sequence = 1)
{
    $response = $browser->postComponent("admin/user", $requestId, [
        "autocomplete_q" => $query,
        "autocomplete_page" => $page,
        "autocomplete_sequence" => $sequence
    ]);
    if (preg_match("/\\.receive\\((\\{.*\\})\\);\\}/s", $response->body, $match)) {
        return json_decode($match[1], true);
    }
    return null;
}

function names($result)
{
    return implode(",", array_map(function ($item) {
        return $item[1];
    }, $result["items"]));
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$db = $site->db();

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$owner->get("admin/user");

$db->exec("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"password\", \"uuid\") VALUES ('plain', 'plain@example.test', 1, '', 'uuid-plain')");
$plainId = intval($site->userByEmail("plain@example.test")["id"]);

// root, administrator, user and 40 more: 43 groups to offer
add_groups($db, "Team", 40);

t_section("up to 49 items: searched in the browser");

$response = open_groups($owner, $plainId);
check_eq("popup is 200", 200, $response->code);
check_contains("the list is embedded", offered(group_id($db, "Team 40")), $response->body);
check_eq("no search request", null, search_request_id($response->body));

t_section("over 49 items: searched on the server");

add_groups($db, "Sales team", 10);

$response = open_groups($owner, $plainId);
check_not_contains("the list is not embedded", offered(group_id($db, "Team 40")), $response->body);
$requestId = search_request_id($response->body);
check("search request found", !is_null($requestId));
check_contains("the request goes to the user popup", "form-linked." . $plainId . "/search", (string) $requestId);

$result = search($owner, $requestId, "team", 0, 7);
check("answer found", is_array($result));
check_eq("the sequence is sent back", 7, $result["sequence"]);
check_eq("50 matches", 50, $result["total"]);
check_eq("first page", 0, $result["page"]);
check_eq("7 per page, names starting with the text first", "Team 01,Team 02,Team 03,Team 04,Team 05,Team 06,Team 07", names($result));
check_eq("the id goes with the name", "" . group_id($db, "Team 01"), $result["items"][0][0]);

$result = search($owner, $requestId, "team", 99);
check_eq("a page past the end is the last page", 7, $result["page"]);
check_eq("then the names containing the text", "Sales team 10", names($result));

$result = search($owner, $requestId, "SALES", 1);
check_eq("case-insensitive, second page", "Sales team 08,Sales team 09,Sales team 10", names($result));

$result = search($owner, $requestId, "te");
check_eq("under 3 characters: nothing", 0, $result["total"]);

$result = search($owner, $requestId, "authenticated");
check_eq("implicit groups are not offered", 0, $result["total"]);

$result = search($owner, $requestId, "<b>&");
check_eq("no match for markup", 0, $result["total"]);

$db->exec("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (" . $plainId . ", " . group_id($db, "Team 05") . ", 1)");
$result = search($owner, $requestId, "team 0");
check_eq("linked groups are not offered", "Team 01,Team 02,Team 03,Team 04,Team 06,Team 07,Team 08", names($result));

$response = open_groups($owner, $plainId);
$response = $owner->submit("admin/user", $response->body, "dialog.form-linked_form", ["action" => "add", "group__id" => group_id($db, "Sales team 03")]);
check_contains("adding still works", "Sales team 03", $response->body);

t_section("the search needs the admin area");

$guest = $site->browser();
$guest->get("user/login");
$response = $guest->postComponent("admin/user", $requestId, ["autocomplete_q" => "team"]);
check_not_contains("no answer without login", ".receive(", $response->body);

check_eq("no router errors", "", $site->routerLog());

t_done();
