<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Acl;

// Backups of the database (the rows of the tables, not their files) as zip
// files, of every table of the database ("all") or of the tables of one
// package (the "datasource" list of its package.json, DataSourceRegistry):
//
//     database.<all | vendor.name>.<YYYYMMDD-HHMMSS>.zip
//         backup.json         format, created, andora, database, scope,
//                             packages (code => installed version),
//                             tables (name, package, columns, rows,
//                             parts: list of file, sha256)
//         table/<n>-<p>.jsonl the rows of a table, one JSON list per line in
//                             the order of its columns (a value that is not
//                             UTF-8: {"base64": "..."}), in parts of up to
//                             PART_SIZE bytes
//
// The names of the tables are without the prefix of the connection. The
// table "andora-web-package" (the state of the package files of this site)
// is never in a backup.
//
// A restore replaces the rows of the tables of the backup that exist in the
// database (in one transaction; the columns of the backup that the table
// still has, the others get their default) after a copy of their current
// rows was saved in _repository/database/backup/ (*.before-restore.zip, the
// newest kept). The data of a package newer than the installed version is
// refused (backup-newer). Then seed() of the restored packages runs again.
// Folders, under _repository/database/ (not served):
//     backup/   the backups of the command line and the copies before a restore
//     import/   an uploaded backup, waiting for the confirmation
class DatabaseBackup
{
    public const FORMAT = "andora-database";
    public const FORMAT_VERSION = 1;
    public const ALL = "all";
    // the state of the package files of this site
    public const EXCLUDED = array("andora-web-package");
    public const NAME_PATTERN = "/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/";
    public const MAX_TABLES = 4096;
    public const MAX_MANIFEST_SIZE = 16777216;
    // a part of the rows of a table is read in memory on restore (below Zip::MAX_ENTRY_SIZE)
    public const PART_SIZE = 33554432;
    public const MAX_PARTS = 4096;
    // copies made before a restore
    public const KEEP_BEFORE_RESTORE = 3;
    // uploads not confirmed are removed after a day
    public const IMPORT_TIMEOUT = 86400;

    public static function repositoryPath()
    {
        return ANDORA_PATH . "_repository/database/";
    }

    public static function backupPath()
    {
        return self::repositoryPath() . "backup/";
    }

    public static function importPath()
    {
        return self::repositoryPath() . "import/";
    }

    protected static function makePath($path)
    {
        return is_dir($path) || @mkdir($path, 0777, true);
    }

    // The import of a backup on the admin page: config database.import
    // (default true) turns it off, except on the command line
    public static function isImportAllowed($web)
    {
        if (Manager::$cli) {
            return true;
        }
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("database")) {
            return true;
        }
        return $config->get("database")->get("import", true) ? true : false;
    }

    // all, or <vendor>/<name>
    public static function isScope($scope)
    {
        return ($scope === self::ALL) || Manifest::isCode($scope);
    }

    // database.<all | vendor.name>.<YYYYMMDD-HHMMSS>.zip
    public static function fileName($scope, $time = null)
    {
        return "database." . str_replace("/", ".", $scope) . "." . date("Ymd-His", is_null($time) ? time() : $time) . ".zip";
    }

    // A file of $folder named as fileName($scope) with $suffix (".zip") that
    // does not exist: -2, -3, ... after the date for a second backup in the
    // same second
    public static function freeFile($folder, $scope, $suffix = ".zip")
    {
        $base = substr(self::fileName($scope), 0, -4);
        $file = $folder . $base . $suffix;
        for ($k = 2; file_exists($file); $k++) {
            $file = $folder . $base . "-" . $k . $suffix;
        }
        return $file;
    }

    // The connection "db" of the configuration
    protected static function connection($web)
    {
        return $web->get(\XYO\Web\DataSource\Connection::class)->get();
    }

    // sqlite, mysql or postgresql
    public static function databaseType($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("dataSource")) {
            return "";
        }
        return "" . $config->get("dataSource")->get("connection")->get("db")->get("type", "");
    }

    protected static function quote($type, $name)
    {
        if ($type === "mysql") {
            return "`" . str_replace("`", "``", $name) . "`";
        }
        if ($type === "sqlite") {
            return "[" . str_replace("]", "]]", str_replace("[", "[[", $name)) . "]";
        }
        return "\"" . str_replace("\"", "\"\"", $name) . "\"";
    }

    // name (without the prefix) => name in the database, sorted; null on error
    public static function databaseTables($web, &$reason)
    {
        $connection = self::connection($web);
        $type = self::databaseType($web);
        if (is_null($connection)) {
            $reason = "database-error";
            return null;
        }
        DataSourceRegistry::ensure($web);
        switch ($type) {
            case "sqlite":
                $query ="SELECT [name] FROM [sqlite_master] WHERE [type] = 'table' AND [name] NOT LIKE 'sqlite\\_%' ESCAPE '\\';";
                break;
            case "mysql":
                $query = "SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND `table_type` = 'BASE TABLE';";
                break;
            case "postgresql":
                $query = "SELECT \"tablename\" FROM \"pg_catalog\".\"pg_tables\" WHERE \"schemaname\" = current_schema();";
                break;
            default:
                $reason = "database-not-supported";
                return null;
        }
        $result = $connection->query($query);
        if (is_null($result)) {
            $reason = "database-error";
            return null;
        }
        $prefix = "" . $connection->getPrefix();
        $list = array();
        foreach ($result->fetchAll(\PDO::FETCH_NUM) as $row) {
            $physical = "" . $row[0];
            if ((strlen($prefix) > 0) && (strncmp($physical, $prefix, strlen($prefix)) != 0)) {
                continue;
            }
            $name = substr($physical, strlen($prefix));
            if (preg_match(self::NAME_PATTERN, $name) == 1) {
                $list[$name] = $physical;
            }
        }
        ksort($list);
        return $list;
    }

    // The columns of a table in their order, null on error
    protected static function columns($connection, $type, $physical)
    {
        switch ($type) {
            case "sqlite":
                $result = $connection->query("PRAGMA table_info(" . self::quote($type, $physical) . ");");
                $index = 1;
                break;
            case "mysql":
                $result = $connection->queryPrepare("SELECT `column_name` FROM `information_schema`.`columns` WHERE `table_schema` = DATABASE() AND `table_name` = ? ORDER BY `ordinal_position`;", array($physical));
                $index = 0;
                break;
            default:
                $result = $connection->queryPrepare("SELECT \"column_name\" FROM \"information_schema\".\"columns\" WHERE \"table_schema\" = current_schema() AND \"table_name\" = ? ORDER BY \"ordinal_position\";", array($physical));
                $index = 0;
        }
        if (is_null($result)) {
            return null;
        }
        $list = array();
        foreach ($result->fetchAll(\PDO::FETCH_NUM) as $row) {
            $list[] = "" . $row[$index];
        }
        return (count($list) > 0) ? $list : null;
    }

    protected static function countRows($connection, $type, $physical)
    {
        $result = $connection->query("SELECT COUNT(*) FROM " . self::quote($type, $physical) . ";");
        return is_null($result) ? 0 : intval($result->fetchColumn());
    }

    // The tables of the database for the admin page and the command line:
    // objects name, package (null: no package declares it), rows, excluded
    public static function tables($web, &$reason)
    {
        $tables = self::databaseTables($web, $reason);
        if (is_null($tables)) {
            return null;
        }
        $connection = self::connection($web);
        $type = self::databaseType($web);
        $list = array();
        foreach ($tables as $name => $physical) {
            $item = new \stdClass();
            $item->name = $name;
            $item->package = DataSourceRegistry::owner($web, $name);
            $item->rows = self::countRows($connection, $type, $physical);
            $item->excluded = in_array($name, self::EXCLUDED, true);
            $list[] = $item;
        }
        return $list;
    }

    // name => name in the database of the tables of a scope: every table of
    // the database (all) or the tables of the package that exist
    public static function scopeTables($web, $scope, &$reason)
    {
        Manager::$detail = "";
        if (!self::isScope($scope)) {
            $reason = "invalid-scope";
            Manager::$detail = "" . $scope;
            return null;
        }
        $tables = self::databaseTables($web, $reason);
        if (is_null($tables)) {
            return null;
        }
        $list = array();
        if ($scope === self::ALL) {
            foreach ($tables as $name => $physical) {
                if (!in_array($name, self::EXCLUDED, true)) {
                    $list[$name] = $physical;
                }
            }
            return $list;
        }
        foreach (DataSourceRegistry::tablesOf($web, $scope) as $name) {
            if (array_key_exists($name, $tables) && !in_array($name, self::EXCLUDED, true)) {
                $list[$name] = $tables[$name];
            }
        }
        if (count($list) == 0) {
            $reason = "no-tables";
            Manager::$detail = $scope;
            return null;
        }
        return $list;
    }

    // The packages of the database for the admin page: code => list of its
    // tables that exist (not the excluded ones), sorted
    public static function packages($web, &$reason)
    {
        $tables = self::databaseTables($web, $reason);
        if (is_null($tables)) {
            return null;
        }
        $list = array();
        foreach ($tables as $name => $physical) {
            $code = DataSourceRegistry::owner($web, $name);
            if (!is_null($code) && !in_array($name, self::EXCLUDED, true)) {
                $list[$code][] = $name;
            }
        }
        ksort($list);
        return $list;
    }

    // The version of the package installed now ("" if none)
    protected static function installedVersion($web, $code)
    {
        $info = Manager::get($web, $code);
        return (is_null($info) || !$info->isInstalled) ? "" : $info->installedVersion;
    }

    // A zip of the tables of $scope (all, <vendor>/<name>) written to $zipFile
    public static function export($web, $scope, $zipFile, &$reason)
    {
        $tables = self::scopeTables($web, $scope, $reason);
        if (is_null($tables)) {
            self::log("export", $scope, "error", $reason);
            return false;
        }
        $isOk = self::exportTables($web, $scope, $tables, $zipFile, $reason);
        self::log("export", $scope, $isOk ? "ok" : "error", $isOk ? "" : $reason, $isOk ? count($tables) . " tables" : null);
        return $isOk;
    }

    protected static function exportTables($web, $scope, $tables, $zipFile, &$reason)
    {
        $connection = self::connection($web);
        $type = self::databaseType($web);
        if (is_null($connection)) {
            $reason = "database-error";
            return false;
        }
        $temporary = self::repositoryPath() . "temp/" . bin2hex(random_bytes(8)) . "/";
        if (!self::makePath($temporary . "table")) {
            $reason = "unable-to-write-files";
            return false;
        }
        $packages = array();
        $core = self::installedVersion($web, Manager::CORE);
        if (strlen($core) > 0) {
            $packages[Manager::CORE] = $core;
        }
        $manifest = array(
            "format" => self::FORMAT,
            "formatVersion" => self::FORMAT_VERSION,
            "created" => date("c"),
            "andora" => $core,
            "database" => $type,
            "scope" => $scope,
            "packages" => array(),
            "tables" => array()
        );
        $files = array();
        $index = 0;
        $isOk = true;
        foreach ($tables as $name => $physical) {
            $index++;
            $columns = self::columns($connection, $type, $physical);
            $parts = array();
            $rows = is_null($columns) ? null : self::writeRows($connection, $type, $physical, $temporary, "table/" . sprintf("%04d", $index), $parts);
            if (is_null($rows)) {
                $reason = "database-error";
                Manager::$detail = $name;
                $isOk = false;
                break;
            }
            $code = DataSourceRegistry::owner($web, $name);
            if (!is_null($code) && !array_key_exists($code, $packages)) {
                $version = self::installedVersion($web, $code);
                if (strlen($version) > 0) {
                    $packages[$code] = $version;
                }
            }
            $manifest["tables"][] = array(
                "name" => $name,
                "package" => $code,
                "columns" => $columns,
                "rows" => $rows,
                "parts" => $parts
            );
            foreach ($parts as $part) {
                $files[$part["file"]] = $temporary . $part["file"];
            }
        }
        if ($isOk) {
            ksort($packages);
            $manifest["packages"] = $packages;
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $isOk = is_string($json) && (@file_put_contents($temporary . "backup.json", $json) !== false);
            if (!$isOk) {
                $reason = "unable-to-write-files";
            }
        }
        if ($isOk) {
            $isOk = Zip::write($zipFile, array_merge(array("backup.json" => $temporary . "backup.json"), $files), $reason);
        }
        Installer::removeDir($temporary);
        return $isOk;
    }

    // The rows of a table to JSON lines files $path<base>-<p>.jsonl, their
    // number (null on error); &$parts: list of file, sha256
    protected static function writeRows($connection, $type, $physical, $path, $base, &$parts)
    {
        $result = $connection->query("SELECT * FROM " . self::quote($type, $physical) . ";");
        if (is_null($result)) {
            return null;
        }
        $handle = null;
        $size = 0;
        $count = 0;
        while (true) {
            $row = $result->fetch(\PDO::FETCH_NUM);
            // a new part: the first one, the last one is full
            if (is_null($handle) || (($row !== false) && ($size >= self::PART_SIZE))) {
                if (!is_null($handle)) {
                    fclose($handle);
                    $parts[count($parts) - 1]["sha256"] = hash_file("sha256", $path . $parts[count($parts) - 1]["file"]);
                }
                $file = $base . "-" . sprintf("%03d", count($parts) + 1) . ".jsonl";
                $handle = @fopen($path . $file, "wb");
                if ($handle === false) {
                    return null;
                }
                $parts[] = array("file" => $file, "sha256" => "");
                $size = 0;
            }
            if ($row === false) {
                break;
            }
            foreach ($row as $key => $value) {
                if (is_bool($value)) {
                    $row[$key] = $value ? 1 : 0;
                } elseif (is_string($value) && (preg_match("//u", $value) != 1)) {
                    $row[$key] = array("base64" => base64_encode($value));
                } elseif (is_resource($value)) {
                    $row[$key] = array("base64" => base64_encode("" . stream_get_contents($value)));
                }
            }
            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (!is_string($line) || (fwrite($handle, $line . "\n") === false)) {
                fclose($handle);
                return null;
            }
            $size += strlen($line) + 1;
            $count++;
        }
        $result = null;
        fclose($handle);
        $parts[count($parts) - 1]["sha256"] = hash_file("sha256", $path . $parts[count($parts) - 1]["file"]);
        return $count;
    }

    // Read and check a backup zip: an object file, created, andora, database,
    // scope, packages, tables (name, package, columns, rows, parts: list of
    // file, sha256, entry); null and $reason: invalid-backup, backup-format-newer
    // (Manager::$detail: what is not valid)
    public static function open($zipFile, &$reason)
    {
        Manager::$detail = "";
        $zip = Zip::open($zipFile, $reason);
        if (is_null($zip)) {
            $reason = "invalid-backup";
            return null;
        }
        $entries = array();
        foreach ($zip->entries() as $index => $entry) {
            $entries[$entry["name"]] = array("index" => $index, "size" => $entry["size"], "isDir" => $entry["isDir"], "isEncrypted" => $entry["isEncrypted"]);
        }
        $data = null;
        if (array_key_exists("backup.json", $entries) && ($entries["backup.json"]["size"] <= self::MAX_MANIFEST_SIZE)) {
            $data = json_decode("" . $zip->read($entries["backup.json"]["index"]), true);
        }
        $backup = self::check($data, $entries, $reason);
        if (is_null($backup)) {
            $zip->close();
            return null;
        }
        $backup->file = $zipFile;
        $backup->zip = $zip;
        return $backup;
    }

    protected static function invalid(&$reason, $detail)
    {
        $reason = "invalid-backup";
        Manager::$detail = $detail;
        return null;
    }

    protected static function check($data, $entries, &$reason)
    {
        if (!is_array($data) || (($data["format"] ?? null) !== self::FORMAT) || !is_int($data["formatVersion"] ?? null)) {
            return self::invalid($reason, "backup.json");
        }
        if ($data["formatVersion"] > self::FORMAT_VERSION) {
            $reason = "backup-format-newer";
            Manager::$detail = "" . $data["formatVersion"];
            return null;
        }
        $backup = new \stdClass();
        foreach (array("created", "andora", "database", "scope") as $key) {
            if (!is_string($data[$key] ?? null) || (strlen($data[$key]) > 64)) {
                return self::invalid($reason, $key);
            }
            $backup->$key = $data[$key];
        }
        if (!self::isScope($backup->scope)) {
            return self::invalid($reason, "scope");
        }
        $backup->packages = array();
        if (!is_array($data["packages"] ?? null)) {
            return self::invalid($reason, "packages");
        }
        foreach ($data["packages"] as $code => $version) {
            if (!Manifest::isCode("" . $code) || !is_string($version) || (preg_match(Manifest::VERSION_PATTERN, $version) != 1)) {
                return self::invalid($reason, "packages");
            }
            $backup->packages["" . $code] = $version;
        }
        if (!is_array($data["tables"] ?? null) || (count($data["tables"]) > self::MAX_TABLES)) {
            return self::invalid($reason, "tables");
        }
        $backup->tables = array();
        $names = array();
        foreach ($data["tables"] as $item) {
            $name = is_array($item) ? ($item["name"] ?? null) : null;
            if (!is_string($name) || (preg_match(self::NAME_PATTERN, $name) != 1) || in_array($name, $names, true)) {
                return self::invalid($reason, "table");
            }
            $names[] = $name;
            $table = new \stdClass();
            $table->name = $name;
            $table->package = $item["package"] ?? null;
            if (!is_null($table->package) && !Manifest::isCode($table->package)) {
                return self::invalid($reason, $name);
            }
            $table->columns = $item["columns"] ?? null;
            if (!is_array($table->columns) || (count($table->columns) == 0) || (array_values($table->columns) !== $table->columns)) {
                return self::invalid($reason, $name);
            }
            foreach ($table->columns as $column) {
                if (!is_string($column) || (preg_match(self::NAME_PATTERN, $column) != 1)) {
                    return self::invalid($reason, $name);
                }
            }
            if (count(array_unique($table->columns)) != count($table->columns)) {
                return self::invalid($reason, $name);
            }
            $table->rows = $item["rows"] ?? null;
            $parts = $item["parts"] ?? null;
            if (!is_int($table->rows) || ($table->rows < 0) || !is_array($parts) || (count($parts) == 0) || (count($parts) > self::MAX_PARTS)) {
                return self::invalid($reason, $name);
            }
            $table->parts = array();
            foreach ($parts as $part) {
                $file = is_array($part) ? ($part["file"] ?? null) : null;
                $sha256 = is_array($part) ? ($part["sha256"] ?? null) : null;
                $entry = is_string($file) ? ($entries[$file] ?? null) : null;
                if (is_null($entry) || !is_string($sha256) || (preg_match("/^[0-9a-f]{64}$/", $sha256) != 1) || $entry["isDir"] || $entry["isEncrypted"] || ($entry["size"] > Zip::MAX_ENTRY_SIZE)) {
                    return self::invalid($reason, $name);
                }
                $table->parts[] = (object) array("file" => $file, "sha256" => $sha256, "entry" => $entry["index"]);
            }
            $backup->tables[] = $table;
        }
        return $backup;
    }

    // What a restore of the backup does ($package: all, or only the tables
    // of this package of the backup): a list of objects name, package, rows,
    // action (restore, or skip-excluded, skip-not-selected, skip-missing: the
    // table is not in the database). null and $reason: package-not-in-backup,
    // backup-newer (the data of a package newer than the installed version),
    // nothing-to-restore. Manager::$detail: the package.
    public static function plan($web, $backup, $package, &$reason)
    {
        Manager::$detail = "";
        if (!self::isScope($package)) {
            $reason = "invalid-scope";
            Manager::$detail = "" . $package;
            return null;
        }
        $tables = self::databaseTables($web, $reason);
        if (is_null($tables)) {
            return null;
        }
        $isFound = ($package === self::ALL);
        $list = array();
        $count = 0;
        foreach ($backup->tables as $table) {
            $item = new \stdClass();
            $item->name = $table->name;
            $item->package = $table->package;
            $item->rows = $table->rows;
            $item->action = "restore";
            if (($package !== self::ALL) && ($table->package === $package)) {
                $isFound = true;
            }
            if (in_array($table->name, self::EXCLUDED, true)) {
                $item->action = "skip-excluded";
            } elseif (($package !== self::ALL) && ($table->package !== $package)) {
                $item->action = "skip-not-selected";
            } elseif (!array_key_exists($table->name, $tables)) {
                $item->action = "skip-missing";
            } else {
                $count++;
                // the data of a newer version of the package may not fit the tables
                $code = $table->package;
                if (!is_null($code) && array_key_exists($code, $backup->packages)) {
                    $installed = self::installedVersion($web, $code);
                    if ((strlen($installed) > 0) && (version_compare($backup->packages[$code], $installed) > 0)) {
                        $reason = "backup-newer";
                        Manager::$detail = $code . " " . $backup->packages[$code] . " > " . $installed;
                        return null;
                    }
                }
            }
            $list[] = $item;
        }
        if (!$isFound) {
            $reason = "package-not-in-backup";
            Manager::$detail = $package;
            return null;
        }
        if ($count == 0) {
            $reason = "nothing-to-restore";
            return null;
        }
        return $list;
    }

    // The packages of a backup that have tables in it, sorted
    public static function backupPackages($backup)
    {
        $list = array();
        foreach ($backup->tables as $table) {
            if (!is_null($table->package) && !in_array($table->package, $list, true)) {
                $list[] = $table->package;
            }
        }
        sort($list);
        return $list;
    }

    // Replace the rows of the tables of the backup ($package: all or one
    // package, see plan()). &$restored: the number of tables restored.
    // $reason: the ones of open() and plan(), busy, before-restore-error
    // (the copy of the current rows could not be written), database-error
    public static function restore($web, $zipFile, $package, &$reason, &$restored = 0)
    {
        Manager::$detail = "";
        $restored = 0;
        if (!Installer::lock($reason)) {
            return false;
        }
        $backup = self::open($zipFile, $reason);
        $isOk = !is_null($backup);
        $plan = $isOk ? self::plan($web, $backup, $package, $reason) : null;
        $isOk = $isOk && !is_null($plan);
        if ($isOk) {
            $isOk = self::restoreTables($web, $backup, $plan, $package, $reason, $restored);
            $backup->zip->close();
        }
        Installer::unlock();
        self::log("import", $package, $isOk ? "ok" : "error", $isOk ? "" : $reason, $isOk ? $restored . " tables, " . basename($zipFile) : null);
        return $isOk;
    }

    protected static function restoreTables($web, $backup, $plan, $package, &$reason, &$restored)
    {
        $connection = self::connection($web);
        $type = self::databaseType($web);
        $tables = self::databaseTables($web, $reason);
        if (is_null($connection) || is_null($tables)) {
            $reason = "database-error";
            return false;
        }
        $selected = array();
        foreach ($backup->tables as $index => $table) {
            if ($plan[$index]->action === "restore") {
                $selected[$table->name] = $tables[$table->name];
            }
        }

        // the current rows first
        $scope = ($package === self::ALL) ? $backup->scope : $package;
        $copy = self::freeFile(self::backupPath(), $scope, ".before-restore.zip");
        $copyReason = "";
        if (!self::makePath(self::backupPath()) || !self::exportTables($web, $scope, $selected, $copy, $copyReason)) {
            @unlink($copy);
            $reason = "before-restore-error";
            Manager::$detail = $copyReason;
            return false;
        }
        self::pruneBeforeRestore();

        // SQLite: no statement may be open while the tables change
        gc_collect_cycles();
        if (!$connection->beginTransaction()) {
            $reason = "database-error";
            return false;
        }
        $packages = array();
        foreach ($backup->tables as $table) {
            if (!array_key_exists($table->name, $selected)) {
                continue;
            }
            if (!self::restoreTable($connection, $type, $backup, $table, $selected[$table->name], $reason)) {
                $connection->rollBack();
                Manager::$detail = $table->name . ((strlen(Manager::$detail) > 0) ? " (" . Manager::$detail . ")" : "");
                return false;
            }
            $restored++;
            if (!is_null($table->package) && !in_array($table->package, $packages, true)) {
                $packages[] = $table->package;
            }
        }
        if (!$connection->commit()) {
            $connection->rollBack();
            $reason = "database-error";
            return false;
        }

        // the rows the code of the packages needs
        Manager::clear();
        Acl::reset();
        sort($packages);
        foreach ($packages as $code) {
            $info = Manager::get($web, $code);
            if (is_null($info) || (($code !== Manager::CORE) && !Manager::isActive($web, $code))) {
                continue;
            }
            $seedReason = "";
            $instance = Manager::instance($web, $info, $seedReason);
            try {
                $isSeeded = !is_null($instance) && $instance->seed($seedReason);
            } catch (\Throwable $e) {
                $isSeeded = false;
                $seedReason = $e->getMessage();
            }
            if (!$isSeeded) {
                self::log("seed", $code, "error", $seedReason);
            }
        }
        Manager::clear();
        Acl::reset();
        return true;
    }

    protected static function restoreTable($connection, $type, $backup, $table, $physical, &$reason)
    {
        $reason = "database-error";
        $current = self::columns($connection, $type, $physical);
        if (is_null($current)) {
            return false;
        }
        // the columns of the backup the table still has
        $columns = array();
        foreach ($table->columns as $index => $column) {
            if (in_array($column, $current, true)) {
                $columns[$index] = $column;
            }
        }
        if (is_null($connection->query("DELETE FROM " . self::quote($type, $physical) . ";"))) {
            return false;
        }
        if (($table->rows == 0) || (count($columns) == 0)) {
            return true;
        }
        $names = array();
        foreach ($columns as $column) {
            $names[] = self::quote($type, $column);
        }
        $insert = "INSERT INTO " . self::quote($type, $physical) . " (" . implode(", ", $names) . ") VALUES ";
        $placeholder = "(" . implode(", ", array_fill(0, count($columns), "?")) . ")";
        // SQLite: 999 parameters in a query (before 3.32)
        $batchSize = max(1, min(100, intval(900 / count($columns))));
        $batch = 0;
        $params = array();
        $count = 0;
        $width = count($table->columns);
        foreach ($table->parts as $part) {
            $data = $backup->zip->read($part->entry);
            if (is_null($data) || (hash("sha256", $data) !== $part->sha256)) {
                $reason = "invalid-backup";
                return false;
            }
            $offset = 0;
            $length = strlen($data);
            while ($offset < $length) {
                $end = strpos($data, "\n", $offset);
                if ($end === false) {
                    $end = $length;
                }
                $line = substr($data, $offset, $end - $offset);
                $offset = $end + 1;
                if (strlen($line) == 0) {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row) || (count($row) != $width) || (array_values($row) !== $row)) {
                    $reason = "invalid-backup";
                    return false;
                }
                foreach ($columns as $index => $column) {
                    $value = $row[$index];
                    if (is_array($value)) {
                        $value = (is_string($value["base64"] ?? null) && (count($value) == 1)) ? base64_decode($value["base64"], true) : false;
                        if ($value === false) {
                            $reason = "invalid-backup";
                            return false;
                        }
                    }
                    $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                }
                $batch++;
                $count++;
                if ($batch == $batchSize) {
                    if (is_null($connection->queryPrepare($insert . implode(", ", array_fill(0, $batch, $placeholder)) . ";", $params))) {
                        return false;
                    }
                    $batch = 0;
                    $params = array();
                }
            }
            unset($data);
        }
        if (($batch > 0) && is_null($connection->queryPrepare($insert . implode(", ", array_fill(0, $batch, $placeholder)) . ";", $params))) {
            return false;
        }
        if ($count != $table->rows) {
            $reason = "invalid-backup";
            return false;
        }
        // PostgreSQL: the sequences of the serial columns after the highest value
        if ($type === "postgresql") {
            foreach ($columns as $column) {
                $result = $connection->queryPrepare("SELECT pg_get_serial_sequence(?, ?);", array(self::quote($type, $physical), $column));
                $sequence = is_null($result) ? null : $result->fetchColumn();
                if (is_string($sequence) && (strlen($sequence) > 0)) {
                    $max = "SELECT MAX(" . self::quote($type, $column) . ") FROM " . self::quote($type, $physical);
                    if (is_null($connection->queryPrepare("SELECT setval(?, COALESCE((" . $max . "), 0) + 1, false);", array($sequence)))) {
                        return false;
                    }
                }
            }
        }
        $reason = "";
        return true;
    }

    // The newest KEEP_BEFORE_RESTORE copies made before a restore stay
    protected static function pruneBeforeRestore()
    {
        $list = glob(self::backupPath() . "*.before-restore.zip");
        if (!is_array($list) || (count($list) <= self::KEEP_BEFORE_RESTORE)) {
            return;
        }
        usort($list, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        foreach (array_slice($list, self::KEEP_BEFORE_RESTORE) as $file) {
            @unlink($file);
        }
    }

    // The backups in _repository/database/backup/: objects file, name, size,
    // time, newest first
    public static function backups()
    {
        $list = array();
        foreach (glob(self::backupPath() . "*.zip") ?: array() as $file) {
            $item = new \stdClass();
            $item->file = $file;
            $item->name = basename($file);
            $item->size = filesize($file);
            $item->time = filemtime($file);
            $list[] = $item;
        }
        usort($list, function ($a, $b) {
            return ($b->time <=> $a->time) ?: strcmp($b->name, $a->name);
        });
        return $list;
    }

    // An uploaded backup waiting for the confirmation: checked, copied to
    // import/<id>.zip; its id (32 hex), null and $reason
    public static function stageImport($file, &$reason)
    {
        self::removeOldImports();
        $backup = self::open($file, $reason);
        if (is_null($backup)) {
            return null;
        }
        $backup->zip->close();
        if (!self::makePath(self::importPath())) {
            $reason = "unable-to-write-files";
            return null;
        }
        $id = bin2hex(random_bytes(16));
        if (!@copy($file, self::importPath() . $id . ".zip")) {
            $reason = "unable-to-write-files";
            return null;
        }
        return $id;
    }

    // The file of an uploaded backup, null if there is none
    public static function importFile($id)
    {
        if (!is_string($id) || (preg_match("/^[0-9a-f]{32}$/", $id) != 1)) {
            return null;
        }
        $file = self::importPath() . $id . ".zip";
        return is_file($file) ? $file : null;
    }

    public static function removeImport($id)
    {
        $file = self::importFile($id);
        if (!is_null($file)) {
            @unlink($file);
        }
    }

    protected static function removeOldImports()
    {
        foreach (glob(self::importPath() . "*.zip") ?: array() as $file) {
            if (filemtime($file) < (time() - self::IMPORT_TIMEOUT)) {
                @unlink($file);
            }
        }
    }

    // _log/<date>.database.log ($detail: Manager::$detail when null)
    public static function log($operation, $scope, $result, $reason, $detail = null)
    {
        \XYO\Web\Log::logMessage("database", array(
            "datetime" => date("Y-m-d H:i:s"),
            "user" => Manager::$cli ? "cli" : Acl::currentUserId(),
            "operation" => $operation,
            "scope" => "" . $scope,
            "result" => $result,
            "reason" => "" . $reason,
            "detail" => is_null($detail) ? Manager::$detail : "" . $detail
        ));
    }
}
