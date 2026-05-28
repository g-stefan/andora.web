<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace User\Login;

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
        if (!User::login($this->web, $this->form->value->email, $this->form->value->password, $reason)) {
            $this->form->setElementError("email", true);
            $this->form->setElementError("password", true);
            $this->form->setElementAlert("email", true);
            return;
        }

        // ---
        MessageOk::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("login-successfully")
        ));       
        
        if ($this->session->get("login_as_admin", false)) {
            $this->session->set("login_as_admin", false);
            $this->view->renderJS(function () {
                echo "setTimeout(function(){";
                echo "document.location.href=\"" . $this->site . "admin/dashboard\";";
                echo "},1500);";
            });
        }

    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }

}

return Page::class;
