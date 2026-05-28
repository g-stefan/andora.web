<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");
require_once(ANDORA_PATH . "_site/vendor/autoload.php");

use \Andora\Web\DataSource\TableUser;
use \PHPMailer\PHPMailer\PHPMailer;

class User
{
    public static $isAuthorized = null;
    public static $user__id = null;
    public static $user__name = null;
    public static $user__email = null;

    public static function addUser($web, $name, $email, $password, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $name = trim($name);
        $email = trim($email);

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

        if (strlen($password) < 12) {
            $reason = "invalid-password";
            return false;
        }

        $pattern = "/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-_]).{8,}$/";
        if (!preg_match($pattern, $password)) {
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

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

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
        $table->email = $email;
        if (!$table->load(0, 1)) {
            $reason = "email-not-found";
            return false;
        }

        return self::sendCheckEmail($table->uuid, $reason);
    }

    public static function sendCheckEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $config = $web->get(\XYO\Web\Config::class);
        if (property_exists($config, "smtp")) {
            $mail = new PHPMailer(true);
            try {
                $table = $dsConnection->getDataSource(TableUser::class);
                $table->clear();
                $table->uuid = $uuid;

                if (!$table->load(0, 1)) {
                    $reason = "user-not-found";
                    return false;
                }

                $name = $table->name;

                $configSMTP = $config->get("smtp");

                $mail->isSMTP();
                $mail->Host = $configSMTP->get("server");
                $mail->SMTPAuth = true;
                $mail->Username = $configSMTP->get("username");
                $mail->Password = $configSMTP->get("password");
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = $configSMTP->get("port");

                //Recipients
                $mail->setFrom($configSMTP->get("username"), $configSMTP->get("name"));
                $mail->addAddress($table->email, $name);

                //Content
                $mail->isHTML(true);
                $mail->Subject = "Andora - Confirm your email address";

                $mail->Body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head><body style=\"font-size: 10pt; font-family: Verdana,Geneva,sans-serif\">";
                $mail->Body .= "<p>Hi " . $name . ",</p>";
                $mail->Body .= "<p>Thanks for signing up! Your Andora account is almost complete. Click the link below to confirm your email address.</p>";
                $mail->Body .= "<p><a href=\"" . $config->get("siteURL") . "/email-confirm?uuid=" . $uuid . "\" target=\"_blank\" style=\"padding:6px;margin:3px;background-color:#ffffff;border:1px solid #000000;border-radius:6px;\" rel=\"noopener\">Confirm email address</a></p>";
                $mail->Body .= "<p>&nbsp;</p><p>Thank you!</p>";
                $mail->Body .= "</body></html>";

                $mail->AltBody = "Hi " . $name . ",\r\n\r\n";
                $mail->AltBody .= "Thanks for signing up! Your Andora account is almost complete. Click the link below to confirm your email address.\r\n";
                $mail->AltBody .= $config->get("siteURL") . "/email-confirm?uuid=" . $uuid . "\r\n";
                $mail->AltBody .= "\r\nThank you!\r\n";

                $mail->send();

                $table->confirmation_sent_at = "NOW";

                if (!$table->save()) {
                    $reason = "database-error";
                    return false;
                }
                return true;

            } catch (Exception $e) {
                $reason = "smtp-mailer-error";
                return false;
            }

        }
        $reason = "configuration-error";
        return false;
    }

    public static function emailConfirm($web, $uuid, &$reason)
    {
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
        $table->confirmed_at = "NOW";
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        return true;
    }

    public static function forgotPassword($web, $email, &$reason)
    {
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
        $table->forgot_password_sent_at = "NOW";
        $table->save();

        return self::sendPasswordChangeEmail($web, $table->uuid, $reason);
    }

    public static function sendPasswordChangeEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $config = $web->get(\XYO\Web\Config::class);
        if (property_exists($config, "smtp")) {
            $mail = new PHPMailer(true);
            try {
                $table = $dsConnection->getDataSource(TableUser::class);
                $table->clear();
                $table->uuid = $uuid;

                if (!$table->load(0, 1)) {
                    $reason = "user-not-found";
                    return false;
                }

                $name = $table->name;

                $configSMTP = $config->get("smtp");

                $mail->isSMTP();
                $mail->Host = $configSMTP->get("server");
                $mail->SMTPAuth = true;
                $mail->Username = $configSMTP->get("username");
                $mail->Password = $configSMTP->get("password");
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = $configSMTP->get("port");

                //Recipients
                $mail->setFrom($configSMTP->get("username"), $configSMTP->get("name"));
                $mail->addAddress($table->email, $name);

                //Content
                $mail->isHTML(true);
                $mail->Subject = "Andora - Forgot Password";

                $mail->Body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head><body style=\"font-size: 10pt; font-family: Verdana,Geneva,sans-serif\">";
                $mail->Body .= "<p>Hi " . $name . ",</p>";
                $mail->Body .= "<p>Forgot Password request recorded. Click the link below to set a new password.</p>";
                $mail->Body .= "<p><a href=\"" . $config->get("siteURL") . "/password-reset?uuid=" . $uuid . "\" target=\"_blank\" style=\"padding:6px;margin:3px;background-color:#ffffff;border:1px solid #000000;border-radius:6px;\" rel=\"noopener\">Change password</a></p>";
                $mail->Body .= "<p>&nbsp;</p><p>Thank you!</p>";
                $mail->Body .= "</body></html>";

                $mail->AltBody = "Hi " . $name . ",\r\n\r\n";
                $mail->AltBody .= "Forgot Password request recorded. Click the link below to set a new password.\r\n";
                $mail->AltBody .= $config->get("siteURL") . "/password-reset?uuid=" . $uuid . "\r\n";
                $mail->AltBody .= "\r\nThank you!\r\n";

                $mail->send();

                $table->confirmation_sent_at = "NOW";

                if (!$table->save()) {
                    $reason = "database-error";
                    return false;
                }
                return true;

            } catch (Exception $e) {
                $reason = "smtp-mailer-error";
                return false;
            }

        }
        $reason = "configuration-error";
        return false;
    }

    public static function passwordReset($web, $uuid, $password, $reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->uuid = $uuid;
        $table->enabled = 1;

        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        $table->is_forgot_password = 0;
        $table->forgot_password_confirmed_at = "NOW";
        $table->password = self::setPasswordHash($web, $table->email, $password, "hash");

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        return self::sendPasswordResetEmail($web, $uuid, $reason);
    }

    public static function sendPasswordResetEmail($web, $uuid, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $config = $web->get(\XYO\Web\Config::class);
        if (property_exists($config, "smtp")) {
            $mail = new PHPMailer(true);
            try {
                $table = $dsConnection->getDataSource(TableUser::class);
                $table->clear();
                $table->uuid = $uuid;

                if (!$table->load(0, 1)) {
                    $reason = "user-not-found";
                    return false;
                }

                $name = $table->name;

                $configSMTP = $config->get("smtp");

                $mail->isSMTP();
                $mail->Host = $configSMTP->get("server");
                $mail->SMTPAuth = true;
                $mail->Username = $configSMTP->get("username");
                $mail->Password = $configSMTP->get("password");
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = $configSMTP->get("port");

                //Recipients
                $mail->setFrom($configSMTP->get("username"), $configSMTP->get("name"));
                $mail->addAddress($table->email, $name);

                //Content
                $mail->isHTML(true);
                $mail->Subject = "Andora - Password Changed";

                $mail->Body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head><body style=\"font-size: 10pt; font-family: Verdana,Geneva,sans-serif\">";
                $mail->Body .= "<p>Hi " . $name . ",</p>";
                $mail->Body .= "<p>This is a confirmation, that your password was changed. You can login now with your new password.</p>";
                $mail->Body .= "<p><a href=\"" . $config->get("siteURL") . "/login\" target=\"_blank\" style=\"padding:6px;margin:3px;background-color:#ffffff;border:1px solid #000000;border-radius:6px;\" rel=\"noopener\">Login</a></p>";
                $mail->Body .= "<p>&nbsp;</p><p>Thank you!</p>";
                $mail->Body .= "</body></html>";

                $mail->AltBody = "Hi " . $name . ",\r\n\r\n";
                $mail->AltBody .= "This is a confirmation, that your password was changed. You can login now with your new password.\r\n";
                $mail->AltBody .= $config->get("siteURL") . "/login\r\n";
                $mail->AltBody .= "\r\nThank you!\r\n";

                $mail->send();

                $table->confirmation_sent_at = "NOW";

                if (!$table->save()) {
                    $reason = "database-error";
                    return false;
                }
                return true;

            } catch (Exception $e) {
                $reason = "smtp-mailer-error";
                return false;
            }

        }
        $reason = "configuration-error";
        return false;
    }

    public static function login($web, $email, $password, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $info = $web->get(\XYO\Web\Info::class);
        $config = $web->get(\XYO\Web\Config::class);

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

        $user_rnd = hash("sha512", date("Y-m-d H:i:s") . " - " . rand(), false);
        $user_session = hash("sha512", $user_rnd . "." . $configUser->get("loginSalt") . "." . $table->password, false);
        $user__id = $table->id;

        $table->session = $user_session;
        $table->logged_at = "NOW";
        $table->logged_in = 1;
        $table->action = 1;
        $table->action_at = "NOW";

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        setcookie("_user__id", $user__id, [
            "path" => $info->site,
            "httponly" => true,
            "samesite" => "Strict"
        ]);
        setcookie("_user_session", $user_session, [
            "path" => $info->site,
            "httponly" => true,
            "samesite" => "Strict"
        ]);

        return true;
    }

    public static function checkSession($web, $id, $session, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        self::$isAuthorized = false;
        self::$user__id = null;
        self::$user__name = null;
        self::$user__email = null;

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = $id;
        $table->enabled = 1;
        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        if (strcmp($table->session, $session) != 0) {
            $reason = "invalid-session";
            return false;
        }

        $table->action = 1;
        $table->action_at = "NOW";

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        self::$isAuthorized = true;
        self::$user__id = $table->id;
        self::$user__name = $table->name;
        self::$user__email = $table->email;

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
        if (!array_key_exists("_user__id", $_COOKIE)) {
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
        $info = $web->get(\XYO\Web\Info::class);

        if (!array_key_exists("_user__id", $_COOKIE)) {
            $reason = "invalid-cookie";
            return 0;
        }
        if (!array_key_exists("_user_session", $_COOKIE)) {
            $reason = "invalid-cookie";
            return 0;
        }

        $id = trim($_COOKIE["_user__id"]);
        $session = trim($_COOKIE["_user_session"]);

        if (strlen($id) == 0) {
            $reason = "invalid-user";
            return;
        }

        $id = intval("" . $id);
        if ($id == 0) {
            $reason = "invalid-user";
            return;
        }

        if (strlen($session) == 0) {
            $reason = "invalid-session";
            return;
        }

        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->id = $id;
        $table->enabled = 1;
        if (!$table->load(0, 1)) {
            $reason = "user-not-found";
            return false;
        }

        if (strcmp($table->session, $session) != 0) {
            $reason = "invalid-session";
            return false;
        }

        $table->action = 1;
        $table->action_at = "NOW";
        $table->session = "";

        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        setcookie("_user__id", 0, [
            "path" => $info->site,
            "httponly" => true,
            "samesite" => "Strict"
        ]);
        setcookie("_user_session", "", [
            "path" => $info->site,
            "httponly" => true,
            "samesite" => "Strict"
        ]);

        return true;
    }

    public static function setPasswordHash($web, $username, $passwordPlain, $mode)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $configUser = $config->get("user");

        if ($mode === "hash") {
            $salt = hash("sha256", date("Y-m-d H:i:s") . " - " . rand(), false);
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
        if ($mode === "hash") {
            $list1 = explode(":", $passwordHash);
            $list2 = explode(".", $list1[1]);
            $salt = $list2[0];
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, $salt, $mode);
            return strcmp($passwordHash, $passwordCheck) == 0;
        }
        if ($mode === "reco") {
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, "", $mode);
            return strcmp($passwordHash, $passwordCheck) == 0;
        }
        if ($mode === "plain") {
            $passwordCheck = self::getPasswordHash($web, $username, $passwordPlain, "", $mode);
            return strcmp($passwordHash, $passwordCheck) == 0;
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
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($data), 4));
    }

    public static function generateHash512()
    {
        $salt = hash("sha256", date("Y-m-d H:i:s") . " - " . rand(), false);
        return hash("sha512", $salt . hash("sha512", self::generateUUID()), false);
    }

    public static function getSidebarMenu($web)
    {
        $menu = array(
            array("link", "circle-user", "Account", "#"),
            array("link", "credit-card", "Billing", "#"),
            array("link", "bell", "Notifications", "#"),
            array("separator"),
            array("link", "log-out", "Log out", "user/logout"),
        );

        $info = $web->get(\XYO\Web\Info::class);
        foreach ($menu as &$row) {
            if (count($row) > 2) {
                if (!strncmp($row[3], "#", 1) == 0) {
                    $row[3] = $info->site . $row[3];
                }
            }
        }

        return $menu;
    }

}

