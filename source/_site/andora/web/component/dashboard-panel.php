<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manager;
use \XYO\LucideIcons\LucideIcons;

// A panel of a package on /admin/dashboard (Manager::dashboardPanels):
// a card with its icon, title and the output of its render callback or of
// its component (registered as "content", so its AJAX requests work), as
// wide as its size (1 - one column, 2 - two columns, 3 - the full row of the
// dashboard grid). An exception of the package code is logged
// (_log/<date>.package.log) and the card shows an error, the rest of the
// dashboard is not affected.
class DashboardPanel extends \XYO\Web\Component
{
    // The classes of the sizes in the grid of the dashboard
    // (grid-cols-1 md:grid-cols-2 xl:grid-cols-3)
    public const SIZE_CLASS = array(
        1 => "",
        2 => "md:col-span-2",
        3 => "md:col-span-2 xl:col-span-3"
    );

    // The id of the component of a panel on the dashboard: panel-<vendor>--<name>--<panel name>
    // (the names have no "--", so it is unique; AJAX requests: <id>/content)
    public static function componentId($panelId)
    {
        return "panel-" . str_replace("/", "--", $panelId);
    }

    public $panel = null;
    public $size = 1;
    protected $failed = false;

    public function init($options = null)
    {
        $this->panel = $options["panel"];
        $this->size = Manager::panelSize($options["size"] ?? $this->panel->size);
        $this->failed = false;
        LucideIcons::register($this, "icons");
    }

    protected function fail($e)
    {
        $this->failed = true;
        Manager::log($this->panel->code, $this->panel->version, "dashboardPanel", "error", $e->getMessage());
    }

    public function initComponents()
    {
        try {
            if (!is_null($this->panel->component)) {
                $this->registerComponent($this->panel->component, "content");
            }
            parent::initComponents();
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    public function initComponentFromRequest($idList)
    {
        if (is_null($this->panel->component)) {
            return;
        }
        try {
            $this->registerComponent($this->panel->component, "content");
            parent::initComponentFromRequest($idList);
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    // The component of the package (also its AJAX answers)
    public function renderComponent($id, $options = null)
    {
        if ($this->failed) {
            return;
        }
        ob_start();
        try {
            parent::renderComponent($id, $options);
            ob_end_flush();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail($e);
        }
    }

    protected function renderContent()
    {
        if (!$this->failed) {
            if (!is_null($this->panel->render)) {
                ob_start();
                try {
                    call_user_func($this->panel->render, $this->web);
                    ob_end_flush();
                } catch (\Throwable $e) {
                    ob_end_clean();
                    $this->fail($e);
                }
            } else {
                $this->renderComponent("content");
            }
        }
        if ($this->failed) {
            $this->loadLanguageFromPath("./_site/andora/web/component", "dashboard-panel");
            ?>
            <p class="text-error text-sm"><?php $this->language->render("panel-error"); ?></p>
            <?php
        }
    }

    public function renderAJAX($options = null)
    {
        $this->renderContent();
    }

    public function renderContainer($options = null)
    {
        ?>
        <div class="card bg-base-100 border border-base-300 <?php echo self::SIZE_CLASS[$this->size]; ?>" id="<?php echo $this->id; ?>" data-panel="<?php echo htmlspecialchars($this->panel->id, ENT_QUOTES); ?>">
            <div class="card-body p-4 gap-2">
                <h2 class="card-title text-base">
                    <?php if (strlen($this->panel->icon) > 0) {
                        parent::renderComponent("icons", array("icon" => $this->panel->icon, "class" => "text-lg text-base-content/70"));
                    } ?>
                    <span><?php echo htmlspecialchars("" . $this->panel->title, ENT_QUOTES); ?></span>
                </h2>
                <div class="text-sm">
                    <?php $this->renderContent(); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
