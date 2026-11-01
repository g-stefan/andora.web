<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Signing tool for package authors, without a site (no configuration, no
// database). Command line only:
//   php tool.php keygen <private.pem> <public.pem>
//   php tool.php fingerprint <public.pem>
//   php tool.php sign <package folder> <private.pem>
//   php tool.php verify <package folder> <public.pem>
// <package folder> is _site/<vendor>/<name> (the folder name gives the
// package code). Keep the private key out of the package and of the site.

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit(1);
}

define("ANDORA", 1);
require_once(__DIR__ . "/manifest.php");
require_once(__DIR__ . "/manager.php");
require_once(__DIR__ . "/signature.php");

use \Andora\Web\Package\Signature;
use \Andora\Web\Package\Manifest;

function tool_fail($message)
{
    fwrite(STDERR, "Error: " . $message . "\n");
    exit(1);
}

// [folder, vendor, name] of a package folder with a valid package.json
function tool_package($folder)
{
    $folder = rtrim(str_replace("\\", "/", "" . $folder), "/");
    $name = basename($folder);
    $vendor = basename(dirname($folder));
    $reason = "";
    if (!is_dir($folder) || is_null(Manifest::load($folder . "/", $vendor, $name, $reason))) {
        tool_fail("not a package folder (_site/<vendor>/<name> with a valid package.json): " . $folder . ($reason ? " (" . $reason . ")" : ""));
    }
    return array($folder, $vendor, $name);
}

$argv = $GLOBALS["argv"];
$command = $argv[1] ?? "help";

switch ($command) {
    case "keygen":
        if (count($argv) < 4) {
            tool_fail("keygen <private.pem> <public.pem>");
        }
        if (file_exists($argv[2])) {
            tool_fail("the private key file exists, not overwritten: " . $argv[2]);
        }
        $private = "";
        $public = "";
        if (!Signature::generateKey($private, $public)) {
            tool_fail("OpenSSL can not make an EC P-256 key");
        }
        if ((file_put_contents($argv[2], $private) === false) || (file_put_contents($argv[3], $public) === false)) {
            tool_fail("unable to write the key files");
        }
        @chmod($argv[2], 0600);
        echo Signature::fingerprint($public) . "\n";
        exit(0);
    case "fingerprint":
        $fingerprint = Signature::fingerprint("" . @file_get_contents($argv[2] ?? ""));
        if (is_null($fingerprint)) {
            tool_fail("not a public key PEM");
        }
        echo $fingerprint . "\n";
        exit(0);
    case "sign":
        if (count($argv) < 4) {
            tool_fail("sign <package folder> <private.pem>");
        }
        list($folder, $vendor, $name) = tool_package($argv[2]);
        $reason = "";
        if (!Signature::sign($folder, $vendor . "/" . $name, "" . @file_get_contents($argv[3]), $reason)) {
            tool_fail($reason);
        }
        echo $vendor . "/" . $name . ": signed (" . Signature::fingerprint(Signature::publicKeyOf(file_get_contents($argv[3]))) . ")\n";
        exit(0);
    case "verify":
        if (count($argv) < 4) {
            tool_fail("verify <package folder> <public.pem>");
        }
        list($folder, $vendor, $name) = tool_package($argv[2]);
        $public = "" . @file_get_contents($argv[3]);
        $fingerprint = Signature::fingerprint($public);
        if (is_null($fingerprint)) {
            tool_fail("not a public key PEM");
        }
        $result = Signature::verify(null, $folder, $vendor, $name, array("key" => array("key" => $public, "vendor" => array(), "fingerprint" => $fingerprint)));
        echo $vendor . "/" . $name . ": " . $result->state . "\n";
        exit($result->state === Signature::TRUSTED ? 0 : 1);
}

echo "php tool.php keygen <private.pem> <public.pem>\n";
echo "php tool.php fingerprint <public.pem>\n";
echo "php tool.php sign <package folder> <private.pem>\n";
echo "php tool.php verify <package folder> <public.pem>\n";
exit($command === "help" ? 0 : 1);
