<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

// The administration popup of the sidebar: the admin.panel entries of the
// admin menu (\Andora\Web\Model\Sidebar::getMenu, sidebar-panel).
// An entry: array(active, icon, title, url). Not shown without entries.
class SidebarPanel extends \XYO\Web\Component
{

    public $options = null;

    public function init($options = null)
    {
        $this->options = $options;

        LucideIcons::register($this, "icons");
    }

    public function render($options = null)
    {
        if (!is_null($options)) {
            $this->options = $options;
        }

        if (is_null($this->options)) {
            $this->options = array();
        }

        if (count($this->options) == 0) {
            return;
        }

        $isActive = false;
        foreach ($this->options as $link) {
            if ($link[0]) {
                $isActive = true;
            }
        }

        ?>

        <!-- Dropdown Component -->
        <div class="dropdown dropdown-right dropdown-end w-full group mb-2" id="sidebar-panel-menu">

            <!-- Trigger Button -->
            <div tabindex="0" role="button"
                class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors w-full cursor-pointer peer <?php echo $isActive ? "bg-base-300/50 text-base-content" : "text-base-content/80 group-hover:bg-base-300/50 group-hover:text-base-content"; ?>">
                <?php $this->renderComponent("icons", array("icon" => "settings", "class" => "text-base " . ($isActive ? "" : "text-base-content/60 group-hover:text-base-content transition-colors"))); ?>
                <span class="flex-1 text-left">Administration</span>
                <?php $this->renderComponent("icons", array("icon" => "chevron-right", "class" => "text-base text-base-content/40 group-hover:text-base-content/70 transition-colors")); ?>
            </div>

            <!-- Dropdown Menu Popup -->
            <ul tabindex="0"
                class="dropdown-content bg-base-100 menu rounded-md min-w-48 p-1.5 shadow-xl border border-base-300 ml-1 peer-focus:animate-fade-in-from-left peer-focus-within:animate-fade-in-from-left hover:animate-fade-in-from-left origin-bottom-left">

                <?php foreach ($this->options as $link) { ?>
                    <li>
                        <a href="<?php echo htmlspecialchars("" . $link[3], ENT_QUOTES); ?>"
                            class="flex items-center gap-2.5 py-2 px-2.5 text-sm font-medium rounded-md transition-colors group/link <?php echo $link[0] ? "bg-base-300/50 text-base-content" : "text-base-content/80 hover:text-base-content hover:bg-base-300/50"; ?>">
                            <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base " . ($link[0] ? "" : "text-base-content/60 group-hover/link:text-base-content transition-colors"))); ?>
                            <?php echo htmlspecialchars("" . $link[2], ENT_QUOTES); ?>
                        </a>
                    </li>
                <?php } ?>
            </ul>
        </div>

        <?php
    }

}
