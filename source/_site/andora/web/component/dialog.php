<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class Dialog extends \XYO\Web\Component
{
    public $component = null;
    public $placeholder = null;
    public $cssClass = null;
    public $ajaxComponents = array();

    public function init($options = null)
    {
        LucideIcons::register($this, "icons");

        $this->component = "none";
        if (array_key_exists("component", $options)) {
            $this->component = $options["component"];
        }
        $this->placeholder = null;
        if (array_key_exists("placeholder", $options)) {
            $this->placeholder = $options["placeholder"];
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

        $this->registerAJAXComponent($this->component);

    }

    public function registerAJAXComponent($component)
    {
        if (array_key_exists($component, $this->ajaxComponents)) {
            ($this->ajaxComponents[$component])($this, $component);
        }
    }

    public function renderAJAX($options = null)
    {
        $this->renderComponent($this->component);
    }

    public function renderContainer($options = null)
    { ?>
        <dialog id="<?php echo $this->id; ?>_dialog" class="modal transition-all">
            <div class="modal-box transition-all">
                <div id="<?php echo $this->id; ?>" class="<?php echo $this->cssClass; ?>" class="transition-all">
                    <?php $this->renderComponent($this->component); ?>
                </div>
                <form method="dialog">
                    <button id="<?php echo $this->id; ?>_close"
                        class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2 active:bg-error/10 active:border-error/10 hover:bg-error/10 hover:border-error/80 group/button">
                        <?php $this->renderComponent("icons", array("icon" => "x", "class" => "text-base group-active/button:text-error group-hover/button:text-error")); ?>
                    </button>
                </form>
            </div>
        </dialog>
        <?php
        if (!is_null($this->placeholder)) {
            if ($this->placeholder != $this->component) {
                $this->registerAJAXComponent($this->placeholder);
                $this->initComponent($this->placeholder);
            }
            $content = json_encode($this->strRenderComponent($this->placeholder));

            $this->view->renderJS(function () use ($content) {
                ?>
                <script>
                    window["dialog_<?php echo $this->id; ?>_placeholder"] = <?php echo $content; ?>;
                    document.getElementById("<?php echo $this->id; ?>_close").addEventListener("click", function (event) {
                        setTimeout(function () {
                            XYO.Web.HTML.update("<?php echo $this->id; ?>", window["dialog_<?php echo $this->id; ?>_placeholder"], XYO.Web.Component.nonce);
                        }, 150);
                    });
                </script>
                <?php
            });
        }
    }

    public function renderJSOpen($options = null)
    {
        echo "document.getElementById(\"" . $this->id . "_dialog\").showModal();";
    }

    public function renderJSClose($options = null)
    {
        echo "document.getElementById(\"" . $this->id . "_dialog\").close();";
        echo "document.getElementById(\"" . $this->id . "_close\").click();";
    }

    public function setAJAXComponent($name, $fn)
    {
        $this->ajaxComponents[$name] = $fn;
    }

}

