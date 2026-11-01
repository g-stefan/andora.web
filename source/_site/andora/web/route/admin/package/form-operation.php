<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\DataSourceRegistry;

// Details of a package and the confirmation of an operation (install,
// upgrade, reset, uninstall, restore). The page fills $package, $isAllowed and
// $requirements (onOperationInit) and runs the operation (onOperationProcess).
// Install and upgrade are not offered while a requirement is missing.
class FormOperation extends \Andora\Web\Component\Form
{
    use RequirementList;

    public $operation = "details";
    public $package = null;
    public $isAllowed = false;
    public $signature = null;
    public $requirements = array();

    public function formInit($options = null)
    {
        $this->operation = "details";
        if (!is_null($options) && array_key_exists("operation", $options) && in_array($options["operation"], Page::OPERATIONS, true)) {
            $this->operation = $options["operation"];
        }
        $this->package = null;
        $this->isAllowed = false;
        $this->requirements = array();
    }

    public function isRequirementMissing()
    {
        return in_array($this->operation, array("install", "upgrade"), true) && self::hasMissingRequirement($this->requirements);
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
                <?php $this->language->render("operation." . $this->operation); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            $package = $this->package;
            if (is_null($package)) {
                ?>
                <p class="text-error"><?php $this->language->render("package-not-found"); ?></p>
                <?php
            } else {
                ?>
                <h2 class="text-xl font-semibold"><?php echo htmlspecialchars("" . $package->title, ENT_QUOTES); ?></h2>
                <p class="text-base-content/70"><?php echo htmlspecialchars("" . $package->description, ENT_QUOTES); ?></p>
                <div class="flex flex-col gap-1 text-sm mt-2">
                    <?php
                    $this->renderLine("package", $package->code);
                    $this->renderLine("version", $package->version);
                    $this->renderLine("installed", $package->installedVersion);
                    $this->renderLine("state", $this->language->get("state." . $package->state));
                    if (!is_null($package->manifest)) {
                        $this->renderLine("license", $package->manifest["license"]);
                        $this->renderLine("author", $package->manifest["author"]);
                    }
                    $this->renderRequirements($this->requirements);
                    if (!is_null($package->manifest)) {
                        $this->renderLine("event.provide", implode(", ", array_keys($package->manifest["event"]["provide"])));
                        $this->renderLine("event.listen", implode(", ", array_keys($package->manifest["event"]["listen"])));
                    }
                    // the tables of the registry, else the Table classes of package.json
                    $tables = DataSourceRegistry::tablesOf($this->web, $package->code);
                    if ((count($tables) == 0) && !is_null($package->manifest)) {
                        $tables = $package->manifest["datasource"];
                    }
                    $this->renderLine("datasource", implode(", ", $tables));
                    if (strlen($package->error) > 0) {
                        $this->renderLine("error", $this->language->get($package->error));
                    }
                    if (count($package->backups) > 0) {
                        $this->renderLine("backup", $package->backups[0][1]);
                    }
                    if (!is_null($this->signature)) {
                        $this->renderLine("signature", $this->language->get("signature." . $this->signature->state) . ((strlen($this->signature->keyName) > 0) ? ": " . $this->signature->keyName : ""));
                    }
                    ?>
                </div>
                <?php
                if (($this->operation === "uninstall") && $this->isAllowed) {
                    ?>
                    <label class="label mt-2">
                        <input type="checkbox" name="keepData" value="1" class="checkbox checkbox-sm" />
                        <?php $this->language->render("keep-data"); ?>
                    </label>
                    <p class="text-error mt-2"><?php $this->language->render("uninstall-warning"); ?></p>
                    <?php
                }
                if (($this->operation === "restore") && $this->isAllowed) {
                    ?>
                    <p class="mt-2"><?php $this->language->render("restore-warning"); ?></p>
                    <?php
                }
                if ($this->isRequirementMissing()) {
                    ?>
                    <p class="text-error mt-2"><?php $this->language->render("requirements-not-met"); ?></p>
                    <?php
                } elseif (($this->operation !== "details") && !$this->isAllowed) {
                    ?>
                    <p class="text-error mt-2"><?php $this->language->render("not-available"); ?></p>
                    <?php
                }
                if (($this->operation === "reset") && $this->isAllowed) {
                    ?>
                    <p class="text-error mt-2"><?php $this->language->render("reset-warning"); ?></p>
                    <?php
                }
            }
            ?>
            <br />

            <div class="relative flex flex-wrap items-center justify-center gap-2">
                <button class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_cancel"><?php $this->language->render("close"); ?></button>
                <?php if (($this->operation !== "details") && $this->isAllowed) { ?>
                    <div class="ml-auto">
                        <button class="btn <?php echo in_array($this->operation, array("reset", "uninstall"), true) ? "btn-error" : "btn-primary"; ?>">
                            <?php $this->language->render("operation." . $this->operation . ".submit"); ?>
                        </button>
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
