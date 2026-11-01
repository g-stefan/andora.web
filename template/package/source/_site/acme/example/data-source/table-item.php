<?php
// Example package
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\DataSource;

defined("ANDORA") or die("Forbidden");

// The items of the example; a table name of a package starts with its
// vendor and name, so it never meets the tables of Andora or of another package
class TableItem extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $name;
    public $description;
    public $enabled;
    public $created_at;

    public static function descriptor($info)
    {
        $info->name = "acme-example-item";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"), // the user who made it
            "name" => array("varchar", null, 128),
            "description" => array("varchar", null, 255),
            "enabled" => array("int", 0, "unsigned"),
            "created_at" => array("datetime", null)
        );
        $info->indexes = array(
            "user__id"
        );
    }
}
