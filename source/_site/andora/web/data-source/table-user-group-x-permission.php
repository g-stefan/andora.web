<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUserGroupXPermission extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user_group__id;
    public $permission__id;
    public $is_granted;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "andora-web-user-group-x-permission";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user_group__id" => array("bigint", 0, "unsigned"),
            "permission__id" => array("bigint", 0, "unsigned"),
            "is_granted" => array("int", 0, "unsigned"),
            "enabled" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "user_group__id",
            "permission__id"
        );
    }

}

