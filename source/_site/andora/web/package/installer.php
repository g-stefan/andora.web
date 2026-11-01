<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// The files of the packages: a zip is extracted to a staging folder and
// checked (stage), then the package folder is replaced by it (commit), the
// old folder kept as a backup (restore). The migrations of the new files run
// in the next request (Manager::migrate), never in the request that swapped
// the folders. Everything is under _repository/package/ (not served):
//   staging/<id>/_site/<vendor>/<name>/   a checked upload, waiting for commit
//   backup/<vendor>.<name>/<time>-<n>-<version>/   older folders of a package
//   backup/<vendor>.<name>/database-<time>-<version>.sqlite   SQLite before an upgrade
//
// A zip has the layout of the site: every file under one _site/<vendor>/<name>/.
class Installer
{
    public const MAX_ENTRIES = 10000;
    public const MAX_TOTAL_SIZE = 268435456; // 256 MB uncompressed
    public const KEEP_BACKUPS = 2;
    public const STAGE_LIFETIME = 3600;
    // never written by a package
    public const FORBIDDEN_NAMES = array(".htaccess", ".htpasswd", ".user.ini", "php.ini", "web.config");

    protected static $lockHandle = null;
    protected static $lockCount = 0;

    public static function repositoryPath()
    {
        return ANDORA_PATH . "_repository/package/";
    }

    public static function stagingPath()
    {
        return self::repositoryPath() . "staging/";
    }

    public static function backupPath($code)
    {
        return self::repositoryPath() . "backup/" . str_replace("/", ".", $code) . "/";
    }

    protected static function makePath($path)
    {
        return is_dir($path) || @mkdir($path, 0777, true);
    }

    // One file operation / migration at a time (re-entrant in a request)
    public static function lock(&$reason)
    {
        if (self::$lockCount > 0) {
            self::$lockCount++;
            return true;
        }
        if (!self::makePath(self::repositoryPath())) {
            $reason = "unable-to-write-files";
            return false;
        }
        $handle = @fopen(self::repositoryPath() . "lock", "c");
        if ($handle === false) {
            $reason = "unable-to-write-files";
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $reason = "busy";
            return false;
        }
        self::$lockHandle = $handle;
        self::$lockCount = 1;
        return true;
    }

    public static function unlock()
    {
        if (self::$lockCount == 0) {
            return;
        }
        self::$lockCount--;
        if (self::$lockCount == 0) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    public static function removeDir($path)
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        foreach (scandir($path) as $name) {
            if (($name === ".") || ($name === "..")) {
                continue;
            }
            self::removeDir($path . "/" . $name);
        }
        return @rmdir($path);
    }

    // rename() of a folder, tried again for up to 2 seconds: on Windows a
    // folder whose files were just written or read (a virus scanner, the
    // indexer) can not be renamed for a moment
    public static function renameFolder($from, $to)
    {
        for ($k = 0; $k < 20; $k++) {
            if (@rename($from, $to)) {
                return true;
            }
            clearstatcache();
            if (!file_exists($from) || file_exists($to)) {
                return false;
            }
            usleep(100000);
        }
        return false;
    }

    // The new PHP files are loaded by the next requests
    protected static function resetCache()
    {
        clearstatcache();
        if (function_exists("opcache_reset")) {
            @opcache_reset();
        }
    }

    // A name of the zip that can be written: _site/<vendor>/<name>/<path>.
    // Returns [vendor, name, path] (path "" for a folder entry above or equal to
    // the package folder), null if the name is refused.
    public static function checkEntryName($name, $isDir)
    {
        if (!is_string($name) || (strlen($name) == 0) || (strlen($name) > 1024)) {
            return null;
        }
        if (preg_match("/[\\x00-\\x1F\\x7F\\\\:]/", $name)) {
            return null;
        }
        if ($isDir) {
            $name = substr($name, 0, -1);
        }
        $part = explode("/", $name);
        foreach ($part as $segment) {
            if (($segment === "") || ($segment === ".") || ($segment === "..")) {
                return null;
            }
            if (in_array(strtolower($segment), self::FORBIDDEN_NAMES, true)) {
                return null;
            }
        }
        if ($part[0] !== "_site") {
            return null;
        }
        if (count($part) == 1) {
            return $isDir ? array("", "", "") : null;
        }
        if (!Manifest::isName($part[1]) || Manifest::isReservedVendor($part[1])) {
            return null;
        }
        if (count($part) == 2) {
            return $isDir ? array($part[1], "", "") : null;
        }
        if (!Manifest::isName($part[2])) {
            return null;
        }
        if (count($part) == 3) {
            return $isDir ? array($part[1], $part[2], "") : null;
        }
        return array($part[1], $part[2], implode("/", array_slice($part, 3)));
    }

    // Extract and check a zip. Returns the stage (id, code, manifest, path)
    // or null and $reason: invalid-zip, zip-not-supported, too-large,
    // invalid-entry (Manager::$detail = the entry), several-packages,
    // empty-package, no-manifest / invalid-manifest / ... (package.json)
    public static function stage($web, $zipFile, &$reason)
    {
        Manager::$detail = "";
        if (!Manager::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return null;
        }
        self::removeOldStages();

        $zip = Zip::open($zipFile, $reason);
        if (is_null($zip)) {
            return null;
        }
        $entries = $zip->entries();
        if (count($entries) > self::MAX_ENTRIES) {
            $zip->close();
            $reason = "too-large";
            return null;
        }

        $vendor = null;
        $name = null;
        $total = 0;
        $files = array();
        foreach ($entries as $index => $entry) {
            $check = self::checkEntryName($entry["name"], $entry["isDir"]);
            if (is_null($check) || $entry["isLink"] || $entry["isEncrypted"]) {
                $zip->close();
                $reason = "invalid-entry";
                Manager::$detail = substr("" . $entry["name"], 0, 128);
                return null;
            }
            if ($check[0] === "") {
                continue;
            }
            if (is_null($vendor)) {
                $vendor = $check[0];
            }
            if ($check[0] !== $vendor) {
                $zip->close();
                $reason = "several-packages";
                return null;
            }
            if ($check[1] === "") {
                continue;
            }
            if (is_null($name)) {
                $name = $check[1];
            }
            if ($check[1] !== $name) {
                $zip->close();
                $reason = "several-packages";
                return null;
            }
            if ($entry["isDir"]) {
                continue;
            }
            $total += $entry["size"];
            if (($entry["size"] > Zip::MAX_ENTRY_SIZE) || ($total > self::MAX_TOTAL_SIZE)) {
                $zip->close();
                $reason = "too-large";
                return null;
            }
            $files[$index] = $check[2];
        }
        if (is_null($name) || (count($files) == 0)) {
            $zip->close();
            $reason = "empty-package";
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $stagePath = self::stagingPath() . $id . "/";
        $packagePath = $stagePath . "_site/" . $vendor . "/" . $name . "/";
        if (!self::makePath($packagePath)) {
            $zip->close();
            $reason = "unable-to-write-files";
            return null;
        }
        foreach ($files as $index => $path) {
            $data = $zip->read($index);
            if (is_null($data)) {
                $zip->close();
                self::removeDir($stagePath);
                $reason = "invalid-zip";
                Manager::$detail = substr("" . $entries[$index]["name"], 0, 128);
                return null;
            }
            $target = $packagePath . $path;
            if (!self::makePath(dirname($target)) || (file_put_contents($target, $data) === false)) {
                $zip->close();
                self::removeDir($stagePath);
                $reason = "unable-to-write-files";
                return null;
            }
        }
        $zip->close();

        $manifest = Manifest::load($packagePath, $vendor, $name, $reason);
        if (is_null($manifest)) {
            self::removeDir($stagePath);
            Manager::$detail = $vendor . "/" . $name;
            return null;
        }
        return self::loadStage($id, $web);
    }

    // A checked upload: id, code, vendor, name, version, manifest, path, and
    // with $web its signature (Signature::verify) and decision
    // (Signature::decide: accept, confirm or a refusal reason); null if gone
    public static function loadStage($id, $web = null)
    {
        if (!is_string($id) || (preg_match("/^[0-9a-f]{32}$/", $id) != 1)) {
            return null;
        }
        $stagePath = self::stagingPath() . $id . "/";
        $vendors = @scandir($stagePath . "_site/");
        if ($vendors === false) {
            return null;
        }
        foreach ($vendors as $vendor) {
            if (!Manifest::isName($vendor)) {
                continue;
            }
            foreach (scandir($stagePath . "_site/" . $vendor . "/") as $name) {
                if (!Manifest::isName($name)) {
                    continue;
                }
                $path = $stagePath . "_site/" . $vendor . "/" . $name . "/";
                $reason = "";
                $manifest = Manifest::load($path, $vendor, $name, $reason);
                if (is_null($manifest)) {
                    return null;
                }
                $stage = new \stdClass();
                $stage->id = $id;
                $stage->code = $manifest["code"];
                $stage->vendor = $vendor;
                $stage->name = $name;
                $stage->version = $manifest["version"];
                $stage->title = $manifest["title"];
                $stage->manifest = $manifest;
                $stage->path = $path;
                $stage->signature = null;
                $stage->decision = "";
                if (!is_null($web)) {
                    $stage->signature = Signature::verify($web, $path, $vendor, $name);
                    $stage->decision = Signature::decide($web, $vendor, $stage->signature);
                }
                return $stage;
            }
        }
        return null;
    }

    public static function removeStage($id)
    {
        if (is_string($id) && (preg_match("/^[0-9a-f]{32}$/", $id) == 1)) {
            self::removeDir(self::stagingPath() . $id);
        }
    }

    protected static function removeOldStages()
    {
        $list = @scandir(self::stagingPath());
        if ($list === false) {
            return;
        }
        foreach ($list as $id) {
            if (preg_match("/^[0-9a-f]{32}$/", $id) && (filemtime(self::stagingPath() . $id) < time() - self::STAGE_LIFETIME)) {
                self::removeDir(self::stagingPath() . $id);
            }
        }
    }

    // What the commit of a stage does: install (new package), upgrade (newer
    // than the installed version), replace (same version: the files are
    // copied again) or downgrade (refused: older than the installed version)
    public static function stageAction($web, $stage)
    {
        $info = Manager::get($web, $stage->code);
        if (is_null($info) || !$info->isInstalled) {
            return "install";
        }
        $compare = version_compare($stage->version, $info->installedVersion);
        if ($compare < 0) {
            return "downgrade";
        }
        return ($compare == 0) ? "replace" : "upgrade";
    }

    // Replace the package folder with the stage, the old folder goes to the backups.
    // $trusted: "I trust this package" for an unsigned one (decision confirm).
    public static function commit($web, $id, &$reason, $trusted = false)
    {
        Manager::$detail = "";
        if (!Manager::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return false;
        }
        $stage = self::loadStage($id, $web);
        if (is_null($stage)) {
            $reason = "stage-not-found";
            return false;
        }
        if (self::stageAction($web, $stage) === "downgrade") {
            $reason = "downgrade";
            return false;
        }
        if (($stage->decision !== "accept") && ($stage->decision !== "confirm")) {
            $reason = $stage->decision;
            Manager::$detail = $stage->signature->keyName;
            Manager::log($stage->code, $stage->version, "files", "error", $reason);
            return false;
        }
        if (($stage->decision === "confirm") && !$trusted) {
            $reason = "confirm-unsigned";
            return false;
        }
        if (!self::lock($reason)) {
            return false;
        }
        $isOk = self::replaceFolder($stage->code, $stage->path, $reason);
        if ($isOk) {
            self::removeDir(self::stagingPath() . $id);
        }
        self::unlock();
        Manager::$detail = "signature: " . $stage->signature->state . ((strlen($stage->signature->keyName) > 0) ? " (" . $stage->signature->keyName . ")" : "");
        Manager::log($stage->code, $stage->version, "files", $isOk ? "ok" : "error", $isOk ? "" : $reason);
        Manager::$detail = "";
        Manager::clear();
        return $isOk;
    }

    // Move the package folder (if any) to the backups and $from in its place
    protected static function replaceFolder($code, $from, &$reason)
    {
        $part = explode("/", $code);
        $vendorPath = Manager::sitePath() . $part[0] . "/";
        $target = $vendorPath . $part[1];
        if (!self::makePath($vendorPath)) {
            $reason = "unable-to-write-files";
            return false;
        }
        if (!is_file($vendorPath . "index.html") && is_file(Manager::sitePath() . "index.html")) {
            @copy(Manager::sitePath() . "index.html", $vendorPath . "index.html");
        }
        $backup = null;
        if (is_dir($target)) {
            $backup = self::moveToBackup($code, $reason);
            if (is_null($backup)) {
                return false;
            }
        }
        if (!self::renameFolder(rtrim($from, "/"), $target)) {
            if (!is_null($backup)) {
                self::renameFolder($backup, $target);
            }
            $reason = "unable-to-write-files";
            return false;
        }
        self::resetCache();
        self::pruneBackups($code);
        return true;
    }

    // Move _site/<vendor>/<name> to backup/<vendor>.<name>/<time>-<n>-<version>,
    // returns the backup folder
    public static function moveToBackup($code, &$reason)
    {
        $part = explode("/", $code);
        $source = Manager::sitePath() . $part[0] . "/" . $part[1];
        if (!is_dir($source)) {
            $reason = "files-missing";
            return null;
        }
        $version = "unknown";
        $manifestReason = "";
        $manifest = Manifest::load($source . "/", $part[0], $part[1], $manifestReason);
        if (!is_null($manifest)) {
            $version = $manifest["version"];
        }
        $path = self::backupPath($code);
        if (!self::makePath($path)) {
            $reason = "unable-to-write-files";
            return null;
        }
        // <time>-<microseconds>: the backups made in the same second keep their order
        $backup = $path . date("YmdHis") . "-" . sprintf("%06d", intval(substr(microtime(false), 2, 6))) . "-" . $version;
        while (file_exists($backup)) {
            usleep(10);
            $backup = $path . date("YmdHis") . "-" . sprintf("%06d", intval(substr(microtime(false), 2, 6))) . "-" . $version;
        }
        if (!self::renameFolder($source, $backup)) {
            $reason = "unable-to-write-files";
            return null;
        }
        self::resetCache();
        return $backup;
    }

    // Folders of the backups of a package, the newest first: [folder, version]
    public static function backups($code)
    {
        $list = array();
        $scan = @scandir(self::backupPath($code));
        if ($scan === false) {
            return $list;
        }
        foreach ($scan as $name) {
            // <time>-<microseconds>-<version> (and <time>-<4 hex>-<version> of 0.4.0)
            if (preg_match("/^[0-9]{14}-([0-9]{6}|[0-9a-f]{4})-(.+)$/", $name, $match) && is_dir(self::backupPath($code) . $name)) {
                $list[] = array($name, $match[2]);
            }
        }
        usort($list, function ($a, $b) {
            return strcmp($b[0], $a[0]);
        });
        return $list;
    }

    // The KEEP_BACKUPS newest folders and database copies are kept
    protected static function pruneBackups($code)
    {
        foreach (array_slice(self::backups($code), self::KEEP_BACKUPS) as $backup) {
            self::removeDir(self::backupPath($code) . $backup[0]);
        }
        $databases = glob(self::backupPath($code) . "database-*.sqlite");
        if (is_array($databases)) {
            rsort($databases);
            foreach (array_slice($databases, self::KEEP_BACKUPS) as $file) {
                @unlink($file);
            }
        }
    }

    // Put the newest backup back, the current folder (if any) becomes a backup
    public static function restore($web, $code, &$reason)
    {
        Manager::$detail = "";
        if (!Manager::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return false;
        }
        $backups = self::backups($code);
        if (count($backups) == 0) {
            $reason = "no-backup";
            return false;
        }
        if (!self::lock($reason)) {
            return false;
        }
        $from = self::backupPath($code) . $backups[0][0];
        // The folder is renamed first, a replaced folder must not become the newest backup
        $temporary = self::backupPath($code) . "restore-" . bin2hex(random_bytes(4));
        $isOk = self::renameFolder($from, $temporary);
        if (!$isOk) {
            $reason = "unable-to-write-files";
        } else {
            $isOk = self::replaceFolder($code, $temporary, $reason);
            if (!$isOk) {
                self::renameFolder($temporary, $from);
            }
        }
        self::unlock();
        Manager::log($code, $backups[0][1], "restore", $isOk ? "ok" : "error", $isOk ? "" : $reason);
        Manager::clear();
        return $isOk;
    }

    // SQLite only: a copy of the database before the migrations of $code
    public static function backupDatabase($web, $code, $fromVersion)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("dataSource")) {
            return true;
        }
        $db = $config->get("dataSource")->get("connection")->get("db");
        if (("" . $db->get("type", "")) !== "sqlite") {
            return true;
        }
        $file = "" . $db->get("database", "");
        if (!is_file($file)) {
            // relative to the site root (the CLI may run from another folder)
            $file = ANDORA_PATH . preg_replace("#^\./#", "", $file);
            if (!is_file($file)) {
                return true;
            }
        }
        if (!self::makePath(self::backupPath($code))) {
            return false;
        }
        gc_collect_cycles();
        $isOk = @copy($file, self::backupPath($code) . "database-" . date("YmdHis") . "-" . $fromVersion . ".sqlite");
        self::pruneBackups($code);
        return $isOk;
    }

    // A zip of the package folder (_site/<vendor>/<name>/...) written to $zipFile
    public static function export($web, $code, $zipFile, &$reason)
    {
        Manager::$detail = "";
        if (!Manager::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return false;
        }
        $info = Manager::get($web, $code);
        if (!Manager::canOperate($info, "export")) {
            $reason = "invalid-state";
            return false;
        }
        $files = array();
        $base = Manager::sitePath() . $info->vendor . "/" . $info->name;
        self::listFiles($base, "_site/" . $info->vendor . "/" . $info->name, $files);
        if (count($files) > self::MAX_ENTRIES) {
            $reason = "too-large";
            return false;
        }
        if (!Zip::write($zipFile, $files, $reason)) {
            return false;
        }
        Manager::log($code, $info->version, "export", "ok", "");
        return true;
    }

    protected static function listFiles($path, $name, &$files)
    {
        foreach (scandir($path) as $item) {
            if (($item === ".") || ($item === "..") || is_link($path . "/" . $item)) {
                continue;
            }
            if (is_dir($path . "/" . $item)) {
                self::listFiles($path . "/" . $item, $name . "/" . $item, $files);
                continue;
            }
            $files[$name . "/" . $item] = $path . "/" . $item;
        }
    }

    // <vendor>.<name>.v<version>.zip (the name of the framework zips)
    public static function exportFileName($info)
    {
        return $info->vendor . "." . $info->name . ".v" . $info->version . ".zip";
    }
}
