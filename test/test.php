<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// ---
chdir("../output");
if (!defined("ANDORA")) {
    require_once("../output/_site/andora/web/andora.php");
    defined("ANDORA") or die("Forbidden");
    \XYO\Web\Main\serviceRun(__FILE__);
    exit(0);
}
// ---

defined("XYO_WEB_SERVICE") or die("Forbidden");

class Service extends \XYO\Web\Module
{

    public function process($options = null)
    {

        $table = $this->getDataSource(\Andora\Web\DataSource\TableBilling::class);
        $table->createStorage();

    }


}

return Service::class;
