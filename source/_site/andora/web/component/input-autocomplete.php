<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;

// Search input with an autocomplete list: the matches show after the
// first minChars characters, pageSize items at a time, with a
// first / previous / next / last pager. The chosen id is written into a
// hidden field "name". The results are shown in the page flow (not as an
// overlay), so they are fully visible inside a dialog.
//
// The list (id => name) is searched in the browser when it has at most
// clientMaxItems items (default 49, 7 pages of 7). A longer list is not
// sent to the browser: each search / page change is an AJAX request to
// this component, answered with one page of matches. The request goes
// through the parent components like any component request, so the
// parent builds the list again (with its own access rules) before the
// search; an Andora Form parent is marked as init for it, so it does not
// process (validate / save) the search request as a submit.
//
// No validation is done here (like InputSelect would), the form reads
// the hidden field and checks the id itself.
class InputAutocomplete extends \XYO\Web\Component
{
    public $name = null;
    public $legend = null;
    public $placeholder = null;
    public $list = null;
    public $minChars = 3;
    public $pageSize = 7;
    public $clientMaxItems = 49;

    public function init($options = null)
    {
        LucideIcons::register($this, "icons");

        $this->name = $options["name"];
        $this->legend = null;
        if (array_key_exists("legend", $options)) {
            $this->legend = $options["legend"];
        }
        $this->placeholder = null;
        if (array_key_exists("placeholder", $options)) {
            $this->placeholder = $options["placeholder"];
        }
        $this->list = array();
        if (array_key_exists("list", $options)) {
            if ($options["list"]) {
                $this->list = $options["list"];
            }
        }
        $this->minChars = 3;
        if (array_key_exists("minChars", $options)) {
            $this->minChars = max(0, intval($options["minChars"]));
        }
        $this->pageSize = 7;
        if (array_key_exists("pageSize", $options)) {
            $this->pageSize = max(1, intval($options["pageSize"]));
        }
        $this->clientMaxItems = 49;
        if (array_key_exists("clientMaxItems", $options)) {
            $this->clientMaxItems = max(0, intval($options["clientMaxItems"]));
        }
    }

    public function process($options = null)
    {
        // A search request is not a submit of the parent form.
        if ($this->isComponentAJAX() && ($this->parent instanceof Form)) {
            $this->parent->setIsInit(true);
        }
    }

    public function isServerMode()
    {
        return (count($this->list) > $this->clientMaxItems);
    }

    public function render($options = null)
    {
        if ($this->isComponentAJAX()) {
            $this->renderAJAX($options);
            return;
        }

        $this->loadLanguageFromPath("./_site/andora/web/component", "input-autocomplete");

        if (is_array($options)) {
            if (array_key_exists("legend", $options)) {
                $this->legend = $options["legend"];
            }
            if (array_key_exists("placeholder", $options)) {
                $this->placeholder = $options["placeholder"];
            }
        }
        if (is_null($this->legend)) {
            $this->legend = $this->language->get($this->name . ".legend");
        }
        if (is_null($this->placeholder)) {
            $this->placeholder = $this->language->get($this->name . ".placeholder");
        }

        $serverMode = $this->isServerMode();
        $items = array();
        if (!$serverMode) {
            foreach ($this->list as $key => $value) {
                $items[] = array("" . $key, "" . $value);
            }
        }

        $text = array(
            "typeMore" => str_replace("{count}", $this->minChars, $this->language->get("type-at-least")),
            "notFound" => $this->language->get("no-items-found"),
            "searching" => $this->language->get("searching"),
            "searchError" => $this->language->get("search-error"),
            "range" => $this->language->get("items-range"),
            "page" => $this->language->get("page-of")
        );

        ?>

        <fieldset class="fieldset w-full">
            <legend class="fieldset-legend text-base"><?php echo htmlspecialchars($this->legend, ENT_QUOTES); ?></legend>
            <label class="input w-full">
                <?php $this->renderComponent("icons", array("icon" => "search", "class" => "text-base opacity-50")); ?>
                <input type="search" id="<?php echo $this->id; ?>_search" class="grow" autocomplete="off"
                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                    aria-controls="<?php echo $this->id; ?>_list"
                    placeholder="<?php echo htmlspecialchars($this->placeholder, ENT_QUOTES); ?>" />
            </label>
            <input type="hidden" name="<?php echo $this->name; ?>" id="<?php echo $this->id; ?>_value" value="" />
            <p class="text-sm text-base-content/60" id="<?php echo $this->id; ?>_hint"></p>
            <div class="hidden rounded-box border border-base-300 bg-base-100" id="<?php echo $this->id; ?>_panel">
                <ul class="menu w-full p-1 transition-opacity" role="listbox" id="<?php echo $this->id; ?>_list"></ul>
                <div class="flex items-center gap-1 border-t border-base-300 px-2 py-1 text-sm text-base-content/70"
                    id="<?php echo $this->id; ?>_footer">
                    <span class="mr-auto" id="<?php echo $this->id; ?>_range"></span>
                    <div class="flex items-center gap-1" id="<?php echo $this->id; ?>_pager">
                        <button type="button" class="btn btn-ghost btn-xs btn-square" id="<?php echo $this->id; ?>_first"
                            title="<?php echo htmlspecialchars($this->language->get("first-page"), ENT_QUOTES); ?>">
                            <?php $this->renderComponent("icons", array("icon" => "chevron-first", "class" => "text-base")); ?>
                        </button>
                        <button type="button" class="btn btn-ghost btn-xs btn-square" id="<?php echo $this->id; ?>_previous"
                            title="<?php echo htmlspecialchars($this->language->get("previous-page"), ENT_QUOTES); ?>">
                            <?php $this->renderComponent("icons", array("icon" => "chevron-left", "class" => "text-base")); ?>
                        </button>
                        <span class="px-1 whitespace-nowrap" id="<?php echo $this->id; ?>_page"></span>
                        <button type="button" class="btn btn-ghost btn-xs btn-square" id="<?php echo $this->id; ?>_next"
                            title="<?php echo htmlspecialchars($this->language->get("next-page"), ENT_QUOTES); ?>">
                            <?php $this->renderComponent("icons", array("icon" => "chevron-right", "class" => "text-base")); ?>
                        </button>
                        <button type="button" class="btn btn-ghost btn-xs btn-square" id="<?php echo $this->id; ?>_last"
                            title="<?php echo htmlspecialchars($this->language->get("last-page"), ENT_QUOTES); ?>">
                            <?php $this->renderComponent("icons", array("icon" => "chevron-last", "class" => "text-base")); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php if ($serverMode) { ?>
                <div class="hidden" id="<?php echo $this->id; ?>_ajax"></div>
            <?php } ?>
        </fieldset>

        <?php

        $this->view->renderJS(function () use ($items, $text, $serverMode) {
            ?>
            <script>
                (function () {
                    var id = <?php echo json_encode($this->id); ?>;
                    var serverMode = <?php echo $serverMode ? "true" : "false"; ?>;
                    var items = <?php echo $this->toJSON($items); ?>;
                    var text = <?php echo $this->toJSON($text); ?>;
                    var minChars = <?php echo intval($this->minChars); ?>;
                    var pageSize = <?php echo intval($this->pageSize); ?>;

                    var search = document.getElementById(id + "_search");
                    var value = document.getElementById(id + "_value");
                    var hint = document.getElementById(id + "_hint");
                    var panel = document.getElementById(id + "_panel");
                    var list = document.getElementById(id + "_list");
                    var footer = document.getElementById(id + "_footer");
                    var range = document.getElementById(id + "_range");
                    var pager = document.getElementById(id + "_pager");
                    var pageInfo = document.getElementById(id + "_page");
                    var btnFirst = document.getElementById(id + "_first");
                    var btnPrevious = document.getElementById(id + "_previous");
                    var btnNext = document.getElementById(id + "_next");
                    var btnLast = document.getElementById(id + "_last");
                    if (!search || !value || !panel || !list) {
                        return;
                    }

                    // The matches of the browser search (client mode).
                    var found = [];
                    // The shown page: its items, the page index and the count of all matches.
                    var pageItems = [];
                    var page = 0;
                    var total = 0;
                    var query = "";
                    var active = -1;
                    // Server mode: only the answer of the last request is shown.
                    var sequence = 0;
                    var timer = null;

                    function pagesCount() {
                        return Math.max(1, Math.ceil(total / pageSize));
                    }

                    function format(str, map) {
                        return str.replace(/\{(\w+)\}/g, function (m, key) {
                            return (key in map) ? map[key] : m;
                        });
                    }

                    function open() {
                        panel.classList.remove("hidden");
                        search.setAttribute("aria-expanded", "true");
                        panel.scrollIntoView({ block: "nearest" });
                    }

                    function close() {
                        panel.classList.add("hidden");
                        search.setAttribute("aria-expanded", "false");
                        active = -1;
                        if (timer) {
                            clearTimeout(timer);
                            timer = null;
                        }
                        // A pending answer is no longer wanted.
                        ++sequence;
                        list.classList.remove("opacity-50");
                    }

                    function choose(item) {
                        value.value = item[0];
                        search.value = item[1];
                        hint.textContent = "";
                        close();
                    }

                    function setActive(index) {
                        var nodes = list.querySelectorAll("a");
                        if (nodes.length == 0) {
                            active = -1;
                            return;
                        }
                        active = Math.max(0, Math.min(index, nodes.length - 1));
                        for (var k = 0; k < nodes.length; k++) {
                            nodes[k].classList.toggle("menu-active", k == active);
                        }
                    }

                    function renderMessage(message) {
                        list.innerHTML = "";
                        active = -1;
                        pageItems = [];
                        var li = document.createElement("li");
                        li.className = "menu-disabled";
                        var span = document.createElement("span");
                        span.textContent = message;
                        li.appendChild(span);
                        list.appendChild(li);
                        footer.classList.add("hidden");
                    }

                    function renderPage() {
                        list.classList.remove("opacity-50");
                        if (total == 0) {
                            renderMessage(text.notFound);
                            return;
                        }
                        list.innerHTML = "";
                        active = -1;
                        pageItems.forEach(function (item) {
                            var li = document.createElement("li");
                            li.setAttribute("role", "option");
                            var a = document.createElement("a");
                            a.href = "#";
                            a.textContent = item[1];
                            a.addEventListener("click", function (e) {
                                e.preventDefault();
                                choose(item);
                            });
                            li.appendChild(a);
                            list.appendChild(li);
                        });
                        var count = pagesCount();
                        var from = page * pageSize;
                        footer.classList.remove("hidden");
                        range.textContent = format(text.range, { from: from + 1, to: from + pageItems.length, total: total });
                        pageInfo.textContent = format(text.page, { page: page + 1, pages: count });
                        pager.classList.toggle("hidden", count <= 1);
                        btnFirst.disabled = (page == 0);
                        btnPrevious.disabled = (page == 0);
                        btnNext.disabled = (page >= count - 1);
                        btnLast.disabled = (page >= count - 1);
                    }

                    // --- Client mode: search the embedded list.

                    function clientPage(index) {
                        total = found.length;
                        page = Math.max(0, Math.min(index, pagesCount() - 1));
                        pageItems = found.slice(page * pageSize, (page + 1) * pageSize);
                        renderPage();
                    }

                    function clientSearch() {
                        // Names starting with the text first, then the ones containing it.
                        var q = query.toLowerCase();
                        var starts = [];
                        var contains = [];
                        items.forEach(function (item) {
                            var index = item[1].toLowerCase().indexOf(q);
                            if (index == 0) {
                                starts.push(item);
                            } else if (index > 0) {
                                contains.push(item);
                            }
                        });
                        found = starts.concat(contains);
                        clientPage(0);
                        open();
                    }

                    // --- Server mode: ask this component for one page of matches.

                    function failed() {
                        renderMessage(text.searchError);
                        list.classList.remove("opacity-50");
                    }

                    function request(q, index) {
                        var current = ++sequence;
                        list.classList.add("opacity-50");
                        <?php
                        if ($serverMode) {
                            // Like renderJSRequestPostToElement, with an error callback.
                            echo "XYO.Web.Component.AJAX.post(" . json_encode($this->getIdRequest()) . ", " . json_encode($this->id . "_ajax") . ", ";
                            echo $this->processJSPayload(null, array(
                                "autocomplete_q" => "q",
                                "autocomplete_page" => "index",
                                "autocomplete_sequence" => "current"
                            ));
                            echo ", " . json_encode($this->view->token) . ", failed);";
                        }
                        ?>
                    }

                    function receive(data) {
                        if (data.sequence != sequence) {
                            return;
                        }
                        total = data.total;
                        page = data.page;
                        pageItems = data.items;
                        renderPage();
                        open();
                    }

                    if (serverMode) {
                        window.AndoraInputAutocomplete = window.AndoraInputAutocomplete || {};
                        window.AndoraInputAutocomplete[id] = { receive: receive };
                    }

                    // ---

                    function update() {
                        query = search.value.trim();
                        value.value = "";
                        if (query.length < minChars) {
                            hint.textContent = (query.length > 0) ? text.typeMore : "";
                            close();
                            return;
                        }
                        hint.textContent = "";
                        if (!serverMode) {
                            clientSearch();
                            return;
                        }
                        if (panel.classList.contains("hidden")) {
                            renderMessage(text.searching);
                            open();
                        }
                        if (timer) {
                            clearTimeout(timer);
                        }
                        var q = query;
                        timer = setTimeout(function () {
                            timer = null;
                            request(q, 0);
                        }, 250);
                    }

                    function goPage(index) {
                        index = Math.max(0, Math.min(index, pagesCount() - 1));
                        if (serverMode) {
                            if (index != page) {
                                request(query, index);
                            }
                        } else {
                            clientPage(index);
                        }
                        search.focus();
                    }

                    search.addEventListener("input", update);

                    search.addEventListener("keydown", function (e) {
                        var isOpen = !panel.classList.contains("hidden");
                        if (e.key == "ArrowDown" || e.key == "ArrowUp") {
                            if (!isOpen) {
                                return;
                            }
                            e.preventDefault();
                            setActive(active + ((e.key == "ArrowDown") ? 1 : -1));
                        } else if (e.key == "PageDown" || e.key == "PageUp") {
                            if (!isOpen) {
                                return;
                            }
                            e.preventDefault();
                            goPage(page + ((e.key == "PageDown") ? 1 : -1));
                        } else if (e.key == "Enter") {
                            if (!isOpen) {
                                return;
                            }
                            // Pick the highlighted (or the only) match, never submit with the list open.
                            e.preventDefault();
                            var index = active;
                            if ((index < 0) && (pageItems.length == 1) && (total == 1)) {
                                index = 0;
                            }
                            if ((index >= 0) && (index < pageItems.length)) {
                                choose(pageItems[index]);
                            }
                        } else if (e.key == "Escape") {
                            if (isOpen) {
                                e.preventDefault();
                                e.stopPropagation();
                                close();
                            }
                        }
                    });

                    btnFirst.addEventListener("click", function () {
                        goPage(0);
                    });
                    btnPrevious.addEventListener("click", function () {
                        goPage(page - 1);
                    });
                    btnNext.addEventListener("click", function () {
                        goPage(page + 1);
                    });
                    btnLast.addEventListener("click", function () {
                        goPage(pagesCount() - 1);
                    });

                    // Clicks inside the panel keep the focus on the input,
                    // so the panel closes only when the input loses it.
                    panel.addEventListener("mousedown", function (e) {
                        e.preventDefault();
                    });
                    search.addEventListener("blur", close);
                    search.addEventListener("focus", function () {
                        if ((value.value.length == 0) && (search.value.trim().length >= minChars)) {
                            update();
                        }
                    });
                })();
            </script>
            <?php
        });
    }

    // Answer of a server mode search: a script that hands one page of
    // matches to the input (window.AndoraInputAutocomplete[id].receive).
    public function renderAJAX($options = null)
    {
        $query = $this->request->get("autocomplete_q", "");
        if (!is_string($query)) {
            $query = "";
        }
        $query = trim(mb_substr($query, 0, 128, "UTF-8"));
        $page = intval($this->request->get("autocomplete_page", 0));
        $sequence = intval($this->request->get("autocomplete_sequence", 0));

        $result = $this->search($query, $page);
        $result["sequence"] = $sequence;

        $this->view->renderJS(function () use ($result) {
            echo "(function(){";
            echo "var list=window.AndoraInputAutocomplete;";
            echo "var id=" . json_encode($this->id) . ";";
            echo "if(list&&list[id]){list[id].receive(" . $this->toJSON($result) . ");}";
            echo "})();";
        });
    }

    // One page of the names matching $query (starting with it first, then
    // containing it), as the browser search does.
    public function search($query, $page)
    {
        $result = array("items" => array(), "total" => 0, "page" => 0);
        if ((mb_strlen($query, "UTF-8") < $this->minChars) || !$this->isServerMode()) {
            return $result;
        }

        $q = mb_strtolower($query, "UTF-8");
        $starts = array();
        $contains = array();
        foreach ($this->list as $key => $value) {
            $index = mb_strpos(mb_strtolower("" . $value, "UTF-8"), $q, 0, "UTF-8");
            if ($index === 0) {
                $starts[] = array("" . $key, "" . $value);
            } else if ($index !== false) {
                $contains[] = array("" . $key, "" . $value);
            }
        }
        $found = array_merge($starts, $contains);

        $result["total"] = count($found);
        $pages = max(1, intval(ceil($result["total"] / $this->pageSize)));
        $result["page"] = max(0, min($page, $pages - 1));
        $result["items"] = array_slice($found, $result["page"] * $this->pageSize, $this->pageSize);
        return $result;
    }

    // JSON for inside a <script>: no "<", ">" or "&" in the text.
    protected function toJSON($value)
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP);
    }

}
