<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Setup;

defined("ANDORA") or die("Forbidden");

// setup: open before the site is configured, the page itself
// refuses every step once it is (see page.php)
class Authorization extends \XYO\Web\Authorization
{
}

return Authorization::class;
