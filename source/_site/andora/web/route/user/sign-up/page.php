<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\SignUp;

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
        if (!$this->isPOST()) {
            return;
        }
        if ($this->form->hasError()) {
            return;
        }

        $reason = "";
        if (!User::signUp($this->web, $this->form->value->name, $this->form->value->email, $this->form->value->password, $reason)) {
            MessageError::registerAndInit($this, "form", array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $reason
            ));
            return;
        }

        // ---
        MessageOk::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("thank-you-for-your-registration...")
        ));
    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }

}

return Page::class;
