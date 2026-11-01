<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Setup;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Component\InputSelect;
use \Andora\Web\Component\InputText;
use \Andora\Web\Component\InputPasswordText;
use \Andora\Web\Model\SystemCheck;

class FormSelectDatabase extends \Andora\Web\Component\Form
{

    public function formInit($options = null)
    {

        $this->value->databaseType = null;
        $this->value->databaseName = null;
        $this->value->username = null;
        $this->value->password = null;
        $this->value->databaseServer = null;
        $this->value->databasePort = null;
        $this->value->tablePrefix = null;

    }

    public function formInitComponents($options = null)
    {
        // Only the database types PHP has a driver for
        $typeList = array();
        foreach (array("mysql" => "MySQL", "postgresql" => "PostgreSQL", "sqlite" => "SQLite") as $type => $name) {
            if (SystemCheck::hasDatabaseDriver($type)) {
                $typeList[$type] = $name;
            }
        }
        $this->value->databaseType = array_key_first($typeList);
        if ($this->isPOST()) {
            $this->setIsInit($this->getElementValueString("action") != "submit");
            $this->value->databaseType = $this->getElementValueString("databaseType", $this->value->databaseType);
        }

        InputSelect::register($this, "databaseType", array(
            "name" => "databaseType",
            "required" => true,
            "list" => $typeList,
            "initialValue" => $this->value->databaseType
        ));

        if ($this->value->databaseType == "mysql" || $this->value->databaseType == "postgresql") {
            $defaultPort = 3306;
            if ($this->value->databaseType == "postgresql") {
                $defaultPort = 5432;
            }
            if ($this->value->databasePort == 0) {
                $this->value->databasePort = $defaultPort;
            }

            InputText::register($this, "databaseName", array(
                "name" => "databaseName",
                "placeholder" => "andora",
                "required" => true
            ));

            InputText::register($this, "username", array(
                "name" => "username",
                "required" => true,
                "autocomplete" => "off"
            ));

            InputPasswordText::register($this, "password", array(
                "name" => "password",
                "autocomplete" => "new-password"
            ));

            InputText::register($this, "databaseServer", array(
                "name" => "databaseServer",
                "required" => true
            ));

            InputText::register($this, "databasePort", array(
                "name" => "databasePort",
                "required" => true,
                "initialValue" => $defaultPort
            ));

            InputText::register($this, "tablePrefix", array(
                "name" => "tablePrefix",
                "placeholder" => ""
            ));
        }

    }

    public function formProcess($options = null)
    {
        $this->setIsDone($this->getElementValueString("action") == "submit");
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold">Setup</h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("let-s-configure-the-database"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>
            <input type="hidden" name="action" value="select" id="<?php echo $this->getElementId("action"); ?>" />

            <?php

            $this->renderComponent("databaseType");
            if (($this->value->databaseType == "mysql") || ($this->value->databaseType == "postgresql")) {
                $this->renderComponent("databaseName");
                $this->renderComponent("username");
                $this->renderComponent("password");
                $this->renderComponent("databaseServer");
                $this->renderComponent("databasePort");
                $this->renderComponent("tablePrefix");
            }

            if ($this->value->databaseType == "sqlite") {
                $this->language->render("sqlite-database-stored-in-repository");
            }

            ?>

            <button type="submit" class="btn btn-neutral" id="<?php echo $this->getElementId("submit"); ?>">
                <?php echo $this->language->get("configure"); ?>
            </button>

            <?php

            $this->view->renderJS(function () {
                echo "document.getElementById(\"" . $this->getComponentId("databaseType") . "\").addEventListener(\"change\",function(e){";
                echo "e.preventDefault();";
                $this->formRenderJSRequest();
                echo "});";
                echo "document.getElementById(\"" . $this->getElementId("submit") . "\").addEventListener(\"click\",function(e){";
                echo "e.preventDefault();";
                echo "document.getElementById(\"" . $this->getElementId("action") . "\").value=\"submit\";";
                $this->formRenderJSRequest();
                echo "});";
            });

        }, "flex flex-col gap-2");

    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
