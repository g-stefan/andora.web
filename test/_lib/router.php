<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Router for the PHP built-in web server, does what source/.htaccess does for Apache:
// static files are served as they are, every other path goes to index.php?__=<path>.
//   php -S 127.0.0.1:8080 -t output test/_lib/router.php

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = $_SERVER["DOCUMENT_ROOT"] . $path;

// Same as the .htaccess files: database, logs, configuration and composer files are not served
$name = basename($path);
if (preg_match("#^/(_repository|_log|_site/vendor)(/|$)#", $path) || (strncmp($name, "config.", 7) == 0) || (strncmp($name, "composer.", 9) == 0)) {
    http_response_code(403);
    return true;
}
if (($path !== "/") && is_file($file) && (substr($file, -4) !== ".php")) {
    return false;
}

$_GET["__"] = ltrim($path, "/");
chdir($_SERVER["DOCUMENT_ROOT"]);
require($_SERVER["DOCUMENT_ROOT"] . "/index.php");
