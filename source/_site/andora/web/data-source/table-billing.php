<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\DataSource;

defined("ANDORA") or die("Forbidden");

class TableBilling extends \XYO\Web\DataSource\Table
{
    public $id;
    public $user__id;
    public $type; // 'invoice' or 'receipt'
    public $series;
    public $number;
    public $date;
    public $amount;
    public $currency;
    public $status; // 'paid', 'unpaid', 'pending'
    public $description;
    public $created_at;

    public static function descriptor($info)
    {
        $info->name = "billing";
        $info->primaryKey = "id";
        $info->fields = array(
            "id" => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "user__id" => array("bigint", 0, "unsigned"),
            "type" => array("varchar", "invoice", 32),
            "series" => array("varchar", null, 32),
            "number" => array("varchar", null, 64),
            "date" => array("datetime", null),
            "amount" => array("float", 0),
            "currency" => array("varchar", "USD", 3),
            "status" => array("varchar", "pending", 32),
            "description" => array("varchar", null, 255),
            "created_at" => array("datetime", null)
        );
        $info->indexes = array(
            "user__id",
            "type",
            "status"
        );
    }

}

