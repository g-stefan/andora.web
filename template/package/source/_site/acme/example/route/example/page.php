<?php
// Example package
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Acme\Example\Route\Example;

defined("ANDORA") or die("Forbidden");

use \Acme\Example\DataSource\TableItem;
use \XYO\Web\DataSource\Order;

// /example: the enabled items, for everybody. The route is mounted from
// _site/acme/example/route/example (package.json "route"), the language
// files are in its language/ folder.
class Page extends \XYO\Web\Page
{
    protected $items = array();

    public function init($options = null)
    {
        $this->setTitle($this->language->get("page.title"));

        $dsItem = $this->getDataSource(TableItem::class);
        $dsItem->clear();
        $dsItem->enabled = 1;
        $dsItem->setOrder("name", Order::ASCENDENT);
        for ($dsItem->load(0, 100); $dsItem->loadIsValid(); $dsItem->loadNext()) {
            $this->items[] = array("" . $dsItem->name, "" . $dsItem->description);
        }
    }

    public function render($options = null)
    {
        ?>
        <main class="mx-auto max-w-2xl p-8 flex flex-col gap-4">
            <h1 class="text-4xl font-bold"><?php echo htmlspecialchars($this->language->get("page.title"), ENT_QUOTES); ?></h1>
            <?php if (count($this->items) == 0) { ?>
                <p class="text-base-content/70"><?php echo htmlspecialchars($this->language->get("no-items"), ENT_QUOTES); ?></p>
            <?php } ?>
            <ul class="flex flex-col gap-2">
                <?php foreach ($this->items as $item) { ?>
                    <li class="card bg-base-200 p-4">
                        <h2 class="font-semibold"><?php echo htmlspecialchars($item[0], ENT_QUOTES); ?></h2>
                        <p><?php echo htmlspecialchars($item[1], ENT_QUOTES); ?></p>
                    </li>
                <?php } ?>
            </ul>
        </main>
        <?php
    }
}

return Page::class;
