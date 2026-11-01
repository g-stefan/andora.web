<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Database;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Package\Manager;
use \Andora\Web\Package\DatabaseBackup;
use \Andora\Web\Component\DataTable;
use \Andora\Web\Component\DataTableAction;
use \Andora\Web\Component\Dialog;
use \Andora\Web\Component\Placeholder;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\MessageError;

// Backups of the database: the packages with tables (package.json
// "datasource"), the export of every table (Export all) or of the tables of a
// package (row menu: admin/database/export), the import of a backup (the add
// button): form-import (the zip is checked and kept in _repository/database/import/)
// -> form-restore (what is restored, all or one package of the backup).
// A row is identified by <vendor>_<name>.
class Page extends \XYO\Web\Page
{
    protected $comDialog = null;
    protected $comDataTable = null;
    protected $isImportAllowed = false;
    // the tables of the database (DatabaseBackup::tables), null on error
    protected $tables = null;

    public function init($options = null)
    {
        $this->setTitle("Admin - Database");
        Dashboard::setApplicationTitle("Database");
        $this->isImportAllowed = DatabaseBackup::isImportAllowed($this->web);

        $this->comDialog = Dialog::register($this, "dialog", array(
            "ajax-component" => true,
            "component" => "placeholder",
            "placeholder" => "placeholder",
            "class" => "flex items-start justify-center"
        ));
        $this->comDialog->setAJAXComponent("placeholder", function ($dialog, $component) {
            Placeholder::register($dialog, $component);
        });
        $this->comDialog->setAJAXComponent("form-import", function ($dialog, $component) {
            $form = FormImport::register($dialog, $component);
            $form->setOnSuccess([$this, "onImportProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-restore", function ($dialog, $component) {
            $form = FormRestore::register($dialog, $component);
            $form->setOnInit([$this, "onRestoreInit"]);
            $form->setOnSuccess([$this, "onRestoreProcess"]);
        });
        $this->comDialog->setAJAXComponent("message-ok", function ($dialog, $component) {
            MessageOk::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("restore-ok")
            ));
        });
        $this->comDialog->setAJAXComponent("message-error", function ($dialog, $component) {
            $reason = $this->request->get("reason", "");
            if (!is_string($reason)) {
                $reason = "";
            }
            $detail = $this->request->get("detail", "");
            if (!is_string($detail)) {
                $detail = "";
            }
            MessageError::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $this->language->get($reason) . ((strlen($detail) > 0) ? ": " . $detail : "")
            ));
        });

        $this->comDataTable = DataTable::register($this, "datatable", array(
            "descriptor" => array(
                "id" => array(
                    "type" => "selector",
                    "name" => "",
                    "primaryKey" => true
                ),
                "code" => array(
                    "type" => "text",
                    "name" => "Package",
                    "sort" => "ascendent",
                    "search" => true
                ),
                "title" => array(
                    "type" => "text",
                    "name" => "Title",
                    "search" => true
                ),
                "tables" => array(
                    "type" => "text",
                    "name" => "Tables",
                    "search" => true
                ),
                "rows" => array(
                    "type" => "text",
                    "name" => "Rows"
                ),
                "#" => array(
                    "type" => "component",
                    "select" => false,
                    "name" => "",
                    "class" => "w-4",
                    "component" => array(
                        DataTableAction::class,
                        array(
                            array("link", "file-down", "Export", $this->site . "admin/database/export")
                        )
                    )
                )
            ),
            "queryFn" => function ($query) {
                return $this->queryPackages($query);
            },
            // the add button imports a backup
            "addFn" => $this->isImportAllowed ? function () {
                $this->comDialog->renderJSRequestGet(array("component" => "form-import"));
                $this->comDialog->renderJSOpen();
            } : null
        ));
    }

    protected function loadTables()
    {
        if (is_null($this->tables)) {
            $reason = "";
            $this->tables = DatabaseBackup::tables($this->web, $reason);
        }
        return $this->tables;
    }

    public function render($options = null)
    {
        $tables = $this->loadTables();
        $unowned = array();
        $excluded = array();
        foreach (is_null($tables) ? array() : $tables as $table) {
            if ($table->excluded) {
                $excluded[] = $table->name;
            } elseif (is_null($table->package)) {
                $unowned[] = $table->name . " (" . $table->rows . ")";
            }
        }
        ?>
        <div class="flex flex-wrap items-center gap-2 mb-4" id="database-toolbar">
            <p class="text-base-content/70"><?php $this->language->render("page.description"); ?></p>
            <div class="ml-auto flex gap-2">
                <?php if (!is_null($tables)) { ?>
                    <a class="btn btn-sm btn-primary" id="database-export-all" href="<?php echo $this->site; ?>admin/database/export?id=<?php echo DatabaseBackup::ALL; ?>"><?php $this->language->render("export-all"); ?></a>
                <?php } ?>
            </div>
        </div>
        <?php
        if (is_null($tables)) {
            ?>
            <p class="text-error mb-4"><?php $this->language->render("database-error"); ?></p>
            <?php
        }
        $this->renderComponent("datatable");
        ?>
        <div class="flex flex-col gap-1 text-sm mt-4 text-base-content/70" id="database-other">
            <?php if (count($unowned) > 0) { ?>
                <p><?php $this->language->render("unowned"); ?>: <?php echo htmlspecialchars(implode(", ", $unowned), ENT_QUOTES); ?></p>
            <?php } ?>
            <?php if (count($excluded) > 0) { ?>
                <p><?php $this->language->render("excluded"); ?>: <?php echo htmlspecialchars(implode(", ", $excluded), ENT_QUOTES); ?></p>
            <?php } ?>
            <p><?php $this->language->render("server-backups"); ?>: <?php echo count(DatabaseBackup::backups()); ?></p>
        </div>
        <?php
        $this->renderComponent("dialog");
    }

    // The rows of the data table: the packages with tables, search, sort and paging
    public function queryPackages($query)
    {
        $packages = array();
        foreach ($this->loadTables() ?? array() as $table) {
            if ($table->excluded || is_null($table->package)) {
                continue;
            }
            $packages[$table->package][] = $table;
        }
        $rows = array();
        $search = strtolower(trim($query->search->text));
        foreach ($packages as $code => $tables) {
            $info = Manager::get($this->web, $code);
            $names = array();
            $count = 0;
            foreach ($tables as $table) {
                $names[] = $table->name;
                $count += $table->rows;
            }
            $row = array(
                "id" => Manager::idFromCode($code),
                "code" => $code,
                "title" => is_null($info) ? $code : $info->title,
                "tables" => implode(", ", $names),
                "rows" => "" . $count
            );
            if (strlen($search) > 0) {
                $found = false;
                foreach ($query->search->select as $key) {
                    if (strpos(strtolower("" . $row[$key]), $search) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        foreach ($query->sort as $key => $mode) {
            if ((($mode !== "ascendent") && ($mode !== "descendent")) || !in_array($key, array("code", "title", "tables"), true)) {
                continue;
            }
            usort($rows, function ($a, $b) use ($key, $mode) {
                $compare = strcmp("" . $a[$key], "" . $b[$key]);
                return ($mode === "ascendent") ? $compare : -$compare;
            });
        }

        $count = count($rows);
        if (!$query->loadAll) {
            $start = $query->loadIndex;
            if ($query->loadLast) {
                $start = max(0, intval(ceil($count / $query->loadCount)) - 1) * $query->loadCount;
            }
            $rows = array_slice($rows, $start, $query->loadCount);
        }

        $result = array();
        foreach ($rows as $row) {
            $line = array();
            foreach ($query->select as $key) {
                $line[] = $row[$key];
            }
            $result[] = $line;
        }

        return array(
            "rows" => $result,
            "count" => $count
        );
    }

    // The uploaded zip: checked, kept for the confirmation (form-restore)
    public function onImportProcess($form)
    {
        if (!$this->isImportAllowed) {
            $this->messageError($form, "import-disabled", "");
            return;
        }
        $file = $_FILES["backup"] ?? null;
        $reason = "upload-error";
        if (is_array($file) && in_array($file["error"] ?? -1, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            $reason = "upload-too-large";
        }
        if (!is_array($file) || (($file["error"] ?? -1) !== UPLOAD_ERR_OK) || !is_string($file["tmp_name"] ?? null) || !is_uploaded_file($file["tmp_name"])) {
            $this->messageError($form, $reason, "");
            return;
        }
        $id = DatabaseBackup::stageImport($file["tmp_name"], $reason);
        if (is_null($id)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($id) {
            $this->comDialog->renderJSRequestPost(array("component" => "form-restore", "init" => "true", "import__id" => $id));
        });
    }

    public function onRestoreInit($form)
    {
        $id = $form->getSelector($form->getElementValueString("import__id", ""));
        $file = DatabaseBackup::importFile($id);
        if (is_null($file)) {
            return;
        }
        $reason = "";
        $form->backup = DatabaseBackup::open($file, $reason);
        if (is_null($form->backup)) {
            $form->reason = $reason;
            DatabaseBackup::removeImport($id);
            return;
        }
        $form->backup->zip->close();
        $form->backup->id = $id;
        $form->plan = DatabaseBackup::plan($this->web, $form->backup, DatabaseBackup::ALL, $reason);
        if (is_null($form->plan)) {
            $form->reason = $reason;
            $form->detail = Manager::$detail;
        }
        foreach (DatabaseBackup::backupPackages($form->backup) as $code) {
            $info = Manager::get($this->web, $code);
            $form->installed[$code] = (is_null($info) || !$info->isInstalled) ? "" : $info->installedVersion;
        }
        $form->setSelector($id);
    }

    public function onRestoreProcess($form)
    {
        if (!$this->isImportAllowed) {
            $this->messageError($form, "import-disabled", "");
            return;
        }
        $id = $form->getSelector("");
        $file = DatabaseBackup::importFile($id);
        if (is_null($file)) {
            $this->messageError($form, "import-not-found", "");
            return;
        }
        if ($form->getElementValueString("confirm", "") !== "1") {
            $this->messageError($form, "confirm-required", "");
            return;
        }
        $package = $form->getElementValueString("package", DatabaseBackup::ALL);
        if ($package !== DatabaseBackup::ALL) {
            $package = Manager::codeFromId($package) ?? "";
        }
        $reason = "";
        if (!DatabaseBackup::restore($this->web, $file, $package, $reason)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        DatabaseBackup::removeImport($id);
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () {
            $this->comDialog->renderJSRequestGet(array("component" => "message-ok"));
            $this->comDataTable->renderJSRequestPost();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSClose();
            // the users and their sessions may have changed
            echo "setTimeout(function(){window.location.reload();},250);";
            echo "},1500);";
        });
    }

    public function messageError($form, $reason, $detail)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($reason, $detail) {
            $this->comDialog->renderJSRequestGet(array("component" => "message-error", "reason" => $reason, "detail" => $detail));
            $this->comDataTable->renderJSRequestPost();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSClose();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSRequestGet(array("component" => "placeholder"));
            echo "},250);";
            echo "},3000);";
        });
    }
}

return Page::class;
