<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\Profile;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\User;
use \Andora\Web\DataSource\TableUser;
use \Andora\Web\Component\InputAvatar;
use \Andora\Web\Component\InputTextarea;
use \Andora\Web\Component\SidebarUser;

// Avatar and description of the logged in user; name and e-mail are shown
// read only (an administrator changes them in admin/user)
class FormProfile extends \Andora\Web\Component\Form
{
    protected $isSaved = false;
    protected $isAvatarChanged = false;

    public function formInit($options = null)
    {
        $this->value->name = "";
        $this->value->email = "";
        $this->value->description = "";

        $dsUser = $this->getDataSource(TableUser::class);
        $dsUser->clear();
        $dsUser->id = User::$user__id;
        if ($dsUser->load(0, 1)) {
            $this->value->name = "" . $dsUser->name;
            $this->value->email = "" . $dsUser->email;
            $this->value->description = "" . $dsUser->description;
        }
    }

    public function formInitComponents($options = null)
    {
        LucideIcons::register($this, "icons");

        InputAvatar::register($this, "avatar", array(
            "name" => "avatar",
            "url" => User::getCurrentUserAvatarURL($this->web)
        ));

        InputTextarea::register($this, "description", array(
            "name" => "description",
            "maxLength" => User::DESCRIPTION_MAX_LENGTH,
            "initialValue" => $this->value->description
        ));
    }

    public function formProcess($options = null)
    {
        $reason = "";
        $userId = User::$user__id;

        if ($this->value->avatar_delete) {
            if (!User::deleteAvatar($this->web, $userId, $reason)) {
                $this->setElementError("avatar", $reason);
                return;
            }
            $this->isAvatarChanged = true;
        } else if (strlen($this->value->avatar) > 0) {
            if (!User::saveAvatar($this->web, $userId, $this->value->avatar, $reason)) {
                $this->setElementError("avatar", $reason);
                return;
            }
            $this->isAvatarChanged = true;
        }

        if (!User::setDescription($this->web, $userId, $this->value->description, $reason)) {
            $this->setElementError("description", true);
            $this->setElementError("form", true);
            $this->setElementAlert("form", $reason);
            return;
        }

        $this->isSaved = true;
        $this->getComponent("avatar")->url = User::getCurrentUserAvatarURL($this->web);
    }

    public function formRenderAJAX($options = null)
    {
        ?>

        <div class="mb-3">
            <h2 class="text-lg font-semibold flex items-center gap-2">
                <?php $this->renderComponent("icons", array("icon" => "user-pen", "class" => "text-xl")); ?>
                <?php $this->language->render("profile.title"); ?>
            </h2>
            <p class="mt-1 text-sm text-base-content/70">
                <?php $this->language->render("profile.info"); ?>
            </p>
        </div>

        <?php

        $this->renderFormAJAX(function () {
            ?>

            <?php if ($this->isSaved) { ?>
                <div role="alert" class="alert alert-success">
                    <?php $this->renderComponent("icons", array("icon" => "circle-check", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("profile-saved"); ?></span>
                </div>
            <?php } ?>

            <?php if ($this->hasAlert()) { ?>
                <div role="alert" class="alert alert-error">
                    <?php $this->renderComponent("icons", array("icon" => "circle-x", "class" => "text-xl")); ?>
                    <span><?php $this->language->render("error." . $this->getElementAlert("form")); ?></span>
                </div>
            <?php } ?>

            <?php $this->renderComponent("avatar"); ?>

            <fieldset class="fieldset w-full">
                <legend class="fieldset-legend text-base"><?php $this->language->render("name.legend"); ?></legend>
                <input type="text" class="input w-full" disabled
                    value="<?php echo htmlspecialchars($this->value->name, ENT_QUOTES); ?>" />
            </fieldset>

            <fieldset class="fieldset w-full">
                <legend class="fieldset-legend text-base"><?php $this->language->render("email.legend"); ?></legend>
                <input type="text" class="input w-full" disabled
                    value="<?php echo htmlspecialchars($this->value->email, ENT_QUOTES); ?>" />
            </fieldset>

            <?php $this->renderComponent("description"); ?>

            <button type="submit" class="btn btn-neutral mt-2">
                <?php $this->language->render("save"); ?>
            </button>

            <?php
        }, "flex flex-col gap-2");

        if ($this->isAvatarChanged) {
            // The avatar in the user menu (Andora\Web\Component\SidebarUser)
            $this->view->renderJS(function () {
                echo "(function(){";
                echo "var el=document.getElementById(\"sidebar-user-avatar\");";
                echo "if(el){el.innerHTML=" . json_encode(SidebarUser::avatarHTML(User::getCurrentUserAvatarURL($this->web))) . ";}";
                echo "})();";
            });
        }
    }

    public function renderContainer($options = null)
    { ?>
        <div class="card bg-base-100 border border-base-300 rounded-box p-6 w-full flex flex-col" id="<?php echo $this->id; ?>">
            <?php $this->renderAJAX(); ?>
        </div>
        <?php
    }
}
