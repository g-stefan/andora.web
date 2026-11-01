<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// The event registry: package.json "event" (provide / listen) written to
// config.11.event.php by the setup and after every package operation; the
// events of andora/web (user.*) and of a package (acme-blog.post-publish,
// dispatched by its page), the results of the listeners in the order of the
// package codes, a failing listener, an undeclared event, disable /
// upgrade pending / uninstall of a listener and of the provider, the checks
// (event-conflict, event-listener-missing, invalid-event), the admin
// Details, cli.php event list | rebuild.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function write_files($site, $files)
{
    foreach ($files as $name => $content) {
        $file = $site->root . "/" . $name;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }
}

function manifest($vendor, $name, $version, $extra = [])
{
    return json_encode(array_merge(["vendor" => $vendor, "name" => $name, "version" => $version, "title" => ucfirst($name), "require" => ["andora/web" => ">=0.1"]], $extra), JSON_UNESCAPED_SLASHES);
}

function set_version($site, $code, $version)
{
    $file = $site->root . "/_site/" . $code . "/package.json";
    $data = json_decode(file_get_contents($file), true);
    $data["version"] = $version;
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
}

// A package with a lifecycle class (the body of the class) and its manifest extra keys
function package_files($vendor, $name, $extra, $body, $files = [])
{
    $guard = "defined(\"ANDORA\") or die(\"Forbidden\");\n";
    $namespace = ucfirst($vendor) . "\\" . str_replace(" ", "", ucwords(str_replace("-", " ", $name)));
    $path = "_site/" . $vendor . "/" . $name . "/";
    return array_merge([
        $path . "package.json" => manifest($vendor, $name, "1.0.0", $extra),
        $path . "index.html" => "<!DOCTYPE html><html><body></body></html>",
        $path . "package.php" => "<?php\n" . $guard . "return \\" . $namespace . "\\Package\\Setup::class;\n",
        $path . "package/setup.php" => "<?php\nnamespace " . $namespace . "\\Package;\n\n" . $guard . "\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n" . $body . "\n}\n"
    ], $files);
}

// What the listeners did: _repository/acme/events.log
function events($site)
{
    $file = $site->root . "/_repository/acme/events.log";
    return is_file($file) ? array_values(array_filter(explode("\n", file_get_contents($file)))) : [];
}

function last_event($site)
{
    $list = events($site);
    return count($list) > 0 ? $list[count($list) - 1] : "";
}

$record = <<<'PHP'
    protected function record($line)
    {
        if (!is_dir(ANDORA_PATH . "_repository/acme")) {
            mkdir(ANDORA_PATH . "_repository/acme", 0777, true);
        }
        file_put_contents(ANDORA_PATH . "_repository/acme/events.log", $line . "\n", FILE_APPEND | LOCK_EX);
    }

PHP;

// acme/blog: provides acme-blog.post-publish (dispatched by /blog), listens to user.create
$blogPage = <<<'PHP'
<?php
namespace Acme\Blog\Route\Blog;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Event;

class Page extends \XYO\Web\Page
{
    public function render($options = null)
    {
        $name = $this->request->get("undeclared", "") ? "acme-blog.not-declared" : "acme-blog.post-publish";
        $results = Event::dispatch($this->web, $name, array("postId" => 7));
        echo "<pre id=\"results\">" . htmlspecialchars(json_encode($results, JSON_UNESCAPED_SLASHES), ENT_QUOTES) . "</pre>";
    }
}

return Page::class;
PHP;
$blogFiles = package_files("acme", "blog", [
    "route" => ["blog" => "route/blog"],
    "event" => [
        "provide" => ["acme-blog.post-publish" => "A post was published: postId"],
        "listen" => ["user.create" => "onUserCreate"]
    ]
], $record . <<<'PHP'
    public function onUserCreate($event)
    {
        $this->record("blog user.create " . $event->get("userId") . " " . $event->source);
    }
PHP, [
    "_site/acme/blog/route/index.html" => "",
    "_site/acme/blog/route/blog/index.html" => "",
    "_site/acme/blog/route/blog/page.php" => $blogPage
]);

// acme/notify: listens to the event of acme/blog; its user.create listener fails
$notifyFiles = package_files("acme", "notify", ["event" => ["listen" => [
    "acme-blog.post-publish" => "onPostPublish",
    "user.create" => "onUserCreate"
]]], $record . <<<'PHP'
    public function onPostPublish($event)
    {
        $this->record("notify " . $event->name . " " . $event->get("postId") . " " . $event->source);
        return "notified " . $event->get("postId");
    }

    public function onUserCreate($event)
    {
        throw new \Exception("the listener of acme/notify failed");
    }
PHP);

// acme/later: listens to the event of acme/blog too (after acme/blog, before acme/notify)
$laterFiles = package_files("acme", "later", ["event" => ["listen" => ["acme-blog.post-publish" => "onPublish"]]], <<<'PHP'
    public function onPublish($event)
    {
        return array("post" => $event->get("postId"), "missing" => $event->get("missing", "default"));
    }
PHP);

// refused: an event of andora/web, an event of acme/blog, a method that does
// not exist, a hook of Base, a private method; broken: invalid event names
$refusedFiles = array_merge(
    package_files("acme", "core-clash", ["event" => ["provide" => ["user.create" => "taken"]]], ""),
    package_files("acme", "blog-clash", ["event" => ["provide" => ["acme-blog.post-publish" => "taken"]]], ""),
    package_files("acme", "no-method", ["event" => ["listen" => ["user.login" => "onMissing"]]], ""),
    package_files("acme", "hook-method", ["event" => ["listen" => ["user.login" => "install"]]], ""),
    package_files("acme", "private-method", ["event" => ["listen" => ["user.login" => "onLogin"]]], "    private function onLogin(\$event)\n    {\n    }\n"),
    ["_site/acme/bad-event/package.json" => manifest("acme", "bad-event", "1.0.0", ["event" => ["provide" => ["Bad Event" => "x"]]])],
    ["_site/acme/bad-listener/package.json" => manifest("acme", "bad-listener", "1.0.0", ["event" => ["listen" => ["user.login" => "on-login"]]])],
    ["_site/acme/bad-key/package.json" => manifest("acme", "bad-key", "1.0.0", ["event" => ["fire" => ["user.login" => "x"]]])]
);

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

function registry($site)
{
    $file = $site->root . "/config.11.event.php";
    if (!is_file($file)) {
        return null;
    }
    // a config file of the site (defined("XYO_WEB") or die)
    defined("XYO_WEB") or define("XYO_WEB", 1);
    $value = include($file);
    return $value["packageEvent"] ?? null;
}

function listener_codes($site, $name)
{
    return array_map(function ($listener) {
        return $listener["package"];
    }, registry($site)["listen"][$name] ?? []);
}

function results($html)
{
    if (preg_match("#<pre id=\"results\">(.*?)</pre>#s", $html, $match) != 1) {
        return null;
    }
    return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
}

function package_log($site)
{
    $content = "";
    foreach (glob($site->root . "/_log/*.package.log") as $file) {
        $content .= file_get_contents($file);
    }
    return $content;
}

$site = (new TestSite())->start();
write_files($site, $blogFiles);
write_files($site, $notifyFiles);
write_files($site, $laterFiles);

t_section("the registry written by the setup");

$site->setup("Owner", "owner@example.test", "Owner-Password-12");
$ownerId = intval($site->userByEmail("owner@example.test")["id"]);
$registry = registry($site);
check("config.11.event.php written", is_array($registry));
check_eq("events of andora/web", ["user.create" => "andora/web", "user.delete" => "andora/web", "user.login" => "andora/web", "user.logout" => "andora/web"], array_intersect_key($registry["provide"] ?? [], array_flip(["user.create", "user.delete", "user.login", "user.logout"])));
check_eq("event of acme/blog", "acme/blog", $registry["provide"]["acme-blog.post-publish"] ?? null);
check_eq("listeners of user.create, by package code", ["acme/blog", "acme/notify"], listener_codes($site, "user.create"));
check_eq("listeners of acme-blog.post-publish", ["acme/later", "acme/notify"], listener_codes($site, "acme-blog.post-publish"));
check_eq("a listener: package, version, method", ["package" => "acme/notify", "version" => "1.0.0", "method" => "onPostPublish"], $registry["listen"]["acme-blog.post-publish"][1] ?? null);
check_eq("no listener of user.login", [], listener_codes($site, "user.login"));
check_eq("user.create of the setup account", "blog user.create " . $ownerId . " andora/web", last_event($site));
check_contains("the failing listener is logged", "the listener of acme\/notify failed", package_log($site));

list($code, $output) = cli($site, ["event", "list"]);
check_eq("cli event list", 0, $code);
check_contains("cli event list: provider", "acme-blog.post-publish", $output);
check_contains("cli event list: listener", "acme/notify                  onPostPublish", $output);

t_section("user.create: a failing listener does not stop the others");

$visitor = $site->browser();
$page = $visitor->get("user/sign-up");
$visitor->submit("user/sign-up", $page->body, "form_form", ["name" => "Signed Up", "email" => "signed@example.test", "password" => $password, "passwordConfirmation" => $password]);
$signedId = intval($site->userByEmail("signed@example.test")["id"]);
check("sign-up made the account", $signedId > 0);
check_eq("acme/blog got user.create", "blog user.create " . $signedId . " andora/web", last_event($site));

t_section("the event of a package");

$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$page = $visitor->get("blog");
check_eq("the page of acme/blog: 200", 200, $page->code);
check_eq("the results of the listeners, in the order of the package codes", ["acme/later" => ["post" => 7, "missing" => "default"], "acme/notify" => "notified 7"], results($page->body));
check_eq("acme/notify got the event of acme/blog", "notify acme-blog.post-publish 7 acme/blog", last_event($site));
check_eq("an undeclared event: no listener", [], results($visitor->get("blog", ["undeclared" => "1"])->body));
check_contains("an undeclared event is logged", "undeclared-event", package_log($site));
check_contains("the name of the undeclared event is logged", "acme-blog.not-declared", package_log($site));

t_section("disable, upgrade pending, uninstall of a listener");

list($code, $output) = cli($site, ["disable", "acme/notify"]);
check_eq("disable acme/notify: " . $output, 0, $code);
check_eq("disabled: not a listener", ["acme/later"], listener_codes($site, "acme-blog.post-publish"));
check_eq("disabled: no result", ["acme/later"], array_keys(results($visitor->get("blog")->body) ?? []));
list($code, $output) = cli($site, ["enable", "acme/notify"]);
check_eq("enable acme/notify: " . $output, 0, $code);
check_eq("enabled: a listener again", ["acme/later", "acme/notify"], listener_codes($site, "acme-blog.post-publish"));

set_version($site, "acme/notify", "1.1.0");
check_eq("upgrade pending: still in the registry", ["acme/later", "acme/notify"], listener_codes($site, "acme-blog.post-publish"));
check_eq("upgrade pending: the newer files are not called", ["acme/later"], array_keys(results($visitor->get("blog")->body) ?? []));
list($code, $output) = cli($site, ["upgrade", "acme/notify"]);
check_eq("upgrade acme/notify: " . $output, 0, $code);
check_eq("upgraded: the version of the listener", "1.1.0", registry($site)["listen"]["acme-blog.post-publish"][1]["version"] ?? null);
check_eq("upgraded: called again", ["acme/later", "acme/notify"], array_keys(results($visitor->get("blog")->body) ?? []));

list($code, $output) = cli($site, ["uninstall", "acme/later"]);
check_eq("uninstall acme/later: " . $output, 0, $code);
check_eq("uninstalled: not a listener", ["acme/notify"], listener_codes($site, "acme-blog.post-publish"));

t_section("disable of the provider");

list($code, $output) = cli($site, ["disable", "acme/blog"]);
check_eq("disable acme/blog: " . $output, 0, $code);
check("disabled provider: its event is gone", !array_key_exists("acme-blog.post-publish", registry($site)["provide"] ?? []));
check_eq("disabled provider: no listener of its event", [], listener_codes($site, "acme-blog.post-publish"));
check_eq("disabled provider: its user.create listener is gone", ["acme/notify"], listener_codes($site, "user.create"));
list($code, $output) = cli($site, ["enable", "acme/blog"]);
check_eq("enable acme/blog: " . $output, 0, $code);
check_eq("enabled provider: the listeners are back", ["acme/notify"], listener_codes($site, "acme-blog.post-publish"));

t_section("checks");

write_files($site, $refusedFiles);
foreach ([
    ["acme/core-clash", "event-conflict: user.create"],
    ["acme/blog-clash", "event-conflict: acme-blog.post-publish"],
    ["acme/no-method", "event-listener-missing: user.login => onMissing"],
    ["acme/hook-method", "event-listener-missing: user.login => install"],
    ["acme/private-method", "event-listener-missing: user.login => onLogin"]
] as list($package, $error)) {
    list($code, $output) = cli($site, ["install", $package]);
    check_eq("install " . $package . " refused", 1, $code);
    check_contains("install " . $package . ": " . $error, $error, $output);
}
list($code, $output) = cli($site, ["list"]);
foreach (["acme/bad-event", "acme/bad-listener", "acme/bad-key"] as $package) {
    check("invalid event in package.json: " . $package . " broken", preg_match("#" . preg_quote($package, "#") . "\\s+.*broken#", $output) == 1);
}
check_eq("the refused packages are not in the registry", ["acme/notify"], listener_codes($site, "acme-blog.post-publish"));
check_eq("acme/blog keeps its event", "acme/blog", registry($site)["provide"]["acme-blog.post-publish"] ?? null);

t_section("admin details");

$response = $owner->postComponent("admin/package", "dialog", ["component" => "form-details", "init" => "true", "package__id" => "acme_blog"]);
check_contains("details: events", "acme-blog.post-publish", $response->body);
check_contains("details: listens to", "user.create", $response->body);

t_section("cli event rebuild");

$before = file_get_contents($site->root . "/config.11.event.php");
unlink($site->root . "/config.11.event.php");
list($code, $output) = cli($site, ["event", "rebuild"]);
check_eq("cli event rebuild: " . $output, 0, $code);
check_eq("the same registry", $before, @file_get_contents($site->root . "/config.11.event.php"));

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
