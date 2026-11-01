<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The system check: the first step of the setup (requirements, Begin only
// when they are met, refused by the page too) and admin/system (the server,
// the database, the logs; system.settings).

require(__DIR__ . "/_lib/site.php");

$site = (new TestSite())->start();
$browser = $site->browser();

t_section("setup: the system check");

$page = $browser->get("setup");
check_eq("GET /setup is 200", 200, $page->code);
check_contains("the system check is shown", "id=\"container.select-language.system-check\"", $page->body);
check_contains("the server meets the requirements", "data-ready=\"1\"", $page->body);
check_contains("PHP version", "whitespace-nowrap\">" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".", $page->body);
check_contains("required extension mbstring is OK", "data-name=\"mbstring\" data-status=\"ok\"", $page->body);
check_contains("database driver pdo_sqlite is OK", "data-name=\"pdo_sqlite\" data-status=\"ok\"", $page->body);
check_contains("the write permission of _repository/", "data-name=\"_repository/\"", $page->body);
check_contains("memory_limit", "data-name=\"memory_limit\"", $page->body);
check_contains("the private folders are checked by the browser", "_repository/index.html", $page->body);
check_not_contains("Begin is enabled", "\"Check again\"", $page->body);

// The language can be changed, the check is translated
$response = $browser->submit("setup", $page->body, "container.select-language_form", ["language" => "ro-RO", "action" => "select"]);
check_contains("the check in Romanian", "Verificare sistem", $response->body);

// A required folder missing: not ready, Begin disabled, the step refused
rename($site->root . "/_repository", $site->root . "/_repository.moved");
$page = $browser->get("setup");
check_contains("not ready without _repository/", "data-ready=\"0\"", $page->body);
check_contains("Check again is shown", "Check again", $page->body);
check_contains("Begin is disabled", "\" disabled>", $page->body);
$response = $browser->submit("setup", $page->body, "container.select-language_form", ["language" => "en-US", "action" => "submit"]);
check_contains("the language step is refused", "system-requirements-not-met", $response->body);
check("config.01.language.php not written", is_null($site->config("config.01.language.php")));
rename($site->root . "/_repository.moved", $site->root . "/_repository");

$page = $browser->get("setup");
check_contains("ready again", "data-ready=\"1\"", $page->body);
check_eq("no router errors", "", $site->routerLog());

t_section("setup: the database types with a driver");

$response = $site->setup("Administrator", "admin@example.test", "Admin-Password-12");
check_contains("setup done", "[\"message\",\"thank-you\"]", $response->body);

t_section("admin/system");

check_eq("not logged in: redirect to login", 302, $browser->get("admin/system")->code);

$admin = $site->browser();
$admin->login("admin@example.test", "Admin-Password-12");
$page = $admin->get("admin/system");
check_eq("GET /admin/system is 200", 200, $page->code);
check_contains("in the administration popup", "href=\"/admin/system\"", $page->body);
check_contains("the summary", "data-ready=\"1\"", $page->body);
check_contains("PHP and extensions", "id=\"system-check_card-php\"", $page->body);
check_contains("PHP settings", "id=\"system-check_card-limit\"", $page->body);
check_contains("upload_max_filesize", "data-name=\"upload_max_filesize\"", $page->body);
check_contains("post_max_size", "data-name=\"post_max_size\"", $page->body);
check_contains("write permission", "id=\"system-check_card-folder\"", $page->body);
check_contains("server", "id=\"system-check_card-server\"", $page->body);
check_contains("the PHP SAPI", "data-name=\"PHP SAPI\"", $page->body);
check_contains("siteURL is the address of the site", "data-name=\"siteURL\"", $page->body);
check_contains("database", "id=\"system-check_card-database\"", $page->body);
check_contains("database type", "whitespace-nowrap\">sqlite</td>", $page->body);
check_contains("database connection", "whitespace-nowrap\">OK</td>", $page->body);
check_contains("logs", "id=\"system-check_card-log\"", $page->body);

// The end of the router log, escaped
$logFile = $site->root . "/_log/" . date("Y-m-d") . ".router.log";
file_put_contents($logFile, "<b>test error</b>\r\n", FILE_APPEND);
$page = $admin->get("admin/system");
check_contains("the last router errors", "id=\"system-check_router-log\"", $page->body);
check_contains("the log is escaped", "&lt;b&gt;test error&lt;/b&gt;", $page->body);
unlink($logFile);

t_done();
