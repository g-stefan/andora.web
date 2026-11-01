<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Profile;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;

// The profile of the logged in user (user menu - Profile): avatar, description and password
class Page extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->setTitle("Admin - " . $this->language->get("page.title"));
        Dashboard::setApplicationTitle($this->language->get("page.title"));

        FormProfile::register($this, "form-profile");
        FormPassword::register($this, "form-password");
    }

    public function render($options = null)
    { ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 w-full max-w-6xl items-start">
            <?php $this->renderComponent("form-profile"); ?>
            <?php $this->renderComponent("form-password"); ?>
        </div>

    <?php }

}

return Page::class;
