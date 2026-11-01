<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;

// Package registries: an index (JSON) of packages that can be downloaded
// and installed (admin/package/catalog, Installer::stage, the same confirm
// and migration as an upload).
//
//   {"packages": [{"vendor": "acme", "name": "blog", "version": "1.2.0",
//      "title": "Blog", "description": "Articles",
//      "url": "https://packages.example/acme.blog.v1.2.0.zip",
//      "sha256": "<64 hex digits of the zip>"}]}
//
// url can be relative to the index. An entry that is not valid is skipped;
// for the same package the highest version is kept.
//
// The registries: the official one (config package.registry, default
// OFFICIAL_URL; empty: none) and the ones added from the page or with
// "cli.php registry add" (config.10.package-registry.php, packageRegistry:
// name => ["url" => url]). An index is fetched (Remote::get) only when the
// catalog is opened and kept in _repository/package/catalog/<name>.json for
// the install (CACHE_LIFETIME).
class Registry
{
    // The registry of Andora, set when it is published
    public const OFFICIAL_URL = "";
    public const OFFICIAL = "official";
    public const CONFIG = "config.10.package-registry.php";
    public const MAX_NAME_LENGTH = 32;
    public const MAX_INDEX_SIZE = 1048576; // 1 MB
    public const MAX_ZIP_SIZE = 67108864; // 64 MB
    public const MAX_PACKAGES = 1000;
    public const INDEX_TIMEOUT = 20;
    public const ZIP_TIMEOUT = 300;
    public const CACHE_LIFETIME = 3600;

    public static function officialURL($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $url = Registry::OFFICIAL_URL;
        if ($config->has("package")) {
            $url = $config->get("package")->get("registry", $url);
        }
        return is_string($url) ? trim($url) : "";
    }

    // The registries added: name => url (from the config file, so a change
    // made in this request is seen)
    public static function added()
    {
        $list = array();
        $file = ANDORA_PATH . self::CONFIG;
        if (!is_file($file)) {
            return $list;
        }
        $value = include($file);
        if (!is_array($value) || !is_array($value["packageRegistry"] ?? null)) {
            return $list;
        }
        foreach ($value["packageRegistry"] as $name => $item) {
            if (self::isName("" . $name) && is_array($item) && is_string($item["url"] ?? null)) {
                $list["" . $name] = $item["url"];
            }
        }
        return $list;
    }

    // name => url: the official registry first (when set), then the ones added
    public static function all($web)
    {
        $list = array();
        $official = self::officialURL($web);
        if (strlen($official) > 0) {
            $list[self::OFFICIAL] = $official;
        }
        foreach (self::added() as $name => $url) {
            if ($name !== self::OFFICIAL) {
                $list[$name] = $url;
            }
        }
        return $list;
    }

    public static function isName($name)
    {
        return Manifest::isName($name) && (strlen($name) <= self::MAX_NAME_LENGTH);
    }

    protected static function write($list, &$reason)
    {
        $value = array();
        foreach ($list as $name => $url) {
            $value[$name] = array("url" => $url);
        }
        ksort($value);
        return Setup::writeConfigFile(ANDORA_PATH . self::CONFIG, array("packageRegistry" => $value), $reason);
    }

    // $reason: invalid-registry-name, registry-exists, invalid-url
    public static function add($web, $name, $url, &$reason)
    {
        Manager::$detail = "";
        $name = trim("" . $name);
        $url = trim("" . $url);
        if (!self::isName($name) || ($name === self::OFFICIAL)) {
            $reason = "invalid-registry-name";
            Manager::$detail = substr($name, 0, 64);
            return false;
        }
        $list = self::added();
        if (array_key_exists($name, $list)) {
            $reason = "registry-exists";
            Manager::$detail = $name;
            return false;
        }
        if (is_null(Remote::parseURL($url, Remote::allowLocal($web)))) {
            $reason = "invalid-url";
            Manager::$detail = substr($url, 0, 256);
            return false;
        }
        $list[$name] = $url;
        if (!self::write($list, $reason)) {
            return false;
        }
        Manager::$detail = $name . " " . $url;
        Manager::log("", "", "registry-add", "ok", "");
        Manager::$detail = "";
        return true;
    }

    // $reason: registry-not-found (the official one can not be removed)
    public static function remove($web, $name, &$reason)
    {
        Manager::$detail = "";
        $list = self::added();
        if (!is_string($name) || !array_key_exists($name, $list)) {
            $reason = "registry-not-found";
            Manager::$detail = substr("" . $name, 0, 64);
            return false;
        }
        unset($list[$name]);
        if (!self::write($list, $reason)) {
            return false;
        }
        @unlink(self::cacheFile($name));
        Manager::$detail = $name;
        Manager::log("", "", "registry-remove", "ok", "");
        Manager::$detail = "";
        return true;
    }

    protected static function cacheFile($name)
    {
        return Installer::repositoryPath() . "catalog/" . $name . ".json";
    }

    // The packages of an index: code => vendor, name, code, version, title,
    // description, url (absolute), sha256. null and $reason: invalid-index.
    public static function parseIndex($json, $indexURL, &$reason)
    {
        $data = json_decode("" . $json, true, 8);
        if (!is_array($data) || !is_array($data["packages"] ?? null) || (array_values($data["packages"]) !== $data["packages"])) {
            $reason = "invalid-index";
            return null;
        }
        $list = array();
        foreach (array_slice($data["packages"], 0, self::MAX_PACKAGES) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vendor = $item["vendor"] ?? null;
            $name = $item["name"] ?? null;
            $version = $item["version"] ?? null;
            $sha256 = $item["sha256"] ?? null;
            $url = $item["url"] ?? null;
            if (!Manifest::isName($vendor) || Manifest::isReservedVendor($vendor) || !Manifest::isName($name)) {
                continue;
            }
            if (!is_string($version) || (preg_match(Manifest::VERSION_PATTERN, $version) != 1)) {
                continue;
            }
            if (!is_string($sha256) || (preg_match("/^[0-9a-fA-F]{64}$/", $sha256) != 1) || !is_string($url) || (strlen($url) == 0)) {
                continue;
            }
            $url = Remote::resolveURL($indexURL, $url);
            if (is_null($url) || (strlen($url) > Remote::MAX_URL_LENGTH)) {
                continue;
            }
            $code = $vendor . "/" . $name;
            if (array_key_exists($code, $list) && (version_compare($list[$code]["version"], $version) >= 0)) {
                continue;
            }
            $list[$code] = array(
                "vendor" => $vendor,
                "name" => $name,
                "code" => $code,
                "version" => $version,
                "title" => is_string($item["title"] ?? null) ? self::text($item["title"], 128) : $code,
                "description" => is_string($item["description"] ?? null) ? self::text($item["description"], 512) : "",
                "url" => $url,
                "sha256" => strtolower($sha256)
            );
        }
        ksort($list);
        return $list;
    }

    // A text of the index: valid UTF-8, at most $length characters
    protected static function text($value, $length)
    {
        if (!mb_check_encoding($value, "UTF-8")) {
            return "";
        }
        return mb_substr($value, 0, $length, "UTF-8");
    }

    // Fetch the index of a registry and keep it for the install: its packages
    // (parseIndex) or null and $reason (registry-not-found, invalid-index,
    // the reasons of Remote::get)
    public static function fetch($web, $name, &$reason)
    {
        $registries = self::all($web);
        if (!array_key_exists($name, $registries)) {
            $reason = "registry-not-found";
            return null;
        }
        $url = $registries[$name];
        $json = Remote::get($web, $url, self::MAX_INDEX_SIZE, self::INDEX_TIMEOUT, $reason);
        if (is_null($json)) {
            return null;
        }
        $packages = self::parseIndex($json, $url, $reason);
        if (is_null($packages)) {
            Manager::$detail = $name;
            return null;
        }
        $file = self::cacheFile($name);
        if (is_dir(dirname($file)) || @mkdir(dirname($file), 0777, true)) {
            @file_put_contents($file, json_encode(array("url" => $url, "time" => time(), "packages" => $packages), JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        return $packages;
    }

    // The index fetched when the catalog was opened, null if it is too old
    // or the registry changed since
    public static function cached($web, $name)
    {
        $registries = self::all($web);
        if (!self::isName($name) || !array_key_exists($name, $registries)) {
            return null;
        }
        $data = json_decode("" . @file_get_contents(self::cacheFile($name)), true);
        if (!is_array($data) || (($data["url"] ?? null) !== $registries[$name]) || (intval($data["time"] ?? 0) < time() - self::CACHE_LIFETIME) || !is_array($data["packages"] ?? null)) {
            return null;
        }
        return $data["packages"];
    }

    // Download the zip of a package of the catalog, check its sha256 and
    // stage it (Installer::stage). Returns the stage or null and $reason:
    // catalog-expired, package-not-in-catalog, checksum-mismatch,
    // catalog-mismatch, the reasons of Remote::get and Installer::stage.
    public static function download($web, $name, $code, &$reason)
    {
        Manager::$detail = "";
        if (!Manager::isFileOperationAllowed($web)) {
            $reason = "file-operations-disabled";
            return null;
        }
        $packages = self::cached($web, $name);
        if (is_null($packages)) {
            $reason = "catalog-expired";
            return null;
        }
        if (!is_string($code) || !array_key_exists($code, $packages)) {
            $reason = "package-not-in-catalog";
            Manager::$detail = substr("" . $code, 0, 128);
            return null;
        }
        $entry = $packages[$code];
        $path = Installer::repositoryPath() . "download/";
        if (!is_dir($path) && !@mkdir($path, 0777, true)) {
            $reason = "unable-to-write-files";
            return null;
        }
        $file = $path . bin2hex(random_bytes(16)) . ".zip";
        $size = Remote::get($web, $entry["url"], self::MAX_ZIP_SIZE, self::ZIP_TIMEOUT, $reason, $file);
        if (is_null($size)) {
            @unlink($file);
            Manager::log($code, $entry["version"], "download", "error", $reason);
            return null;
        }
        if (!hash_equals($entry["sha256"], hash_file("sha256", $file))) {
            @unlink($file);
            $reason = "checksum-mismatch";
            Manager::$detail = $code;
            Manager::log($code, $entry["version"], "download", "error", $reason);
            return null;
        }
        $stage = Installer::stage($web, $file, $reason);
        @unlink($file);
        if (is_null($stage)) {
            Manager::log($code, $entry["version"], "download", "error", $reason);
            return null;
        }
        if (($stage->code !== $code) || ($stage->version !== $entry["version"])) {
            Installer::removeStage($stage->id);
            $reason = "catalog-mismatch";
            Manager::$detail = $stage->code . " " . $stage->version;
            Manager::log($code, $entry["version"], "download", "error", $reason);
            return null;
        }
        Manager::$detail = $name . ": " . $entry["url"];
        Manager::log($code, $entry["version"], "download", "ok", "");
        Manager::$detail = "";
        return $stage;
    }

    // What the catalog offers for a package: install (not installed),
    // upgrade (newer than the installed version), installed (same version),
    // older (the installed version is newer)
    public static function action($web, $entry)
    {
        $info = Manager::get($web, $entry["code"]);
        if (is_null($info) || !$info->isInstalled) {
            return "install";
        }
        $compare = version_compare($entry["version"], $info->installedVersion);
        if ($compare > 0) {
            return "upgrade";
        }
        return ($compare == 0) ? "installed" : "older";
    }
}
