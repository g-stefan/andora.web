<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class SidebarHeader extends \XYO\Web\Component
{
    public function init($options = null)
    {
        LucideIcons::register($this, "icons");
    }

    public function render($options = null)
    {
        ?>
        <?php $this->renderComponent("icons", array("icon" => "building", "class" => "text-base-content text-lg")); ?>
        <span class="font-semibold text-base-content tracking-tight ml-3">Andora</span>
        <?php
    }

}

