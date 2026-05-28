<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableBilling;

class Billing
{
    public static function addBilling($web, $user_id, $type, $series, $number, $amount, $currency, $status, $description, &$reason)
    {
        $dsConnection = $web->get(\XYO\Web\DataSource\Connection::class);
        $dsBilling = $dsConnection->getDataSource(TableBilling::class);
        $dsBilling->clear();
        $dsBilling->user__id = $user_id;
        $dsBilling->type = $type;
        $dsBilling->series = $series;
        $dsBilling->number = $number;
        $dsBilling->amount = $amount;
        $dsBilling->currency = $currency;
        $dsBilling->status = $status;
        $dsBilling->description = $description;
        $dsBilling->date = "NOW";
        $dsBilling->created_at = "NOW";

        if ($dsBilling->save()) {
            return true;
        }

        $reason = "database-error";
        return false;
    }
}
