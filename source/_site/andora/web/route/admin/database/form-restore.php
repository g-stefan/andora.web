<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Database;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manager;
use \Andora\Web\Package\DatabaseBackup;

// What an uploaded backup holds and what its restore does: every table of
// the backup, or the tables of one package of it. The page fills $backup,
// $plan (null: refused, $reason / $detail), $installed (onRestoreInit) and
// restores (onRestoreProcess).
class FormRestore extends \Andora\Web\Component\Form
{
    public $backup = null;
    public $plan = null;
    public $reason = "";
    public $detail = "";
    // code => installed version of the packages of the backup
    public $installed = array();

    public function formInit($options = null)
    {
        $this->backup = null;
        $this->plan = null;
        $this->reason = "";
        $this->detail = "";
        $this->installed = array();
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
                <?php $this->language->render("import-backup"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            $isAllowed = !is_null($this->backup) && !is_null($this->plan);
            if (is_null($this->backup)) {
                ?>
                <p class="text-error"><?php echo htmlspecialchars($this->language->get((strlen($this->reason) > 0) ? $this->reason : "import-not-found"), ENT_QUOTES); ?></p>
                <?php
            } else {
                $scope = ($this->backup->scope === DatabaseBackup::ALL) ? $this->language->get("scope.all") : $this->backup->scope;
                ?>
                <div class="flex flex-col gap-1 text-sm">
                    <?php
                    $this->renderLine("scope", $scope);
                    $this->renderLine("created", $this->backup->created);
                    $this->renderLine("andora", $this->backup->andora);
                    $this->renderLine("database", $this->backup->database);
                    foreach ($this->installed as $code => $version) {
                        $this->renderLine("package", $code . " " . ($this->backup->packages[$code] ?? "-") . " (" . $this->language->get("installed") . ": " . ((strlen($version) > 0) ? $version : "-") . ")");
                    }
                    ?>
                </div>
                <?php
                if (!$isAllowed) {
                    ?>
                    <p class="text-error mt-2"><?php echo htmlspecialchars($this->language->get($this->reason) . ((strlen($this->detail) > 0) ? ": " . $this->detail : ""), ENT_QUOTES); ?></p>
                    <?php
                } else {
                    ?>
                    <div class="overflow-x-auto max-h-60 mt-2">
                        <table class="table table-xs">
                            <thead>
                                <tr>
                                    <th><?php $this->language->render("table"); ?></th>
                                    <th><?php $this->language->render("rows"); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->plan as $item) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item->name, ENT_QUOTES); ?></td>
                                        <td><?php echo intval($item->rows); ?></td>
                                        <td class="<?php echo ($item->action === "restore") ? "" : "text-base-content/50"; ?>"><?php $this->language->render("action." . $item->action); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    $packages = DatabaseBackup::backupPackages($this->backup);
                    if (count($packages) > 1) {
                        ?>
                        <fieldset class="fieldset">
                            <legend class="fieldset-legend"><?php $this->language->render("restore-select"); ?></legend>
                            <select name="package" class="select w-full">
                                <option value="<?php echo DatabaseBackup::ALL; ?>"><?php $this->language->render("restore.all"); ?></option>
                                <?php foreach ($packages as $code) { ?>
                                    <option value="<?php echo htmlspecialchars(Manager::idFromCode($code), ENT_QUOTES); ?>"><?php echo htmlspecialchars($code, ENT_QUOTES); ?></option>
                                <?php } ?>
                            </select>
                        </fieldset>
                        <?php
                    }
                    ?>
                    <p class="text-warning mt-2"><?php $this->language->render("restore-warning"); ?></p>
                    <label class="label mt-2 whitespace-normal items-start">
                        <input type="checkbox" name="confirm" value="1" class="checkbox checkbox-sm checkbox-warning" required />
                        <span><?php $this->language->render("restore-confirm"); ?></span>
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
                        <button class="btn btn-error"><?php $this->language->render("restore"); ?></button>
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
        echo "<div class=\"w-full max-w-[480px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }

}
