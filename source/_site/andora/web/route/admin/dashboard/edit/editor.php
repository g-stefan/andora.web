<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Dashboard\Edit;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\Acl;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Package\Manager;
use \Andora\Web\Component\DashboardPanel;

// The dashboard of the current user in edit mode: the panels in their
// order (drag and drop or the arrows to move them), their size, remove;
// the panels that can be added; reset to the default panels.
// Every change is an AJAX post to this component (action, panel, size,
// order), saved at once (Dashboard::saveLayout), then the editor is shown again.
class Editor extends \XYO\Web\Component
{
    protected $userId = 0;
    protected $panels = array();
    protected $layout = array();
    protected $isCustom = false;
    protected $alert = null;

    public function init($options = null)
    {
        $this->userId = Acl::currentUserId();
        LucideIcons::register($this, "icons");
    }

    protected function loadData()
    {
        $this->panels = Dashboard::panels($this->web);
        $this->layout = Dashboard::layout($this->web, $this->userId, $this->panels);
        $this->isCustom = Dashboard::isCustom($this->web, $this->userId);
    }

    protected function requestString($name)
    {
        $value = $this->request->get($name, "");
        return is_string($value) ? $value : "";
    }

    protected function indexOf($panelId)
    {
        foreach ($this->layout as $index => $item) {
            if ($item["panel"] === $panelId) {
                return $index;
            }
        }
        return -1;
    }

    // Change the layout as the request asks, then save it
    protected function processAction()
    {
        $action = $this->requestString("action");
        $panelId = $this->requestString("panel");
        $index = $this->indexOf($panelId);
        $layout = $this->layout;

        if ($action === "reset") {
            if (!Dashboard::resetLayout($this->web, $this->userId)) {
                $this->alert = "save-error";
            }
            return;
        } else if ($action === "add") {
            if (!array_key_exists($panelId, $this->panels) || ($index >= 0)) {
                return;
            }
            $layout[] = array("panel" => $panelId, "size" => $this->panels[$panelId]->size);
        } else if ($action === "remove") {
            if ($index < 0) {
                return;
            }
            array_splice($layout, $index, 1);
        } else if ($action === "size") {
            if ($index < 0) {
                return;
            }
            $layout[$index]["size"] = Manager::panelSize($this->requestString("size"));
        } else if (($action === "move-left") || ($action === "move-right")) {
            $to = $index + (($action === "move-left") ? -1 : 1);
            if (($index < 0) || ($to < 0) || ($to >= count($layout))) {
                return;
            }
            $item = $layout[$index];
            $layout[$index] = $layout[$to];
            $layout[$to] = $item;
        } else if ($action === "order") {
            // the same panels in a new order
            $order = explode(",", $this->requestString("order"));
            $current = array_column($layout, "panel");
            if ((count($order) != count($current)) || (count(array_diff($current, $order)) > 0) || (count(array_unique($order)) != count($order))) {
                $this->alert = "order-changed";
                return;
            }
            $sizes = array_column($layout, "size", "panel");
            $layout = array();
            foreach ($order as $id) {
                $layout[] = array("panel" => $id, "size" => $sizes[$id]);
            }
        } else {
            return;
        }

        if (!Dashboard::saveLayout($this->web, $this->userId, $layout, $this->panels)) {
            $this->alert = "save-error";
        }
    }

    protected function text($value)
    {
        return htmlspecialchars("" . $value, ENT_QUOTES);
    }

    protected function renderPanel($index, $item)
    {
        $panel = $this->panels[$item["panel"]];
        $last = count($this->layout) - 1;
        ?>
        <div class="card bg-base-100 border border-base-300 cursor-move select-none <?php echo DashboardPanel::SIZE_CLASS[$item["size"]]; ?>"
            draggable="true" data-panel="<?php echo $this->text($panel->id); ?>">
            <div class="card-body p-4 gap-2">
                <div class="flex items-center gap-2">
                    <?php $this->renderComponent("icons", array("icon" => "grip-vertical", "class" => "text-lg text-base-content/50")); ?>
                    <?php if (strlen($panel->icon) > 0) {
                        $this->renderComponent("icons", array("icon" => $panel->icon, "class" => "text-lg text-base-content/70"));
                    } ?>
                    <h2 class="card-title text-base truncate"><?php echo $this->text($panel->title); ?></h2>
                    <button type="button" class="btn btn-sm btn-ghost btn-square text-error hover:bg-error/10 ml-auto"
                        data-action="remove" data-panel="<?php echo $this->text($panel->id); ?>"
                        title="<?php echo $this->text($this->language->get("remove")); ?>">
                        <?php $this->renderComponent("icons", array("icon" => "x", "class" => "text-base")); ?>
                    </button>
                </div>
                <?php if (strlen($panel->description) > 0) { ?>
                    <p class="text-sm text-base-content/70"><?php echo $this->text($panel->description); ?></p>
                <?php } ?>
                <p class="text-xs font-mono text-base-content/50"><?php echo $this->text($panel->code); ?></p>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-xs text-base-content/70"><?php $this->language->render("size"); ?></span>
                    <div class="join">
                        <?php for ($size = 1; $size <= 3; $size++) { ?>
                            <button type="button" class="btn btn-xs join-item<?php echo ($size == $item["size"]) ? " btn-primary" : ""; ?>"
                                data-action="size" data-panel="<?php echo $this->text($panel->id); ?>" data-size="<?php echo $size; ?>"
                                title="<?php echo $this->text($this->language->get("size-" . $size)); ?>"><?php echo $size; ?></button>
                        <?php } ?>
                    </div>
                    <div class="join ml-auto">
                        <button type="button" class="btn btn-xs join-item" data-action="move-left" data-panel="<?php echo $this->text($panel->id); ?>"
                            title="<?php echo $this->text($this->language->get("move-left")); ?>" <?php echo ($index == 0) ? "disabled" : ""; ?>>
                            <?php $this->renderComponent("icons", array("icon" => "arrow-left", "class" => "text-sm")); ?>
                        </button>
                        <button type="button" class="btn btn-xs join-item" data-action="move-right" data-panel="<?php echo $this->text($panel->id); ?>"
                            title="<?php echo $this->text($this->language->get("move-right")); ?>" <?php echo ($index == $last) ? "disabled" : ""; ?>>
                            <?php $this->renderComponent("icons", array("icon" => "arrow-right", "class" => "text-sm")); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    protected function renderEditor()
    {
        $shown = array_column($this->layout, "panel");
        $available = array();
        foreach ($this->panels as $id => $panel) {
            if (!in_array($id, $shown, true)) {
                $available[] = $panel;
            }
        }
        ?>
        <div class="flex flex-col gap-6" id="<?php echo $this->id; ?>_body">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-base-content/70 text-sm"><?php $this->language->render("help"); ?></p>
                <div class="flex gap-2 ml-auto">
                    <button type="button" class="btn btn-sm" data-action="reset" id="dashboard-reset" <?php echo $this->isCustom ? "" : "disabled"; ?>>
                        <?php $this->renderComponent("icons", array("icon" => "rotate-ccw", "class" => "text-base")); ?>
                        <span><?php $this->language->render("reset"); ?></span>
                    </button>
                    <a class="btn btn-sm btn-neutral" href="<?php echo $this->site; ?>admin/dashboard" id="dashboard-done"><?php $this->language->render("done"); ?></a>
                </div>
            </div>

            <?php if (!$this->isCustom) { ?>
                <p class="text-sm text-base-content/70" id="dashboard-default"><?php $this->language->render("default-panels"); ?></p>
            <?php } ?>

            <?php if (!is_null($this->alert)) { ?>
                <div role="alert" class="alert alert-warning">
                    <?php $this->renderComponent("icons", array("icon" => "triangle-alert", "class" => "text-xl")); ?>
                    <span><?php $this->language->render($this->alert); ?></span>
                </div>
            <?php } ?>

            <?php if (count($this->layout) > 0) { ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="<?php echo $this->id; ?>_grid">
                    <?php foreach ($this->layout as $index => $item) {
                        $this->renderPanel($index, $item);
                    } ?>
                </div>
            <?php } else { ?>
                <p class="text-base-content/70 text-center py-8 border border-dashed border-base-300 rounded-box" id="dashboard-edit-empty">
                    <?php $this->language->render("no-panels"); ?>
                </p>
            <?php } ?>

            <section class="flex flex-col gap-2" id="dashboard-available">
                <h2 class="text-lg font-semibold"><?php $this->language->render("available"); ?></h2>
                <?php if (count($available) == 0) { ?>
                    <p class="text-base-content/70 text-sm"><?php $this->language->render("no-available"); ?></p>
                <?php } ?>
                <?php foreach ($available as $panel) { ?>
                    <div class="flex items-center gap-3 border border-base-300 rounded-box px-3 py-2" data-available="<?php echo $this->text($panel->id); ?>">
                        <?php if (strlen($panel->icon) > 0) {
                            $this->renderComponent("icons", array("icon" => $panel->icon, "class" => "text-lg text-base-content/70"));
                        } ?>
                        <div class="flex flex-col min-w-0">
                            <span class="font-semibold truncate"><?php echo $this->text($panel->title); ?></span>
                            <?php if (strlen($panel->description) > 0) { ?>
                                <span class="text-sm text-base-content/70"><?php echo $this->text($panel->description); ?></span>
                            <?php } ?>
                            <span class="text-xs font-mono text-base-content/50"><?php echo $this->text($panel->code); ?></span>
                        </div>
                        <button type="button" class="btn btn-sm ml-auto" data-action="add" data-panel="<?php echo $this->text($panel->id); ?>">
                            <?php $this->renderComponent("icons", array("icon" => "plus", "class" => "text-base")); ?>
                            <span><?php $this->language->render("add"); ?></span>
                        </button>
                    </div>
                <?php } ?>
            </section>
        </div>
        <?php
        $this->renderEditorJS();
    }

    // The buttons (data-action) and the drag and drop of the panels; on the
    // elements of this render, the next render replaces them
    protected function renderEditorJS()
    {
        $this->view->renderJS(function () {
            ?>
            <script>
                (function () {
                    var body = document.getElementById(<?php echo json_encode($this->id . "_body"); ?>);
                    var grid = document.getElementById(<?php echo json_encode($this->id . "_grid"); ?>);
                    if (!body) {
                        return;
                    }
                    var send = function (action, panel, size, order) {
                        <?php $this->renderJSRequestPost(null, array("action" => "action", "panel" => "panel", "size" => "size", "order" => "order")); ?>
                    };
                    body.addEventListener("click", function (event) {
                        var button = event.target.closest("[data-action]");
                        if (!button || !body.contains(button) || button.disabled) {
                            return;
                        }
                        var action = button.getAttribute("data-action");
                        if ((action === "reset") && !window.confirm(<?php echo json_encode($this->language->get("reset-confirm")); ?>)) {
                            return;
                        }
                        send(action, button.getAttribute("data-panel") || "", button.getAttribute("data-size") || "", "");
                    });
                    if (!grid) {
                        return;
                    }
                    var order = function () {
                        var list = [];
                        for (var k = 0; k < grid.children.length; k++) {
                            list.push(grid.children[k].getAttribute("data-panel"));
                        }
                        return list.join(",");
                    };
                    var dragged = null;
                    var orderBefore = "";
                    grid.addEventListener("dragstart", function (event) {
                        var card = event.target.closest("[data-panel]");
                        if (!card || (card.parentNode !== grid)) {
                            return;
                        }
                        dragged = card;
                        orderBefore = order();
                        event.dataTransfer.effectAllowed = "move";
                        event.dataTransfer.setData("text/plain", card.getAttribute("data-panel"));
                        card.classList.add("opacity-50");
                    });
                    grid.addEventListener("dragover", function (event) {
                        if (!dragged) {
                            return;
                        }
                        event.preventDefault();
                        event.dataTransfer.dropEffect = "move";
                        var card = event.target.closest("[data-panel]");
                        if (!card || (card === dragged) || (card.parentNode !== grid)) {
                            return;
                        }
                        if (dragged.compareDocumentPosition(card) & Node.DOCUMENT_POSITION_FOLLOWING) {
                            card.after(dragged);
                        } else {
                            card.before(dragged);
                        }
                    });
                    grid.addEventListener("drop", function (event) {
                        if (dragged) {
                            event.preventDefault();
                        }
                    });
                    grid.addEventListener("dragend", function () {
                        if (!dragged) {
                            return;
                        }
                        dragged.classList.remove("opacity-50");
                        dragged = null;
                        var orderAfter = order();
                        if (orderAfter !== orderBefore) {
                            send("order", "", "", orderAfter);
                        }
                    });
                })();
            </script>
            <?php
        });
    }

    public function renderAJAX($options = null)
    {
        $this->loadData();
        if ($this->isPOST()) {
            $this->processAction();
            $this->loadData();
        }
        $this->renderEditor();
    }

    public function renderContainer($options = null)
    {
        $this->loadData();
        ?>
        <div class="w-full" id="<?php echo $this->id; ?>">
            <?php $this->renderEditor(); ?>
        </div>
        <?php
    }

}
