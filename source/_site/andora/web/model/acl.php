<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableUser;
use \Andora\Web\DataSource\TableUserGroup;
use \Andora\Web\DataSource\TableUserXUserGroup;
use \Andora\Web\DataSource\QueryUserXUserGroup;
use \Andora\Web\DataSource\QueryUserGroupXPermission;
use \Andora\Web\DataSource\QueryUserXPermission;

// Access control: user levels (groups) and permissions.
//
// - The level of a user is the highest level of its enabled groups (no groups
//   inside groups: the memberships are explicit). Every logged in user is in
//   the "authenticated" group, every visitor in the "public" group, without
//   a membership row.
// - root has every permission.
// - Otherwise a permission given directly to the user decides (granted or
//   denied), then a group that denies it, then a group that grants it.
// - A disabled permission is not granted to anybody (except root).
//
// Everything a user has is loaded once per request (4 queries, 2 for a
// visitor), then the checks are array lookups.
class Acl
{
    public const LEVEL_PUBLIC = 0;
    public const LEVEL_AUTHENTICATED = 1;
    public const LEVEL_ADMINISTRATOR = 2;
    public const LEVEL_ROOT = 3;

    // The system groups (is_system = 1), found by name: they can not be renamed.
    // public and authenticated are implicit (no membership rows), user is
    // given to every new account (User::addUser).
    public const GROUP_PUBLIC = "public";
    public const GROUP_AUTHENTICATED = "authenticated";
    public const GROUP_USER = "user";
    public const GROUP_ADMINISTRATOR = "administrator";
    public const GROUP_ROOT = "root";

    // Permissions used by the code
    public const PERMISSION_SYSTEM_ADMIN = "system.admin";
    public const PERMISSION_SYSTEM_SETTINGS = "system.settings";
    public const PERMISSION_USER_MANAGE = "user.manage";
    public const PERMISSION_USER_DELETE = "user.delete";
    public const PERMISSION_USER_GROUP_MANAGE = "user-group.manage";
    public const PERMISSION_PERMISSION_MANAGE = "permission.manage";
    public const PERMISSION_PACKAGE_MANAGE = "package.manage";
    public const PERMISSION_DATABASE_MANAGE = "database.manage";

    // userId => resolved access (level, groups, permission)
    protected static $cache = array();

    // name => level
    public static function systemGroupList()
    {
        return array(
            self::GROUP_PUBLIC => self::LEVEL_PUBLIC,
            self::GROUP_AUTHENTICATED => self::LEVEL_AUTHENTICATED,
            self::GROUP_USER => self::LEVEL_AUTHENTICATED,
            self::GROUP_ADMINISTRATOR => self::LEVEL_ADMINISTRATOR,
            self::GROUP_ROOT => self::LEVEL_ROOT
        );
    }

    // public / authenticated: every visitor / logged in user, never assigned
    public static function isImplicitGroup($name, $isSystem)
    {
        return (intval($isSystem) == 1) && in_array("" . $name, array(self::GROUP_PUBLIC, self::GROUP_AUTHENTICATED), true);
    }

    public static function systemPermissionList()
    {
        return array(
            self::PERMISSION_SYSTEM_ADMIN => "Access to the administration area",
            self::PERMISSION_SYSTEM_SETTINGS => "Change the system settings",
            self::PERMISSION_USER_MANAGE => "Manage the users",
            self::PERMISSION_USER_DELETE => "Delete users",
            self::PERMISSION_USER_GROUP_MANAGE => "Manage the user groups",
            self::PERMISSION_PERMISSION_MANAGE => "Manage the permissions",
            self::PERMISSION_PACKAGE_MANAGE => "Install, upgrade and reset the packages",
            self::PERMISSION_DATABASE_MANAGE => "Export and import the database"
        );
    }

    // Forget the resolved access (after changes made in the same request)
    public static function reset()
    {
        self::$cache = array();
    }

    // The logged in user (User::checkCurrentSession), 0 for a visitor
    public static function currentUserId()
    {
        if (User::$isAuthorized) {
            return intval(User::$user__id);
        }
        return 0;
    }

    public static function resolve($web, $userId = null)
    {
        if (is_null($userId)) {
            $userId = self::currentUserId();
        }
        $userId = intval($userId);
        if ($userId < 0) {
            $userId = 0;
        }
        if (array_key_exists($userId, self::$cache)) {
            return self::$cache[$userId];
        }

        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);

        $acl = new \stdClass();
        $acl->userId = $userId;
        $acl->level = ($userId > 0) ? self::LEVEL_AUTHENTICATED : self::LEVEL_PUBLIC;
        $acl->groups = array(); // group id => level
        $acl->permission = array(); // permission name => true / false

        // The implicit group: public or authenticated
        $dsUserGroup = $dsConnection->getDataSource(TableUserGroup::class);
        $dsUserGroup->clear();
        $dsUserGroup->is_system = 1;
        $dsUserGroup->name = ($userId > 0) ? self::GROUP_AUTHENTICATED : self::GROUP_PUBLIC;
        $dsUserGroup->enabled = 1;
        if ($dsUserGroup->load(0, 1)) {
            $acl->groups[intval($dsUserGroup->id)] = intval($dsUserGroup->level);
        }

        if ($userId > 0) {
            // The groups of the user
            $dsGroups = $dsConnection->getDataSource(QueryUserXUserGroup::class);
            $dsGroups->clear();
            $dsGroups->user__id = $userId;
            $dsGroups->enabled = 1;
            $dsGroups->group_enabled = 1;
            for ($dsGroups->load(); $dsGroups->loadIsValid(); $dsGroups->loadNext()) {
                $acl->groups[intval($dsGroups->user_group__id)] = intval($dsGroups->group_level);
            }
        }

        foreach ($acl->groups as $level) {
            if ($level > $acl->level) {
                $acl->level = $level;
            }
        }
        if ($acl->level > self::LEVEL_ROOT) {
            $acl->level = self::LEVEL_ROOT;
        }

        // root has every permission, no need to load them
        if ($acl->level < self::LEVEL_ROOT) {
            $granted = array();
            $denied = array();
            if (count($acl->groups) > 0) {
                $dsGroupPermission = $dsConnection->getDataSource(QueryUserGroupXPermission::class);
                $dsGroupPermission->clear();
                $dsGroupPermission->user_group__id = array_keys($acl->groups);
                $dsGroupPermission->enabled = 1;
                $dsGroupPermission->permission_enabled = 1;
                for ($dsGroupPermission->load(); $dsGroupPermission->loadIsValid(); $dsGroupPermission->loadNext()) {
                    if (intval($dsGroupPermission->is_granted) == 1) {
                        $granted[$dsGroupPermission->permission_name] = true;
                    } else {
                        $denied[$dsGroupPermission->permission_name] = true;
                    }
                }
            }
            foreach ($granted as $name => $value) {
                if (!array_key_exists($name, $denied)) {
                    $acl->permission[$name] = true;
                }
            }
            foreach ($denied as $name => $value) {
                $acl->permission[$name] = false;
            }

            if ($userId > 0) {
                $dsUserPermission = $dsConnection->getDataSource(QueryUserXPermission::class);
                $dsUserPermission->clear();
                $dsUserPermission->user__id = $userId;
                $dsUserPermission->enabled = 1;
                $dsUserPermission->permission_enabled = 1;
                for ($dsUserPermission->load(); $dsUserPermission->loadIsValid(); $dsUserPermission->loadNext()) {
                    $acl->permission[$dsUserPermission->permission_name] = (intval($dsUserPermission->is_granted) == 1);
                }
            }
        }

        self::$cache[$userId] = $acl;
        return $acl;
    }

    public static function level($web, $userId = null)
    {
        return self::resolve($web, $userId)->level;
    }

    public static function isRoot($web, $userId = null)
    {
        return self::level($web, $userId) >= self::LEVEL_ROOT;
    }

    public static function can($web, $permission, $userId = null)
    {
        $acl = self::resolve($web, $userId);
        if ($acl->level >= self::LEVEL_ROOT) {
            return true;
        }
        if (array_key_exists($permission, $acl->permission)) {
            return $acl->permission[$permission];
        }
        return false;
    }

    // The current user can change the target user: root can change everybody,
    // the others only the users with a lower level.
    // Yourself: only when $allowSelf (edit your name / password, not your
    // groups or permissions).
    public static function canManageUser($web, $targetUserId, $allowSelf = false)
    {
        $targetUserId = intval($targetUserId);
        $currentUserId = self::currentUserId();
        if (($currentUserId <= 0) || ($targetUserId <= 0)) {
            return false;
        }
        if ($targetUserId == $currentUserId) {
            return $allowSelf;
        }
        $level = self::level($web, $currentUserId);
        if ($level >= self::LEVEL_ROOT) {
            return true;
        }
        return $level > self::level($web, $targetUserId);
    }

    public static function getGroupLevel($web, $groupId)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsUserGroup = $dsConnection->getDataSource(TableUserGroup::class);
        $dsUserGroup->clear();
        $dsUserGroup->id = intval($groupId);
        if ($dsUserGroup->load(0, 1)) {
            return intval($dsUserGroup->level);
        }
        return null;
    }

    // The current user can change the group (its links and permissions) or
    // give it to a user: root any group, the others only groups with a lower level.
    public static function canManageGroup($web, $groupId)
    {
        $currentUserId = self::currentUserId();
        if ($currentUserId <= 0) {
            return false;
        }
        $groupLevel = self::getGroupLevel($web, $groupId);
        if (is_null($groupLevel)) {
            return false;
        }
        $level = self::level($web, $currentUserId);
        if ($level >= self::LEVEL_ROOT) {
            return true;
        }
        return $level > $groupLevel;
    }

    // Only a permission you have can be given to others (root has all)
    public static function canGrantPermission($web, $permission)
    {
        if (self::currentUserId() <= 0) {
            return false;
        }
        return self::can($web, $permission);
    }

    // $name - Acl::GROUP_*
    public static function getSystemGroupId($web, $name)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsUserGroup = $dsConnection->getDataSource(TableUserGroup::class);
        $dsUserGroup->clear();
        $dsUserGroup->is_system = 1;
        $dsUserGroup->name = "" . $name;
        if ($dsUserGroup->load(0, 1)) {
            return intval($dsUserGroup->id);
        }
        return 0;
    }

    // Enabled users that are members of the root group (direct membership)
    public static function getRootUserIds($web)
    {
        $list = array();
        $rootId = self::getSystemGroupId($web, self::GROUP_ROOT);
        if ($rootId == 0) {
            return $list;
        }
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $userIds = array();
        $dsLink = $dsConnection->getDataSource(TableUserXUserGroup::class);
        $dsLink->clear();
        $dsLink->user_group__id = $rootId;
        $dsLink->enabled = 1;
        for ($dsLink->load(); $dsLink->loadIsValid(); $dsLink->loadNext()) {
            $userIds[] = intval($dsLink->user__id);
        }
        if (count($userIds) == 0) {
            return $list;
        }
        $dsUser = $dsConnection->getDataSource(TableUser::class);
        $dsUser->clear();
        $dsUser->id = $userIds;
        $dsUser->enabled = 1;
        for ($dsUser->load(); $dsUser->loadIsValid(); $dsUser->loadNext()) {
            $list[] = intval($dsUser->id);
        }
        return $list;
    }

    // True if the user is the only enabled member of the root group
    public static function isLastRootUser($web, $userId)
    {
        $list = self::getRootUserIds($web);
        return (count($list) == 1) && ($list[0] == intval($userId));
    }

    // Add the user to a group (no checks, used by setup and upgrade)
    public static function addUserToGroup($web, $userId, $groupId)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsLink = $dsConnection->getDataSource(TableUserXUserGroup::class);
        $dsLink->clear();
        $dsLink->user__id = intval($userId);
        $dsLink->user_group__id = intval($groupId);
        if ($dsLink->load(0, 1)) {
            if (intval($dsLink->enabled) == 1) {
                return true;
            }
        } else {
            $dsLink->clear();
            $dsLink->user__id = intval($userId);
            $dsLink->user_group__id = intval($groupId);
        }
        $dsLink->enabled = 1;
        self::reset();
        return $dsLink->save() ? true : false;
    }
}
