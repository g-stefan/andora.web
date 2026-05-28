<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableUserGroup extends \XYO\Web\DataSource\Table
{
    public $id;
    public $name;
    public $description;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "user-group";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "name" => array("varchar", null, 128),
            "description" => array("varchar", null, 255),
            "enabled" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "name"
        );
    }

}
