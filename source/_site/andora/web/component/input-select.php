<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class InputSelect extends \XYO\Web\Component
{
    public $name = null;
    public $legend = null;
    public $required = null;
    public $autofocus = null;
    public $value = null;
    public $list = null;

    public function init($options = null)
    {
        $this->name = $options["name"];
        $this->legend = null;
        if (array_key_exists("legend", $options)) {
            $this->legend = $options["legend"];
        }
        $this->required = "";
        if (array_key_exists("required", $options)) {
            if ($options["required"]) {
                $this->required = "required";
            }
        }
        $this->autofocus = null;
        if (array_key_exists("autofocus", $options)) {
            if ($options["autofocus"]) {
                $this->autofocus = $options["autofocus"];
            }
        }
        $this->list = array();
        if (array_key_exists("list", $options)) {
            if ($options["list"]) {
                $this->list = $options["list"];
            }
        }

        // ---
        $this->value = "";
        if (array_key_exists("initialValue", $options)) {
            $this->value = $options["initialValue"];
            $this->parent->value->{$this->name} = $this->value;
        }

    }

    public function process($options = null)
    {
        if ($this->parent->isInit()) {
            return;
        }
        if ($this->isPOST()) {
            $this->getValue();
            if ($this->validateValue()) {
                $this->parent->value->{$this->name} = $this->value;
            }
        }
    }

    public function render($options = null)
    {
        $this->loadLanguageFromPath("./_site/andora/web/component", "input-select");

        if (is_null($this->legend)) {
            $this->legend = $this->language->get($this->name . ".legend");
        }

        $attribute = "";
        if ($this->parent->elementHasError($this->name)) {
            $attribute = "aria-invalid=\"true\"";
            $this->view->renderJS(function () {
                echo "document.getElementById(\"" . $this->id . "\").addEventListener(\"focus\", function() {";
                echo "this.removeAttribute(\"aria-invalid\");";
                echo "});";
            });
        }

        $autofocus = "";
        if ($this->autofocus) {
            $autofocus = "autofocus";
        }

        ?>

        <fieldset class="fieldset w-full">
            <legend class="fieldset-legend text-base"><?php echo $this->legend; ?></legend>
            <select name="<?php echo $this->name; ?>" class="select validator w-full" <?php echo $attribute; ?>
                id="<?php echo $this->id; ?>" <?php
                   echo $this->required;
                   echo " ";
                   echo $autofocus;
                   ?>>
                <?php
                foreach ($this->list as $key => $value) {
                    echo "<option value=\"" . htmlspecialchars("" . $key, ENT_QUOTES) . "\"";
                    if (strcmp("" . $this->value, "" . $key) == 0) {
                        echo " selected=\"selected\"";
                    }
                    echo ">" . htmlspecialchars("" . $value, ENT_QUOTES) . "</option>";
                }
                ?>
            </select>
            <p class="validator-hint hidden">
                <?php $this->language->render("required-please-select-a-value"); ?>
            </p>
        </fieldset>

        <?php

        if ($this->isAJAX()) {
            if ($this->autofocus) {
                $this->view->renderJS(function () {
                    ?>
                    <script>
                        (function () {
                            var el = document.getElementById("<?php echo $this->id ?>");
                            if (el) {
                                el.focus();
                            }
                        })();                        
                    </script>
                    <?php
                });
            }
        }
    }

    public function validateValue()
    {
        if (strlen($this->value) == 0) {
            $this->parent->setElementError($this->name, true);
            return false;
        }
        if (!array_key_exists($this->value, $this->list)) {
            $this->parent->setElementError($this->name, true);
            return false;
        }
        return true;
    }

    public function getValue()
    {
        $this->value = $this->parent->getElementValueString($this->name, "");
    }

}

