<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Permission;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\DataSource\TableUserXPermission;
use \Andora\Web\DataSource\TableUserGroupXPermission;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\DataTable;
use \Andora\Web\Component\DataTableAction;
use \Andora\Web\Component\DataTableToggle;
use \Andora\Web\Component\Dialog;
use \Andora\Web\Component\Placeholder;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\MessageError;

class Page extends \XYO\Web\Page
{
    protected $comDialog = null;
    protected $comDataTable = null;

    public function init($options = null)
    {
        $this->setTitle("Admin - Permission");
        Dashboard::setApplicationTitle("Permission");

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
            $form->setOnSuccess([$this, "onPermissionAddProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-edit", function ($dialog, $component) {
            $form = FormEdit::register($dialog, $component);
            $form->setOnInit([$this, "onPermissionEditInit"]);
            $form->setOnSuccess([$this, "onPermissionEditProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-delete", function ($dialog, $component) {
            $form = FormDelete::register($dialog, $component);
            $form->setOnInit([$this, "onPermissionDeleteInit"]);
            $form->setOnSuccess([$this, "onPermissionDeleteProcess"]);
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
                            array("js", "square-pen", "Edit", "permissionEdit"),
                            array("separator"),
                            array("js", "trash-2", "Delete", "permissionDelete", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error"),
                        )
                    )
                )
            ),
            "datasource" => TablePermission::class,
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
                function permissionEdit(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-edit", "init" => "true"), array("permission__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function permissionDelete(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-delete", "init" => "true"), array("permission__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
            </script>
            <?php
        });
    }

    public function onPermissionAddProcess($form)
    {
        $name = trim($form->value->name);
        if (strlen($name) < 2) {
            $form->setElementError("name", true);
            $this->messageError($form, "add-error");
            return;
        }

        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->name = $name;
        if ($dsPermission->load(0, 1)) {
            $form->setElementError("name", true);
            $this->messageError($form, "add-error");
            return;
        }

        $dsPermission->clear();
        $dsPermission->name = $name;
        $dsPermission->description = $form->value->description;
        $dsPermission->enabled = 1;
        $dsPermission->is_system = 0;

        if (!$dsPermission->save()) {
            $this->messageError($form, "add-error");
            return;
        }

        $this->messageOk($form, "add-ok");
    }

    public function onPermissionEditInit($form)
    {
        $permission__id = $form->getSelector($form->getElementValueNumber("permission__id", 0));
        if ($permission__id > 0) {
            $dsPermission = $this->getDataSource(TablePermission::class);
            $dsPermission->clear();
            $dsPermission->id = $permission__id;
            if ($dsPermission->load(0, 1)) {
                $form->value->permission__id = $dsPermission->id;
                $form->value->name = $dsPermission->name;
                $form->value->description = $dsPermission->description;

                $form->setSelector($form->value->permission__id);
            }
        }
    }

    public function onPermissionEditProcess($form)
    {
        $isOk = false;
        $permission__id = $form->getSelector(0);
        if ($permission__id > 0) {
            $name = trim($form->value->name);
            if (strlen($name) < 2) {
                $form->setElementError("name", true);
                $this->messageError($form, "save-error");
                return;
            }

            $dsPermission = $this->getDataSource(TablePermission::class);
            $dsPermission->clear();
            $dsPermission->name = $name;
            for ($dsPermission->load(0, 10); $dsPermission->loadIsValid(); $dsPermission->loadNext()) {
                if (intval($dsPermission->id) != intval($permission__id)) {
                    $form->setElementError("name", true);
                    $this->messageError($form, "save-error");
                    return;
                }
            }

            $dsPermission->clear();
            $dsPermission->id = $permission__id;
            if ($dsPermission->load(0, 1)) {
                // The code checks the system permissions by name
                if ((intval($dsPermission->is_system) == 1) && (strcmp($name, "" . $dsPermission->name) != 0)) {
                    $form->setElementError("name", true);
                    $this->messageError($form, "system-permission");
                    return;
                }
                $dsPermission->name = $name;
                $dsPermission->description = $form->value->description;
                $isOk = $dsPermission->save();
            }
        }

        if (!$isOk) {
            $this->messageError($form, "save-error");
            return;
        }

        $this->messageOk($form, "save-ok");
    }

    public function onPermissionDeleteInit($form)
    {
        $permission__id = $form->getSelector($form->getElementValueNumber("permission__id", 0));
        if ($permission__id > 0) {
            $dsPermission = $this->getDataSource(TablePermission::class);
            $dsPermission->clear();
            $dsPermission->id = $permission__id;
            if ($dsPermission->load(0, 1)) {
                $form->value->name = $dsPermission->name;
                $form->value->description = $dsPermission->description;
                $form->setSelector($permission__id);
            }
        }
    }

    public function onPermissionDeleteProcess($form)
    {
        $isOk = false;
        $permission__id = $form->getSelector(0);
        if ($permission__id > 0) {
            $dsPermission = $this->getDataSource(TablePermission::class);
            $dsPermission->clear();
            $dsPermission->id = $permission__id;
            if ($dsPermission->load(0, 1)) {
                if (intval($dsPermission->is_system) == 1) {
                    $this->messageError($form, "system-permission");
                    return;
                }
                $isOk = $dsPermission->delete();
                if ($isOk) {
                    $this->cascadeDelete($permission__id);
                }
            }
        }

        if (!$isOk) {
            $this->messageError($form, "delete-error");
            return;
        }

        $this->messageOk($form, "delete-ok");
    }

    // Remove every association that references the deleted permission so no
    // orphan grant rows are left behind in the junction tables.
    public function cascadeDelete($permission__id)
    {
        // user <-> permission grants
        $dsUserXPermission = $this->getDataSource(TableUserXPermission::class);
        $dsUserXPermission->clear();
        $dsUserXPermission->permission__id = $permission__id;
        $dsUserXPermission->delete();

        // user-group <-> permission grants
        $dsUserGroupXPermission = $this->getDataSource(TableUserGroupXPermission::class);
        $dsUserGroupXPermission->clear();
        $dsUserGroupXPermission->permission__id = $permission__id;
        $dsUserGroupXPermission->delete();
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

    public function onToggleEnabled($id)
    {
        $id = intval($id);
        if ($id == 0) {
            return;
        }
        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->id = $id;
        if ($dsPermission->load(0, 1)) {
            if (intval($dsPermission->is_system) == 1) {
                return $dsPermission->enabled;
            }
            if ($dsPermission->enabled) {
                $dsPermission->enabled = 0;
            } else {
                $dsPermission->enabled = 1;
            }
            if ($dsPermission->save()) {
                return $dsPermission->enabled;
            }
        }
        return false;
    }

}

return Page::class;
