<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Admin\Settings;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\Setup;
use \Andora\Web\Component\MessageError;
use \Andora\Web\Component\MessageOk;
use \Andora\Web\Component\AJAXRedirect;
use \XYO\Web\Component as NilComponent;

class SMTPConfiguration extends \XYO\Web\Component
{
    protected $componentInfo = null;
    protected $componentForm = null;
    protected $componentTest = null;

    public function init($options = null)
    {
        $this->componentInfo = SMTPInfo::register($this, "info", array(
            "toggleId" => $this->id . "_toggle"
        ));
        $this->componentForm = SMTPForm::register($this, "form");

        $this->componentTest = null;        
        if ($this->config->has("smtp")) {
            $this->componentTest = SMTPFormTestConfiguration::register($this, "form-test-configuration");
        }

        LucideIcons::register($this, "icons");
    }

    public function redirectState($state, $component, $reason = null)
    {
        $this->session->set("settings_smtp_state", $state);
        $this->session->set("settings_smtp_component", $component);
        if (!is_null($reason)) {
            $this->session->set("settings_smtp_reason", $reason);
        }

        AJAXRedirect::registerAndInit($this, $component, array("redirect" => $this));
        $this->view->renderJS(function () {
            echo "setTimeout(function(){";
            $this->componentInfo->renderJSRequestGet();
            echo "},500);";
        });
    }

    public function process($options = null)
    {
        if ($this->isGET()) {
            $state = $this->session->get("settings_smtp_state", "none");
            $this->session->set("settings_smtp_state", "none");

            if ($state == "message-ok") {
                $message = "smtp.settings-saved";
                $component = $this->session->get("settings_smtp_component", "none");
                if ($component == "form-test-configuration") {
                    $message = "smtp-form-test-configuration.success";
                }

                MessageOk::registerAndInit($this, "form", array(
                    "message" => $this->language->get($message),
                    "redirectAfterTimeout" => array($this, "3000")
                ));
                NilComponent::registerAndInit($this, "form-test-configuration");
                return;
            }
            if ($state == "message-error") {
                MessageError::registerAndInit($this, "form", array(
                    "message" => $this->language->get("an-error-occurred"),
                    "reason" => $this->session->get("settings_smtp_reason", "unknown"),
                    "redirectAfterTimeout" => array($this, "5000")
                ));
                NilComponent::registerAndInit($this, "form-test-configuration");
                return;
            }
            return;
        }

        if (!$this->isPOST()) {
            return;
        }

        if ($this->componentForm->isDone()) {
            $reason = "";
            if (
                !Setup::writeSMTPConfigFile(
                    $this->componentForm->value->name,
                    $this->componentForm->value->username,
                    $this->componentForm->value->password,
                    $this->componentForm->value->server,
                    $this->componentForm->value->port,
                    false,
                    $reason
                )
            ) {
                $this->redirectState("message-error", "form", $reason);
                return;
            }
            $this->redirectState("message-ok", "form");
            return;
        }

        if (!is_null($this->componentTest)) {
            if ($this->componentTest->isDone()) {

                $reason = "";
                if (!Setup::testSMTPConfiguration($this->web, $reason)) {
                    $this->redirectState("message-error", "form-test-configuration", $reason);
                    return;
                }
                
                $configSMTP = $this->config->get("smtp");
                if (
                    !Setup::writeSMTPConfigFile(
                        $configSMTP->get("name"),
                        $configSMTP->get("username"),
                        $configSMTP->get("password"),
                        $configSMTP->get("server"),
                        $configSMTP->get("port"),
                        true,
                        $reason
                    )
                ) {
                    $this->redirectState("message-error", "form-test-configuration", $reason);
                    return;
                }

                $this->redirectState("message-ok", "form-test-configuration");
                return;
            }
        }

    }

    public function renderAJAX($options = null)
    { ?>

        <?php $this->renderComponent("form"); ?>
        <?php $this->renderComponent("form-test-configuration"); ?>

    <?php }

    public function renderContainer($options = null)
    {
        ?>
        <div class="relative w-full max-w-[400px] flex flex-col bg-base-100 rounded-md p-2 border border-base-300">
            <div class="absolute left-1/2 top-0 flex -translate-x-1/2 -translate-y-1/2 items-center bg-base-100">
                <h1 class="text-2xl font-bold text-nowrap border-l border-r border-base-300 px-2">
                    <?php $this->language->render("smtp.title"); ?>
                </h1>
            </div>

            <!-- Toggle Input (Hidden Checkbox acting as the state manager) -->
            <!-- We use sr-only instead of hidden so it remains focusable via keyboard -->
            <input type="checkbox" id="<?php echo $this->id; ?>_toggle" class="sr-only peer" aria-label="Toggle" />

            <!-- Toggle Label (Acts as the visual button) -->
            <label for="<?php echo $this->id; ?>_toggle"
                class="absolute top-0 right-8 -translate-y-1/2 bg-base-100 border border-base-300 rounded-full w-8 h-8 flex items-center justify-center cursor-pointer hover:bg-base-200 transition-all duration-300 z-20 group peer-focus-visible:ring-4 peer-focus-visible:ring-slate-200 peer-checked:rotate-180 peer-checked:[&>.toggle-icon-plus]:hidden peer-checked:[&>.toggle-icon-minus]:block">
                <!-- Plus Icon (Visible initially) -->
                <?php $this->renderComponent("icons", array("icon" => "plus", "class" => "toggle-icon-plus text-xl transition-transform duration-300 group-hover:scale-120 block")); ?>

                <!-- Minus Icon (Hidden initially) -->
                <?php $this->renderComponent("icons", array("icon" => "minus", "class" => "toggle-icon-minus text-xl hidden transition-transform duration-300 group-hover:scale-120")); ?>
            </label>

            <!-- Always Visible Content -->
            <div
                class="text-lg text-slate-700 leading-relaxed grid grid-rows-[1fr] peer-checked:grid-rows-[0fr] transition-[grid-template-rows] duration-500 ease-in-out">
                <div class="overflow-hidden">
                    <?php $this->renderComponent("info"); ?>
                </div>
            </div>

            <!-- Toggled Visible Content -->
            <div
                class="grid grid-rows-[0fr] peer-checked:grid-rows-[1fr] transition-[grid-template-rows] duration-500 ease-in-out">
                <div class="overflow-hidden">
                    <div class="relative w-full flex flex-col p-2" id="<?php echo $this->id; ?>">
                        <?php $this->renderAJAX(); ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

}

return Page::class;
