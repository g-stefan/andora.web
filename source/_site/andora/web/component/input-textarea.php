<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class InputTextarea extends \XYO\Web\Component
{
    public $name = null;
    public $legend = null;
    public $placeholder = null;
    public $required = null;
    public $maxLength = null;
    public $rows = null;
    public $value = null;

    public function init($options = null)
    {
        $this->name = $options["name"];
        $this->legend = null;
        if (array_key_exists("legend", $options)) {
            $this->legend = $options["legend"];
        }
        $this->placeholder = null;
        if (array_key_exists("placeholder", $options)) {
            $this->placeholder = $options["placeholder"];
        }
        $this->required = false;
        if (array_key_exists("required", $options)) {
            if ($options["required"]) {
                $this->required = true;
            }
        }
        $this->maxLength = 0;
        if (array_key_exists("maxLength", $options)) {
            $this->maxLength = intval($options["maxLength"]);
        }
        $this->rows = 4;
        if (array_key_exists("rows", $options)) {
            $this->rows = intval($options["rows"]);
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
        $this->loadLanguageFromPath("./_site/andora/web/component", "input-textarea");

        if (is_null($this->legend)) {
            $this->legend = $this->language->get($this->name . ".legend");
        }
        if (is_null($this->placeholder)) {
            $this->placeholder = $this->language->get($this->name . ".placeholder");
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

        $required = "";
        if ($this->required) {
            $required = "required";
        }

        $maxLength = "";
        if ($this->maxLength > 0) {
            $maxLength = "maxlength=\"" . $this->maxLength . "\"";
        }

        ?>

        <fieldset class="fieldset w-full">
            <legend class="fieldset-legend text-base"><?php echo $this->legend; ?></legend>
            <textarea name="<?php echo $this->name; ?>" class="textarea validator w-full" <?php echo $attribute; ?>
                placeholder="<?php echo $this->placeholder; ?>" rows="<?php echo $this->rows; ?>" <?php
                   echo $required;
                   echo " ";
                   echo $maxLength;
                   ?> id="<?php echo $this->id; ?>"><?php echo htmlspecialchars("" . $this->value, ENT_QUOTES); ?></textarea>
            <p class="validator-hint hidden">
                <?php
                if ($this->maxLength > 0) {
                    echo str_replace("{0}", "" . $this->maxLength, $this->language->get("max-length"));
                } else {
                    $this->language->render("required");
                }
                ?>
            </p>
        </fieldset>

        <?php
    }

    public function validateValue()
    {
        if ($this->required) {
            if (strlen($this->value) == 0) {
                $this->parent->setElementError($this->name, true);
                return false;
            }
        }
        if ($this->maxLength > 0) {
            $length = function_exists("mb_strlen") ? mb_strlen($this->value, "UTF-8") : strlen($this->value);
            if ($length > $this->maxLength) {
                $this->parent->setElementError($this->name, true);
                return false;
            }
        }
        return true;
    }

    public function getValue()
    {
        $this->value = $this->parent->getElementValueString($this->name, "");
    }

}
