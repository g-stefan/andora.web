<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class Sidebar extends \XYO\Web\Component
{
    protected static $name = "andora.sidebar";
    public $options = null;

    public function init($options = null)
    {
        $this->options = $options;
        if (is_null($this->options)) {
            $this->options = \Andora\Web\Model\Sidebar::getMenu($this->web);
        }

        $this->view->cssLinks->removeGroup(self::$name);
        $this->view->cssLinks->set(self::$name, $this->site . "_site/andora/web/client/sidebar.css");
        $this->view->jsLinks->set(self::$name, $this->site . "_site/andora/web/client/sidebar.js", "defer");

        LucideIcons::register($this, "icons");
        SidebarHeader::register($this, "sidebar-header");
        SidebarUser::register($this, "sidebar-user");
    }

    public function render($options = null)
    {
        if (!is_null($options)) {
            $this->options = $options;
        }

        if (is_null($this->options)) {
            $this->options = array("sidebar-content" => array(), "sidebar-bottom" => array());
        }

        ?>
        <!-- Sidebar Container -->
        <aside id="sidebar"
            class="bg-base-100 w-64 border-r border-base-300 flex flex-col h-screen shrink-0 z-50 transition-all duration-300 absolute md:relative -ml-64 md:ml-0">

            <!-- Sidebar Header -->
            <div class="h-[60px] flex items-center px-6 border-b border-base-300 shrink-0">
                <?php $this->renderComponent("sidebar-header"); ?>
            </div>

            <!-- Sidebar Scrollable Content -->
            <div class="flex-1 overflow-y-auto py-4 px-3 flex flex-col gap-6">
                <?php foreach ($this->options["sidebar-content"] as $key => $list) { ?>

                    <div>
                        <h4 class="px-3 text-xs font-medium text-base-content/60 mb-2"><?php echo $key; ?></h4>
                        <?php foreach ($list as $link) { ?>
                            <ul class="flex flex-col gap-0.5 text-sm">
                                <li>
                                    <?php if ($link[0]) { ?>
                                        <a href="<?php echo $link[3]; ?>"
                                            class="flex items-center gap-3 px-3 py-2 rounded-md bg-base-300/50 text-base-content font-medium transition-colors">
                                            <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base")); ?>
                                            <?php echo $link[2]; ?>
                                        </a>
                                    <?php } else { ?>
                                        <a href="<?php echo $link[3]; ?>"
                                            class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-base-300/50 text-base-content/80 hover:text-base-content transition-colors font-medium group">
                                            <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base text-base-content/60 group-hover:text-base-content transition-colors")); ?>
                                            <?php echo $link[2]; ?>
                                        </a>
                                    <?php } ?>
                                </li>
                            <?php } ?>
                        </ul>
                    </div>

                <?php } ?>

            </div>

            <!-- Sidebar Bottom Section -->
            <div class="px-3 pb-4 pt-2">
                <ul class="flex flex-col gap-0.5 text-sm mb-4">
                    <?php foreach ($this->options["sidebar-bottom"] as $link) { ?>
                        <li>
                            <?php if ($link[0]) { ?>
                                <a href="<?php echo $link[3]; ?>"
                                    class="flex items-center gap-3 px-3 py-2 rounded-md bg-base-300/50 text-base-content font-medium transition-colors">
                                    <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base")); ?>
                                    <?php echo $link[2]; ?>
                                </a>
                            <?php } else { ?>
                                <a href="<?php echo $link[3]; ?>"
                                    class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-base-300/50 text-base-content/80 hover:text-base-content transition-colors font-medium group">
                                    <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base text-base-content/60 group-hover:text-base-content transition-colors")); ?>
                                    <?php echo $link[2]; ?>
                                </a>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>

                <!-- User Profile Dropdown / Card -->
                <?php $this->renderComponent("sidebar-user"); ?>

            </div>
        </aside>

        <!-- Mobile Overlay Background -->
        <div id="mobile-overlay"
            class="fixed inset-0 z-40 hidden transition-opacity duration-300 md:hidden backdrop-blur-[1px]"></div>

        <?php
    }

}

