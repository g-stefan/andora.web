<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class AJAXAction extends \XYO\Web\Component
{
    public $modParent = null;
    public $action = null;
    public $cssClass = null;

    public function init($options = null)
    {
        $this->cssClass = "";

        if (is_null($options)) {
            return;
        }

        if (!array_key_exists("parent", $options)) {
            return;
        }

        $this->modParent = $options["parent"];

        if (array_key_exists("class", $options)) {
            $this->cssClass = $options["class"];
        }

        $defaultAction = "";
        if (array_key_exists("default", $options)) {
            $defaultAction = $options["default"];
        }

        $this->action = $defaultAction;
        if ($this->isComponentAJAX()) {
            $this->action = $this->request->get("action", $defaultAction);
        }
        $this->modParent->initComponent($this->action);
    }

    public function renderAJAX($options = null)
    {

        $this->modParent->renderComponent($this->action);
    }

    public function renderContainer($options = null)
    {
        ?>
        <div id="<?php echo $this->id; ?>" class="<?php echo $this->cssClass; ?>">
            <?php $this->modParent->renderComponent($this->action); ?>
        </div>
        <?php
    }

}

