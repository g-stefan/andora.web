<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\Route\Admin\Example;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputName;
use \Andora\Web\Component\InputText;

class FormEdit extends \Andora\Web\Component\Form
{

    public function formInit($options = null)
    {
        $this->value->name = "";
        $this->value->description = "";
    }

    public function formInitComponents($options = null)
    {

        LucideIcons::register($this, "icons");

        InputName::register($this, "name", array(
            "name" => "name",
            "required" => true,
            "autofocus" => true,
            "initialValue" => $this->value->name,
            "autocomplete" => "off"
        ));

        InputText::register($this, "description", array(
            "name" => "description",
            "required" => false,
            "initialValue" => $this->value->description,
            "autocomplete" => "off"
        ));

    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("edit-item"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php $this->renderComponent("name"); ?>
            <?php $this->renderComponent("description"); ?>

            <button class="btn btn-neutral">
                <?php $this->language->render("submit"); ?>
            </button>

            <?php
        }, "flex flex-col gap-2");

    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }

}
