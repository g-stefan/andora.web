<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package\Catalog;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Package\Manager;
use \Andora\Web\Package\Registry;

// The registries and their packages. The page renders only a placeholder
// that posts to this component when it is loaded: the indexes are fetched
// in that AJAX request (POST, CSRF token), never in another one. With
// cached=1 (the refresh after an operation) the indexes fetched then are used.
class Catalog extends \XYO\Web\Component
{
    protected $isFileOperationAllowed = false;

    public function init($options = null)
    {
        $this->isFileOperationAllowed = $options["isFileOperationAllowed"] ?? false;
    }

    public function renderContainer($options = null)
    {
        ?>
        <div class="flex flex-col gap-6" id="<?php echo $this->id; ?>">
            <div class="flex items-center gap-2 text-base-content/70">
                <span class="loading loading-spinner loading-sm"></span>
                <span><?php $this->language->render("loading"); ?></span>
            </div>
        </div>
        <?php
        $this->view->renderJS(function () {
            ?>
            <script>
                (function () {
                    var load = function () {
                        <?php $this->renderJSRequestPost(); ?>
                    };
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", load);
                    } else {
                        load();
                    }
                })();
            </script>
            <?php
        });
    }

    protected function text($value)
    {
        return htmlspecialchars("" . $value, ENT_QUOTES);
    }

    public function renderAJAX($options = null)
    {
        if (!$this->isPOST()) {
            return;
        }
        $useCache = $this->request->get("cached", "") === "1";
        $registries = Registry::all($this->web);
        $added = Registry::added();

        // name => packages or the error
        $result = array();
        foreach ($registries as $name => $url) {
            $packages = $useCache ? Registry::cached($this->web, $name) : null;
            $reason = "";
            if (is_null($packages)) {
                $packages = Registry::fetch($this->web, $name, $reason);
            }
            $result[$name] = array(
                "url" => $url,
                "packages" => $packages,
                "error" => is_null($packages) ? $this->language->get($reason) . ((strlen(Manager::$detail) > 0) ? ": " . Manager::$detail : "") : ""
            );
        }
        ?>
        <section class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold"><?php $this->language->render("registries"); ?></h2>
                <button class="btn btn-sm btn-ghost ml-auto" id="<?php echo $this->id; ?>_add"><?php $this->language->render("registry-add"); ?></button>
            </div>
            <?php if (count($registries) == 0) { ?>
                <p class="text-base-content/70" id="catalog-not-configured"><?php $this->language->render("not-configured"); ?></p>
            <?php } ?>
            <?php foreach ($result as $name => $registry) { ?>
                <div class="flex flex-wrap items-center gap-2 border border-base-300 rounded-box px-3 py-2 text-sm" data-registry="<?php echo $this->text($name); ?>">
                    <span class="font-semibold"><?php echo $this->text($name); ?></span>
                    <span class="text-base-content/60 break-all"><?php echo $this->text($registry["url"]); ?></span>
                    <?php if ($name === Registry::OFFICIAL) { ?>
                        <span class="badge badge-sm"><?php $this->language->render("official"); ?></span>
                    <?php } ?>
                    <span class="ml-auto <?php echo is_null($registry["packages"]) ? "text-error" : "text-base-content/70"; ?>">
                        <?php echo is_null($registry["packages"]) ? $this->text($registry["error"]) : $this->text(count($registry["packages"]) . " " . $this->language->get("packages")); ?>
                    </span>
                    <?php if (array_key_exists($name, $added)) { ?>
                        <button class="btn btn-xs btn-ghost text-error" data-registry-remove="<?php echo $this->text($name); ?>"><?php $this->language->render("remove"); ?></button>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php
        $rows = array();
        foreach ($result as $name => $registry) {
            foreach (is_array($registry["packages"]) ? $registry["packages"] : array() as $entry) {
                $info = Manager::get($this->web, $entry["code"]);
                $rows[] = array(
                    "registry" => $name,
                    "entry" => $entry,
                    "installed" => (!is_null($info) && $info->isInstalled) ? $info->installedVersion : "",
                    "action" => Registry::action($this->web, $entry)
                );
            }
        }
        if (count($registries) > 0) {
            ?>
            <section class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold"><?php $this->language->render("packages.title"); ?></h2>
                <?php if (!$this->isFileOperationAllowed) { ?>
                    <p class="text-warning text-sm"><?php $this->language->render("file-operations-disabled"); ?></p>
                <?php } ?>
                <?php if (count($rows) == 0) { ?>
                    <p class="text-base-content/70"><?php $this->language->render("no-packages"); ?></p>
                <?php } else { ?>
                    <div class="overflow-x-auto">
                        <table class="table table-sm" id="catalog-packages">
                            <thead>
                                <tr>
                                    <th><?php $this->language->render("package"); ?></th>
                                    <th><?php $this->language->render("version"); ?></th>
                                    <th><?php $this->language->render("installed"); ?></th>
                                    <th><?php $this->language->render("registry"); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row) {
                                    $entry = $row["entry"];
                                    ?>
                                    <tr data-package="<?php echo $this->text($entry["code"]); ?>" data-action="<?php echo $this->text($row["action"]); ?>">
                                        <td>
                                            <div class="font-semibold"><?php echo $this->text($entry["title"]); ?></div>
                                            <div class="font-mono text-xs text-base-content/60"><?php echo $this->text($entry["code"]); ?></div>
                                            <div class="text-base-content/70"><?php echo $this->text($entry["description"]); ?></div>
                                        </td>
                                        <td><?php echo $this->text($entry["version"]); ?></td>
                                        <td><?php echo $this->text($row["installed"]); ?></td>
                                        <td><?php echo $this->text($row["registry"]); ?></td>
                                        <td class="text-right">
                                            <?php if ((($row["action"] === "install") || ($row["action"] === "upgrade")) && $this->isFileOperationAllowed) { ?>
                                                <button class="btn btn-sm btn-primary" data-catalog-registry="<?php echo $this->text($row["registry"]); ?>" data-catalog-package="<?php echo $this->text(Manager::idFromCode($entry["code"])); ?>"><?php $this->language->render("catalog-action." . $row["action"]); ?></button>
                                            <?php } else { ?>
                                                <span class="text-base-content/60"><?php $this->language->render("catalog-action." . $row["action"]); ?></span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </section>
            <?php
        }
        $this->view->renderJS(function () {
            ?>
            <script>
                (function () {
                    var root = document.getElementById(<?php echo json_encode($this->id); ?>);
                    document.getElementById(<?php echo json_encode($this->id . "_add"); ?>).addEventListener("click", function (event) {
                        event.preventDefault();
                        catalogRegistryAdd();
                    });
                    root.querySelectorAll("[data-registry-remove]").forEach(function (element) {
                        element.addEventListener("click", function (event) {
                            event.preventDefault();
                            catalogRegistryRemove(element.getAttribute("data-registry-remove"));
                        });
                    });
                    root.querySelectorAll("[data-catalog-package]").forEach(function (element) {
                        element.addEventListener("click", function (event) {
                            event.preventDefault();
                            catalogInstall(element.getAttribute("data-catalog-registry"), element.getAttribute("data-catalog-package"));
                        });
                    });
                })();
            </script>
            <?php
        });
    }
}
