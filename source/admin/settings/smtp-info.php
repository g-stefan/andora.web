<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Admin\Settings;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class SMTPInfo extends \XYO\Web\Component
{
    public $toggleId = null;

    public function init($options = null)
    {
        $this->toggleId = null;
        if (array_key_exists("toggleId", $options)) {
            $this->toggleId = $options["toggleId"];
        }

        LucideIcons::register($this, "icons");
    }


    public function renderAJAX($options = null)
    {
        $message = "smtp.info.not-configured";
        $icon = "circle-x";
        $iconClass = "text-error";


        if ($this->config->has("smtp")) {
            $message = "smtp.info.configured";
            $icon = "triangle-alert";
            $iconClass = "text-warning";
            if ($this->config->get("smtp")->get("isTested")) {
                $message = "smtp.info.configured-and-tested";
                $icon = "circle-check-big";
                $iconClass = "text-success";
            }
        }

        ?>

        <div class="flex flex-row w-full items-center p-4 cursor-pointer" id="<?php echo $this->id; ?>">
            <div class="text-base text-base-content/70"><?php $this->language->render($message); ?></div>
            <?php $this->renderComponent("icons", array("icon" => $icon, "class" => "text-2xl ml-auto mr-3 " . $iconClass)); ?>
        </div>

        <?php

        if (!is_null($this->toggleId)) {
            $this->view->renderJS(function () { ?>
                <script>
                    document.getElementById("<?php echo $this->id; ?>").addEventListener("click", function (e) {
                        e.preventDefault();
                        document.getElementById("<?php echo $this->toggleId; ?>").checked = true;
                    });
                </script>
            <?php });

        }

    }

    public function renderContainer($options = null)
    { ?>
        <div class="relative w-full flex flex-col" id="<?php echo $this->id; ?>">
            <?php $this->renderAJAX(); ?>
        </div>
        <?php
    }

}

return Page::class;
