<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace User\Login;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputEmail;
use \Andora\Web\Component\InputPasswordLogin;

class Form extends \Andora\Web\Component\Form
{

    public function init($options = null)
    {
        parent::init($options);

        $this->value->email = null;
        $this->value->password = null;

        LucideIcons::register($this, "icons");

        InputEMail::register($this, "email", array(
            "name" => "email",
            "placeholder" => "mail@example.com"
        ));

        InputPasswordLogin::register($this, "password", array(
            "name" => "password",
            "linkForgotPassword" => $this->site . "user/forgot-password"
        ));
    }

    public function renderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("enter-your-email-below-to-login-to-your-account"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php if ($this->hasAlert()) { ?>
                <div role="alert" class="alert alert-error">
                    <?php $this->renderComponent("icons", array("icon" => "circle-x", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("error-incorrect-login"); ?></span>
                </div>
            <?php } ?>

            <?php $this->renderComponent("email"); ?>
            <?php $this->renderComponent("password"); ?>

            <button class="btn btn-neutral">
                <?php $this->language->render("page.title"); ?>
            </button>

            <div class="text-center mt-8">
                <span class="text-base"><?php $this->language->render("don-t-have-an-account"); ?></span>
                <a href="<?php echo $this->site; ?>user/sign-up"
                    class="link link-hover font-medium underline"><?php $this->language->render("sign-up"); ?></a>
            </div>

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
