<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;

// The tables of the packages, config.12.datasource.php (packageDataSource),
// written by the package manager after every operation (like the routes and
// the events) from the "datasource" list of package.json:
//
//     table => array("package" => <vendor>/<name>, "class" => Table class)
//
// table is the name of the descriptor, without the prefix of the connection.
// andora/web and every installed package (enabled or not). A package
// installed without its files (uninstalled with "keep data", files missing)
// keeps the tables of the registry written before: its data is still there.
// The database backups (DatabaseBackup) find the tables of a package here.
class DataSourceRegistry
{
    public const CONFIG = "config.12.datasource.php";

    // table => array(package, class); null: not read yet
    protected static $registry = null;

    // The registry of the configuration (or the one written in this request)
    public static function load($web)
    {
        if (is_null(self::$registry)) {
            $config = $web->get(\XYO\Web\Config::class);
            self::$registry = $config->has("packageDataSource") ? self::normalize($config->getArray("packageDataSource")) : false;
        }
        return (self::$registry === false) ? array() : self::$registry;
    }

    // The registry was written (by the setup, or a package operation)
    public static function exists($web)
    {
        self::load($web);
        return self::$registry !== false;
    }

    // Write the registry if it was never written (a site made before it)
    public static function ensure($web)
    {
        $reason = "";
        if (!self::exists($web) && !self::write($web, $reason)) {
            Manager::log("", "", "datasource", "error", $reason);
        }
    }

    protected static function normalize($value)
    {
        $registry = array();
        foreach ($value as $table => $entry) {
            if (is_array($entry) && Manifest::isCode($entry["package"] ?? null) && is_string($entry["class"] ?? null)) {
                $registry["" . $table] = array("package" => $entry["package"], "class" => $entry["class"]);
            }
        }
        return $registry;
    }

    // <vendor>/<name> of the package that declares the table, null if none
    public static function owner($web, $table)
    {
        $registry = self::load($web);
        return array_key_exists($table, $registry) ? $registry[$table]["package"] : null;
    }

    // The tables of a package
    public static function tablesOf($web, $code)
    {
        $list = array();
        foreach (self::load($web) as $table => $entry) {
            if ($entry["package"] === $code) {
                $list[] = "" . $table;
            }
        }
        return $list;
    }

    // class => table of the "datasource" list of the package (from its files),
    // null and $reason when a class is not a Table of the package:
    // datasource-missing (no file, no class, not a Table, no table name).
    // Manager::$detail: the class.
    public static function describe($info, &$reason)
    {
        $list = array();
        foreach ($info->manifest["datasource"] as $class) {
            $file = Manager::sitePath() . Manifest::classPath($class) . ".php";
            $isTable = is_file($file) && class_exists($class) && is_a($class, \XYO\Web\DataSource\Table::class, true);
            $name = "";
            if ($isTable) {
                $tableInfo = new \XYO\Web\DataSource\TableInfo();
                $class::descriptor($tableInfo);
                $name = $tableInfo->name;
            }
            if (!$isTable || !is_string($name) || (strlen($name) == 0) || (strlen($name) > 64)) {
                $reason = "datasource-missing";
                Manager::$detail = $class;
                return null;
            }
            $list[$class] = $name;
        }
        return $list;
    }

    // The start of the table names of a package: <vendor>-<name>-
    // (acme/example: acme-example-item, andora/web: andora-web-user)
    public static function prefix($code)
    {
        return str_replace("/", "-", "" . $code) . "-";
    }

    // The table name is <vendor>-<name>-<table>, <table> lowercase letters,
    // digits and "-" (like a package name)
    public static function isTableOf($table, $code)
    {
        $prefix = self::prefix($code);
        return is_string($table) && (strncmp($table, $prefix, strlen($prefix)) == 0) && (preg_match(Manifest::NAME_PATTERN, substr($table, strlen($prefix))) == 1);
    }

    // Before install, upgrade and enable (and new files of the same version):
    // the Table classes of "datasource" exist (datasource-missing), their
    // tables start with <vendor>-<name>- (datasource-prefix), are not declared
    // twice nor by andora/web or another installed package
    // (datasource-conflict), the $tables of the lifecycle class are declared
    // (datasource-undeclared). Manager::$detail: the class or the table.
    public static function check($web, $info, $package, &$reason)
    {
        $tables = self::describe($info, $reason);
        if (is_null($tables)) {
            return false;
        }
        foreach ($tables as $class => $table) {
            if (!self::isTableOf($table, $info->code)) {
                $reason = "datasource-prefix";
                Manager::$detail = $table;
                return false;
            }
        }
        $others = self::build($web, $info->code);
        $seen = array();
        foreach ($tables as $class => $table) {
            if (in_array($table, $seen, true) || array_key_exists($table, $others)) {
                $reason = "datasource-conflict";
                Manager::$detail = $table;
                return false;
            }
            $seen[] = $table;
        }
        foreach ($package->getTables() as $class) {
            if (!in_array(ltrim($class, "\\"), $info->manifest["datasource"], true)) {
                $reason = "datasource-undeclared";
                Manager::$detail = ltrim($class, "\\");
                return false;
            }
        }
        return true;
    }

    // andora/web and the installed packages
    protected static function isOwner($info)
    {
        return ($info->code === Manager::CORE) || $info->isInstalled;
    }

    // The registry of the packages found now ($skip: without this package)
    public static function build($web, $skip = null)
    {
        $stored = self::load($web);
        $list = Manager::discover($web);
        $registry = array();
        // andora/web first: its tables can not be taken by another package
        $owners = array_key_exists(Manager::CORE, $list) ? array($list[Manager::CORE]) : array();
        foreach ($list as $info) {
            if ($info->code !== Manager::CORE) {
                $owners[] = $info;
            }
        }
        foreach ($owners as $info) {
            if (($info->code === $skip) || !self::isOwner($info)) {
                continue;
            }
            $tables = null;
            if (!is_null($info->manifest) && $info->hasFiles) {
                $reason = "";
                $detail = Manager::$detail;
                $tables = self::describe($info, $reason);
                Manager::$detail = $detail;
            }
            if (is_null($tables)) {
                // no files (or broken ones): the tables written before stay
                foreach ($stored as $table => $entry) {
                    if (($entry["package"] === $info->code) && !array_key_exists($table, $registry)) {
                        $registry[$table] = $entry;
                    }
                }
                continue;
            }
            foreach ($tables as $class => $table) {
                // a conflict is refused by check(), the first package keeps the table
                if (!array_key_exists($table, $registry)) {
                    $registry[$table] = array("package" => $info->code, "class" => $class);
                }
            }
        }
        ksort($registry);
        return $registry;
    }

    // config.12.datasource.php, only when it changed
    public static function write($web, &$reason)
    {
        if (is_null($web)) {
            return true;
        }
        $registry = self::build($web);
        self::$registry = $registry;
        $file = ANDORA_PATH . self::CONFIG;
        $value = array("packageDataSource" => $registry);
        if (is_file($file)) {
            $current = include($file);
            if ($current === $value) {
                return true;
            }
        }
        return Setup::writeConfigFile($file, $value, $reason);
    }
}
