<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class MessageError extends \XYO\Web\Component
{
    public $title = null;
    public $message = null;
    public $reason = null;
    public $reloadAfterTimeout = null;
    public $redirectAfterTimeout = null;
    // A Dialog: the message stays open until its Close button, with a Copy
    // button for the text (an error that must be read or reported)
    public $close = null;
    public $code = "";
    public $closeLabel = "Close";
    public $copyLabel = "Copy";
    public $copiedLabel = "Copied";

    public function init($options = null)
    {

        $this->title = null;
        if (array_key_exists("title", $options)) {
            $this->title = $options["title"];
        }

        $this->message = null;
        if (array_key_exists("message", $options)) {
            $this->message = $options["message"];
        }

        $this->reloadAfterTimeout = null;
        if (array_key_exists("reloadAfterTimeout", $options)) {
            $this->reloadAfterTimeout = $options["reloadAfterTimeout"];
        }

        $this->redirectAfterTimeout = null;
        if (array_key_exists("redirectAfterTimeout", $options)) {
            $this->redirectAfterTimeout = $options["redirectAfterTimeout"];
        }

        $this->reason = "unknown";
        if (array_key_exists("reason", $options)) {
            $this->reason = $options["reason"];
        }

        $this->close = $options["close"] ?? null;
        $this->code = "" . ($options["code"] ?? "");
        foreach (array("closeLabel", "copyLabel", "copiedLabel") as $name) {
            if (array_key_exists($name, $options)) {
                $this->$name = $options[$name];
            }
        }

        LucideIcons::register($this, "icons");
    }

    public function renderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <?php if (!is_null($this->title)) { ?>
                <h1 class="text-4xl font-bold"><?php echo $this->title; ?></h1>
            <?php } ?>
            <?php if (!is_null($this->message)) { ?>
                <p class="mt-3 text-lg text-base-content/70">
                    <?php echo $this->message; ?>
                </p>
            <?php } ?>
            <p class="mt-2 text-lg text-error-content break-all select-text" id="<?php echo $this->id; ?>_reason">
                <?php echo htmlspecialchars("" . $this->reason, ENT_QUOTES); ?>
            </p>
            <?php if (strlen($this->code) > 0) { ?>
                <p class="mt-1 text-sm font-mono text-base-content/60 break-all select-text" id="<?php echo $this->id; ?>_code"><?php echo htmlspecialchars($this->code, ENT_QUOTES); ?></p>
            <?php } ?>
        </div>

        <?php $this->renderComponent("icons", array("icon" => "x", "class" => "bg-error text-error-content text-8xl rounded-full self-center")); ?>

        <?php

        if (!is_null($this->close)) {
            $this->renderButtons();
        }

        if (!is_null($this->reloadAfterTimeout)) {
            $this->view->renderJS(function () {
                echo "setTimeout(function(){";
                $this->renderJSRequestGet();
                echo "}," . $this->reloadAfterTimeout . ");";
            });
        }

        if (!is_null($this->redirectAfterTimeout)) {
            $this->view->renderJS(function () {
                echo "setTimeout(function(){";
                $this->redirectAfterTimeout[0]->renderJSRequestGet();
                echo "}," . $this->redirectAfterTimeout[1] . ");";
            });
        }

    }

    // Copy (the title, the message, the reason and its code) and Close
    protected function renderButtons()
    {
        $text = trim(implode("
", array_filter(array(strip_tags("" . $this->title), strip_tags("" . $this->message), "" . $this->reason, $this->code), "strlen")));
        ?>
        <div class="relative flex flex-wrap items-center justify-center gap-2 mt-8">
            <button type="button" class="btn btn-ghost mr-2" id="<?php echo $this->id; ?>_copy"><?php echo htmlspecialchars($this->copyLabel, ENT_QUOTES); ?></button>
            <div class="ml-auto">
                <button type="button" class="btn btn-primary" id="<?php echo $this->id; ?>_close"><?php echo htmlspecialchars($this->closeLabel, ENT_QUOTES); ?></button>
            </div>
        </div>
        <?php
        $this->view->renderJS(function () use ($text) {
            ?>
            <script>
                document.getElementById("<?php echo $this->id; ?>_close").addEventListener("click", function (event) {
                    <?php $this->close->renderJSClose(); ?>
                    event.preventDefault();
                });
                document.getElementById("<?php echo $this->id; ?>_copy").addEventListener("click", function (event) {
                    event.preventDefault();
                    var button = event.currentTarget;
                    var text = <?php echo json_encode($text); ?>;
                    var copied = function () {
                        button.textContent = <?php echo json_encode($this->copiedLabel); ?>;
                    };
                    // navigator.clipboard needs https or localhost
                    var fallback = function () {
                        var area = document.createElement("textarea");
                        area.value = text;
                        area.setAttribute("readonly", "");
                        area.style.position = "fixed";
                        area.style.opacity = "0";
                        button.parentNode.appendChild(area);
                        area.select();
                        try {
                            if (document.execCommand("copy")) {
                                copied();
                            }
                        } catch (e) {
                        }
                        area.remove();
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(copied, fallback);
                    } else {
                        fallback();
                    }
                });
            </script>
            <?php
        });
    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}

