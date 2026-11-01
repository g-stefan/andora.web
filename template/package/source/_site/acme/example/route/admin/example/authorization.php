<?php
// Example package
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\Route\Admin\Example;

defined("ANDORA") or die("Forbidden");

use \Acme\Example\Package\Setup;

// admin/example: the admin area checks (logged in, system.admin) and the
// permission of the package
class Authorization extends \Andora\Web\Route\Admin\Authorization
{
    protected $permission = Setup::PERMISSION_MANAGE;
}

return Authorization::class;
