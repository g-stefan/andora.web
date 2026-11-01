<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Dashboard;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Model\Acl;
use \Andora\Web\Package\Manager;
use \Andora\Web\Component\DashboardPanel;

// The dashboard: a notice for the users who manage the packages
// (package.manage) when a package needs attention (Manager::needAttention),
// then the panels of the user (Dashboard::layout, the default panels of
// the active packages until the user changes them in admin/dashboard/edit).
class Page extends \XYO\Web\Page
{
    protected $attention = array();
    protected $packageLanguage = null;
    protected $panelIdList = array();
    protected $hasPanels = false;

    public function init($options = null)
    {
        $this->setTitle("Admin - Dashboard");
        Dashboard::setApplicationTitle("Dashboard");
        LucideIcons::register($this, "icons");

        // the folders are scanned only for the users who can act on them
        if (!$this->isAJAX() && Acl::can($this->web, Acl::PERMISSION_PACKAGE_MANAGE)) {
            $this->attention = Manager::needAttention($this->web);
            $this->packageLanguage = new \XYO\Web\Language();
            $language = strtolower("" . $this->view->language);
            if (preg_match("/^[a-z]{2}(-[a-z]{2})?$/", $language)) {
                $this->packageLanguage->includeFile(ANDORA_PATH . "_site/andora/web/route/admin/package/language/" . $language . ".php");
            }
        }

        $panels = Dashboard::panels($this->web);
        $this->hasPanels = count($panels) > 0;
        $this->panelIdList = array();
        foreach (Dashboard::layout($this->web, Acl::currentUserId(), $panels) as $item) {
            $id = DashboardPanel::componentId($item["panel"]);
            $this->panelIdList[] = $id;
            DashboardPanel::register($this, $id, array("panel" => $panels[$item["panel"]], "size" => $item["size"]));
        }
    }

    protected function renderPackageNotice()
    {
        if (count($this->attention) == 0) {
            return;
        }
        ?>
        <div role="alert" class="alert alert-warning items-start mb-6" id="package-notice">
            <?php $this->renderComponent("icons", array("icon" => "triangle-alert", "class" => "text-xl")); ?>
            <div class="flex flex-col gap-2">
                <h2 class="font-semibold"><?php $this->language->render("package-notice.title"); ?></h2>
                <ul class="list-disc ml-5 text-sm">
                    <?php foreach ($this->attention as $info) {
                        $line = $this->packageLanguage->get("state." . $info->state);
                        if ((strlen($info->installedVersion) > 0) && (strlen($info->version) > 0) && ($info->installedVersion !== $info->version)) {
                            $line .= " (" . $info->installedVersion . " -> " . $info->version . ")";
                        }
                        if (strlen($info->error) > 0) {
                            $line .= "; " . ($info->isInstalled ? $this->language->get("package-notice.last-upgrade-failed") . ": " : "") . $this->packageLanguage->get($info->error);
                        }
                        ?>
                        <li>
                            <span class="font-mono"><?php echo htmlspecialchars($info->code, ENT_QUOTES); ?></span>
                            <?php if ($info->title !== $info->code) { ?>
                                <span><?php echo htmlspecialchars("" . $info->title, ENT_QUOTES); ?></span>
                            <?php } ?>
                            - <span><?php echo htmlspecialchars($line, ENT_QUOTES); ?></span>
                        </li>
                    <?php } ?>
                </ul>
                <div>
                    <a class="btn btn-sm" href="<?php echo $this->site; ?>admin/package"><?php $this->language->render("package-notice.open"); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    public function render($options = null)
    {
        $this->renderPackageNotice();
        if (count($this->panelIdList) > 0) {
            ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="dashboard-panels">
                <?php foreach ($this->panelIdList as $id) {
                    $this->renderComponent($id);
                } ?>
            </div>
            <?php
        } else if ($this->hasPanels) {
            ?>
            <p class="text-base-content/70 text-center py-8" id="dashboard-empty"><?php $this->language->render("empty"); ?></p>
            <?php
        }
    }

}

return Page::class;
