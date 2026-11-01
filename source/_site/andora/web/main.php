<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Main;
defined("ANDORA") or die("Forbidden");

require_once(ANDORA_PATH . "_site/xyo/web/web.php");

// The routes of andora/web (admin, user, setup) are in its package folder,
// _site/andora/web/route/: mounted here, from its package.json, so they work
// before the configuration exists (the setup). The other packages are
// mounted by config.08.route.php (\Andora\Web\Package\Manager::writeRoutes).
function mountRoutes()
{
    $manifest = json_decode("" . @file_get_contents(ANDORA_PATH . "_site/andora/web/package.json"), true);
    if (!is_array($manifest) || !array_key_exists("route", $manifest) || !is_array($manifest["route"])) {
        return;
    }
    foreach ($manifest["route"] as $prefix => $folder) {
        if (is_string($folder)) {
            \XYO\Web\Router::mount("" . $prefix, "_site/andora/web/" . $folder);
        }
    }
}

function run()
{
    define("ANDORA_RUN", 1);
    mountRoutes();
    \XYO\Web\Main\run();
}

function service($route)
{
    define("ANDORA_SERVICE", 1);
    mountRoutes();
    \XYO\Web\Main\service($route);
}

function serviceRun($filename)
{
    define("ANDORA_SERVICE_RUN", $filename);
    mountRoutes();
    \XYO\Web\Main\serviceRun($filename);
}
