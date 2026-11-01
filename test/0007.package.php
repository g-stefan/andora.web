<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Packages: the setup installs the packages found in _site/, the admin page
// lists them and runs install / upgrade / reset / enable / disable, a folder
// replaced by a newer version is an upgrade pending, onUserDelete, the
// andora/web version sync and the upgrade from schema 6.

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

function add_to_group($db, $userId, $groupId)
{
    $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, $groupId]);
}

// Write _site/<vendor>/<name>/ (the old folder is deleted first, like an update)
function write_package($site, $code, $manifest, $files = [])
{
    $path = $site->root . "/_site/" . $code;
    TestSite::removeDir($path);
    mkdir($path, 0777, true);
    file_put_contents($path . "/package.json", is_string($manifest) ? $manifest : json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach ($files as $name => $content) {
        file_put_contents($path . "/" . $name, $content);
    }
}

function package_row($db, $code)
{
    $statement = $db->prepare("SELECT * FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetch();
}

function column_names($db, $table)
{
    $names = [];
    foreach ($db->query("PRAGMA table_info(\"" . $table . "\")")->fetchAll() as $column) {
        $names[] = $column["name"];
    }
    return $names;
}

function table_exists($db, $name)
{
    $statement = $db->prepare("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" = ?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}

function open_operation($browser, $operation, $id)
{
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-" . $operation, "init" => "true", "package__id" => $id]);
}

function run_operation($browser, $operation, $id)
{
    $response = open_operation($browser, $operation, $id);
    return $browser->submit("admin/package", $response->body, "dialog.form-" . $operation . "_form", []);
}

function toggle_package($browser, $id)
{
    return $browser->postComponent("admin/package", "datatable.enabled=enabled_" . $id . "/enabled_" . $id, ["id" => $id]);
}

// The links of the admin sidebar
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

// acme/hello: a table, a permission, an admin menu entry, onUserDelete
$helloPackage = "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Hello\\Setup::class;\n";
$helloSetup = <<<'PHP'
<?php
namespace Acme\Hello;

defined("ANDORA") or die("Forbidden");

class Setup extends \Andora\Web\Package\Base
{
    protected $tables = array(TableNote::class);

    public function seed(&$reason)
    {
        return $this->registerPermission("acme-hello.manage", "Manage the hello notes", $reason)
            && $this->registerAdminMenu("acme-hello", "admin.sidebar", "admin.home", "Hello", "smile", "admin/hello", "acme-hello.manage", 0, 900, $reason);
    }

    public function upgrade($fromVersion, &$reason)
    {
        file_put_contents($this->dataPath() . "upgraded-from.txt", $fromVersion);
        return parent::upgrade($fromVersion, $reason);
    }

    public function onUserDelete($event)
    {
        $dsNote = $this->getDataSource(TableNote::class);
        $dsNote->clear();
        $dsNote->user__id = $event->get("userId");
        $dsNote->delete();
    }
}
PHP;
$helloTable = <<<'PHP'
<?php
namespace Acme\Hello;

defined("ANDORA") or die("Forbidden");

class TableNote extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $text;
    #FIELD#

    public static function descriptor($info)
    {
        $info->name = "acme-hello-note";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"),
            "text" => array("varchar", null, 255)#DESCRIPTOR#
        );
    }
}
PHP;
$helloManifest = [
    "vendor" => "acme",
    "name" => "hello",
    "version" => "1.0.0",
    "title" => "Hello",
    "description" => "Test package",
    "require" => ["andora/web" => ">=0.1", "xyo/web" => "*"],
    "event" => ["listen" => ["user.delete" => "onUserDelete"]],
    "datasource" => ["Acme\\Hello\\TableNote"]
];
$helloFiles = [
    "package.php" => $helloPackage,
    "setup.php" => $helloSetup,
    "table-note.php" => str_replace(["#FIELD#", "#DESCRIPTOR#"], ["", ""], $helloTable)
];

$failingPackage = "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Failing\\Setup::class;\n";
$failingSetup = "<?php\nnamespace Acme\\Failing;\ndefined(\"ANDORA\") or die(\"Forbidden\");\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n    public function install(&\$reason)\n    {\n        \$reason = \"custom-error\";\n        return false;\n    }\n}\n";

$site = (new TestSite())->start();
write_package($site, "acme/hello", $helloManifest, $helloFiles);
write_package($site, "acme/asset", ["vendor" => "acme", "name" => "asset", "version" => "2.0.0", "title" => "<script>alert(1)</script>"]);
write_package($site, "acme/needs", ["vendor" => "acme", "name" => "needs", "version" => "1.0.0", "require" => ["acme/later" => ">=1.0.0"]]);
write_package($site, "acme/failing", ["vendor" => "acme", "name" => "failing", "version" => "1.0.0"], ["package.php" => $failingPackage, "setup.php" => $failingSetup]);
write_package($site, "acme/broken", "{");
write_package($site, "acme/wrong", ["vendor" => "other", "name" => "wrong", "version" => "1.0.0"]);
// a folder without package.json
mkdir($site->root . "/_site/acme/plain");
file_put_contents($site->root . "/_site/acme/plain/x.txt", "x");

$coreVersion = json_decode(file_get_contents($site->root . "/_site/andora/web/package.json"), true)["version"];

t_section("setup installs the packages found");

$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$db = $site->db();

check_contains("schema version 1", "'schemaVersion' => 1", $site->config("config.06.schema.php"));
check_eq("andora/web recorded with the version of its files", $coreVersion, package_row($db, "andora/web")["version"] ?? null);
check_eq("acme/hello installed", "1.0.0", package_row($db, "acme/hello")["version"] ?? null);
check_eq("acme/hello enabled", 1, intval(package_row($db, "acme/hello")["enabled"] ?? 0));
check_eq("acme/asset (no package.php) installed", "2.0.0", package_row($db, "acme/asset")["version"] ?? null);
check_eq("acme/needs not installed (requirement missing)", false, package_row($db, "acme/needs"));
check_eq("acme/failing not installed (install failed)", false, package_row($db, "acme/failing"));
check_eq("acme/broken not installed", false, package_row($db, "acme/broken"));
check_eq("acme/wrong (vendor does not match) not installed", false, package_row($db, "acme/wrong"));
foreach (["xyo/web", "xyo/inter-font", "xyo/lucide-icons", "xyo/overlay-scrollbars"] as $code) {
    check_eq("framework package installed: " . $code, json_decode(file_get_contents($site->root . "/_site/" . $code . "/package.json"), true)["version"], package_row($db, $code)["version"] ?? null);
}
check("acme/hello table created", table_exists($db, "acme-hello-note"));
$permission = $db->query("SELECT * FROM \"andora-web-permission\" WHERE \"name\" = 'acme-hello.manage'")->fetch();
check_eq("permission of the package registered", "acme/hello", $permission["package"] ?? null);
check_eq("permission of the package is a system permission", 1, intval($permission["is_system"] ?? 0));
$menu = $db->query("SELECT * FROM \"andora-web-admin-menu\" WHERE \"code\" = 'acme-hello'")->fetch();
check_eq("admin menu entry of the package registered", "acme/hello", $menu["package"] ?? null);
check_eq("Andora menu entries have no package", "", "" . $db->query("SELECT \"package\" FROM \"andora-web-admin-menu\" WHERE \"code\" = 'admin.user'")->fetchColumn());
$log = "" . @file_get_contents($site->root . "/_log/" . date("Y-m-d") . ".package.log");
check_contains("package log: acme/needs skipped", "requires-package", $log);
check_contains("package log: acme/failing error", "custom-error", $log);
$db = null;

t_section("admin page");

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$page = $owner->get("admin/package");
check_eq("root: admin/package is 200", 200, $page->code);
check_contains("root: menu has the package page", "admin/package", menu_links($page->body));
check_contains("root: menu has the entry of acme/hello", "admin/hello", menu_links($page->body));
foreach (["andora/web", "acme/hello", "acme/needs", "acme/broken", "xyo/web"] as $code) {
    check("listed: " . $code, preg_match("/>\s*" . preg_quote($code, "/") . "\s*</", $page->body) == 1);
}
check_not_contains("titles are escaped", "<script>alert(1)</script>", $page->body);
check_contains("state: not installed", "Not installed", $page->body);
check_contains("state: broken", "Broken", $page->body);
check_contains("state: folder without manifest", "No manifest", $page->body);
check_contains("rows are identified by vendor_name", "name=\"selector[]\" value=\"acme_hello\"", $page->body);
check("composer folder is not listed", preg_match("/>\s*vendor\/phpmailer\s*</", $page->body) == 0);

$db = $site->db();
$administratorId = add_user($db, "Administrator", "administrator@example.test", $password);
add_to_group($db, $administratorId, group_id($db, "administrator"));
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("administrator: admin/package is 401 (root only)", 401, $administrator->get("admin/package")->code);
check_not_contains("administrator: no package page in the menu", "admin/package", menu_links($administrator->get("admin/dashboard")->body));

$response = open_operation($owner, "details", "acme_hello");
check_contains("details: title", "Hello", $response->body);
check_contains("details: description", "Test package", $response->body);
check_contains("details: requirements", "andora/web &gt;=0.1", $response->body);
check_not_contains("details: no submit button", "operation.details.submit", $response->body);
$response = open_operation($owner, "details", "acme_asset");
check_not_contains("details: title escaped", "<script>alert(1)</script>", $response->body);
$response = open_operation($owner, "details", "acme_broken");
check_contains("details: reason of a broken package", "package.json is not valid", $response->body);
$response = open_operation($owner, "details", "acme_wrong");
check_contains("details: vendor that does not match the folder", "does not match the folder", $response->body);
$response = open_operation($owner, "details", "../x");
check_contains("details: invalid id", "The package was not found.", $response->body);

t_section("install");

$response = run_operation($owner, "install", "acme_failing");
check_contains("install: the reason of the package is shown", "custom-error", $response->body);
$response = run_operation($owner, "install", "acme_needs");
check_contains("install: requirement missing", "requires-package", $response->body);
check_contains("install: the missing package is named", "acme\\/later", $response->body);
$response = $owner->component("admin/package", "dialog", ["component" => "message-error", "reason" => "requires-package", "detail" => "<b>x</b>"]);
check_contains("message-error: reason translated", "Requires the package", $response->body);
check_not_contains("message-error: detail escaped", "<b>x</b>", $response->body);

write_package($site, "acme/later", ["vendor" => "acme", "name" => "later", "version" => "1.0.0"]);
$response = run_operation($owner, "install", "acme_later");
check_contains("install: acme/later installed", "install-ok", $response->body);
$response = run_operation($owner, "install", "acme_needs");
check_contains("install: acme/needs installed once its requirement is", "install-ok", $response->body);
$db = $site->db();
check_eq("acme/needs recorded", "1.0.0", package_row($db, "acme/needs")["version"] ?? null);
$db = null;
$response = open_operation($owner, "install", "acme_hello");
check_contains("install: not available for an installed package", "Not available in this state.", $response->body);
$response = run_operation($owner, "install", "acme_hello");
check_contains("install: refused for an installed package", "invalid-state", $response->body);

t_section("enable / disable");

toggle_package($owner, "acme_later");
$db = $site->db();
check_eq("disable refused: acme/needs requires acme/later", 1, intval(package_row($db, "acme/later")["enabled"]));
$db = null;
toggle_package($owner, "acme_needs");
toggle_package($owner, "acme_later");
$db = $site->db();
check_eq("acme/needs disabled", 0, intval(package_row($db, "acme/needs")["enabled"]));
check_eq("acme/later disabled once nothing enabled requires it", 0, intval(package_row($db, "acme/later")["enabled"]));
$db = null;
$response = run_operation($owner, "install", "acme_needs");
check_contains("a disabled package can not be installed again", "invalid-state", $response->body);
toggle_package($owner, "acme_needs");
$db = $site->db();
check_eq("enable refused: acme/later is disabled", 0, intval(package_row($db, "acme/needs")["enabled"]));
$db = null;

toggle_package($owner, "andora_web");
$db = $site->db();
check_eq("andora/web can not be disabled", 1, intval(package_row($db, "andora/web")["enabled"]));
$db = null;
toggle_package($owner, "xyo_web");
$db = $site->db();
check_eq("xyo/web can not be disabled (andora/web requires it)", 1, intval(package_row($db, "xyo/web")["enabled"]));
$db = null;

toggle_package($owner, "acme_hello");
$db = $site->db();
check_eq("acme/hello disabled", 0, intval(package_row($db, "acme/hello")["enabled"]));
check_eq("its permission is disabled", 0, intval($db->query("SELECT \"enabled\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-hello.manage'")->fetchColumn()));
check_eq("its menu entry is disabled", 0, intval($db->query("SELECT \"enabled\" FROM \"andora-web-admin-menu\" WHERE \"code\" = 'acme-hello'")->fetchColumn()));
$db = null;
check_not_contains("menu without the entry of a disabled package", "admin/hello", menu_links($owner->get("admin/dashboard")->body));
check_contains("state: disabled", "Disabled", $owner->get("admin/package")->body);
toggle_package($owner, "acme_hello");
$db = $site->db();
check_eq("acme/hello enabled again", 1, intval(package_row($db, "acme/hello")["enabled"]));
check_eq("its permission is enabled again", 1, intval($db->query("SELECT \"enabled\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-hello.manage'")->fetchColumn()));
$db = null;
check_contains("menu has the entry again", "admin/hello", menu_links($owner->get("admin/dashboard")->body));

t_section("upgrade (folder replaced by a newer version)");

$response = open_operation($owner, "upgrade", "acme_asset");
check_contains("upgrade: not available when the files are the installed version", "Not available in this state.", $response->body);

$helloManifest["version"] = "1.1.0";
$helloFiles["table-note.php"] = str_replace(["#FIELD#", "#DESCRIPTOR#"], ["public \$title;", ",\n            \"title\" => array(\"varchar\", null, 128)"], $helloTable);
write_package($site, "acme/hello", $helloManifest, $helloFiles);
check_contains("state: upgrade pending", "Upgrade pending", $owner->get("admin/package")->body);
$response = run_operation($owner, "upgrade", "acme_hello");
check_contains("upgrade done", "upgrade-ok", $response->body);
$db = $site->db();
check_eq("new version recorded", "1.1.0", package_row($db, "acme/hello")["version"]);
check("the new column is added", in_array("title", column_names($db, "acme-hello-note"), true));
$db = null;
check_eq("upgrade gets the version installed before", "1.0.0", @file_get_contents($site->root . "/_repository/acme/hello/upgraded-from.txt"));

t_section("reset");

$db = $site->db();
$db->exec("INSERT INTO \"acme-hello-note\" (\"user__id\", \"text\") VALUES (0, 'note')");
$db = null;
$response = open_operation($owner, "reset", "acme_hello");
check_contains("reset: warning", "The data of the package is deleted.", $response->body);
$response = $owner->submit("admin/package", $response->body, "dialog.form-reset_form", []);
check_contains("reset done", "reset-ok", $response->body);
$db = $site->db();
check_eq("reset: the data is deleted", 0, intval($db->query("SELECT COUNT(*) FROM \"acme-hello-note\"")->fetchColumn()));
check_eq("reset: the permission is kept", "acme/hello", "" . $db->query("SELECT \"package\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-hello.manage'")->fetchColumn());
$db = null;
$response = run_operation($owner, "reset", "andora_web");
check_contains("reset: andora/web is protected", "invalid-state", $response->body);
$db = $site->db();
check("reset refused: the users are kept", $site->userByEmail("owner@example.test") !== false);
$db = null;

t_section("onUserDelete");

$db = $site->db();
$deletedId = add_user($db, "Deleted", "deleted@example.test", $password);
$db->prepare("INSERT INTO \"acme-hello-note\" (\"user__id\", \"text\") VALUES (?, 'mine')")->execute([$deletedId]);
$db->exec("INSERT INTO \"acme-hello-note\" (\"user__id\", \"text\") VALUES (0, 'other')");
$db = null;
$response = $owner->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $deletedId]);
$owner->submit("admin/user", $response->body, "dialog.form-delete_form", []);
$db = $site->db();
check_eq("user deleted", false, $site->userByEmail("deleted@example.test"));
check_eq("onUserDelete: the rows of the user are deleted", 0, intval($db->query("SELECT COUNT(*) FROM \"acme-hello-note\" WHERE \"user__id\" = " . $deletedId)->fetchColumn()));
check_eq("onUserDelete: the other rows are kept", 1, intval($db->query("SELECT COUNT(*) FROM \"acme-hello-note\"")->fetchColumn()));
$db = null;

t_section("downgrade, missing files");

$helloManifest["version"] = "1.0.5";
write_package($site, "acme/hello", $helloManifest, $helloFiles);
$page = $owner->get("admin/package");
check_contains("state: older files", "Older files", $page->body);
$response = run_operation($owner, "upgrade", "acme_hello");
check_contains("upgrade refused for older files", "invalid-state", $response->body);
$db = $site->db();
check_eq("version kept", "1.1.0", package_row($db, "acme/hello")["version"]);
$db = null;

TestSite::removeDir($site->root . "/_site/acme/asset");
check_contains("state: files missing", "Files missing", $owner->get("admin/package")->body);

t_section("andora/web version sync");

$db = $site->db();
$db->exec("UPDATE \"andora-web-package\" SET \"version\" = '0.0.1' WHERE \"code\" = 'andora/web'");
$db = null;
check_eq("dashboard is 200", 200, $owner->get("admin/dashboard")->code);
$db = $site->db();
check_eq("andora/web upgraded to the version of its files", $coreVersion, package_row($db, "andora/web")["version"]);
$db = null;

check_eq("no router errors", "", $site->routerLog());
$site->stop();

t_done();
