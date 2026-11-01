<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\UserGroup;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Component\InputAutocomplete;
use \XYO\Web\DataSource\Order;
use \Andora\Web\DataSource\TableUserGroup;
use \Andora\Web\DataSource\TablePermission;
use \Andora\Web\Model\Acl;
use \Andora\Web\DataSource\TableUserGroupXPermission;

// Manages "table-user-group-x-permission": grants permissions
// (permission__id) to the selected group (user_group__id). The group id
// travels across the AJAX add/remove refreshes as the component selector.
// Only groups with a lower level than yours (root: all), and only the
// permissions you have can be given / removed / toggled.
class FormLinkedPermission extends \Andora\Web\Component\Form
{
    protected $currentGroupId = 0;
    protected $currentGroupName = "";
    protected $linkedList = array();
    protected $availableList = array();
    protected $alert = null;

    public function formInit($options = null)
    {
        if ($this->isInit()) {
            $this->currentGroupId = $this->getElementValueNumber("user_group__id", 0);
            $this->setSelector($this->currentGroupId);
        } else {
            $this->currentGroupId = intval($this->getSelector(0));
        }

        if ($this->isPOST() && !$this->isInit() && $this->currentGroupId > 0) {
            $action = $this->getElementValueString("action", "");
            if (!Acl::canManageGroup($this->web, $this->currentGroupId)) {
                $this->alert = "access-denied";
            } else if ($action === "add") {
                $this->addLink($this->currentGroupId, $this->getElementValueNumber("permission__id", 0));
            } else if ($action === "remove") {
                $this->removeLink($this->currentGroupId, $this->getElementValueNumber("link__id", 0));
            } else if ($action === "toggle") {
                $this->toggleGranted($this->currentGroupId, $this->getElementValueNumber("link__id", 0));
            }
        }

        $this->loadData($this->currentGroupId);
    }

    public function formInitComponents($options = null)
    {
        LucideIcons::register($this, "icons");

        InputAutocomplete::register($this, "search", array(
            "name" => "permission__id",
            "list" => $this->availableList
        ));
    }

    protected function loadData($groupId)
    {
        $this->currentGroupName = "";
        $this->linkedList = array();
        $this->availableList = array();

        if ($groupId <= 0) {
            return;
        }

        $dsGroup = $this->getDataSource(TableUserGroup::class);
        $dsGroup->clear();
        $dsGroup->id = $groupId;
        if ($dsGroup->load(0, 1)) {
            $this->currentGroupName = $dsGroup->name;
        }

        // Currently granted permissions.
        $links = array();
        $linkedIds = array();
        $dsLink = $this->getDataSource(TableUserGroupXPermission::class);
        $dsLink->clear();
        $dsLink->user_group__id = $groupId;
        for ($dsLink->load(0, 1000); $dsLink->loadIsValid(); $dsLink->loadNext()) {
            $links[$dsLink->permission__id] = array(
                "link_id" => $dsLink->id,
                "is_granted" => intval($dsLink->is_granted)
            );
            $linkedIds[] = intval($dsLink->permission__id);
        }

        // Resolve the names of the granted permissions in one query.
        $names = array();
        $dsPermission = $this->getDataSource(TablePermission::class);
        if (count($linkedIds) > 0) {
            $dsPermission->clear();
            $dsPermission->id = $linkedIds;
            for ($dsPermission->load(0, 1000); $dsPermission->loadIsValid(); $dsPermission->loadNext()) {
                $names[intval($dsPermission->id)] = $dsPermission->name;
            }
        }

        foreach ($links as $permissionId => $link) {
            $permissionId = intval($permissionId);
            $this->linkedList[] = array(
                "link_id" => $link["link_id"],
                "is_granted" => $link["is_granted"],
                "permission_id" => $permissionId,
                "name" => array_key_exists($permissionId, $names) ? $names[$permissionId] : ("#" . $permissionId)
            );
        }
        usort($this->linkedList, function ($a, $b) {
            return strcasecmp($a["name"], $b["name"]);
        });

        // Selectable permissions: every permission except the ones already granted.
        $dsPermission->clear();
        $dsPermission->setOrder("name", Order::ASCENDENT);
        for ($dsPermission->load(0, 5000); $dsPermission->loadIsValid(); $dsPermission->loadNext()) {
            if (in_array(intval($dsPermission->id), $linkedIds)) {
                continue;
            }
            if (!Acl::canGrantPermission($this->web, $dsPermission->name)) {
                continue;
            }
            $this->availableList[intval($dsPermission->id)] = $dsPermission->name;
        }
    }

    protected function addLink($groupId, $permissionId)
    {
        $permissionId = intval($permissionId);
        if ($permissionId <= 0) {
            $this->alert = "select-a-permission-to-add";
            return;
        }

        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->id = $permissionId;
        if (!$dsPermission->load(0, 1)) {
            $this->alert = "permission-not-found";
            return;
        }

        if (!Acl::canGrantPermission($this->web, $dsPermission->name)) {
            $this->alert = "access-denied";
            return;
        }

        $dsLink = $this->getDataSource(TableUserGroupXPermission::class);
        $dsLink->clear();
        $dsLink->user_group__id = $groupId;
        $dsLink->permission__id = $permissionId;
        if ($dsLink->load(0, 1)) {
            $this->alert = "already-linked-permission";
            return;
        }

        $dsLink->clear();
        $dsLink->user_group__id = $groupId;
        $dsLink->permission__id = $permissionId;
        $dsLink->is_granted = 1;
        $dsLink->enabled = 1;
        $dsLink->save();
    }

    protected function removeLink($groupId, $linkId)
    {
        $linkId = intval($linkId);
        if ($linkId <= 0) {
            return;
        }
        $dsLink = $this->getDataSource(TableUserGroupXPermission::class);
        $dsLink->clear();
        $dsLink->id = $linkId;
        $dsLink->user_group__id = $groupId;
        if ($dsLink->load(0, 1)) {
            if (!$this->canGrantLinkedPermission($dsLink->permission__id)) {
                $this->alert = "access-denied";
                return;
            }
            $dsLink->clear();
            $dsLink->id = $linkId;
            $dsLink->delete();
        }
    }

    protected function toggleGranted($groupId, $linkId)
    {
        $linkId = intval($linkId);
        if ($linkId <= 0) {
            return;
        }
        $dsLink = $this->getDataSource(TableUserGroupXPermission::class);
        $dsLink->clear();
        $dsLink->id = $linkId;
        $dsLink->user_group__id = $groupId;
        if ($dsLink->load(0, 1)) {
            if (!$this->canGrantLinkedPermission($dsLink->permission__id)) {
                $this->alert = "access-denied";
                return;
            }
            $dsLink->is_granted = $dsLink->is_granted ? 0 : 1;
            $dsLink->save();
        }
    }

    protected function canGrantLinkedPermission($permissionId)
    {
        $dsPermission = $this->getDataSource(TablePermission::class);
        $dsPermission->clear();
        $dsPermission->id = intval($permissionId);
        if (!$dsPermission->load(0, 1)) {
            return Acl::isRoot($this->web);
        }
        return Acl::canGrantPermission($this->web, $dsPermission->name);
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold"><?php $this->language->render("page.title"); ?></h1>
            <p class="mt-3 text-lg text-base-content/70">
                <?php $this->language->render("linked-permissions"); ?>
            </p>
            <?php if (strlen($this->currentGroupName) > 0) { ?>
                <p class="mt-1 text-base font-semibold"><?php echo htmlspecialchars($this->currentGroupName, ENT_QUOTES); ?></p>
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
                <p class="text-base-content/60 text-center py-2"><?php $this->language->render("no-linked-permissions"); ?></p>
            <?php } else {
                foreach ($this->linkedList as $index => $item) {
                    $removeId = $this->id . "_remove_" . $index;
                    $toggleId = $this->id . "_granted_" . $index;
                    $isGranted = $item["is_granted"];
                    ?>
                    <div class="flex items-center justify-between gap-2 p-2 rounded-md border border-base-300">
                        <span class="truncate flex-1 <?php echo $isGranted ? "" : "opacity-50 line-through"; ?>"><?php echo htmlspecialchars($item["name"], ENT_QUOTES); ?></span>
                        <input type="checkbox" id="<?php echo $toggleId; ?>"
                            class="toggle toggle-sm toggle-success"
                            title="<?php echo htmlspecialchars($this->language->get($isGranted ? "granted" : "denied"), ENT_QUOTES); ?>"
                            <?php echo $isGranted ? "checked" : ""; ?> />
                        <button type="button" id="<?php echo $removeId; ?>"
                            class="btn btn-sm btn-ghost btn-square text-error hover:bg-error/10">
                            <?php $this->renderComponent("icons", array("icon" => "trash-2", "class" => "text-base")); ?>
                        </button>
                    </div>
                    <?php
                    $linkId = $item["link_id"];
                    $this->view->renderJS(function () use ($removeId, $toggleId, $linkId) {
                        echo "document.getElementById(\"" . $toggleId . "\").addEventListener(\"change\",function(){";
                        $this->renderJSRequestPost(array("action" => "toggle", "link__id" => $linkId));
                        echo "});";
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
                "legend" => $this->language->get("add-permission"),
                "placeholder" => $this->language->get("search-permission")
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
