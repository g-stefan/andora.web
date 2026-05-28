<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUserGroupSetting extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user_group__id;
    public $component__id;
    public $name;
    public $value;

    public static function descriptor($info)
    {
        $info->name = "user-group-setting";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user_group__id" => array("bigint", 0, "unsigned"),
            "component__id" => array("bigint", 0, "unsigned"),
            "name" => array("varchar", null, 128),
            "value" => array("varchar", null, 255)
        );
        $info->indexes = array(
            "user_group__id",
            "component__id",
            "name"
        );
    }

}

