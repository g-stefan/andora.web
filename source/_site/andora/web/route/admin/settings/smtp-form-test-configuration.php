<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Settings;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\Setup;

class SMTPFormTestConfiguration extends \Andora\Web\Component\Form
{

    public function init($options = null)
    {
        parent::init($options);

        LucideIcons::register($this, "icons");
    }

    public function process($options = null)
    {

        if (!$this->isPOST()) {
            return;
        }

        if ($this->hasError()) {
            return;
        }

        $this->setIsDone(true);
    }

    public function renderAJAX($options = null)
    {

        $this->renderFormAJAX(function () {
            ?>

            <button type="submit" class="btn btn-neutral">
                <?php $this->language->render("smtp-form-test-configuration.submit"); ?>
            </button>

            <?php
        }, "flex flex-col gap-2");

    }

    public function renderContainer($options = null)
    { ?>
        <div class="relative w-full flex flex-col mt-4 text-center" id="<?php echo $this->id; ?>">
            <?php $this->renderAJAX(); ?>
        </div>
        <?php
    }
}
