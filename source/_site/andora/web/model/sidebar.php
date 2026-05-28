<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

class Sidebar
{
    public static function getMenu($web)
    {
        $info = $web->get(\XYO\Web\Info::class);
        $menu = array(
            "sidebar-content" =>
                array(
                    "Home" => array(
                        array(false, "circle-check-big", "Dashboard", "admin/dashboard"),
                        array(false, "list-tree", "Lifecycle", "#"),
                        array(false, "chart-no-axes-column", "Analytics", "#"),
                        array(false, "folder", "Projects", "#"),
                        array(false, "user", "User", "admin/user"),
                        array(false, "credit-card", "Billing", "admin/billing"),
                    ),
                    "Documents" => array(
                        array(false, "database", "Data Library", "#"),
                        array(false, "file-text", "Reports", "#"),
                        array(false, "file-code-2", "Word Assistant", "#"),
                        array(false, "ellipsis", "More", "#"),
                    )
                ),
            "sidebar-bottom" => array(
                array(false, "settings", "Settings", "admin/settings"),
                array(false, "circle-question-mark", "Get Help", "#"),
                array(false, "search", "Search", "#")
            )
        );
    
        foreach ($menu["sidebar-content"] as &$submenu) {
            foreach ($submenu as &$row) {
                $row[0] = false;
                if ($row[3] == $info->path) {
                    $row[0] = true;
                }
                if (!(strncmp($row[3], "#", 1) == 0)) {
                    $row[3] = $info->site . $row[3];
                }
            }
        }
        foreach ($menu["sidebar-bottom"] as &$row) {
            $row[0] = false;
            if ($row[3] == $info->path) {
                $row[0] = true;
            }
            if (!(strncmp($row[3], "#", 1) == 0)) {
                $row[3] = $info->site . $row[3];
            }
        }
        
        return $menu;
    }

}
