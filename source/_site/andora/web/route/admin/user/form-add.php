<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\User;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputName;
use \Andora\Web\Component\InputEmail;
use \Andora\Web\Component\InputPassword;

class FormAdd extends \Andora\Web\Component\Form
{

    public function formInit($options = null)
    {

        $this->value->name = null;
        $this->value->email = null;
        $this->value->password = null;
        $this->value->passwordConfirmation = null;
    }

    public function formInitComponents($options = null)
    {

        LucideIcons::register($this, "icons");

        InputName::register($this, "name", array(
            "name" => "name",
            "required" => true,
            "autofocus" => true,
            "autocomplete" => "off"
        ));

        InputEMail::register($this, "email", array(
            "name" => "email",
            "placeholder" => "mail@example.com",
            "required" => true,
            "autocomplete" => "off"
        ));

        InputPassword::register($this, "password", array(
            "name" => "password",
            "hasLabel" => true,
            "required" => true,
            "autocomplete" => "new-password"
        ));

        InputPassword::register($this, "passwordConfirmation", array(
            "name" => "passwordConfirmation",
            "required" => true,
            "autocomplete" => "off"
        ));

    }

    public function formProcess($options = null)
    {
        if (!(strcmp($this->value->password, $this->value->passwordConfirmation) == 0)) {
            $this->setElementError("password", true);
            $this->setElementError("passwordConfirmation", true);
            $this->setElementAlert("password", true);
            return;
        }
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("add-new-user-account"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php if ($this->hasAlert()) { ?>
                <div role="alert" class="alert alert-error">
                    <?php $this->renderComponent("icons", array("icon" => "circle-x", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("error-the-passwords-must-match"); ?></span>
                </div>
            <?php } ?>

            <?php $this->renderComponent("name"); ?>
            <?php $this->renderComponent("email"); ?>
            <?php $this->renderComponent("password"); ?>
            <?php $this->renderComponent("passwordConfirmation"); ?>

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
