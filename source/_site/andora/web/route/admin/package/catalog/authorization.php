<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

// admin/package/catalog: same access as admin/package (package.manage)
class Authorization extends \Andora\Web\Route\Admin\Package\Authorization
{
}

return Authorization::class;
