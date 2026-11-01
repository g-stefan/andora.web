<?php
// Example package
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\Package;

defined("ANDORA") or die("Forbidden");

use \Acme\Example\DataSource\TableItem;
use \Andora\Web\Model\Acl;

// Lifecycle of acme/example. The default hooks of Base create / update /
// empty / drop the tables of package.json "datasource" (TableItem); seed()
// adds what the code needs, after install, upgrade, reset and a restore of
// the tables from a database backup (safe to run again).
class Setup extends \Andora\Web\Package\Base
{
    public const PERMISSION_MANAGE = "acme-example.manage";

    public function seed(&$reason)
    {
        return $this->registerPermission(self::PERMISSION_MANAGE, "Manage the example items", $reason)
            && $this->grantPermissionToGroup(self::PERMISSION_MANAGE, Acl::GROUP_ADMINISTRATOR, $reason)
            && $this->registerAdminMenu("acme-example", "admin.sidebar", "admin.home", "Example", "box", "admin/example", self::PERMISSION_MANAGE, Acl::LEVEL_PUBLIC, 900, $reason);
    }

    public function upgrade($fromVersion, &$reason)
    {
        // new tables / columns, seed()
        if (!parent::upgrade($fromVersion, $reason)) {
            return false;
        }
        // data migrations, by the version installed before:
        // if (version_compare($fromVersion, "1.1.0") < 0) { ... }
        return true;
    }

    // The listeners of package.json "event.listen", an \Andora\Web\Package\Event each

    // user.delete: the items of the user go too
    public function onUserDelete($event)
    {
        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->user__id = intval($event->get("userId"));
        $dsItem->delete();
    }

    // user.create: counted in the activity of the example
    public function onUserCreate($event)
    {
        $this->updateActivity(function ($activity) use ($event) {
            $activity["usersCreated"] = intval($activity["usersCreated"] ?? 0) + 1;
            $activity["lastUserCreated"] = intval($event->get("userId"));
            return $activity;
        });
    }

    // user.login: the last login in the activity of the example
    public function onUserLogin($event)
    {
        $this->updateActivity(function ($activity) use ($event) {
            $activity["lastLogin"] = array("user" => intval($event->get("userId")), "at" => date("Y-m-d H:i:s"));
            return $activity;
        });
    }

    // acme-example.item-add, the event of this package (another package
    // listens to it the same way)
    public function onItemAdd($event)
    {
        $this->updateActivity(function ($activity) use ($event) {
            $activity["itemsAdded"] = intval($activity["itemsAdded"] ?? 0) + 1;
            $activity["lastItemAdded"] = intval($event->get("itemId"));
            return $activity;
        });
    }

    // A panel of /admin/dashboard for the users who manage the items
    // (id acme/example/items, on the default dashboard)
    public function dashboardPanels()
    {
        return array(
            "items" => array(
                "title" => "Example",
                "description" => "The items of the example and the events it got.",
                "icon" => "list",
                "permission" => self::PERMISSION_MANAGE,
                "default" => true,
                "render" => function ($web) {
                    $dsItem = $this->getDataSource(TableItem::class);
                    $dsItem->clear();
                    $count = $dsItem->count();
                    $activity = $this->activity();
                    $lastLogin = $activity["lastLogin"]["at"] ?? "-";
                    $site = $web->get(\XYO\Web\Info::class)->site;
                    ?>
                    <p id="acme-example-widget">Items: <?php echo intval($count); ?></p>
                    <p>Accounts created: <?php echo intval($activity["usersCreated"] ?? 0); ?></p>
                    <p>Items added: <?php echo intval($activity["itemsAdded"] ?? 0); ?></p>
                    <p>Last login: <?php echo htmlspecialchars("" . $lastLogin, ENT_QUOTES); ?></p>
                    <a class="link" href="<?php echo $site; ?>admin/example">Open</a>
                    <?php
                }
            )
        );
    }

    // _repository/acme/example/activity.json: kept when the folder is replaced
    public function activity()
    {
        $activity = json_decode("" . @file_get_contents($this->dataPath() . "activity.json"), true);
        return is_array($activity) ? $activity : array();
    }

    protected function updateActivity($update)
    {
        $handle = @fopen($this->dataPath() . "activity.json", "c+");
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $activity = json_decode("" . stream_get_contents($handle), true);
        $activity = $update(is_array($activity) ? $activity : array());
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($activity));
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
