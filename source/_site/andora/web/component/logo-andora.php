<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class LogoAndora extends \XYO\Web\Component
{

    protected static $name = "logo-andora";

    public function init($options = null)
    {
        $this->view->cssLinks->set(self::$name, $this->site . "_site/andora/web/client/logo-andora.css");
    }

    public function render($options = null)
    {
        echo "<div class=\"logo-andora\"></div>";
    }

}

