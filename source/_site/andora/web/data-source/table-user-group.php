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
    public $level;
    public $is_system;

    public static function descriptor($info)
    {
        $info->name = "andora-web-user-group";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "name" => array("varchar", null, 128),
            "description" => array("varchar", null, 255),
            "enabled" => array("int", 0, "unsigned"),
            // \Andora\Web\Model\Acl::LEVEL_* - 0 public, 1 authenticated, 2 administrator, 3 root
            "level" => array("int", 1, "unsigned"),
            // the system groups (\Andora\Web\Model\Acl::GROUP_*): cannot be deleted, renamed or disabled
            "is_system" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "name",
            "level"
        );
    }

}
