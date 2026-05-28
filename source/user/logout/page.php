<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace User\Logout;

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
        MessageOk::register($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("logout-successfully")
        ));
    }

    public function process($options = null)
    {

        $reason = "";
        if (!User::logoutCurrentSession($this->web, $reason)) {
            MessageError::registerAndInit($this, "form", array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $reason
            ));
            return;
        }

    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }


}

return Page::class;
