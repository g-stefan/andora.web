<?php
// Example package
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\Route\Admin\Example;

defined("ANDORA") or die("Forbidden");

use \Acme\Example\DataSource\TableItem;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Package\Event;
use \Andora\Web\Component\DataTable;
use \Andora\Web\Component\DataTableAction;
use \Andora\Web\Component\DataTableToggle;
use \Andora\Web\Component\Dialog;
use \Andora\Web\Component\Placeholder;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\MessageError;

// admin/example: the items in a data table, add / edit / delete in a dialog
// (the pattern of the admin pages of Andora). It gets the admin layout of
// admin/layout.php: the route is mounted below admin/.
class Page extends \XYO\Web\Page
{
    protected $comDialog = null;
    protected $comDataTable = null;

    public function init($options = null)
    {
        $this->setTitle("Admin - Example");
        Dashboard::setApplicationTitle("Example");

        $this->comDialog = Dialog::register($this, "dialog", array(
            "ajax-component" => true,
            "component" => "placeholder",
            "placeholder" => "placeholder",
            "class" => "flex items-start justify-center"
        ));
        $this->comDialog->setAJAXComponent("placeholder", function ($dialog, $component) {
            Placeholder::register($dialog, $component);
        });
        $this->comDialog->setAJAXComponent("form-add", function ($dialog, $component) {
            $form = FormAdd::register($dialog, $component);
            $form->setOnSuccess([$this, "onItemAddProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-edit", function ($dialog, $component) {
            $form = FormEdit::register($dialog, $component);
            $form->setOnInit([$this, "onItemEditInit"]);
            $form->setOnSuccess([$this, "onItemEditProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-delete", function ($dialog, $component) {
            $form = FormDelete::register($dialog, $component);
            $form->setOnInit([$this, "onItemDeleteInit"]);
            $form->setOnSuccess([$this, "onItemDeleteProcess"]);
        });
        $this->comDialog->setAJAXComponent("message-ok", function ($dialog, $component) {
            $message = $this->request->get("message", "");
            if (!in_array($message, array("add-ok", "save-ok", "delete-ok"), true)) {
                $message = "save-ok";
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
            MessageError::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $this->language->get($reason)
            ));
        });

        $this->comDataTable = DataTable::register($this, "datatable", array(
            "descriptor" => array(
                "id" => array(
                    "type" => "selector",
                    "name" => "",
                    "primaryKey" => true
                ),
                "name" => array(
                    "type" => "text",
                    "name" => "Name",
                    "sort" => "ascendent",
                    "search" => true
                ),
                "description" => array(
                    "type" => "text",
                    "name" => "Description",
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
                        array(
                            array("js", "square-pen", "Edit", "itemEdit"),
                            array("separator"),
                            array("js", "trash-2", "Delete", "itemDelete", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error"),
                        )
                    )
                )
            ),
            "datasource" => TableItem::class,
            "has-search" => true,
            "has-add" => true,
            "addFn" => function () {
                $this->comDialog->renderJSRequestGet(array("component" => "form-add"));
                $this->comDialog->renderJSOpen();
            }
        ));
    }

    public function render($options = null)
    {
        $this->renderComponent("datatable");
        $this->renderComponent("dialog");
        $this->view->renderJS(function () {
            ?>
            <script>
                function itemEdit(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-edit", "init" => "true"), array("item__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function itemDelete(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-delete", "init" => "true"), array("item__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
            </script>
            <?php
        });
    }

    public function onItemAddProcess($form)
    {
        $name = trim("" . $form->value->name);
        if (strlen($name) < 2) {
            $form->setElementError("name", true);
            $this->messageError($form, "add-error");
            return;
        }
        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->user__id = Acl::currentUserId();
        $dsItem->name = $name;
        $dsItem->description = "" . $form->value->description;
        $dsItem->enabled = 1;
        $dsItem->created_at = date("Y-m-d H:i:s");
        if (!$dsItem->save()) {
            $this->messageError($form, "add-error");
            return;
        }
        // the event of package.json "event.provide": every package that
        // listens to it gets it (this one too, Setup::onItemAdd)
        Event::dispatch($this->web, "acme-example.item-add", array("itemId" => intval($dsItem->id), "userId" => intval($dsItem->user__id)));
        $this->messageOk($form, "add-ok");
    }

    public function onItemEditInit($form)
    {
        $item__id = intval($form->getSelector($form->getElementValueNumber("item__id", 0)));
        if ($item__id > 0) {
            $dsItem = $this->getDataSource(TableItem::class);
            $dsItem->clear();
            $dsItem->id = $item__id;
            if ($dsItem->load(0, 1)) {
                $form->value->name = $dsItem->name;
                $form->value->description = $dsItem->description;
                $form->setSelector($item__id);
            }
        }
    }

    public function onItemEditProcess($form)
    {
        $item__id = intval($form->getSelector(0));
        $name = trim("" . $form->value->name);
        if (($item__id == 0) || (strlen($name) < 2)) {
            $form->setElementError("name", true);
            $this->messageError($form, "save-error");
            return;
        }
        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->id = $item__id;
        if (!$dsItem->load(0, 1)) {
            $this->messageError($form, "save-error");
            return;
        }
        $dsItem->name = $name;
        $dsItem->description = "" . $form->value->description;
        if (!$dsItem->save()) {
            $this->messageError($form, "save-error");
            return;
        }
        $this->messageOk($form, "save-ok");
    }

    public function onItemDeleteInit($form)
    {
        $item__id = intval($form->getSelector($form->getElementValueNumber("item__id", 0)));
        if ($item__id > 0) {
            $dsItem = $this->getDataSource(TableItem::class);
            $dsItem->clear();
            $dsItem->id = $item__id;
            if ($dsItem->load(0, 1)) {
                $form->value->name = $dsItem->name;
                $form->value->description = $dsItem->description;
                $form->setSelector($item__id);
            }
        }
    }

    public function onItemDeleteProcess($form)
    {
        $item__id = intval($form->getSelector(0));
        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->id = $item__id;
        if (($item__id == 0) || !$dsItem->load(0, 1) || !$dsItem->delete()) {
            $this->messageError($form, "delete-error");
            return;
        }
        $this->messageOk($form, "delete-ok");
    }

    public function onToggleEnabled($id)
    {
        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->id = intval($id);
        if ((intval($id) == 0) || !$dsItem->load(0, 1)) {
            return 0;
        }
        $dsItem->enabled = intval($dsItem->enabled) ? 0 : 1;
        return $dsItem->save() ? $dsItem->enabled : 0;
    }

    public function messageError($form, $reason)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($reason) {
            $this->comDialog->renderJSRequestGet(array("component" => "message-error", "reason" => $reason));
            $this->comDataTable->renderJSRequestPost();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSClose();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSRequestGet(array("component" => "placeholder"));
            echo "},250);";
            echo "},3000);";
        });
    }

    public function messageOk($form, $message)
    {
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($message) {
            $this->comDialog->renderJSRequestGet(array("component" => "message-ok", "message" => $message));
            $this->comDataTable->renderJSRequestPost();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSClose();
            echo "setTimeout(function(){";
            $this->comDialog->renderJSRequestGet(array("component" => "placeholder"));
            echo "},250);";
            echo "},500);";
        });
    }
}

return Page::class;
