<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web;

defined("ANDORA") or die("Forbidden");

class Client extends \XYO\Web\Component
{

    protected static $name = "andora.client";

    public function init($options = null)
    {
        $this->view->cssLinks->removeGroup(self::$name);
        $this->view->cssLinks->set(self::$name, $this->site . "_site/andora/web/client/andora.css");
        $this->view->jsLinks->set(self::$name, $this->site . "_site/andora/web/client/andora.js", "defer");
    }

}
