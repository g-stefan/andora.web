<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Runs every numbered test file (test/0001.*.php, ...), each in its own process.
//   php test/_run-all.php
// The tests use the built site, run "fabricare make" first.
// Each test starts the PHP built-in web server on a copy of output/ in the
// temporary folder, so output/ itself is never changed.

require_once(__DIR__ . "/_lib/site.php");

$files = glob(__DIR__ . "/[0-9][0-9][0-9][0-9].*.php");
sort($files);

$failed = [];
foreach ($files as $file) {
    echo "\n############################################################\n";
    echo "## " . basename($file) . "\n";
    echo "############################################################\n";
    $sitesBefore = TestSite::temporaryFolders();
    $command = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($file);
    passthru($command, $code);
    if ($code !== 0) {
        $failed[] = basename($file);
    }
    // A site folder the test could not remove itself (a file was still open)
    foreach (array_diff(TestSite::temporaryFolders(), $sitesBefore) as $folder) {
        TestSite::removeDir($folder);
    }
}

echo "\n";
if (count($failed) == 0) {
    echo "==> ALL TESTS PASSED (" . count($files) . " files)\n";
    exit(0);
}
echo "==> TESTS FAILED: " . implode(", ", $failed) . "\n";
exit(1);
