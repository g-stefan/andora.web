<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\User;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputAutocomplete;
use \XYO\Web\DataSource\Order;
use \Andora\Web\DataSource\TableUser;
use \Andora\Web\DataSource\TableUserGroup;
use \Andora\Web\DataSource\TableUserXUserGroup;
use \Andora\Web\Model\Acl;

// Manages "table-user-x-user-group": links the selected user (user__id)
// to user groups (user_group__id). The user id travels across the AJAX
// add/remove refreshes as the component selector, exactly like the
// edit/delete forms carry their id.
// Only users and groups with a lower level than yours can be changed
// (root: all), not your own groups.
class FormLinked extends \Andora\Web\Component\Form
{
    protected $currentUserId = 0;
    protected $currentUserName = "";
    protected $linkedList = array();
    protected $availableList = array();
    protected $alert = null;

    public function formInit($options = null)
    {
        if ($this->isInit()) {
            $this->currentUserId = $this->getElementValueNumber("user__id", 0);
            $this->setSelector($this->currentUserId);
        } else {
            $this->currentUserId = intval($this->getSelector(0));
        }

        if ($this->isPOST() && !$this->isInit() && $this->currentUserId > 0) {
            $action = $this->getElementValueString("action", "");
            if (!Acl::canManageUser($this->web, $this->currentUserId)) {
                $this->alert = "access-denied";
            } else if ($action === "add") {
                $this->addLink($this->currentUserId, $this->getElementValueNumber("group__id", 0));
            } else if ($action === "remove") {
                $this->removeLink($this->currentUserId, $this->getElementValueNumber("link__id", 0));
            }
        }

        $this->loadData($this->currentUserId);
    }

    public function formInitComponents($options = null)
    {
        LucideIcons::register($this, "icons");

        InputAutocomplete::register($this, "search", array(
            "name" => "group__id",
            "list" => $this->availableList
        ));
    }

    protected function loadData($userId)
    {
        $this->currentUserName = "";
        $this->linkedList = array();
        $this->availableList = array();

        if ($userId <= 0) {
            return;
        }

        $dsUser = $this->getDataSource(TableUser::class);
        $dsUser->clear();
        $dsUser->id = $userId;
        if ($dsUser->load(0, 1)) {
            $this->currentUserName = $dsUser->name;
        }

        // Currently assigned groups.
        $links = array();
        $linkedIds = array();
        $dsLink = $this->getDataSource(TableUserXUserGroup::class);
        $dsLink->clear();
        $dsLink->user__id = $userId;
        for ($dsLink->load(0, 1000); $dsLink->loadIsValid(); $dsLink->loadNext()) {
            $links[$dsLink->user_group__id] = $dsLink->id;
            $linkedIds[] = intval($dsLink->user_group__id);
        }

        // Resolve the names of the assigned groups in one query.
        $names = array();
        $dsGroup = $this->getDataSource(TableUserGroup::class);
        if (count($linkedIds) > 0) {
            $dsGroup->clear();
            $dsGroup->id = $linkedIds;
            for ($dsGroup->load(0, 1000); $dsGroup->loadIsValid(); $dsGroup->loadNext()) {
                $names[intval($dsGroup->id)] = $dsGroup->name;
            }
        }

        foreach ($links as $groupId => $linkId) {
            $groupId = intval($groupId);
            $this->linkedList[] = array(
                "link_id" => $linkId,
                "group_id" => $groupId,
                "name" => array_key_exists($groupId, $names) ? $names[$groupId] : ("#" . $groupId)
            );
        }
        usort($this->linkedList, function ($a, $b) {
            return strcasecmp($a["name"], $b["name"]);
        });

        // Selectable groups: every group you can give, except the ones already assigned
        // (public and authenticated are implicit, never assigned).
        $isRoot = Acl::isRoot($this->web);
        $level = Acl::level($this->web);
        $dsGroup->clear();
        $dsGroup->setOrder("name", Order::ASCENDENT);
        for ($dsGroup->load(0, 5000); $dsGroup->loadIsValid(); $dsGroup->loadNext()) {
            if (in_array(intval($dsGroup->id), $linkedIds)) {
                continue;
            }
            if (Acl::isImplicitGroup($dsGroup->name, $dsGroup->is_system)) {
                continue;
            }
            if (!$isRoot && (intval($dsGroup->level) >= $level)) {
                continue;
            }
            $this->availableList[intval($dsGroup->id)] = $dsGroup->name;
        }
    }

    protected function addLink($userId, $groupId)
    {
        $groupId = intval($groupId);
        if ($groupId <= 0) {
            $this->alert = "select-a-group-to-add";
            return;
        }

        $dsGroup = $this->getDataSource(TableUserGroup::class);
        $dsGroup->clear();
        $dsGroup->id = $groupId;
        if (!$dsGroup->load(0, 1)) {
            $this->alert = "group-not-found";
            return;
        }

        if (!Acl::canManageGroup($this->web, $groupId)) {
            $this->alert = "access-denied";
            return;
        }

        $dsLink = $this->getDataSource(TableUserXUserGroup::class);
        $dsLink->clear();
        $dsLink->user__id = $userId;
        $dsLink->user_group__id = $groupId;
        if ($dsLink->load(0, 1)) {
            $this->alert = "already-linked";
            return;
        }

        $dsLink->clear();
        $dsLink->user__id = $userId;
        $dsLink->user_group__id = $groupId;
        $dsLink->enabled = 1;
        $dsLink->save();
    }

    protected function removeLink($userId, $linkId)
    {
        $linkId = intval($linkId);
        if ($linkId <= 0) {
            return;
        }
        $dsLink = $this->getDataSource(TableUserXUserGroup::class);
        $dsLink->clear();
        $dsLink->id = $linkId;
        $dsLink->user__id = $userId;
        if ($dsLink->load(0, 1)) {
            $groupId = intval($dsLink->user_group__id);
            if (!Acl::canManageGroup($this->web, $groupId)) {
                $this->alert = "access-denied";
                return;
            }
            if (($groupId == Acl::getSystemGroupId($this->web, Acl::GROUP_ROOT)) && Acl::isLastRootUser($this->web, $userId)) {
                $this->alert = "last-root-user";
                return;
            }
            $dsLink->clear();
            $dsLink->id = $linkId;
            $dsLink->delete();
        }
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("linked-groups"); ?>
            </p>
            <?php if (strlen($this->currentUserName) > 0) { ?>
                <p class="mt-1 text-base font-semibold"><?php echo htmlspecialchars($this->currentUserName, ENT_QUOTES); ?></p>
            <?php } ?>
        </div>

        <?php if (!is_null($this->alert)) { ?>
            <div role="alert" class="alert alert-warning mb-3">
                <?php $this->renderComponent("icons", array("icon" => "triangle-alert", "class" => "text-xl")); ?>
                <span><?php $this->language->render($this->alert); ?></span>
            </div>
        <?php } ?>

        <div class="flex flex-col gap-2 mb-4">
            <?php if (count($this->linkedList) == 0) { ?>
                <p class="text-base-content/60 text-center py-2"><?php $this->language->render("no-linked-groups"); ?></p>
            <?php } else {
                foreach ($this->linkedList as $index => $item) {
                    $removeId = $this->id . "_remove_" . $index;
                    ?>
                    <div class="flex items-center justify-between gap-2 p-2 rounded-md border border-base-300">
                        <span class="truncate"><?php echo htmlspecialchars($item["name"], ENT_QUOTES); ?></span>
                        <button type="button" id="<?php echo $removeId; ?>"
                            class="btn btn-sm btn-ghost btn-square text-error hover:bg-error/10">
                            <?php $this->renderComponent("icons", array("icon" => "trash-2", "class" => "text-base")); ?>
                        </button>
                    </div>
                    <?php
                    $linkId = $item["link_id"];
                    $this->view->renderJS(function () use ($removeId, $linkId) {
                        echo "document.getElementById(\"" . $removeId . "\").addEventListener(\"click\",function(){";
                        $this->renderJSRequestPost(array("action" => "remove", "link__id" => $linkId));
                        echo "});";
                    });
                }
            } ?>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            $this->renderComponent("search", array(
                "legend" => $this->language->get("add-group"),
                "placeholder" => $this->language->get("search-group")
            ));
            ?>

            <input type="hidden" name="action" value="add" />

            <button class="btn btn-neutral">
                <?php $this->language->render("add"); ?>
            </button>

            <?php
        }, "flex flex-col gap-2");

    }

    public function renderContainer($options = null)
    {
        echo "<div class=\"w-full max-w-[400px] flex flex-col\" id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }

}
