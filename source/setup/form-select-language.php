<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Setup;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\InputSelect;
class FormSelectLanguage extends \Andora\Web\Component\Form
{
    public $onSelect_ = null;

    public function formInit($options = null)
    {
        $this->value->language = "en-US";
    }

    public function formInitComponents($options = null)
    {
        InputSelect::register($this, "language", array(
            "name" => "language",
            "required" => true,
            "list" => array(
                "en-US" => "English (United States)",
                "ro-RO" => "Romana"
            ),
            "initialValue" => $this->value->language
        ));
    }

    public function setOnSelect($fn)
    {
        $this->onSelect_ = $fn;
    }

    public function onSelect()
    {
        if (!is_null($this->onSelect_)) {
            ($this->onSelect_)($this);
        }
    }

    public function formProcess($options = null)
    {
        $action = $this->getElementValueString("action");
        if ($action == "select") {
            $this->onSelect();
        }
        if ($action == "submit") {
            $this->setIsDone($this->getElementValueString("action") == "submit");
        }
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold">Setup</h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("this-will-perform-the-initial-setup"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>
            <input type="hidden" name="action" value="select" id="<?php echo $this->getElementId("action"); ?>" />

            <?php $this->renderComponent("language"); ?>

            <button type="submit" class="btn btn-neutral" id="<?php echo $this->getElementId("submit"); ?>">
                <?php $this->language->render("begin"); ?>
            </button>

            <?php

            $this->view->renderJS(function () {
                echo "document.getElementById(\"" . $this->getComponentId("language") . "\").addEventListener(\"change\",function(e){";
                echo "e.preventDefault();";
                $this->formRenderJSRequest();
                echo "});";
                echo "document.getElementById(\"" . $this->getElementId("submit") . "\").addEventListener(\"click\",function(e){";
                echo "e.preventDefault();";
                echo "document.getElementById(\"" . $this->getElementId("action") . "\").value=\"submit\";";
                $this->formRenderJSRequest();
                echo "});";
            });

        }, "flex flex-col gap-2");

    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
