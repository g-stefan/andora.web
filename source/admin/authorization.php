<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Admin;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\User;

class Authorization extends \XYO\Web\Authorization
{
    public function checkAuthorization()
    {

        $reason = "";
        $isAuthorized = false;
        if ($this->config->get("configured")) {
            if (User::checkCurrentSession($this->web, $reason)) {
                $isAuthorized = true;
            }
        }

        if (!$isAuthorized) {
            $this->session->set("login_as_admin", true); 
            header("Location: " . $this->info->site . "user/login", true, 302);            
            exit(0);
        }        

        if (strcmp($this->info->path, "admin") == 0) {
            header("Location: " . $this->info->site . "admin/dashboard", true, 302);
        }

        return true;
    }

}

return Authorization::class;
