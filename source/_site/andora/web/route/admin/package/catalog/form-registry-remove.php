<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

// Remove a registry that was added (not the official one). The page fills
// $registry and $url (onRegistryRemoveInit) and removes it (onRegistryRemoveProcess).
class FormRegistryRemove extends \Andora\Web\Component\Form
{
    public $registry = null;
    public $url = "";

    public function formInit($options = null)
    {
        $this->registry = null;
        $this->url = "";
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("registry-remove"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            if (is_null($this->registry)) {
                ?>
                <p class="text-error"><?php $this->language->render("registry-not-found"); ?></p>
                <?php
            } else {
                ?>
                <h2 class="text-xl font-semibold"><?php echo htmlspecialchars($this->registry, ENT_QUOTES); ?></h2>
                <p class="text-base-content/70 break-all"><?php echo htmlspecialchars($this->url, ENT_QUOTES); ?></p>
                <?php
            }
            ?>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel"><?php $this->language->render("close"); ?></button>
                <?php if (!is_null($this->registry)) { ?>
                    <div class="ml-auto">
                        <button class="btn btn-error"><?php $this->language->render("registry-remove.submit"); ?></button>
                    </div>
                <?php } ?>
            </div>

            <?php
            $this->view->renderJS(function () {
                ?>
                <script>
                    document.getElementById("<?php echo $this->id; ?>_cancel").addEventListener("click", function (event) {
                        <?php $this->parent->renderJSClose(); ?>
                        event.preventDefault();
                    });
                </script>
                <?php
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
