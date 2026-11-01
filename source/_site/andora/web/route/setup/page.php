<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Setup;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\ThemeChange;
use \Andora\Web\Component\LogoAndora;
use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\Container;
use \Andora\Web\Component\Placeholder;
use \Andora\Web\Model\Setup;
use \Andora\Web\Model\SystemCheck;
use \Andora\Web\Model\User;
use \Andora\Web\Package\Manager;

class Page extends \XYO\Web\Page
{
    protected $comContainer = null;

    public function init($options = null)
    {

        //---

        $this->setTitle("Andora - Setup");
        LucideIcons::register($this, "icons");
        ThemeChange::register($this, "theme-change");
        LogoAndora::register($this, "logo-andora");

        $component = "select-language";
        $isConfigured = $this->config->get("configured", false);
        if ($isConfigured) {
            $component = "message-ok";
            $this->request->setQuery("component", "message-ok");
            $this->request->removePost("component");
        }

        $this->comContainer = Container::register($this, "container", array(
            "ajax-component" => true,
            "component" => $component,
            "class" => "w-full flex items-start justify-center"
        ));

        $this->comContainer->setAJAXComponent("message-ok", function ($parent, $component) {
            $message = $this->request->get("message", "");
            if (!in_array($message, array("thank-you", "already-configured"), true)) {
                $message = "already-configured";
            }
            // After the setup, go to the login page
            MessageOk::register($parent, $component, array(
                "title" => $this->language->get("page.title"),
                "message" => $this->language->get($message),
                "redirectURLAfterTimeout" => array($this->site . "user/login", 3000)
            ));
        });

        // Once configured, the setup steps are not available anymore
        if ($isConfigured) {
            return;
        }

        $this->comContainer->setAJAXComponent("select-language", function ($parent, $component) {
            $form = FormSelectLanguage::registerAndInit($parent, $component);
            $form->setOnInit([$this, "onSelectLanguageInit"]);
            $form->setOnSelect([$this, "onSelectLanguage"]);
            $form->setOnSuccess([$this, "onSetLanguage"]);
        });

        $this->comContainer->setAJAXComponent("select-database", function ($parent, $component) {
            $form = FormSelectDatabase::registerAndInit($parent, $component);            
            $form->setOnSuccess([$this, "onSelectDatabase"]);
        });

        $this->comContainer->setAJAXComponent("register-user", function ($parent, $component) {
            $form = FormUser::registerAndInit($parent, $component);
            $form->setOnSuccess([$this, "onRegisterUser"]);
        });

        $this->comContainer->setAJAXComponent("message-error", function ($parent, $component) {
            $reason = $this->request->get("reason", "");
            MessageError::register($parent, $component, array(
                "title" => "Setup",
                "message" => $this->language->get("an-error-occurred"),
                "reason" => $reason
            ));
        });
    }

    public function messageError($form, $reason)
    {
        $form->setIsDone(false);
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($reason) {
            $this->comContainer->renderJSRequestGet(array("component" => "message-error", "reason" => $reason));
        });
    }

    public function messageOk($form, $message)
    {
        $form->setIsDone(false);
        $form->disableRenderAJAX(true);
        $this->view->renderJS(function () use ($message) {
            $this->comContainer->renderJSRequestGet(array("component" => "message-ok", "message" => $message));
        });
    }

    public function render($options = null)
    {
        $this->renderComponent("container");
    }

    public function onSelectLanguageInit($form)
    {
        $form->value->language = $this->config->get("language", "en-US");
    }

    public function onSelectLanguage($form)
    {
        $this->setLanguage($form->value->language);
        $this->loadLanguage(true);
        $form->setIsDone(false);
    }

    public function onSetLanguage($form)
    {
        $form->disableRenderAJAX(true);
        // ---
        $reason = null;
        if (!SystemCheck::isReady()) {
            $this->messageError($form, "system-requirements-not-met");
            return;
        }

        $isOk = Setup::writeLanguageConfigFile(
            $form->value->language,
            $reason
        );

        if (!$isOk) {
            $this->messageError($form, $reason);
            return;
        }

        $this->view->renderJS(function () {
            $this->comContainer->renderJSRequestGet(array("component" => "select-database"));
        });
    }

    public function onSelectDatabase($form)
    {
        $form->disableRenderAJAX(true);
        // ---
        $reason = null;
        if (!SystemCheck::hasDatabaseDriver($form->value->databaseType)) {
            $this->messageError($form, "database-driver-missing");
            return;
        }

        $isOk = Setup::writeDatabaseConfigFile(
            $form->value->databaseType,
            $form->value->username,
            $form->value->password,
            $form->value->databaseServer,
            $form->value->databasePort,
            $form->value->databaseName,
            $form->value->tablePrefix,
            $reason
        );

        if (!$isOk) {
            $this->messageError($form, $reason);
            return;
        }

        if (!Setup::checkDatabaseConnection($this->web, $reason)) {
            $this->messageError($form, $reason);
            return;
        }

        // andora/web (the database), then the packages found in _site/
        if (!Manager::installAll($this->web, $reason)) {
            $this->messageError($form, $reason);
            return;
        }

        if (!Setup::writeSchemaConfigFile($reason)) {
            $this->messageError($form, $reason);
            return;
        }

        if (!Setup::writeUserConfigFile($reason)) {
            $this->messageError($form, $reason);
            return;
        }

        $this->view->renderJS(function () {
            $this->comContainer->renderJSRequestGet(array("component" => "register-user"));
        });
    }

    public function onRegisterUser($form)
    {
        $form->disableRenderAJAX(true);
        // ---
        $reason = null;
        if (!$this->config->has("dataSource") || !$this->config->has("user")) {
            $this->messageError($form, "database-not-configured");
            return;
        }

        $isOk = Setup::createAdministrator(
            $this->web,
            $form->value->name,
            $form->value->email,
            $form->value->password,
            $reason
        );

        if (!$isOk) {
            $this->messageError($form, $reason);
            return;
        }

        // The setup is done only after the administrator account exists
        if (!Setup::writeConfiguredConfigFile(User::getSiteURL($this->web), $reason)) {
            $this->messageError($form, $reason);
            return;
        }

        $this->messageOk($form, "thank-you");
    }

}

return Page::class;
