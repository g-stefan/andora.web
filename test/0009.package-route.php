<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Routes of the packages: package.json "route" mounted from the package
// folder (config.08.route.php), the access rules of admin/ for a page a
// package adds below it, no access by the folder path, conflicts, 404 while
// disabled, 503 while an upgrade is pending, uninstall. The routes of
// andora/web itself (admin, user, setup) are mounted the same way.

require(__DIR__ . "/_lib/site.php");

$password = "User-Password-12";

function password_hash_andora($password)
{
    $salt = bin2hex(random_bytes(32));
    return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $password), false);
}

function add_user($db, $name, $email, $password)
{
    $statement = $db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"is_confirmed\", \"password\", \"uuid\") VALUES (?, ?, 1, 1, ?, ?)");
    $statement->execute([$name, $email, password_hash_andora($password), "uuid-" . $email]);
    return intval($db->lastInsertId());
}

function group_id($db, $name)
{
    $statement = $db->prepare("SELECT \"id\" FROM \"andora-web-user-group\" WHERE \"name\" = ?");
    $statement->execute([$name]);
    return intval($statement->fetchColumn());
}

function package_row($db, $code)
{
    $statement = $db->prepare("SELECT * FROM \"andora-web-package\" WHERE \"code\" = ?");
    $statement->execute([$code]);
    return $statement->fetch();
}

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

function manifest($vendor, $name, $version, $route, $extra = [])
{
    return json_encode(array_merge(["vendor" => $vendor, "name" => $name, "version" => $version, "route" => $route], $extra), JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
}

function route_config($site)
{
    $file = $site->root . "/config.08.route.php";
    return is_file($file) ? file_get_contents($file) : "";
}

function open_operation($browser, $operation, $id)
{
    return $browser->postComponent("admin/package", "dialog", ["component" => "form-" . $operation, "init" => "true", "package__id" => $id]);
}

function run_operation($browser, $operation, $id)
{
    $response = open_operation($browser, $operation, $id);
    return $browser->submit("admin/package", $response->body, "dialog.form-" . $operation . "_form", []);
}

function toggle_package($browser, $id)
{
    return $browser->postComponent("admin/package", "datatable.enabled=enabled_" . $id . "/enabled_" . $id, ["id" => $id]);
}

function menu_links($html)
{
    $start = strpos($html, "id=\"sidebar\"");
    if ($start === false) {
        return "";
    }
    $html = substr($html, $start);
    $end = strpos($html, "id=\"sidebar-user-menu\"");
    if ($end !== false) {
        $html = substr($html, 0, $end);
    }
    preg_match_all("/href=\"[^\"]*\\/(admin\\/[a-z\\-]+)\"/", $html, $match);
    return implode(",", $match[1]);
}

// acme/blog: a public page with a language file and a slug, an admin page
// below admin/ with its own permission
function blog_files($version, $withNews = false)
{
    $route = ["blog" => "route/blog", "admin/blog" => "route/admin/blog"];
    if ($withNews) {
        $route["news"] = "route/news";
    }
    $files = [
        "_site/acme/blog/package.json" => manifest("acme", "blog", $version, $route, ["title" => "Blog"]),
        "_site/acme/blog/package.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn \\Acme\\Blog\\Package\\Setup::class;\n",
        "_site/acme/blog/package/setup.php" => "<?php\nnamespace Acme\\Blog\\Package;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Setup extends \\Andora\\Web\\Package\\Base\n{\n    public function seed(&\$reason)\n    {\n        return \$this->registerPermission(\"acme-blog.manage\", \"Manage the blog\", \$reason)\n            && \$this->registerAdminMenu(\"acme-blog\", \"admin.sidebar\", \"admin.home\", \"Blog\", \"newspaper\", \"admin/blog\", \"acme-blog.manage\", 0, 900, \$reason);\n    }\n}\n",
        "_site/acme/blog/route/blog/page.php" => "<?php\nnamespace Acme\\Blog\\Route\\Blog;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Page extends \\XYO\\Web\\Page\n{\n    public function render(\$options = null)\n    {\n        echo \"<p>Blog home: \" . htmlspecialchars(\$this->language->get(\"title\"), ENT_QUOTES) . \" (\" . htmlspecialchars(\$this->info->path, ENT_QUOTES) . \")</p>\";\n    }\n}\n\nreturn Page::class;\n",
        "_site/acme/blog/route/blog/language/en-us.php" => "<?php\ndefined(\"ANDORA\") or die(\"Forbidden\");\nreturn array(\"title\" => \"Blog title " . $version . "\");\n",
        "_site/acme/blog/route/blog/slug.php" => "<?php\nnamespace Acme\\Blog\\Route\\Blog;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Slug extends \\XYO\\Web\\Page\n{\n    public function render(\$options = null)\n    {\n        echo \"<p>Blog post: \" . htmlspecialchars(\$this->info->path, ENT_QUOTES) . \"</p>\";\n    }\n}\n\nreturn Slug::class;\n",
        "_site/acme/blog/route/admin/blog/page.php" => "<?php\nnamespace Acme\\Blog\\Route\\Admin\\Blog;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Page extends \\XYO\\Web\\Page\n{\n    public function init(\$options = null)\n    {\n        \$this->setTitle(\"Admin - Blog\");\n    }\n\n    public function render(\$options = null)\n    {\n        echo \"<p>Blog admin</p>\";\n    }\n}\n\nreturn Page::class;\n",
        "_site/acme/blog/route/admin/blog/authorization.php" => "<?php\nnamespace Acme\\Blog\\Route\\Admin\\Blog;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Authorization extends \\Andora\\Web\\Route\\Admin\\Authorization\n{\n    protected \$permission = \"acme-blog.manage\";\n}\n\nreturn Authorization::class;\n"
    ];
    if ($withNews) {
        $files["_site/acme/blog/route/news/page.php"] = "<?php\nnamespace Acme\\Blog\\Route\\News;\n\ndefined(\"ANDORA\") or die(\"Forbidden\");\n\nclass Page extends \\XYO\\Web\\Page\n{\n    public function render(\$options = null)\n    {\n        echo \"<p>News page</p>\";\n    }\n}\n\nreturn Page::class;\n";
    }
    return $files;
}

$site = (new TestSite())->start();
write_files($site, blog_files("1.0.0"));
// the same route as acme/blog, a route of andora/web, a folder of the site root
write_files($site, [
    "_site/acme/clash/package.json" => manifest("acme", "clash", "1.0.0", ["blog" => "route/blog"]),
    "_site/acme/clash/route/blog/page.php" => "<?php\n",
    "_site/acme/core-clash/package.json" => manifest("acme", "core-clash", "1.0.0", ["user" => "route/user"]),
    "_site/acme/core-clash/route/user/page.php" => "<?php\n",
    "_site/acme/root-clash/package.json" => manifest("acme", "root-clash", "1.0.0", ["contact" => "route/contact"]),
    "_site/acme/root-clash/route/contact/page.php" => "<?php\n",
    "contact/index.html" => "x",
    "_site/acme/no-folder/package.json" => manifest("acme", "no-folder", "1.0.0", ["nothing" => "route/nothing"]),
    "_site/acme/bad-route/package.json" => manifest("acme", "bad-route", "1.0.0", ["../up" => "route/up"]),
    "_site/acme/bad-route-2/package.json" => manifest("acme", "bad-route-2", "1.0.0", ["_site/x" => "route/x"]),
    "_site/acme/bad-route-3/package.json" => manifest("acme", "bad-route-3", "1.0.0", ["x" => "../../outside"])
]);

t_section("andora/web routes are mounted from its package folder");

check("no admin folder in the site root", !is_dir($site->root . "/admin") && !is_dir($site->root . "/user") && !is_dir($site->root . "/setup"));
check("admin routes in the package folder", is_file($site->root . "/_site/andora/web/route/admin/authorization.php"));
$browser = $site->browser();
check_eq("setup before the configuration exists (no route config)", 200, $browser->get("setup")->code);
check_eq("route config not written before the setup", "", route_config($site));
$site->setup("Owner", "owner@example.test", "Owner-Password-12");
check_eq("the folder path of a route of andora/web is not a route", 404, $browser->get("_site/andora/web/route/user/login")->code);

t_section("setup mounts the routes of the packages");

$config = route_config($site);
check_contains("route config: blog", "'blog' => '_site/acme/blog/route/blog'", $config);
check_contains("route config: admin/blog", "'admin/blog' => '_site/acme/blog/route/admin/blog'", $config);
check_contains("route config: installed version", "'acme/blog' => '1.0.0'", $config);
check_not_contains("route config: andora/web is mounted by main.php", "_site/andora/web/route", $config);
check_not_contains("route config: a conflicting package is not mounted", "acme/clash", $config);
$db = $site->db();
check_eq("acme/clash not installed (route conflict)", false, package_row($db, "acme/clash"));
check_eq("acme/core-clash not installed", false, package_row($db, "acme/core-clash"));
check_eq("acme/root-clash not installed", false, package_row($db, "acme/root-clash"));
check_eq("acme/no-folder not installed", false, package_row($db, "acme/no-folder"));
$db = null;

t_section("public page of a package");

$anonymous = $site->browser();
$page = $anonymous->get("blog");
check_eq("blog is 200", 200, $page->code);
check_contains("blog: the page of the package", "Blog home:", $page->body);
check_contains("blog: its language file", "Blog title 1.0.0", $page->body);
check_contains("blog: the route path", "(blog)", $page->body);
$page = $anonymous->get("blog/2026/hello");
check_eq("slug of the package is 200", 200, $page->code);
check_contains("slug of the package", "Blog post: blog/2026/hello", $page->body);
check_eq("the folder path is not a route", 404, $anonymous->get("_site/acme/blog/route/blog")->code);
check_eq("the folder path of the admin page is not a route", 404, $anonymous->get("_site/acme/blog/route/admin/blog")->code);
check_eq("_site/ is not a route", 404, $anonymous->get("_site/acme/blog")->code);

t_section("admin page of a package below admin/");

$response = $anonymous->get("admin/blog");
check_eq("not logged in: redirect to the login (admin/authorization.php)", 302, $response->code);
check_contains("not logged in: to user/login", "user/login", "" . $response->header("location"));
$owner = $site->browser();
$owner->login("owner@example.test", "Owner-Password-12");
$page = $owner->get("admin/blog");
check_eq("root: admin/blog is 200", 200, $page->code);
check_contains("root: the admin page of the package", "Blog admin", $page->body);
check_contains("root: in the admin layout", "id=\"sidebar\"", $page->body);
check_contains("root: its menu entry", "admin/blog", menu_links($page->body));

$db = $site->db();
$administratorId = add_user($db, "Administrator", "administrator@example.test", $password);
$db->prepare("INSERT INTO \"andora-web-user-x-user-group\" (\"user__id\", \"user_group__id\", \"enabled\") VALUES (?, ?, 1)")->execute([$administratorId, group_id($db, "administrator")]);
$db = null;
$administrator = $site->browser();
$administrator->login("administrator@example.test", $password);
check_eq("administrator without acme-blog.manage: 401 (the package's authorization.php)", 401, $administrator->get("admin/blog")->code);
check_eq("administrator: the admin area of Andora still works", 200, $administrator->get("admin/user")->code);
$db = $site->db();
$permissionId = intval($db->query("SELECT \"id\" FROM \"andora-web-permission\" WHERE \"name\" = 'acme-blog.manage'")->fetchColumn());
$db->prepare("INSERT INTO \"andora-web-user-group-x-permission\" (\"user_group__id\", \"permission__id\", \"is_granted\", \"enabled\") VALUES (?, ?, 1, 1)")->execute([group_id($db, "administrator"), $permissionId]);
$db = null;
check_eq("administrator with acme-blog.manage: 200", 200, $administrator->get("admin/blog")->code);

t_section("route conflicts and invalid routes");

$response = run_operation($owner, "install", "acme_clash");
check_contains("same route as another package: refused", "route-conflict", $response->body);
check_contains("the route is named", "\"blog\"", $response->body);
$response = run_operation($owner, "install", "acme_core-clash");
check_contains("a route of andora/web: refused", "route-conflict", $response->body);
check_contains("the route of andora/web is named", "\"user\"", $response->body);
$response = run_operation($owner, "install", "acme_root-clash");
check_contains("a folder of the site root: refused", "route-conflict", $response->body);
$response = run_operation($owner, "install", "acme_no-folder");
check_contains("route folder missing: refused", "route-folder-missing", $response->body);
$page = $owner->get("admin/package");
foreach (["acme_bad-route", "acme_bad-route-2", "acme_bad-route-3"] as $id) {
    $response = open_operation($owner, "details", $id);
    check_contains("invalid route: " . $id, "The routes in package.json are not valid", $response->body);
}
check_eq("login of andora/web still works", 200, $anonymous->get("user/login")->code);

t_section("disabled: 404");

toggle_package($owner, "acme_blog");
check_eq("disabled: blog is 404", 404, $anonymous->get("blog")->code);
check_eq("disabled: admin/blog is 404", 404, $owner->get("admin/blog")->code);
check_not_contains("disabled: not in the route config", "acme/blog", route_config($site));
$response = run_operation($owner, "install", "acme_clash");
check_contains("the route of a disabled package can be taken", "install-ok", $response->body);
check_contains("acme/clash mounted", "_site/acme/clash/route/blog", route_config($site));
toggle_package($owner, "acme_blog");
$db = $site->db();
check_eq("enable refused while another package has the route", 0, intval(package_row($db, "acme/blog")["enabled"]));
$db = null;
toggle_package($owner, "acme_clash");
toggle_package($owner, "acme_blog");
check_eq("enabled again: blog is 200", 200, $anonymous->get("blog")->code);

t_section("upgrade pending: 503");

write_files($site, blog_files("1.1.0", true));
$response = $anonymous->get("blog");
check_eq("files newer than the installed version: blog is 503", 503, $response->code);
check_eq("admin/blog is 503", 503, $owner->get("admin/blog")->code);
check_eq("a new route is not mounted before the upgrade", 404, $anonymous->get("news")->code);
check_eq("the pages of andora/web are not affected", 200, $owner->get("admin/package")->code);
$response = run_operation($owner, "upgrade", "acme_blog");
check_contains("upgrade", "upgrade-ok", $response->body);
check_contains("upgraded: blog is the new version", "Blog title 1.1.0", $anonymous->get("blog")->body);
check_eq("upgraded: the new route", 200, $anonymous->get("news")->code);
check_contains("route config: new installed version", "'acme/blog' => '1.1.0'", route_config($site));

t_section("uninstall");

$response = run_operation($owner, "uninstall", "acme_blog");
check_contains("uninstall", "uninstall-ok", $response->body);
check_eq("uninstalled: blog is 404", 404, $anonymous->get("blog")->code);
check_eq("uninstalled: news is 404", 404, $anonymous->get("news")->code);
check_not_contains("uninstalled: not in the route config", "acme/blog", route_config($site));

check_eq("no router errors", "", $site->routerLog());
$site->stop();
t_done();
