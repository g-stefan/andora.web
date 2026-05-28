<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class AJAXRedirect extends \XYO\Web\Component
{

    public $redirect = null;
    public function init($options = null)
    {
        $this->redirect = null;
        if (array_key_exists("redirect", $options)) {
            $this->redirect = $options["redirect"];
        }
    }

    public function renderAJAX($options = null)
    {
        if (!is_null($this->redirect)) {
            $this->view->renderJS(function () {
                $this->redirect->renderJSRequestGet();
            });
        }
    }

    public function renderContainer($options = null)
    {
    }

}

