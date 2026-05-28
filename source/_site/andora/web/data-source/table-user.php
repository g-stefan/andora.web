<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUser extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $name;
    public $email;
    public $password;
    // ---
    public $session;
    public $enabled;
    public $created_at;
    public $logged_at;
    public $logged_in;
    public $action;
    public $action_at;
    public $language;
    public $picture;
    public $invisible;
    public $uuid;
    public $description;
    public $is_confirmed;
    public $confirmation_sent_at;
    public $confirmed_at;
    public $is_forgot_password;
    public $forgot_password_sent_at;
    public $forgot_password_confirmed_at;

    public static function descriptor($info)
    {
        $info->name = "user";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"), // user creator - 0 - creator is one of the system user
            "name" => array("varchar", null, 255),
            "email" => array("varchar", null, 128),
            "password" => array("varchar", null, 255),
            "session" => array("varchar", null, 255),
            "enabled" => array("int", 0, "unsigned"),
            "created_at" => array("datetime", null),
            "logged_at" => array("datetime", null),
            "logged_in" => array("int", 0, "unsigned"),
            "action" => array("int", 0, "unsigned"), // authorization checks
            "action_at" => array("datetime", null), // last time when an authorization was requested
            "language" => array("varchar", null, 8),
            "avatar" => array("varchar", null, 255),
            "invisible" => array("int", 0, "unsigned"),
            "uuid" => array("varchar", null, 40),
            "description" => array("varchar", null, 255),
            "is_confirmed" => array("int", 0, "unsigned"),
            "confirmation_sent_at" => array("datetime", null),
            "confirmed_at" => array("datetime", null),
            "is_forgot_password" => array("int", 0, "unsigned"),
            "forgot_password_sent_at" => array("datetime", null),
            "forgot_password_confirmed_at" => array("datetime", null)
        );
        $info->indexes = array(
            "user__id",
            "email"
        );
    }

}
