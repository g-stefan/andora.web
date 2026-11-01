<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\InputText;

// Add a registry: a name (a-z 0-9 -) and the URL of its index (https);
// checked by Registry::add (Page::onRegistryAddProcess)
class FormRegistryAdd extends \Andora\Web\Component\Form
{
    public function formInit($options = null)
    {
        $this->value->name = null;
        $this->value->url = null;
    }

    public function formInitComponents($options = null)
    {
        InputText::register($this, "name", array(
            "name" => "name",
            "legend" => $this->language->get("registry-name.legend"),
            "placeholder" => "acme",
            "required" => true,
            "autofocus" => true,
            "autocomplete" => "off"
        ));
        InputText::register($this, "url", array(
            "name" => "url",
            "legend" => $this->language->get("registry-url.legend"),
            "placeholder" => "https://packages.example/index.json",
            "required" => true,
            "autocomplete" => "off"
        ));
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("registry-add"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php $this->renderComponent("name"); ?>
            <?php $this->renderComponent("url"); ?>
            <p class="text-sm text-base-content/70"><?php $this->language->render("registry-add-hint"); ?></p>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel"><?php $this->language->render("close"); ?></button>
                <div class="ml-auto">
                    <button class="btn btn-primary"><?php $this->language->render("registry-add.submit"); ?></button>
                </div>
            </div>

            <?php
            $this->view->renderJS(function () {
                ?>
                <script>
                    document.getElementById("<?php echo $this->id; ?>_cancel").addEventListener("click", function (event) {
                        <?php $this->parent->renderJSClose(); ?>
                        event.preventDefault();
                    });
                </script>
                <?php
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
