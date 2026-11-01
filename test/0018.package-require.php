<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The requirements of a package on the upload: each one shown with what the
// site has, an install whose requirements are not met is refused before the
// files are replaced; the error of a package check() (its own reason and
// detail) stays open with Copy and Close.

require(__DIR__ . "/_lib/site.php");

// A zip with the files $files (name in the zip => content)
function make_zip($site, $name, $files)
{
    $source = $site->root . "/_log/zip-source-" . bin2hex(random_bytes(4));
    mkdir($source);
    $list = [];
    $k = 0;
    foreach ($files as $entry => $content) {
        $k++;
        file_put_contents($source . "/" . $k, $content);
        $list[$entry] = $source . "/" . $k;
    }
    $file = $site->root . "/_log/" . $name;
    $reason = "";
    check("zip written: " . $name, \Andora\Web\Package\Zip::write($file, $list, $reason));
    TestSite::removeDir($source);
    return $file;
}

// acme/<name> requiring $require, its check() fails with $checkReason (and a detail)
function package_files($name, $require, $checkReason = "")
{
    $check = "";
    if (strlen($checkReason) > 0) {
        $check = "\n    public function check(&\$reason)\n    {\n        \$reason = \"" . $checkReason . "\";\n        \\Andora\\Web\\Package\\Manager::\$detail = \"key length 0\";\n        return false;\n    }\n";
    }
    $namespace = "Acme\\" . ucfirst($name) . "\\Package";
    return [
        "_site/acme/" . $name . "/package.json" => json_encode(["vendor" => "acme", "name" => $name, "version" => "1.0.0", "title" => "Package " . $name, "require" => $require], JSON_UNESCAPED_SLASHES),
        "_site/acme/" . $name . "/package.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\" . $namespace . "\\Setup::class;\n",
        "_site/acme/" . $name . "/index.html" => "<!DOCTYPE html><html><body></body></html>",
        "_site/acme/" . $name . "/package/setup.php" => "<?php\nnamespace " . $namespace . ";\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Setup extends \\Andora\\Web\\Package\\Base\n{" . $check . "}\n"
    ];
}

function upload($browser, $zip)
{
    $form = $browser->component("admin/package", "dialog", ["component" => "form-upload"]);
    return $browser->submitFile("admin/package", $form->body, "dialog.form-upload_form", [], "package", $zip);
}

function stage_id($response)
{
    return preg_match("/\"stage__id\",\"([0-9a-f]{32})\"/", $response->body, $match) ? $match[1] : null;
}

function open_confirm($browser, $stageId)
{
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-confirm", "init" => "true", "stage__id" => $stageId]);
}

// The message-error request of an answer: [reason, detail], null if none
function error_request($response)
{
    if (preg_match("/\\[\"component\",\"message-error\"\\],\\[\"reason\",(\"[^\"]*\")\\],\\[\"detail\",(\"[^\"]*\")\\]/", $response->body, $match)) {
        return [json_decode($match[1]), json_decode($match[2])];
    }
    return null;
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
\Andora\Web\Package\Zip::$useZipArchive = false;

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");

t_section("requirements not met: refused before the files are replaced");

$zip = make_zip($site, "acme.needs.v1.0.0.zip", package_files("needs", ["php" => ">=8.1", "extension" => ["json", "andoramissingext"], "andora/web" => ">=0.1"]));
$stageId = stage_id(upload($owner, $zip));
check("upload accepted", !is_null($stageId));
$confirm = open_confirm($owner, $stageId);
check_contains("confirm: php met, its version", "php &gt;=8.1 <span class=\"text-success\">(" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".", $confirm->body);
check_contains("confirm: the loaded extension", "ext-json <span class=\"text-success\">", $confirm->body);
check_contains("confirm: the missing extension in red", "<span class=\"text-error\">ext-andoramissingext (missing)</span>", $confirm->body);
check_contains("confirm: andora/web met", "andora/web &gt;=0.1 <span class=\"text-success\">", $confirm->body);
check_contains("confirm: refused", "does not meet the requirements", $confirm->body);
check_not_contains("confirm: no Install button", "btn btn-primary", $confirm->body);

// the form posted anyway
$commit = $owner->submit("admin/package", $confirm->body, "dialog.form-confirm_form", ["trust" => "1"]);
check_eq("commit refused: requires-extension", ["requires-extension", "andoramissingext"], error_request($commit));
check("no migration asked", strpos($commit->body, "form-migrate") === false);
check("the files were not copied", !is_dir($site->root . "/_site/acme/needs"));
check("the stage was removed", count(glob($site->root . "/_repository/package/staging/*", GLOB_ONLYDIR) ?: []) == 0);
check_not_contains("the error does not close itself", "setTimeout", $commit->body);

t_section("requirements met: the package check() fails, the error stays open");

$zip = make_zip($site, "acme.failing.v1.0.0.zip", package_files("failing", ["extension" => ["json"]], "acme-failing.openssl-error"));
$stageId = stage_id(upload($owner, $zip));
$confirm = open_confirm($owner, $stageId);
check_contains("confirm: the Install button", "btn btn-primary", $confirm->body);
$commit = $owner->submit("admin/package", $confirm->body, "dialog.form-confirm_form", ["trust" => "1"]);
check("migration asked", preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $commit->body, $match) == 1);
$migrate = $owner->postComponent("admin/package", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
$error = error_request($migrate);
check_eq("install failed: the reason and the detail of the package", ["acme-failing.openssl-error", "key length 0"], $error);
check_not_contains("the error does not close itself", "setTimeout", $migrate->body);

$message = $owner->component("admin/package", "dialog", ["component" => "message-error", "reason" => $error[0], "detail" => $error[1]]);
check_contains("message: the reason and the detail", "acme-failing.openssl-error: key length 0", $message->body);
check_contains("message: Copy", ">Copy</button>", $message->body);
check_contains("message: Close", ">Close</button>", $message->body);
check_contains("message: the text copied", "acme-failing.openssl-error: key length 0", substr($message->body, strpos($message->body, "var text")));

$message = $owner->component("admin/package", "dialog", ["component" => "message-error", "reason" => "requires-extension", "detail" => "openssl"]);
check_contains("message: a known reason, its text", "Requires the PHP extension: openssl", $message->body);
check_contains("message: and its code", ">requires-extension</p>", $message->body);

t_section("details: install not offered while a requirement is missing");

file_put_contents($site->root . "/_site/acme/failing/package.json", json_encode(["vendor" => "acme", "name" => "failing", "version" => "1.0.0", "title" => "Package failing", "require" => ["extension" => ["andoramissingext"]]], JSON_UNESCAPED_SLASHES));
$install = $owner->postComponent("admin/package", "dialog", ["component" => "form-install", "init" => "true", "package__id" => "acme_failing"]);
check_contains("install: the missing extension", "ext-andoramissingext (missing)", $install->body);
check_contains("install: refused", "does not meet the requirements", $install->body);
check_not_contains("install: no Install button", "btn btn-primary", $install->body);

t_done();
