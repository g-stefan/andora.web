<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package;

defined("ANDORA") or die("Forbidden");

// What the uploaded package changes (install, upgrade, replace; downgrade is
// refused). The page fills $stage, $package, $action, $requirements
// (onConfirmInit) and replaces the folder (onConfirmProcess). An install or
// an upgrade whose requirements are not met is refused before the files
// are replaced.
class FormConfirm extends \Andora\Web\Component\Form
{
    use RequirementList;

    public $stage = null;
    public $package = null;
    public $action = "";
    public $requirements = array();

    public function formInit($options = null)
    {
        $this->stage = null;
        $this->package = null;
        $this->action = "";
        $this->requirements = array();
    }

    // The requirements must be met before the files are replaced
    public function isRequirementMissing()
    {
        return in_array($this->action, array("install", "upgrade"), true) && self::hasMissingRequirement($this->requirements);
    }

    protected function renderLine($label, $value)
    {
        if (strlen("" . $value) == 0) {
            return;
        }
        ?>
        <div class="flex gap-2">
            <span class="w-28 shrink-0 text-base-content/60"><?php $this->language->render($label); ?></span>
            <span class="break-all"><?php echo htmlspecialchars("" . $value, ENT_QUOTES); ?></span>
        </div>
        <?php
    }

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
            $isAllowed = !is_null($this->stage) && ($this->action !== "downgrade") && in_array($this->stage->decision, array("accept", "confirm"), true) && !$this->isRequirementMissing();
            if (is_null($this->stage)) {
                ?>
                <p class="text-error"><?php $this->language->render("stage-not-found"); ?></p>
                <?php
            } else {
                $manifest = $this->stage->manifest;
                ?>
                <h2 class="text-xl font-semibold"><?php echo htmlspecialchars("" . $this->stage->title, ENT_QUOTES); ?></h2>
                <p class="text-base-content/70"><?php echo htmlspecialchars("" . $manifest["description"], ENT_QUOTES); ?></p>
                <div class="flex flex-col gap-1 text-sm mt-2">
                    <?php
                    $this->renderLine("package", $this->stage->code);
                    $this->renderLine("version", $this->stage->version);
                    if (!is_null($this->package)) {
                        $this->renderLine("installed", $this->package->installedVersion);
                        $this->renderLine("state", $this->language->get("state." . $this->package->state));
                    }
                    $this->renderLine("license", $manifest["license"]);
                    $this->renderLine("author", $manifest["author"]);
                    $this->renderRequirements($this->requirements);
                    $this->renderLine("signature", $this->language->get("signature." . $this->stage->signature->state) . ((strlen($this->stage->signature->keyName) > 0) ? ": " . $this->stage->signature->keyName : ""));
                    ?>
                </div>
                <p class="mt-2 <?php echo ($this->action !== "downgrade") ? "" : "text-error"; ?>"><?php $this->language->render("action." . $this->action); ?></p>
                <?php
                if ($this->isRequirementMissing()) {
                    ?>
                    <p class="text-error mt-2"><?php $this->language->render("requirements-not-met"); ?></p>
                    <?php
                }
                if (($this->action !== "downgrade") && !in_array($this->stage->decision, array("accept", "confirm"), true)) {
                    ?>
                    <p class="text-error mt-2"><?php echo htmlspecialchars($this->language->get($this->stage->decision), ENT_QUOTES); ?></p>
                    <?php
                }
                if ($isAllowed && ($this->stage->decision === "confirm")) {
                    ?>
                    <label class="label mt-2 whitespace-normal items-start">
                        <input type="checkbox" name="trust" value="1" class="checkbox checkbox-sm checkbox-warning" required />
                        <span><?php $this->language->render("trust-package"); ?></span>
                    </label>
                    <?php
                }
            }
            ?>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel"><?php $this->language->render("close"); ?></button>
                <?php if ($isAllowed) { ?>
                    <div class="ml-auto">
                        <button class="btn btn-primary"><?php $this->language->render("action." . $this->action . ".submit"); ?></button>
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
