<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manifest;

// What the server gives Andora: the PHP version, the extensions, the folders
// PHP can write, the limits of php.ini and the server. Used by the setup
// wizard (the requirements must be met before the first step) and by
// admin/system. Every check returns rows:
//   name   - what is checked (a php.ini key, an extension, a folder, ...)
//   value  - what was found, as text
//   status - STATUS_OK, STATUS_ERROR (a requirement is not met),
//            STATUS_WARNING (works, but should be fixed), STATUS_MISSING
//            (an optional feature is not available), STATUS_INFO
//   note   - a language key of component/language/<lang>/system-check.php
//            ("" for none), its {value} / {limit} replaced by the fields
//            of the same name
class SystemCheck
{
    public const STATUS_OK = "ok";
    public const STATUS_ERROR = "error";
    public const STATUS_WARNING = "warning";
    public const STATUS_MISSING = "missing";
    public const STATUS_INFO = "info";

    // Used if package.json can not be read, keep it the same as its "require" "php"
    public const PHP_REQUIRE = ">=8.0";
    // Older versions do not get security fixes anymore
    public const PHP_SUPPORTED = "8.2.0";

    // The extensions Andora can not work without: name => note
    protected static $requiredExtension = array(
        "pdo" => "ext.pdo",
        "json" => "ext.json",
        "mbstring" => "ext.mbstring",
        "openssl" => "ext.openssl",
        "session" => "ext.session",
        "filter" => "ext.filter",
        "hash" => "ext.hash"
    );

    // One of them is required: extension => database type of the setup
    protected static $databaseDriver = array(
        "pdo_mysql" => "mysql",
        "pdo_pgsql" => "postgresql",
        "pdo_sqlite" => "sqlite"
    );

    // Features that are turned off without them: name => note
    protected static $optionalExtension = array(
        "gd" => "ext.gd",
        "curl" => "ext.curl",
        "zlib" => "ext.zlib",
        "zip" => "ext.zip",
        "intl" => "ext.intl",
        "fileinfo" => "ext.fileinfo",
        "Zend OPcache" => "ext.opcache"
    );

    // The folders PHP must write (relative to the site root, "" = the site root): name => required
    protected static $writableFolder = array(
        "" => true,
        "_repository" => true,
        "_log" => true,
        "_site" => false
    );

    protected static function row($name, $value, $status, $note = "", $limit = "")
    {
        return array(
            "name" => "" . $name,
            "value" => "" . $value,
            "status" => $status,
            "note" => $note,
            "limit" => "" . $limit
        );
    }

    public static function phpVersionString()
    {
        return PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;
    }

    // The "require" "php" of andora/web/package.json
    public static function phpRequire()
    {
        $file = ANDORA_PATH . "_site/andora/web/package.json";
        $json = @file_get_contents($file);
        if ($json !== false) {
            $manifest = json_decode($json, true);
            if (is_array($manifest) && isset($manifest["require"]["php"]) && is_string($manifest["require"]["php"])) {
                return $manifest["require"]["php"];
            }
        }
        return self::PHP_REQUIRE;
    }

    public static function checkPHP()
    {
        $version = self::phpVersionString();
        $require = self::phpRequire();
        if (!Manifest::versionMatches($version, $require)) {
            return array(self::row("PHP", $version, self::STATUS_ERROR, "php.too-old", $require));
        }
        if (version_compare($version, self::PHP_SUPPORTED, "<")) {
            return array(self::row("PHP", $version, self::STATUS_WARNING, "php.end-of-life", self::PHP_SUPPORTED));
        }
        return array(self::row("PHP", $version, self::STATUS_OK, "", $require));
    }

    public static function checkRequiredExtension()
    {
        $list = array();
        foreach (self::$requiredExtension as $name => $note) {
            $isLoaded = extension_loaded($name);
            $list[] = self::row($name, $isLoaded ? phpversion($name) : "", $isLoaded ? self::STATUS_OK : self::STATUS_ERROR, $note);
        }
        return $list;
    }

    public static function hasDatabaseDriver($databaseType)
    {
        foreach (self::$databaseDriver as $extension => $type) {
            if ($type === $databaseType) {
                return extension_loaded($extension);
            }
        }
        return false;
    }

    // At least one driver is required, the missing ones are not an error then
    public static function checkDatabaseDriver()
    {
        $hasOne = false;
        foreach (self::$databaseDriver as $extension => $type) {
            $hasOne = $hasOne || extension_loaded($extension);
        }
        $list = array();
        foreach (self::$databaseDriver as $extension => $type) {
            $isLoaded = extension_loaded($extension);
            $status = $isLoaded ? self::STATUS_OK : ($hasOne ? self::STATUS_MISSING : self::STATUS_ERROR);
            $list[] = self::row($extension, $isLoaded ? phpversion($extension) : "", $status, "driver." . $type);
        }
        return $list;
    }

    public static function checkOptionalExtension()
    {
        $list = array();
        foreach (self::$optionalExtension as $name => $note) {
            $isLoaded = extension_loaded($name);
            $version = $isLoaded ? phpversion($name) : "";
            if ($name == "Zend OPcache") {
                // Loaded, but off for the web server
                if ($isLoaded && !filter_var(ini_get("opcache.enable"), FILTER_VALIDATE_BOOLEAN)) {
                    $list[] = self::row($name, $version, self::STATUS_MISSING, "ext.opcache-disabled");
                    continue;
                }
            }
            $list[] = self::row($name, $version, $isLoaded ? self::STATUS_OK : self::STATUS_MISSING, $note);
        }
        return $list;
    }

    // Write and remove a file: is_writable() is not reliable with ACLs,
    // open_basedir or a web server running as another user
    public static function canWrite($folder)
    {
        if (!is_dir($folder)) {
            return false;
        }
        $file = rtrim($folder, "/\\") . "/.andora-write-test-" . bin2hex(random_bytes(6));
        if (@file_put_contents($file, "test") !== 4) {
            return false;
        }
        @unlink($file);
        return true;
    }

    public static function checkWritable()
    {
        $list = array();
        foreach (self::$writableFolder as $folder => $isRequired) {
            $path = ANDORA_PATH . $folder;
            $name = strlen($folder) ? $folder . "/" : "./";
            if (!is_dir($path)) {
                $list[] = self::row($name, "", $isRequired ? self::STATUS_ERROR : self::STATUS_WARNING, "folder.not-found");
                continue;
            }
            $value = self::permissionString($path);
            if (self::canWrite($path)) {
                $list[] = self::row($name, $value, self::STATUS_OK, "folder." . (strlen($folder) ? $folder : "root"));
                continue;
            }
            $list[] = self::row($name, $value, $isRequired ? self::STATUS_ERROR : self::STATUS_WARNING, "folder.not-writable." . (strlen($folder) ? $folder : "root"));
        }

        // The files written later (admin/settings, package operations, the database)
        $fileList = glob(ANDORA_PATH . "config.*.php");
        if (!is_array($fileList)) {
            $fileList = array();
        }
        $sqlite = ANDORA_PATH . "_repository/andora.sqlite";
        if (is_file($sqlite)) {
            $fileList[] = $sqlite;
        }
        foreach ($fileList as $file) {
            if (!is_writable($file)) {
                $list[] = self::row(substr($file, strlen(ANDORA_PATH)), self::permissionString($file), self::STATUS_ERROR, "file.not-writable");
            }
        }

        // Uploads and sessions
        $temp = sys_get_temp_dir();
        $uploadTemp = "" . ini_get("upload_tmp_dir");
        if (strlen($uploadTemp) == 0) {
            $uploadTemp = $temp;
        }
        $list[] = self::row("upload_tmp_dir", $uploadTemp, self::canWrite($uploadTemp) ? self::STATUS_OK : self::STATUS_WARNING, self::canWrite($uploadTemp) ? "" : "folder.upload-temp");
        if (ini_get("session.save_handler") === "files") {
            $sessionPath = "" . session_save_path();
            // "N;/path" or "N;MODE;/path"
            if (strpos($sessionPath, ";") !== false) {
                $parts = explode(";", $sessionPath);
                $sessionPath = end($parts);
            }
            if (strlen($sessionPath) == 0) {
                $sessionPath = $temp;
            }
            $isOk = self::canWrite($sessionPath);
            $list[] = self::row("session.save_path", $sessionPath, $isOk ? self::STATUS_OK : self::STATUS_ERROR, $isOk ? "" : "folder.session");
        }
        return $list;
    }

    protected static function permissionString($path)
    {
        $permission = @fileperms($path);
        if ($permission === false) {
            return "";
        }
        return substr(sprintf("%o", $permission), -4);
    }

    // php.ini size ("128M", "1G", "-1") in bytes, -1 = no limit
    public static function iniBytes($value)
    {
        $value = trim("" . $value);
        if ((strlen($value) == 0) || ($value === "-1")) {
            return -1;
        }
        $number = intval($value);
        $unit = strtolower(substr($value, -1));
        if ($unit == "g") {
            $number *= 1024 * 1024 * 1024;
        } else if ($unit == "m") {
            $number *= 1024 * 1024;
        } else if ($unit == "k") {
            $number *= 1024;
        }
        return $number;
    }

    public static function formatBytes($bytes)
    {
        if ($bytes < 0) {
            return "-1";
        }
        $unit = array("B", "KB", "MB", "GB", "TB");
        $index = 0;
        $value = 1.0 * $bytes;
        while (($value >= 1024) && ($index < count($unit) - 1)) {
            $value /= 1024;
            $index++;
        }
        return (($index == 0) ? intval($value) : round($value, 1)) . " " . $unit[$index];
    }

    protected static function iniFlag($name)
    {
        return filter_var(ini_get($name), FILTER_VALIDATE_BOOLEAN);
    }

    public static function checkLimit()
    {
        $list = array();

        $memory = self::iniBytes(ini_get("memory_limit"));
        $status = (($memory >= 0) && ($memory < 64 * 1024 * 1024)) ? self::STATUS_WARNING : self::STATUS_OK;
        $list[] = self::row("memory_limit", ini_get("memory_limit"), $status, ($status == self::STATUS_OK) ? "" : "limit.memory", "128M");

        $upload = self::iniBytes(ini_get("upload_max_filesize"));
        $post = self::iniBytes(ini_get("post_max_size"));
        if (!self::iniFlag("file_uploads")) {
            $list[] = self::row("file_uploads", "Off", self::STATUS_WARNING, "limit.file-uploads");
        }
        $status = (($upload >= 0) && ($upload < 8 * 1024 * 1024)) ? self::STATUS_WARNING : self::STATUS_OK;
        $list[] = self::row("upload_max_filesize", ini_get("upload_max_filesize"), $status, ($status == self::STATUS_OK) ? "limit.upload" : "limit.upload-small", "64M");
        // post_max_size 0 = no limit
        $status = (($post > 0) && ($upload > 0) && ($post < $upload)) ? self::STATUS_WARNING : self::STATUS_OK;
        $list[] = self::row("post_max_size", ini_get("post_max_size"), $status, ($status == self::STATUS_OK) ? "" : "limit.post", ini_get("upload_max_filesize"));
        $list[] = self::row("max_file_uploads", ini_get("max_file_uploads"), self::STATUS_INFO);

        $time = intval(ini_get("max_execution_time"));
        $status = (($time > 0) && ($time < 30)) ? self::STATUS_WARNING : self::STATUS_OK;
        $list[] = self::row("max_execution_time", $time, $status, ($status == self::STATUS_OK) ? "" : "limit.time", "30");
        $list[] = self::row("max_input_time", ini_get("max_input_time"), self::STATUS_INFO);
        $list[] = self::row("max_input_vars", ini_get("max_input_vars"), self::STATUS_INFO);

        $isOn = self::iniFlag("display_errors");
        $list[] = self::row("display_errors", $isOn ? "On" : "Off", $isOn ? self::STATUS_WARNING : self::STATUS_OK, $isOn ? "limit.display-errors" : "");
        $list[] = self::row("log_errors", self::iniFlag("log_errors") ? "On" : "Off", self::STATUS_INFO);
        $errorLog = "" . ini_get("error_log");
        $list[] = self::row("error_log", $errorLog, self::STATUS_INFO, strlen($errorLog) ? "" : "limit.error-log");

        $timezone = "" . ini_get("date.timezone");
        $list[] = self::row("date.timezone", strlen($timezone) ? $timezone : date_default_timezone_get(), strlen($timezone) ? self::STATUS_OK : self::STATUS_WARNING, strlen($timezone) ? "" : "limit.timezone");
        $list[] = self::row("default_charset", ini_get("default_charset"), (strtoupper("" . ini_get("default_charset")) == "UTF-8") ? self::STATUS_OK : self::STATUS_WARNING, (strtoupper("" . ini_get("default_charset")) == "UTF-8") ? "" : "limit.charset");

        $baseDir = "" . ini_get("open_basedir");
        $list[] = self::row("open_basedir", $baseDir, self::STATUS_INFO, strlen($baseDir) ? "limit.open-basedir" : "");
        $disabled = trim("" . ini_get("disable_functions"));
        $list[] = self::row("disable_functions", str_replace(",", ", ", $disabled), self::STATUS_INFO);
        $list[] = self::row("allow_url_fopen", self::iniFlag("allow_url_fopen") ? "On" : "Off", self::STATUS_INFO);

        if (extension_loaded("Zend OPcache") && self::iniFlag("opcache.enable")) {
            $isOn = self::iniFlag("opcache.validate_timestamps");
            $list[] = self::row("opcache.validate_timestamps", $isOn ? "On" : "Off", $isOn ? self::STATUS_OK : self::STATUS_WARNING, $isOn ? "" : "limit.opcache-timestamps");
        }
        return $list;
    }

    // The user PHP runs as and the owner of the files: when they are not the
    // same, PHP usually can not write the files uploaded by FTP
    protected static function processUser()
    {
        if (function_exists("posix_geteuid") && function_exists("posix_getpwuid")) {
            $user = @posix_getpwuid(posix_geteuid());
            if (is_array($user) && isset($user["name"])) {
                return $user["name"];
            }
        }
        if (function_exists("getenv")) {
            $user = getenv("USERNAME");
            if (is_string($user) && strlen($user)) {
                return $user;
            }
        }
        return "";
    }

    protected static function fileOwner($path)
    {
        $owner = @fileowner($path);
        if ($owner === false) {
            return "";
        }
        if (function_exists("posix_getpwuid")) {
            $user = @posix_getpwuid($owner);
            if (is_array($user) && isset($user["name"])) {
                return $user["name"];
            }
        }
        // Windows has no owner id
        return ($owner == 0) ? "" : "" . $owner;
    }

    public static function checkServer($web)
    {
        $list = array();
        $list[] = self::row("Andora", ANDORA_VERSION_STR, self::STATUS_INFO);
        $list[] = self::row("PHP SAPI", php_sapi_name(), self::STATUS_INFO);
        $list[] = self::row("Server", isset($_SERVER["SERVER_SOFTWARE"]) ? $_SERVER["SERVER_SOFTWARE"] : "", self::STATUS_INFO);
        $os = PHP_OS_FAMILY;
        if (function_exists("php_uname")) {
            $os = php_uname("s") . " " . php_uname("r") . " " . php_uname("m");
        }
        $list[] = self::row("OS", $os, self::STATUS_INFO);

        $user = self::processUser();
        $owner = self::fileOwner(ANDORA_PATH . "index.php");
        $list[] = self::row("PHP user", $user, self::STATUS_INFO);
        if (strlen($owner)) {
            $isSame = (strlen($user) == 0) || ($user === $owner);
            $list[] = self::row("Files owner", $owner, $isSame ? self::STATUS_OK : self::STATUS_WARNING, $isSame ? "" : "server.owner");
        }

        $list[] = self::row("Site folder", ANDORA_PATH, self::STATUS_INFO);
        $list[] = self::row("DOCUMENT_ROOT", isset($_SERVER["DOCUMENT_ROOT"]) ? $_SERVER["DOCUMENT_ROOT"] : "", self::STATUS_INFO);

        $isHTTPS = (!empty($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] !== "off"));
        $proxy = isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) ? strtolower("" . $_SERVER["HTTP_X_FORWARDED_PROTO"]) : "";
        if ($isHTTPS) {
            $list[] = self::row("HTTPS", "On", self::STATUS_OK);
        } else if ($proxy == "https") {
            $list[] = self::row("HTTPS", "Off (X-Forwarded-Proto: https)", self::STATUS_WARNING, "server.https-proxy");
        } else {
            $list[] = self::row("HTTPS", "Off", self::STATUS_WARNING, "server.https");
        }

        // The base of the links sent by e-mail, saved at the end of the setup
        $config = $web->get(\XYO\Web\Config::class);
        $info = $web->get(\XYO\Web\Info::class);
        $scheme = ($isHTTPS || ($proxy == "https")) ? "https" : "http";
        $host = isset($_SERVER["HTTP_HOST"]) ? preg_replace("/[^a-zA-Z0-9\\.\\-:\\[\\]]/", "", $_SERVER["HTTP_HOST"]) : "localhost";
        $currentURL = $scheme . "://" . $host . $info->site;
        $siteURL = trim("" . $config->get("siteURL", ""));
        if (strlen($siteURL)) {
            $siteURL = rtrim($siteURL, "/") . "/";
            $isSame = (strcasecmp($siteURL, $currentURL) == 0);
            $list[] = self::row("siteURL", $siteURL, $isSame ? self::STATUS_OK : self::STATUS_WARNING, $isSame ? "" : "server.site-url", $currentURL);
        } else {
            $list[] = self::row("Site URL", $currentURL, self::STATUS_INFO);
        }

        $list[] = self::row("Time", date("Y-m-d H:i:s T"), self::STATUS_INFO);
        if (function_exists("disk_free_space")) {
            $free = @disk_free_space(ANDORA_PATH);
            if ($free !== false) {
                $status = ($free < 100 * 1024 * 1024) ? self::STATUS_WARNING : self::STATUS_INFO;
                $list[] = self::row("Free disk space", self::formatBytes($free), $status, ($status == self::STATUS_INFO) ? "" : "server.disk");
            }
        }
        return $list;
    }

    // The database of the site (after the setup)
    public static function checkDatabase($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("dataSource")) {
            return array();
        }
        $connection = $config->get("dataSource")->getArray("connection");
        $db = (isset($connection["db"]) && is_array($connection["db"])) ? $connection["db"] : array();
        $field = function ($name) use ($db) {
            return (isset($db[$name]) && is_scalar($db[$name])) ? "" . $db[$name] : "";
        };
        $type = $field("type");
        if (strlen($type) == 0) {
            return array();
        }
        $list = array();
        $list[] = self::row("Type", $type, self::STATUS_INFO);
        $sql = array(
            "sqlite" => "SELECT sqlite_version()",
            "mysql" => "SELECT VERSION()",
            "postgresql" => "SHOW server_version"
        );
        $version = "";
        $isOpen = false;
        try {
            $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
            $dbConnection = $dsConnection->get();
            $isOpen = !is_null($dbConnection) && $dbConnection->open();
            if ($isOpen && array_key_exists($type, $sql)) {
                $statement = $dbConnection->query($sql[$type]);
                if ($statement) {
                    $version = "" . $statement->fetchColumn();
                }
            }
        } catch (\Throwable $e) {
            $isOpen = false;
        }
        $list[] = self::row("Connection", $isOpen ? "OK" : "", $isOpen ? self::STATUS_OK : self::STATUS_ERROR, $isOpen ? "" : "database.connection");
        if (strlen($version)) {
            $list[] = self::row("Version", $version, self::STATUS_INFO);
        }
        if ($type != "sqlite") {
            $list[] = self::row("Server", $field("server") . ":" . $field("port"), self::STATUS_INFO);
            $list[] = self::row("Database", $field("database"), self::STATUS_INFO);
        }
        $list[] = self::row("Table prefix", $field("prefix"), self::STATUS_INFO);
        if ($type == "sqlite") {
            $file = ANDORA_PATH . "_repository/andora.sqlite";
            if (is_file($file)) {
                $list[] = self::row("File size", self::formatBytes(filesize($file)), self::STATUS_INFO);
            }
        }
        return $list;
    }

    // The newest log files of _log/: name, size, time
    public static function logFiles($count = 10)
    {
        $files = glob(ANDORA_PATH . "_log/*.log");
        if (!is_array($files)) {
            return array();
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $list = array();
        foreach (array_slice($files, 0, $count) as $file) {
            $list[] = array(
                "name" => basename($file),
                "size" => self::formatBytes(filesize($file)),
                "time" => date("Y-m-d H:i:s", filemtime($file))
            );
        }
        return $list;
    }

    // The last lines of the newest _log/<date>.router.log (the errors behind a 501)
    public static function lastRouterLog(&$fileName, $lines = 40)
    {
        $fileName = "";
        $files = glob(ANDORA_PATH . "_log/*.router.log");
        if (!is_array($files) || (count($files) == 0)) {
            return "";
        }
        rsort($files);
        $fileName = basename($files[0]);
        $size = filesize($files[0]);
        $handle = @fopen($files[0], "rb");
        if ($handle === false) {
            return "";
        }
        // The end of the file is enough
        $length = 65536;
        if ($size > $length) {
            fseek($handle, $size - $length);
        }
        $content = fread($handle, $length);
        fclose($handle);
        $content = str_replace("\r\n", "\n", "" . $content);
        $list = explode("\n", rtrim($content));
        return implode("\n", array_slice($list, -$lines));
    }

    // The requirements of the setup: PHP, the required extensions, one
    // database driver and the required folders
    public static function hasErrors($list)
    {
        foreach ($list as $row) {
            if ($row["status"] === self::STATUS_ERROR) {
                return true;
            }
        }
        return false;
    }

    public static function isReady()
    {
        return !self::hasErrors(array_merge(
            self::checkPHP(),
            self::checkRequiredExtension(),
            self::checkDatabaseDriver(),
            self::checkWritable()
        ));
    }
}
