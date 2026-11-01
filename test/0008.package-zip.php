<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Package zip files: the zip reader / writer, upload (checks, stage, commit,
// migration in the next request), backups and restore, export, uninstall,
// package.upload = false, the lock and the command line (cli.php).

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

function add_to_group($db, $userId, $groupName)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $statement->execute([$groupName]);
    $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, intval($statement->fetchColumn())]);
}

function package_row($db, $code)
{
    $statement = $db->prepare("SELECT * FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetch();
}

function table_exists($db, $name)
{
    $statement = $db->prepare("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" = ?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}

function column_names($db, $table)
{
    $names = [];
    foreach ($db->query("PRAGMA table_info(\"" . $table . "\")")->fetchAll() as $column) {
        $names[] = $column["name"];
    }
    return $names;
}

function scalar($db, $sql)
{
    return $db->query($sql)->fetchColumn();
}

function folder_count($path)
{
    $list = glob($path . "/*", GLOB_ONLYDIR);
    return is_array($list) ? count($list) : 0;
}

// A zip with the files $files (name in the zip => content), written with the
// own writer (\Andora\Web\Package\Zip, ZipArchive off)
function make_zip($site, $name, $files)
{
    $source = $site->root . "/_log/zip-source-" . bin2hex(random_bytes(4));
    mkdir($source);
    $list = [];
    $k = 0;
    foreach ($files as $entry => $content) {
        $k++;
        file_put_contents($source . "/" . $k, $content);
        $list[$entry] = $source . "/" . $k;
    }
    $file = $site->root . "/_log/" . $name;
    $reason = "";
    check("zip written: " . $name, \Andora\Web\Package\Zip::write($file, $list, $reason));
    TestSite::removeDir($source);
    return $file;
}

// The entry $index of a zip becomes a symbolic link (unix mode 0120777)
function make_link_entry($file, $index)
{
    $data = file_get_contents($file);
    $offset = -1;
    for ($k = 0; $k <= $index; $k++) {
        $offset = strpos($data, "PK\x01\x02", $offset + 1);
    }
    $data = substr($data, 0, $offset + 38) . pack("V", 0120777 << 16) . substr($data, $offset + 42);
    file_put_contents($file, $data);
}

// The files of acme/notes <version>: a table (a new column from 1.1.0), a
// permission, a menu entry; with $failUpgrade the upgrade hook fails
function notes_files($version, $failUpgrade = false)
{
    $field = version_compare($version, "1.1.0") >= 0 ? "public \$title;" : "";
    $descriptor = version_compare($version, "1.1.0") >= 0 ? ",\n            \"title\" => array(\"varchar\", null, 128)" : "";
    $upgrade = $failUpgrade ? "\n    public function upgrade(\$fromVersion, &\$reason)\n    {\n        \$reason = \"custom-upgrade-error\";\n        return false;\n    }\n" : "";
    return [
        "_site/acme/notes/package.json" => json_encode(["vendor" => "acme", "name" => "notes", "version" => $version, "title" => "Notes", "description" => "Notes of the users", "require" => ["andora/web" => ">=0.1"], "datasource" => ["Acme\\Notes\\DataSource\\TableNote"]], JSON_UNESCAPED_SLASHES),
        "_site/acme/notes/package.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Notes\\Package\\Setup::class;\n",
        "_site/acme/notes/index.html" => "<!DOCTYPE html><html><body></body></html>",
        "_site/acme/notes/package/setup.php" => "<?php\nnamespace Acme\\Notes\\Package;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n    protected \$tables = array(\\Acme\\Notes\\DataSource\\TableNote::class);\n\n    public function seed(&\$reason)\n    {\n        return \$this->registerPermission(\"acme-notes.manage\", \"Manage the notes\", \$reason)\n            && \$this->registerAdminMenu(\"acme-notes\", \"admin.sidebar\", \"admin.home\", \"Notes\", \"notebook\", \"admin/notes\", \"acme-notes.manage\", 0, 900, \$reason);\n    }\n" . $upgrade . "}\n",
        "_site/acme/notes/data-source/table-note.php" => "<?php\nnamespace Acme\\Notes\\DataSource;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass TableNote extends \\XYO\\Web\\DataSource\\Table\n{\n    public \$id;\n    public \$text;\n    " . $field . "\n\n    public static function descriptor(\$info)\n    {\n        \$info->name = \"acme-notes-note\";\n        \$info->primaryKey = \"id\";\n        \$info->fields = array(\n            \"id\" => array(\"bigint\", \"DEFAULT\", \"unsigned\", \"autoIncrement\"),\n            \"text\" => array(\"varchar\", null, 255)" . $descriptor . "\n        );\n    }\n}\n"
    ];
}

function upload_form($browser)
{
    return $browser->component("admin/package", "dialog", ["component" => "form-upload"]);
}

// Upload: the response of the upload form (stage id or error)
function upload($browser, $zip)
{
    return $browser->submitFile("admin/package", upload_form($browser)->body, "dialog.form-upload_form", [], "package", $zip);
}

function stage_id($response)
{
    return preg_match("/\"stage__id\",\"([0-9a-f]{32})\"/", $response->body, $match) ? $match[1] : null;
}

function open_confirm($browser, $stageId)
{
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-confirm", "init" => "true", "stage__id" => $stageId]);
}

// Upload, confirm, then the migration request the commit asks for.
// Returns [confirm form, answer of the commit, answer of the migration].
function deploy($browser, $zip)
{
    $stageId = stage_id(upload($browser, $zip));
    check("upload accepted: " . basename($zip), !is_null($stageId));
    $confirm = open_confirm($browser, $stageId);
    // the fixtures are not signed: "I trust this package"
    $commit = $browser->submit("admin/package", $confirm->body, "dialog.form-confirm_form", ["trust" => "1"]);
    $migrate = new TestResponse();
    if (preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $commit->body, $match)) {
        $migrate = $browser->postComponent("admin/package", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
    }
    return [$confirm, $commit, $migrate];
}

function open_operation($browser, $operation, $id)
{
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-" . $operation, "init" => "true", "package__id" => $id]);
}

function run_operation($browser, $operation, $id, $fields = [])
{
    $response = open_operation($browser, $operation, $id);
    return $browser->submit("admin/package", $response->body, "dialog.form-" . $operation . "_form", $fields);
}

// The migration request a restore asks for
function follow_migrate($browser, $response)
{
    if (preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $response->body, $match)) {
        return $browser->postComponent("admin/package", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
    }
    return new TestResponse();
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

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
\Andora\Web\Package\Zip::$useZipArchive = false;
$staging = $site->root . "/_repository/package/staging";
$backup = $site->root . "/_repository/package/backup/acme.notes";

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");

t_section("zip reader / writer");

$zip = make_zip($site, "roundtrip.zip", ["_site/a/b/x.txt" => str_repeat("text ", 1000), "_site/a/b/empty.txt" => "", "_site/a/b/bin" => random_bytes(3000)]);
$reason = "";
$reader = \Andora\Web\Package\Zip::open($zip, $reason);
check("zip opened", !is_null($reader));
$entries = $reader->entries();
check_eq("3 entries", 3, count($entries));
check_eq("deflate for text", 8, $entries[0]["method"]);
check_eq("content read back", str_repeat("text ", 1000), $reader->read(0));
check_eq("empty file read back", "", $reader->read(1));
$reader->close();
$data = file_get_contents($zip);
// a byte of the compressed data of the first entry (after the 30 byte header and its name)
$position = 30 + strlen("_site/a/b/x.txt") + 5;
file_put_contents($zip, substr($data, 0, $position) . chr(ord($data[$position]) ^ 0xFF) . substr($data, $position + 1));
$reader = \Andora\Web\Package\Zip::open($zip, $reason);
check_eq("corrupted entry: refused (CRC)", null, $reader->read(0));
$reader->close();
file_put_contents($site->root . "/_log/not.zip", "not a zip file");
check_eq("not a zip", null, \Andora\Web\Package\Zip::open($site->root . "/_log/not.zip", $reason));
check_eq("not a zip: reason", "invalid-zip", $reason);

t_section("upload: refused zips");

$page = $owner->get("admin/package");
check_contains("upload button", "form-upload", $page->body);
check_contains("upload form has a file input", "type=\"file\" name=\"package\"", upload_form($owner)->body);

$refused = [
    "not a zip" => [$site->root . "/_log/not.zip", "invalid-zip"],
    "parent folder" => [make_zip($site, "r1.zip", ["../evil.php" => "x"]), "invalid-entry"],
    "parent folder inside" => [make_zip($site, "r2.zip", ["_site/acme/x/package.json" => "{}", "_site/acme/x/../../../evil.php" => "x"]), "invalid-entry"],
    "absolute path" => [make_zip($site, "r3.zip", ["/_site/acme/x/a.php" => "x"]), "invalid-entry"],
    "backslash" => [make_zip($site, "r4.zip", ["_site\\acme\\x\\a.php" => "x"]), "invalid-entry"],
    "drive" => [make_zip($site, "r5.zip", ["C:/_site/acme/x/a.php" => "x"]), "invalid-entry"],
    "file outside _site" => [make_zip($site, "r6.zip", ["_site/acme/x/package.json" => "{}", "index.php" => "x"]), "invalid-entry"],
    "file in the vendor folder" => [make_zip($site, "r7.zip", ["_site/acme/a.php" => "x"]), "invalid-entry"],
    ".htaccess" => [make_zip($site, "r8.zip", ["_site/acme/x/package.json" => "{}", "_site/acme/x/.htaccess" => "x"]), "invalid-entry"],
    ".user.ini" => [make_zip($site, "r9.zip", ["_site/acme/x/sub/.USER.INI" => "x"]), "invalid-entry"],
    "composer vendor" => [make_zip($site, "r10.zip", ["_site/vendor/x/package.json" => "{}"]), "invalid-entry"],
    "invalid name" => [make_zip($site, "r11.zip", ["_site/Acme/x/package.json" => "{}"]), "invalid-entry"],
    "two packages" => [make_zip($site, "r12.zip", ["_site/acme/x/a.txt" => "x", "_site/acme/y/a.txt" => "x"]), "several-packages"],
    "two vendors" => [make_zip($site, "r13.zip", ["_site/acme/x/a.txt" => "x", "_site/other/x/a.txt" => "x"]), "several-packages"],
    "no files" => [make_zip($site, "r14.zip", []), "empty-package"],
    "no package.json" => [make_zip($site, "r15.zip", ["_site/acme/x/a.txt" => "x"]), "no-manifest"],
    "vendor does not match" => [make_zip($site, "r16.zip", ["_site/acme/x/package.json" => json_encode(["vendor" => "other", "name" => "x", "version" => "1.0.0"])]), "invalid-vendor"],
    "bad version" => [make_zip($site, "r17.zip", ["_site/acme/x/package.json" => json_encode(["vendor" => "acme", "name" => "x", "version" => "1.0"])]), "invalid-version"]
];
$link = make_zip($site, "r18.zip", ["_site/acme/x/package.json" => json_encode(["vendor" => "acme", "name" => "x", "version" => "1.0.0"]), "_site/acme/x/link" => "/etc/passwd"]);
make_link_entry($link, 1);
$refused["symbolic link"] = [$link, "invalid-entry"];
foreach ($refused as $label => $item) {
    $response = upload($owner, $item[0]);
    check_contains("refused, " . $label . ": " . $item[1], "\"" . $item[1] . "\"", $response->body);
    check_eq("refused, " . $label . ": no stage", null, stage_id($response));
}
check_eq("nothing left in staging", 0, folder_count($staging));
check("refused files were not written", !is_dir($site->root . "/_site/acme"));
check("no file outside _site", !is_file($site->root . "/evil.php") && !is_file($site->root . "/index.php.x"));
$response = $owner->postComponent("admin/package", "dialog", ["component" => "form-upload", "x" => "1"]);
check_contains("upload without a file", "upload-error", $response->body);
$response = open_confirm($owner, str_repeat("0", 32));
check_contains("confirm: unknown stage", "The upload was not found", $response->body);
$response = open_confirm($owner, "../../x");
check_contains("confirm: invalid stage id", "The upload was not found", $response->body);

t_section("upload: install");

list($confirm, $commit, $migrate) = deploy($owner, make_zip($site, "acme.notes.v1.0.0.zip", notes_files("1.0.0")));
check_contains("confirm: title", "Notes", $confirm->body);
check_contains("confirm: what it does", "The package will be installed.", $confirm->body);
check_contains("commit asks for the migration in a new request", "form-migrate", $commit->body);
check_contains("migration: installed", "install-ok", $migrate->body);
$db = $site->db();
check_eq("acme/notes 1.0.0 recorded", "1.0.0", package_row($db, "acme/notes")["version"] ?? null);
check("its table is created", table_exists($db, "acme-notes-note"));
check_eq("its permission is registered", "acme/notes", "" . scalar($db, "SELECT \"package\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-notes.manage'"));
$db = null;
check("files in _site/acme/notes", is_file($site->root . "/_site/acme/notes/package/setup.php"));
check("vendor folder has an index.html", is_file($site->root . "/_site/acme/index.html"));
check_eq("staging is empty", 0, folder_count($staging));
check_eq("no backup for a new package", 0, folder_count($backup));

t_section("upload: upgrade");

$db = $site->db();
$db->exec("INSERT INTO \"acme-notes-note\" (\"text\") VALUES ('kept')");
$db = null;
list($confirm, $commit, $migrate) = deploy($owner, make_zip($site, "acme.notes.v1.1.0.zip", notes_files("1.1.0")));
check_contains("confirm: upgrade", "The files will be replaced and the package upgraded.", $confirm->body);
check_contains("confirm: installed version", "1.0.0", $confirm->body);
check_contains("migration: upgraded", "upgrade-ok", $migrate->body);
$db = $site->db();
check_eq("acme/notes 1.1.0 recorded", "1.1.0", package_row($db, "acme/notes")["version"]);
check("new column added", in_array("title", column_names($db, "acme-notes-note"), true));
check_eq("data kept", "kept", "" . scalar($db, "SELECT \"text\" FROM \"acme-notes-note\""));
$db = null;
check_eq("backup of the old folder", 1, folder_count($backup));
check("the backup is version 1.0.0", count(glob($backup . "/*-1.0.0", GLOB_ONLYDIR)) == 1);
check_eq("database copied before the upgrade", 1, count(glob($backup . "/database-*-1.0.0.sqlite")));

t_section("upload: downgrade refused, same version replaced");

$stageId = stage_id(upload($owner, $site->root . "/_log/acme.notes.v1.0.0.zip"));
$confirm = open_confirm($owner, $stageId);
check_contains("confirm: downgrade refused", "Refused: older than the installed version", $confirm->body);
check_not_contains("confirm: no submit for a downgrade", "action.downgrade.submit", $confirm->body);
$response = $owner->submit("admin/package", $confirm->body, "dialog.form-confirm_form", []);
check_contains("commit of a downgrade refused", "\"downgrade\"", $response->body);
check_contains("files kept", "\"1.1.0\"", file_get_contents($site->root . "/_site/acme/notes/package.json"));
check_eq("the refused stage is removed", 0, folder_count($staging));

list($confirm, $commit, $migrate) = deploy($owner, $site->root . "/_log/acme.notes.v1.1.0.zip");
check_contains("confirm: same version", "The files will be replaced (same version).", $confirm->body);
check_contains("migration: nothing to run", "replace-ok", $migrate->body);
check_eq("two backups kept", 2, folder_count($backup));

t_section("failed upgrade, restore");

list($confirm, $commit, $migrate) = deploy($owner, make_zip($site, "acme.notes.v1.2.0.zip", notes_files("1.2.0", true)));
check_contains("migration: the upgrade fails", "custom-upgrade-error", $migrate->body);
$db = $site->db();
check_eq("failed upgrade: the installed version stays", "1.1.0", package_row($db, "acme/notes")["version"]);
check_eq("failed upgrade: the reason is recorded", "custom-upgrade-error", package_row($db, "acme/notes")["error"]);
$db = null;
check_eq("backups pruned to 2", 2, folder_count($backup));
$response = open_operation($owner, "restore", "acme_notes");
check_contains("restore: newest backup shown", "1.1.0", $response->body);
check_contains("restore: what it does", "The newest backup is put back", $response->body);
$response = $owner->submit("admin/package", $response->body, "dialog.form-restore_form", []);
$migrate = follow_migrate($owner, $response);
check_contains("restore: migration of the restored files", "replace-ok", $migrate->body);
check_contains("restore: files 1.1.0 again", "\"1.1.0\"", file_get_contents($site->root . "/_site/acme/notes/package.json"));
$db = $site->db();
check_eq("restore: the error is cleared", "", "" . package_row($db, "acme/notes")["error"]);
$db = null;
check("restore: the failing 1.2.0 is a backup now", count(glob($backup . "/*-1.2.0", GLOB_ONLYDIR)) == 1);
check_contains("restore: state installed", "Installed", $owner->get("admin/package")->body);

t_section("export");

$response = $owner->get("admin/package/export", ["id" => "acme_notes"]);
check_eq("export is 200", 200, $response->code);
check_eq("export: zip", "application/zip", $response->header("content-type"));
check_contains("export: file name", "acme.notes.v1.1.0.zip", "" . $response->header("content-disposition"));
file_put_contents($site->root . "/_log/exported.zip", $response->body);
$reader = \Andora\Web\Package\Zip::open($site->root . "/_log/exported.zip", $reason);
check("export: a valid zip", !is_null($reader));
$names = [];
$packageJson = null;
foreach ($reader->entries() as $index => $entry) {
    $names[] = $entry["name"];
    if ($entry["name"] === "_site/acme/notes/package.json") {
        $packageJson = $reader->read($index);
    }
}
$reader->close();
check_eq("export: every file of the folder", count(notes_files("1.1.0")), count($names));
check("export: every name under _site/acme/notes/", count(array_filter($names, function ($name) {
    return strpos($name, "_site/acme/notes/") !== 0;
})) == 0);
check_eq("export: package.json", file_get_contents($site->root . "/_site/acme/notes/package.json"), $packageJson);
check_eq("export: unknown package is 404", 404, $owner->get("admin/package/export", ["id" => "acme_none"])->code);
mkdir($site->root . "/_site/acme/plain", 0777, true);
file_put_contents($site->root . "/_site/acme/plain/x.txt", "x");
check_eq("export: folder without package.json is 404", 404, $owner->get("admin/package/export", ["id" => "acme_plain"])->code);
$response = $owner->get("admin/package/export", ["id" => "xyo_web"]);
check_eq("export: the framework", "xyo.web.v" . json_decode(file_get_contents($site->root . "/_site/xyo/web/package.json"), true)["version"] . ".zip", preg_match("/filename=\"([^\"]+)\"/", "" . $response->header("content-disposition"), $match) ? $match[1] : null);
check_eq("export: invalid id is 404", 404, $owner->get("admin/package/export", ["id" => "../x"])->code);
check_eq("export: no export file left", 0, count(glob($site->root . "/_repository/package/export-*")));

$db = $site->db();
$administratorId = add_user($db, "Administrator", "administrator@example.test", $password);
add_to_group($db, $administratorId, "administrator");
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("export: administrator (not root) is 401", 401, $administrator->get("admin/package/export", ["id" => "acme_notes"])->code);
$anonymous = $site->browser();
check("export: not logged in is not served", $anonymous->get("admin/package/export", ["id" => "acme_notes"])->code != 200);

t_section("uninstall");

$response = open_operation($owner, "uninstall", "andora_web");
check_contains("uninstall: andora/web not available", "Not available in this state.", $response->body);
$response = run_operation($owner, "uninstall", "andora_web");
check_contains("uninstall: andora/web refused", "invalid-state", $response->body);
$response = run_operation($owner, "uninstall", "xyo_web");
check_contains("uninstall: xyo/web refused (andora/web requires it)", "required-by", $response->body);
check("xyo/web is still there", is_file($site->root . "/_site/xyo/web/web.php"));

deploy($owner, make_zip($site, "acme.needs.v1.0.0.zip", ["_site/acme/needs/package.json" => json_encode(["vendor" => "acme", "name" => "needs", "version" => "1.0.0", "require" => ["acme/notes" => ">=1.0.0"]], JSON_UNESCAPED_SLASHES)]));
$response = run_operation($owner, "uninstall", "acme_notes");
check_contains("uninstall refused while another package requires it", "required-by", $response->body);
check_contains("uninstall: the package that requires it is named", "acme\\/needs", $response->body);
$response = run_operation($owner, "uninstall", "acme_needs");
check_contains("uninstall: acme/needs", "uninstall-ok", $response->body);

$response = open_operation($owner, "uninstall", "acme_notes");
check_contains("uninstall: keep data option", "name=\"keepData\"", $response->body);
$response = $owner->submit("admin/package", $response->body, "dialog.form-uninstall_form", ["keepData" => "1"]);
check_contains("uninstall, keep data", "uninstall-ok", $response->body);
check("keep data: folder moved", !is_dir($site->root . "/_site/acme/notes"));
$db = $site->db();
check("keep data: table kept", table_exists($db, "acme-notes-note"));
check_eq("keep data: record kept, disabled", 0, intval(package_row($db, "acme/notes")["enabled"]));
check_eq("keep data: permission disabled", 0, intval(scalar($db, "SELECT \"enabled\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-notes.manage'")));
$db = null;
check_contains("keep data: state files missing", "Files missing", $owner->get("admin/package")->body);

list($confirm, $commit, $migrate) = deploy($owner, $site->root . "/_log/exported.zip");
check_contains("the exported zip uploads again (same version)", "replace-ok", $migrate->body);
check_contains("uploaded again: disabled", "Disabled", $owner->get("admin/package")->body);
$owner->postComponent("admin/package", "datatable.enabled=enabled_acme_notes/enabled_acme_notes", ["id" => "acme_notes"]);
$db = $site->db();
check_eq("enabled again", 1, intval(package_row($db, "acme/notes")["enabled"]));
check_eq("the data is back", "kept", "" . scalar($db, "SELECT \"text\" FROM \"acme-notes-note\""));
$db = null;

$response = run_operation($owner, "uninstall", "acme_notes");
check_contains("uninstall", "uninstall-ok", $response->body);
$db = $site->db();
check("uninstall: table dropped", !table_exists($db, "acme-notes-note"));
check_eq("uninstall: record deleted", false, package_row($db, "acme/notes"));
check_eq("uninstall: permission deleted", false, scalar($db, "SELECT \"id\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-notes.manage'"));
check_eq("uninstall: menu entry deleted", false, scalar($db, "SELECT \"id\" FROM \"andora-web-admin-menu\" WHERE \"code\" = 'acme-notes'"));
$db = null;
check_contains("uninstalled package is listed (backups)", "Uninstalled", $owner->get("admin/package")->body);
$response = run_operation($owner, "restore", "acme_notes");
$migrate = follow_migrate($owner, $response);
check_contains("restore of an uninstalled package installs it", "install-ok", $migrate->body);
$db = $site->db();
check("restored: table created again", table_exists($db, "acme-notes-note"));
$db = null;

t_section("package.upload = false");

file_put_contents($site->root . "/config.07.package.php", "<?php\ndefined(\"XYO_WEB\") or die(\"Forbidden\");\nreturn array('package' => array('upload' => false));\n");
$page = $owner->get("admin/package");
check_eq("page is 200", 200, $page->code);
check_not_contains("no upload button", "\"form-upload\"", $page->body);
check_not_contains("no export link", "admin/package/export", $page->body);
$response = $owner->submitFile("admin/package", upload_form($owner)->body, "dialog.form-upload_form", [], "package", $site->root . "/_log/acme.notes.v1.1.0.zip");
check_contains("upload refused", "file-operations-disabled", $response->body);
check_eq("export is 403", 403, $owner->get("admin/package/export", ["id" => "acme_notes"])->code);
$response = open_operation($owner, "uninstall", "acme_notes");
check_contains("uninstall not available", "Not available in this state.", $response->body);
$response = $owner->submit("admin/package", $response->body, "dialog.form-uninstall_form", []);
check_contains("uninstall refused", "file-operations-disabled", $response->body);
$db = $site->db();
check("still installed", package_row($db, "acme/notes") !== false);
$db = null;
unlink($site->root . "/config.07.package.php");

t_section("lock");

$lock = fopen($site->root . "/_repository/package/lock", "c");
flock($lock, LOCK_EX);
$response = run_operation($owner, "reset", "acme_notes");
check_contains("an operation while another one runs: busy", "busy", $response->body);
flock($lock, LOCK_UN);
fclose($lock);
$response = run_operation($owner, "reset", "acme_notes");
check_contains("after the lock is released", "reset-ok", $response->body);

t_section("command line");

list($code, $output) = cli($site, ["list"]);
check_eq("cli list: exit 0", 0, $code);
check_contains("cli list: acme/notes", "acme/notes", $output);
check_contains("cli list: state", "installed", $output);
list($code, $output) = cli($site, ["deploy", "--trust", make_zip($site, "acme.cli.v1.0.0.zip", ["_site/acme/cli/package.json" => json_encode(["vendor" => "acme", "name" => "cli", "version" => "1.0.0"])])]);
check_eq("cli deploy: exit 0", 0, $code);
check_contains("cli deploy: files", "files ok (install)", $output);
check_contains("cli deploy: migrated in a new process", "acme/cli: install ok", $output);
$db = $site->db();
check_eq("cli deploy: installed", "1.0.0", package_row($db, "acme/cli")["version"] ?? null);
$db = null;
list($code, $output) = cli($site, ["deploy", $site->root . "/_log/r1.zip"]);
check_eq("cli deploy of a refused zip: exit 1", 1, $code);
check_contains("cli deploy of a refused zip: reason", "invalid-entry", $output);
list($code, $output) = cli($site, ["export", "acme/cli", $site->root . "/_log/cli-export.zip"]);
check_eq("cli export: exit 0", 0, $code);
check("cli export: file written", is_file($site->root . "/_log/cli-export.zip"));
list($code, $output) = cli($site, ["uninstall", "acme/cli", "--keep-data"]);
check_eq("cli uninstall --keep-data: exit 0", 0, $code);
list($code, $output) = cli($site, ["restore", "acme/cli"]);
check_eq("cli restore: exit 0", 0, $code);
check_contains("cli restore: migrated", "acme/cli: replace ok", $output);
list($code, $output) = cli($site, ["uninstall", "andora/web"]);
check_eq("cli uninstall andora/web: exit 1", 1, $code);
check_contains("cli uninstall andora/web: reason", "invalid-state", $output);
list($code, $output) = cli($site, ["bogus"]);
check_eq("cli unknown command: exit 1", 1, $code);
check_contains("cli unknown command: help", "deploy <file.zip>", $output);
file_put_contents($site->root . "/config.07.package.php", "<?php\ndefined(\"XYO_WEB\") or die(\"Forbidden\");\nreturn array('package' => array('upload' => false));\n");
list($code, $output) = cli($site, ["export", "acme/cli", $site->root . "/_log/cli-export-2.zip"]);
check_eq("cli: package.upload does not apply", 0, $code);
unlink($site->root . "/config.07.package.php");
check_eq("cli from the web: not served", true, $owner->get("_site/andora/web/package/cli.php")->code != 200 || strlen($owner->get("_site/andora/web/package/cli.php")->body) == 0);
check_contains("package log: cli user", "\"cli\"", "" . @file_get_contents($site->root . "/_log/" . date("Y-m-d") . ".package.log"));

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
