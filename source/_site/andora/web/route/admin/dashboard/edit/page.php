<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Dashboard\Edit;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;

// admin/dashboard/edit: the dashboard of the current user in edit mode
// (the Editor component), in the administration popup
class Page extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->setTitle("Admin - Dashboard edit");
        Dashboard::setApplicationTitle("Dashboard");
        Editor::register($this, "editor");
    }

    public function render($options = null)
    {
        $this->renderComponent("editor");
    }

}

return Page::class;
