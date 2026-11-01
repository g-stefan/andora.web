<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Setup wizard: pages before setup, the wizard itself, the lock after setup,
// configuration files written without code injection.

require(__DIR__ . "/_lib/site.php");

$site = (new TestSite())->start();
$browser = $site->browser();

t_section("pages before setup");

check_eq("GET /setup is 200", 200, $browser->get("setup")->code);
foreach (["", "user/login", "user/sign-up", "user/forgot-password", "user/password-reset", "user/email-confirmation", "user/logout", "admin", "admin/user", "admin/settings"] as $path) {
    $response = $browser->get($path);
    check_eq("GET /" . $path . " before setup redirects", 302, $response->code);
    check_eq("GET /" . $path . " redirects to the setup page", "/setup", $response->header("Location"));
}
$response = $browser->component("user/login", "form", ["component" => "form"]);
check_eq("AJAX before setup is 401", 401, $response->code);
check_eq("API before setup is 401", 401, $browser->get("user/avatar", ["id" => 1])->code);
check_eq("no router errors", "", $site->routerLog());

t_section("setup wizard");

$password = "Admin-Password-12";
$response = $site->setup("Administrator", "admin@example.test", $password);
check_eq("register administrator is 200", 200, $response->code);
check_contains("setup asks for the thank you message", "[\"message\",\"thank-you\"]", $response->body);
$response = $browser->component("setup", "container", ["component" => "message-ok", "message" => "thank-you"]);
check_contains("thank you message", "Thank you!", $response->body);
check_contains("thank you message redirects to the login page", "user/login\";},3000);", $response->body);

check("config.01.language.php written", !is_null($site->config("config.01.language.php")));
check("config.02.datasource.php written", !is_null($site->config("config.02.datasource.php")));
check("config.03.user.php written", !is_null($site->config("config.03.user.php")));
check_contains("config.04.configured.php marks the setup as done", "'configured' => true", $site->config("config.04.configured.php"));
check_contains("config.04.configured.php has siteURL", "'siteURL' => 'http://127.0.0.1:" . $site->port . "/'", $site->config("config.04.configured.php"));

$admin = $site->userByEmail("admin@example.test");
check("administrator account created", $admin !== false);
check_eq("administrator e-mail is confirmed", 1, intval($admin["is_confirmed"]));
check("password is stored hashed", strncmp($admin["password"], "hash:", 5) == 0);

check_contains("config.06.schema.php has the schema version", "'schemaVersion' => 1", $site->config("config.06.schema.php"));

$groups = $site->db()->query("SELECT \"name\", \"level\" FROM \"andora-web-user-group\" WHERE \"is_system\" = 1 ORDER BY \"level\", \"id\"")->fetchAll();
check_eq("the system groups are created", "public:0,authenticated:1,user:1,administrator:2,root:3", implode(",", array_map(function ($row) {
    return $row["name"] . ":" . $row["level"];
}, $groups)));

$tables = $site->db()->query("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" NOT LIKE 'sqlite\_%' ESCAPE '\' ORDER BY \"name\"")->fetchAll(PDO::FETCH_COLUMN);
check_eq("the tables of andora/web start with andora-web-", "", implode(",", array_filter($tables, function ($name) {
    return strncmp($name, "andora-web-", 11) != 0;
})));
check("the user table", in_array("andora-web-user", $tables, true));

$permission = $site->db()->query("SELECT * FROM \"andora-web-permission\" WHERE \"name\" = 'system.admin'")->fetch();
check("permission system.admin created", $permission !== false);
check_eq("system.admin is a system permission", 1, intval($permission["is_system"]));
check_eq("permission admin is not created", false, $site->db()->query("SELECT * FROM \"andora-web-permission\" WHERE \"name\" = 'admin'")->fetch());

$statement = $site->db()->prepare("SELECT \"g\".\"name\" FROM \"andora-web-user-x-user-group\" AS \"l\" JOIN \"andora-web-user-group\" AS \"g\" ON \"g\".\"id\" = \"l\".\"user_group__id\" WHERE \"l\".\"user__id\" = ? AND \"l\".\"enabled\" = 1 ORDER BY \"g\".\"name\"");
$statement->execute([$admin["id"]]);
check_eq("the administrator account is in administrator, root and user", "administrator,root,user", implode(",", $statement->fetchAll(PDO::FETCH_COLUMN)));

$count = $site->db()->query("SELECT COUNT(*) FROM \"andora-web-admin-menu\" WHERE \"enabled\" = 1")->fetchColumn();
check_eq("admin menu entries registered", 11, intval($count));

t_section("private files are not served");

check_eq("database file", 403, $browser->get("_repository/andora.sqlite")->code);
check_eq("configuration file", 403, $browser->get("config.02.datasource.php")->code);
check_eq("log folder", 403, $browser->get("_log/index.php")->code);

t_section("setup is locked after install");

$page = $browser->get("setup");
check_contains("GET /setup says already configured", "Already configured", $page->body);

// Try to run the setup steps again, directly by AJAX
$before = $site->config("config.02.datasource.php");
$response = $browser->postComponent("setup", "container.select-database/select-database", [
    "component" => "select-database",
    "databaseType" => "sqlite",
    "action" => "submit"
]);
check_not_contains("select-database is not available", "container.select-database_form", $response->body);
$response = $browser->postComponent("setup", "container.register-user/register-user", [
    "component" => "register-user",
    "name" => "Intruder",
    "email" => "intruder@example.test",
    "password" => $password,
    "passwordConfirmation" => $password
]);
check("no new user from the locked setup", $site->userByEmail("intruder@example.test") === false);
check_eq("database configuration unchanged", $before, $site->config("config.02.datasource.php"));
$response = $browser->postComponent("setup", "container.select-language/select-language", [
    "component" => "select-language",
    "language" => "en-US\"); echo(\"injected",
    "action" => "submit"
]);
check_not_contains("language configuration unchanged", "injected", $site->config("config.01.language.php"));

$response = $browser->component("setup", "container", ["component" => "message-error", "reason" => "<b>x</b>"]);
check_not_contains("message-error is not available after setup", "<b>x</b>", $response->body);
$response = $browser->component("setup", "container", ["component" => "message-ok", "message" => "<b>x</b>"]);
check_not_contains("message-ok does not echo the request", "<b>x</b>", $response->body);

check_eq("no router errors", "", $site->routerLog());

t_section("default public page");

$page = $browser->get("");
check_eq("GET / after setup is 200", 200, $page->code);
check_contains("GET / shows the Andora logo", "class=\"logo-andora logo-andora-contain\"", $page->body);
check_contains("GET / uses the Andora styles", "_site/andora/web/client/andora.css", $page->body);
check_contains("GET / links to the login page", "user/login\"", $page->body);
check_not_contains("GET / is not the xyo.web default page", "Hello, World!", $page->body);
$page = $browser->get("not-a-page");
check_eq("GET of an unknown page is 404", 404, $page->code);
check_contains("the 404 page uses the Andora styles", "_site/andora/web/client/andora.css", $page->body);
check_eq("no router errors", "", $site->routerLog());

t_section("configuration values are exported, not interpolated");

// A second site, stopped at the database step with a value that would break out of a PHP string
$site2 = (new TestSite())->start();
$browser2 = $site2->browser();
$page = $browser2->get("setup");
$browser2->submit("setup", $page->body, "container.select-language_form", ["language" => "en-US", "action" => "submit"]);
$form = $browser2->component("setup", "container", ["component" => "select-database", "databaseType" => "mysql"]);
$form = $browser2->submit("setup", $form->body, "container.select-database_form", ["databaseType" => "mysql", "action" => "select"]);
$evil = "pa\"ss\$word'); echo('injected";
$browser2->submit("setup", $form->body, "container.select-database_form", [
    "databaseType" => "mysql",
    "databaseName" => "andora",
    "username" => "andora",
    "password" => $evil,
    "databaseServer" => "127.0.0.1",
    "databasePort" => "1",
    "tablePrefix" => "",
    "action" => "submit"
]);
$file = $site2->root . "/config.02.datasource.php";
check("config.02.datasource.php written", is_file($file));
if (is_file($file)) {
    define("XYO_WEB", 1);
    ob_start();
    $config = include($file);
    $out = ob_get_clean();
    check_eq("nothing is executed from the configuration", "", $out);
    check_eq("password is stored as typed", $evil, $config["dataSource"]["connection"]["db"]["password"]);
}
$site2->stop();

$site->stop();
t_done();
