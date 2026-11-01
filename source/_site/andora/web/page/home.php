<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Page;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\LogoAndora;
use \Andora\Web\Component\ThemeChange;

// Default public page of the site (the root page.php, after the setup).
// A system built on Andora can replace the root page.php or extend this class.
class Home extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->loadLanguageFromPath("./_site/andora/web/page", "home");
        $this->setTitle($this->language->get("andora.home.title"));
        LogoAndora::register($this, "logo-andora");
        ThemeChange::register($this, "theme-change");
    }

    public function render($options = null)
    { ?>
        <div class="flex min-h-screen flex-col">
            <div class="flex justify-end py-3 px-3"><?php $this->renderComponent("theme-change"); ?></div>
            <div class="flex flex-1 flex-col items-center justify-center gap-8 px-4 pb-16">
                <div class="w-full max-w-[832px] aspect-[832/464]">
                    <?php $this->renderComponent("logo-andora", array("class" => "logo-andora-contain")); ?>
                </div>
                <a class="btn btn-neutral" href="<?php echo $this->site; ?>user/login"><?php $this->language->render("andora.home.login"); ?></a>
            </div>
        </div>
    <?php }

}
