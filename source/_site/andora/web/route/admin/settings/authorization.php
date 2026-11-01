<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Settings;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Acl;

class Authorization extends \Andora\Web\Route\Admin\Authorization
{
    protected $permission = Acl::PERMISSION_SYSTEM_SETTINGS;
}

return Authorization::class;
