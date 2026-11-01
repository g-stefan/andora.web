<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package;

defined("ANDORA") or die("Forbidden");

// Upload of a package zip (Page::onUploadProcess checks it, then opens form-confirm)
class FormUpload extends \Andora\Web\Component\Form
{
    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("upload-package"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <fieldset class="fieldset">
                <legend class="fieldset-legend"><?php $this->language->render("package.legend"); ?></legend>
                <input type="file" name="package" accept=".zip,application/zip" class="file-input w-full" required />
                <p class="label whitespace-normal"><?php $this->language->render("upload-hint"); ?></p>
            </fieldset>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel"><?php $this->language->render("close"); ?></button>
                <div class="ml-auto">
                    <button class="btn btn-primary"><?php $this->language->render("upload"); ?></button>
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
