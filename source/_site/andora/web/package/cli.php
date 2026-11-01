<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Packages from the command line (deployments, CI), from any folder:
//   php _site/andora/web/package/cli.php <command> [arguments]
// Run "help" for the commands. Command line only.

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit(1);
}

require_once(__DIR__ . "/../andora.php");
chdir(ANDORA_PATH);

if (!is_file(ANDORA_PATH . "config.04.configured.php")) {
    fwrite(STDERR, "Error: the site is not configured, run the setup first\n");
    exit(1);
}

\Andora\Web\Main\serviceRun(__DIR__ . "/service.php");
