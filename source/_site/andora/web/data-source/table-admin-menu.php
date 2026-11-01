<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// Entries of the admin menus (\Andora\Web\Model\Sidebar::getMenu).
// An entry is shown when the user has its permission (empty = everybody that
// can see the menu) and at least its level. An entry without path and with
// children is a section (heading).
class TableAdminMenu extends \XYO\Web\DataSource\Table
{
    public $id;
    public $admin_menu__id;
    public $location;
    public $code;
    public $title;
    public $icon;
    public $path;
    public $permission;
    public $level;
    public $position;
    public $enabled;
    public $package;

    public static function descriptor($info)
    {
        $info->name = "andora-web-admin-menu";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "admin_menu__id" => array("bigint", 0, "unsigned"), // parent entry, 0 - top level
            "location" => array("varchar", null, 64), // admin.sidebar, admin.bottom
            "code" => array("varchar", null, 128), // unique key, used to register the entry only once
            "title" => array("varchar", null, 128),
            "icon" => array("varchar", null, 64), // lucide icon
            "path" => array("varchar", null, 255), // route, relative to the site
            "permission" => array("varchar", null, 128), // permission name, empty - no permission required
            "level" => array("int", 0, "unsigned"), // minimum level, \Andora\Web\Model\Acl::LEVEL_*
            "position" => array("int", 0, "unsigned"),
            "enabled" => array("int", 0, "unsigned"),
            "package" => array("varchar", null, 128) // <vendor>/<name> of the package that registered it, empty - Andora
        );
        $info->indexes = array(
            "location",
            "code"
        );
    }
}
