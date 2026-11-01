<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

// Posted (without init) by Install / Upgrade of the catalog, with registry
// and package__id: Page::onDownloadProcess downloads the zip and opens
// form-confirm. Rendered only while the request runs.
class FormDownload extends \Andora\Web\Component\Form
{
    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("downloading"); ?>
            </p>
        </div>

        <?php
    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }

}
