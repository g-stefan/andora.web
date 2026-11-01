<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Layout;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Client;

// Plain page with the Andora styles, used by the root layout.php: the home page
// and the error pages (http-*.php) of the site. A system built on Andora can
// replace the root layout.php or extend this class.
class Base extends \XYO\Web\Layout
{
    public function init($options = null)
    {
        Client::register($this);
    }

    public function renderLayout($page = null)
    { ?><!DOCTYPE html>
        <html <?php
        $this->renderLanguage();
        $this->renderHTMLClasses();
        ?>>

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <?php $this->renderHead(); ?>
        </head>

        <body <?php $this->renderBodyClasses(); ?>>
            <?php $this->renderPage($page); ?>
            <?php $this->renderScripts(); ?>
        </body>

        </html>

    <?php }

}
