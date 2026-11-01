<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\DataSource\TableUser;

// The andora/web package (protected: no reset, disable or uninstall).
// install - the database of the setup wizard,
// upgrade - the rows a newer version needs; the schema upgrade itself
// (Setup::upgrade) runs before, in admin/authorization.php.
class Core extends Base
{
    public function install(&$reason)
    {
        return Setup::dsCreateStorage($this->web, $reason);
    }

    public function upgrade($fromVersion, &$reason)
    {
        return $this->seed($reason);
    }

    // The system groups, permissions and admin menu (also after a restore of
    // its tables from a database backup)
    public function seed(&$reason)
    {
        return Setup::dsSeed($this->web, $reason);
    }

    public function reset(&$reason)
    {
        $reason = "protected-package";
        return false;
    }

    public function uninstall($keepData, &$reason)
    {
        $reason = "protected-package";
        return false;
    }

    // The panels of Andora: the user accounts and the packages
    public function dashboardPanels()
    {
        $language = Dashboard::language($this->web);
        return array(
            "users" => array(
                "title" => $language->get("users.title"),
                "description" => $language->get("users.description"),
                "icon" => "users",
                "permission" => Acl::PERMISSION_USER_MANAGE,
                "default" => true,
                "render" => function ($web) use ($language) {
                    $dsUser = $this->getDataSource(TableUser::class);
                    $dsUser->clear();
                    $count = intval($dsUser->count());
                    $dsUser->clear();
                    $dsUser->enabled = 1;
                    $enabled = intval($dsUser->count());
                    $this->renderCount("panel-users", $count, array($enabled . " " . $language->get("users.enabled")), "admin/user", $language);
                }
            ),
            "packages" => array(
                "title" => $language->get("packages.title"),
                "description" => $language->get("packages.description"),
                "icon" => "package",
                "permission" => Acl::PERMISSION_PACKAGE_MANAGE,
                "default" => true,
                "render" => function ($web) use ($language) {
                    $count = 0;
                    $active = 0;
                    foreach (Manager::discover($this->web) as $info) {
                        if ($info->isInstalled) {
                            $count++;
                        }
                        if ($info->state === Manager::STATE_INSTALLED) {
                            $active++;
                        }
                    }
                    $attention = count(Manager::needAttention($this->web));
                    $details = array($active . " " . $language->get("packages.active"));
                    if ($attention > 0) {
                        $details[] = $attention . " " . $language->get("packages.attention");
                    }
                    $this->renderCount("panel-packages", $count, $details, "admin/package", $language);
                }
            )
        );
    }

    // A number, its details and a link to the page
    protected function renderCount($id, $count, $details, $path, $language)
    {
        $site = $this->web->get(\XYO\Web\Info::class)->site;
        ?>
        <div class="flex items-end gap-4" id="<?php echo $id; ?>">
            <div class="flex flex-col">
                <span class="text-4xl font-bold" data-count="<?php echo intval($count); ?>"><?php echo intval($count); ?></span>
                <span class="text-base-content/70"><?php echo htmlspecialchars(implode(", ", $details), ENT_QUOTES); ?></span>
            </div>
            <a class="btn btn-sm btn-ghost ml-auto" href="<?php echo $site . $path; ?>"><?php echo htmlspecialchars($language->get("open"), ENT_QUOTES); ?></a>
        </div>
        <?php
    }
}
