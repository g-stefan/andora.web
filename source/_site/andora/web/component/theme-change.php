<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class ThemeChange extends \XYO\Web\Component
{

    protected static $name = "andora.theme-change";
    public function init($options = null)
    {
        $this->view->jsLinks->set(self::$name, $this->site . "_site/andora/web/client/theme-change.js", "defer");
        LucideIcons::register($this, "icons");
    }

    public function render($options = null)
    {
        $strIconCheck = $this->strRenderComponent("icons", array("icon" => "check", "class" => "opacity-0 peer-checked:opacity-100 text-base-content transition-opacity duration-200 text-base"));
        ?>

        <div class="dropdown dropdown-end">
            <button tabindex="0" class="btn btn-square">
                <?php $this->renderComponent("icons", array("icon" => "sun", "class" => "text-xl")); ?>
            </button>
            <ul tabindex="0"
                class="dropdown-content z-[1] menu p-2 shadow-xl bg-base-100 rounded-md w-52 border border-base-300 mt-1">
                <li>
                    <label class="flex items-center gap-3 p-3 cursor-pointer">
                        <input type="radio" name="theme-dropdown" class="theme-controller hidden peer" value="light" checked
                            aria-label="Light" />

                        <?php echo $strIconCheck; ?>

                        <span class="text-base font-normal">Light</span>
                    </label>
                </li>

                <li>
                    <label class="flex items-center gap-3 p-3 cursor-pointer">
                        <input type="radio" name="theme-dropdown" class="theme-controller hidden peer" value="dark"
                            aria-label="Dark" />

                        <?php echo $strIconCheck; ?>

                        <span class="text-base font-normal">Dark</span>
                    </label>
                </li>

                <li>
                    <label class="flex items-center gap-3 p-3 cursor-pointer">
                        <input type="radio" name="theme-dropdown" class="theme-controller hidden peer" value="default"
                            aria-label="System" />

                        <?php echo $strIconCheck; ?>

                        <span class="text-base font-normal">System</span>
                    </label>
                </li>

            </ul>
        </div>
    <?php }

}

