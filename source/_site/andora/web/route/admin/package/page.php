<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Package\Manager;
use \Andora\Web\Package\Installer;
use \Andora\Web\Package\Signature;
use \Andora\Web\Component\DataTable;
use \Andora\Web\Component\DataTableAction;
use \Andora\Web\Component\DataTableToggle;
use \Andora\Web\Component\Dialog;
use \Andora\Web\Component\Placeholder;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\MessageError;

// The packages found in _site/<vendor>/<name>/ and their state. A row is
// identified by <vendor>_<name> (the request ids can not hold "/" or ".").
//
// Upload: form-upload (the zip is extracted and checked: a stage) ->
// form-confirm (what changes; commit replaces the folder) -> form-migrate,
// posted by the answer of the commit, so install / upgrade run in a new
// request with the new files. Restore ends with form-migrate too.
class Page extends \XYO\Web\Page
{
    public const OPERATIONS = array("details", "install", "upgrade", "reset", "uninstall", "restore");
    public const MESSAGES = array("install-ok", "upgrade-ok", "reset-ok", "uninstall-ok", "replace-ok");

    protected $comDialog = null;
    protected $comDataTable = null;
    protected $isFileOperationAllowed = false;

    public function init($options = null)
    {
        $this->setTitle("Admin - Package");
        Dashboard::setApplicationTitle("Package");
        $this->isFileOperationAllowed = Manager::isFileOperationAllowed($this->web);

        $this->registerDialog();
        foreach (self::OPERATIONS as $operation) {
            $this->comDialog->setAJAXComponent("form-" . $operation, function ($dialog, $component) use ($operation) {
                $form = FormOperation::register($dialog, $component, array("operation" => $operation));
                $form->setOnInit([$this, "onOperationInit"]);
                $form->setOnSuccess([$this, "onOperationProcess"]);
            });
        }
        $this->comDialog->setAJAXComponent("form-upload", function ($dialog, $component) {
            $form = FormUpload::register($dialog, $component);
            $form->setOnSuccess([$this, "onUploadProcess"]);
        });

        $actions = array(
            array("js", "info", "Details", "packageDetails"),
            array("separator"),
            array("js", "package-plus", "Install", "packageInstall"),
            array("js", "circle-arrow-up", "Upgrade", "packageUpgrade")
        );
        if ($this->isFileOperationAllowed) {
            $actions[] = array("link", "file-down", "Export", $this->site . "admin/package/export");
            $actions[] = array("js", "archive-restore", "Restore", "packageRestore");
        }
        $actions[] = array("separator");
        $actions[] = array("js", "rotate-ccw", "Reset", "packageReset", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error");
        if ($this->isFileOperationAllowed) {
            $actions[] = array("js", "package-minus", "Uninstall", "packageUninstall", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error");
        }
        $this->registerDataTable($actions);
    }

    // The dialog and the screens shared with the catalog (admin/package/catalog):
    // placeholder, form-confirm (a stage), form-migrate, message-ok, message-error
    protected function registerDialog()
    {
        $this->comDialog = Dialog::register($this, "dialog", array(
            "ajax-component" => true,
            "component" => "placeholder",
            "placeholder" => "placeholder",
            "class" => "flex items-start justify-center"
        ));
        $this->comDialog->setAJAXComponent("placeholder", function ($dialog, $component) {
            Placeholder::register($dialog, $component);
        });
        $this->comDialog->setAJAXComponent("form-confirm", function ($dialog, $component) {
            $form = FormConfirm::register($dialog, $component);
            $form->setOnInit([$this, "onConfirmInit"]);
            $form->setOnSuccess([$this, "onConfirmProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-migrate", function ($dialog, $component) {
            $form = FormMigrate::register($dialog, $component);
            $form->setOnSuccess([$this, "onMigrateProcess"]);
        });
        $this->comDialog->setAJAXComponent("message-ok", function ($dialog, $component) {
            $message = $this->request->get("message", "");
            if (!in_array($message, static::MESSAGES, true)) {
                $message = "install-ok";
            }
            MessageOk::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get($message)
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
            // the code too: the reasons of a package (<vendor>-<name>.*) have no text here
            $text = $this->language->get($reason);
            MessageError::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $text . ((strlen($detail) > 0) ? ": " . $detail : ""),
                "code" => ($text !== $reason) ? $reason : "",
                "close" => $dialog,
                "closeLabel" => $this->language->get("close"),
                "copyLabel" => $this->language->get("copy"),
                "copiedLabel" => $this->language->get("copied")
            ));
        });
    }

    protected function registerDataTable($actions)
    {
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
                "version" => array(
                    "type" => "text",
                    "name" => "Version"
                ),
                "installedVersion" => array(
                    "type" => "text",
                    "name" => "Installed"
                ),
                "state" => array(
                    "type" => "text",
                    "name" => "State",
                    "search" => true
                ),
                "enabled" => array(
                    "type" => "component",
                    "name" => "Enabled",
                    "component" => array(
                        DataTableToggle::class,
                        array(
                            "onToggle" => [$this, "onToggleEnabled"]
                        )
                    )
                ),
                "#" => array(
                    "type" => "component",
                    "select" => false,
                    "name" => "",
                    "class" => "w-4",
                    "component" => array(
                        DataTableAction::class,
                        $actions
                    )
                )
            ),
            "queryFn" => function ($query) {
                return $this->queryPackages($query);
            },
            // the add button uploads a package
            "addFn" => $this->isFileOperationAllowed ? function () {
                $this->comDialog->renderJSRequestGet(array("component" => "form-upload"));
                $this->comDialog->renderJSOpen();
            } : null
        ));
    }

    // Installed | Catalog
    protected function renderTabs($active)
    {
        ?>
        <div role="tablist" class="tabs tabs-border mb-4" id="package-tabs">
            <a role="tab" class="tab<?php echo ($active === "installed") ? " tab-active" : ""; ?>" href="<?php echo $this->site; ?>admin/package"><?php $this->language->render("tab.installed"); ?></a>
            <a role="tab" class="tab<?php echo ($active === "catalog") ? " tab-active" : ""; ?>" href="<?php echo $this->site; ?>admin/package/catalog"><?php $this->language->render("tab.catalog"); ?></a>
        </div>
        <?php
    }

    public function render($options = null)
    {
        $this->renderTabs("installed");
        $this->renderComponent("datatable");
        $this->renderComponent("dialog");
        $this->view->renderJS(function () {
            ?>
            <script>
                <?php foreach (self::OPERATIONS as $operation) { ?>
                    function package<?php echo ucfirst($operation); ?>(record) {
                        <?php $this->comDialog->renderJSRequestPost(array("component" => "form-" . $operation, "init" => "true"), array("package__id" => "record.id")); ?>
                        <?php $this->comDialog->renderJSOpen(); ?>
                    };
                <?php } ?>
            </script>
            <?php
        });
    }

    // The rows of the data table: search, sort and paging over the packages found
    public function queryPackages($query)
    {
        $rows = array();
        $search = strtolower(trim($query->search->text));
        foreach (Manager::discover($this->web) as $info) {
            $row = array(
                "id" => Manager::idFromCode($info->code),
                "code" => $info->code,
                "title" => $info->title,
                "version" => $info->version,
                "installedVersion" => $info->installedVersion,
                "state" => $this->language->get("state." . $info->state),
                "enabled" => ($info->isInstalled && $info->enabled) ? 1 : 0
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
            if (($mode !== "ascendent") && ($mode !== "descendent")) {
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

    public function onOperationInit($form)
    {
        $id = $form->getSelector($form->getElementValueString("package__id", ""));
        $code = Manager::codeFromId($id);
        if (is_null($code)) {
            return;
        }
        $form->package = Manager::get($this->web, $code);
        if (is_null($form->package)) {
            return;
        }
        $form->isAllowed = Manager::canOperate($form->package, $form->operation);
        if (!is_null($form->package->manifest)) {
            $form->requirements = Manager::requirements($this->web, $form->package->manifest);
            if ($form->isRequirementMissing()) {
                $form->isAllowed = false;
            }
        }
        if (($form->operation === "details") && $form->package->hasFiles && !is_null($form->package->manifest)) {
            $form->signature = Signature::verify($this->web, Manager::sitePath() . $form->package->vendor . "/" . $form->package->name, $form->package->vendor, $form->package->name);
        }
        if (in_array($form->operation, array("uninstall", "restore"), true) && !$this->isFileOperationAllowed) {
            $form->isAllowed = false;
        }
        $form->setSelector(Manager::idFromCode($code));
    }

    public function onOperationProcess($form)
    {
        $code = Manager::codeFromId($form->getSelector(""));
        if (is_null($code) || is_null($form->package) || ($form->operation === "details")) {
            $this->messageError($form, "invalid-state", "");
            return;
        }
        $reason = "";
        $isOk = false;
        switch ($form->operation) {
            case "install":
                $isOk = Manager::install($this->web, $code, $reason);
                break;
            case "upgrade":
                $isOk = Manager::upgrade($this->web, $code, $reason);
                break;
            case "reset":
                $isOk = Manager::reset($this->web, $code, $reason);
                break;
            case "uninstall":
                $isOk = Manager::uninstall($this->web, $code, $form->getElementValueString("keepData", "") === "1", $reason);
                break;
            case "restore":
                if (!Installer::restore($this->web, $code, $reason)) {
                    $this->messageError($form, $reason, Manager::$detail);
                    return;
                }
                $this->migrate($form, $code);
                return;
        }
        if (!$isOk) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $this->messageOk($form, $form->operation . "-ok");
    }

    public function onUploadProcess($form)
    {
        $file = $_FILES["package"] ?? null;
        $reason = "upload-error";
        if (is_array($file) && in_array($file["error"] ?? -1, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            $reason = "upload-too-large";
        }
        if (!is_array($file) || (($file["error"] ?? -1) !== UPLOAD_ERR_OK) || !is_string($file["tmp_name"] ?? null) || !is_uploaded_file($file["tmp_name"])) {
            $this->messageError($form, $reason, "");
            return;
        }
        $stage = Installer::stage($this->web, $file["tmp_name"], $reason);
        if (is_null($stage)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($stage) {
            $this->comDialog->renderJSRequestPost(array("component" => "form-confirm", "init" => "true", "stage__id" => $stage->id));
        });
    }

    public function onConfirmInit($form)
    {
        $id = $form->getSelector($form->getElementValueString("stage__id", ""));
        $form->stage = Installer::loadStage($id, $this->web);
        if (is_null($form->stage)) {
            return;
        }
        $form->package = Manager::get($this->web, $form->stage->code);
        $form->action = Installer::stageAction($this->web, $form->stage);
        $form->requirements = Manager::requirements($this->web, $form->stage->manifest);
        $form->setSelector($form->stage->id);
    }

    public function onConfirmProcess($form)
    {
        if (is_null($form->stage)) {
            $this->messageError($form, "stage-not-found", "");
            return;
        }
        $reason = "";
        // the files of a package that can not be installed are not copied
        if ($form->isRequirementMissing()) {
            Manager::checkManifestRequire($this->web, $form->stage->manifest, $reason);
            Manager::log($form->stage->code, $form->stage->version, $form->action, "error", $reason);
            Installer::removeStage($form->stage->id);
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        if (!Installer::commit($this->web, $form->stage->id, $reason, $form->getElementValueString("trust", "") === "1")) {
            Installer::removeStage($form->stage->id);
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $this->migrate($form, $form->stage->code);
    }

    // The files were replaced: the migrations run in the next request
    public function migrate($form, $code)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($code) {
            $this->comDialog->renderJSRequestPost(array("component" => "form-migrate", "package__id" => Manager::idFromCode($code)));
        });
    }

    public function onMigrateProcess($form)
    {
        $code = Manager::codeFromId($form->getElementValueString("package__id", ""));
        if (is_null($code)) {
            $this->messageError($form, "invalid-state", "");
            return;
        }
        $reason = "";
        $operation = "";
        if (!Manager::migrate($this->web, $code, $reason, $operation)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $this->messageOk($form, $operation . "-ok");
    }

    // Refresh the list after an operation
    protected function renderJSRefresh()
    {
        $this->comDataTable->renderJSRequestPost();
    }

    // The error stays open (Close button) so it can be read and copied
    public function messageError($form, $reason, $detail)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($reason, $detail) {
            $this->comDialog->renderJSRequestGet(array("component" => "message-error", "reason" => $reason, "detail" => $detail));
            $this->renderJSRefresh();
        });
    }

    public function messageOk($form, $message)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($message) {
            $this->comDialog->renderJSRequestGet(array("component" => "message-ok", "message" => $message));
            $this->renderJSRefresh();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSClose();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSRequestGet(array("component" => "placeholder"));
            echo "},250);";
            echo "},500);";
        });
    }

    // Enable / disable an installed package, the new value (unchanged when refused)
    public function onToggleEnabled($id)
    {
        $code = Manager::codeFromId($id);
        if (is_null($code)) {
            return 0;
        }
        $info = Manager::get($this->web, $code);
        if (is_null($info)) {
            return 0;
        }
        $reason = "";
        if (Manager::canOperate($info, "disable")) {
            Manager::disable($this->web, $code, $reason);
        } elseif (Manager::canOperate($info, "enable")) {
            Manager::enable($this->web, $code, $reason);
        }
        $info = Manager::get($this->web, $code);
        return ($info->isInstalled && $info->enabled) ? 1 : 0;
    }

}

return Page::class;
