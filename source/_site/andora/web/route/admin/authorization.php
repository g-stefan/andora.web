<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\User;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Setup;
use \Andora\Web\Package\Manager;

// admin/*: logged in users with the system.admin permission.
// A folder below can require one more permission with its own
// authorization.php extending this class and setting $permission.
class Authorization extends \Andora\Web\Authorization
{
    protected $permission = null;

    public function checkAuthorization()
    {
        // Not configured: redirect to the setup wizard
        if (!parent::checkAuthorization()) {
            return false;
        }
        $reason = "";
        // A database made by an older version, upgraded before any other
        // query (SQLite can not rebuild a table while a query is open)
        if (!Setup::isSchemaCurrent($this->web)) {
            if (!Setup::upgrade($this->web, $reason)) {
                return false;
            }
        }
        // Newer andora/web files: record the version, add what it needs
        if (!Manager::syncCore($this->web, $reason)) {
            return false;
        }
        if (!User::checkCurrentSession($this->web, $reason)) {
            header("Location: " . $this->info->site . "user/login", true, 302);
            exit(0);
        }
        // Logged in, but only users with the system.admin permission can use admin/*
        if (!Acl::can($this->web, Acl::PERMISSION_SYSTEM_ADMIN)) {
            return false;
        }
        if (!is_null($this->permission)) {
            if (!Acl::can($this->web, $this->permission)) {
                return false;
            }
        }
        if (strcmp($this->info->path, "admin") == 0) {
            header("Location: " . $this->info->site . "admin/dashboard", true, 302);
        }
        return true;
    }
}

return Authorization::class;
