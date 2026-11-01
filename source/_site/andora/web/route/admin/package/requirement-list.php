<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Package;

defined("ANDORA") or die("Forbidden");

// The requirements of a package (Manager::requirements) in the details and
// confirm screens: each one with what this site has, the missing ones in red
trait RequirementList
{
    protected function renderRequirements($requirements)
    {
        if (count($requirements) == 0) {
            return;
        }
        ?>
        <div class="flex gap-2">
            <span class="w-28 shrink-0 text-base-content/60"><?php $this->language->render("require"); ?></span>
            <span class="flex flex-col break-all">
                <?php foreach ($requirements as $item) { ?>
                    <?php if ($item->isOk) { ?>
                        <span><?php echo htmlspecialchars($item->name, ENT_QUOTES); ?> <span class="text-success">(<?php echo htmlspecialchars((strlen($item->current) > 0) ? $item->current : $this->language->get("require.ok"), ENT_QUOTES); ?>)</span></span>
                    <?php } else { ?>
                        <span class="text-error"><?php echo htmlspecialchars($item->name, ENT_QUOTES); ?> (<?php echo htmlspecialchars((strlen($item->current) > 0) ? $this->language->get("require.found") . " " . $item->current : $this->language->get("require.missing"), ENT_QUOTES); ?>)</span>
                    <?php } ?>
                <?php } ?>
            </span>
        </div>
        <?php
    }

    // Not every requirement is met
    protected static function hasMissingRequirement($requirements)
    {
        foreach ($requirements as $item) {
            if (!$item->isOk) {
                return true;
            }
        }
        return false;
    }
}
