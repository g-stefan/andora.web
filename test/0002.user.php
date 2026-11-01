<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// User flows: login, admin access, sign-up, e-mail confirmation,
// forgot password / password reset, logout.
// SMTP is not configured, so the e-mails fail; the database state is checked instead.

require(__DIR__ . "/_lib/site.php");

$site = (new TestSite())->start();
$adminPassword = "Admin-Password-12";
$site->setup("Administrator", "admin@example.test", $adminPassword);

t_section("login");

$admin = $site->browser();
$response = $admin->login("admin@example.test", "Wrong-Password-12");
check_contains("wrong password is rejected", "alert-error", $response->body);
$response = $admin->get("admin/user");
check_eq("admin area still closed", 302, $response->code);

$response = $admin->login("admin@example.test", $adminPassword);
check_contains("administrator can log in", "bg-success", $response->body);
check_contains("administrator is sent to admin/dashboard", "admin/dashboard\";},1500);", $response->body);
$response = $admin->get("admin/dashboard");
check_eq("administrator opens admin/dashboard", 200, $response->code);
check_contains("dashboard title is shown", "<title>Admin - Dashboard</title>", $response->body);
check_contains("sidebar shows the user name", "Administrator", $response->body);
check_contains("sidebar shows the user e-mail", "admin@example.test", $response->body);
foreach (["admin/user", "admin/user-group", "admin/permission", "admin/settings"] as $path) {
    check_eq("administrator opens " . $path, 200, $admin->get($path)->code);
}

t_section("sign-up");

$password = "User-Password-12";
$visitor = $site->browser();
$page = $visitor->get("user/sign-up");
$response = $visitor->submit("user/sign-up", $page->body, "form_form", [
    "name" => "Plain User",
    "email" => "user@example.test",
    "password" => $password,
    "passwordConfirmation" => $password
]);
check_eq("sign-up answers 200", 200, $response->code);
// No SMTP in the test: the account exists, the confirmation e-mail can not be sent
check_contains("sign-up reports the missing e-mail configuration", "configuration-error", $response->body);
$user = $site->userByEmail("user@example.test");
check("account created by sign-up", $user !== false);
check_eq("account is not confirmed yet", 0, intval($user["is_confirmed"]));
$statement = $site->db()->prepare("SELECT \"g\".\"name\" FROM \"andora-web-user-x-user-group\" AS \"l\" JOIN \"andora-web-user-group\" AS \"g\" ON \"g\".\"id\" = \"l\".\"user_group__id\" WHERE \"l\".\"user__id\" = ? AND \"l\".\"enabled\" = 1");
$statement->execute([$user["id"]]);
check_eq("account created by sign-up is in the user group", "user", implode(",", $statement->fetchAll(PDO::FETCH_COLUMN)));

$response = $visitor->submit("user/sign-up", $page->body, "form_form", [
    "name" => "Plain User",
    "email" => "user2@example.test",
    "password" => "Abcdefghijk1",
    "passwordConfirmation" => "Abcdefghijk1"
]);
check("password without a special character is refused", $site->userByEmail("user2@example.test") === false);

t_section("a normal user can not use the admin area");

$response = $visitor->login("user@example.test", $password);
check_contains("user can log in", "bg-success", $response->body);
check("user without admin access stays on the login page", strpos($response->body, "admin/dashboard") === false);
check_eq("admin/dashboard is refused", 401, $visitor->get("admin/dashboard")->code);
check_eq("admin/user is refused", 401, $visitor->get("admin/user")->code);

t_section("e-mail confirmation");

$response = $visitor->get("user/email-confirmation", ["uuid" => "not-a-uuid"]);
check_contains("unknown uuid is an error", "user-not-found", $response->body);
$response = $visitor->get("user/email-confirmation", ["uuid" => $user["uuid"]]);
check_contains("confirmation succeeds", "Confirmation successfully", $response->body);
check_eq("account is confirmed", 1, intval($site->userByEmail("user@example.test")["is_confirmed"]));
$response = $visitor->get("user/email-confirmation", ["uuid" => $user["uuid"]]);
check_contains("second confirmation says already confirmed", "already confirmed", $response->body);

t_section("password reset");

$response = $visitor->get("user/password-reset", ["uuid" => $user["uuid"]]);
check_contains("reset link without a request is refused", "invalid-request", $response->body);

$guest = $site->browser();
$page = $guest->get("user/forgot-password");
$response = $guest->submit("user/forgot-password", $page->body, "form_form", ["email" => "nobody@example.test"]);
check_contains("unknown e-mail gets the same answer", "bg-success", $response->body);
$response = $guest->submit("user/forgot-password", $page->body, "form_form", ["email" => "user@example.test"]);
check_contains("known e-mail gets the same answer", "bg-success", $response->body);
check_eq("forgot password request recorded", 1, intval($site->userByEmail("user@example.test")["is_forgot_password"]));

$page = $guest->get("user/password-reset", ["uuid" => $user["uuid"]]);
check_contains("reset form is shown", "form_form", $page->body);
$newPassword = "New-User-Password-34";
$response = $guest->submit("user/password-reset", $page->body, "form_form", [
    "uuid" => $user["uuid"],
    "password" => $newPassword,
    "passwordConfirmation" => $newPassword
], ["uuid" => $user["uuid"]]);
check_contains("password reset succeeds", "Password reset successfully", $response->body);
$changed = $site->userByEmail("user@example.test");
check_eq("request is closed", 0, intval($changed["is_forgot_password"]));
check_eq("open sessions are closed", "", (string) $changed["session"]);
check_contains("old password does not work", "alert-error", $site->browser()->login("user@example.test", $password)->body);
check_contains("new password works", "bg-success", $site->browser()->login("user@example.test", $newPassword)->body);

$page = $guest->get("user/password-reset", ["uuid" => $user["uuid"]]);
check_contains("the reset link works only once", "invalid-request", $page->body);

// An expired request
$site->db()->prepare("UPDATE \"andora-web-user\" SET \"is_forgot_password\" = 1, \"forgot_password_sent_at\" = ? WHERE \"id\" = ?")
    ->execute([date("Y-m-d H:i:s", time() - 2 * 86400), $user["id"]]);
$page = $guest->get("user/password-reset", ["uuid" => $user["uuid"]]);
check_contains("an expired reset link is refused", "request-expired", $page->body);

t_section("logout");

$response = $admin->get("user/logout");
check_contains("logout succeeds", "Logout successfully", $response->body);
check_eq("admin area closed after logout", 302, $admin->get("admin/dashboard")->code);

check_eq("no router errors", "", $site->routerLog());

$site->stop();
t_done();
