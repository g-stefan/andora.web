<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Profile page (user menu - Profile): avatar upload / replace / remove and the
// user/avatar route, description, change of password; avatar deleted with the user.

require(__DIR__ . "/_lib/site.php");

$site = (new TestSite())->start();
$site->setup("Administrator", "admin@example.test", "Admin-Password-12");

$admin = $site->browser();
$admin->login("admin@example.test", "Admin-Password-12");

$avatarFolder = $site->root . "/_repository/avatar";

function avatar_files($folder)
{
    $list = glob($folder . "/*.png");
    return is_array($list) ? $list : [];
}

// A PNG data: URL, like the one the browser sends after the crop
function png_data_url($width, $height)
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagepng($image);
    return "data:image/png;base64," . base64_encode(ob_get_clean());
}

t_section("user menu and page");

$page = $admin->get("admin/dashboard");
check_contains("user menu has Profile", "admin/profile", $page->body);
check_contains("user menu avatar placeholder", "id=\"sidebar-user-avatar\"", $page->body);
check_contains("no avatar: user icon", "lucide-user-round", $page->body);

$page = $admin->get("admin/profile");
check_eq("admin/profile is 200", 200, $page->code);
check_contains("profile form", "form-profile_form", $page->body);
check_contains("password form", "form-password_form", $page->body);
check_contains("e-mail shown", "admin@example.test", $page->body);

$anonymous = $site->browser();
$response = $anonymous->get("admin/profile");
check_eq("not logged in: redirect to login", 302, $response->code);

t_section("avatar and description");

if (!function_exists("imagecreatetruecolor")) {
    t_skip("avatar upload", "the PHP GD extension is missing");
} else {
    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => png_data_url(400, 300),
        "avatar_delete" => "0",
        "description" => "Hello <b>world</b>"
    ]);
    check_eq("save answers 200", 200, $response->code);
    check_contains("profile saved", "Profile saved.", $response->body);
    check_contains("user menu avatar updated", "sidebar-user-avatar", $response->body);

    $user = $site->userByEmail("admin@example.test");
    $userId = intval($user["id"]);
    check_eq("description saved", "Hello <b>world</b>", $user["description"]);
    check("avatar file name saved", preg_match("/^" . $userId . "-[0-9a-f]{32}\\.png$/", "" . $user["avatar"]) === 1);
    $files = avatar_files($avatarFolder);
    check_eq("one avatar file", 1, count($files));
    $size = @getimagesize($avatarFolder . "/" . $user["avatar"]);
    check("avatar is a PNG", ($size !== false) && ($size[2] == IMAGETYPE_PNG));
    check("avatar is 256 x 256", ($size !== false) && ($size[0] == 256) && ($size[1] == 256));

    $page = $admin->get("admin/profile");
    check_not_contains("description is escaped", "Hello <b>world</b>", $page->body);
    check_contains("description shown", "Hello &lt;b&gt;world&lt;/b&gt;", $page->body);
    $version = substr($user["avatar"], strlen($userId . "-"), 32);
    check_contains("avatar URL in the user menu", "user/avatar?id=" . $userId . "&amp;v=" . $version, $page->body);

    $response = $admin->get("user/avatar", ["id" => $userId, "v" => $version]);
    check_eq("user/avatar is 200", 200, $response->code);
    check_eq("user/avatar is a PNG", "image/png", $response->header("content-type"));
    check_eq("user/avatar nosniff", "nosniff", $response->header("x-content-type-options"));
    check("user/avatar body is the image", strncmp($response->body, "\x89PNG", 4) == 0);

    $response = $anonymous->get("user/avatar", ["id" => $userId]);
    check("user/avatar refused when not logged in", $response->code != 200);
    $response = $admin->get("user/avatar", ["id" => "999"]);
    check_eq("user/avatar of a user without avatar is 404", 404, $response->code);
    $response = $admin->get("user/avatar", ["id" => "../config"]);
    check_eq("user/avatar with an invalid id is 404", 404, $response->code);
    $response = $anonymous->get("_repository/avatar/" . $user["avatar"]);
    check_eq("avatar file is not served directly", 403, $response->code);

    // A new image replaces the previous file
    $previous = $user["avatar"];
    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => png_data_url(256, 256),
        "avatar_delete" => "0",
        "description" => "Hello <b>world</b>"
    ]);
    $user = $site->userByEmail("admin@example.test");
    check("new avatar file name", $user["avatar"] !== $previous);
    check_eq("previous avatar file deleted", 1, count(avatar_files($avatarFolder)));
    check("new avatar file exists", is_file($avatarFolder . "/" . $user["avatar"]));

    // Not an image
    $current = $user["avatar"];
    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => "data:image/png;base64," . base64_encode("<?php echo 1; ?>"),
        "avatar_delete" => "0",
        "description" => "Changed"
    ]);
    check_contains("invalid image refused", "not a valid image", $response->body);
    $user = $site->userByEmail("admin@example.test");
    check_eq("avatar unchanged after an invalid image", $current, $user["avatar"]);
    check_eq("description unchanged after an invalid image", "Hello <b>world</b>", $user["description"]);

    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => "data:text/html;base64," . base64_encode("<b>x</b>"),
        "avatar_delete" => "0",
        "description" => "Changed"
    ]);
    check_contains("data: URL of another type refused", "not a valid image", $response->body);

    // Description too long
    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => "",
        "avatar_delete" => "0",
        "description" => str_repeat("x", 256)
    ]);
    check_not_contains("description of 256 characters refused", "Profile saved.", $response->body);
    $user = $site->userByEmail("admin@example.test");
    check_eq("description unchanged", "Hello <b>world</b>", $user["description"]);

    // Remove the avatar
    $response = $admin->submit("admin/profile", $page->body, "form-profile_form", [
        "avatar" => "",
        "avatar_delete" => "1",
        "description" => ""
    ]);
    check_contains("avatar removed", "Profile saved.", $response->body);
    $user = $site->userByEmail("admin@example.test");
    check_eq("avatar field cleared", "", "" . $user["avatar"]);
    check_eq("description cleared", "", "" . $user["description"]);
    check_eq("avatar file deleted", 0, count(avatar_files($avatarFolder)));
    $response = $admin->get("user/avatar", ["id" => $userId]);
    check_eq("user/avatar after remove is 404", 404, $response->code);
}

t_section("password");

$fields = ["currentPassword" => "Admin-Password-12", "password" => "New-Password-345", "passwordConfirmation" => "New-Password-345"];

$response = $admin->submit("admin/profile", $page->body, "form-password_form", array_merge($fields, ["currentPassword" => "Wrong-Password-12"]));
check_contains("wrong current password refused", "The current password is not correct.", $response->body);

$response = $admin->submit("admin/profile", $page->body, "form-password_form", array_merge($fields, ["passwordConfirmation" => "New-Password-346"]));
check_contains("different confirmation refused", "The passwords must match!", $response->body);

$response = $admin->submit("admin/profile", $page->body, "form-password_form", array_merge($fields, ["password" => "weak", "passwordConfirmation" => "weak"]));
check_not_contains("weak password refused", "Password changed.", $response->body);

$response = $admin->submit("admin/profile", $page->body, "form-password_form", array_merge($fields, ["password" => "Admin-Password-12", "passwordConfirmation" => "Admin-Password-12"]));
check_contains("same password refused", "must be different", $response->body);

$before = $site->userByEmail("admin@example.test");
$response = $admin->submit("admin/profile", $page->body, "form-password_form", $fields);
check_contains("password changed", "Password changed.", $response->body);
check_not_contains("passwords are not sent back", "New-Password-345", $response->body);
$after = $site->userByEmail("admin@example.test");
check("password hash changed", $before["password"] !== $after["password"]);

$response = $admin->get("admin/profile");
check_eq("current session still open", 200, $response->code);

$other = $site->browser();
$other->login("admin@example.test", "Admin-Password-12");
check_eq("old password refused", 302, $other->get("admin/dashboard")->code);
$other = $site->browser();
$other->login("admin@example.test", "New-Password-345");
check_eq("new password works", 200, $other->get("admin/dashboard")->code);

t_section("avatar deleted with the user");

$db = $site->db();
$db->prepare("INSERT INTO \"andora-web-user\" (\"name\", \"email\", \"enabled\", \"password\", \"uuid\") VALUES (?, ?, 1, '', ?)")
    ->execute(["User 02", "user02@example.test", "uuid-02"]);
$user02 = $site->userByEmail("user02@example.test");
@mkdir($avatarFolder, 0755, true);
$file02 = $avatarFolder . "/" . $user02["id"] . "-" . str_repeat("a", 32) . ".png";
file_put_contents($file02, "png");
$db->prepare("UPDATE \"andora-web-user\" SET \"avatar\" = ? WHERE \"id\" = ?")->execute([basename($file02), $user02["id"]]);

$response = $other->postComponent("admin/user", "dialog", ["component" => "form-delete", "init" => "true", "user__id" => $user02["id"]]);
$response = $other->submit("admin/user", $response->body, "dialog.form-delete_form", []);
check("user deleted", $site->userByEmail("user02@example.test") === false);
check("avatar file of the deleted user removed", !is_file($file02));

check_eq("no router errors", "", $site->routerLog());

t_done();
