<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\Login;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Model\User;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Setup;

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
        $userId = null;
        if (!User::login($this->web, $this->form->value->email, $this->form->value->password, $reason, $userId)) {
            $this->form->setElementError("email", true);
            $this->form->setElementError("password", true);
            $this->form->setElementAlert("email", true);
            return;
        }

        // ---
        $options = array(
            "title" => $this->language->get("page.title"),
            "message" => $this->language->get("login-successfully")
        );
        // Users with access to the admin area go to the dashboard.
        // A database made by an older version is upgraded by the admin area
        // first, the permission is checked there.
        if ((!Setup::isSchemaCurrent($this->web)) || Acl::can($this->web, Acl::PERMISSION_SYSTEM_ADMIN, $userId)) {
            $options["redirectURLAfterTimeout"] = array($this->site . "admin/dashboard", 1500);
        }
        MessageOk::registerAndInit($this, "form", $options);
    }

    public function render($options = null)
    {
        $this->renderComponent("form");
    }

}

return Page::class;
