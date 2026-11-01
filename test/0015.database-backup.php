<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The tables of the packages (package.json "datasource", the registry
// config.12.datasource.php) and the database backups: admin/database (the
// packages with tables, the tables without a package), export of every
// table / of the tables of a package (admin/database/export), the import of
// a backup (form-import -> form-restore: every table or one package of it),
// the copy made before a restore, seed() after it, the refusals
// (invalid-backup, backup-newer, a changed table file, confirm-required,
// database.import), the access (database.manage), the checks of the
// manifest (invalid-datasource, datasource-missing, datasource-prefix,
// datasource-conflict, datasource-undeclared), cli.php database tables | export | import | backups.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function write_files($site, $files)
{
    foreach ($files as $name => $content) {
        $file = $site->root . "/" . $name;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }
}

function manifest($vendor, $name, $version, $extra = [])
{
    return json_encode(array_merge(["vendor" => $vendor, "name" => $name, "version" => $version, "title" => $vendor . "/" . $name], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// A Table class _site/<vendor>/<name>/data-source/table-<short>.php of the
// table $table
function table_file($namespace, $short, $table)
{
    return "<?php\nnamespace " . $namespace . "\\DataSource;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Table" . ucfirst($short) . " extends \\XYO\\Web\\DataSource\\Table\n{\n    public \$id;\n    public \$user__id;\n    public \$text;\n    public \$data;\n\n    public static function descriptor(\$info)\n    {\n        \$info->name = \"" . $table . "\";\n        \$info->primaryKey = \"id\";\n        \$info->fields = array(\n            \"id\" => array(\"bigint\", \"DEFAULT\", \"unsigned\", \"autoIncrement\"),\n            \"user__id\" => array(\"bigint\", 0, \"unsigned\"),\n            \"text\" => array(\"varchar\", null, 255),\n            \"data\" => array(\"varchar\", null, 255)\n        );\n    }\n}\n";
}

// acme/notes: a table, a permission registered by seed()
$notesSetup = "<?php\nnamespace Acme\\Notes\\Package;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n    public function seed(&\$reason)\n    {\n        return \$this->registerPermission(\"acme-notes.manage\", \"Manage the notes\", \$reason);\n    }\n}\n";
$packages = [
    "_site/acme/notes/package.json" => manifest("acme", "notes", "1.0.0", ["datasource" => ["Acme\\Notes\\DataSource\\TableNote"]]),
    "_site/acme/notes/package.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Notes\\Package\\Setup::class;\n",
    "_site/acme/notes/package/setup.php" => $notesSetup,
    "_site/acme/notes/data-source/table-note.php" => table_file("Acme\\Notes", "note", "acme-notes-note"),
    // no tables
    "_site/acme/plain/package.json" => manifest("acme", "plain", "1.0.0"),
    // the checks of the manifest
    "_site/acme/outside/package.json" => manifest("acme", "outside", "1.0.0", ["datasource" => ["Acme\\Notes\\DataSource\\TableNote"]]),
    "_site/acme/missing/package.json" => manifest("acme", "missing", "1.0.0", ["datasource" => ["Acme\\Missing\\DataSource\\TableNone"]]),
    "_site/acme/undeclared/package.json" => manifest("acme", "undeclared", "1.0.0"),
    "_site/acme/undeclared/package.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Undeclared\\Package\\Setup::class;\n",
    "_site/acme/undeclared/package/setup.php" => "<?php\nnamespace Acme\\Undeclared\\Package;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n    protected \$tables = array(\\Acme\\Undeclared\\DataSource\\TableNote::class);\n}\n",
    "_site/acme/undeclared/data-source/table-note.php" => table_file("Acme\\Undeclared", "note", "acme-undeclared-note")
];
// added after the setup: a table of acme/notes, not acme-clash-...
$clash = [
    "_site/acme/clash/package.json" => manifest("acme", "clash", "1.0.0", ["datasource" => ["Acme\\Clash\\DataSource\\TableNote"]]),
    "_site/acme/clash/data-source/table-note.php" => table_file("Acme\\Clash", "note", "acme-notes-note")
];
// andora-web/user: andora-web-user-group starts with its prefix, but
// andora/web has the table first
$clashCore = [
    "_site/andora-web/user/package.json" => manifest("andora-web", "user", "1.0.0", ["datasource" => ["AndoraWeb\\User\\DataSource\\TableGroup"]]),
    "_site/andora-web/user/data-source/table-group.php" => table_file("AndoraWeb\\User", "group", "andora-web-user-group")
];

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

function notes($db)
{
    return $db->query("SELECT \"id\", \"user__id\", \"text\", \"data\" FROM \"acme-notes-note\" ORDER BY \"id\"")->fetchAll(PDO::FETCH_ASSOC);
}

function package_row($db, $code)
{
    $statement = $db->prepare("SELECT * FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetch();
}

// backup.json and the names of the entries of a backup zip
function read_backup($file)
{
    $reason = "";
    $zip = \Andora\Web\Package\Zip::open($file, $reason);
    if (is_null($zip)) {
        return null;
    }
    $names = [];
    $manifest = null;
    foreach ($zip->entries() as $index => $entry) {
        $names[] = $entry["name"];
        if ($entry["name"] === "backup.json") {
            $manifest = json_decode($zip->read($index), true);
        }
    }
    $zip->close();
    return ["manifest" => $manifest, "names" => $names];
}

function backup_table($backup, $name)
{
    foreach ($backup["manifest"]["tables"] ?? [] as $table) {
        if ($table["name"] === $name) {
            return $table;
        }
    }
    return null;
}

// Write a backup zip again from its entries, $change(name, content) returns the new content
function rewrite_backup($site, $file, $target, $change)
{
    $reason = "";
    $zip = \Andora\Web\Package\Zip::open($file, $reason);
    $folder = $site->root . "/_log/rewrite-" . bin2hex(random_bytes(4));
    $files = [];
    foreach ($zip->entries() as $index => $entry) {
        $path = $folder . "/" . $entry["name"];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $change($entry["name"], $zip->read($index)));
        $files[$entry["name"]] = $path;
    }
    $zip->close();
    \Andora\Web\Package\Zip::write($target, $files, $reason);
    return $target;
}

function download($browser, $site, $id, $name)
{
    $response = $browser->get("admin/database/export", ["id" => $id]);
    $file = $site->root . "/_log/" . $name;
    file_put_contents($file, $response->body);
    return [$response, $file];
}

// Upload a backup on admin/database: the answer of form-import and of form-restore (init)
function upload($browser, $file)
{
    $form = $browser->component("admin/database", "dialog", ["component" => "form-import"]);
    $response = $browser->submitFile("admin/database", $form->body, "dialog.form-import_form", [], "backup", $file);
    $id = preg_match("/\"import__id\",\"([0-9a-f]{32})\"/", $response->body, $match) ? $match[1] : null;
    if (is_null($id)) {
        return [$response, null];
    }
    return [$response, $browser->postComponent("admin/database", "dialog", ["component" => "form-restore", "init" => "true", "import__id" => $id])];
}

function restore($browser, $restoreForm, $fields = ["confirm" => "1"])
{
    return $browser->submit("admin/database", $restoreForm->body, "dialog.form-restore_form", $fields);
}

$site = (new TestSite())->start();
write_files($site, $packages);
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
\Andora\Web\Package\Zip::$useZipArchive = false;

t_section("the tables of the packages");

$db = $site->db();
check_eq("acme/notes installed by the setup", "1.0.0", package_row($db, "acme/notes")["version"] ?? null);
check("its table is created from package.json datasource", count($db->query("SELECT \"name\" FROM \"sqlite_master\" WHERE \"name\" = 'acme-notes-note'")->fetchAll()) == 1);
check_eq("acme/outside broken: a class of another package", false, package_row($db, "acme/outside"));
check_eq("acme/missing not installed", false, package_row($db, "acme/missing"));
check_eq("acme/undeclared not installed", false, package_row($db, "acme/undeclared"));
$db = null;

$registry = $site->config("config.12.datasource.php");
check("registry written", !is_null($registry));
check_contains("registry: user of andora/web", "'andora-web-user' => \n    array (\n      'package' => 'andora/web',\n      'class' => 'Andora\\\\Web\\\\DataSource\\\\TableUser',", str_replace("\r\n", "\n", "" . $registry));
check_contains("registry: the table of acme/notes", "'acme-notes-note' => \n    array (\n      'package' => 'acme/notes',", str_replace("\r\n", "\n", "" . $registry));
check_not_contains("registry: no table of a package not installed", "acme-undeclared-note", "" . $registry);

list($code, $output) = cli($site, ["install", "acme/outside"]);
check_eq("outside: refused", 1, $code);
check_contains("outside: broken manifest", "invalid-state", $output);
list($code, $output) = cli($site, ["install", "acme/missing"]);
check_contains("missing: datasource-missing", "Error: datasource-missing: Acme\\Missing\\DataSource\\TableNone", $output);
write_files($site, $clash);
list($code, $output) = cli($site, ["install", "acme/clash"]);
check_contains("clash: datasource-prefix", "Error: datasource-prefix: acme-notes-note", $output);
write_files($site, $clashCore);
list($code, $output) = cli($site, ["install", "andora-web/user"]);
check_contains("clash with andora/web: datasource-conflict", "Error: datasource-conflict: andora-web-user-group", $output);
list($code, $output) = cli($site, ["install", "acme/undeclared"]);
check_contains("undeclared: datasource-undeclared", "Error: datasource-undeclared: Acme\\Undeclared\\DataSource\\TableNote", $output);

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$response = $owner->postComponent("admin/package", "dialog", ["component" => "form-details", "init" => "true", "package__id" => "acme_notes"]);
check_contains("details: the tables of the package", "acme-notes-note", $response->body);
$response = $owner->postComponent("admin/package", "dialog", ["component" => "form-details", "init" => "true", "package__id" => "acme_outside"]);
check_contains("details: invalid datasource", "The datasource list in package.json is not valid", $response->body);

t_section("admin/database");

$db = $site->db();
$ownerId = intval($site->userByEmail("owner@example.test")["id"]);
$adminId = add_user($db, "Admin", "admin@example.test", $password, "administrator");
$insert = $db->prepare("INSERT INTO \"acme-notes-note\" (\"user__id\", \"text\", \"data\") VALUES (?, ?, ?)");
$insert->execute([$ownerId, "First note", null]);
$insert->execute([$ownerId, "Ünicode: ăîșț — 😀", "line 1\nline 2 \"quoted\""]);
$binary = $db->prepare("INSERT INTO \"acme-notes-note\" (\"user__id\", \"text\", \"data\") VALUES (?, ?, ?)");
$binary->bindValue(1, $adminId);
$binary->bindValue(2, "Binary");
$binary->bindValue(3, "\xff\xfe\x00\x01", PDO::PARAM_LOB);
$binary->execute();
$db->exec("CREATE TABLE \"orphan-thing\" (\"id\" INTEGER, \"name\" VARCHAR(32))");
$db->exec("INSERT INTO \"orphan-thing\" VALUES (1, 'old')");
$notesBefore = notes($db);
$db = null;
check_eq("three notes", 3, count($notesBefore));

$page = $owner->get("admin/database");
check_eq("root: admin/database is 200", 200, $page->code);
check_contains("menu: the database page", "admin/database\"", $page->body);
check_contains("export all", "admin/database/export?id=all", $page->body);
check_contains("a table without a package", "orphan-thing (1)", $page->body);
check_contains("the package table is not in the backups", "Not in the backups: andora-web-package", $page->body);
$response = $owner->postComponent("admin/database", "datatable", []);
check_contains("list: acme/notes", "acme/notes", $response->body);
check_contains("list: andora/web", "andora/web", $response->body);
check_not_contains("list: no package without tables", "acme/plain", $response->body);

$admin = $site->browser();
$admin->login("admin@example.test", $password);
check_eq("administrator: admin/database is 401", 401, $admin->get("admin/database")->code);
check_eq("administrator: export is 401", 401, $admin->get("admin/database/export", ["id" => "all"])->code);
check_eq("not logged in: login", 302, $site->browser()->get("admin/database")->code);

t_section("export");

list($response, $notesFile) = download($owner, $site, "acme_notes", "notes.zip");
check_eq("export acme/notes: 200", 200, $response->code);
check_eq("a zip", "application/zip", $response->header("content-type"));
check("file name: database.acme.notes.<date-time>.zip", preg_match("/^attachment; filename=\"database\\.acme\\.notes\\.[0-9]{8}-[0-9]{6}\\.zip\"$/", "" . $response->header("content-disposition")) == 1);
$backup = read_backup($notesFile);
check_eq("format", "andora-database", $backup["manifest"]["format"] ?? null);
check_eq("scope", "acme/notes", $backup["manifest"]["scope"] ?? null);
check_eq("only the table of the package", ["acme-notes-note"], array_column($backup["manifest"]["tables"] ?? [], "name"));
check_eq("its rows", 3, backup_table($backup, "acme-notes-note")["rows"] ?? null);
check_eq("its columns", ["id", "user__id", "text", "data"], backup_table($backup, "acme-notes-note")["columns"] ?? null);
check_eq("the version of the package", "1.0.0", $backup["manifest"]["packages"]["acme/notes"] ?? null);
check("the version of andora/web", isset($backup["manifest"]["packages"]["andora/web"]));
check("a part per table", in_array("table/0001-001.jsonl", $backup["names"], true));

list($response, $allFile) = download($owner, $site, "all", "all.zip");
check_eq("export all: 200", 200, $response->code);
check("file name: database.all.<date-time>.zip", preg_match("/filename=\"database\\.all\\.[0-9]{8}-[0-9]{6}\\.zip\"/", "" . $response->header("content-disposition")) == 1);
$backup = read_backup($allFile);
$names = array_column($backup["manifest"]["tables"] ?? [], "name");
check("all: the users", in_array("andora-web-user", $names, true));
check("all: the notes", in_array("acme-notes-note", $names, true));
check("all: a table without a package", in_array("orphan-thing", $names, true));
check("all: no package for it", array_key_exists("package", backup_table($backup, "orphan-thing") ?? []) && is_null(backup_table($backup, "orphan-thing")["package"]));
check("all: not the package table", !in_array("andora-web-package", $names, true));
check_eq("all: the package of user", "andora/web", backup_table($backup, "andora-web-user")["package"] ?? null);

check_eq("export of an unknown package: 404", 404, $owner->get("admin/database/export", ["id" => "acme_unknown"])->code);
check_eq("export of a package without tables: 404", 404, $owner->get("admin/database/export", ["id" => "acme_plain"])->code);
check_eq("export of a bad id: 404", 404, $owner->get("admin/database/export", ["id" => "../x"])->code);
check_contains("export logged", "\"operation\": \"export\"", implode("", array_map("file_get_contents", glob($site->root . "/_log/*.database.log"))));

t_section("import a package backup");

$db = $site->db();
$db->exec("DELETE FROM \"acme-notes-note\"");
$db->exec("INSERT INTO \"acme-notes-note\" (\"user__id\", \"text\") VALUES (1, 'after the backup')");
$db->exec("UPDATE \"andora-web-user\" SET \"name\" = 'Renamed' WHERE \"email\" = 'admin@example.test'");
$db->exec("DELETE FROM \"andora-web-permission\" WHERE \"name\" = 'acme-notes.manage'");
// a column added after the backup: its default
$db->exec("ALTER TABLE \"acme-notes-note\" ADD COLUMN \"extra\" VARCHAR(16) DEFAULT 'none'");
$db = null;

list($response, $form) = upload($owner, $notesFile);
check("upload accepted", !is_null($form));
check_contains("restore: the scope", "acme/notes", $form->body ?? "");
check_contains("restore: the table", "acme-notes-note", $form->body ?? "");
check_not_contains("restore: no package choice for one package", "name=\"package\"", $form->body ?? "");
$response = restore($owner, $form, []);
check_contains("restore without the confirmation: refused", "confirm-required", $response->body);
$response = restore($owner, $form);
check_contains("restore ok", "message-ok", $response->body);
$db = $site->db();
$notesAfter = $db->query("SELECT \"id\", \"user__id\", \"text\", \"data\" FROM \"acme-notes-note\" ORDER BY \"id\"")->fetchAll(PDO::FETCH_ASSOC);
check_eq("the notes are the ones of the backup (ids, NULL, unicode, new lines)", array_slice($notesBefore, 0, 2), array_slice($notesAfter, 0, 2));
check_eq("binary value", "\xff\xfe\x00\x01", $notesAfter[2]["data"] ?? null);
check_eq("three notes again", 3, count($notesAfter));
check_eq("the new column has its default", "none", $db->query("SELECT \"extra\" FROM \"acme-notes-note\" LIMIT 1")->fetchColumn());
check_eq("the tables of andora/web are not restored", "Renamed", $db->query("SELECT \"name\" FROM \"andora-web-user\" WHERE \"email\" = 'admin@example.test'")->fetchColumn());
check_eq("seed() ran after the restore: the permission again", 1, intval($db->query("SELECT COUNT(*) FROM \"andora-web-permission\" WHERE \"name\" = 'acme-notes.manage' AND \"package\" = 'acme/notes'")->fetchColumn()));
$db->exec("INSERT INTO \"acme-notes-note\" (\"user__id\", \"text\") VALUES (1, 'next id')");
check("autoincrement after the restored ids", intval($db->query("SELECT MAX(\"id\") FROM \"acme-notes-note\"")->fetchColumn()) > intval($notesBefore[2]["id"]));
$db = null;
$copies = glob($site->root . "/_repository/database/backup/database.acme.notes.*.before-restore.zip");
check_eq("a copy before the restore", 1, count($copies));
$copy = read_backup($copies[0] ?? "");
check_eq("the copy holds the rows before the restore", 1, backup_table($copy, "acme-notes-note")["rows"] ?? null);
check_eq("the upload is removed", 0, count(glob($site->root . "/_repository/database/import/*.zip")));

t_section("import a backup of every table");

list($response, $form) = upload($owner, $allFile);
check_contains("restore: every table", "All the tables", $form->body ?? "");
check_contains("restore: choice of a package", "name=\"package\"", $form->body ?? "");
check_contains("restore: andora/web", "value=\"andora_web\"", $form->body ?? "");
check_contains("restore: acme/notes", "value=\"acme_notes\"", $form->body ?? "");
// only acme/notes of the full backup
$db = $site->db();
$db->exec("DELETE FROM \"acme-notes-note\"");
$db = null;
$response = restore($owner, $form, ["confirm" => "1", "package" => "acme_notes"]);
check_contains("restore of one package ok", "message-ok", $response->body);
$db = $site->db();
check_eq("only the notes", 3, count(notes($db)));
check_eq("users unchanged", "Renamed", $db->query("SELECT \"name\" FROM \"andora-web-user\" WHERE \"email\" = 'admin@example.test'")->fetchColumn());
$db->exec("DELETE FROM \"orphan-thing\"");
$db = null;

list($response, $form) = upload($owner, $allFile);
$response = restore($owner, $form);
check_contains("restore of everything ok", "message-ok", $response->body);
$db = $site->db();
check_eq("users restored", "Admin", $db->query("SELECT \"name\" FROM \"andora-web-user\" WHERE \"email\" = 'admin@example.test'")->fetchColumn());
check_eq("a table without a package restored", "old", $db->query("SELECT \"name\" FROM \"orphan-thing\"")->fetchColumn());
check_eq("the package table is kept", "1.0.0", package_row($db, "acme/notes")["version"] ?? null);
$db = null;
check_eq("the copies before a restore: the newest 3", 3, count(glob($site->root . "/_repository/database/backup/*.before-restore.zip")));
check_eq("still logged in (the session of the backup)", 200, $owner->get("admin/database")->code);

t_section("refused");

$bad = $site->root . "/_log/bad.zip";
file_put_contents($bad, "not a zip");
list($response, $form) = upload($owner, $bad);
check_contains("not a zip: invalid-backup", "invalid-backup", $response->body);

$newer = rewrite_backup($site, $notesFile, $site->root . "/_log/newer.zip", function ($name, $content) {
    if ($name !== "backup.json") {
        return $content;
    }
    $data = json_decode($content, true);
    $data["packages"]["acme/notes"] = "9.0.0";
    return json_encode($data);
});
list($response, $form) = upload($owner, $newer);
check_contains("backup of a newer version: refused", "The backup holds the data of a newer version of the package", $form->body ?? "");
check_not_contains("no restore button", "btn btn-error", $form->body ?? "");
list($code, $output) = cli($site, ["database", "import", $newer]);
check_contains("cli: backup-newer", "Error: backup-newer: acme/notes 9.0.0 > 1.0.0", $output);

$db = $site->db();
$db->exec("DELETE FROM \"acme-notes-note\" WHERE \"text\" = 'Binary'");
$db = null;
$changed = rewrite_backup($site, $notesFile, $site->root . "/_log/changed.zip", function ($name, $content) {
    return ($name === "table/0001-001.jsonl") ? str_replace("First note", "Other note", $content) : $content;
});
list($response, $form) = upload($owner, $changed);
$response = restore($owner, $form);
check_contains("a changed table file: invalid-backup", "invalid-backup", $response->body);
$db = $site->db();
check_eq("nothing changed (rolled back)", 2, count(notes($db)));
$db = null;

list($code, $output) = cli($site, ["database", "import", $allFile, "--package=acme/plain"]);
check_contains("cli: a package not in the backup", "Error: package-not-in-backup: acme/plain", $output);

write_files($site, ["config.99.test.php" => "<?php\ndefined(\"XYO_WEB\") or die(\"Forbidden\");\nreturn array(\"database\" => array(\"import\" => false));\n"]);
$response = $owner->postComponent("admin/database", "datatable", []);
list($response, $form) = upload($owner, $notesFile);
check_contains("database.import off: refused", "import-disabled", $response->body);
check_eq("database.import off: export still works", 200, $owner->get("admin/database/export", ["id" => "acme_notes"])->code);
unlink($site->root . "/config.99.test.php");

t_section("command line");

list($code, $output) = cli($site, ["database", "tables"]);
check_eq("tables: ok", 0, $code);
check("tables: the notes of acme/notes", preg_match("/acme-notes-note\\s+acme\\/notes\\s+2/", $output) == 1);
check("tables: orphan-thing without a package", preg_match("/orphan-thing\\s+-\\s+1/", $output) == 1);
check_contains("tables: package not in the backups", "(not in backups)", $output);
list($code, $output) = cli($site, ["database", "export", "acme/notes"]);
check_eq("export: ok", 0, $code);
$file = trim($output);
check("export: in _repository/database/backup/", preg_match("#_repository/database/backup/database\\.acme\\.notes\\.[0-9]{8}-[0-9]{6}\\.zip$#", str_replace("\\", "/", $file)) == 1 && is_file($file));
list($code, $output) = cli($site, ["database", "export", "acme/plain"]);
check_contains("export of a package without tables", "Error: no-tables: acme/plain", $output);
list($code, $output) = cli($site, ["database", "export", "bad"]);
check_contains("export of a bad scope", "Error: invalid-scope: bad", $output);
list($code, $output) = cli($site, ["database", "backups"]);
check_contains("backups: the export", basename($file), $output);
check_contains("backups: a copy before a restore", ".before-restore.zip", $output);
$db = $site->db();
$db->exec("DELETE FROM \"acme-notes-note\"");
$db = null;
list($code, $output) = cli($site, ["database", "import", $file, "--package=acme/notes"]);
check_eq("import: ok", 0, $code);
check_contains("import: one table", "1 tables restored", $output);
$db = $site->db();
check_eq("import: the notes", 2, count(notes($db)));
$db = null;
check_contains("import logged", "\"operation\": \"import\"", implode("", array_map("file_get_contents", glob($site->root . "/_log/*.database.log"))));

check_eq("no router errors", "", $site->routerLog());

t_done();
