<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableComponent extends \XYO\Web\DataSource\Table
{
    public $id;
    public $name;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "component";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "uuid" => array("varchar", null, 40),
            "description" => array("varchar", null, 255),
        );
        $info->indexes = array(
            "uuid"
        );
    }

}

