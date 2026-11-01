<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\User;

// Avatar picker: choose an image, move it (drag) and zoom it (slider / mouse wheel)
// inside a square, like the xyo-cloud form-image. The browser crops it to a
// User::AVATAR_SIZE PNG sent as a data: URL in the "<name>" field (empty = no change),
// "<name>_delete" is 1 to remove the avatar. The file itself is never sent.
class InputAvatar extends \XYO\Web\Component
{
    public $name = null;
    public $legend = null;
    public $url = null;
    public $value = null;
    public $delete = null;

    public function init($options = null)
    {
        $this->name = $options["name"];
        $this->legend = null;
        if (array_key_exists("legend", $options)) {
            $this->legend = $options["legend"];
        }
        $this->url = null;
        if (array_key_exists("url", $options)) {
            $this->url = $options["url"];
        }

        $this->value = "";
        $this->delete = false;
        $this->parent->value->{$this->name} = $this->value;
        $this->parent->value->{$this->name . "_delete"} = $this->delete;

        LucideIcons::register($this, "icons");
    }

    public function process($options = null)
    {
        if ($this->parent->isInit()) {
            return;
        }
        if ($this->isPOST()) {
            $this->getValue();
            $this->parent->value->{$this->name} = $this->value;
            $this->parent->value->{$this->name . "_delete"} = $this->delete;
        }
    }

    public function getValue()
    {
        $value = $this->parent->getElementValue($this->name, "");
        $this->value = is_string($value) ? trim($value) : "";
        $this->delete = ($this->parent->getElementValueString($this->name . "_delete", "0") === "1");
    }

    public function render($options = null)
    {
        $this->loadLanguageFromPath("./_site/andora/web/component", "input-avatar");

        if (is_null($this->legend)) {
            $this->legend = $this->language->get($this->name . ".legend");
        }

        $hasError = $this->parent->elementHasError($this->name);
        $error = $this->parent->getElementError($this->name);

        ?>

        <fieldset class="fieldset w-full">
            <legend class="fieldset-legend text-base"><?php echo $this->legend; ?></legend>
            <div class="flex flex-col items-center gap-3">
                <div class="relative size-48 rounded-box overflow-hidden border <?php echo $hasError ? "border-error" : "border-base-300"; ?> bg-base-200 cursor-grab active:cursor-grabbing touch-none select-none"
                    id="<?php echo $this->id; ?>_frame">
                    <canvas width="<?php echo User::AVATAR_SIZE; ?>" height="<?php echo User::AVATAR_SIZE; ?>"
                        class="size-full" id="<?php echo $this->id; ?>_canvas"></canvas>
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none" id="<?php echo $this->id; ?>_empty">
                        <?php $this->renderComponent("icons", array("icon" => "user-round", "class" => "text-8xl text-base-content/20")); ?>
                    </div>
                </div>
                <div class="flex items-center gap-2 w-48">
                    <?php $this->renderComponent("icons", array("icon" => "zoom-out", "class" => "text-base text-base-content/60")); ?>
                    <input type="range" min="1" max="4" step="0.01" value="1" class="range range-xs flex-1"
                        aria-label="<?php $this->language->render("zoom"); ?>" id="<?php echo $this->id; ?>_zoom" disabled />
                    <?php $this->renderComponent("icons", array("icon" => "zoom-in", "class" => "text-base text-base-content/60")); ?>
                </div>
                <div class="flex flex-wrap justify-center gap-2">
                    <label class="btn btn-sm btn-outline" for="<?php echo $this->id; ?>_file">
                        <?php $this->renderComponent("icons", array("icon" => "upload", "class" => "text-base")); ?>
                        <?php $this->language->render("choose-image"); ?>
                    </label>
                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden"
                        id="<?php echo $this->id; ?>_file" />
                    <button type="button" class="btn btn-sm btn-ghost text-error" id="<?php echo $this->id; ?>_remove">
                        <?php $this->renderComponent("icons", array("icon" => "trash-2", "class" => "text-base")); ?>
                        <?php $this->language->render("remove"); ?>
                    </button>
                </div>
                <p class="label text-center whitespace-normal">
                    <?php $this->language->render("hint"); ?>
                </p>
                <?php if ($hasError) { ?>
                    <p class="text-error text-sm text-center" id="<?php echo $this->id; ?>_error">
                        <?php $this->language->render(is_string($error) ? "error." . $error : "error.invalid-image"); ?>
                    </p>
                <?php } ?>
            </div>
            <input type="hidden" name="<?php echo $this->name; ?>" value="" id="<?php echo $this->id; ?>_value" />
            <input type="hidden" name="<?php echo $this->name; ?>_delete" value="0" id="<?php echo $this->id; ?>_delete" />
        </fieldset>

        <?php

        $this->view->renderJS(function () {
            ?>
            <script>
                (function () {
                    var id = <?php echo json_encode($this->id); ?>;
                    var url = <?php echo json_encode($this->url); ?>;
                    var size = <?php echo User::AVATAR_SIZE; ?>;
                    // Only the cropped image is sent, this just avoids decoding huge files
                    var maxBytes = 16777216;
                    var tooLarge = <?php echo json_encode($this->language->get("error.image-too-large")); ?>;
                    var invalidImage = <?php echo json_encode($this->language->get("error.invalid-image")); ?>;
                    var canvas = document.getElementById(id + "_canvas");
                    if (!canvas) {
                        return;
                    }
                    var context = canvas.getContext("2d");
                    var frame = document.getElementById(id + "_frame");
                    var empty = document.getElementById(id + "_empty");
                    var zoom = document.getElementById(id + "_zoom");
                    var file = document.getElementById(id + "_file");
                    var remove = document.getElementById(id + "_remove");
                    var value = document.getElementById(id + "_value");
                    var del = document.getElementById(id + "_delete");
                    var image = null, base = 1, scale = 1, x = 0, y = 0, changed = false, drag = null, timer = null;

                    function width() { return image.width * base * scale; }
                    function height() { return image.height * base * scale; }

                    function draw() {
                        context.clearRect(0, 0, size, size);
                        if (!image) {
                            empty.classList.remove("hidden");
                            return;
                        }
                        empty.classList.add("hidden");
                        x = Math.min(0, Math.max(size - width(), x));
                        y = Math.min(0, Math.max(size - height(), y));
                        context.imageSmoothingEnabled = true;
                        context.imageSmoothingQuality = "high";
                        context.drawImage(image, x, y, width(), height());
                    }

                    function store() {
                        if (image && changed) {
                            value.value = canvas.toDataURL("image/png");
                        }
                    }

                    function storeLater() {
                        clearTimeout(timer);
                        timer = setTimeout(store, 250);
                    }

                    function setImage(newImage) {
                        image = newImage;
                        base = Math.max(size / image.width, size / image.height);
                        scale = 1;
                        zoom.value = 1;
                        zoom.disabled = false;
                        x = (size - width()) / 2;
                        y = (size - height()) / 2;
                        draw();
                    }

                    function setScale(newScale) {
                        if (!image) {
                            return;
                        }
                        newScale = Math.min(Number(zoom.max), Math.max(1, newScale));
                        var cx = (size / 2 - x) / width();
                        var cy = (size / 2 - y) / height();
                        scale = newScale;
                        zoom.value = scale;
                        x = size / 2 - cx * width();
                        y = size / 2 - cy * height();
                        changed = true;
                        draw();
                    }

                    function showError(message) {
                        var el = document.getElementById(id + "_error");
                        if (!el) {
                            el = document.createElement("p");
                            el.id = id + "_error";
                            el.className = "text-error text-sm text-center";
                            frame.parentNode.appendChild(el);
                        }
                        el.textContent = message;
                    }

                    if (url) {
                        var current = new Image();
                        current.onload = function () { setImage(current); };
                        current.src = url;
                    }
                    draw();

                    file.addEventListener("change", function () {
                        var selected = file.files && file.files[0];
                        file.value = "";
                        if (!selected) {
                            return;
                        }
                        if (selected.size > maxBytes) {
                            showError(tooLarge);
                            return;
                        }
                        var objectURL = URL.createObjectURL(selected);
                        var newImage = new Image();
                        newImage.onload = function () {
                            URL.revokeObjectURL(objectURL);
                            var el = document.getElementById(id + "_error");
                            if (el) {
                                el.remove();
                            }
                            setImage(newImage);
                            changed = true;
                            del.value = "0";
                            store();
                        };
                        newImage.onerror = function () {
                            URL.revokeObjectURL(objectURL);
                            showError(invalidImage);
                        };
                        newImage.src = objectURL;
                    });

                    remove.addEventListener("click", function () {
                        image = null;
                        changed = false;
                        value.value = "";
                        del.value = "1";
                        zoom.disabled = true;
                        zoom.value = 1;
                        draw();
                    });

                    zoom.addEventListener("input", function () {
                        setScale(Number(zoom.value));
                    });
                    zoom.addEventListener("change", store);

                    frame.addEventListener("wheel", function (e) {
                        if (!image) {
                            return;
                        }
                        e.preventDefault();
                        setScale(scale * (e.deltaY < 0 ? 1.05 : 1 / 1.05));
                        storeLater();
                    }, { passive: false });

                    frame.addEventListener("pointerdown", function (e) {
                        if (!image) {
                            return;
                        }
                        drag = { x: e.clientX, y: e.clientY };
                        frame.setPointerCapture(e.pointerId);
                    });
                    frame.addEventListener("pointermove", function (e) {
                        if (!drag) {
                            return;
                        }
                        var ratio = size / frame.clientWidth;
                        x += (e.clientX - drag.x) * ratio;
                        y += (e.clientY - drag.y) * ratio;
                        drag = { x: e.clientX, y: e.clientY };
                        changed = true;
                        draw();
                    });
                    function endDrag() {
                        if (drag) {
                            drag = null;
                            store();
                        }
                    }
                    frame.addEventListener("pointerup", endDrag);
                    frame.addEventListener("pointercancel", endDrag);
                })();
            </script>
            <?php
        });
    }

}
