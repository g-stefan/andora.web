<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

class MessageOk extends \XYO\Web\Component
{
    public $title = null;
    public $message = null;
    public $reloadAfterTimeout = null;
    public $redirectAfterTimeout = null;

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
        </div>

        <?php $this->renderComponent("icons", array("icon" => "check", "class" => "bg-success text-success-content text-8xl rounded-full self-center")); ?>

        <?php

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

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
