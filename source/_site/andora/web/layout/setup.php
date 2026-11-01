<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Layout;

defined("ANDORA") or die("Forbidden");

use \XYO\OverlayScrollbars\OverlayScrollbars;
use \Andora\Web\Client;
use \Andora\Web\Component\LogoAndora;
use \Andora\Web\Component\ThemeChange;

class Setup extends \XYO\Web\Layout
{
    public function init($options = null)
    {
        OverlayScrollbars::register($this);
        Client::register($this);
        LogoAndora::register($this, "logo-andora");
        ThemeChange::register($this, "theme-change");
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
            <div class="w-full lg:grid lg:min-h-[600px] lg:grid-cols-2 xl:min-h-[800px]">
                <div>
                    <div class="flex justify-end py-3 px-3"><?php $this->renderComponent("theme-change"); ?></div>
                    <div class="flex flex-col items-center justify-center">
                        <?php $this->renderPage($page); ?>
                    </div>
                    <div class="h-16"></div>
                </div>
                <!-- The logo stays at the top, the steps can be long (the system check) -->
                <div class="hidden lg:block">
                    <div class="sticky top-0 pt-24 px-12">
                        <div class="w-full max-w-[832px] mx-auto aspect-[832/464]"><?php $this->renderComponent("logo-andora", array("class" => "logo-andora-contain")); ?></div>
                    </div>
                </div>
            </div>
            <?php $this->renderScripts(); ?>
        </body>

        </html>

    <?php }

}
