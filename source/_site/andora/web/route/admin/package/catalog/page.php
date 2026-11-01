<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Package\Manager;
use \Andora\Web\Package\Registry;

// admin/package/catalog: the packages of the registries (\Andora\Web\Package\Registry).
// The indexes are fetched only by the AJAX request the Catalog component
// makes when the page is opened (and after a change made on the page).
// Install: form-download (download, sha256, stage) -> form-confirm ->
// form-migrate, the dialog of admin/package (the same as an upload).
class Page extends \Andora\Web\Route\Admin\Package\Page
{
    public const MESSAGES = array("install-ok", "upgrade-ok", "replace-ok", "registry-add-ok", "registry-remove-ok");

    protected $comCatalog = null;

    public function init($options = null)
    {
        // the strings of admin/package (forms of the dialog), then the ones of the catalog
        $language = strtolower("" . $this->view->language);
        if (preg_match("/^[a-z]{2}(-[a-z]{2})?$/", $language)) {
            $this->language->includeFile(__DIR__ . "/../language/" . $language . ".php");
            $this->loadLanguage(true);
        }

        $this->setTitle("Admin - Package catalog");
        Dashboard::setApplicationTitle("Package");
        $this->isFileOperationAllowed = Manager::isFileOperationAllowed($this->web);

        $this->registerDialog();
        $this->comDialog->setAJAXComponent("form-download", function ($dialog, $component) {
            $form = FormDownload::register($dialog, $component);
            $form->setOnSuccess([$this, "onDownloadProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-registry-add", function ($dialog, $component) {
            $form = FormRegistryAdd::register($dialog, $component);
            $form->setOnSuccess([$this, "onRegistryAddProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-registry-remove", function ($dialog, $component) {
            $form = FormRegistryRemove::register($dialog, $component);
            $form->setOnInit([$this, "onRegistryRemoveInit"]);
            $form->setOnSuccess([$this, "onRegistryRemoveProcess"]);
        });

        $this->comCatalog = Catalog::register($this, "catalog", array(
            "isFileOperationAllowed" => $this->isFileOperationAllowed
        ));
    }

    public function render($options = null)
    {
        $this->renderTabs("catalog");
        $this->renderComponent("catalog");
        $this->renderComponent("dialog");
        $this->view->renderJS(function () {
            ?>
            <script>
                function catalogInstall(registry, id) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-download"), array("registry" => "registry", "package__id" => "id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function catalogRegistryAdd() {
                    <?php $this->comDialog->renderJSRequestGet(array("component" => "form-registry-add")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function catalogRegistryRemove(registry) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-registry-remove", "init" => "true"), array("registry" => "registry")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
            </script>
            <?php
        });
    }

    // The list again, from the indexes fetched when the page was opened
    protected function renderJSRefresh()
    {
        $this->comCatalog->renderJSRequestPost(array("cached" => "1"));
    }

    // Download the zip of the package, check it and stage it, then the
    // confirm of an upload (signature, "I trust this package")
    public function onDownloadProcess($form)
    {
        $registry = $form->getElementValueString("registry", "");
        $code = Manager::codeFromId($form->getElementValueString("package__id", ""));
        $reason = "";
        $stage = Registry::download($this->web, $registry, $code, $reason);
        if (is_null($stage)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($stage) {
            $this->comDialog->renderJSRequestPost(array("component" => "form-confirm", "init" => "true", "stage__id" => $stage->id));
        });
    }

    public function onRegistryAddProcess($form)
    {
        $reason = "";
        if (!Registry::add($this->web, $form->value->name ?? "", $form->value->url ?? "", $reason)) {
            $this->messageError($form, $reason, Manager::$detail);
            return;
        }
        $this->messageOk($form, "registry-add-ok");
    }

    public function onRegistryRemoveInit($form)
    {
        $name = $form->getSelector($form->getElementValueString("registry", ""));
        $added = Registry::added();
        if (!is_string($name) || !array_key_exists($name, $added)) {
            return;
        }
        $form->registry = $name;
        $form->url = $added[$name];
        $form->setSelector($name);
    }

    public function onRegistryRemoveProcess($form)
    {
        $reason = "";
        if (is_null($form->registry) || !Registry::remove($this->web, $form->registry, $reason)) {
            $this->messageError($form, is_null($form->registry) ? "registry-not-found" : $reason, Manager::$detail);
            return;
        }
        $this->messageOk($form, "registry-remove-ok");
    }
}

return Page::class;
