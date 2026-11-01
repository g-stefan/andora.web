<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;

// The events of the packages, config.11.event.php (packageEvent), written
// by the package manager after every operation (like the routes,
// config.08.route.php) and read with the configuration on every request:
//
//     "provide" => event => <vendor>/<name> of the package that provides it
//     "listen"  => event => list of array(package, version, method)
//
// provide: andora/web and the enabled packages with files (as the routes);
// listen: the active packages only (installed, enabled, the files of the
// installed version), for the events that are provided.
class EventRegistry
{
    public const CONFIG = "config.11.event.php";

    // provide, listen; null: not read yet
    protected static $registry = null;
    // <vendor>/<name> => info of a listener checked in this request (null: not active)
    protected static $info = array();

    // The registry of the configuration (or the one written in this request)
    public static function load($web)
    {
        if (is_null(self::$registry)) {
            $config = $web->get(\XYO\Web\Config::class);
            self::$registry = $config->has("packageEvent") ? self::normalize($config->getArray("packageEvent")) : false;
        }
        return (self::$registry === false) ? array("provide" => array(), "listen" => array()) : self::$registry;
    }

    // The registry was written (by the setup, or a package operation)
    public static function exists($web)
    {
        self::load($web);
        return self::$registry !== false;
    }

    protected static function normalize($value)
    {
        return array(
            "provide" => (is_array($value["provide"] ?? null)) ? $value["provide"] : array(),
            "listen" => (is_array($value["listen"] ?? null)) ? $value["listen"] : array()
        );
    }

    // <vendor>/<name> of the package that provides the event, null if none
    public static function provider($web, $name)
    {
        $registry = self::load($web);
        return (is_string($name) && array_key_exists($name, $registry["provide"])) ? $registry["provide"][$name] : null;
    }

    // array(package, version, method) of the listeners of the event
    public static function listeners($web, $name)
    {
        $registry = self::load($web);
        return (is_string($name) && array_key_exists($name, $registry["listen"])) ? $registry["listen"][$name] : array();
    }

    // The lifecycle object of a listener, null when its package is no longer
    // the one of the registry (the files were replaced: an upgrade is
    // pending, the folder is gone) or the method is missing (logged)
    public static function instance($web, $listener)
    {
        $code = "" . ($listener["package"] ?? "");
        $version = "" . ($listener["version"] ?? "");
        $method = "" . ($listener["method"] ?? "");
        if (!array_key_exists($code, self::$info)) {
            self::$info[$code] = null;
            if (Manifest::isCode($code)) {
                $part = explode("/", $code);
                $reason = "";
                $manifest = Manifest::load(Manager::sitePath() . $code . "/", $part[0], $part[1], $reason);
                if (!is_null($manifest) && (version_compare($manifest["version"], $version) == 0)) {
                    self::$info[$code] = Manager::activeInfo($manifest, $version);
                }
            }
        }
        if (is_null(self::$info[$code])) {
            return null;
        }
        $reason = "";
        $package = Manager::instance($web, self::$info[$code], $reason);
        if (is_null($package) || !self::isListener($package, $method)) {
            Manager::log($code, $version, "event", "error", "event-listener-missing", $method);
            return null;
        }
        return $package;
    }

    // A public method of the lifecycle class, not a hook of Base (install, ...)
    public static function isListener($package, $method)
    {
        return is_string($method) && !method_exists(Base::class, $method) && method_exists($package, $method) && is_callable(array($package, $method));
    }

    // Before install, upgrade and enable (and new files of the same version):
    // the events the package provides are not provided by andora/web or
    // another enabled package (event-conflict), its listeners are public
    // methods of its lifecycle class that are not hooks of Base
    // (event-listener-missing). Manager::$detail: the event.
    public static function check($web, $info, $package, &$reason)
    {
        foreach ($info->manifest["event"]["provide"] as $name => $description) {
            foreach (Manager::discover($web) as $other) {
                if (($other->code === $info->code) || is_null($other->manifest)) {
                    continue;
                }
                if (self::isProvider($other) && array_key_exists($name, $other->manifest["event"]["provide"])) {
                    $reason = "event-conflict";
                    Manager::$detail = $name;
                    return false;
                }
            }
        }
        foreach ($info->manifest["event"]["listen"] as $name => $method) {
            if (!self::isListener($package, $method)) {
                $reason = "event-listener-missing";
                Manager::$detail = $name . " => " . $method;
                return false;
            }
        }
        return true;
    }

    // andora/web, and the enabled packages with files (their events stay
    // while an upgrade is pending, as their routes)
    protected static function isProvider($info)
    {
        return ($info->code === Manager::CORE) || ($info->isInstalled && $info->enabled && $info->hasFiles);
    }

    // The registry of the packages found now
    public static function build($web)
    {
        $list = Manager::discover($web);
        $provide = array();
        $listen = array();
        // andora/web first: its events can not be taken by another package
        $providers = array_key_exists(Manager::CORE, $list) ? array($list[Manager::CORE]) : array();
        foreach ($list as $info) {
            if ($info->code !== Manager::CORE) {
                $providers[] = $info;
            }
        }
        foreach ($providers as $info) {
            if (is_null($info->manifest) || !self::isProvider($info)) {
                continue;
            }
            foreach ($info->manifest["event"]["provide"] as $name => $description) {
                // a conflict is refused by check(), the first package keeps the event
                if (!array_key_exists($name, $provide)) {
                    $provide[$name] = $info->code;
                }
            }
        }
        foreach ($list as $info) {
            if (is_null($info->manifest) || ($info->state !== Manager::STATE_INSTALLED)) {
                continue;
            }
            foreach ($info->manifest["event"]["listen"] as $name => $method) {
                // nothing to listen to while no package provides the event
                if (!array_key_exists($name, $provide)) {
                    continue;
                }
                $listen[$name][] = array(
                    "package" => $info->code,
                    "version" => $info->installedVersion,
                    "method" => $method
                );
            }
        }
        ksort($provide);
        ksort($listen);
        return array("provide" => $provide, "listen" => $listen);
    }

    // config.11.event.php, only when it changed
    public static function write($web, &$reason)
    {
        if (is_null($web)) {
            return true;
        }
        $registry = self::build($web);
        self::$registry = $registry;
        self::$info = array();
        $file = ANDORA_PATH . self::CONFIG;
        $value = array("packageEvent" => $registry);
        if (is_file($file)) {
            $current = include($file);
            if ($current === $value) {
                return true;
            }
        }
        return Setup::writeConfigFile($file, $value, $reason);
    }
}
