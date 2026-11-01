<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\ForgotPassword;

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

        // The same answer is given for known and unknown e-mail addresses,
        // so the page can not be used to find out who has an account
        $reason = "";
        User::forgotPassword($this->web, $this->form->value->email, $reason);

        // ---
        MessageOk::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("your-request-has-ben-recorded...")
        ));
    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }


}

return Page::class;
