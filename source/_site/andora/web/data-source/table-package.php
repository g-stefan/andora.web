<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

// The installed packages (\Andora\Web\Package\Manager): the version whose
// install / upgrade ran last, compared with the version of the files
// (_site/<vendor>/<name>/package.json).
class TablePackage extends \XYO\Web\DataSource\Table
{
    public $id;
    public $code;
    public $version;
    public $enabled;
    public $error;
    public $installed_at;
    public $updated_at;

    public static function descriptor($info)
    {
        $info->name = "andora-web-package";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "code" => array("varchar", null, 128), // <vendor>/<name>
            "version" => array("varchar", null, 64),
            "enabled" => array("int", 0, "unsigned"),
            "error" => array("varchar", null, 128), // reason of the last failed upgrade, empty - none
            "installed_at" => array("datetime", null),
            "updated_at" => array("datetime", null)
        );
        $info->indexes = array(
            "code"
        );
    }
}
