<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\EmailConfirmation;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Model\User;

class Page extends \XYO\Web\Page
{
    protected $form = null;
    protected $allOk = null;

    public function init($options = null)
    {
        $this->setTitle("Andora - " . $this->language->get("page.title"));
    }

    public function process($options = null)
    {
        $reason = "";
        $uuid = $this->request->get("uuid", "");
        if (!is_string($uuid)) {
            $uuid = "";
        }

        if (!User::emailConfirm($this->web, $uuid, $reason)) {
            if ($reason == "already-confirmed") {
                MessageOk::registerAndInit($this, "form", array(
                    "title" => $this->language->get("page.title"),
                    "message" => $this->language->get("already-confirmed")
                ));
                return;
            }
            MessageError::registerAndInit($this, "form", array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $reason
            ));
            return;
        }

        MessageOk::registerAndInit($this, "form", array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("confirmation-successfully")
        ));
    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }


}

return Page::class;
