<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// Signed packages. _site/<vendor>/<name>/package.sig (JSON: algorithm, key,
// signature) signs the list of the other files of the folder:
//   andora-package-signature-v1\n<vendor>/<name>\n
//   <sha256 of the file> <path in the folder>\n   (sorted by path)
// with ECDSA P-256 / SHA-256 (OpenSSL). "key" is the fingerprint of the public
// key: sha256 of its DER, in hex.
//
// Trusted keys: config packageTrust => [name => ["key" => public key PEM,
// "vendor" => [vendors it may sign, empty: any]]] (config.09.package-trust.php,
// written by "cli.php trust add"). A vendor named by a key is pinned: its
// packages are accepted only signed by a key that names it.
class Signature
{
    public const FILE = "package.sig";
    public const ALGORITHM = "ecdsa-p256-sha256";
    public const HEADER = "andora-package-signature-v1";
    public const TRUST_CONFIG = "config.09.package-trust.php";

    // States of verify()
    public const UNSIGNED = "unsigned";
    public const UNKNOWN_KEY = "unknown-key";
    public const KEY_NOT_ALLOWED = "key-not-allowed"; // a trusted key that may not sign this vendor
    public const INVALID = "invalid"; // the files do not match the signature of a trusted key
    public const TRUSTED = "trusted";

    // OpenSSL on Windows wants a configuration file to make keys
    protected static function opensslOptions(&$configFile)
    {
        $configFile = tempnam(sys_get_temp_dir(), "andora-openssl");
        file_put_contents($configFile, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
        return array(
            "private_key_type" => OPENSSL_KEYTYPE_EC,
            "curve_name" => "prime256v1",
            "config" => $configFile
        );
    }

    // A new key pair (PEM), false if OpenSSL can not make it
    public static function generateKey(&$privatePem, &$publicPem)
    {
        if (!function_exists("openssl_pkey_new")) {
            return false;
        }
        $configFile = null;
        $options = self::opensslOptions($configFile);
        $key = openssl_pkey_new($options);
        $isOk = ($key !== false) && openssl_pkey_export($key, $privatePem, null, $options);
        if ($isOk) {
            $publicPem = openssl_pkey_get_details($key)["key"];
        }
        @unlink($configFile);
        while (openssl_error_string() !== false) {
        }
        return $isOk;
    }

    // sha256 (hex) of the DER of a public key PEM, null if it is not one
    public static function fingerprint($publicPem)
    {
        if (!is_string($publicPem) || (preg_match("/-----BEGIN PUBLIC KEY-----(.+)-----END PUBLIC KEY-----/s", $publicPem, $match) != 1)) {
            return null;
        }
        $der = base64_decode(preg_replace("/\\s+/", "", $match[1]), true);
        return ($der === false) ? null : hash("sha256", $der);
    }

    // The public key of a private key PEM
    public static function publicKeyOf($privatePem)
    {
        $key = @openssl_pkey_get_private($privatePem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        return is_array($details) ? $details["key"] : null;
    }

    // The signed text of a package folder
    public static function message($folder, $code)
    {
        $files = array();
        self::listFiles(rtrim($folder, "/"), "", $files);
        ksort($files, SORT_STRING);
        $message = self::HEADER . "\n" . $code . "\n";
        foreach ($files as $path => $file) {
            $message .= hash_file("sha256", $file) . " " . $path . "\n";
        }
        return $message;
    }

    protected static function listFiles($path, $prefix, &$files)
    {
        foreach (scandir($path) as $item) {
            if (($item === ".") || ($item === "..") || is_link($path . "/" . $item)) {
                continue;
            }
            $name = (strlen($prefix) > 0) ? $prefix . "/" . $item : $item;
            if (is_dir($path . "/" . $item)) {
                self::listFiles($path . "/" . $item, $name, $files);
                continue;
            }
            if ($name === self::FILE) {
                continue;
            }
            $files[$name] = $path . "/" . $item;
        }
    }

    // Write <folder>/package.sig, signed with the private key
    public static function sign($folder, $code, $privatePem, &$reason)
    {
        $publicPem = self::publicKeyOf($privatePem);
        $fingerprint = self::fingerprint($publicPem);
        if (is_null($fingerprint)) {
            $reason = "invalid-key";
            return false;
        }
        $signature = "";
        if (!openssl_sign(self::message($folder, $code), $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
            $reason = "unable-to-sign";
            return false;
        }
        $content = json_encode(array(
            "algorithm" => self::ALGORITHM,
            "key" => $fingerprint,
            "signature" => base64_encode($signature)
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents(rtrim($folder, "/") . "/" . self::FILE, $content) === false) {
            $reason = "unable-to-write-files";
            return false;
        }
        return true;
    }

    // name => ["key" => PEM, "vendor" => [...], "fingerprint" => hex]
    public static function trustedKeys($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $list = array();
        if (!$config->has("packageTrust")) {
            return $list;
        }
        foreach ($config->getArray("packageTrust") as $name => $item) {
            if (!is_array($item) || !array_key_exists("key", $item)) {
                continue;
            }
            $fingerprint = self::fingerprint($item["key"]);
            if (is_null($fingerprint)) {
                continue;
            }
            $vendor = array();
            if (array_key_exists("vendor", $item) && is_array($item["vendor"])) {
                foreach ($item["vendor"] as $value) {
                    if (Manifest::isName($value)) {
                        $vendor[] = $value;
                    }
                }
            }
            $list["" . $name] = array("key" => $item["key"], "vendor" => $vendor, "fingerprint" => $fingerprint);
        }
        return $list;
    }

    // Check package.sig of a folder against the trusted keys: state, keyName, fingerprint
    public static function verify($web, $folder, $vendor, $name, $keys = null)
    {
        $result = new \stdClass();
        $result->state = self::UNSIGNED;
        $result->keyName = "";
        $result->fingerprint = "";
        $file = rtrim($folder, "/") . "/" . self::FILE;
        if (!is_file($file)) {
            return $result;
        }
        $data = json_decode("" . file_get_contents($file), true);
        if (!is_array($data) || (($data["algorithm"] ?? "") !== self::ALGORITHM) || !is_string($data["key"] ?? null) || !is_string($data["signature"] ?? null)) {
            $result->state = self::INVALID;
            return $result;
        }
        $result->fingerprint = $data["key"];
        if (is_null($keys)) {
            $keys = self::trustedKeys($web);
        }
        foreach ($keys as $keyName => $key) {
            if (!hash_equals($key["fingerprint"], strtolower($data["key"]))) {
                continue;
            }
            $signature = base64_decode($data["signature"], true);
            if (($signature === false) || (openssl_verify(self::message($folder, $vendor . "/" . $name), $signature, $key["key"], OPENSSL_ALGO_SHA256) !== 1)) {
                $result->state = self::INVALID;
                $result->keyName = $keyName;
                return $result;
            }
            $result->keyName = $keyName;
            $result->state = ((count($key["vendor"]) == 0) || in_array($vendor, $key["vendor"], true)) ? self::TRUSTED : self::KEY_NOT_ALLOWED;
            return $result;
        }
        $result->state = self::UNKNOWN_KEY;
        return $result;
    }

    // What an upload may do: "accept", "confirm" (needs "I trust this
    // package") or a refusal reason: invalid-signature, signature-required
    public static function decide($web, $vendor, $result)
    {
        if ($result->state === self::INVALID) {
            return "invalid-signature";
        }
        $keys = self::trustedKeys($web);
        $isPinned = false;
        foreach ($keys as $keyName => $key) {
            if (in_array($vendor, $key["vendor"], true)) {
                $isPinned = true;
            }
        }
        // a pinned vendor: only a key that names it (not a key for any vendor)
        if ($isPinned) {
            $isNamed = ($result->state === self::TRUSTED) && array_key_exists($result->keyName, $keys) && in_array($vendor, $keys[$result->keyName]["vendor"], true);
            return $isNamed ? "accept" : "signature-required";
        }
        if ($result->state === self::TRUSTED) {
            return "accept";
        }
        $config = $web->get(\XYO\Web\Config::class);
        if ($config->has("package") && $config->get("package")->get("requireSignature", false)) {
            return "signature-required";
        }
        return "confirm";
    }
}
