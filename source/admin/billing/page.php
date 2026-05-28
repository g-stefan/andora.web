<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Admin\Billing;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\DataSource\TableBilling;
use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\DataTable;

class Page extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->setTitle("Admin - Billing");
        Dashboard::setApplicationTitle("Billing");

        DataTable::register($this, "datatable", array(
            "descriptor" => array(
                "id" => array(
                    "type" => "selector",
                    "name" => "",
                    "primaryKey" => true
                ),
                "type" => array(
                    "type" => "text",
                    "name" => "Type",
                    "sort" => "ascendent"
                ),
                "series" => array(
                    "type" => "text",
                    "name" => "Series"
                ),
                "number" => array(
                    "type" => "text",
                    "name" => "Number"
                ),
                "amount" => array(
                    "type" => "text",
                    "name" => "Amount"
                ),
                "currency" => array(
                    "type" => "text",
                    "name" => "Currency"
                ),
                "status" => array(
                    "type" => "text",
                    "name" => "Status"
                ),
                "date" => array(
                    "type" => "text",
                    "name" => "Date"
                )
            ),
            "datasource" => TableBilling::class,
            "has-search" => false,
            "has-add" => false
        ));
    }

    public function render($options = null)
    { ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl mb-6">
            <!-- Payment Methods -->
            <div class="border border-base-200 rounded-xl p-6 shadow-sm flex flex-col transition-all hover:shadow-md">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-sm font-medium text-base-content/60">Payment Methods</h3>
                    <div
                        class="flex items-center gap-1.5 border border-base-200 px-2 py-0.5 rounded-full text-[11px] font-semibold text-base-content shadow-sm">
                        <i data-lucide="credit-card" class="w-3 h-3 text-base-content/80"></i>
                        Active
                    </div>
                </div>

                <div class="flex flex-col gap-3 mt-2">
                    <button class="btn btn-outline flex items-center justify-center gap-2">
                        <i data-lucide="smartphone" class="w-5 h-5"></i>
                        Pay with Google Pay
                    </button>
                    <button class="btn btn-outline flex items-center justify-center gap-2">
                        <i data-lucide="smartphone" class="w-5 h-5"></i>
                        Pay with Apple Pay
                    </button>
                    <button class="btn btn-outline flex items-center justify-center gap-2">
                        <i data-lucide="credit-card" class="w-5 h-5"></i>
                        Pay with Credit Card
                    </button>
                </div>
            </div>

            <!-- Billing Summary -->
            <div class="border border-base-200 rounded-xl p-6 shadow-sm flex flex-col transition-all hover:shadow-md">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-sm font-medium text-base-content/60">Current Balance</h3>
                </div>

                <div class="text-4xl font-bold tracking-tight text-base-content mb-4">$0.00</div>

                <div class="flex flex-col gap-1.5 mt-auto">
                    <div class="text-[13px] font-medium text-base-content/90 flex items-center gap-1.5">
                        Next invoice due in 15 days
                    </div>
                    <div class="text-xs text-base-content/50">
                        Your account is in good standing.
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h3 class="text-lg font-semibold mb-2">Invoices & Receipts</h3>
            <?php $this->renderComponent("datatable"); ?>
        </div>

    <?php }
}

return Page::class;
