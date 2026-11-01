<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Database\Export;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manager;
use \Andora\Web\Package\Installer;
use \Andora\Web\Package\DatabaseBackup;

// admin/database/export?id=all | <vendor>_<name> - a backup of every table of
// the database, or of the tables of a package, as a zip:
// database.<all | vendor.name>.<YYYYMMDD-HHMMSS>.zip.
// 404 unknown package / no tables, 409 busy.
class Api extends \XYO\Web\Module
{
    public function render($options = null)
    {
        $id = $this->request->get("id", "");
        $scope = ($id === DatabaseBackup::ALL) ? DatabaseBackup::ALL : Manager::codeFromId($id);
        $reason = "";
        if (is_null($scope) || is_null(DatabaseBackup::scopeTables($this->web, $scope, $reason))) {
            http_response_code(404);
            return;
        }

        if (!Installer::lock($reason)) {
            http_response_code(409);
            return;
        }
        $file = DatabaseBackup::repositoryPath() . "export-" . bin2hex(random_bytes(8)) . ".zip";
        $isOk = is_dir(DatabaseBackup::repositoryPath()) || @mkdir(DatabaseBackup::repositoryPath(), 0777, true);
        $isOk = $isOk && DatabaseBackup::export($this->web, $scope, $file, $reason);
        Installer::unlock();
        if (!$isOk) {
            @unlink($file);
            http_response_code(500);
            return;
        }

        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=\"" . DatabaseBackup::fileName($scope) . "\"");
        header("Content-Length: " . filesize($file));
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: no-store");
        readfile($file);
        @unlink($file);
    }
}

return Api::class;
