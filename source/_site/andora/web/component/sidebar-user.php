<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class SidebarUser extends \XYO\Web\Component
{

    public $options = null;

    public function init($options = null)
    {
        $this->options = $options;
        if (is_null($this->options)) {
            $this->options = \Andora\Web\Model\User::getSidebarMenu($this->web);
        }

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

        ?>

        <!-- Dropdown Component -->
        <div class="dropdown dropdown-right dropdown-end w-full group">

            <!-- Trigger Button -->
            <div tabindex="0" role="button"
                class="flex items-center gap-3 p-2 rounded-sm group-hover:bg-base-300/50 transition-colors w-full cursor-pointer peer">
                <div class="avatar">
                    <div class="w-9 h-9 rounded-lg overflow-hidden border border-base-200/50">
                        <!-- img src="https://i.pravatar.cc/150?u=shadcn" alt="User Avatar" class="object-cover" / -->
                    </div>
                </div>
                <div class="flex-1 text-left overflow-hidden">
                    <div class="text-sm font-semibold text-base-content truncate">Administrator</div>
                    <div class="text-xs text-base-content/60 truncate">admin@example.com</div>
                </div>
                <div class="text-base-content/40 group-hover:text-base-content/70 transition-colors">
                    <?php $this->renderComponent("icons", array("icon" => "ellipsis-vertical", "class" => "text-lg text-base-content/60 group-hover:text-base-content transition-colors")); ?>
                </div>
            </div>

            <!-- Dropdown Menu Popup -->
            <ul tabindex="0"
                class="dropdown-content bg-base-100 menu rounded-md min-w-48 p-1.5 shadow-xl border border-base-300 ml-1 peer-focus:animate-fade-in-from-left peer-focus-within:animate-fade-in-from-left hover:animate-fade-in-from-left origin-bottom-left">

                <?php

                foreach ($this->options as $link) {
                    if ($link[0] == "separator") { ?>
                        <hr class="border-base-300 my-1" />
                        <?php
                        continue;
                    }
                    if ($link[0] == "link") { ?>
                        <li>
                            <a href="<?php echo $link[3]; ?>"
                                class="flex items-center gap-2.5 py-2 px-2.5 text-sm font-medium text-base-content/80 hover:text-base-content hover:bg-base-300/50 rounded-lg transition-colors group/link">
                                <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base text-base-content/60 group-hover/link:text-base-content transition-colors")); ?>
                                <?php echo $link[2]; ?>
                            </a>
                        </li>
                        <?php
                        continue;
                    }
                }
                ?>
            </ul>
        </div>

        <?php
    }

}

