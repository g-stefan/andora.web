<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// The permissions of user groups, with the permission name
class QueryUserGroupXPermission extends \XYO\Web\DataSource\Query
{
    public $id;
    public $user_group__id;
    public $permission__id;
    public $is_granted;
    public $enabled;
    public $permission_name;
    public $permission_enabled;

    public static function descriptor($info)
    {
        $info->base = array("link", TableUserGroupXPermission::class, "*");
        $info->outer = array(
            "permission" => array(
                TablePermission::class,
                array(
                    "permission_name" => "name",
                    "permission_enabled" => "enabled"
                ),
                array("id", array("link", "permission__id"))
            )
        );
    }
}
