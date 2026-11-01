<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0


namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \XYO\Web\DataSource\Order;
use \Andora\Web\DataSource\TableUserDashboardPanel;
use \Andora\Web\Package\Manager;

// The title of the admin pages and the dashboard of the users.
// The panels come from the packages (Base::dashboardPanels, Manager::dashboardPanels).
// A user that never changed the dashboard sees the default panels; once
// changed (admin/dashboard/edit), the dashboard is the user's own list
// (table user-dashboard-panel): the panels added later by the packages are
// not shown, they can be added. A layout is a list of arrays
// array("panel" => id, "size" => 1-3).
class Dashboard
{
    public static $applicationTitle;

    public static function init()
    {
        self::$applicationTitle = "";
    }

    public static function setApplicationTitle($title)
    {
        self::$applicationTitle = $title;
    }

    // The strings of the panels of Andora and of the dashboard component
    public static function language($web)
    {
        $language = new \XYO\Web\Language();
        $code = strtolower("" . $web->get(\XYO\Web\View::class)->language);
        if (preg_match("/^[a-z]{2}(-[a-z]{2})?$/", $code) != 1) {
            $code = "en-us";
        }
        if (!$language->includeFile(ANDORA_PATH . "_site/andora/web/component/language/" . $code . "/dashboard-panel.php")) {
            $language->includeFile(ANDORA_PATH . "_site/andora/web/component/language/en-us/dashboard-panel.php");
        }
        return $language;
    }

    // The panels the current user may see (Manager::dashboardPanels)
    public static function panels($web)
    {
        return Manager::dashboardPanels($web);
    }

    // The user changed the dashboard
    public static function isCustom($web, $userId)
    {
        $dsPanel = $web->get(\XYO\Web\DataSource\Connection::class)->getDataSource(TableUserDashboardPanel::class);
        $dsPanel->clear();
        $dsPanel->user__id = intval($userId);
        return $dsPanel->load(0, 1) ? true : false;
    }

    // The panels shown to the user, $panels from panels(): the user's own
    // list, without the panels that are gone or the user may not see
    // anymore, or the default panels
    public static function layout($web, $userId, $panels)
    {
        $layout = array();
        $dsPanel = $web->get(\XYO\Web\DataSource\Connection::class)->getDataSource(TableUserDashboardPanel::class);
        $dsPanel->clear();
        $dsPanel->user__id = intval($userId);
        $dsPanel->setOrder("position", Order::ASCENDENT);
        $dsPanel->setOrder("id", Order::ASCENDENT);
        $isCustom = false;
        for ($dsPanel->load(); $dsPanel->loadIsValid(); $dsPanel->loadNext()) {
            $isCustom = true;
            if (intval($dsPanel->enabled) != 1) {
                continue;
            }
            $id = "" . $dsPanel->panel;
            if (!array_key_exists($id, $panels) || in_array($id, array_column($layout, "panel"), true)) {
                continue;
            }
            $layout[] = array("panel" => $id, "size" => Manager::panelSize($dsPanel->size));
        }
        if ($isCustom) {
            return $layout;
        }
        foreach ($panels as $id => $panel) {
            if ($panel->isDefault) {
                $layout[] = array("panel" => $id, "size" => $panel->size);
            }
        }
        return $layout;
    }

    // Save the dashboard of the user: the panels of $layout (shown, in this
    // order), every other panel of $panels as removed. The rows of the panels
    // the user may not see now are kept as they are.
    public static function saveLayout($web, $userId, $layout, $panels)
    {
        $userId = intval($userId);
        if ($userId <= 0) {
            return false;
        }
        $dsPanel = $web->get(\XYO\Web\DataSource\Connection::class)->getDataSource(TableUserDashboardPanel::class);
        // panel id => row id, the rows of a panel that is there twice are deleted
        $rows = array();
        $deleteList = array();
        $dsPanel->clear();
        $dsPanel->user__id = $userId;
        for ($dsPanel->load(); $dsPanel->loadIsValid(); $dsPanel->loadNext()) {
            $id = "" . $dsPanel->panel;
            if (array_key_exists($id, $rows)) {
                $deleteList[] = intval($dsPanel->id);
            } else if (array_key_exists($id, $panels)) {
                $rows[$id] = intval($dsPanel->id);
            }
        }

        $values = array();
        $position = 0;
        foreach ($layout as $item) {
            if (!array_key_exists($item["panel"], $panels) || array_key_exists($item["panel"], $values)) {
                continue;
            }
            $position += 10;
            $values[$item["panel"]] = array("size" => Manager::panelSize($item["size"]), "position" => $position, "enabled" => 1);
        }
        foreach ($panels as $id => $panel) {
            if (!array_key_exists($id, $values)) {
                $values[$id] = array("size" => $panel->size, "position" => 0, "enabled" => 0);
            }
        }

        foreach ($deleteList as $rowId) {
            $dsPanel->clear();
            $dsPanel->id = $rowId;
            $dsPanel->delete();
        }
        foreach ($values as $id => $value) {
            $dsPanel->clear();
            if (array_key_exists($id, $rows)) {
                $dsPanel->id = $rows[$id];
                $dsPanel->user__id = $userId;
                if (!$dsPanel->load(0, 1)) {
                    return false;
                }
            }
            $dsPanel->user__id = $userId;
            $dsPanel->panel = $id;
            $dsPanel->size = $value["size"];
            $dsPanel->position = $value["position"];
            $dsPanel->enabled = $value["enabled"];
            if (!$dsPanel->save()) {
                return false;
            }
        }
        return true;
    }

    // Back to the default panels
    public static function resetLayout($web, $userId)
    {
        $userId = intval($userId);
        if ($userId <= 0) {
            return false;
        }
        $dsPanel = $web->get(\XYO\Web\DataSource\Connection::class)->getDataSource(TableUserDashboardPanel::class);
        $dsPanel->clear();
        $dsPanel->user__id = $userId;
        return $dsPanel->delete() ? true : false;
    }

}
