<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// The groups of a user, with the enabled flag and the level of each group
class QueryUserXUserGroup extends \XYO\Web\DataSource\Query
{
    public $id;
    public $user__id;
    public $user_group__id;
    public $enabled;
    public $group_enabled;
    public $group_level;

    public static function descriptor($info)
    {
        $info->base = array("link", TableUserXUserGroup::class, "*");
        $info->outer = array(
            "group" => array(
                TableUserGroup::class,
                array(
                    "group_enabled" => "enabled",
                    "group_level" => "level"
                ),
                array("id", array("link", "user_group__id"))
            )
        );
    }
}
