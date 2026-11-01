<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");
require_once(ANDORA_PATH . "_site/vendor/autoload.php");

use \Andora\Web\DataSource\TableUser;
use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\DataSource\TableUserXPermission;
use \Andora\Web\Package\Event;
use \PHPMailer\PHPMailer\PHPMailer;

class User
{
    // Permission that gives access to the administration area (admin/*)
    public const PERMISSION_ADMINISTRATOR = Acl::PERMISSION_SYSTEM_ADMIN;
    // A password reset link is valid for 24 hours
    public const PASSWORD_RESET_TIMEOUT = 86400;

    public static $isAuthorized = null;
    public static $user__id = null;
    public static $user__name = null;
    public static $user__email = null;
    public static $user__avatar = null;

    // Avatars are square PNG images, stored as _repository/avatar/<user id>-<random>.png
    // (the file name is in user.avatar) and served to logged in users by user/avatar
    public const AVATAR_SIZE = 256;
    // Largest uploaded image accepted (decoded bytes) and its largest side in pixels
    public const AVATAR_MAX_BYTES = 4194304;
    public const AVATAR_MAX_SIDE = 8192;
    // user.description is varchar(255)
    public const DESCRIPTION_MAX_LENGTH = 255;

    // At least 12 characters, one upper case letter, one lower case letter, one digit and one of #?!@$%^&*-_
    public static function checkPasswordPolicy($password)
    {
        if (!is_string($password)) {
            return false;
        }
        if (strlen($password) < 12) {
            return false;
        }
        $pattern = "/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*\\-_]).{12,}$/";
        return preg_match($pattern, $password) === 1;
    }

    public static function addUser($web, $name, $email, $password, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $name = trim("" . $name);
        $email = trim("" . $email);

        if (strlen($name) < 2) {
            $reason = "invalid-name";
            return false;
        }

        if (strlen($email) == 0) {
            $reason = "invalid-email";
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $reason = "invalid-email";
            return false;
        }

        if (!self::checkPasswordPolicy($password)) {
            $reason = "invalid-password";
            return false;
        }

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->email = $email;
        if ($table->load(0, 1)) {
            $reason = "already-registered";
            return false;
        }

        $uuid = "";
        for ($count = 0; $count < 16; $count++) {
            $uuid = self::generateUUID();
            $table->clear();
            $table->uuid = $uuid;
            if ($table->load(0, 1)) {
                $uuid = "";
                continue;
            }
            break;
        }
        if (strlen($uuid) == 0) {
            $reason = "uuid-already-exists";
            return false;
        }

        $table->clear();
        $table->name = $name;
        $table->email = $email;
        $table->uuid = $uuid;
        $table->password = self::setPasswordHash($web, $table->email, $password, "hash");
        $table->enabled = 1;
        $table->is_confirmed = 0;
        $table->is_forgot_password = 0;
        $table->created_at = date("Y-m-d H:i:s");

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        // Every account starts in the user group (if it exists)
        $userGroupId = Acl::getSystemGroupId($web, Acl::GROUP_USER);
        if ($userGroupId > 0) {
            if (!Acl::addUserToGroup($web, $table->id, $userGroupId)) {
                $reason = "database-error";
                return false;
            }
        }

        // The packages that listen to user.create (none before the setup wrote the event registry)
        Event::dispatch($web, "user.create", array("userId" => intval($table->id)));

        return true;
    }

    public static function signUp($web, $name, $email, $password, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        if (!self::addUser($web, $name, $email, $password, $reason)) {
            return false;
        }

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->email = trim("" . $email);
        if (!$table->load(0, 1)) {
            $reason = "email-not-found";
            return false;
        }

        return self::sendCheckEmail($web, $table->uuid, $reason);
    }

    // Base URL of the site, with a trailing slash, used for the links sent by e-mail.
    // Set "siteURL" in the configuration, otherwise it is built from the current request.
    public static function getSiteURL($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $siteURL = trim("" . $config->get("siteURL", ""));
        if (strlen($siteURL) > 0) {
            return rtrim($siteURL, "/") . "/";
        }

        $info = $web->get(\XYO\Web\Info::class);
        $scheme = "http";
        if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
            $scheme = "https";
        }
        $host = "localhost";
        if (array_key_exists("HTTP_HOST", $_SERVER)) {
            $host = preg_replace("/[^a-zA-Z0-9\\.\\-:\\[\\]]/", "", $_SERVER["HTTP_HOST"]);
        }
        return $scheme . "://" . $host . $info->site;
    }

    // Send an e-mail using the "smtp" configuration (see admin/settings)
    public static function sendMail($web, $email, $name, $subject, $message, $link, $linkText, &$reason)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if (!$config->has("smtp")) {
            $reason = "configuration-error";
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $configSMTP = $config->get("smtp");

            $mail->isSMTP();
            $mail->Host = $configSMTP->get("server");
            $mail->SMTPAuth = true;
            $mail->Username = $configSMTP->get("username");
            $mail->Password = $configSMTP->get("password");
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $configSMTP->get("port");
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            //Recipients
            $mail->setFrom($configSMTP->get("username"), $configSMTP->get("name"));
            $mail->addAddress($email, $name);

            //Content
            $mail->isHTML(true);
            $mail->Subject = $subject;

            $nameHTML = htmlspecialchars($name, ENT_QUOTES);
            $linkHTML = htmlspecialchars($link, ENT_QUOTES);

            $mail->Body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head><body style=\"font-size: 10pt; font-family: Verdana,Geneva,sans-serif\">";
            $mail->Body .= "<p>Hi " . $nameHTML . ",</p>";
            $mail->Body .= "<p>" . htmlspecialchars($message, ENT_QUOTES) . "</p>";
            $mail->Body .= "<p><a href=\"" . $linkHTML . "\" target=\"_blank\" style=\"padding:6px;margin:3px;background-color:#ffffff;border:1px solid #000000;border-radius:6px;\" rel=\"noopener\">" . htmlspecialchars($linkText, ENT_QUOTES) . "</a></p>";
            $mail->Body .= "<p>&nbsp;</p><p>Thank you!</p>";
            $mail->Body .= "</body></html>";

            $mail->AltBody = "Hi " . $name . ",\r\n\r\n";
            $mail->AltBody .= $message . "\r\n";
            $mail->AltBody .= $link . "\r\n";
            $mail->AltBody .= "\r\nThank you!\r\n";

            $mail->send();
            return true;

        } catch (\Exception $e) {
            $reason = "smtp-mailer-error";
            \XYO\Web\Log::logMessage("smtp", ["datetime" => date("Y-m-d H:i:s"), "message" => $e->getMessage()]);
            return false;
        }
    }

    public static function sendCheckEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $isOk = self::sendMail(
            $web,
            $table->email,
            $table->name,
            "Andora - Confirm your email address",
            "Thanks for signing up! Your Andora account is almost complete. Click the link below to confirm your email address.",
            self::getSiteURL($web) . "user/email-confirmation?uuid=" . rawurlencode($uuid),
            "Confirm email address",
            $reason
        );
        if (!$isOk) {
            return false;
        }

        $table->confirmation_sent_at = date("Y-m-d H:i:s");

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }
        return true;
    }

    public static function emailConfirm($web, $uuid, &$reason)
    {
        $uuid = trim("" . $uuid);
        if (strlen($uuid) == 0) {
            $reason = "user-not-found";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;
        $table->enabled = 1;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        if ($table->is_confirmed) {
            $reason = "already-confirmed";
            return false;
        }

        $table->is_confirmed = 1;
        $table->confirmed_at = date("Y-m-d H:i:s");
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        return true;
    }

    public static function forgotPassword($web, $email, &$reason)
    {
        $email = trim("" . $email);
        if (strlen($email) == 0) {
            $reason = "user-not-found";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->email = $email;
        $table->enabled = 1;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->is_forgot_password = 1;
        $table->forgot_password_sent_at = date("Y-m-d H:i:s");
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        return self::sendPasswordChangeEmail($web, $table->uuid, $reason);
    }

    public static function sendPasswordChangeEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        return self::sendMail(
            $web,
            $table->email,
            $table->name,
            "Andora - Forgot Password",
            "Forgot Password request recorded. Click the link below to set a new password.",
            self::getSiteURL($web) . "user/password-reset?uuid=" . rawurlencode($uuid),
            "Change password",
            $reason
        );
    }

    // True if the uuid belongs to an enabled user with a pending, not expired, forgot password request
    public static function checkPasswordResetRequest($web, $uuid, &$reason)
    {
        $uuid = trim("" . $uuid);
        if (strlen($uuid) == 0) {
            $reason = "invalid-request";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;
        $table->enabled = 1;
        $table->is_forgot_password = 1;

        if (!$table->load(0, 1)) {
            $reason = "invalid-request";
            return false;
        }

        $sentAt = strtotime("" . $table->forgot_password_sent_at);
        if (($sentAt === false) || (time() - $sentAt > self::PASSWORD_RESET_TIMEOUT)) {
            $reason = "request-expired";
            return false;
        }

        return true;
    }

    public static function passwordReset($web, $uuid, $password, &$reason)
    {
        if (!self::checkPasswordResetRequest($web, $uuid, $reason)) {
            return false;
        }

        if (!self::checkPasswordPolicy($password)) {
            $reason = "invalid-password";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = trim("" . $uuid);
        $table->enabled = 1;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->is_forgot_password = 0;
        $table->forgot_password_confirmed_at = date("Y-m-d H:i:s");
        $table->password = self::setPasswordHash($web, $table->email, $password, "hash");
        // Log out every open session
        $table->session = "";

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        // The password is changed, the notification e-mail is informative only
        $reasonMail = "";
        self::sendPasswordResetEmail($web, $uuid, $reasonMail);
        return true;
    }

    public static function sendPasswordResetEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        return self::sendMail(
            $web,
            $table->email,
            $table->name,
            "Andora - Password Changed",
            "This is a confirmation, that your password was changed. You can login now with your new password.",
            self::getSiteURL($web) . "user/login",
            "Login",
            $reason
        );
    }

    public static function login($web, $email, $password, &$reason, &$userId = null)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $info = $web->get(\XYO\Web\Info::class);
        $config = $web->get(\XYO\Web\Config::class);

        $email = trim("" . $email);
        if ((strlen($email) == 0) || (strlen("" . $password) == 0)) {
            $reason = "user-not-found";
            return false;
        }

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->email = $email;
        $table->enabled = 1;
        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        if (!self::checkPasswordHash($web, $table->email, $table->password, $password, "hash")) {
            $reason = "invalid-password";
            return false;
        }

        $configUser = $config->get("user");
        $loginSalt = "";
        if (!is_null($configUser)) {
            $loginSalt = $configUser->get("loginSalt", "");
        }

        $user_rnd = bin2hex(random_bytes(64));
        $user_session = hash("sha512", $user_rnd . "." . $loginSalt . "." . $table->password, false);
        $user__id = $table->id;

        $table->session = $user_session;
        $table->logged_at = date("Y-m-d H:i:s");
        $table->logged_in = 1;
        $table->action = 1;
        $table->action_at = date("Y-m-d H:i:s");

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        self::setSessionCookies($web, "" . $user__id, $user_session);
        $userId = $user__id;

        Event::dispatch($web, "user.login", array("userId" => intval($user__id)));

        return true;
    }

    protected static function setSessionCookies($web, $user__id, $user_session)
    {
        $info = $web->get(\XYO\Web\Info::class);
        $options = [
            "path" => $info->site,
            "secure" => (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"),
            "httponly" => true,
            "samesite" => "Strict"
        ];
        if (strlen($user_session) == 0) {
            // Expire the cookies
            $options["expires"] = 1;
        }
        if (headers_sent()) {
            return;
        }
        setcookie("_user__id", $user__id, $options);
        setcookie("_user_session", $user_session, $options);
    }

    public static function checkSession($web, $id, $session, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        self::$isAuthorized = false;
        self::$user__id = null;
        self::$user__name = null;
        self::$user__email = null;
        self::$user__avatar = null;

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = $id;
        $table->enabled = 1;
        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $tableSession = "" . $table->session;
        if ((strlen($tableSession) == 0) || !hash_equals($tableSession, "" . $session)) {
            $reason = "invalid-session";
            return false;
        }

        $table->action = 1;
        $table->action_at = date("Y-m-d H:i:s");

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        self::$isAuthorized = true;
        self::$user__id = $table->id;
        self::$user__name = $table->name;
        self::$user__email = $table->email;
        self::$user__avatar = $table->avatar;

        return true;
    }

    public static function checkCurrentSession($web, &$reason)
    {
        if (!array_key_exists("_user__id", $_COOKIE)) {
            $reason = "invalid-cookie";
            return false;
        }
        if (!array_key_exists("_user_session", $_COOKIE)) {
            $reason = "invalid-cookie";
            return false;
        }
        if (!is_string($_COOKIE["_user__id"]) || !is_string($_COOKIE["_user_session"])) {
            $reason = "invalid-cookie";
            return false;
        }

        $id = trim($_COOKIE["_user__id"]);
        $session = trim($_COOKIE["_user_session"]);

        if (strlen($id) == 0) {
            $reason = "invalid-user";
            return false;
        }

        $id = intval("" . $id);
        if ($id == 0) {
            $reason = "invalid-user";
            return false;
        }

        if (strlen($session) == 0) {
            $reason = "invalid-session";
            return false;
        }

        return self::checkSession($web, $id, $session, $reason);
    }

    public static function getCurrenUserId(&$reason)
    {
        if (!array_key_exists("_user__id", $_COOKIE) || !is_string($_COOKIE["_user__id"])) {
            $reason = "invalid-cookie";
            return 0;
        }
        $id = trim($_COOKIE["_user__id"]);
        $id = intval("" . $id);
        if ($id == 0) {
            $reason = "invalid-user";
            return 0;
        }
        return $id;
    }

    public static function logoutCurrentSession($web, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        if (!self::checkCurrentSession($web, $reason)) {
            // Remove the cookies anyway
            self::setSessionCookies($web, "", "");
            return false;
        }

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = self::$user__id;
        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->action = 1;
        $table->action_at = date("Y-m-d H:i:s");
        $table->session = "";
        $table->logged_in = 0;

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        $userId = intval(self::$user__id);
        self::$isAuthorized = false;
        self::$user__id = null;
        self::$user__name = null;
        self::$user__email = null;
        self::$user__avatar = null;

        self::setSessionCookies($web, "", "");

        Event::dispatch($web, "user.logout", array("userId" => $userId));

        return true;
    }

    // Find the id of the administrator permission, 0 if it does not exist or is disabled
    public static function getAdministratorPermissionId($web)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsPermission = $dsConnection->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->name = self::PERMISSION_ADMINISTRATOR;
        $dsPermission->enabled = 1;
        if ($dsPermission->load(0, 1)) {
            return intval($dsPermission->id);
        }
        return 0;
    }

    // Create the administrator permission (if missing) and grant it to the user
    public static function grantAdministrator($web, $userId, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        $dsPermission = $dsConnection->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->name = self::PERMISSION_ADMINISTRATOR;
        if (!$dsPermission->load(0, 1)) {
            $dsPermission->clear();
            $dsPermission->name = self::PERMISSION_ADMINISTRATOR;
            $dsPermission->description = "Access to the administration area";
            $dsPermission->enabled = 1;
            if (!$dsPermission->save()) {
                $reason = "database-error";
                return false;
            }
            $dsPermission->clear();
            $dsPermission->name = self::PERMISSION_ADMINISTRATOR;
            if (!$dsPermission->load(0, 1)) {
                $reason = "database-error";
                return false;
            }
        }
        $permissionId = intval($dsPermission->id);

        $dsLink = $dsConnection->getDataSource(TableUserXPermission::class);
        $dsLink->clear();
        $dsLink->user__id = $userId;
        $dsLink->permission__id = $permissionId;
        if ($dsLink->load(0, 1)) {
            $dsLink->is_granted = 1;
            $dsLink->enabled = 1;
        } else {
            $dsLink->clear();
            $dsLink->user__id = $userId;
            $dsLink->permission__id = $permissionId;
            $dsLink->is_granted = 1;
            $dsLink->enabled = 1;
        }
        if (!$dsLink->save()) {
            $reason = "database-error";
            return false;
        }

        return true;
    }

    // True if the user has the system.admin permission (\Andora\Web\Model\Acl)
    public static function isAdministrator($web, $userId)
    {
        $userId = intval($userId);
        if ($userId <= 0) {
            return false;
        }

        return Acl::can($web, Acl::PERMISSION_SYSTEM_ADMIN, $userId);
    }

    public static function setPasswordHash($web, $username, $passwordPlain, $mode)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $configUser = $config->get("user");

        if ($mode === "hash") {
            $salt = bin2hex(random_bytes(32));
            return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $passwordPlain), false);
        }
        if ($mode === "reco") {
            $key = hash("sha512", hash("sha512", strtolower($username), true) . hash("sha512", $configUser->get("passwordRecoSalt"), false), false);
            return "reco:" . self::recoEncode($passwordPlain, pack("H*", $key));
        }
        if ($mode === "plain") {
            return "plain:" . $passwordPlain;
        }
        return "";
    }

    public static function getPasswordHash($web, $username, $passwordPlain, $salt, $mode)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $configUser = $config->get("user");

        if ($mode === "hash") {
            return "hash:" . $salt . "." . hash("sha512", $salt . hash("sha512", $passwordPlain), false);
        }
        if ($mode === "reco") {
            $key = hash("sha512", hash("sha512", strtolower($username), true) . hash("sha512", $configUser->get("passwordRecoSalt"), false), false);
            return "reco:" . self::recoEncode($passwordPlain, pack("H*", $key));
        }
        if ($mode === "plain") {
            return "plain:" . $passwordPlain;
        }
        return "";
    }

    public static function checkPasswordHash($web, $username, $passwordHash, $passwordPlain, $mode)
    {
        $passwordHash = "" . $passwordHash;
        $passwordPlain = "" . $passwordPlain;
        if ($mode === "hash") {
            $list1 = explode(":", $passwordHash, 2);
            if ((count($list1) != 2) || ($list1[0] !== "hash")) {
                return false;
            }
            $list2 = explode(".", $list1[1], 2);
            if (count($list2) != 2) {
                return false;
            }
            $salt = $list2[0];
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, $salt, $mode);
            return hash_equals($passwordHash, $passwordCheck);
        }
        if ($mode === "reco") {
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, "", $mode);
            return hash_equals($passwordHash, $passwordCheck);
        }
        if ($mode === "plain") {
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, "", $mode);
            return hash_equals($passwordHash, $passwordCheck);
        }
        return false;
    }

    public static function recoEncode($in, $key_)
    {
        $out = array();
        $seed = strlen($in);

        for ($k = 0; $k < $seed; ++$k) {
            $out[$k] = ord($in[$k]);
        }

        for ($k = 1; $k < $seed; ++$k) {
            $out[$k] = ($out[$k] ^ $out[$k - 1] ^ $k) & 0xFF;
        }

        $k_ln = strlen($key_);
        if ($k_ln > 0) {
            $k_k = 0;
            for ($k = 0; $k < $seed; ++$k) {
                $out[$k] = $out[$k] ^ ord($key_[$k_k]);
                ++$k_k;
                if ($k_k == $k_ln) {
                    $k_k = 0;
                }
            }
        }

        for ($k = 0; $k < $seed; ++$k) {
            $out[$k] = sprintf("%02X", $out[$k]);
        }
        return implode("", $out);
    }

    public static function recoDecode($in, $key_)
    {
        $out = array();
        $in = pack("H*", $in);
        $seed = strlen($in);
        if ($seed == 0) {
            return "";
        }

        for ($k = 0; $k < $seed; ++$k) {
            $out[$k] = ord($in[$k]);
        }

        $k_ln = strlen($key_);
        if ($k_ln > 0) {
            $k_k = 0;
            for ($k = 0; $k < $seed; ++$k) {
                $out[$k] = $out[$k] ^ ord($key_[$k_k]);
                ++$k_k;
                if ($k_k == $k_ln) {
                    $k_k = 0;
                }
            }
        }

        $in = $out;

        $out[0] = chr($in[0]);
        for ($k = 1; $k < $seed; ++$k) {
            $out[$k] = chr(($in[$k] ^ $in[$k - 1] ^ $k) & 0xFF);
        }

        return implode("", $out);
    }

    public static function generateUUID()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($data), 4));
    }

    public static function generateHash512()
    {
        return hash("sha512", bin2hex(random_bytes(64)) . hash("sha512", self::generateUUID()), false);
    }

    // Change the password of a user that knows the current one (the profile page).
    // The current session stays open.
    public static function changePassword($web, $userId, $currentPassword, $newPassword, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = intval($userId);
        $table->enabled = 1;
        if (($table->id <= 0) || !$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        if (!self::checkPasswordHash($web, $table->email, $table->password, $currentPassword, "hash")) {
            $reason = "invalid-current-password";
            return false;
        }

        if (!self::checkPasswordPolicy($newPassword)) {
            $reason = "invalid-password";
            return false;
        }

        if (self::checkPasswordHash($web, $table->email, $table->password, $newPassword, "hash")) {
            $reason = "same-password";
            return false;
        }

        $table->password = self::setPasswordHash($web, $table->email, $newPassword, "hash");
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        // The password is changed, the notification e-mail is informative only
        if (strlen("" . $table->uuid) > 0) {
            $reasonMail = "";
            self::sendPasswordResetEmail($web, $table->uuid, $reasonMail);
        }
        return true;
    }

    public static function setDescription($web, $userId, $description, &$reason)
    {
        $description = trim("" . $description);
        $length = function_exists("mb_strlen") ? mb_strlen($description, "UTF-8") : strlen($description);
        if ($length > self::DESCRIPTION_MAX_LENGTH) {
            $reason = "description-too-long";
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = intval($userId);
        if (($table->id <= 0) || !$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->description = $description;
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }
        return true;
    }

    public static function getAvatarPath()
    {
        return ANDORA_PATH . "_repository/avatar/";
    }

    // <user id>-<32 hex digits>.png, anything else is never read or deleted
    public static function isAvatarFileName($fileName)
    {
        return is_string($fileName) && (preg_match("/^[0-9]+-[0-9a-f]{32}\\.png$/", $fileName) === 1);
    }

    // URL of the avatar of a user, null when the user has none.
    // v= changes with every new image, so the browser can keep it in its cache.
    public static function getAvatarURL($web, $userId, $avatar)
    {
        $userId = intval($userId);
        if (($userId <= 0) || !self::isAvatarFileName($avatar)) {
            return null;
        }
        $info = $web->get(\XYO\Web\Info::class);
        $version = substr($avatar, strpos($avatar, "-") + 1, 32);
        return $info->site . "user/avatar?id=" . $userId . "&v=" . $version;
    }

    public static function getCurrentUserAvatarURL($web)
    {
        return self::getAvatarURL($web, self::$user__id, self::$user__avatar);
    }

    // Path of the avatar file of a user, null when the user has none
    public static function getAvatarFile($web, $userId)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = intval($userId);
        if (($table->id <= 0) || !$table->load(0, 1)) {
            return null;
        }
        if (!self::isAvatarFileName($table->avatar)) {
            return null;
        }
        if (strncmp($table->avatar, $table->id . "-", strlen($table->id . "-")) != 0) {
            return null;
        }
        $file = self::getAvatarPath() . $table->avatar;
        if (!is_file($file)) {
            return null;
        }
        return $file;
    }

    // An uploaded image (bytes or a data:image/...;base64, URL) to a square PNG of
    // AVATAR_SIZE pixels. With GD the image is decoded, cropped to the center square,
    // resized and encoded again, nothing of the uploaded file is kept. Without GD
    // only a PNG of exactly AVATAR_SIZE x AVATAR_SIZE is accepted, as it is.
    public static function avatarImageFromData($data, &$reason)
    {
        if (!is_string($data) || (strlen($data) == 0)) {
            $reason = "invalid-image";
            return null;
        }
        if (strncmp($data, "data:", 5) == 0) {
            $match = array();
            if (!preg_match("#^data:image/(png|jpeg|gif|webp);base64,#", $data, $match)) {
                $reason = "invalid-image";
                return null;
            }
            if (strlen($data) - strlen($match[0]) > intdiv(self::AVATAR_MAX_BYTES * 4, 3) + 4) {
                $reason = "image-too-large";
                return null;
            }
            $data = base64_decode(substr($data, strlen($match[0])), true);
            if ($data === false) {
                $reason = "invalid-image";
                return null;
            }
        }
        if (strlen($data) > self::AVATAR_MAX_BYTES) {
            $reason = "image-too-large";
            return null;
        }

        $size = @getimagesizefromstring($data);
        if (($size === false) || ($size[0] < 1) || ($size[1] < 1)) {
            $reason = "invalid-image";
            return null;
        }
        if (($size[0] > self::AVATAR_MAX_SIDE) || ($size[1] > self::AVATAR_MAX_SIDE)) {
            $reason = "image-too-large";
            return null;
        }
        if (!in_array($size[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP), true)) {
            $reason = "invalid-image";
            return null;
        }

        if (!function_exists("imagecreatefromstring")) {
            if (($size[2] == IMAGETYPE_PNG) && ($size[0] == self::AVATAR_SIZE) && ($size[1] == self::AVATAR_SIZE)) {
                return $data;
            }
            $reason = "image-not-supported";
            return null;
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            $reason = "invalid-image";
            return null;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $avatar = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127));
        imagecopyresampled($avatar, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::AVATAR_SIZE, self::AVATAR_SIZE, $side, $side);

        ob_start();
        $isOk = imagepng($avatar, null, 9);
        $png = ob_get_clean();
        if (!$isOk || (strlen($png) == 0)) {
            $reason = "invalid-image";
            return null;
        }
        return $png;
    }

    // Set the avatar of a user, the previous image is deleted
    public static function saveAvatar($web, $userId, $data, &$reason)
    {
        $userId = intval($userId);
        $png = self::avatarImageFromData($data, $reason);
        if (is_null($png)) {
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = $userId;
        if (($userId <= 0) || !$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $path = self::getAvatarPath();
        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                $reason = "file-error";
                return false;
            }
        }

        $fileName = $userId . "-" . bin2hex(random_bytes(16)) . ".png";
        if (@file_put_contents($path . $fileName, $png) !== strlen($png)) {
            @unlink($path . $fileName);
            $reason = "file-error";
            return false;
        }

        $table->avatar = $fileName;
        if (!$table->save()) {
            @unlink($path . $fileName);
            $reason = "database-error";
            return false;
        }

        self::deleteAvatarFiles($userId, $fileName);
        if ($userId == self::$user__id) {
            self::$user__avatar = $fileName;
        }
        return true;
    }

    public static function deleteAvatar($web, $userId, &$reason)
    {
        $userId = intval($userId);
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = $userId;
        if (($userId <= 0) || !$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->avatar = "";
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        self::deleteAvatarFiles($userId);
        if ($userId == self::$user__id) {
            self::$user__avatar = "";
        }
        return true;
    }

    // Delete the avatar files of a user, except $keep (a file name)
    public static function deleteAvatarFiles($userId, $keep = null)
    {
        $userId = intval($userId);
        if ($userId <= 0) {
            return;
        }
        $list = glob(self::getAvatarPath() . $userId . "-*.png");
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $file) {
            $name = basename($file);
            if (!self::isAvatarFileName($name) || ($name === $keep)) {
                continue;
            }
            @unlink($file);
        }
    }

    public static function getSidebarMenu($web)
    {
        $menu = array(
            array("link", "user-pen", "Profile", "admin/profile"),
            array("separator"),
            array("link", "log-out", "Log out", "user/logout"),
        );

        $info = $web->get(\XYO\Web\Info::class);
        foreach ($menu as &$row) {
            if (count($row) > 3) {
                if (strncmp($row[3], "#", 1) != 0) {
                    $row[3] = $info->site . $row[3];
                }
            }
        }

        return $menu;
    }

}
