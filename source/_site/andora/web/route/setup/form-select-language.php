<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Setup;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\InputSelect;
use \Andora\Web\Component\SystemCheck as ComponentSystemCheck;
use \Andora\Web\Model\SystemCheck;

// The first step: the language and the system check, "Begin" only when the
// server meets the requirements (checked again by the page on submit)
class FormSelectLanguage extends \Andora\Web\Component\Form
{
    public $onSelect_ = null;
    public $isReady = false;

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
        ComponentSystemCheck::register($this, "system-check", array("mode" => "setup"));
        $this->isReady = SystemCheck::isReady();
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

            <button type="submit" class="btn btn-neutral" id="<?php echo $this->getElementId("submit"); ?>"<?php echo $this->isReady ? "" : " disabled"; ?>>
                <?php $this->language->render("begin"); ?>
            </button>

            <?php if (!$this->isReady) { ?>
                <button type="button" class="btn btn-outline" id="<?php echo $this->getElementId("check"); ?>">
                    <?php $this->language->render("check-again"); ?>
                </button>
            <?php } ?>

            <?php

            $this->view->renderJS(function () {
                echo "document.getElementById(\"" . $this->getComponentId("language") . "\").addEventListener(\"change\",function(e){";
                echo "e.preventDefault();";
                $this->formRenderJSRequest();
                echo "});";
                if (!$this->isReady) {
                    echo "document.getElementById(\"" . $this->getElementId("check") . "\").addEventListener(\"click\",function(e){";
                    echo "e.preventDefault();";
                    $this->formRenderJSRequest();
                    echo "});";
                }
                echo "document.getElementById(\"" . $this->getElementId("submit") . "\").addEventListener(\"click\",function(e){";
                echo "e.preventDefault();";
                echo "document.getElementById(\"" . $this->getElementId("action") . "\").value=\"submit\";";
                $this->formRenderJSRequest();
                echo "});";
            });

        }, "flex flex-col gap-2");

        echo "<div class=\"mt-6\">";
        $this->renderComponent("system-check");
        echo "</div>";

    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[480px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
