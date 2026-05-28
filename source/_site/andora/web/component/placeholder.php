<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class Placeholder extends \XYO\Web\Component
{

    public function renderAJAX($options = null)
    {
        ?>
        <div class="flex w-52 flex-col gap-4">
            <div class="flex items-center gap-4">
                <div class="skeleton h-16 w-16 shrink-0 rounded-full"></div>
                <div class="flex flex-col gap-4">
                    <div class="skeleton h-4 w-20"></div>
                    <div class="skeleton h-4 w-28"></div>
                </div>
            </div>
            <div class="skeleton h-32 w-full"></div>
        </div>
        <?php
    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full flex flex-col content-center items-center\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
