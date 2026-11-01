<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Signed packages: package.sig made by tool.php (keygen, sign, verify),
// trusted keys (cli.php trust add / list / remove, config.09), the upload
// decision (accept, confirm "I trust this package", refused: invalid
// signature, signature required for a pinned vendor or with
// package.requireSignature), cli.php deploy --trust and verify.

require(__DIR__ . "/_lib/site.php");

function run_php($arguments)
{
    $command = escapeshellarg(PHP_BINARY);
    foreach ($arguments as $argument) {
        $command .= " " . escapeshellarg($argument);
    }
    $output = [];
    exec($command . " 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

function tool($site, $arguments)
{
    return run_php(array_merge([$site->root . "/_site/andora/web/package/tool.php"], $arguments));
}

function cli($site, $arguments)
{
    return run_php(array_merge([$site->root . "/_site/andora/web/package/cli.php"], $arguments));
}

// A package folder _log/build-<n>/_site/<vendor>/<name>/ with $files, signed
// with $privateKey (null: unsigned); returns the folder
function build_package($site, $vendor, $name, $version, $privateKey = null, $files = [])
{
    static $count = 0;
    $count++;
    $folder = $site->root . "/_log/build-" . $count . "/_site/" . $vendor . "/" . $name;
    mkdir($folder . "/sub", 0777, true);
    file_put_contents($folder . "/package.json", json_encode(["vendor" => $vendor, "name" => $name, "version" => $version, "title" => ucfirst($name)], JSON_UNESCAPED_SLASHES));
    file_put_contents($folder . "/sub/data.txt", "data of " . $vendor . "/" . $name);
    foreach ($files as $file => $content) {
        file_put_contents($folder . "/" . $file, $content);
    }
    if (!is_null($privateKey)) {
        list($code, $output) = tool($site, ["sign", $folder, $privateKey]);
        check_eq("signed: " . $vendor . "/" . $name . " " . $version, 0, $code);
    }
    return $folder;
}

// The zip of a package folder (names _site/<vendor>/<name>/...)
function zip_package($site, $folder)
{
    $base = dirname(dirname(dirname($folder)));
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        $path = str_replace("\\", "/", $item->getPathname());
        $files[substr($path, strlen(str_replace("\\", "/", $base)) + 1)] = $path;
    }
    $zip = $folder . ".zip";
    $reason = "";
    \Andora\Web\Package\Zip::write($zip, $files, $reason);
    return $zip;
}

function upload($browser, $zip)
{
    $form = $browser->component("admin/package", "dialog", ["component" => "form-upload"]);
    $response = $browser->submitFile("admin/package", $form->body, "dialog.form-upload_form", [], "package", $zip);
    return preg_match("/\"stage__id\",\"([0-9a-f]{32})\"/", $response->body, $match) ? $match[1] : null;
}

function confirm_form($browser, $zip)
{
    $stageId = upload($browser, $zip);
    check("uploaded: " . basename($zip), !is_null($stageId));
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-confirm", "init" => "true", "stage__id" => $stageId]);
}

// Commit the confirm form, then the migration; returns [commit, migration]
function commit($browser, $confirm, $fields = [])
{
    $commit = $browser->submit("admin/package", $confirm->body, "dialog.form-confirm_form", $fields);
    $migrate = new TestResponse();
    if (preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $commit->body, $match)) {
        $migrate = $browser->postComponent("admin/package", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
    }
    return [$commit, $migrate];
}

function package_version($site, $code)
{
    $statement = $site->db()->prepare("SELECT \"version\" FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetchColumn();
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
\Andora\Web\Package\Zip::$useZipArchive = false;
$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$keys = $site->root . "/_log/keys";
mkdir($keys);

t_section("tool.php");

list($code, $output) = tool($site, ["help"]);
check_eq("help: exit 0", 0, $code);
check_contains("help: commands", "keygen", $output);
foreach (["acme", "other", "xyo", "any"] as $name) {
    list($code, $output) = tool($site, ["keygen", $keys . "/" . $name . "-private.pem", $keys . "/" . $name . "-public.pem"]);
    check_eq("keygen " . $name . ": exit 0", 0, $code);
    check("keygen " . $name . ": prints the fingerprint", preg_match("/^[0-9a-f]{64}$/", trim($output)) == 1);
}
list($code, $output) = tool($site, ["keygen", $keys . "/acme-private.pem", $keys . "/x.pem"]);
check_eq("keygen does not overwrite a private key", 1, $code);
list($code, $fingerprint) = tool($site, ["fingerprint", $keys . "/acme-public.pem"]);
check_eq("fingerprint: exit 0", 0, $code);
$folder = build_package($site, "acme", "signed", "1.0.0", $keys . "/acme-private.pem");
check("package.sig written", is_file($folder . "/package.sig"));
check_contains("package.sig names the key", trim($fingerprint), file_get_contents($folder . "/package.sig"));
list($code, $output) = tool($site, ["verify", $folder, $keys . "/acme-public.pem"]);
check_eq("verify with the key: exit 0", 0, $code);
check_contains("verify with the key", "trusted", $output);
list($code, $output) = tool($site, ["verify", $folder, $keys . "/other-public.pem"]);
check_eq("verify with another key: exit 1", 1, $code);
list($code, $output) = tool($site, ["sign", $site->root . "/_log", $keys . "/acme-private.pem"]);
check_eq("sign a folder that is not a package: exit 1", 1, $code);
check_eq("tool.php from the web is not served", true, $owner->get("_site/andora/web/package/tool.php")->code != 200 || strlen($owner->get("_site/andora/web/package/tool.php")->body) == 0);

t_section("signed by an unknown key: confirm");

$signedZip = zip_package($site, $folder);
$confirm = confirm_form($owner, $signedZip);
check_contains("signature shown: unknown key", "Signed by an unknown key", $confirm->body);
check_contains("trust checkbox", "name=\"trust\"", $confirm->body);
check_contains("trust checkbox is required", "required", $confirm->body);
list($commit) = commit($owner, $confirm);
check_contains("without the confirmation: refused", "confirm-unsigned", $commit->body);
check("not installed", package_version($site, "acme/signed") === false);
check("the refused upload is removed", count(glob($site->root . "/_repository/package/staging/*", GLOB_ONLYDIR)) == 0);

t_section("trusted key");

list($code, $output) = cli($site, ["trust", "add", "acme", $keys . "/acme-public.pem", "--vendor=acme"]);
check_eq("trust add: exit 0", 0, $code);
check("trust add: config.09 written", is_file($site->root . "/config.09.package-trust.php"));
list($code, $output) = cli($site, ["trust", "list"]);
check_contains("trust list: the key", "acme", $output);
check_contains("trust list: its fingerprint", trim($fingerprint), $output);
list($code, $output) = cli($site, ["trust", "add", "bad name", $keys . "/acme-public.pem"]);
check_eq("trust add: invalid name refused", 1, $code);
list($code, $output) = cli($site, ["trust", "add", "bad", $keys . "/acme-private.pem"]);
check_eq("trust add: a private key is not a public key", 1, $code);

$confirm = confirm_form($owner, $signedZip);
check_contains("signature shown: trusted", "Signed by a trusted key: acme", $confirm->body);
check_not_contains("no trust checkbox for a trusted package", "name=\"trust\"", $confirm->body);
list($commit, $migrate) = commit($owner, $confirm);
check_contains("trusted: installed without the confirmation", "install-ok", $migrate->body);
$details = $owner->postComponent("admin/package", "dialog", ["component" => "form-details", "init" => "true", "package__id" => "acme_signed"]);
check_contains("details: signature of the installed folder", "Signed by a trusted key: acme", $details->body);
list($code, $output) = cli($site, ["verify", "acme/signed"]);
check_eq("cli verify: exit 0", 0, $code);
check_contains("cli verify", "trusted (acme)", $output);
check_contains("package log: the signature of the upload", "signature: trusted (acme)", "" . @file_get_contents($site->root . "/_log/" . date("Y-m-d") . ".package.log"));

t_section("changed after signing: refused");

$folder = build_package($site, "acme", "signed", "1.1.0", $keys . "/acme-private.pem");
file_put_contents($folder . "/sub/data.txt", "changed");
$confirm = confirm_form($owner, zip_package($site, $folder));
check_contains("signature shown: invalid", "Invalid: the files do not match the signature", $confirm->body);
check_not_contains("no submit for an invalid signature", "action.upgrade.submit", $confirm->body);
list($commit) = commit($owner, $confirm, ["trust" => "1"]);
check_contains("invalid signature refused, even with the confirmation", "invalid-signature", $commit->body);
check_eq("still 1.0.0", "1.0.0", package_version($site, "acme/signed"));
$folder = build_package($site, "acme", "signed", "1.2.0", $keys . "/acme-private.pem");
file_put_contents($folder . "/added.php", "<?php\n");
$confirm = confirm_form($owner, zip_package($site, $folder));
check_contains("a file added after signing: invalid", "Invalid: the files do not match the signature", $confirm->body);
file_put_contents($folder . "/package.sig", "{not json");
$confirm = confirm_form($owner, zip_package($site, $folder));
check_contains("a broken package.sig: invalid", "Invalid: the files do not match the signature", $confirm->body);

t_section("pinned vendor (a key named for it)");

$confirm = confirm_form($owner, zip_package($site, build_package($site, "acme", "plain", "1.0.0")));
check_contains("unsigned package of a pinned vendor", "Unsigned", $confirm->body);
check_contains("refused: must be signed", "Refused: this package must be signed by a trusted key", $confirm->body);
check_not_contains("no trust checkbox", "name=\"trust\"", $confirm->body);
list($commit) = commit($owner, $confirm, ["trust" => "1"]);
check_contains("pinned vendor: refused even with the confirmation", "signature-required", $commit->body);
$confirm = confirm_form($owner, zip_package($site, build_package($site, "acme", "plain", "1.0.0", $keys . "/other-private.pem")));
list($commit) = commit($owner, $confirm, ["trust" => "1"]);
check_contains("pinned vendor signed by an unknown key: refused", "signature-required", $commit->body);

$confirm = confirm_form($owner, zip_package($site, build_package($site, "other", "x", "1.0.0", $keys . "/acme-private.pem")));
check_contains("a trusted key for another vendor", "Signed by a trusted key that may not sign this vendor", $confirm->body);
check_contains("... needs the confirmation", "name=\"trust\"", $confirm->body);
list($commit, $migrate) = commit($owner, $confirm, ["trust" => "1"]);
check_contains("... installed with the confirmation", "install-ok", $migrate->body);

list($code, $output) = cli($site, ["trust", "add", "xyo", $keys . "/xyo-public.pem", "--vendor=xyo,andora"]);
check_eq("trust add xyo for xyo, andora", 0, $code);
list($code, $output) = cli($site, ["trust", "add", "any", $keys . "/any-public.pem"]);
check_eq("trust add a key for any vendor", 0, $code);
foreach (["unsigned" => null, "signed by acme" => $keys . "/acme-private.pem", "signed by a key for any vendor" => $keys . "/any-private.pem"] as $label => $key) {
    $confirm = confirm_form($owner, zip_package($site, build_package($site, "xyo", "demo", "1.0.0", $key)));
    list($commit) = commit($owner, $confirm, ["trust" => "1"]);
    check_contains("xyo/demo " . $label . ": refused", "signature-required", $commit->body);
}
$confirm = confirm_form($owner, zip_package($site, build_package($site, "xyo", "demo", "1.0.0", $keys . "/xyo-private.pem")));
check_contains("xyo/demo signed by the xyo key", "Signed by a trusted key: xyo", $confirm->body);
list($commit, $migrate) = commit($owner, $confirm);
check_contains("xyo/demo signed by the xyo key: installed", "install-ok", $migrate->body);
$confirm = confirm_form($owner, zip_package($site, build_package($site, "other", "any", "1.0.0", $keys . "/any-private.pem")));
list($commit, $migrate) = commit($owner, $confirm);
check_contains("a key for any vendor signs a vendor that is not pinned", "install-ok", $migrate->body);

t_section("package.requireSignature");

file_put_contents($site->root . "/config.07.package.php", "<?php\ndefined(\"XYO_WEB\") or die(\"Forbidden\");\nreturn array('package' => array('requireSignature' => true));\n");
$confirm = confirm_form($owner, zip_package($site, build_package($site, "other", "y", "1.0.0")));
list($commit) = commit($owner, $confirm, ["trust" => "1"]);
check_contains("requireSignature: unsigned refused", "signature-required", $commit->body);
$confirm = confirm_form($owner, zip_package($site, build_package($site, "other", "y", "1.0.0", $keys . "/any-private.pem")));
list($commit, $migrate) = commit($owner, $confirm);
check_contains("requireSignature: signed by a trusted key accepted", "install-ok", $migrate->body);
unlink($site->root . "/config.07.package.php");

t_section("command line");

$zip = zip_package($site, build_package($site, "other", "cli", "1.0.0"));
list($code, $output) = cli($site, ["deploy", $zip]);
check_eq("deploy unsigned without --trust: exit 1", 1, $code);
check_contains("deploy unsigned without --trust: reason", "confirm-unsigned", $output);
list($code, $output) = cli($site, ["deploy", $zip, "--trust"]);
check_eq("deploy unsigned with --trust: exit 0", 0, $code);
check_contains("deploy shows the signature", "signature unsigned", $output);
list($code, $output) = cli($site, ["deploy", zip_package($site, build_package($site, "acme", "plain", "1.0.0")), "--trust"]);
check_eq("deploy of a pinned vendor unsigned: exit 1 even with --trust", 1, $code);
check_contains("deploy of a pinned vendor: reason", "signature-required", $output);
list($code, $output) = cli($site, ["verify", "other/cli"]);
check_eq("cli verify of an unsigned package: exit 1", 1, $code);
check_contains("cli verify of an unsigned package", "unsigned", $output);
list($code, $output) = cli($site, ["trust", "remove", "acme"]);
check_eq("trust remove: exit 0", 0, $code);
list($code, $output) = cli($site, ["trust", "list"]);
check_not_contains("trust remove: not listed", "acme ", $output);
list($code, $output) = cli($site, ["trust", "remove", "acme"]);
check_eq("trust remove of an unknown key: exit 1", 1, $code);
$details = $owner->postComponent("admin/package", "dialog", ["component" => "form-details", "init" => "true", "package__id" => "acme_signed"]);
check_contains("details after the key is removed: unknown key", "Signed by an unknown key", $details->body);

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
