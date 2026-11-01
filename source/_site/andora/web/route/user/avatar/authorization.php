<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\Avatar;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\User;

// user/avatar: logged in users only, with the session cookies (no API bearer token)
class Authorization extends \Andora\Web\Authorization
{
    public function checkBearerToken($bearerToken)
    {
        return true;
    }

    public function checkAuthorization()
    {
        if (!parent::checkAuthorization()) {
            return false;
        }
        $reason = "";
        return User::checkCurrentSession($this->web, $reason);
    }
}

return Authorization::class;
