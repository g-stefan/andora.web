<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Admin\User;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableUser;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Model\User;
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
        $this->setTitle("Admin - User");
        Dashboard::setApplicationTitle("User");

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
            $form->setOnSuccess([$this, "onUserAddProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-edit", function ($dialog, $component) {
            $form = FormEdit::register($dialog, $component);
            $form->setOnInit([$this, "onUserEditInit"]);
            $form->setOnSuccess([$this, "onUserEditProcess"]);
        });
        $this->comDialog->setAJAXComponent("form-delete", function ($dialog, $component) {
            $form = FormDelete::register($dialog, $component);
            $form->setOnInit([$this, "onUserDeleteInit"]);
            $form->setOnSuccess([$this, "onUserDeleteProcess"]);
        });
        $this->comDialog->setAJAXComponent("message-ok", function ($dialog, $component) {
            $message = $this->request->get("message", "");
            MessageOk::register($dialog, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get($message)
            ));
        });
        $this->comDialog->setAJAXComponent("message-error", function ($dialog, $component) {
            $reason = $this->request->get("reason", "");
            MessageError::register($dialog, $component, array(
                "title" => "Setup",
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $reason
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
                "email" => array(
                    "type" => "text",
                    "name" => "E-Mail",
                    "search" => true
                ),
                "enabled" => array(
                    "type" => "component",
                    "name" => "Enabled",
                    "component" => array(
                        DataTableToggle::class,
                        array(
                            "toggleFn" => [$this, "toggleEnabled"]
                        )
                    )
                ),
                "is_confirmed" => array(
                    "type" => "component",
                    "name" => "Is confirmed",
                    "component" => array(
                        DataTableToggle::class,
                        array(
                            "toggleFn" => [$this, "toggleIsConfirmed"]
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
                            array("js", "square-pen", "Edit", "userEdit"),
                            array("separator"),
                            array("js", "trash-2", "Delete", "userDelete", "text-error hover:text-base-content hover:bg-error/10", "text-error group-hover/link:text-error"),
                        )
                    )
                )
            ),
            "datasource" => TableUser::class,
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
                function userEdit(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-edit", "init" => "true"), array("user__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
                function userDelete(record) {
                    <?php $this->comDialog->renderJSRequestPost(array("component" => "form-delete", "init" => "true"), array("user__id" => "record.id")); ?>
                    <?php $this->comDialog->renderJSOpen(); ?>
                };
            </script>
            <?php
        });
    }

    public function onUserAddProcess($form)
    {
        $reason = "";
        $isOk = User::addUser(
            $this->web,
            $form->value->name,
            $form->value->email,
            $form->value->password,
            $reason
        );

        if (!$isOk) {
            $this->messageError($form, "add-error");
            return;
        }

        $this->messageOk($form, "add-ok");
    }

    public function onUserEditInit($form)
    {
        $user__id = $form->getSelector($form->getElementValueNumber("user__id", 0));
        if ($user__id > 0) {
            $dsUser = $this->getDataSource(TableUser::class);
            $dsUser->clear();
            $dsUser->id = $user__id;
            if ($dsUser->load(0, 1)) {
                $form->value->user__id = $dsUser->id;
                $form->value->name = $dsUser->name;
                $form->value->email = $dsUser->email;

                $form->setSelector($form->value->user__id);
            }
        }
    }

    public function onUserEditProcess($form)
    {
        $isOk = false;
        $user__id = $form->getSelector(0);
        if ($user__id > 0) {
            $dsUser = $this->getDataSource(TableUser::class);
            $dsUser->clear();
            $dsUser->id = $user__id;
            if ($dsUser->load(0, 1)) {
                $dsUser->name = $form->value->name;
                $dsUser->email = $form->value->email;
                if (strlen($form->value->password) > 0) {
                    if (strlen($form->value->password) < 12) {
                        $form->setElementError("password", true);
                        return;
                    }
                    $pattern = "/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-_]).{8,}$/";
                    if (!preg_match($pattern, $form->value->password)) {
                        $form->setElementError("password", true);
                        return;
                    }
                    $dsUser->password = User::setPasswordHash($this->web, $dsUser->email, $form->value->password, "hash");
                }
                $isOk = $dsUser->save();
            }
        }

        if (!$isOk) {
            $this->messageError($form, "save-error");
            return;
        }

        $this->messageOk($form, "save-ok");

    }

    public function onUserDeleteInit($form)
    {
        $user__id = $form->getSelector($form->getElementValueNumber("user__id", 0));
        if ($user__id > 0) {
            $dsUser = $this->getDataSource(TableUser::class);
            $dsUser->clear();
            $dsUser->id = $user__id;
            if ($dsUser->load(0, 1)) {
                $form->value->name = $dsUser->name;
                $form->value->email = $dsUser->email;
                $form->setSelector($user__id);
            }
        }
    }

    public function onUserDeleteProcess($form)
    {
        $isOk = false;
        $user__id = $form->getSelector(0);
        if ($user__id > 0) {
            if ($user__id == User::$user__id) {
                $this->messageError($form, "delete-self");
                return;
            }

            $dsUser = $this->getDataSource(TableUser::class);
            $dsUser->clear();
            $dsUser->id = $user__id;
            if ($dsUser->load(0, 1)) {
                $isOk = $dsUser->delete();
            }
        }

        if (!$isOk) {
            $this->messageError($form, "delete-error");
            return;
        }

        $this->messageOk($form, "delete-ok");
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

    public function toggleEnabled($id)
    {
        $id = intval($id);
        if ($id == 0) {
            return;
        }
        $dsUser = $this->getDataSource(TableUser::class);
        $dsUser->clear();
        $dsUser->id = $id;
        if ($dsUser->load(0, 1)) {

            if ($dsUser->id == User::$user__id) {
                return $dsUser->enabled;
            }

            if ($dsUser->enabled) {
                $dsUser->enabled = 0;
            } else {
                $dsUser->enabled = 1;
            }
            if ($dsUser->save()) {
                return $dsUser->enabled;
            }
        }
        return 2;
    }

    public function toggleIsConfirmed($id)
    {
        $id = intval($id);
        if ($id == 0) {
            return;
        }
        $dsUser = $this->getDataSource(TableUser::class);
        $dsUser->clear();
        $dsUser->id = $id;
        if ($dsUser->load(0, 1)) {
            if ($dsUser->is_confirmed) {
                $dsUser->is_confirmed = 0;
            } else {
                $dsUser->is_confirmed = 1;
            }
            if ($dsUser->save()) {
                return $dsUser->is_confirmed;
            }
        }
        return 2;
    }

}

return Page::class;
