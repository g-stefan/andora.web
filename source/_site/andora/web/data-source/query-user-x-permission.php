<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// The permissions given directly to users, with the permission name
class QueryUserXPermission extends \XYO\Web\DataSource\Query
{
    public $id;
    public $user__id;
    public $permission__id;
    public $is_granted;
    public $enabled;
    public $permission_name;
    public $permission_enabled;

    public static function descriptor($info)
    {
        $info->base = array("link", TableUserXPermission::class, "*");
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
