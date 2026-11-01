<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// package.json of a package folder, _site/<vendor>/<name>/package.json.
// The file is data: it is read and checked without running any code of the
// package.
class Manifest
{
    // vendor and name: lower case letters and digits, groups joined by "-"
    public const NAME_PATTERN = "/^[a-z0-9]+(-[a-z0-9]+)*$/";
    // major.minor.patch
    public const VERSION_PATTERN = "/^[0-9]+\\.[0-9]+\\.[0-9]+$/";
    // one part of a requirement: >=1.0.0, <2.0, =1.2.3, 1.2.3
    public const CONSTRAINT_PATTERN = "/^(>=|<=|>|<|==|=|!=)?([0-9]+(\\.[0-9]+){0,2})$/";
    public const MAX_SIZE = 65536;
    // a route path or a folder of the package: segments of lower case letters,
    // digits, ".", "_", "-" starting with a letter or digit
    public const ROUTE_PATTERN = "/^[a-z0-9][a-z0-9._-]*(\\/[a-z0-9][a-z0-9._-]*)*$/";
    // an event: user.create, acme-blog.post-publish
    public const EVENT_PATTERN = "/^[a-z0-9]+(-[a-z0-9]+)*(\\.[a-z0-9]+(-[a-z0-9]+)*)+$/";
    // a class name with its namespace: Acme\Example\DataSource\TableItem
    public const CLASS_PATTERN = "/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/";

    // array_is_list (PHP 8.1)
    protected static function isList($value)
    {
        return (count($value) == 0) || (array_keys($value) === range(0, count($value) - 1));
    }

    // _site/vendor/ is composer
    public static function isReservedVendor($vendor)
    {
        return in_array($vendor, array("vendor"), true);
    }

    public static function isName($name)
    {
        return is_string($name) && (strlen($name) <= 64) && (preg_match(self::NAME_PATTERN, $name) == 1);
    }

    public static function isCode($code)
    {
        if (!is_string($code)) {
            return false;
        }
        $part = explode("/", $code);
        return (count($part) == 2) && self::isName($part[0]) && self::isName($part[1]);
    }

    // Read and check <path>package.json, null and $reason on error:
    // no-manifest, invalid-manifest, invalid-vendor, invalid-name, invalid-version, invalid-require
    public static function load($path, $vendor, $name, &$reason)
    {
        $file = $path . "package.json";
        if (!is_file($file)) {
            $reason = "no-manifest";
            return null;
        }
        if (filesize($file) > self::MAX_SIZE) {
            $reason = "invalid-manifest";
            return null;
        }
        $data = json_decode("" . file_get_contents($file), true);
        return self::check($data, $vendor, $name, $reason);
    }

    // The checked manifest as an array: vendor, name, code, version, title,
    // description, license, author, protected, require (php, extension, package),
    // route (route path => folder in the package), event (provide: event =>
    // description, listen: event => method of the lifecycle class),
    // datasource (the Table classes of the package)
    public static function check($data, $vendor, $name, &$reason)
    {
        if (!is_array($data) || self::isList($data)) {
            $reason = "invalid-manifest";
            return null;
        }
        if (!array_key_exists("vendor", $data) || ($data["vendor"] !== $vendor) || !self::isName($vendor) || self::isReservedVendor($vendor)) {
            $reason = "invalid-vendor";
            return null;
        }
        if (!array_key_exists("name", $data) || ($data["name"] !== $name) || !self::isName($name)) {
            $reason = "invalid-name";
            return null;
        }
        if (!array_key_exists("version", $data) || !is_string($data["version"]) || (preg_match(self::VERSION_PATTERN, $data["version"]) != 1)) {
            $reason = "invalid-version";
            return null;
        }

        $manifest = array(
            "vendor" => $vendor,
            "name" => $name,
            "code" => $vendor . "/" . $name,
            "version" => $data["version"],
            "title" => $vendor . "/" . $name,
            "description" => "",
            "license" => "",
            "author" => "",
            "protected" => false,
            "route" => array(),
            "event" => array(
                "provide" => array(),
                "listen" => array()
            ),
            "datasource" => array(),
            "require" => array(
                "php" => "",
                "extension" => array(),
                "package" => array()
            )
        );
        foreach (array("title", "description", "license", "author") as $key) {
            if (array_key_exists($key, $data)) {
                if (!is_string($data[$key])) {
                    $reason = "invalid-manifest";
                    return null;
                }
                if (strlen($data[$key]) > 0) {
                    $manifest[$key] = $data[$key];
                }
            }
        }
        if (array_key_exists("protected", $data)) {
            if (!is_bool($data["protected"])) {
                $reason = "invalid-manifest";
                return null;
            }
            $manifest["protected"] = $data["protected"];
        }

        if (array_key_exists("require", $data)) {
            $require = $data["require"];
            if (!is_array($require) || ((count($require) > 0) && self::isList($require))) {
                $reason = "invalid-require";
                return null;
            }
            foreach ($require as $key => $value) {
                if ($key === "php") {
                    if (!self::isConstraint($value)) {
                        $reason = "invalid-require";
                        return null;
                    }
                    $manifest["require"]["php"] = $value;
                    continue;
                }
                if ($key === "extension") {
                    if (!is_array($value) || !self::isList($value)) {
                        $reason = "invalid-require";
                        return null;
                    }
                    foreach ($value as $extension) {
                        if (!is_string($extension) || (preg_match("/^[A-Za-z0-9_]+$/", $extension) != 1)) {
                            $reason = "invalid-require";
                            return null;
                        }
                        $manifest["require"]["extension"][] = $extension;
                    }
                    continue;
                }
                if (!self::isCode($key) || ($key === $manifest["code"]) || !self::isConstraint($value)) {
                    $reason = "invalid-require";
                    return null;
                }
                $manifest["require"]["package"][$key] = $value;
            }
        }

        // "route": {"blog": "route/blog", "admin/blog": "route/admin/blog"}
        if (array_key_exists("route", $data)) {
            $route = $data["route"];
            if (!is_array($route) || ((count($route) > 0) && self::isList($route))) {
                $reason = "invalid-route";
                return null;
            }
            foreach ($route as $prefix => $folder) {
                if (!self::isRoutePath("" . $prefix) || !is_string($folder) || !self::isRoutePath($folder)) {
                    $reason = "invalid-route";
                    Manager::$detail = "" . $prefix;
                    return null;
                }
                $manifest["route"]["" . $prefix] = $folder;
            }
        }

        // "event": {"provide": {"acme-blog.post-publish": "A post was published"},
        //           "listen": {"user.create": "onUserCreate"}}
        if (array_key_exists("event", $data)) {
            $event = $data["event"];
            if (!is_array($event) || ((count($event) > 0) && self::isList($event))) {
                $reason = "invalid-event";
                return null;
            }
            foreach ($event as $key => $value) {
                if (!in_array($key, array("provide", "listen"), true) || !is_array($value) || ((count($value) > 0) && self::isList($value))) {
                    $reason = "invalid-event";
                    Manager::$detail = "" . $key;
                    return null;
                }
                foreach ($value as $name => $item) {
                    $isItem = ($key === "provide") ? is_string($item) && (strlen($item) <= 255) : self::isMethodName($item);
                    if (!self::isEventName("" . $name) || !$isItem) {
                        $reason = "invalid-event";
                        Manager::$detail = "" . $name;
                        return null;
                    }
                    $manifest["event"][$key]["" . $name] = $item;
                }
            }
        }

        // "datasource": ["Acme\\Example\\DataSource\\TableItem"]: the Table
        // classes of the package, their files in the package folder
        // (_site/acme/example/data-source/table-item.php)
        if (array_key_exists("datasource", $data)) {
            $datasource = $data["datasource"];
            if (!is_array($datasource) || !self::isList($datasource)) {
                $reason = "invalid-datasource";
                return null;
            }
            foreach ($datasource as $class) {
                $class = is_string($class) ? ltrim($class, "\\") : $class;
                if (!self::isClassName($class) || (strpos(self::classPath($class), $manifest["code"] . "/") !== 0) || in_array($class, $manifest["datasource"], true)) {
                    $reason = "invalid-datasource";
                    Manager::$detail = is_string($class) ? $class : "";
                    return null;
                }
                $manifest["datasource"][] = $class;
            }
        }

        return $manifest;
    }

    public static function isClassName($name)
    {
        return is_string($name) && (strlen($name) <= 255) && (preg_match(self::CLASS_PATTERN, $name) == 1);
    }

    // The file of a class for the autoloader of XYO.Web, without _site/ and
    // .php: Acme\Example\DataSource\TableItem -> acme/example/data-source/table-item
    // (\XYO\Web\classNameToKebabCase, the manifest is also read without the framework)
    public static function classPath($class)
    {
        $parts = explode("\\", ltrim($class, "\\"));
        $kebab = array_map(function ($part) {
            return strtolower(preg_replace("/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|(?<=[a-zA-Z])(?=[0-9]+)/", "-", $part));
        }, $parts);
        return implode("/", $kebab);
    }

    // segments of lower case letters and digits joined by "-", at least two
    // joined by ".": user.create, acme-blog.post-publish
    public static function isEventName($name)
    {
        return is_string($name) && (strlen($name) <= 128) && (preg_match(self::EVENT_PATTERN, $name) == 1);
    }

    public static function isMethodName($name)
    {
        return is_string($name) && (strlen($name) <= 64) && (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $name) == 1);
    }

    public static function isRoutePath($path)
    {
        if (!is_string($path) || (strlen($path) > 255) || (preg_match(self::ROUTE_PATTERN, $path) != 1)) {
            return false;
        }
        foreach (explode("/", $path) as $segment) {
            if (($segment === ".") || ($segment === "..")) {
                return false;
            }
        }
        return true;
    }

    // ">=1.0.0 <2.0.0", "1.2.3", "*" or ""
    public static function isConstraint($constraint)
    {
        if (!is_string($constraint) || (strlen($constraint) > 128)) {
            return false;
        }
        $constraint = trim($constraint);
        if ((strlen($constraint) == 0) || ($constraint === "*")) {
            return true;
        }
        foreach (preg_split("/\\s+/", $constraint) as $part) {
            if (preg_match(self::CONSTRAINT_PATTERN, $part) != 1) {
                return false;
            }
        }
        return true;
    }

    // The version matches every part of the constraint
    public static function versionMatches($version, $constraint)
    {
        if (!self::isConstraint($constraint)) {
            return false;
        }
        $constraint = trim($constraint);
        if ((strlen($constraint) == 0) || ($constraint === "*")) {
            return true;
        }
        foreach (preg_split("/\\s+/", $constraint) as $part) {
            preg_match(self::CONSTRAINT_PATTERN, $part, $match);
            $operator = $match[1];
            if (($operator === "") || ($operator === "=")) {
                $operator = "==";
            }
            // 1.2 is 1.2.0 (version_compare finds 1.2.0 newer than 1.2)
            $required = $match[2] . str_repeat(".0", 2 - substr_count($match[2], "."));
            if (!version_compare("" . $version, $required, $operator)) {
                return false;
            }
        }
        return true;
    }
}
