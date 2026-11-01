<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \XYO\Web\DataSource\Order;

class DataTable extends \Andora\Web\Component\Form
{
    public $header = null;
    public $rows = null;
    public $descriptor = null;
    public $queryFn = null;
    public $query = null;
    public $sort = null;
    public $select = null;
    public $pageIndex = null;
    public $pagesCount = null;
    public $totalItemsCount = null;
    public $itemsCountSelect = null;
    public $itemsCountPerPage = null;
    public $type = null;
    public $sortIndex = null;
    public $sortType = null;
    public $datasource = null;
    public $componentList = null;
    public $primaryKeyIndex = null;
    public $rowIndex = null;
    public $headerClass = null;
    public $addFn = null;

    public function init($options = null)
    {
        parent::init($options);

        $this->header = array();
        $this->rows = array();
        $this->descriptor = array();
        $this->queryFn = function ($query) {
            return array(
                "rows" => $this->rows,
                "count" => $this->totalItemsCount
            );
        };

        if (is_null($options)) {
            return;
        }
        if (array_key_exists("header", $options)) {
            $this->header = $options["header"];
        }
        if (array_key_exists("rows", $options)) {
            $this->rows = $options["rows"];
        }
        if (array_key_exists("descriptor", $options)) {
            $this->descriptor = $options["descriptor"];
        }
        if (array_key_exists("datasource", $options)) {
            $this->datasource = $options["datasource"];
            $this->queryFn = function ($query) {
                return $this->processDatasource($query);
            };
        }
        if (array_key_exists("queryFn", $options)) {
            $this->queryFn = $options["queryFn"];
        }
        if (array_key_exists("addFn", $options)) {
            $this->addFn = $options["addFn"];
        }

        $this->query = new \stdClass();
        $this->query->select = array();
        $this->query->sort = array();
        $this->query->search = new \stdClass();
        $this->query->search->select = array();
        $this->query->search->text = "";
        $this->query->loadLast = false;
        $this->query->loadAll = false;
        $this->query->loadIndex = 0;
        $this->query->loadCount = 25;

        $this->select = array();
        $this->sort = array();
        $this->type = array();
        $this->sortIndex = 1;
        $this->sortType = 1;
        $this->componentList = array();
        $this->primaryKeyIndex = null;
        $this->rowIndex = array();
        $this->headerClass = array();
        if (!is_null($this->descriptor)) {
            $this->header = array();
            $sortIndex = 0;
            foreach ($this->descriptor as $key => $value) {
                $sortIndex = $sortIndex + 1;
                $this->header[$key] = $value["name"];
                $this->rowIndex[$key] = null;
                $this->headerClass[$key] = "";
                if (array_key_exists("select", $value)) {
                    if ($value["select"]) {
                        $this->rowIndex[$key] = count($this->query->select);
                        $this->query->select[] = $key;
                    }
                } else {
                    $this->rowIndex[$key] = count($this->query->select);
                    $this->query->select[] = $key;
                }
                if (array_key_exists("sort", $value)) {
                    $this->sortIndex = $sortIndex;
                    $this->sortType = 0;
                    if ($value["sort"] == "ascendent") {
                        $this->sortType = 1;
                        $this->query->sort[$key] = "ascendent";
                    }
                    if ($value["sort"] == "descendent") {
                        $this->sortType = 2;
                        $this->query->sort[$key] = "descendent";
                    }
                }
                if (array_key_exists("search", $value)) {
                    if ($value["search"]) {
                        $this->query->search->select[] = $key;
                    }
                }
                if (array_key_exists("class", $value)) {
                    $this->headerClass[$key] = $value["class"];
                }

                $this->sort[$sortIndex] = $key;
                $this->type[$key] = $value["type"];

                if ($value["type"] == "component") {
                    $this->componentList[$key] = $value["component"];
                }

                if (array_key_exists("primaryKey", $value)) {
                    if ($value["primaryKey"]) {
                        $this->primaryKeyIndex = $sortIndex - 1;
                    }
                }
            }
        }

        $this->pageIndex = 1;
        $this->pagesCount = 1;
        $this->totalItemsCount = 0;

        if (array_key_exists("search", $options)) {
            $this->query->search->text = $options["search"];
        }

        $this->itemsCountSelect = array(
            "10" => "10",
            "25" => "25",
            "50" => "50",
            "100" => "100",
            "*" => "all"
        );
        if (array_key_exists("itemsCountSelect", $options)) {
            $this->itemsCountSelect = $options["itemsCountSelect"];
        }
        $this->itemsCountPerPage = "25";
        if (array_key_exists("itemsCountPerPage", $options)) {
            $this->itemsCountPerPage = $options["itemsCountPerPage"];
        }

        LucideIcons::register($this, "icons");

        if ($this->isAJAX()) {
            if ($this->hasSelector()) {
                $info = explode("=", "" . $this->getSelector(), 2);
                if (count($info) == 2) {
                    $componentKey = $info[0];
                    $componentId = $info[1];
                    if (array_key_exists($componentKey, $this->componentList)) {
                        $this->componentList[$componentKey][0]::register($this, $componentId, $this->componentList[$componentKey][1]);
                    }
                }
            }
        }
    }

    public function process($options = null)
    {
        if ($this->isInit()) {
            return;
        }

        if (!$this->isPOST()) {
            $this->processQuery();
            return;
        }

        if ($this->hasError()) {
            return;
        }

        // ---

        $clickFirstPage = ($this->getElementValueNumber("firstPage", 0) > 0) ? true : false;
        $clickPreviousPage = ($this->getElementValueNumber("previousPage", 0) > 0) ? true : false;
        $clickNextPage = ($this->getElementValueNumber("nextPage", 0) > 0) ? true : false;
        $clickLastPage = ($this->getElementValueNumber("lastPage", 0) > 0) ? true : false;
        $pageIndex = $this->getElementValueNumber("pageIndex", 0);

        $pageIndex = intval($pageIndex);
        if ($clickPreviousPage) {
            $pageIndex = $pageIndex - 1;
        }
        if ($clickNextPage) {
            $pageIndex = $pageIndex + 1;
        }
        if ($pageIndex < 1) {
            $pageIndex = 1;
        }
        if ($clickFirstPage) {
            $pageIndex = 1;
        }
        if ($clickLastPage) {
            $this->query->loadLast = true;
        }

        // Clamped to the number of pages in processQuery, once the count of items is known
        $this->pageIndex = $pageIndex;

        // ---

        $itemsCountPerPage = $this->getElementValueString("itemsCountPerPage", $this->itemsCountPerPage);
        if (array_key_exists($itemsCountPerPage, $this->itemsCountSelect)) {
            $this->itemsCountPerPage = $itemsCountPerPage;
        }

        // ---

        $sortIndex = $this->getElementValueNumber("sortIndex", 0);
        $sortType = $this->getElementValueNumber("sortType", 0);

        if ($sortIndex >= count($this->sort)) {
            $sortIndex = count($this->sort);
        }
        if ($sortIndex < 1) {
            $sortIndex = 1;
        }

        $mode = "none";
        if ($sortType == 1) {
            $mode = "ascendent";
        } else
            if ($sortType == 2) {
                $mode = "descendent";
            }
        // Only data columns can be used to sort
        if (array_key_exists($sortIndex, $this->sort) && ($this->type[$this->sort[$sortIndex]] == "text")) {
            $this->query->sort = array($this->sort[$sortIndex] => $mode);
            $this->sortIndex = $sortIndex;
            $this->sortType = $sortType;
        }

        // ---

        $this->query->search->text = $this->getElementValueString("search", "");

        // ---            

        $this->processQuery();
    }

    public function processQuery()
    {
        if (strcmp("" . $this->itemsCountPerPage, "*") == 0) {
            $this->query->loadCount = 0;
            $this->query->loadAll = true;
        } else {
            $this->query->loadCount = intval($this->itemsCountPerPage);
            $this->query->loadAll = false;
            if ($this->query->loadCount < 1) {
                $this->query->loadCount = 25;
            }
        }

        if ($this->pageIndex < 1) {
            $this->pageIndex = 1;
        }
        if ($this->query->loadAll) {
            $this->pageIndex = 1;
        }
        $this->query->loadIndex = ($this->pageIndex - 1) * $this->query->loadCount;

        $result = ($this->queryFn)($this->query);
        $this->totalItemsCount = $result["count"];
        if ($this->query->loadAll) {
            $this->pagesCount = 1;
        } else {
            $this->pagesCount = max(1, intval(ceil($this->totalItemsCount / $this->query->loadCount)));
        }
        if ($this->query->loadLast) {
            $this->pageIndex = $this->pagesCount;
        } else if ($this->pageIndex > $this->pagesCount) {
            // The requested page is past the end, show the last page
            $this->pageIndex = $this->pagesCount;
            $this->query->loadIndex = ($this->pageIndex - 1) * $this->query->loadCount;
            $result = ($this->queryFn)($this->query);
            $this->totalItemsCount = $result["count"];
        }
        $this->rows = $result["rows"];
    }

    public function processDatasource($query)
    {
        $table = $this->getDataSource($this->datasource);
        $table->clear();
        $table->select($query->select);
        foreach ($query->sort as $key => $mode) {
            if ($mode == "ascendent") {
                $table->setOrder($key, Order::ASCENDENT);
            }
            if ($mode == "descendent") {
                $table->setOrder($key, Order::DESCENDENT);
            }
        }

        if (strlen($query->search->text) > 0) {
            if (count($query->search->select) > 0) {
                $table->pushOperator("and");
                $searchMultiple = count($query->search->select) > 1;
                if ($searchMultiple) {
                    $table->pushOperator("(");
                }
                $orOp = false;
                foreach ($query->search->select as $key) {
                    if ($orOp) {
                        $table->pushOperator("or");
                    }
                    $table->setOperator($key, "like", $query->search->text);
                    $orOp = true;
                }
                if ($searchMultiple) {
                    $table->pushOperator(")");
                }
            }
        }

        $rows = array();
        $count = $table->count();

        if ($query->loadAll) {
            $table->load();
        } else
            if ($query->loadLast) {
                $table->load(max(0, intval(ceil($count / $query->loadCount)) - 1) * $query->loadCount, $query->loadCount);
            } else {
                $table->load($query->loadIndex, $query->loadCount);
            }

        for (; $table->loadIsValid(); $table->loadNext()) {
            $row = array();
            foreach ($query->select as $key) {
                $row[] = $table->$key;
            }
            $rows[] = $row;
        }

        $result = array();
        $result["rows"] = $rows;
        $result["count"] = $count;

        return $result;
    }

    public function renderAJAX($options = null)
    {
        $this->renderFormAJAX(function () {
            ?>
            <input type="submit" value="" class="hidden" />

            <div class="p-4 flex flex-wrap items-center gap-4 text-sm text-base-content/80">
                <label class="input">
                    <?php $this->renderComponent("icons", array("icon" => "search", "class" => "text-base")); ?>
                    <input type="search" class="grow" placeholder="Search" name="search"
                        value="<?php echo htmlspecialchars("" . $this->query->search->text, ENT_QUOTES); ?>" />
                </label>
                <?php if (!is_null($this->addFn)) { ?>
                    <div class="btn btn-sm btn-square ml-auto" title="Add" id="<?php echo $this->id; ?>_add">
                        <?php $this->renderComponent("icons", array("icon" => "plus", "class" => "text-base")); ?>
                    </div>
                <?php } ?>
            </div>

            <table class="table overflow-visible">
                <?php if (count($this->header) > 0) { ?>
                    <!-- head -->
                    <thead>
                        <tr>
                            <?php

                            $sortIndex = 0;
                            $tdCount = count($this->header);
                            foreach ($this->header as $key => $cell) {
                                $sortIndex = $sortIndex + 1;
                                if ($sortIndex == 1) {
                                    $tdClass = "rounded-tl border-t border-l border-base-300 bg-base-200";
                                } else
                                    if ($tdCount == $sortIndex) {
                                        $tdClass = "rounded-tr border-t border-r border-base-300 bg-base-200";
                                    } else {
                                        $tdClass = "border-t border-base-300 bg-base-200";
                                    }

                                $tdClass = $tdClass . " " . $this->headerClass[$key];
                                ?>

                                <?php if ($this->type[$key] == "selector") { ?>
                                    <th id="<?php echo $this->id; ?>_header_selector" class="w-4 <?php echo $tdClass; ?>">
                                        <input type="checkbox" class="checkbox" id="<?php echo $this->id; ?>_check_all" />
                                    </th>
                                    <?php continue;
                                } ?>

                                <?php if ($this->type[$key] == "component") { ?>
                                    <th class="<?php echo $tdClass; ?>">
                                        <?php echo "<span>" . $cell . "</span>"; ?>
                                    </th>
                                    <?php continue;
                                } ?>

                                <th id="<?php echo $this->id; ?>_header_<?php echo $sortIndex; ?>"
                                    class="cursor-pointer <?php echo $tdClass; ?>">
                                    <div class="flex flex-row items-center h-6">
                                        <?php
                                        echo "<span>" . $cell . "</span>";
                                        if (($this->sortIndex == $sortIndex) && ($this->sortType == 1)) { ?>
                                            <?php $this->renderComponent("icons", array("icon" => "chevron-down", "class" => "text-base ml-3 h-6 w-4")); ?>
                                        <?php } else
                                            if (($this->sortIndex == $sortIndex) && ($this->sortType == 2)) { ?>
                                                <?php $this->renderComponent("icons", array("icon" => "chevron-up", "class" => "text-base ml-3 h-6 w-4")); ?>
                                            <?php } else { ?>
                                                <div class="h-6 w-4 ml-3"></div>
                                            <?php }
                                        ?>
                                    </div>
                                </th>

                            <?php } ?>
                        </tr>
                    </thead>
                <?php } ?>
                <?php if (count($this->rows) > 0) { ?>
                    <tbody>
                        <?php
                        $rowIndex = 0;
                        $rowCount = count($this->rows);
                        foreach ($this->rows as $row) {
                            $rowIndex = $rowIndex + 1;
                            ?>
                            <!-- row 1 -->
                            <tr class="group">
                                <?php
                                $cellIndex = 0;
                                foreach ($this->header as $key => $value_) {
                                    $cellIndex = $cellIndex + 1;

                                    $tdClass = "";
                                    if ($cellIndex == 1) {
                                        $tdClass = "border-l border-base-300";
                                    } else
                                        if ($cellIndex == $tdCount) {
                                            $tdClass = "border-r border-base-300";
                                        }

                                    if ($rowCount == $rowIndex) {
                                        $tdClass = "border-b border-base-300";
                                        if ($cellIndex == 1) {
                                            $tdClass = "rounded-bl border-l border-b border-base-300";
                                        } else
                                            if ($cellIndex == $tdCount) {
                                                $tdClass = "rounded-br border-r border-b border-base-300";
                                            }
                                    }

                                    $cell = "";
                                    if (!is_null($this->rowIndex[$key])) {
                                        $cell = $row[$this->rowIndex[$key]];
                                    }
                                    ?>
                                    <td class="group-hover:bg-base-200  <?php echo $tdClass; ?>">
                                        <?php if ($this->type[$key] == "selector") { ?>

                                            <input type="checkbox" class="checkbox" name="selector[]" value="<?php echo htmlspecialchars("" . $cell, ENT_QUOTES); ?>"
                                                id="<?php echo $this->id; ?>_check_<?php echo $rowIndex; ?>" />

                                            <?php continue;
                                        } ?>

                                        <?php if ($this->type[$key] == "component") {
                                            if (!is_null($this->primaryKeyIndex)) {
                                                // The primary key value, by its position in the selected columns
                                                $primaryKey = $this->sort[$this->primaryKeyIndex + 1];
                                                $primaryKeyValue = $row[$this->rowIndex[$primaryKey]];
                                                $componentId = $key . "_" . $primaryKeyValue;
                                                $this->setSelector($key . "=" . $componentId);
                                                $component = $this->componentList[$key][0]::registerAndInit($this, $componentId, $this->componentList[$key][1]);
                                                $component->render(array(
                                                    "primaryKey" => array($primaryKey, $primaryKeyValue),
                                                    "value" => array($key, $cell)
                                                ));
                                            }
                                            continue;
                                        } ?>

                                        <?php echo htmlspecialchars("" . $cell, ENT_QUOTES); ?>

                                    </td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                <?php } ?>
            </table>


            <div class="p-4 bg-base-100 flex flex-wrap items-center gap-4 text-sm text-base-content/80">

                <!-- Navigation Controls -->
                <div class="flex items-center gap-1">
                    <button class="btn btn-ghost btn-sm btn-square" title="First Page"
                        id="<?php echo $this->id; ?>_firstPageButton">
                        <?php $this->renderComponent("icons", array("icon" => "chevron-first", "class" => "text-base")); ?>
                    </button>
                    <button class="btn btn-ghost btn-sm btn-square" title="Previous Page"
                        id="<?php echo $this->id; ?>_previousPageButton">
                        <?php $this->renderComponent("icons", array("icon" => "chevron-left", "class" => "text-base")); ?>
                    </button>

                    <!-- Page Input Box -->
                    <div class="mx-1">
                        <input type="number" value="<?php echo $this->pageIndex; ?>" class="input input-sm w-14 text-center"
                            name="pageIndex" id="<?php echo $this->id; ?>_pageIndex" min="1" />
                    </div>

                    <button class="btn btn-ghost btn-sm btn-square" title="Next Page" id="<?php echo $this->id; ?>_nextPageButton">
                        <?php $this->renderComponent("icons", array("icon" => "chevron-right", "class" => "text-base")); ?>
                    </button>
                    <button class="btn btn-ghost btn-sm btn-square" title="Last Page" id="<?php echo $this->id; ?>_lastPageButton">
                        <?php $this->renderComponent("icons", array("icon" => "chevron-last", "class" => "text-base")); ?>
                    </button>
                </div>

                <!-- Page Size Selector -->
                <div class="flex items-center gap-2">
                    <select class="select select-sm" name="itemsCountPerPage" id="<?php echo $this->id; ?>_itemsCountPerPage">
                        <?php
                        foreach ($this->itemsCountSelect as $key => $value) {
                            echo "<option value=\"" . $key . "\"";
                            if ($key == $this->itemsCountPerPage) {
                                echo " selected=\"selected\"";
                            }
                            echo ">" . $value . "</option>";
                        }
                        ?>
                    </select>
                    <span>items</span>
                </div>

                <!-- Stats Divider -->
                <div class="hidden sm:block h-4 w-[1px] bg-base-300 mx-2"></div>

                <!-- Results Summary -->
                <div class="flex items-center gap-1 tracking-tight">
                    <span class="font-medium text-base-content"><?php echo $this->pagesCount; ?></span>
                    <span class="opacity-70">pages -</span>
                    <span class="font-medium text-base-content"><?php echo $this->totalItemsCount; ?></span>
                    <span class="opacity-70">total items</span>
                </div>

            </div>

            <input type="hidden" name="firstPage" value="0" id="<?php echo $this->id; ?>_firstPage" />
            <input type="hidden" name="previousPage" value="0" id="<?php echo $this->id; ?>_previousPage" />
            <input type="hidden" name="nextPage" value="0" id="<?php echo $this->id; ?>_nextPage" />
            <input type="hidden" name="lastPage" value="0" id="<?php echo $this->id; ?>_lastPage" />
            <input type="hidden" name="sortIndex" value="<?php echo $this->sortIndex; ?>" id="<?php echo $this->id; ?>_sortIndex" />
            <input type="hidden" name="sortType" value="<?php echo $this->sortType; ?>" id="<?php echo $this->id; ?>_sortType" />
            <?php
        });

        $this->view->renderJS(function () {
            echo "document.getElementById(\"" . $this->id . "_itemsCountPerPage\").addEventListener(\"change\",function(e){";
            echo "e.preventDefault();";
            $this->formRenderJSRequest();
            echo "});";
            echo "document.getElementById(\"" . $this->id . "_firstPageButton\").addEventListener(\"click\",function(e){";
            echo "e.preventDefault();";
            echo "document.getElementById(\"" . $this->id . "_firstPage\").value=\"1\";";
            $this->formRenderJSRequest();
            echo "});";
            echo "document.getElementById(\"" . $this->id . "_previousPageButton\").addEventListener(\"click\",function(e){";
            echo "e.preventDefault();";
            echo "document.getElementById(\"" . $this->id . "_previousPage\").value=\"1\";";
            $this->formRenderJSRequest();
            echo "});";
            echo "document.getElementById(\"" . $this->id . "_nextPageButton\").addEventListener(\"click\",function(e){";
            echo "e.preventDefault();";
            echo "document.getElementById(\"" . $this->id . "_nextPage\").value=\"1\";";
            $this->formRenderJSRequest();
            echo "});";
            echo "document.getElementById(\"" . $this->id . "_lastPageButton\").addEventListener(\"click\",function(e){";
            echo "e.preventDefault();";
            echo "document.getElementById(\"" . $this->id . "_lastPage\").value=\"1\";";
            $this->formRenderJSRequest();
            echo "});";

            $sortIndex = 0;
            foreach ($this->header as $cell) {
                $sortIndex = $sortIndex + 1;
                if ($this->type[$this->sort[$sortIndex]] == "selector") {
                    continue;
                };
                if ($this->type[$this->sort[$sortIndex]] == "component") {
                    continue;
                };
                $sortMode = 0;
                if ($this->sortIndex == $sortIndex) {
                    $sortMode = $this->sortType;
                }
                $sortType = 1;
                if ($sortMode == 1) {
                    $sortType = 2;
                }
                if ($sortMode == 2) {
                    $sortType = 1;
                }
                echo "document.getElementById(\"" . $this->id . "_header_" . $sortIndex . "\").addEventListener(\"click\",function(e){";
                echo "e.preventDefault();";
                echo "document.getElementById(\"" . $this->id . "_sortIndex\").value=\"" . $sortIndex . "\";";
                echo "document.getElementById(\"" . $this->id . "_sortType\").value=\"" . $sortType . "\";";
                $this->formRenderJSRequest();
                echo "});";
            }

            $hasSelector = false;
            foreach ($this->header as $key => $cell) {
                if ($this->type[$key] == "selector") {
                    $hasSelector = true;
                }
            }
            if ($hasSelector) {
                echo "document.getElementById(\"" . $this->id . "_check_all\").addEventListener(\"click\",function(e){";
                echo "var value=document.getElementById(\"" . $this->id . "_check_all\").checked;";
                echo "var id=[0";

                $rowIndex = 0;
                foreach ($this->rows as $row) {
                    $rowIndex = $rowIndex + 1;
                    echo "," . $rowIndex;
                }

                echo "];";
                echo "var prefix=\"" . $this->id . "_check_\";";
                echo "for(var i in id){";
                echo "if(id[i]>0){";
                echo "document.getElementById(prefix+id[i]).checked=value;";
                echo "}";
                echo "}";
                echo "});";
            }
        });

        if (!is_null($this->addFn)) {
            $this->view->renderJS(function () { ?>
                <script>
                    document.getElementById("<?php echo $this->id; ?>_add").addEventListener("click", function (e) {
                        e.preventDefault();
                        <?php ($this->addFn)(); ?>
                    });
                </script>
            <?php });
        }
    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"overflow-visible\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }

}

