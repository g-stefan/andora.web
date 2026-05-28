<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Admin\Settings;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\DataTable;

class Page extends \XYO\Web\Page
{
    protected $formSMTP = null;

    public function init($options = null)
    {
        $this->setTitle("Admin - Settings");
        Dashboard::setApplicationTitle("Settings");

        SMTPConfiguration::register($this, "smtp-configuration");
    }

    public function process($options = null)
    {

    }

    public function render($options = null)
    { ?>

        <?php $this->renderComponent("smtp-configuration"); ?>

    <?php }

}

return Page::class;
