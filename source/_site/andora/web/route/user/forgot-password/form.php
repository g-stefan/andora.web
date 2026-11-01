<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\ForgotPassword;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputEmail;

class Form extends \Andora\Web\Component\Form
{

    public function init($options = null)
    {
        parent::init($options);

        $this->value->email = null;

        LucideIcons::register($this, "icons");

        InputEmail::register($this, "email", array(
            "name" => "email",
            "placeholder" => "mail@example.com"
        ));

    }

    public function renderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("enter-your-email below..."); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php $this->renderComponent("email"); ?>

            <button class="btn btn-neutral">
                <?php $this->language->render("request-password-reset"); ?>
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
