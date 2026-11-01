<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// HTTP GET of the package registries (index and zip files), safe against
// requests to the network of the server (SSRF):
//   - https only;
//   - the host is resolved here and every address it resolves to must be a
//     public one (no loopback, private, link local, reserved, multicast,
//     IPv4 mapped / NAT64 / 6to4 / Teredo forms of them), then curl connects
//     to that checked address (CURLOPT_RESOLVE: no second resolution);
//   - no proxy, redirects followed here (at most MAX_REDIRECTS), each one
//     checked the same way;
//   - connect and total timeouts, a size limit (the transfer is aborted).
// Config package.registryAllowLocal (only for the tests): http and the
// loopback addresses are allowed too, the private ones are still refused.
// Needs ext-curl.
class Remote
{
    public const MAX_REDIRECTS = 3;
    public const CONNECT_TIMEOUT = 10;
    public const MAX_URL_LENGTH = 2048;

    // not reachable from a registry request
    public const BLOCKED_IPV4 = array(
        "0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16",
        "172.16.0.0/12", "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "192.168.0.0/16",
        "198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4"
    );
    public const BLOCKED_IPV6 = array(
        "::/128", "::1/128", "::ffff:0:0/96", "64:ff9b::/96", "64:ff9b:1::/48", "100::/64",
        "2001::/32", "2001:db8::/32", "2002::/16", "fc00::/7", "fe80::/10", "fec0::/10", "ff00::/8"
    );

    public static function allowLocal($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("package")) {
            return false;
        }
        return $config->get("package")->get("registryAllowLocal", false) === true;
    }

    // scheme, host (without the brackets of IPv6), port, url; null if the
    // URL is refused (not https, user / password, no host, control characters)
    public static function parseURL($url, $allowLocal)
    {
        if (!is_string($url) || (strlen($url) > self::MAX_URL_LENGTH) || (preg_match("/[\\x00-\\x20\\x7F\\\\]/", $url) == 1)) {
            return null;
        }
        $part = parse_url($url);
        if (!is_array($part) || !isset($part["scheme"]) || !isset($part["host"])) {
            return null;
        }
        $scheme = strtolower($part["scheme"]);
        if (($scheme !== "https") && !(($scheme === "http") && $allowLocal)) {
            return null;
        }
        if (isset($part["user"]) || isset($part["pass"])) {
            return null;
        }
        $host = strtolower($part["host"]);
        if ((strlen($host) > 2) && ($host[0] === "[") && (substr($host, -1) === "]")) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } elseif ((filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) && (preg_match("/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\\.?$/", $host) != 1)) {
            return null;
        }
        $port = isset($part["port"]) ? intval($part["port"]) : (($scheme === "https") ? 443 : 80);
        if (($port < 1) || ($port > 65535)) {
            return null;
        }
        return array("scheme" => $scheme, "host" => $host, "port" => $port, "url" => $url);
    }

    // A URL of an index or of a Location header, relative to $base
    public static function resolveURL($base, $url)
    {
        $url = trim("" . $url);
        if (preg_match("/^[a-z][a-z0-9+.-]*:/i", $url) == 1) {
            return $url;
        }
        $part = parse_url($base);
        if (!is_array($part) || !isset($part["scheme"]) || !isset($part["host"])) {
            return null;
        }
        $origin = $part["scheme"] . "://" . ((strpos($part["host"], ":") !== false && $part["host"][0] !== "[") ? "[" . $part["host"] . "]" : $part["host"]) . (isset($part["port"]) ? ":" . $part["port"] : "");
        if (strncmp($url, "//", 2) == 0) {
            return $part["scheme"] . ":" . $url;
        }
        if (strncmp($url, "/", 1) == 0) {
            return $origin . $url;
        }
        $path = $part["path"] ?? "/";
        $folder = substr($path, 0, strrpos($path, "/") + 1);
        if (strlen($folder) == 0) {
            $folder = "/";
        }
        return $origin . $folder . $url;
    }

    protected static function inRange($ip, $range)
    {
        list($subnet, $bits) = explode("/", $range);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if (($ipBinary === false) || ($subnetBinary === false) || (strlen($ipBinary) != strlen($subnetBinary))) {
            return false;
        }
        $bits = intval($bits);
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBinary, $subnetBinary, $bytes) != 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest == 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBinary[$bytes]) & $mask) == (ord($subnetBinary[$bytes]) & $mask);
    }

    public static function isLoopback($ip)
    {
        return self::inRange($ip, "127.0.0.0/8") || self::inRange($ip, "::1/128");
    }

    // An address a registry request may connect to
    public static function isAllowedAddress($ip, $allowLocal)
    {
        if (!is_string($ip) || (filter_var($ip, FILTER_VALIDATE_IP) === false)) {
            return false;
        }
        // ::ffff:a.b.c.d is the IPv4 address
        if ((stripos($ip, "::ffff:") === 0) && (filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)) {
            $ip = substr($ip, 7);
        }
        if ($allowLocal && self::isLoopback($ip)) {
            return true;
        }
        $isIPv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        foreach ($isIPv4 ? self::BLOCKED_IPV4 : self::BLOCKED_IPV6 as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }
        return true;
    }

    // The address to connect to: every address of the host must be allowed.
    // null and $reason: host-not-found, address-not-allowed.
    public static function resolve($host, $allowLocal, &$reason)
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $list = array($host);
        } else {
            $list = @gethostbynamel(rtrim($host, "."));
            if (!is_array($list) || (count($list) == 0)) {
                $list = array();
                $records = function_exists("dns_get_record") ? @dns_get_record(rtrim($host, "."), DNS_AAAA) : false;
                foreach (is_array($records) ? $records : array() as $record) {
                    if (isset($record["ipv6"])) {
                        $list[] = $record["ipv6"];
                    }
                }
            }
        }
        if (count($list) == 0) {
            $reason = "host-not-found";
            Manager::$detail = $host;
            return null;
        }
        foreach ($list as $ip) {
            if (!self::isAllowedAddress($ip, $allowLocal)) {
                $reason = "address-not-allowed";
                Manager::$detail = $host . " (" . $ip . ")";
                return null;
            }
        }
        return $list[0];
    }

    // GET $url: the body (string), or with $file the number of bytes written
    // to it; null and $reason: requires-extension, invalid-url,
    // host-not-found, address-not-allowed, too-many-redirects, http-error,
    // too-large, timeout, download-error (Manager::$detail: what about)
    public static function get($web, $url, $maxSize, $timeout, &$reason, $file = null)
    {
        Manager::$detail = "";
        if (!function_exists("curl_init")) {
            $reason = "requires-extension";
            Manager::$detail = "curl";
            return null;
        }
        $allowLocal = self::allowLocal($web);
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $target = self::parseURL($url, $allowLocal);
            if (is_null($target)) {
                $reason = "invalid-url";
                Manager::$detail = substr("" . $url, 0, 256);
                return null;
            }
            $ip = self::resolve($target["host"], $allowLocal, $reason);
            if (is_null($ip)) {
                return null;
            }
            $response = self::request($target, $ip, $maxSize, $timeout, $reason, $file);
            if (is_null($response)) {
                return null;
            }
            if (in_array($response["status"], array(301, 302, 303, 307, 308), true)) {
                $url = self::resolveURL($url, $response["location"]);
                continue;
            }
            if ($response["status"] != 200) {
                $reason = "http-error";
                Manager::$detail = "" . $response["status"];
                return null;
            }
            return is_null($file) ? $response["body"] : $response["size"];
        }
        $reason = "too-many-redirects";
        return null;
    }

    // One request to the checked address
    protected static function request($target, $ip, $maxSize, $timeout, &$reason, $file)
    {
        $response = array("status" => 0, "location" => "", "body" => "", "size" => 0);
        $handle = null;
        if (!is_null($file)) {
            $handle = @fopen($file, "wb");
            if ($handle === false) {
                $reason = "unable-to-write-files";
                return null;
            }
        }
        $isTooLarge = false;
        $curl = curl_init($target["url"]);
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => "",
            CURLOPT_NOPROXY => "*",
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => "Andora package registry",
            CURLOPT_HTTPHEADER => array("Accept: application/json, application/zip;q=0.9, */*;q=0.1"),
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$response) {
                if (preg_match("/^HTTP\\/[0-9.]+\\s+([0-9]{3})/", $line, $match)) {
                    $response["status"] = intval($match[1]);
                    $response["location"] = "";
                }
                $part = explode(":", $line, 2);
                if ((count($part) == 2) && (strtolower(trim($part[0])) === "location")) {
                    $response["location"] = trim($part[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$response, &$isTooLarge, $maxSize, $handle) {
                // the body of a redirect is not kept
                if (in_array($response["status"], array(301, 302, 303, 307, 308), true)) {
                    return strlen($data);
                }
                $response["size"] += strlen($data);
                if ($response["size"] > $maxSize) {
                    $isTooLarge = true;
                    return 0;
                }
                if (is_null($handle)) {
                    $response["body"] .= $data;
                } elseif (fwrite($handle, $data) !== strlen($data)) {
                    return 0;
                }
                return strlen($data);
            }
        );
        if (filter_var($target["host"], FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = array($target["host"] . ":" . $target["port"] . ":" . ((strpos($ip, ":") !== false) ? "[" . $ip . "]" : $ip));
        }
        if (defined("CURLOPT_PROTOCOLS_STR")) {
            $options[CURLOPT_PROTOCOLS_STR] = ($target["scheme"] === "https") ? "https" : "http";
        } else {
            $options[CURLOPT_PROTOCOLS] = ($target["scheme"] === "https") ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
        }
        curl_setopt_array($curl, $options);
        $isOk = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        unset($curl);
        if (!is_null($handle)) {
            fclose($handle);
        }
        if ($isTooLarge) {
            $reason = "too-large";
            return null;
        }
        if (($isOk === false) || ($errno != 0)) {
            $reason = ($errno == 28) ? "timeout" : "download-error";
            Manager::$detail = $target["host"] . ": " . $error;
            return null;
        }
        return $response;
    }
}
