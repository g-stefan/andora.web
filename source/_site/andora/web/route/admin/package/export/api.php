<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Export;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manager;
use \Andora\Web\Package\Installer;

// admin/package/export?id=<vendor>_<name> - the package folder as a zip,
// <vendor>.<name>.v<version>.zip (_site/<vendor>/<name>/... inside).
// 404 unknown package, 403 file operations disabled (package.upload).
class Api extends \XYO\Web\Module
{
    public function render($options = null)
    {
        $code = Manager::codeFromId($this->request->get("id", ""));
        $info = is_null($code) ? null : Manager::get($this->web, $code);
        if (is_null($info) || !Manager::canOperate($info, "export")) {
            http_response_code(404);
            return;
        }
        if (!Manager::isFileOperationAllowed($this->web)) {
            http_response_code(403);
            return;
        }

        $reason = "";
        if (!Installer::lock($reason)) {
            http_response_code(409);
            return;
        }
        $file = Installer::repositoryPath() . "export-" . bin2hex(random_bytes(8)) . ".zip";
        $isOk = Installer::export($this->web, $code, $file, $reason);
        Installer::unlock();
        if (!$isOk) {
            @unlink($file);
            http_response_code(500);
            return;
        }

        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=\"" . Installer::exportFileName($info) . "\"");
        header("Content-Length: " . filesize($file));
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: no-store");
        readfile($file);
        @unlink($file);
    }
}

return Api::class;
