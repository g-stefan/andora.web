<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\DataSource\TableUserGroupXPermission;
use \Andora\Web\DataSource\TableAdminMenu;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Setup;

// Lifecycle of a package. _site/<vendor>/<name>/package.php returns the name
// of a class extending this one (only the name, the class is in its own file:
// \Andora\Web\Package\Manager may include package.php more than once).
// A package without package.php uses this class as it is (files only).
//
// The default hooks work with $tables (the Table classes of the package,
// by default the "datasource" list of package.json): install creates them,
// upgrade updates them (new columns), reset recreates them (empty); each one
// then calls seed() for the rows the code needs (permissions, admin menu,
// ...). Override what the package needs. Every class of $tables must be in
// the "datasource" of package.json (the database backups use that list).
// The hooks return true / false and set &$reason to a short code.
class Base
{
    protected $web = null;
    protected $info = null;
    protected $tables = array();

    public function __construct($web, $info)
    {
        $this->web = $web;
        $this->info = $info;
        if ((count($this->tables) == 0) && is_array($info->manifest ?? null)) {
            $this->tables = $info->manifest["datasource"];
        }
    }

    // The Table classes the default hooks work with
    public function getTables()
    {
        return $this->tables;
    }

    // <vendor>/<name>
    public function getCode()
    {
        return $this->info->code;
    }

    // Version of the files
    public function getVersion()
    {
        return $this->info->version;
    }

    // Data of the package, kept when the folder is replaced:
    // _repository/<vendor>/<name>/ (created)
    public function dataPath()
    {
        $path = ANDORA_PATH . "_repository/" . $this->info->vendor . "/" . $this->info->name . "/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }

    public function getDataSource($dsClass)
    {
        $dsConnection = $this->web->get(\XYO\Web\DataSource\Connection::class);
        return $dsConnection->getDataSource($dsClass);
    }

    // Requirements the manifest can not express, before install and upgrade
    public function check(&$reason)
    {
        return true;
    }

    public function install(&$reason)
    {
        if (!$this->createTables($this->tables, $reason)) {
            return false;
        }
        return $this->seed($reason);
    }

    // The files were replaced by a newer version, $fromVersion is the version installed before
    public function upgrade($fromVersion, &$reason)
    {
        if (!$this->updateTables($this->tables, $reason)) {
            return false;
        }
        return $this->seed($reason);
    }

    // Back to the state right after install: the data of the package is lost
    public function reset(&$reason)
    {
        if (!$this->recreateTables($this->tables, $reason)) {
            return false;
        }
        return $this->seed($reason);
    }

    // Remove what install made (used by the uninstall of a next version)
    public function uninstall($keepData, &$reason)
    {
        if (!$keepData) {
            if (!$this->dropTables($this->tables, $reason)) {
                return false;
            }
        }
        return true;
    }

    public function enable(&$reason)
    {
        return true;
    }

    public function disable(&$reason)
    {
        return true;
    }

    // The rows the code needs, safe to run again (existing rows are kept).
    // Also runs after the tables of the package were restored from a
    // database backup (\Andora\Web\Package\DatabaseBackup).
    public function seed(&$reason)
    {
        return true;
    }

    // Events: package.json "event": {"listen": {"user.delete": "onUserDelete"}}
    // calls the public method onUserDelete($event) of this class (an Event:
    // $event->get("userId")), see \Andora\Web\Package\Event. A package fires
    // the events it provides with Event::dispatch($web, $name, $data).

    // The panels of the package for /admin/dashboard, an array
    // name => panel, the name is [a-z0-9] words joined by "-" (at most 64),
    // the panel id is <vendor>/<name>/<panel name>. A panel is an array:
    //   "title"       => text of the card (escaped by the dashboard)
    //   "description" => optional, shown when the dashboard is edited
    //   "icon"        => optional, a lucide icon shown before the title
    //   "permission"  => optional, the panel is shown only if Acl::can
    //   "size"        => optional, 1 (default) - one column, 2 - two
    //                    columns, 3 - the full row; the user can change it
    //   "default"     => optional, true - on the dashboard of the users that
    //                    did not change theirs (\Andora\Web\Model\Dashboard)
    //   "render"      => callable($web) echoing the HTML of the panel (escape
    //                    what you output), or
    //   "component"   => a class extending \XYO\Web\Component, registered on
    //                    the dashboard (can use AJAX)
    // An exception is logged, the other panels are shown.
    public function dashboardPanels()
    {
        return array();
    }

    // Helpers

    public function createTables($tables, &$reason)
    {
        foreach ($tables as $dsClass) {
            if (!$this->getDataSource($dsClass)->createStorage()) {
                $reason = "database-error";
                return false;
            }
        }
        return true;
    }

    public function updateTables($tables, &$reason)
    {
        // SQLite rebuilds a table to change it, not possible while a query is open
        gc_collect_cycles();
        foreach ($tables as $dsClass) {
            if (!$this->getDataSource($dsClass)->storageUpdateTable()) {
                $reason = "database-error";
                return false;
            }
        }
        return true;
    }

    public function recreateTables($tables, &$reason)
    {
        gc_collect_cycles();
        foreach ($tables as $dsClass) {
            if (!$this->getDataSource($dsClass)->recreateStorage()) {
                $reason = "database-error";
                return false;
            }
        }
        return true;
    }

    public function dropTables($tables, &$reason)
    {
        gc_collect_cycles();
        foreach ($tables as $dsClass) {
            if (!$this->getDataSource($dsClass)->destroyStorage()) {
                $reason = "database-error";
                return false;
            }
        }
        return true;
    }

    // A permission used by the code of the package: a system permission
    // (no delete / rename / disable from the admin pages), tagged with the
    // package. An existing permission with the same name is taken over only
    // if it is not used by Andora or by another package.
    public function registerPermission($name, $description, &$reason)
    {
        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->name = $name;
        if ($dsPermission->load(0, 1)) {
            if ("" . $dsPermission->package === $this->info->code) {
                return true;
            }
            if ((intval($dsPermission->is_system) == 1) || (strlen("" . $dsPermission->package) > 0)) {
                $reason = "permission-in-use";
                return false;
            }
        } else {
            $dsPermission->clear();
            $dsPermission->name = $name;
            $dsPermission->description = $description;
            $dsPermission->enabled = 1;
        }
        $dsPermission->is_system = 1;
        $dsPermission->package = $this->info->code;
        if (!$dsPermission->save()) {
            $reason = "database-error";
            return false;
        }
        Acl::reset();
        return true;
    }

    // Grant a permission to a system group (Acl::GROUP_ADMINISTRATOR, ...), once
    public function grantPermissionToGroup($name, $groupName, &$reason)
    {
        $groupId = Acl::getSystemGroupId($this->web, $groupName);
        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->name = $name;
        if (($groupId == 0) || !$dsPermission->load(0, 1)) {
            $reason = "database-error";
            return false;
        }
        $dsLink = $this->getDataSource(TableUserGroupXPermission::class);
        $dsLink->clear();
        $dsLink->user_group__id = $groupId;
        $dsLink->permission__id = $dsPermission->id;
        if ($dsLink->load(0, 1)) {
            return true;
        }
        $dsLink->clear();
        $dsLink->user_group__id = $groupId;
        $dsLink->permission__id = $dsPermission->id;
        $dsLink->is_granted = 1;
        $dsLink->enabled = 1;
        if (!$dsLink->save()) {
            $reason = "database-error";
            return false;
        }
        Acl::reset();
        return true;
    }

    // An admin menu entry tagged with the package (Setup::registerAdminMenu)
    public function registerAdminMenu($code, $location, $parentCode, $title, $icon, $path, $permission, $level, $position, &$reason)
    {
        if (!Setup::registerAdminMenu($this->web, $code, $location, $parentCode, $title, $icon, $path, $permission, $level, $position, $this->info->code)) {
            $reason = "database-error";
            return false;
        }
        return true;
    }
}
