<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\User;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class FormDelete extends \Andora\Web\Component\Form
{

    public function formInit($options = null)
    {
        
        $this->value->name = null;
        $this->value->email = null;

    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("delete-user-account"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <h2><?php echo htmlspecialchars("" . $this->value->name, ENT_QUOTES); ?></h2>
            <h3><?php echo htmlspecialchars("" . $this->value->email, ENT_QUOTES); ?></h3>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel">Cancel</button>
                <div class="ml-auto">
                    <button class="btn btn-error">
                        <?php $this->language->render("delete"); ?>
                    </button>
                </div>
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
