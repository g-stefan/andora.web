<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Admin\Dashboard;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\DataTable;

class Page extends \XYO\Web\Page
{
    protected $form = null;

    public function init($options = null)
    {
        $this->setTitle("Admin - Dashboard");
        Dashboard::setApplicationTitle("Dashboard");

        DataTable::register($this, "datatable", array(
            "descriptor" => array(
                "id" => array(
                    "name" => "",
                    "type" => "selector"
                ),
                "name" => array(
                    "name" => "Name",
                    "type" => "text",
                    "sort" => "ascendent"
                ),
                "job" => array(
                    "name" => "Job",
                    "type" => "text"
                ),
                "color" => array(
                    "name" => "Favorite Color",
                    "type" => "text"
                )
            ),
            "queryFn" => function ($query) {
                return array(
                    "rows" => array(
                        array("1", "Cy Ganderton", "Quality Control Specialist", "Blue"),
                        array("2", "Hart Hagerty", "Desktop Support Technician", "Purple"),
                        array("3", "Brice Swyre", "Tax Accountant", "Red")
                    ),
                    "count" => 50
                );
            }
        ));

        DataTable::register($this, "datatable2", array(
            "descriptor" => array(
                "id" => array(
                    "name" => "",
                    "type" => "selector"
                ),
                "name" => array(
                    "name" => "Name",
                    "type" => "text",
                    "sort" => "ascendent"
                ),
                "job" => array(
                    "name" => "Job",
                    "type" => "text"
                ),
                "color" => array(
                    "name" => "Favorite Color",
                    "type" => "text"
                )
            ),
            "queryFn" => function ($query) {
                return array(
                    "rows" => array(
                        array("1", "Cy Ganderton 2", "Quality Control Specialist", "Blue"),
                        array("2", "Hart Hagerty 2", "Desktop Support Technician", "Purple"),
                        array("3", "Brice Swyre 2", "Tax Accountant", "Red")
                    ),
                    "count" => 50
                );
            }
        ));

    }

    public function render($options = null)
    { ?>


        <!-- Stats Cards Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl">

            <!-- Card 1: Total Revenue -->
            <div class="border border-base-200 rounded-xl p-6 shadow-sm flex flex-col transition-all hover:shadow-md">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-sm font-medium text-base-content/60">Total Revenue</h3>
                    <div
                        class="flex items-center gap-1.5 border border-base-200 px-2 py-0.5 rounded-full text-[11px] font-semibold text-base-content shadow-sm">
                        <i data-lucide="trending-up" class="w-3 h-3 text-base-content/80"></i>
                        +12.5%
                    </div>
                </div>

                <div class="text-4xl font-bold tracking-tight text-base-content mb-4">$1,250.00</div>

                <div class="flex flex-col gap-1.5 mt-auto">
                    <div class="text-[13px] font-medium text-base-content/90 flex items-center gap-1.5">
                        Trending up this month
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-base-content/70"></i>
                    </div>
                    <div class="text-xs text-base-content/50">
                        Visitors for the last 6 months
                    </div>
                </div>
            </div>

            <!-- Card 2: New Customers -->
            <div class="border border-base-200 rounded-xl p-6 shadow-sm flex flex-col transition-all hover:shadow-md">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-sm font-medium text-base-content/60">New Customers</h3>
                    <div
                        class="flex items-center gap-1.5 border border-base-200 px-2 py-0.5 rounded-full text-[11px] font-semibold text-base-content shadow-sm">
                        <i data-lucide="trending-down" class="w-3 h-3 text-base-content/80"></i>
                        -20%
                    </div>
                </div>

                <div class="text-4xl font-bold tracking-tight text-base-content mb-4">1,234</div>

                <div class="flex flex-col gap-1.5 mt-auto">
                    <div class="text-[13px] font-medium text-base-content/90 flex items-center gap-1.5">
                        Down 20% this period
                        <i data-lucide="arrow-down-right" class="w-3.5 h-3.5 text-base-content/70"></i>
                    </div>
                    <div class="text-xs text-base-content/50">
                        Acquisition needs attention
                    </div>
                </div>
            </div>

        </div>

        <br />
        <?php $this->renderComponent("datatable"); ?>
        <br />
        <?php $this->renderComponent("datatable2"); ?>
        <br /><br /><br /><br /><br /><br /><br /><br />

    <?php }

}

return Page::class;
