<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class Container extends \XYO\Web\Component
{
    public $component = null;
    public $cssClass = null;
    public $ajaxComponents = array();

    public function init($options = null)
    {

        $this->component = "none";
        if (array_key_exists("component", $options)) {
            $this->component = $options["component"];
        }
        $this->cssClass = "";
        if (array_key_exists("class", $options)) {
            $this->cssClass = $options["class"];
        }

        if (array_key_exists("ajax-component", $options)) {
            if ($options["ajax-component"]) {
                $this->component = $this->getSelector($this->component);
                if ($this->isComponentAJAX()) {
                    $this->component = $this->request->get("component", $this->component);
                    $this->setSelector($this->component);
                }                
            }
        }

        if (array_key_exists($this->component, $this->ajaxComponents)) {
            ($this->ajaxComponents[$this->component])($this, $this->component);
        }

    }    

    public function renderAJAX($options = null)
    {
        $this->renderComponent($this->component);
    }

    public function renderContainer($options = null)
    { ?>
        <div id="<?php echo $this->id; ?>" class="<?php echo $this->cssClass; ?>">
            <?php $this->renderComponent($this->component); ?>
        </div>
        <?php
    }

    public function setAJAXComponent($name, $fn)
    {
        $this->ajaxComponents[$name] = $fn;
    }

}

