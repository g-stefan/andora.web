<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// The dashboard of a user (\Andora\Web\Model\Dashboard): a row for every
// panel the user had when the dashboard was changed, the shown ones
// (enabled = 1) in the order of position, the removed ones with enabled = 0.
// No rows - the default panels.
class TableUserDashboardPanel extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $panel;
    public $size;
    public $position;
    public $enabled;

    public static function descriptor($info)
    {
        $info->name = "andora-web-user-dashboard-panel";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"),
            "panel" => array("varchar", null, 255), // <vendor>/<name>/<panel name>
            "size" => array("int", 1, "unsigned"), // 1 - one column, 2 - two columns, 3 - the full row
            "position" => array("int", 0, "unsigned"),
            "enabled" => array("int", 0, "unsigned")
        );
        $info->indexes = array(
            "user__id"
        );
    }

}
