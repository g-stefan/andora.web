<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The remote catalog (admin/package/catalog): the checks of the downloads
// (\Andora\Web\Package\Remote: https only, no local / private addresses,
// redirects, size, timeout), the index format (Registry::parseIndex), the
// registries (page and cli.php registry), the index fetched only by the AJAX
// request of the page, install and upgrade from a registry (sha256, stage,
// confirm, migrate). The registry is a second PHP built-in web server; the
// config package.registryAllowLocal lets the site reach it (http, 127.0.0.1).

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function password_hash_andora($password)
{
    $salt = bin2hex(random_bytes(32));
    return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $password), false);
}

function add_user($db, $name, $email, $password, $groupName)
{
    $statement = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"is_confirmed\", \"password\", \"uuid\") VALUES (?, ?, 1, 1, ?, ?)");
    $statement->execute([$name, $email, password_hash_andora($password), "uuid-" . $email]);
    $userId = intval($db->lastInsertId());
    $group = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $group->execute([$groupName]);
    $db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$userId, intval($group->fetchColumn())]);
    return $userId;
}

function package_row($db, $code)
{
    $statement = $db->prepare("SELECT * FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetch();
}

function cli($site, $arguments)
{
    $command = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($site->root . "/_site/andora/web/package/cli.php");
    foreach ($arguments as $argument) {
        $command .= " " . escapeshellarg($argument);
    }
    $output = [];
    exec($command . " 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

// The config of the site for the catalog (config.07.package.php)
function package_config($site, $value)
{
    file_put_contents($site->root . "/config.07.package.php", "<?php\nreturn " . var_export(["package" => $value], true) . ";\n");
}

// The zip of acme/remote <version> (or another code), returns [file, sha256]
function remote_zip($folder, $version, $code = "acme/remote")
{
    $source = $folder . "/source-" . bin2hex(random_bytes(4));
    mkdir($source);
    list($vendor, $name) = explode("/", $code);
    file_put_contents($source . "/package.json", json_encode(["vendor" => $vendor, "name" => $name, "version" => $version, "title" => "Remote", "description" => "From the registry", "require" => ["andora/web" => ">=0.1"]], JSON_UNESCAPED_SLASHES));
    file_put_contents($source . "/index.html", "<!DOCTYPE html><html><body></body></html>");
    $file = $folder . "/files/" . $vendor . "." . $name . ".v" . $version . ".zip";
    $reason = "";
    check("zip written: " . basename($file), \Andora\Web\Package\Zip::write($file, [
        "_site/" . $code . "/package.json" => $source . "/package.json",
        "_site/" . $code . "/index.html" => $source . "/index.html"
    ], $reason));
    TestSite::removeDir($source);
    return [$file, hash_file("sha256", $file)];
}

function write_index($folder, $packages, $name = "index.json")
{
    file_put_contents($folder . "/files/" . $name, json_encode(["packages" => $packages], JSON_UNESCAPED_SLASHES));
}

// Requests of the registry server to a path
function hits($folder, $path)
{
    $file = $folder . "/hits.log";
    if (!is_file($file)) {
        return 0;
    }
    return count(array_filter(explode("\n", file_get_contents($file)), function ($line) use ($path) {
        return $line === $path;
    }));
}

// The answer of the catalog component (the AJAX request of the page)
function catalog($browser, $payload = [])
{
    $browser->get("admin/package/catalog");
    return $browser->postComponent("admin/package/catalog", "catalog", $payload);
}

function add_registry($browser, $name, $url)
{
    $form = $browser->component("admin/package/catalog", "dialog", ["component" => "form-registry-add"]);
    return $browser->submit("admin/package/catalog", $form->body, "dialog.form-registry-add_form", ["name" => $name, "url" => $url]);
}

function remove_registry($browser, $name)
{
    $form = $browser->postComponent("admin/package/catalog", "dialog", ["component" => "form-registry-remove", "init" => "true", "registry" => $name]);
    return $browser->submit("admin/package/catalog", $form->body, "dialog.form-registry-remove_form", []);
}

// Install / Upgrade of the catalog: download, confirm ("I trust this
// package"), migrate. Returns [download answer, confirm form, migrate answer].
function install($browser, $registry, $id)
{
    $download = $browser->postComponent("admin/package/catalog", "dialog", ["component" => "form-download", "registry" => $registry, "package__id" => $id]);
    $confirm = new TestResponse();
    $migrate = new TestResponse();
    if (preg_match("/\"stage__id\",\"([0-9a-f]{32})\"/", $download->body, $match)) {
        $confirm = $browser->postComponent("admin/package/catalog", "dialog", ["component" => "form-confirm", "init" => "true", "stage__id" => $match[1]]);
        $commit = $browser->submit("admin/package/catalog", $confirm->body, "dialog.form-confirm_form", ["trust" => "1"]);
        if (preg_match("/\\[\"component\",\"form-migrate\"\\],\\[\"package__id\",\"([a-z0-9_\\-]+)\"\\]/", $commit->body, $match)) {
            $migrate = $browser->postComponent("admin/package/catalog", "dialog", ["component" => "form-migrate", "package__id" => $match[1]]);
        }
    }
    return [$download, $confirm, $migrate];
}

// The action of a package row of the catalog
function row_action($html, $code)
{
    return preg_match("/data-package=\"" . preg_quote($code, "/") . "\" data-action=\"([a-z]+)\"/", $html, $match) ? $match[1] : "";
}

// A registry for Remote::get / Registry outside of the site
class StubConfig
{
    protected $values;

    public function __construct($values)
    {
        $this->values = $values;
    }

    public function has($name)
    {
        return array_key_exists($name, $this->values);
    }

    public function get($name, $default = null)
    {
        $value = array_key_exists($name, $this->values) ? $this->values[$name] : $default;
        return is_array($value) ? new StubConfig($value) : $value;
    }
}

class StubWeb
{
    public $config;

    public function __construct($values)
    {
        $this->config = new StubConfig($values);
    }

    public function get($class)
    {
        return $this->config;
    }
}

$site = (new TestSite())->start();
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
define("ANDORA", 1);
require($site->root . "/_site/andora/web/package/zip.php");
require($site->root . "/_site/andora/web/package/manifest.php");
require($site->root . "/_site/andora/web/package/manager.php");
require($site->root . "/_site/andora/web/package/remote.php");
require($site->root . "/_site/andora/web/package/registry.php");
\Andora\Web\Package\Zip::$useZipArchive = false;
use \Andora\Web\Package\Remote;
use \Andora\Web\Package\Registry;

// The registry: files/ served as they are, the paths of the router answer
// redirects, a large or a slow answer; every request is written to hits.log
$folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "andora-test-registry-" . bin2hex(random_bytes(6));
mkdir($folder . "/files", 0777, true);
file_put_contents($folder . "/router.php", <<<'PHP'
<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
file_put_contents(__DIR__ . "/hits.log", $path . "\n", FILE_APPEND | LOCK_EX);
$port = $_SERVER["SERVER_PORT"];
$redirect = array(
    "/redirect-local" => "http://127.0.0.1:" . $port . "/index.json",
    "/redirect-relative" => "/index.json",
    "/redirect-private" => "http://10.0.0.1/index.json",
    "/redirect-metadata" => "http://169.254.169.254/latest/meta-data/",
    "/redirect-localhost-name" => "http://localhost:" . $port . "/index.json",
    "/redirect-loop" => "/redirect-loop",
    "/redirect-ftp" => "ftp://127.0.0.1/index.json"
);
if (array_key_exists($path, $redirect)) {
    header("Location: " . $redirect[$path], true, 302);
    return true;
}
if ($path === "/big.json") {
    echo str_repeat(" ", 2 * 1048576);
    return true;
}
if ($path === "/slow.json") {
    sleep(3);
    echo "{\"packages\": []}";
    return true;
}
$file = __DIR__ . "/files" . $path;
if (is_file($file)) {
    header("Content-Type: " . ((substr($file, -4) === ".zip") ? "application/zip" : "application/json"));
    readfile($file);
    return true;
}
http_response_code(404);
return true;
PHP);
$registry = new TestServer($folder, $folder . "/router.php");
$local = new StubWeb(["package" => ["registryAllowLocal" => true]]);
$strict = new StubWeb([]);

t_section("addresses");

foreach (["127.0.0.1", "127.1.2.3", "10.1.2.3", "172.16.5.4", "172.31.255.255", "192.168.1.1", "169.254.169.254", "100.64.1.1", "0.0.0.0", "224.0.0.1", "255.255.255.255", "198.18.0.1", "::1", "::", "fe80::1", "fc00::1", "fd12:3456::1", "ff02::1", "::ffff:127.0.0.1", "::ffff:10.0.0.1", "::ffff:7f00:1", "64:ff9b::a00:1", "2002:a00:1::1", "2001:db8::1", "not an address", ""] as $ip) {
    check("refused: " . $ip, !Remote::isAllowedAddress($ip, false));
}
foreach (["8.8.8.8", "1.1.1.1", "172.32.0.1", "2606:4700:4700::1111", "::ffff:8.8.8.8"] as $ip) {
    check("allowed: " . $ip, Remote::isAllowedAddress($ip, false));
}
check("registryAllowLocal: 127.0.0.1", Remote::isAllowedAddress("127.0.0.1", true));
check("registryAllowLocal: ::1", Remote::isAllowedAddress("::1", true));
check("registryAllowLocal: 10.0.0.1 still refused", !Remote::isAllowedAddress("10.0.0.1", true));
check("registryAllowLocal: 169.254.169.254 still refused", !Remote::isAllowedAddress("169.254.169.254", true));
$reason = "";
check("localhost resolves to a refused address", is_null(Remote::resolve("localhost", false, $reason)));
check_eq("localhost: address-not-allowed", "address-not-allowed", $reason);

t_section("URLs");

check("https", !is_null(Remote::parseURL("https://packages.example/index.json", false)));
check("http refused", is_null(Remote::parseURL("http://packages.example/index.json", false)));
check("http with registryAllowLocal", !is_null(Remote::parseURL("http://127.0.0.1:8080/index.json", true)));
check("ftp refused", is_null(Remote::parseURL("ftp://packages.example/index.json", true)));
check("file refused", is_null(Remote::parseURL("file:///etc/passwd", true)));
check("user and password refused", is_null(Remote::parseURL("https://user:secret@packages.example/index.json", false)));
check("space refused", is_null(Remote::parseURL("https://packages .example/index.json", false)));
check("backslash refused", is_null(Remote::parseURL("https://packages.example\\@127.0.0.1/", false)));
check_eq("IPv6 host", "::1", Remote::parseURL("https://[::1]:8443/index.json", false)["host"] ?? "");
check_eq("port", 8443, Remote::parseURL("https://[::1]:8443/index.json", false)["port"] ?? 0);
check_eq("relative url", "https://a.example/repo/acme.zip", Remote::resolveURL("https://a.example/repo/index.json", "acme.zip"));
check_eq("absolute path", "https://a.example:8443/acme.zip", Remote::resolveURL("https://a.example:8443/repo/index.json", "/acme.zip"));
check_eq("other host", "https://b.example/acme.zip", Remote::resolveURL("https://a.example/repo/index.json", "https://b.example/acme.zip"));
check_eq("scheme relative", "https://c.example/acme.zip", Remote::resolveURL("https://a.example/repo/index.json", "//c.example/acme.zip"));

t_section("index format");

$sha = str_repeat("ab", 32);
$index = json_encode(["packages" => [
    ["vendor" => "acme", "name" => "blog", "version" => "1.0.0", "title" => "Blog", "description" => "Articles", "url" => "acme.blog.v1.0.0.zip", "sha256" => strtoupper($sha)],
    ["vendor" => "acme", "name" => "blog", "version" => "1.2.0", "url" => "https://cdn.example/acme.blog.v1.2.0.zip", "sha256" => $sha],
    ["vendor" => "acme", "name" => "blog", "version" => "1.1.0", "url" => "acme.blog.v1.1.0.zip", "sha256" => $sha],
    ["vendor" => "Acme", "name" => "upper", "version" => "1.0.0", "url" => "x.zip", "sha256" => $sha],
    ["vendor" => "vendor", "name" => "reserved", "version" => "1.0.0", "url" => "x.zip", "sha256" => $sha],
    ["vendor" => "acme", "name" => "short-version", "version" => "1.0", "url" => "x.zip", "sha256" => $sha],
    ["vendor" => "acme", "name" => "bad-sha", "version" => "1.0.0", "url" => "x.zip", "sha256" => "1234"],
    ["vendor" => "acme", "name" => "no-url", "version" => "1.0.0", "sha256" => $sha],
    "not an object"
]]);
$packages = Registry::parseIndex($index, "https://packages.example/repo/index.json", $reason);
check_eq("only the valid packages", ["acme/blog"], array_keys(is_array($packages) ? $packages : []));
check_eq("the highest version", "1.2.0", $packages["acme/blog"]["version"] ?? "");
check_eq("absolute url kept", "https://cdn.example/acme.blog.v1.2.0.zip", $packages["acme/blog"]["url"] ?? "");
check_eq("title defaults to the code", "acme/blog", $packages["acme/blog"]["title"] ?? "");
$packages = Registry::parseIndex(json_encode(["packages" => [["vendor" => "acme", "name" => "blog", "version" => "1.0.0", "url" => "files/acme.blog.v1.0.0.zip", "sha256" => strtoupper($sha)]]]), "https://packages.example/repo/index.json", $reason);
check_eq("relative url", "https://packages.example/repo/files/acme.blog.v1.0.0.zip", $packages["acme/blog"]["url"] ?? "");
check_eq("sha256 in lower case", $sha, $packages["acme/blog"]["sha256"] ?? "");
check("not JSON: invalid-index", is_null(Registry::parseIndex("{not json", "https://a.example/", $reason)) && ($reason === "invalid-index"));
check("packages not a list: invalid-index", is_null(Registry::parseIndex("{\"packages\": {\"a\": 1}}", "https://a.example/", $reason)) && ($reason === "invalid-index"));
check("no packages: invalid-index", is_null(Registry::parseIndex("[]", "https://a.example/", $reason)) && ($reason === "invalid-index"));

t_section("downloads");

write_index($folder, []);
check_eq("index", "{\"packages\":[]}", Remote::get($local, $registry->url("index.json"), 1048576, 10, $reason));
$hits = hits($folder, "/index.json");
check("https only", is_null(Remote::get($strict, $registry->url("index.json"), 1048576, 10, $reason)) && ($reason === "invalid-url"));
check("https to 127.0.0.1: refused", is_null(Remote::get($strict, "https://127.0.0.1:" . $registry->port . "/index.json", 1048576, 10, $reason)) && ($reason === "address-not-allowed"));
check("https to localhost: refused", is_null(Remote::get($strict, "https://localhost:" . $registry->port . "/index.json", 1048576, 10, $reason)) && ($reason === "address-not-allowed"));
check("https to 2130706433 (127.0.0.1): refused", is_null(Remote::get($strict, "https://2130706433:" . $registry->port . "/index.json", 1048576, 10, $reason)));
check("https to [::ffff:127.0.0.1]: refused", is_null(Remote::get($strict, "https://[::ffff:127.0.0.1]:" . $registry->port . "/index.json", 1048576, 10, $reason)) && ($reason === "address-not-allowed"));
check_eq("refused before connecting", $hits, hits($folder, "/index.json"));
check_eq("redirect", "{\"packages\":[]}", Remote::get($local, $registry->url("redirect-local"), 1048576, 10, $reason));
check_eq("relative redirect", "{\"packages\":[]}", Remote::get($local, $registry->url("redirect-relative"), 1048576, 10, $reason));
check("redirect to a private address: refused", is_null(Remote::get($local, $registry->url("redirect-private"), 1048576, 10, $reason)) && ($reason === "address-not-allowed"));
check_contains("the refused address", "10.0.0.1", \Andora\Web\Package\Manager::$detail);
check("redirect to the metadata address: refused", is_null(Remote::get($local, $registry->url("redirect-metadata"), 1048576, 10, $reason)) && ($reason === "address-not-allowed"));
check("redirect to ftp: refused", is_null(Remote::get($local, $registry->url("redirect-ftp"), 1048576, 10, $reason)) && ($reason === "invalid-url"));
check("redirect loop", is_null(Remote::get($local, $registry->url("redirect-loop"), 1048576, 10, $reason)) && ($reason === "too-many-redirects"));
check_eq("redirect loop: at most 4 requests", 4, hits($folder, "/redirect-loop"));
check("size limit", is_null(Remote::get($local, $registry->url("big.json"), 1048576, 10, $reason)) && ($reason === "too-large"));
check("not found", is_null(Remote::get($local, $registry->url("missing.json"), 1048576, 10, $reason)) && ($reason === "http-error") && (\Andora\Web\Package\Manager::$detail === "404"));
$time = microtime(true);
check("timeout", is_null(Remote::get($local, $registry->url("slow.json"), 1048576, 1, $reason)) && ($reason === "timeout"));
check("timeout: stopped after about 1 s", microtime(true) - $time < 2.5);
$file = $folder . "/downloaded.bin";
check_eq("download to a file: its size", 15, Remote::get($local, $registry->url("index.json"), 1048576, 10, $reason, $file));
check_eq("download to a file", "{\"packages\":[]}", file_get_contents($file));

t_section("catalog page");

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$page = $owner->get("admin/package");
check_contains("admin/package: tab Catalog", "admin/package/catalog\"", $page->body);
$page = $owner->get("admin/package/catalog");
check_eq("catalog: 200", 200, $page->code);
check_contains("catalog: tabs", "id=\"package-tabs\"", $page->body);
check_contains("catalog: loaded by AJAX", "Loading the registries", $page->body);
check_contains("not configured", "id=\"catalog-not-configured\"", catalog($owner)->body);
$db = $site->db();
add_user($db, "Administrator", "administrator@example.test", $password, "administrator");
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("administrator (no package.manage): 401", 401, $administrator->get("admin/package/catalog")->code);
check_eq("administrator: AJAX 401", 401, $administrator->postComponent("admin/package/catalog", "catalog", [])->code);

t_section("registries");

check_contains("http refused", "invalid-url", add_registry($owner, "plain", $registry->url("index.json"))->body);
check_contains("invalid name", "invalid-registry-name", add_registry($owner, "Bad Name", "https://packages.example/index.json")->body);
check_contains("official is reserved", "invalid-registry-name", add_registry($owner, "official", "https://packages.example/index.json")->body);
check_contains("https to the registry server", "registry-add-ok", add_registry($owner, "loopback", "https://127.0.0.1:" . $registry->port . "/index.json")->body);
check_contains("the same name again", "registry-exists", add_registry($owner, "loopback", "https://packages.example/index.json")->body);
check_contains("config file", "'loopback'", "" . $site->config("config.10.package-registry.php"));
$hits = hits($folder, "/index.json");
$html = catalog($owner)->body;
check_contains("loopback refused when the page is opened", "Refused: the host is a local or private address", $html);
check_eq("loopback: no request", $hits, hits($folder, "/index.json"));
check_contains("remove", "registry-remove-ok", remove_registry($owner, "loopback")->body);
check_not_contains("removed from the config", "'loopback'", "" . $site->config("config.10.package-registry.php"));
check_contains("remove an unknown registry", "registry-not-found", remove_registry($owner, "loopback")->body);

package_config($site, ["registryAllowLocal" => true]);
list($zip100, $sha100) = remote_zip($folder, "1.0.0");
list($zipOther, $shaOther) = remote_zip($folder, "1.0.0", "acme/other");
write_index($folder, [
    ["vendor" => "acme", "name" => "remote", "version" => "1.0.0", "title" => "Remote <b>", "description" => "From the registry", "url" => "acme.remote.v1.0.0.zip", "sha256" => $sha100],
    ["vendor" => "acme", "name" => "tampered", "version" => "1.0.0", "url" => "acme.remote.v1.0.0.zip", "sha256" => str_repeat("0", 64)],
    ["vendor" => "acme", "name" => "mismatch", "version" => "1.0.0", "url" => "acme.other.v1.0.0.zip", "sha256" => $shaOther]
]);
check_contains("add with registryAllowLocal", "registry-add-ok", add_registry($owner, "test", $registry->url("index.json"))->body);

t_section("the index is fetched only when the catalog is opened");

$hits = hits($folder, "/index.json");
$owner->get("admin/dashboard");
$owner->get("admin/package");
$owner->get("admin/package/catalog");
$owner->component("admin/package/catalog", "catalog");
check_eq("no fetch by the pages nor by a GET of the component", $hits, hits($folder, "/index.json"));
$html = catalog($owner)->body;
check_eq("one fetch by the AJAX request", $hits + 1, hits($folder, "/index.json"));
check_contains("registry listed", "data-registry=\"test\"", $html);
check_contains("3 packages", "3 packages", $html);
check_eq("acme/remote: install", "install", row_action($html, "acme/remote"));
check_contains("the title is escaped", "Remote &lt;b&gt;", $html);
check_contains("install button", "data-catalog-package=\"acme_remote\"", $html);
check_eq("the cache is used with cached=1", $hits + 1, (function () use ($owner, $folder) {
    catalog($owner, ["cached" => "1"]);
    return hits($folder, "/index.json");
})());

t_section("install from the registry");

list($download, $confirm, $migrate) = install($owner, "test", "acme_remote");
check_contains("downloaded and staged", "form-confirm", $download->body);
check_contains("confirm: the package", "From the registry", $confirm->body);
check_contains("confirm: unsigned", "Unsigned", $confirm->body);
check_contains("confirm: I trust this package", "name=\"trust\"", $confirm->body);
check_contains("installed", "install-ok", $migrate->body);
$db = $site->db();
check_eq("acme/remote 1.0.0 installed", "1.0.0", package_row($db, "acme/remote")["version"] ?? "");
$db = null;
check_eq("no zip left in the downloads", [], glob($site->root . "/_repository/package/download/*"));
$html = catalog($owner, ["cached" => "1"])->body;
check_eq("acme/remote: installed", "installed", row_action($html, "acme/remote"));

list($download) = install($owner, "test", "acme_tampered");
check_contains("sha256 checked", "checksum-mismatch", $download->body);
list($download) = install($owner, "test", "acme_mismatch");
check_contains("the zip is the package of the index", "catalog-mismatch", $download->body);
list($download) = install($owner, "test", "acme_unknown");
check_contains("a package not in the index", "package-not-in-catalog", $download->body);
list($download) = install($owner, "nothing", "acme_remote");
check_contains("an unknown registry", "catalog-expired", $download->body);
check_eq("no stage left", [], glob($site->root . "/_repository/package/staging/*"));

t_section("upgrade from the registry");

list($zip110, $sha110) = remote_zip($folder, "1.1.0");
write_index($folder, [
    ["vendor" => "acme", "name" => "remote", "version" => "1.1.0", "url" => "/acme.remote.v1.1.0.zip", "sha256" => $sha110]
]);
$html = catalog($owner)->body;
check_eq("acme/remote: upgrade", "upgrade", row_action($html, "acme/remote"));
list($download, $confirm, $migrate) = install($owner, "test", "acme_remote");
check_contains("upgraded", "upgrade-ok", $migrate->body);
$db = $site->db();
check_eq("acme/remote 1.1.0", "1.1.0", package_row($db, "acme/remote")["version"] ?? "");
$db = null;
write_index($folder, [
    ["vendor" => "acme", "name" => "remote", "version" => "1.0.0", "url" => "acme.remote.v1.0.0.zip", "sha256" => $sha100]
]);
check_eq("an older version in the index", "older", row_action(catalog($owner)->body, "acme/remote"));

t_section("expired catalog");

foreach (glob($site->root . "/_repository/package/catalog/*.json") as $file) {
    $data = json_decode(file_get_contents($file), true);
    $data["time"] = time() - 7200;
    file_put_contents($file, json_encode($data));
}
list($download) = install($owner, "test", "acme_remote");
check_contains("too old", "catalog-expired", $download->body);

t_section("errors of a registry");

check_contains("add redirect", "registry-add-ok", add_registry($owner, "private", $registry->url("redirect-private"))->body);
file_put_contents($folder . "/files/broken.json", "{not json");
check_contains("add broken", "registry-add-ok", add_registry($owner, "broken", $registry->url("broken.json"))->body);
$html = catalog($owner)->body;
check_contains("redirect to a private address", "Refused: the host is a local or private address: 10.0.0.1", $html);
check_contains("invalid index", "The index of the registry is not valid", $html);
check_contains("the other registry still works", "data-registry=\"test\"", $html);
check_eq("acme/remote still listed", "older", row_action($html, "acme/remote"));
remove_registry($owner, "private");
remove_registry($owner, "broken");

t_section("official registry");

write_index($folder, [
    ["vendor" => "acme", "name" => "official-package", "version" => "2.0.0", "url" => "acme.remote.v1.0.0.zip", "sha256" => $sha100]
], "official.json");
package_config($site, ["registryAllowLocal" => true, "registry" => $registry->url("official.json")]);
$html = catalog($owner)->body;
check_contains("official registry listed", "data-registry=\"official\"", $html);
check_not_contains("the official registry can not be removed", "data-registry-remove=\"official\"", $html);
check_contains("a registry added can", "data-registry-remove=\"test\"", $html);
check_eq("official package", "install", row_action($html, "acme/official-package"));
check_contains("remove official: refused", "registry-not-found", remove_registry($owner, "official")->body);

t_section("file operations turned off");

package_config($site, ["registryAllowLocal" => true, "upload" => false]);
$html = catalog($owner)->body;
check_not_contains("no install button", "data-catalog-package=", $html);
check_contains("the reason", "turned off (package.upload)", $html);
list($download) = install($owner, "test", "acme_remote");
check_contains("download refused", "file-operations-disabled", $download->body);
package_config($site, ["registryAllowLocal" => true]);

t_section("command line");

list($code, $output) = cli($site, ["registry", "list"]);
check_eq("list: exit 0", 0, $code);
check_contains("list", "test", $output);
list($code, $output) = cli($site, ["registry", "add", "cli", "ftp://packages.example/index.json"]);
check_eq("add ftp: exit 1", 1, $code);
check_contains("add ftp: reason", "Error: invalid-url", $output);
list($code, $output) = cli($site, ["registry", "add", "cli", $registry->url("index.json")]);
check_eq("add: exit 0", 0, $code);
check_contains("add: in the config", "'cli'", "" . $site->config("config.10.package-registry.php"));
list($code, $output) = cli($site, ["registry", "remove", "cli"]);
check_eq("remove: exit 0", 0, $code);
list($code, $output) = cli($site, ["registry", "remove", "cli"]);
check_eq("remove again: exit 1", 1, $code);
check_contains("remove again: reason", "Error: registry-not-found", $output);
check_contains("registry operations are logged", "registry-add", implode("", array_map("file_get_contents", glob($site->root . "/_log/*.package.log"))));

check_eq("no router errors", "", $site->routerLog());
$registry->stop();
TestSite::removeDir($folder);
$site->stop();
t_done();
