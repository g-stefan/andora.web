<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUserXUserGroup extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $user_group__id;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "andora-web-user-x-user-group";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"),
            "user_group__id" => array("bigint", 0, "unsigned"),
            "enabled" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "user__id",
            "user_group__id"
        );
    }

}
