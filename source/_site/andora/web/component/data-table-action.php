<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class DataTableAction extends \XYO\Web\Component
{
    public $options = null;

    public function init($options = null)
    {

        $this->options = $options;
        if (is_null($this->options)) {
            $this->options = array();
        }

        LucideIcons::register($this, "icons");
    }

    public function render($options = null)
    {
        if (is_null($options)) {
            return;
        }
        if (!array_key_exists("primaryKey", $options)) {
            return;
        }

        ?>

        <!-- Dropdown Component -->
        <div class="dropdown dropdown-end group/menu">

            <!-- Trigger Button -->
            <div tabindex="0" role="button"
                class="flex items-center gap-3 p-2 rounded-sm group-hover/menu:bg-base-300 w-full cursor-pointer peer">
                <div class="text-base-content/40 group-hover/menu:text-base-content/70 transition-colors">
                    <?php $this->renderComponent("icons", array("icon" => "ellipsis-vertical", "class" => "text-lg text-base-content/60 group-hover/menu:text-base-content transition-colors")); ?>
                </div>
            </div>

            <!-- Dropdown Menu Popup -->
            <ul tabindex="0"
                class="dropdown-content bg-base-100 menu rounded-md min-w-32 p-1.5 shadow-xl border border-base-300 mt-1 peer-focus:animate-fade-in-from-right peer-focus-within:animate-fade-in-from-right hover:animate-fade-in-from-right origin-top-right">

                <?php

                $index = 0;
                foreach ($this->options as $link) {
                    $index = $index + 1;
                    if ($link[0] == "separator") { ?>
                        <hr class="border-base-300 my-1" />
                        <?php
                        continue;
                    }
                    if ($link[0] == "link") {
                        $class = "text-base-content/80 hover:text-base-content hover:bg-base-300/50";
                        if (count($link) > 4) {
                            $class = $link[4];
                        }
                        $classIcon = "text-base-content/60 group-hover/link:text-base-content";
                        if (count($link) > 5) {
                            $classIcon = $link[5];
                        }

                        ?>
                        <li>
                            <a href="<?php echo $link[3] . "?" . $options["primaryKey"][0] . "=" . $options["primaryKey"][1]; ?>"
                                class="flex items-center gap-2.5 py-2 px-2.5 text-sm font-medium rounded-md transition-colors group/link <?php echo $class; ?>">
                                <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base transition-colors " . $classIcon)); ?>
                                <?php echo $link[2]; ?>
                            </a>
                        </li>
                        <?php
                        continue;
                    }
                    if ($link[0] == "js") {
                        $class = "text-base-content/80 hover:text-base-content hover:bg-base-300/50";
                        if (count($link) > 4) {
                            $class = $link[4];
                        }
                        $classIcon = "text-base-content/60 group-hover/link:text-base-content";
                        if (count($link) > 5) {
                            $classIcon = $link[5];
                        }

                        ?>
                        <li>
                            <a href="#" id="<?php echo $this->id . "_" . $index; ?>"
                                class="flex items-center gap-2.5 py-2 px-2.5 text-sm font-medium rounded-md transition-colors group/link <?php echo $class; ?>">
                                <?php $this->renderComponent("icons", array("icon" => $link[1], "class" => "text-base transition-colors " . $classIcon)); ?>
                                <?php echo $link[2]; ?>
                            </a>
                        </li>
                        <?php

                        $this->view->renderJS(function () use ($index, $link, $options) {

                            echo "document.getElementById(\"" . $this->id . "_" . $index . "\").addEventListener(\"click\",function(){";
                            echo $link[3] . "({\"" . $options["primaryKey"][0] . "\":\"" . $options["primaryKey"][1] . "\"});";
                            echo "});";

                        });

                        continue;
                    }
                }
                ?>
            </ul>
        </div>

        <?php
    }
}

