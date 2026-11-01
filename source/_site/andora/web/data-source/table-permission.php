<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TablePermission extends \XYO\Web\DataSource\Table
{
    public $id;
    public $name;
    public $description;
    public $enabled;
    public $is_system;
    public $package;

    public static function descriptor($info)
    {
        $info->name = "andora-web-permission";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "name" => array("varchar", null, 128),
            "description" => array("varchar", null, 255),
            "enabled" => array("int", 0, "unsigned"),
            // used by the code (system.admin, user.manage, ...): cannot be deleted, renamed or disabled
            "is_system" => array("int", 0, "unsigned"),
            // <vendor>/<name> of the package that registered it, empty - Andora
            "package" => array("varchar", null, 128)
        );
        $info->indexes = array(
            "name"
        );
    }

}
