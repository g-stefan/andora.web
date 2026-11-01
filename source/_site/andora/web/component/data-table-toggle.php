<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class DataTableToggle extends \XYO\Web\Component
{
    public $primaryKeyId = null;
    public $value = null;
    public $onToggle_ = null;

    public function init($options = null)
    {
        LucideIcons::register($this, "icons");

        $this->primaryKeyId = 0;
        $this->value = false;
        $this->onToggle_ = null;
        if (array_key_exists("onToggle", $options)) {
            $this->onToggle_ = $options["onToggle"];
        }
    }

    public function process($options = null)
    {
        if (!$this->isPOST()) {
            return;
        }
        if (!$this->isComponentAJAX()) {
            return;
        }

        $this->primaryKeyId = $this->request->get("id", 0);
        if (!is_null($this->onToggle_)) {
            $this->value = ($this->onToggle_)($this->primaryKeyId);
        }
    }

    public function renderAJAX($options = null)
    {
        if ($this->value) {
            $this->renderComponent("icons", array("icon" => "check", "class" => "text-base transition-colors"));
            return;
        }
        $this->renderComponent("icons", array("icon" => "x", "class" => "text-base transition-colors"));
    }

    public function renderContainer($options = null)
    {
        if (!is_null($options)) {
            if (array_key_exists("primaryKey", $options)) {
                $this->primaryKeyId = $options["primaryKey"][1];
            }
            if (array_key_exists("value", $options)) {
                $this->value = $options["value"][1];
            }
        }
        echo "<div class=\"w-full flex flex-col cursor-pointer\" id=\"" . $this->id . "\">";
        $this->renderAJAX($options);
        echo "</div>";
        $this->view->renderJS(function () use ($options) {
            ?>
            <script>
                document.getElementById("<?php echo $this->id; ?>").addEventListener("click", function (e) {
                    <?php

                    $this->renderJSRequestPost(array("id" => $this->primaryKeyId));

                    ?>
                    e.preventDefault();
                });
            </script>
            <?php
        });
    }
}

