<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Database\Export;

defined("ANDORA") or die("Forbidden");

// admin/database/export: an API route used with the session cookies of the
// admin page (no API bearer token), same access as admin/database
class Authorization extends \Andora\Web\Route\Admin\Database\Authorization
{
    public function checkBearerToken($bearerToken)
    {
        return true;
    }
}

return Authorization::class;
