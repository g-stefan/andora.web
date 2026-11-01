<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Profile;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\User;
use \Andora\Web\Component\InputPassword;
use \Andora\Web\Component\InputPasswordText;

// New password for the logged in user, the current password is required
class FormPassword extends \Andora\Web\Component\Form
{
    protected $isSaved = false;

    public function formInit($options = null)
    {
        $this->value->currentPassword = null;
        $this->value->password = null;
        $this->value->passwordConfirmation = null;
    }

    public function formInitComponents($options = null)
    {
        LucideIcons::register($this, "icons");

        InputPasswordText::register($this, "currentPassword", array(
            "name" => "currentPassword",
            "required" => true,
            "autocomplete" => "current-password"
        ));

        InputPassword::register($this, "password", array(
            "name" => "password",
            "required" => true,
            "autocomplete" => "new-password",
            "hasLabel" => true
        ));

        InputPassword::register($this, "passwordConfirmation", array(
            "name" => "passwordConfirmation",
            "required" => true,
            "autocomplete" => "new-password"
        ));
    }

    public function formProcess($options = null)
    {
        if (strcmp("" . $this->value->password, "" . $this->value->passwordConfirmation) != 0) {
            $this->setElementError("password", true);
            $this->setElementError("passwordConfirmation", true);
            $this->setElementAlert("form", "passwords-must-match");
            return;
        }

        $reason = "";
        if (!User::changePassword($this->web, User::$user__id, $this->value->currentPassword, $this->value->password, $reason)) {
            if ($reason == "invalid-current-password") {
                $this->setElementError("currentPassword", true);
            } else if (($reason == "invalid-password") || ($reason == "same-password")) {
                $this->setElementError("password", true);
                $this->setElementError("passwordConfirmation", true);
            } else {
                $this->setElementError("form", true);
            }
            $this->setElementAlert("form", $reason);
            return;
        }

        $this->isSaved = true;
        // Do not send the passwords back
        foreach (array("currentPassword", "password", "passwordConfirmation") as $name) {
            $this->getComponent($name)->value = "";
        }
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="mb-3">
            <h2 class="text-lg font-semibold flex items-center gap-2">
                <?php $this->renderComponent("icons", array("icon" => "key-round", "class" => "text-xl")); ?>
                <?php $this->language->render("password.title"); ?>
            </h2>
            <p class="mt-1 text-sm text-base-content/70">
                <?php $this->language->render("password.info"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php if ($this->isSaved) { ?>
                <div role="alert" class="alert alert-success">
                    <?php $this->renderComponent("icons", array("icon" => "circle-check", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("password-changed"); ?></span>
                </div>
            <?php } ?>

            <?php if ($this->hasAlert()) { ?>
                <div role="alert" class="alert alert-error">
                    <?php $this->renderComponent("icons", array("icon" => "circle-x", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("error." . $this->getElementAlert("form")); ?></span>
                </div>
            <?php } ?>

            <?php $this->renderComponent("currentPassword"); ?>
            <?php $this->renderComponent("password"); ?>
            <?php $this->renderComponent("passwordConfirmation"); ?>

            <button type="submit" class="btn btn-neutral mt-2">
                <?php $this->language->render("change-password"); ?>
            </button>

            <?php
        }, "flex flex-col gap-2");
    }

    public function renderContainer($options = null)
    { ?>
        <div class="card bg-base-100 border border-base-300 rounded-box p-6 w-full flex flex-col" id="<?php echo $this->id; ?>">
            <?php $this->renderAJAX(); ?>
        </div>
        <?php
    }
}
