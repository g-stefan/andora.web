<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

define("ANDORA", 1);
define("ANDORA_PATH", realpath(__DIR__ . "/../../../") . "/");
define("ANDORA_VERSION_STR", "#{VERSION_VERSION}");
define("ANDORA_VERSION_STR_BUILD", "#{VERSION_BUILD}");
define("ANDORA_VERSION_STR_DATETIME", "#{VERSION_DATETIME}");

require_once(ANDORA_PATH . "_site/andora/web/main.php");
