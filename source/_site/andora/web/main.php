<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Main;
defined("ANDORA") or die("Forbidden");

require_once(ANDORA_PATH . "_site/xyo/web/web.php");

function run()
{
    define("ANDORA_RUN", 1);
    \XYO\Web\Main\run();
}

function service($route)
{
    define("ANDORA_SERVICE", 1);
    \XYO\Web\Main\service($route);
}

function serviceRun($filename)
{
    define("ANDORA_SERVICE_RUN", $filename);
    \XYO\Web\Main\serviceRun($filename);
}
