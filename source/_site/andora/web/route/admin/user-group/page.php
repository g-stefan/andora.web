<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\UserGroup;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableUserGroup;
use \Andora\Web\DataSource\TableUserXUserGroup;
use \Andora\Web\DataSource\TableUserGroupXPermission;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Model\Acl;
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
        $this->setTitle("Admin - User group");
        Dashboard::setApplicationTitle("User group");

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
            $form->setOnSuccess([$this, "onUserGroupAddProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-edit", function ($dialog, $component) {
            $form = FormEdit::register($dialog, $component);
            $form->setOnInit([$this, "onUserGroupEditInit"]);
            $form->setOnSuccess([$this, "onUserGroupEditProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-delete", function ($dialog, $component) {
            $form = FormDelete::register($dialog, $component);
            $form->setOnInit([$this, "onUserGroupDeleteInit"]);
            $form->setOnSuccess([$this, "onUserGroupDeleteProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-linked-permission", function ($dialog, $component) {
            FormLinkedPermission::register($dialog, $component);
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
                "level" => array(
                    "type" => "text",
                    "name" => "Level"
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
                            array("js", "square-pen", "Edit", "userGroupEdit"),
                            array("js", "shield-check", "Permissions", "userGroupPermission"),
                            array("separator"),
                            array("js", "trash-2", "Delete", "userGroupDelete", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error"),
                        )
                    )
                )
            ),
            "datasource" => TableUserGroup::class,
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
                function userGroupEdit(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-edit", "init" => "true"), array("user_group__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function userGroupDelete(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-delete", "init" => "true"), array("user_group__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function userGroupPermission(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-linked-permission", "init" => "true"), array("user_group__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
            </script>
            <?php
        });
    }

    public function onUserGroupAddProcess($form)
    {
        $name = trim($form->value->name);
        if (strlen($name) < 2) {
            $form->setElementError("name", true);
            $this->messageError($form, "add-error");
            return;
        }

        $dsUserGroup = $this->getDataSource(TableUserGroup::class);
        $dsUserGroup->clear();
        $dsUserGroup->name = $name;
        if ($dsUserGroup->load(0, 1)) {
            $form->setElementError("name", true);
            $this->messageError($form, "add-error");
            return;
        }

        $dsUserGroup->clear();
        $dsUserGroup->name = $name;
        $dsUserGroup->description = $form->value->description;
        $dsUserGroup->enabled = 1;
        // A new group is at the authenticated level: the administrator level
        // is given by the administrator group itself
        $dsUserGroup->level = Acl::LEVEL_AUTHENTICATED;
        $dsUserGroup->is_system = 0;

        if (!$dsUserGroup->save()) {
            $this->messageError($form, "add-error");
            return;
        }

        $this->messageOk($form, "add-ok");
    }

    public function onUserGroupEditInit($form)
    {
        $user_group__id = $form->getSelector($form->getElementValueNumber("user_group__id", 0));
        if (($user_group__id > 0) && Acl::canManageGroup($this->web, $user_group__id)) {
            $dsUserGroup = $this->getDataSource(TableUserGroup::class);
            $dsUserGroup->clear();
            $dsUserGroup->id = $user_group__id;
            if ($dsUserGroup->load(0, 1)) {
                $form->value->user_group__id = $dsUserGroup->id;
                $form->value->name = $dsUserGroup->name;
                $form->value->description = $dsUserGroup->description;

                $form->setSelector($form->value->user_group__id);
            }
        }
    }

    public function onUserGroupEditProcess($form)
    {
        $isOk = false;
        $user_group__id = $form->getSelector(0);
        if ($user_group__id > 0) {
            if (!Acl::canManageGroup($this->web, $user_group__id)) {
                $this->messageError($form, "access-denied");
                return;
            }

            $name = trim($form->value->name);
            if (strlen($name) < 2) {
                $form->setElementError("name", true);
                $this->messageError($form, "save-error");
                return;
            }

            $dsUserGroup = $this->getDataSource(TableUserGroup::class);
            $dsUserGroup->clear();
            $dsUserGroup->name = $name;
            for ($dsUserGroup->load(0, 10); $dsUserGroup->loadIsValid(); $dsUserGroup->loadNext()) {
                if (intval($dsUserGroup->id) != intval($user_group__id)) {
                    $form->setElementError("name", true);
                    $this->messageError($form, "save-error");
                    return;
                }
            }

            $dsUserGroup->clear();
            $dsUserGroup->id = $user_group__id;
            if ($dsUserGroup->load(0, 1)) {
                if ((intval($dsUserGroup->is_system) == 1) && (strcmp($name, "" . $dsUserGroup->name) != 0)) {
                    $form->setElementError("name", true);
                    $this->messageError($form, "system-group");
                    return;
                }
                $dsUserGroup->name = $name;
                $dsUserGroup->description = $form->value->description;
                $isOk = $dsUserGroup->save();
            }
        }

        if (!$isOk) {
            $this->messageError($form, "save-error");
            return;
        }

        $this->messageOk($form, "save-ok");
    }

    public function onUserGroupDeleteInit($form)
    {
        $user_group__id = $form->getSelector($form->getElementValueNumber("user_group__id", 0));
        if ($user_group__id > 0) {
            $dsUserGroup = $this->getDataSource(TableUserGroup::class);
            $dsUserGroup->clear();
            $dsUserGroup->id = $user_group__id;
            if ($dsUserGroup->load(0, 1)) {
                $form->value->name = $dsUserGroup->name;
                $form->value->description = $dsUserGroup->description;
                $form->setSelector($user_group__id);
            }
        }
    }

    public function onUserGroupDeleteProcess($form)
    {
        $isOk = false;
        $user_group__id = $form->getSelector(0);
        if ($user_group__id > 0) {
            $dsUserGroup = $this->getDataSource(TableUserGroup::class);
            $dsUserGroup->clear();
            $dsUserGroup->id = $user_group__id;
            if ($dsUserGroup->load(0, 1)) {
                if (intval($dsUserGroup->is_system) == 1) {
                    $this->messageError($form, "system-group");
                    return;
                }
                if (!Acl::canManageGroup($this->web, $user_group__id)) {
                    $this->messageError($form, "access-denied");
                    return;
                }
                $isOk = $dsUserGroup->delete();
                if ($isOk) {
                    $this->cascadeDelete($user_group__id);
                }
            }
        }

        if (!$isOk) {
            $this->messageError($form, "delete-error");
            return;
        }

        $this->messageOk($form, "delete-ok");
    }

    // Remove every association that references the deleted user group so no
    // orphan rows are left behind in the junction tables.
    public function cascadeDelete($user_group__id)
    {
        // user <-> user-group memberships
        $dsUserXUserGroup = $this->getDataSource(TableUserXUserGroup::class);
        $dsUserXUserGroup->clear();
        $dsUserXUserGroup->user_group__id = $user_group__id;
        $dsUserXUserGroup->delete();

        // user-group <-> permission grants
        $dsUserGroupXPermission = $this->getDataSource(TableUserGroupXPermission::class);
        $dsUserGroupXPermission->clear();
        $dsUserGroupXPermission->user_group__id = $user_group__id;
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
        $dsUserGroup = $this->getDataSource(TableUserGroup::class);
        $dsUserGroup->clear();
        $dsUserGroup->id = $id;
        if ($dsUserGroup->load(0, 1)) {
            if ((intval($dsUserGroup->is_system) == 1) || !Acl::canManageGroup($this->web, $id)) {
                return $dsUserGroup->enabled;
            }
            if ($dsUserGroup->enabled) {
                $dsUserGroup->enabled = 0;
            } else {
                $dsUserGroup->enabled = 1;
            }
            if ($dsUserGroup->save()) {
                return $dsUserGroup->enabled;
            }
        }
        return false;
    }

}

return Page::class;
