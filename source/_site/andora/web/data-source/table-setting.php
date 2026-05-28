<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableSetting extends \XYO\Web\DataSource\Table
{
    public $id;
    public $component__id;
    public $name;
    public $value;

    public static function descriptor($info)
    {
        $info->name = "setting";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "component__id" => array("bigint", 0, "unsigned"),
            "name" => array("varchar", null, 128),
            "value" => array("varchar", null, 255)
        );
        $info->indexes = array(
            "component__id",
            "name"
        );
    }

}

