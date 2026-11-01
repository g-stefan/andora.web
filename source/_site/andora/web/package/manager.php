<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TablePackage;
use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\DataSource\TableAdminMenu;
use \Andora\Web\DataSource\TableUserXPermission;
use \Andora\Web\DataSource\TableUserGroupXPermission;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Setup;

// The packages: the folders _site/<vendor>/<name>/ with a package.json,
// compared with the package table (the version whose install / upgrade ran
// last). To update a package its folder is deleted and replaced, then
// upgrade() runs the migrations of the new version.
//
// Every operation returns true / false, sets &$reason to a short code and
// Manager::$detail to what the reason is about (a package, an extension).
class Manager
{
    public const CORE = "andora/web";

    public const STATE_UNMANAGED = "unmanaged"; // no package.json (framework folders)
    public const STATE_BROKEN = "broken"; // invalid package.json
    public const STATE_NOT_INSTALLED = "not-installed";
    public const STATE_INSTALLED = "installed"; // enabled, the files are the installed version
    public const STATE_DISABLED = "disabled";
    public const STATE_UPGRADE = "upgrade"; // the files are newer: upgrade pending
    public const STATE_DOWNGRADE = "downgrade"; // the files are older: restore the newer files
    public const STATE_MISSING = "missing"; // installed, the folder is gone
    public const STATE_REMOVED = "removed"; // uninstalled, only the backups are left (restore)

    // The mounts of the packages (not andora/web: main.php mounts it), written
    // after every operation
    public const ROUTE_CONFIG = "config.08.route.php";

    // code => info, once per request
    protected static $list = null;
    // code => Base
    protected static $instance = array();

    public static $detail = "";

    // The registry of the last discover (writeRoutes after an operation)
    protected static $web = null;

    // Set by the command line (Service): the file operations are always allowed
    public static $cli = false;

    // Forget the packages found (after changes made in the same request)
    public static function clear()
    {
        self::$list = null;
        self::$instance = array();
    }

    // Folder of the packages
    public static function sitePath()
    {
        return ANDORA_PATH . "_site/";
    }

    // Folder names that can be a vendor or a package name ($isBackup: <vendor>.<name>)
    protected static function folderNames($path, $isBackup = false)
    {
        $list = array();
        $scan = @scandir($path);
        if ($scan === false) {
            return $list;
        }
        foreach ($scan as $name) {
            $isName = $isBackup ? Manifest::isCode(str_replace(".", "/", $name)) : Manifest::isName($name);
            if ($isName && is_dir($path . $name)) {
                $list[] = $name;
            }
        }
        return $list;
    }

    // Version of a framework folder that has no package.json yet
    protected static function knownVersion($code)
    {
        if (($code === "xyo/web") && defined("XYO_WEB_VERSION_STR")) {
            return XYO_WEB_VERSION_STR;
        }
        return "";
    }

    protected static function newInfo($vendor, $name)
    {
        $info = new \stdClass();
        $info->code = $vendor . "/" . $name;
        $info->vendor = $vendor;
        $info->name = $name;
        $info->manifest = null;
        $info->version = "";
        $info->title = $info->code;
        $info->description = "";
        $info->protected = false;
        $info->hasFiles = false;
        $info->isInstalled = false;
        $info->installedVersion = "";
        $info->enabled = false;
        $info->error = "";
        $info->state = "";
        $info->backups = array();
        return $info;
    }

    // The package folders, without the database
    public static function scan()
    {
        $list = array();
        $site = self::sitePath();
        foreach (self::folderNames($site) as $vendor) {
            if (Manifest::isReservedVendor($vendor)) {
                continue;
            }
            foreach (self::folderNames($site . $vendor . "/") as $name) {
                $info = self::newInfo($vendor, $name);
                $info->hasFiles = true;
                $reason = "";
                $manifest = Manifest::load($site . $vendor . "/" . $name . "/", $vendor, $name, $reason);
                if (is_null($manifest)) {
                    if ($reason === "no-manifest") {
                        $info->state = self::STATE_UNMANAGED;
                        $info->version = self::knownVersion($info->code);
                    } else {
                        $info->state = self::STATE_BROKEN;
                        $info->error = $reason;
                    }
                } else {
                    $info->manifest = $manifest;
                    $info->version = $manifest["version"];
                    $info->title = $manifest["title"];
                    $info->description = $manifest["description"];
                    $info->protected = $manifest["protected"];
                }
                $list[$info->code] = $info;
            }
        }
        return $list;
    }

    // code => row of the package table
    protected static function records($web)
    {
        $records = array();
        try {
            $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
            $dsPackage = $dsConnection->getDataSource(TablePackage::class);
            $dsPackage->clear();
            for ($dsPackage->load(); $dsPackage->loadIsValid(); $dsPackage->loadNext()) {
                $records["" . $dsPackage->code] = array(
                    "version" => "" . $dsPackage->version,
                    "enabled" => intval($dsPackage->enabled) == 1,
                    "error" => "" . $dsPackage->error
                );
            }
        } catch (\Throwable $e) {
            // No package table yet (before the setup / the upgrade to schema 7)
        }
        return $records;
    }

    // code => info (code, vendor, name, manifest, version, title, description,
    // protected, hasFiles, isInstalled, installedVersion, enabled, error, state), sorted by code
    public static function discover($web)
    {
        self::$web = $web;
        if (!is_null(self::$list)) {
            return self::$list;
        }
        $list = self::scan();
        foreach (self::records($web) as $code => $record) {
            if (!array_key_exists($code, $list)) {
                if (!Manifest::isCode($code)) {
                    continue;
                }
                $part = explode("/", $code);
                $list[$code] = self::newInfo($part[0], $part[1]);
            }
            $info = $list[$code];
            $info->isInstalled = true;
            $info->installedVersion = $record["version"];
            $info->enabled = $record["enabled"];
            if (strlen($info->error) == 0) {
                $info->error = $record["error"];
            }
        }
        // Uninstalled packages that can be restored (backup/<vendor>.<name>/)
        foreach (self::folderNames(Installer::repositoryPath() . "backup/", true) as $folder) {
            $part = explode(".", $folder, 2);
            if ((count($part) == 2) && !array_key_exists($part[0] . "/" . $part[1], $list) && (count(Installer::backups($part[0] . "/" . $part[1])) > 0)) {
                $list[$part[0] . "/" . $part[1]] = self::newInfo($part[0], $part[1]);
            }
        }
        foreach ($list as $info) {
            $info->backups = Installer::backups($info->code);
            if (!$info->hasFiles) {
                $info->state = $info->isInstalled ? self::STATE_MISSING : self::STATE_REMOVED;
                continue;
            }
            if ($info->state === self::STATE_BROKEN) {
                continue;
            }
            if (is_null($info->manifest)) {
                // A package installed before can not lose its package.json
                if ($info->isInstalled) {
                    $info->state = self::STATE_BROKEN;
                    $info->error = "no-manifest";
                }
                continue;
            }
            if (!$info->isInstalled) {
                $info->state = self::STATE_NOT_INSTALLED;
                continue;
            }
            $compare = version_compare($info->version, $info->installedVersion);
            if ($compare > 0) {
                $info->state = self::STATE_UPGRADE;
                continue;
            }
            if ($compare < 0) {
                $info->state = self::STATE_DOWNGRADE;
                continue;
            }
            $info->state = $info->enabled ? self::STATE_INSTALLED : self::STATE_DISABLED;
        }
        ksort($list);
        self::$list = $list;
        return $list;
    }

    // <vendor>/<name> as one name: <vendor>_<name> (request ids, row ids)
    public static function idFromCode($code)
    {
        return str_replace("/", "_", $code);
    }

    // null if it is not a package code
    public static function codeFromId($id)
    {
        if (!is_string($id)) {
            return null;
        }
        $code = str_replace("_", "/", $id);
        return Manifest::isCode($code) ? $code : null;
    }

    public static function get($web, $code)
    {
        $list = self::discover($web);
        if (!is_string($code) || !array_key_exists($code, $list)) {
            return null;
        }
        return $list[$code];
    }

    // The package is installed, enabled and its files are the installed version
    public static function isActive($web, $code)
    {
        $info = self::get($web, $code);
        return !is_null($info) && ($info->state === self::STATE_INSTALLED);
    }

    // Upload, restore, export and uninstall write code: config package.upload
    // (default true) turns them off, except on the command line
    public static function isFileOperationAllowed($web)
    {
        if (self::$cli) {
            return true;
        }
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("package")) {
            return true;
        }
        return $config->get("package")->get("upload", true) ? true : false;
    }

    // Operation possible in the state of the package: install, upgrade, reset,
    // enable, disable, uninstall, restore, export
    public static function canOperate($info, $operation)
    {
        if (is_null($info)) {
            return false;
        }
        switch ($operation) {
            case "restore":
                return count($info->backups) > 0;
            case "uninstall":
                return !$info->protected && ($info->state !== self::STATE_UNMANAGED) && ($info->isInstalled || $info->hasFiles);
        }
        if (is_null($info->manifest) || !$info->hasFiles) {
            return false;
        }
        switch ($operation) {
            case "install":
                return $info->state === self::STATE_NOT_INSTALLED;
            case "upgrade":
                return $info->state === self::STATE_UPGRADE;
            case "reset":
                return !$info->protected && (($info->state === self::STATE_INSTALLED) || ($info->state === self::STATE_DISABLED));
            case "enable":
                return $info->state === self::STATE_DISABLED;
            case "disable":
                return !$info->protected && ($info->state === self::STATE_INSTALLED);
            case "export":
                return true;
        }
        return false;
    }

    // The requirements of a manifest and whether this site meets them, in the
    // order they are checked: a list of objects reason (requires-php,
    // requires-extension, requires-package), name (php >=8.1, ext-openssl,
    // andora/web >=0.4), detail (what checkRequire reports), current (the
    // version found, empty when none), isOk
    public static function requirements($web, $manifest)
    {
        $list = array();
        $require = $manifest["require"];
        $php = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;
        if (strlen($require["php"]) > 0) {
            $list[] = self::requirement("requires-php", "php " . $require["php"], "php " . $require["php"], $php, Manifest::versionMatches($php, $require["php"]));
        }
        foreach ($require["extension"] as $extension) {
            $isLoaded = extension_loaded($extension);
            $version = $isLoaded ? phpversion($extension) : false;
            $list[] = self::requirement("requires-extension", "ext-" . $extension, $extension, $isLoaded ? (($version === false) ? "loaded" : $version) : "", $isLoaded);
        }
        $packages = self::discover($web);
        foreach ($require["package"] as $code => $constraint) {
            $isOk = false;
            $current = "";
            if (array_key_exists($code, $packages)) {
                $required = $packages[$code];
                if ($required->state === self::STATE_UNMANAGED) {
                    $isOk = (strlen($required->version) == 0) || Manifest::versionMatches($required->version, $constraint);
                    $current = $required->version;
                }
                if ($required->state === self::STATE_INSTALLED) {
                    $isOk = Manifest::versionMatches($required->version, $constraint);
                    $current = $required->version;
                }
            }
            $name = trim($code . " " . $constraint);
            $list[] = self::requirement("requires-package", $name, $name, $current, $isOk);
        }
        return $list;
    }

    protected static function requirement($reason, $name, $detail, $current, $isOk)
    {
        $item = new \stdClass();
        $item->reason = $reason;
        $item->name = $name;
        $item->detail = $detail;
        $item->current = "" . $current;
        $item->isOk = $isOk;
        return $item;
    }

    // The manifest requirements are met (the first one that is not: $reason, $detail)
    public static function checkManifestRequire($web, $manifest, &$reason)
    {
        foreach (self::requirements($web, $manifest) as $item) {
            if (!$item->isOk) {
                $reason = $item->reason;
                self::$detail = $item->detail;
                return false;
            }
        }
        return true;
    }

    // PHP version, extensions and packages the manifest requires
    public static function checkRequire($web, $info, &$reason)
    {
        return self::checkManifestRequire($web, $info->manifest, $reason);
    }

    // The installed packages (only the enabled ones: $enabledOnly) that require $code
    public static function dependents($web, $code, $enabledOnly = true)
    {
        $list = array();
        foreach (self::discover($web) as $info) {
            if (is_null($info->manifest) || !$info->isInstalled || ($enabledOnly && !$info->enabled)) {
                continue;
            }
            if (array_key_exists($code, $info->manifest["require"]["package"])) {
                $list[] = $info->code;
            }
        }
        return $list;
    }

    // The lifecycle object of the package (Base or the class package.php returns)
    public static function instance($web, $info, &$reason)
    {
        if (array_key_exists($info->code, self::$instance)) {
            return self::$instance[$info->code];
        }
        $class = Base::class;
        $file = self::sitePath() . $info->vendor . "/" . $info->name . "/package.php";
        if (is_file($file)) {
            $class = include($file);
            if (!is_string($class) || !class_exists($class) || !is_a($class, Base::class, true)) {
                $reason = "invalid-package-class";
                self::$detail = $info->code;
                return null;
            }
        }
        self::$instance[$info->code] = new $class($web, $info);
        return self::$instance[$info->code];
    }

    public static function install($web, $code, &$reason)
    {
        self::$detail = "";
        $info = self::get($web, $code);
        if (!self::canOperate($info, "install")) {
            $reason = "invalid-state";
            return false;
        }
        if (!self::checkRequire($web, $info, $reason) || !self::checkRoutes($web, $info, $reason)) {
            return self::fail($info, "install", $reason);
        }
        if (!Installer::lock($reason)) {
            return false;
        }
        $package = self::instance($web, $info, $reason);
        $isOk = !is_null($package) && EventRegistry::check($web, $info, $package, $reason) && DataSourceRegistry::check($web, $info, $package, $reason) && $package->check($reason) && $package->install($reason);
        Installer::unlock();
        if (!$isOk) {
            return self::fail($info, "install", $reason);
        }
        if (!self::saveRecord($web, $code, $info->version, true, "")) {
            $reason = "database-error";
            return self::fail($info, "install", $reason);
        }
        return self::done($info, "install");
    }

    // Run the migrations of the newer files
    public static function upgrade($web, $code, &$reason)
    {
        self::$detail = "";
        $info = self::get($web, $code);
        if (!self::canOperate($info, "upgrade")) {
            $reason = "invalid-state";
            return false;
        }
        if (!self::checkRequire($web, $info, $reason) || !self::checkRoutes($web, $info, $reason)) {
            return self::fail($info, "upgrade", $reason);
        }
        if (!Installer::lock($reason)) {
            return false;
        }
        $package = self::instance($web, $info, $reason);
        $isOk = !is_null($package) && EventRegistry::check($web, $info, $package, $reason) && DataSourceRegistry::check($web, $info, $package, $reason) && $package->check($reason);
        if ($isOk && !Installer::backupDatabase($web, $code, $info->installedVersion)) {
            $reason = "database-backup-error";
            $isOk = false;
        }
        $isOk = $isOk && $package->upgrade($info->installedVersion, $reason);
        Installer::unlock();
        if (!$isOk) {
            // The installed version stays, the reason is shown in the list
            self::saveRecord($web, $code, $info->installedVersion, $info->enabled, $reason);
            return self::fail($info, "upgrade", $reason);
        }
        if (!self::saveRecord($web, $code, $info->version, $info->enabled, "")) {
            $reason = "database-error";
            return self::fail($info, "upgrade", $reason);
        }
        return self::done($info, "upgrade");
    }

    // Back to the state right after install, the data of the package is lost
    public static function reset($web, $code, &$reason)
    {
        self::$detail = "";
        $info = self::get($web, $code);
        if (!self::canOperate($info, "reset")) {
            $reason = "invalid-state";
            return false;
        }
        if (!Installer::lock($reason)) {
            return false;
        }
        $package = self::instance($web, $info, $reason);
        $isOk = !is_null($package) && $package->reset($reason);
        Installer::unlock();
        if (!$isOk) {
            return self::fail($info, "reset", $reason);
        }
        return self::done($info, "reset");
    }

    public static function enable($web, $code, &$reason)
    {
        self::$detail = "";
        $info = self::get($web, $code);
        if (!self::canOperate($info, "enable")) {
            $reason = "invalid-state";
            return false;
        }
        if (!self::checkRequire($web, $info, $reason) || !self::checkRoutes($web, $info, $reason)) {
            return self::fail($info, "enable", $reason);
        }
        $package = self::instance($web, $info, $reason);
        if (is_null($package) || !EventRegistry::check($web, $info, $package, $reason) || !DataSourceRegistry::check($web, $info, $package, $reason) || !$package->enable($reason)) {
            return self::fail($info, "enable", $reason);
        }
        if (!self::setTaggedEnabled($web, $code, true) || !self::saveRecord($web, $code, $info->installedVersion, true, $info->error)) {
            $reason = "database-error";
            return self::fail($info, "enable", $reason);
        }
        return self::done($info, "enable");
    }

    // The permissions and the admin menu entries of the package are disabled too
    public static function disable($web, $code, &$reason)
    {
        self::$detail = "";
        $info = self::get($web, $code);
        if (!self::canOperate($info, "disable")) {
            $reason = "invalid-state";
            return false;
        }
        $dependents = self::dependents($web, $code);
        if (count($dependents) > 0) {
            $reason = "required-by";
            self::$detail = implode(", ", $dependents);
            return self::fail($info, "disable", $reason);
        }
        $package = self::instance($web, $info, $reason);
        if (is_null($package) || !$package->disable($reason)) {
            return self::fail($info, "disable", $reason);
        }
        if (!self::setTaggedEnabled($web, $code, false) || !self::saveRecord($web, $code, $info->installedVersion, false, $info->error)) {
            $reason = "database-error";
            return self::fail($info, "disable", $reason);
        }
        return self::done($info, "disable");
    }

    // Remove a package: the uninstall hook (when its files are there), the
    // permissions and menu entries it registered and its record, then its
    // folder goes to the backups. $keepData: the tables stay, the record stays
    // disabled (Files missing): the same files uploaded again bring it back.
    // A package found but not installed only has its folder moved.
    public static function uninstall($web, $code, $keepData, &$reason)
    {
        self::$detail = "";
        if (!self::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return false;
        }
        $info = self::get($web, $code);
        if (!self::canOperate($info, "uninstall")) {
            $reason = "invalid-state";
            return false;
        }
        $dependents = self::dependents($web, $code, false);
        if (count($dependents) > 0) {
            $reason = "required-by";
            self::$detail = implode(", ", $dependents);
            return self::fail($info, "uninstall", $reason);
        }
        if (!Installer::lock($reason)) {
            return false;
        }
        $isOk = true;
        if ($info->isInstalled && $info->hasFiles && !is_null($info->manifest)) {
            $package = self::instance($web, $info, $reason);
            $isOk = !is_null($package) && $package->uninstall($keepData, $reason);
        }
        if ($isOk && $info->isInstalled) {
            if ($keepData && $info->hasFiles) {
                $isOk = self::setTaggedEnabled($web, $code, false) && self::saveRecord($web, $code, $info->installedVersion, false, $info->error);
            } else {
                $isOk = self::removeTagged($web, $code) && self::deleteRecord($web, $code);
            }
            if (!$isOk && (strlen($reason) == 0)) {
                $reason = "database-error";
            }
        }
        if ($isOk && $info->hasFiles) {
            $isOk = !is_null(Installer::moveToBackup($code, $reason));
        }
        Installer::unlock();
        if (!$isOk) {
            return self::fail($info, "uninstall", $reason);
        }
        return self::done($info, "uninstall");
    }

    // After the files of a package were replaced (in the request after it):
    // install a new package, upgrade a newer one, nothing for the same version.
    // $operation: install, upgrade or replace.
    public static function migrate($web, $code, &$reason, &$operation)
    {
        self::$detail = "";
        $operation = "";
        $info = self::get($web, $code);
        if (is_null($info)) {
            $reason = "invalid-state";
            return false;
        }
        switch ($info->state) {
            case self::STATE_NOT_INSTALLED:
                $operation = "install";
                return self::install($web, $code, $reason);
            case self::STATE_UPGRADE:
                $operation = "upgrade";
                return self::upgrade($web, $code, $reason);
            case self::STATE_INSTALLED:
            case self::STATE_DISABLED:
                // the files of the installed version again (a failed upgrade was undone)
                $operation = "replace";
                if ((strlen($info->error) > 0) && !self::saveRecord($web, $code, $info->installedVersion, $info->enabled, "")) {
                    $reason = "database-error";
                    return false;
                }
                // new files of the same version: their routes, events and tables may differ
                if (!self::checkRoutes($web, $info, $reason)) {
                    return self::fail($info, "replace", $reason);
                }
                $package = self::instance($web, $info, $reason);
                if (is_null($package) || !EventRegistry::check($web, $info, $package, $reason) || !DataSourceRegistry::check($web, $info, $package, $reason)) {
                    return self::fail($info, "replace", $reason);
                }
                return self::done($info, "replace");
        }
        $reason = "invalid-state";
        return false;
    }

    // Setup: andora/web first (creates the database), then every package found,
    // each one after the packages it requires. A package that can not be
    // installed is left for the admin page, only andora/web must succeed.
    public static function installAll($web, &$reason)
    {
        self::$detail = "";
        self::clear();
        // the routes, the events and the tables are written after andora/web too
        self::$web = $web;
        $list = self::scan();
        if (!array_key_exists(self::CORE, $list) || is_null($list[self::CORE]->manifest)) {
            $reason = "core-package-missing";
            return false;
        }
        $core = $list[self::CORE];
        $package = self::instance($web, $core, $reason);
        if (is_null($package) || !$package->install($reason)) {
            return self::fail($core, "install", $reason);
        }
        if (!self::saveRecord($web, self::CORE, $core->version, true, "")) {
            $reason = "database-error";
            return self::fail($core, "install", $reason);
        }
        self::done($core, "install");

        do {
            $progress = false;
            foreach (self::discover($web) as $code => $info) {
                if ($info->state !== self::STATE_NOT_INSTALLED) {
                    continue;
                }
                $packageReason = "";
                if (!self::checkRequire($web, $info, $packageReason)) {
                    continue;
                }
                if (self::install($web, $code, $packageReason)) {
                    $progress = true;
                }
            }
        } while ($progress);

        foreach (self::discover($web) as $info) {
            if ($info->state === self::STATE_NOT_INSTALLED) {
                $packageReason = "";
                self::checkRequire($web, $info, $packageReason);
                self::log($info->code, $info->version, "install", "skipped", $packageReason);
            }
        }
        return true;
    }

    // andora/web: on every admin request (after the schema upgrade of
    // admin/authorization.php) the files newer than the installed version are
    // upgraded at once, the admin area needs them.
    public static function syncCore($web, &$reason)
    {
        $vendorName = explode("/", self::CORE);
        $manifest = Manifest::load(self::sitePath() . self::CORE . "/", $vendorName[0], $vendorName[1], $reason);
        if (is_null($manifest)) {
            $reason = "core-package-missing";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsPackage = $dsConnection->getDataSource(TablePackage::class);
        $dsPackage->clear();
        $dsPackage->code = self::CORE;
        $fromVersion = "";
        if ($dsPackage->load(0, 1)) {
            $fromVersion = "" . $dsPackage->version;
            if (version_compare($manifest["version"], $fromVersion) <= 0) {
                return true;
            }
        }

        self::clear();
        $info = self::get($web, self::CORE);
        $package = self::instance($web, $info, $reason);
        if (is_null($package) || !$package->upgrade($fromVersion, $reason)) {
            if (strlen($fromVersion) > 0) {
                self::saveRecord($web, self::CORE, $fromVersion, true, $reason);
            }
            return self::fail($info, "upgrade", $reason);
        }
        if (!self::saveRecord($web, self::CORE, $info->version, true, "")) {
            $reason = "database-error";
            return self::fail($info, "upgrade", $reason);
        }
        self::done($info, "upgrade");
        self::installFramework($web);
        return true;
    }

    // The packages of the framework (vendor xyo) found but not installed, for
    // a site made before they had a package.json
    protected static function installFramework($web)
    {
        foreach (self::discover($web) as $code => $info) {
            if (($info->vendor === "xyo") && ($info->state === self::STATE_NOT_INSTALLED)) {
                $reason = "";
                self::install($web, $code, $reason);
            }
        }
    }

    // The active packages (installed, enabled, files of the installed version),
    // sorted by code. Only the package table and the package.json of the
    // enabled packages are read (no scan of _site/ nor of the backups, unless
    // discover already ran in this request). Safe before the package table
    // exists (setup, older database): none.
    public static function active($web)
    {
        $list = array();
        try {
            if (!is_null(self::$list)) {
                foreach (self::$list as $info) {
                    if ($info->state === self::STATE_INSTALLED) {
                        $list[] = $info;
                    }
                }
                return $list;
            }
            self::$web = $web;
            $records = self::records($web);
            ksort($records);
            foreach ($records as $code => $record) {
                if (!$record["enabled"] || !Manifest::isCode($code)) {
                    continue;
                }
                $part = explode("/", $code);
                if (Manifest::isReservedVendor($part[0])) {
                    continue;
                }
                $reason = "";
                $manifest = Manifest::load(self::sitePath() . $code . "/", $part[0], $part[1], $reason);
                if (is_null($manifest) || (version_compare($manifest["version"], $record["version"]) != 0)) {
                    continue;
                }
                $list[] = self::activeInfo($manifest, $record["version"], $record["error"]);
            }
        } catch (\Throwable $e) {
            self::log("", "", "active", "error", $e->getMessage());
        }
        return $list;
    }

    // The info of an active package (installed, enabled, files of the
    // installed version) from its checked manifest
    public static function activeInfo($manifest, $installedVersion, $error = "")
    {
        $info = self::newInfo($manifest["vendor"], $manifest["name"]);
        $info->manifest = $manifest;
        $info->version = $manifest["version"];
        $info->title = $manifest["title"];
        $info->description = $manifest["description"];
        $info->protected = $manifest["protected"];
        $info->hasFiles = true;
        $info->isInstalled = true;
        $info->installedVersion = "" . $installedVersion;
        $info->enabled = true;
        $info->error = "" . $error;
        $info->state = self::STATE_INSTALLED;
        return $info;
    }

    // The panels of the active packages for /admin/dashboard (Base::dashboardPanels)
    // that the current user may see, an array id => object id, code, version,
    // name, title, description, icon, size (1-3), isDefault, render (callable
    // or null), component (class or null); in the order of the packages
    // (sorted by code), then of the panels. A package whose dashboardPanels()
    // fails is logged and left out, a panel that is not valid is left out.
    public static function dashboardPanels($web)
    {
        $list = array();
        foreach (self::active($web) as $info) {
            $reason = "";
            try {
                $package = self::instance($web, $info, $reason);
                if (is_null($package)) {
                    continue;
                }
                $panels = $package->dashboardPanels();
                if (!is_array($panels)) {
                    continue;
                }
                foreach ($panels as $name => $panel) {
                    if (!is_string($name) || !Manifest::isName($name)) {
                        continue;
                    }
                    if (!is_array($panel) || !is_string($panel["title"] ?? null)) {
                        continue;
                    }
                    $permission = $panel["permission"] ?? "";
                    if (is_string($permission) && (strlen($permission) > 0) && !Acl::can($web, $permission)) {
                        continue;
                    }
                    $item = new \stdClass();
                    $item->id = $info->code . "/" . $name;
                    $item->code = $info->code;
                    $item->version = $info->version;
                    $item->name = $name;
                    $item->title = $panel["title"];
                    $item->description = is_string($panel["description"] ?? null) ? $panel["description"] : "";
                    $item->icon = Manifest::isName($panel["icon"] ?? null) ? $panel["icon"] : "";
                    $item->size = self::panelSize($panel["size"] ?? 1);
                    $item->isDefault = (($panel["default"] ?? false) === true);
                    $item->render = null;
                    $item->component = null;
                    if (array_key_exists("render", $panel) && is_callable($panel["render"])) {
                        $item->render = $panel["render"];
                    } elseif (is_string($panel["component"] ?? null) && is_subclass_of($panel["component"], \XYO\Web\Component::class)) {
                        $item->component = $panel["component"];
                    } else {
                        continue;
                    }
                    $list[$item->id] = $item;
                }
            } catch (\Throwable $e) {
                self::log($info->code, $info->version, "dashboardPanels", "error", $e->getMessage());
            }
        }
        return $list;
    }

    // The size of a panel: 1 - one column, 2 - two columns, 3 - the full row
    public static function panelSize($size)
    {
        $size = intval($size);
        return (($size >= 1) && ($size <= 3)) ? $size : 1;
    }

    // The packages that need the attention of an administrator: files newer
    // than the installed version, a failed last upgrade, files missing, older
    // files, broken
    public static function needAttention($web)
    {
        $list = array();
        foreach (self::discover($web) as $info) {
            $states = array(self::STATE_UPGRADE, self::STATE_MISSING, self::STATE_DOWNGRADE, self::STATE_BROKEN);
            if (in_array($info->state, $states, true) || ($info->isInstalled && (strlen($info->error) > 0))) {
                $list[] = $info;
            }
        }
        return $list;
    }

    protected static function saveRecord($web, $code, $version, $enabled, $error)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsPackage = $dsConnection->getDataSource(TablePackage::class);
        $dsPackage->clear();
        $dsPackage->code = $code;
        $now = date("Y-m-d H:i:s");
        if (!$dsPackage->load(0, 1)) {
            $dsPackage->clear();
            $dsPackage->code = $code;
            $dsPackage->installed_at = $now;
        }
        $dsPackage->version = "" . $version;
        $dsPackage->enabled = $enabled ? 1 : 0;
        $dsPackage->error = substr("" . $error, 0, 128);
        $dsPackage->updated_at = $now;
        return $dsPackage->save() ? true : false;
    }

    protected static function deleteRecord($web, $code)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsPackage = $dsConnection->getDataSource(TablePackage::class);
        $dsPackage->clear();
        $dsPackage->code = $code;
        return $dsPackage->delete() ? true : false;
    }

    // Delete the permissions (and their grants) and admin menu entries tagged with the package
    protected static function removeTagged($web, $code)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsPermission = $dsConnection->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->package = $code;
        $ids = array();
        for ($dsPermission->load(); $dsPermission->loadIsValid(); $dsPermission->loadNext()) {
            $ids[] = intval($dsPermission->id);
        }
        foreach ($ids as $id) {
            foreach (array(TableUserXPermission::class, TableUserGroupXPermission::class) as $dsClass) {
                $dsLink = $dsConnection->getDataSource($dsClass);
                $dsLink->clear();
                $dsLink->permission__id = $id;
                $dsLink->delete();
            }
            $dsPermission->clear();
            $dsPermission->id = $id;
            if (!$dsPermission->delete()) {
                return false;
            }
        }
        $dsMenu = $dsConnection->getDataSource(TableAdminMenu::class);
        $dsMenu->clear();
        $dsMenu->package = $code;
        $dsMenu->delete();
        Acl::reset();
        return true;
    }

    // enabled of the permissions and admin menu entries tagged with the package
    protected static function setTaggedEnabled($web, $code, $enabled)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        foreach (array(TablePermission::class, TableAdminMenu::class) as $dsClass) {
            $ds = $dsConnection->getDataSource($dsClass);
            $ds->clear();
            $ds->package = $code;
            $ids = array();
            for ($ds->load(); $ds->loadIsValid(); $ds->loadNext()) {
                $ids[] = intval($ds->id);
            }
            foreach ($ids as $id) {
                $ds->clear();
                $ds->id = $id;
                if (!$ds->load(0, 1)) {
                    return false;
                }
                $ds->enabled = $enabled ? 1 : 0;
                if (!$ds->save()) {
                    return false;
                }
            }
        }
        Acl::reset();
        return true;
    }

    protected static function done($info, $operation)
    {
        self::log(is_null($info) ? "" : $info->code, is_null($info) ? "" : $info->version, $operation, "ok", "");
        self::clear();
        Acl::reset();
        $reason = "";
        if (!self::writeRoutes(self::$web, $reason)) {
            self::log("", "", "routes", "error", $reason);
        }
        $reason = "";
        if (!EventRegistry::write(self::$web, $reason)) {
            self::log("", "", "events", "error", $reason);
        }
        $reason = "";
        if (!DataSourceRegistry::write(self::$web, $reason)) {
            self::log("", "", "datasource", "error", $reason);
        }
        return true;
    }

    // The routes of a package (manifest route: path => folder) can be mounted:
    // the folders exist, no other package, andora/web or folder of the site
    // root uses the same path. $reason: route-folder-missing, route-conflict.
    public static function checkRoutes($web, $info, &$reason)
    {
        $core = self::get($web, self::CORE);
        foreach ($info->manifest["route"] as $prefix => $folder) {
            if (!is_dir(self::sitePath() . $info->vendor . "/" . $info->name . "/" . $folder)) {
                $reason = "route-folder-missing";
                self::$detail = $folder;
                return false;
            }
            $isUsed = is_dir(ANDORA_PATH . $prefix);
            foreach (self::discover($web) as $other) {
                if (($other->code === $info->code) || is_null($other->manifest)) {
                    continue;
                }
                $isMounted = ($other->code === self::CORE) || ($other->isInstalled && $other->enabled && $other->hasFiles);
                if ($isMounted && array_key_exists($prefix, $other->manifest["route"])) {
                    $isUsed = true;
                }
            }
            if ($isUsed) {
                $reason = "route-conflict";
                self::$detail = $prefix;
                return false;
            }
        }
        return true;
    }

    // config.08.route.php: route.mount of the enabled packages with files
    // (installed, or with an upgrade pending: their routes answer 503 until
    // the upgrade, see \Andora\Web\Authorization) and packageRoute, their
    // installed versions
    public static function writeRoutes($web, &$reason)
    {
        if (is_null($web)) {
            return true;
        }
        $mount = array();
        $versions = array();
        foreach (self::discover($web) as $info) {
            if (($info->code === self::CORE) || is_null($info->manifest) || !$info->hasFiles || !$info->isInstalled || !$info->enabled) {
                continue;
            }
            if (count($info->manifest["route"]) == 0) {
                continue;
            }
            foreach ($info->manifest["route"] as $prefix => $folder) {
                $mount[$prefix] = "_site/" . $info->vendor . "/" . $info->name . "/" . $folder;
            }
            $versions[$info->code] = $info->installedVersion;
        }
        ksort($mount);
        ksort($versions);
        $file = ANDORA_PATH . self::ROUTE_CONFIG;
        $value = array(
            "route" => array("mount" => $mount),
            "packageRoute" => $versions
        );
        if (is_file($file)) {
            $current = include($file);
            if ($current === $value) {
                return true;
            }
        }
        return Setup::writeConfigFile($file, $value, $reason);
    }

    protected static function fail($info, $operation, $reason)
    {
        self::log(is_null($info) ? "" : $info->code, is_null($info) ? "" : $info->version, $operation, "error", $reason);
        self::clear();
        Acl::reset();
        return false;
    }

    // _log/<date>.package.log ($detail: Manager::$detail when null)
    public static function log($code, $version, $operation, $result, $reason, $detail = null)
    {
        \XYO\Web\Log::logMessage("package", array(
            "datetime" => date("Y-m-d H:i:s"),
            "user" => self::$cli ? "cli" : Acl::currentUserId(),
            "package" => "" . $code,
            "version" => "" . $version,
            "operation" => $operation,
            "result" => $result,
            "reason" => "" . $reason,
            "detail" => is_null($detail) ? self::$detail : "" . $detail
        ));
    }
}
