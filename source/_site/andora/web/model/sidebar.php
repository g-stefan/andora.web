<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableAdminMenu;
use \XYO\Web\DataSource\Order;

class Sidebar
{
    // The admin menu from the admin-menu table, only the entries the current
    // user can open (permission and level, \Andora\Web\Model\Acl).
    // sidebar-content: section title => entries (admin.sidebar),
    // sidebar-panel: entries of the administration popup (admin.panel),
    // sidebar-bottom: entries (admin.bottom).
    // An entry: array(active, icon, title, url).
    public static function getMenu($web)
    {
        $info = $web->get(\XYO\Web\Info::class);
        $menu = array(
            "sidebar-content" => array(),
            "sidebar-panel" => array(),
            "sidebar-bottom" => array()
        );

        $sectionList = array();
        $itemList = array();

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsMenu = $dsConnection->getDataSource(TableAdminMenu::class);
        $dsMenu->clear();
        $dsMenu->location = array("admin.sidebar", "admin.panel", "admin.bottom");
        $dsMenu->enabled = 1;
        $dsMenu->setOrder("position", Order::ASCENDENT);
        for ($dsMenu->load(); $dsMenu->loadIsValid(); $dsMenu->loadNext()) {
            if (!self::isVisible($web, $dsMenu->permission, $dsMenu->level)) {
                continue;
            }
            $entry = array(
                "id" => intval($dsMenu->id),
                "parent" => intval($dsMenu->admin_menu__id),
                "location" => "" . $dsMenu->location,
                "title" => "" . $dsMenu->title,
                "icon" => "" . $dsMenu->icon,
                "path" => "" . $dsMenu->path
            );
            if (($entry["location"] == "admin.sidebar") && ($entry["parent"] == 0) && (strlen($entry["path"]) == 0)) {
                $sectionList[] = $entry;
                continue;
            }
            $itemList[] = $entry;
        }

        foreach ($sectionList as $section) {
            $list = array();
            foreach ($itemList as $item) {
                if ($item["parent"] == $section["id"]) {
                    $list[] = self::getLink($info, $item);
                }
            }
            // A section without visible entries is not shown
            if (count($list) > 0) {
                $menu["sidebar-content"][$section["title"]] = $list;
            }
        }

        foreach ($itemList as $item) {
            if ($item["location"] == "admin.panel") {
                $menu["sidebar-panel"][] = self::getLink($info, $item);
            }
            if ($item["location"] == "admin.bottom") {
                $menu["sidebar-bottom"][] = self::getLink($info, $item);
            }
        }

        return $menu;
    }

    public static function isVisible($web, $permission, $level)
    {
        if (Acl::level($web) < intval($level)) {
            return false;
        }
        $permission = "" . $permission;
        if (strlen($permission) == 0) {
            return true;
        }
        return Acl::can($web, $permission);
    }

    protected static function getLink($info, $item)
    {
        $url = $item["path"];
        if (strncmp($url, "#", 1) != 0) {
            $url = $info->site . $url;
        }
        return array($item["path"] == $info->path, $item["icon"], $item["title"], $url);
    }

}
