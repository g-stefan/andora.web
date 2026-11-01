<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableUserGroup;
use \Andora\Web\DataSource\TableUser;
use \Andora\Web\DataSource\TableUserXUserGroup;
use \Andora\Web\DataSource\TableUserDashboardPanel;
use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\DataSource\TableUserGroupXPermission;
use \Andora\Web\DataSource\TableUserXPermission;
use \Andora\Web\DataSource\TableAdminMenu;
use \Andora\Web\DataSource\TablePackage;
use \Andora\Web\Model\User;
use \Andora\Web\Model\Acl;

use \PHPMailer\PHPMailer\PHPMailer;

class Setup
{
    // Version of the database content, saved in config.06.schema.php.
    // 1 - the first version
    public const SCHEMA_VERSION = 1;

    // The tables of andora/web
    protected static $tableList = array(
        TableUserGroup::class,
        TableUser::class,
        TableUserXUserGroup::class,
        TablePermission::class,
        TableUserGroupXPermission::class,
        TableUserXPermission::class,
        TableAdminMenu::class,
        TablePackage::class,
        TableUserDashboardPanel::class
    );

    public static function dsCreateStorage($web, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        foreach (self::$tableList as $dsTable) {

            $table = $dsConnection->getDataSource($dsTable);
            if (!$table->createStorage()) {
                $reason = "database-error";
                return false;
            }

        }

        return self::dsSeed($web, $reason);
    }

    // The data the code needs: the system groups (levels and user), the system
    // permissions (given to the administrator group) and the admin menu.
    // Safe to run again, existing rows are kept.
    // The entries of the administration popup: code, title, icon, path, permission, position
    protected static $adminPanelMenu = array(
        array("admin.dashboard-edit", "Dashboard", "layout-dashboard", "admin/dashboard/edit", Acl::PERMISSION_SYSTEM_ADMIN, 50),
        array("admin.user", "User", "user", "admin/user", Acl::PERMISSION_USER_MANAGE, 100),
        array("admin.user-group", "User group", "users", "admin/user-group", Acl::PERMISSION_USER_GROUP_MANAGE, 200),
        array("admin.permission", "Permission", "shield-check", "admin/permission", Acl::PERMISSION_PERMISSION_MANAGE, 300),
        array("admin.package", "Package", "package", "admin/package", Acl::PERMISSION_PACKAGE_MANAGE, 400),
        array("admin.database", "Database", "database", "admin/database", Acl::PERMISSION_DATABASE_MANAGE, 500),
        array("admin.settings", "Settings", "settings", "admin/settings", Acl::PERMISSION_SYSTEM_SETTINGS, 900),
        array("admin.system", "System", "monitor-cog", "admin/system", Acl::PERMISSION_SYSTEM_SETTINGS, 950),
        array("admin.about", "About", "info", "admin/about", Acl::PERMISSION_SYSTEM_ADMIN, 1000)
    );

    public static function dsSeed($web, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        // The system groups, found by is_system + name. A group with the same
        // name made before is renamed (<name>-<id>), not taken over: its
        // members must not get the level of the system group.
        $groupId = array();
        $dsUserGroup = $dsConnection->getDataSource(TableUserGroup::class);
        foreach (Acl::systemGroupList() as $name => $level) {
            $dsUserGroup->clear();
            $dsUserGroup->is_system = 1;
            $dsUserGroup->name = $name;
            if (!$dsUserGroup->load(0, 1)) {
                if (!self::freeGroupName($web, $name)) {
                    $reason = "database-error";
                    return false;
                }
                $dsUserGroup->clear();
                $dsUserGroup->name = $name;
                $dsUserGroup->description = "Level: " . $name;
                $dsUserGroup->level = $level;
                $dsUserGroup->is_system = 1;
                $dsUserGroup->enabled = 1;
                if (!$dsUserGroup->save()) {
                    $reason = "database-error";
                    return false;
                }
            }
            $groupId[$name] = intval($dsUserGroup->id);
        }

        $permissionId = array();
        $dsPermission = $dsConnection->getDataSource(TablePermission::class);
        foreach (Acl::systemPermissionList() as $name => $description) {
            $dsPermission->clear();
            $dsPermission->name = $name;
            if (!$dsPermission->load(0, 1)) {
                $dsPermission->clear();
                $dsPermission->name = $name;
                $dsPermission->description = $description;
                $dsPermission->is_system = 0;
            }
            if (intval($dsPermission->is_system) != 1) {
                $dsPermission->is_system = 1;
                $dsPermission->enabled = 1;
                if (!$dsPermission->save()) {
                    $reason = "database-error";
                    return false;
                }
            }
            $permissionId[$name] = intval($dsPermission->id);
        }

        // The administrator level, permission.manage, package.manage and
        // database.manage are left to root
        $administratorPermission = array(
            Acl::PERMISSION_SYSTEM_ADMIN,
            Acl::PERMISSION_SYSTEM_SETTINGS,
            Acl::PERMISSION_USER_MANAGE,
            Acl::PERMISSION_USER_DELETE,
            Acl::PERMISSION_USER_GROUP_MANAGE
        );
        $dsLink = $dsConnection->getDataSource(TableUserGroupXPermission::class);
        foreach ($administratorPermission as $name) {
            $dsLink->clear();
            $dsLink->user_group__id = $groupId[Acl::GROUP_ADMINISTRATOR];
            $dsLink->permission__id = $permissionId[$name];
            if ($dsLink->load(0, 1)) {
                continue;
            }
            $dsLink->clear();
            $dsLink->user_group__id = $groupId[Acl::GROUP_ADMINISTRATOR];
            $dsLink->permission__id = $permissionId[$name];
            $dsLink->is_granted = 1;
            $dsLink->enabled = 1;
            if (!$dsLink->save()) {
                $reason = "database-error";
                return false;
            }
        }

        $isOk = self::registerAdminMenu($web, "admin.home", "admin.sidebar", "", "Home", "", "", "", Acl::LEVEL_PUBLIC, 100);
        $isOk = $isOk && self::registerAdminMenu($web, "admin.dashboard", "admin.sidebar", "admin.home", "Dashboard", "circle-check-big", "admin/dashboard", Acl::PERMISSION_SYSTEM_ADMIN, Acl::LEVEL_PUBLIC, 100);
        // The administration popup (admin.panel)
        foreach (self::$adminPanelMenu as $entry) {
            $isOk = $isOk && self::registerAdminMenu($web, $entry[0], "admin.panel", "", $entry[1], $entry[2], $entry[3], $entry[4], Acl::LEVEL_PUBLIC, $entry[5]);
        }
        if (!$isOk) {
            $reason = "database-error";
            return false;
        }

        Acl::reset();
        return true;
    }

    // Rename the groups (not system) named $name to <name>-<id>
    protected static function freeGroupName($web, $name)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsUserGroup = $dsConnection->getDataSource(TableUserGroup::class);
        $groupIds = array();
        $dsUserGroup->clear();
        $dsUserGroup->name = $name;
        for ($dsUserGroup->load(); $dsUserGroup->loadIsValid(); $dsUserGroup->loadNext()) {
            if (intval($dsUserGroup->is_system) != 1) {
                $groupIds[] = intval($dsUserGroup->id);
            }
        }
        foreach ($groupIds as $id) {
            $dsUserGroup->clear();
            $dsUserGroup->id = $id;
            if (!$dsUserGroup->load(0, 1)) {
                return false;
            }
            $dsUserGroup->name = $name . "-" . $id;
            if (!$dsUserGroup->save()) {
                return false;
            }
        }
        return true;
    }

    // Add an admin menu entry once (found by code, an existing entry is not changed).
    // $parentCode - code of the section, "" for a top level entry.
    // $package - <vendor>/<name> of the package that adds it, "" for Andora.
    public static function registerAdminMenu($web, $code, $location, $parentCode, $title, $icon, $path, $permission, $level, $position, $package = "")
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsMenu = $dsConnection->getDataSource(TableAdminMenu::class);
        $dsMenu->clear();
        $dsMenu->code = $code;
        if ($dsMenu->load(0, 1)) {
            return true;
        }

        $parentId = 0;
        if (strlen($parentCode) > 0) {
            $dsMenu->clear();
            $dsMenu->code = $parentCode;
            if (!$dsMenu->load(0, 1)) {
                return false;
            }
            $parentId = intval($dsMenu->id);
        }

        $dsMenu->clear();
        $dsMenu->admin_menu__id = $parentId;
        $dsMenu->location = $location;
        $dsMenu->code = $code;
        $dsMenu->title = $title;
        $dsMenu->icon = $icon;
        $dsMenu->path = $path;
        $dsMenu->permission = $permission;
        $dsMenu->level = intval($level);
        $dsMenu->position = intval($position);
        $dsMenu->enabled = 1;
        $dsMenu->package = "" . $package;
        return $dsMenu->save() ? true : false;
    }

    public static function writeSchemaConfigFile(&$reason)
    {
        return self::writeConfigFile("config.06.schema.php", array(
            "schemaVersion" => self::SCHEMA_VERSION
        ), $reason);
    }

    public static function isSchemaCurrent($web)
    {
        $config = $web->get(\XYO\Web\Config::class);
        return intval($config->get("schemaVersion", 0)) >= self::SCHEMA_VERSION;
    }

    // Bring the database up to SCHEMA_VERSION: the new tables and columns
    // (storageUpdateTable) and the data the code needs (dsSeed). A new version
    // that changes existing rows adds its steps here, for an older
    // schemaVersion of config.06.schema.php.
    public static function upgrade($web, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        foreach (self::$tableList as $dsTable) {
            $table = $dsConnection->getDataSource($dsTable);
            if (!$table->storageUpdateTable()) {
                $reason = "database-error";
                return false;
            }
        }

        if (!self::dsSeed($web, $reason)) {
            return false;
        }

        Acl::reset();
        return self::writeSchemaConfigFile($reason);
    }

    // The groups of a root account: root, administrator and user
    // (authenticated is implicit)
    public static function addRootGroups($web, $userId)
    {
        foreach ([Acl::GROUP_ROOT, Acl::GROUP_ADMINISTRATOR, Acl::GROUP_USER] as $name) {
            $groupId = Acl::getSystemGroupId($web, $name);
            if (($groupId == 0) || !Acl::addUserToGroup($web, $userId, $groupId)) {
                return false;
            }
        }
        return true;
    }

    // Write a configuration file, the values are exported with var_export (no code injection)
    public static function writeConfigFile($fileName, $value, &$reason)
    {
        $content = "<" . "?php
";
        $content .= "// Andora
";
        $content .= "// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
";
        $content .= "// SPDX-License-Identifier: Apache-2.0
";
        $content .= "
";
        $content .= "defined(\"XYO_WEB\") or die(\"Forbidden\");
";
        $content .= "
";
        $content .= "return " . str_replace("
", "
", var_export($value, true)) . ";
";
        $content .= "
";

        if (!file_put_contents($fileName, $content, LOCK_EX)) {
            $reason = "unable-to-write-configuration";
            return false;
        }

        if (function_exists("opcache_invalidate")) {
            opcache_invalidate($fileName, true);
        }

        return true;
    }

    public static function writeLanguageConfigFile($language, &$reason)
    {
        if (!in_array($language, array("en-US", "ro-RO"), true)) {
            $reason = "invalid-language";
            return false;
        }

        return self::writeConfigFile("config.01.language.php", array("language" => $language), $reason);
    }

    public static function writeDatabaseConfigFile(
        $databaseType,
        $username,
        $password,
        $databaseServer,
        $databasePort,
        $databaseName,
        $tablePrefix,
        &$reason
    ) {
        if (!in_array($databaseType, array("sqlite", "mysql", "postgresql"), true)) {
            $reason = "invalid-database-type";
            return false;
        }

        $db = array("type" => $databaseType);
        if ($databaseType == "sqlite") {
            $db["database"] = "./_repository/andora.sqlite";
        }
        if ($databaseType == "mysql" || $databaseType == "postgresql") {
            $db["user"] = "" . $username;
            $db["password"] = "" . $password;
            $db["server"] = "" . $databaseServer;
            $db["port"] = "" . $databasePort;
            $db["database"] = "" . $databaseName;
        }
        $db["prefix"] = "" . $tablePrefix;

        return self::writeConfigFile("config.02.datasource.php", array(
            "dataSource" => array(
                "connection" => array(
                    "db" => $db
                )
            )
        ), $reason);
    }

    public static function checkDatabaseConnection($web, &$reason)
    {
        $config = $web->get(\XYO\Web\Config::class);
        $config->includeFile("config.02.datasource.php");
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsConnection->init($config);
        $db = $dsConnection->get();
        if (!$db->isOpen()) {
            $reason = "database-connection-error";
            return false;
        }
        return true;
    }

    public static function writeUserConfigFile(&$reason)
    {
        return self::writeConfigFile("config.03.user.php", array(
            "user" => array(
                "passwordRecoSalt" => User::generateHash512(),
                "loginSalt" => User::generateHash512()
            )
        ), $reason);
    }

    // Mark the setup as done, siteURL is the base URL used in the links sent by e-mail
    public static function writeConfiguredConfigFile($siteURL, &$reason)
    {
        return self::writeConfigFile("config.04.configured.php", array(
            "configured" => true,
            "siteURL" => "" . $siteURL
        ), $reason);
    }

    public static function writeSMTPConfigFile(
        $name,
        $username,
        $password,
        $server,
        $port,
        $isTested,
        &$reason
    ) {
        return self::writeConfigFile("config.05.smtp.php", array(
            "smtp" => array(
                "name" => "" . $name,
                "username" => "" . $username,
                "password" => "" . $password,
                "server" => "" . $server,
                "port" => "" . $port,
                "isTested" => $isTested ? true : false
            )
        ), $reason);
    }

    // Create the first user (the owner), member of root, administrator and user
    public static function createAdministrator($web, $name, $email, $password, &$reason)
    {
        if (!User::addUser($web, $name, $email, $password, $reason)) {
            return false;
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $table = $dsConnection->getDataSource(TableUser::class);
        $table->clear();
        $table->email = trim("" . $email);
        if (!$table->load(0, 1)) {
            $reason = "database-error";
            return false;
        }

        // Created by the installer, the e-mail address is trusted
        $table->is_confirmed = 1;
        $table->confirmed_at = date("Y-m-d H:i:s");
        if (!$table->save()) {
            $reason = "database-error";
            return false;
        }

        if (!self::addRootGroups($web, $table->id)) {
            $reason = "database-error";
            return false;
        }

        return true;
    }

    public static function testSMTPConfiguration($web, &$reason)
    {
        $config = $web->get(\XYO\Web\Config::class);
        if ($config->has("smtp")) {
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

                //Recipients
                $mail->setFrom($configSMTP->get("username"), $configSMTP->get("name"));
                $mail->addAddress($configSMTP->get("username"), $configSMTP->get("name"));

                //Content
                $mail->isHTML(true);
                $mail->Subject = "Andora - SMTP configuration test";

                $mail->Body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head><body style=\"font-size: 10pt; font-family: Verdana,Geneva,sans-serif\">";
                $mail->Body .= "<p>Hi,</p><br />";
                $mail->Body .= "<p>This is a test e-mail!</p>";
                $mail->Body .= "<br /><p>Thank you!</p>";
                $mail->Body .= "</body></html>";

                $mail->AltBody = "Hi,\r\n\r\n";
                $mail->AltBody .= "This is a test e-mail!\r\n";
                $mail->AltBody .= "\r\nThank you!\r\n";

                $mail->send();

                return true;

            } catch (\Exception $e) {                                
                $reason = "smtp-mailer-error";
                \XYO\Web\Log::logMessage("smtp", ["datetime" => date("Y-m-d H:i:s"), "message" => $e->getMessage()]);
                return false;
            }

        }
        $reason = "configuration-error";
        return false;
    }

}
