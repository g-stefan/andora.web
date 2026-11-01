<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Tiny shared assertion harness (same conventions as the XYO.Web tests).

$GLOBALS["__andora_test"] = ["pass" => 0, "fail" => 0, "skip" => 0];

function t_section($name)
{
    echo "\n== " . $name . " ==\n";
}

function check($label, $condition)
{
    if ($condition) {
        $GLOBALS["__andora_test"]["pass"]++;
        echo "  PASS  " . $label . "\n";
        return true;
    }
    $GLOBALS["__andora_test"]["fail"]++;
    echo "  FAIL  " . $label . "\n";
    return false;
}

function check_eq($label, $expected, $actual)
{
    $ok = ($expected === $actual);
    if (!$ok) {
        $label .= "  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")";
    }
    return check($label, $ok);
}

function check_contains($label, $needle, $haystack)
{
    $ok = (strpos((string) $haystack, $needle) !== false);
    if (!$ok) {
        $label .= "  (missing " . var_export($needle, true) . ")";
    }
    return check($label, $ok);
}

function check_not_contains($label, $needle, $haystack)
{
    $ok = (strpos((string) $haystack, $needle) === false);
    if (!$ok) {
        $label .= "  (found " . var_export($needle, true) . ")";
    }
    return check($label, $ok);
}

// A check that cannot run in this environment. Reported, but does not fail the test file.
function t_skip($label, $reason)
{
    $GLOBALS["__andora_test"]["skip"]++;
    echo "  SKIP  " . $label . "  (" . $reason . ")\n";
}

function t_done()
{
    $pass = $GLOBALS["__andora_test"]["pass"];
    $fail = $GLOBALS["__andora_test"]["fail"];
    $skip = $GLOBALS["__andora_test"]["skip"];
    echo "\nRESULT: " . $pass . " passed, " . $fail . " failed" . ($skip ? ", " . $skip . " skipped" : "") . "\n";
    exit($fail === 0 ? 0 : 1);
}
