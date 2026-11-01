<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\System;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\SystemCheck;

// System: what the server gives Andora (PHP, extensions, write permission,
// php.ini limits, server, database) and the last log files, to find why
// something does not work on a hosting (\Andora\Web\Model\SystemCheck).
class Page extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->setTitle("Admin - System");
        Dashboard::setApplicationTitle("System");
        SystemCheck::register($this, "system-check", array("mode" => "admin"));
    }

    public function render($options = null)
    { ?>
        <div class="flex flex-col gap-4 w-full max-w-[1024px] mx-auto" id="system">
            <p class="text-sm text-base-content/70"><?php $this->language->render("description"); ?></p>
            <?php $this->renderComponent("system-check"); ?>
        </div>
    <?php }

}

return Page::class;
