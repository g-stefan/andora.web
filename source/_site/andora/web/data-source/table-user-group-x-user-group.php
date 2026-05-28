<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUserGroupXUserGroup extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user_group__id_super;
    public $user_group__id;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "user-group-x-user-group";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user_group__id_super" => array("bigint", 0, "unsigned"),
            "user_group__id" => array("bigint", 0, "unsigned"),
            "enabled" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "user_group__id_super",
            "user_group__id"
        );
    }

}

