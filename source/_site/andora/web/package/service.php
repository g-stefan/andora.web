<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Setup;

// The commands of cli.php. Exit code 0 on success, 1 on error (the reason
// is written to stderr). The command line is trusted: package.upload does
// not apply.
class Service extends \XYO\Web\Module
{
    public function render($options = null)
    {
        Manager::$cli = true;
        $argv = array_slice($GLOBALS["argv"] ?? array(), 1);
        // the arguments without the options (--trust, --keep-data, --vendor=...)
        $positional = array_values(array_filter($argv, function ($argument) {
            return strncmp("" . $argument, "--", 2) != 0;
        }));
        $command = count($positional) > 0 ? "" . $positional[0] : "help";
        $code = count($positional) > 1 ? "" . $positional[1] : "";
        $reason = "";

        if (($command === "help") || ($command === "--help")) {
            $this->help();
            exit(0);
        }
        if ($command === "list") {
            foreach (Manager::discover($this->web) as $info) {
                echo str_pad($info->code, 32) . " " . str_pad($info->version, 10) . " " . str_pad($info->installedVersion, 10) . " " . $info->state . "\n";
            }
            exit(0);
        }
        if ($command === "deploy") {
            $this->deploy($code, in_array("--trust", $argv, true));
        }
        if ($command === "trust") {
            $this->trust(array_slice($positional, 1), $argv);
        }
        if ($command === "registry") {
            $this->registry(array_slice($positional, 1));
        }
        if ($command === "event") {
            $this->event(array_slice($positional, 1));
        }
        if ($command === "database") {
            $this->database(array_slice($positional, 1), $argv);
        }

        if (!in_array($command, array("install", "upgrade", "migrate", "reset", "enable", "disable", "uninstall", "restore", "export", "verify"), true)) {
            $this->help();
            exit(1);
        }
        $keepData = in_array("--keep-data", $argv, true);
        if (!Manifest::isCode($code)) {
            $this->fail("invalid-package-code", $code);
        }
        $isOk = false;
        $operation = $command;
        switch ($command) {
            case "install":
                $isOk = Manager::install($this->web, $code, $reason);
                break;
            case "upgrade":
                $isOk = Manager::upgrade($this->web, $code, $reason);
                break;
            case "migrate":
                $isOk = Manager::migrate($this->web, $code, $reason, $operation);
                break;
            case "reset":
                $isOk = Manager::reset($this->web, $code, $reason);
                break;
            case "enable":
                $isOk = Manager::enable($this->web, $code, $reason);
                break;
            case "disable":
                $isOk = Manager::disable($this->web, $code, $reason);
                break;
            case "uninstall":
                $isOk = Manager::uninstall($this->web, $code, $keepData, $reason);
                break;
            case "restore":
                $isOk = Installer::restore($this->web, $code, $reason);
                if ($isOk) {
                    $this->migrateInNewProcess($code);
                }
                break;
            case "verify":
                $info = Manager::get($this->web, $code);
                if (is_null($info) || !$info->hasFiles || is_null($info->manifest)) {
                    $this->fail("invalid-state", $code);
                }
                $result = Signature::verify($this->web, Manager::sitePath() . $info->vendor . "/" . $info->name, $info->vendor, $info->name);
                echo $code . ": " . $result->state . ((strlen($result->keyName) > 0) ? " (" . $result->keyName . ")" : "") . "\n";
                exit($result->state === Signature::TRUSTED ? 0 : 1);
            case "export":
                $file = count($positional) > 2 ? "" . $positional[2] : "";
                $info = Manager::get($this->web, $code);
                if ((strlen($file) == 0) && !is_null($info)) {
                    $file = Installer::exportFileName($info);
                }
                $isOk = Installer::export($this->web, $code, $file, $reason);
                if ($isOk) {
                    echo $file . "\n";
                }
                break;
            default:
                $this->help();
                exit(1);
        }
        if (!$isOk) {
            $this->fail($reason, Manager::$detail);
        }
        echo $code . ": " . $operation . " ok\n";
        exit(0);
    }

    // Replace the package folder with the zip, then run the migrations in a
    // new process (the new files must not run in the process that swapped them)
    protected function deploy($zipFile, $trusted)
    {
        $reason = "";
        $stage = Installer::stage($this->web, $zipFile, $reason);
        if (is_null($stage)) {
            $this->fail($reason, Manager::$detail);
        }
        $action = Installer::stageAction($this->web, $stage);
        echo $stage->code . " " . $stage->version . ": signature " . $stage->signature->state . ((strlen($stage->signature->keyName) > 0) ? " (" . $stage->signature->keyName . ")" : "") . "\n";
        if (!Installer::commit($this->web, $stage->id, $reason, $trusted)) {
            if ($reason === "confirm-unsigned") {
                Manager::$detail = "not signed by a trusted key, run deploy again with --trust if you trust it";
            }
            Installer::removeStage($stage->id);
            $this->fail($reason, Manager::$detail);
        }
        echo $stage->code . " " . $stage->version . ": files ok (" . $action . ")\n";
        $this->migrateInNewProcess($stage->code);
    }

    // trust list | add <name> <public.pem> [--vendor=a,b] | remove <name>,
    // kept in config.09.package-trust.php
    protected function trust($argv, $options)
    {
        $file = ANDORA_PATH . Signature::TRUST_CONFIG;
        $list = array();
        if (is_file($file)) {
            $value = include($file);
            if (is_array($value) && is_array($value["packageTrust"] ?? null)) {
                $list = $value["packageTrust"];
            }
        }
        $action = $argv[0] ?? "list";
        $name = $argv[1] ?? "";
        if ($action === "list") {
            foreach (Signature::trustedKeys($this->web) as $keyName => $key) {
                echo str_pad($keyName, 16) . " " . $key["fingerprint"] . " " . ((count($key["vendor"]) > 0) ? implode(",", $key["vendor"]) : "*") . "\n";
            }
            exit(0);
        }
        if (!Manifest::isName($name)) {
            $this->fail("invalid-key-name", $name);
        }
        $reason = "";
        if ($action === "add") {
            $publicPem = "" . @file_get_contents($argv[2] ?? "");
            if (is_null(Signature::fingerprint($publicPem))) {
                $this->fail("invalid-key", $argv[2] ?? "");
            }
            $vendor = array();
            foreach ($options as $argument) {
                if (strncmp($argument, "--vendor=", 9) == 0) {
                    foreach (explode(",", substr($argument, 9)) as $item) {
                        if (!Manifest::isName($item)) {
                            $this->fail("invalid-vendor", $item);
                        }
                        $vendor[] = $item;
                    }
                }
            }
            $list[$name] = array("key" => $publicPem, "vendor" => $vendor);
        } elseif ($action === "remove") {
            if (!array_key_exists($name, $list)) {
                $this->fail("key-not-found", $name);
            }
            unset($list[$name]);
        } else {
            $this->help();
            exit(1);
        }
        if (!Setup::writeConfigFile($file, array("packageTrust" => $list), $reason)) {
            $this->fail($reason, $file);
        }
        echo $name . ": " . $action . " ok\n";
        exit(0);
    }

    // registry list | add <name> <url> | remove <name>,
    // kept in config.10.package-registry.php
    protected function registry($argv)
    {
        $action = $argv[0] ?? "list";
        $reason = "";
        if ($action === "list") {
            foreach (Registry::all($this->web) as $name => $url) {
                echo str_pad($name, 16) . " " . $url . "
";
            }
            exit(0);
        }
        $name = $argv[1] ?? "";
        if ($action === "add") {
            if (!Registry::add($this->web, $name, $argv[2] ?? "", $reason)) {
                $this->fail($reason, Manager::$detail);
            }
        } elseif ($action === "remove") {
            if (!Registry::remove($this->web, $name, $reason)) {
                $this->fail($reason, Manager::$detail);
            }
        } else {
            $this->help();
            exit(1);
        }
        echo $name . ": " . $action . " ok
";
        exit(0);
    }

    // event list | rebuild: the events of config.11.event.php, the package
    // that provides each one and its listeners (<vendor>/<name> method)
    protected function event($argv)
    {
        $action = $argv[0] ?? "list";
        $reason = "";
        if ($action === "rebuild") {
            if (!EventRegistry::write($this->web, $reason)) {
                $this->fail($reason, "");
            }
        } elseif ($action !== "list") {
            $this->help();
            exit(1);
        }
        foreach (EventRegistry::load($this->web)["provide"] as $name => $code) {
            echo str_pad($name, 32) . " " . $code . "\n";
            foreach (EventRegistry::listeners($this->web, $name) as $listener) {
                echo "    " . str_pad($listener["package"], 28) . " " . $listener["method"] . "\n";
            }
        }
        exit(0);
    }

    // database tables | export [all|<code>] [file.zip] | import <file.zip>
    // [--package=<code>] | backups: the backups of the tables (DatabaseBackup)
    protected function database($argv, $options)
    {
        $action = $argv[0] ?? "tables";
        $reason = "";
        if ($action === "tables") {
            $tables = DatabaseBackup::tables($this->web, $reason);
            if (is_null($tables)) {
                $this->fail($reason, "");
            }
            foreach ($tables as $table) {
                echo str_pad($table->name, 40) . " " . str_pad(is_null($table->package) ? "-" : $table->package, 28) . " " . str_pad("" . $table->rows, 10) . ($table->excluded ? " (not in backups)" : "") . "\n";
            }
            exit(0);
        }
        if ($action === "backups") {
            foreach (DatabaseBackup::backups() as $backup) {
                echo str_pad($backup->name, 64) . " " . str_pad("" . $backup->size, 12) . " " . date("Y-m-d H:i:s", $backup->time) . "\n";
            }
            exit(0);
        }
        if ($action === "export") {
            $scope = $argv[1] ?? DatabaseBackup::ALL;
            $file = $argv[2] ?? "";
            if (!DatabaseBackup::isScope($scope)) {
                $this->fail("invalid-scope", $scope);
            }
            if (strlen($file) == 0) {
                if (!is_dir(DatabaseBackup::backupPath()) && !@mkdir(DatabaseBackup::backupPath(), 0777, true)) {
                    $this->fail("unable-to-write-files", DatabaseBackup::backupPath());
                }
                $file = DatabaseBackup::freeFile(DatabaseBackup::backupPath(), $scope);
            }
            if (!DatabaseBackup::export($this->web, $scope, $file, $reason)) {
                $this->fail($reason, Manager::$detail);
            }
            echo $file . "\n";
            exit(0);
        }
        if ($action === "import") {
            $file = $argv[1] ?? "";
            $package = DatabaseBackup::ALL;
            foreach ($options as $argument) {
                if (strncmp($argument, "--package=", 10) == 0) {
                    $package = substr($argument, 10);
                }
            }
            $restored = 0;
            if (!DatabaseBackup::restore($this->web, $file, $package, $reason, $restored)) {
                $this->fail($reason, Manager::$detail);
            }
            echo $file . ": " . $restored . " tables restored\n";
            exit(0);
        }
        $this->help();
        exit(1);
    }

    protected function migrateInNewProcess($code)
    {
        $command = escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/cli.php") . " migrate " . escapeshellarg($code);
        passthru($command, $exitCode);
        exit($exitCode == 0 ? 0 : 1);
    }

    protected function fail($reason, $detail)
    {
        fwrite(STDERR, "Error: " . $reason . ((strlen("" . $detail) > 0) ? ": " . $detail : "") . "\n");
        exit(1);
    }

    protected function help()
    {
        echo "php _site/andora/web/package/cli.php <command>\n";
        echo "  list                         packages: code, version of the files, installed version, state\n";
        echo "  deploy <file.zip> [--trust]  replace the package folder with the zip, then migrate\n";
        echo "                               (--trust: accept a package not signed by a trusted key)\n";
        echo "  migrate <code>               install a new package / upgrade newer files\n";
        echo "  install <code>               install a package found in _site/\n";
        echo "  upgrade <code>               run the migrations of newer files\n";
        echo "  reset <code>                 back to the state after install (the data is lost)\n";
        echo "  enable <code>, disable <code>\n";
        echo "  uninstall <code> [--keep-data]\n";
        echo "  restore <code>               put the newest backup of the folder back, then migrate\n";
        echo "  export <code> [file.zip]     zip of the package folder (<vendor>.<name>.v<version>.zip)\n";
        echo "  verify <code>                the signature of the package folder\n";
        echo "  trust list                   the trusted keys: name, fingerprint, vendors\n";
        echo "  trust add <name> <public.pem> [--vendor=a,b]   trust a key (for these vendors only)\n";
        echo "  trust remove <name>\n";
        echo "  registry list                the package registries: name, url of the index\n";
        echo "  registry add <name> <url>    add a registry (https)\n";
        echo "  registry remove <name>\n";
        echo "  event list                   the events: the package that provides each one, its listeners\n";
        echo "  event rebuild                write config.11.event.php again from the packages\n";
        echo "  database tables              the tables of the database: name, package, rows\n";
        echo "  database export [all|<code>] [file.zip]   backup of every table, or of the tables of a\n";
        echo "                               package (default: _repository/database/backup/)\n";
        echo "  database import <file.zip> [--package=<code>]   replace the rows of the tables of the\n";
        echo "                               backup (only those of the package), a copy saved first\n";
        echo "  database backups             the backups in _repository/database/backup/\n";
    }
}

return Service::class;
