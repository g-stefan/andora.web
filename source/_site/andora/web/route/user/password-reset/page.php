<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\PasswordReset;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Model\User;

class Page extends \XYO\Web\Page
{
    protected $form = null;

    public function init($options = null)
    {
        $this->setTitle("Andora - " . $this->language->get("page.title"));
        $this->form = Form::register($this, "form");
    }

    public function process($options = null)
    {
        $reason = "";
        $uuid = $this->form->value->uuid;

        if (!$this->isPOST()) {
            if (!User::checkPasswordResetRequest($this->web, $uuid, $reason)) {
                $this->messageError($reason);
            }
            return;
        }
        if ($this->form->hasError()) {
            return;
        }

        if (!User::passwordReset($this->web, $uuid, $this->form->value->password, $reason)) {
            if ($reason == "invalid-password") {
                $this->form->setElementError("password", true);
                $this->form->setElementError("passwordConfirmation", true);
                return;
            }
            $this->messageError($reason);
            return;
        }

        // ---
        MessageOk::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("password-reset-successfully")
        ));
    }

    public function messageError($reason)
    {
        MessageError::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("an-error-occurred"),
            "reason" => $reason
        ));
    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }


}

return Page::class;

