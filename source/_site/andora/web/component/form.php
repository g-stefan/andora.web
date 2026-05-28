<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

class Form extends \XYO\Web\ComponentForm
{
    public $value = null;
    protected $isDone_ = null;
    protected $isInit_ = null;
    protected $onInit_ = null;
    protected $onError_ = null;
    protected $onSuccess_ = null;
    protected $disableRenderAJAX_ = false;

    public function init($options = null)
    {
        parent::init($options);

        if (!is_null($options)) {
            $this->isInit_ = false;
            if (array_key_exists("init", $options)) {
                $this->isInit_ = $options["init"];
            }
        }

        $this->value = new \stdClass();
        $this->isDone_ = false;

        $this->initFromPOST();

        $this->formInit($options);

        $this->onInit();

        $this->formInitComponents($options);
    }

    public function setIsDone($value)
    {
        $this->isDone_ = $value;
    }

    public function isDone()
    {
        return $this->isDone_;
    }

    public function setIsInit($value)
    {
        $this->isInit_ = $value;
    }

    public function isInit()
    {
        return $this->isInit_;
    }

    public function initFromPOST()
    {
        if ($this->isPOST()) {
            if ($this->getElementValueString("init", "") == "true") {
                $this->setIsInit(true);
            }
        }
    }

    public function setOnInit($fn)
    {
        $this->onInit_ = $fn;
    }

    public function onInit()
    {
        if (!is_null($this->onInit_)) {
            ($this->onInit_)($this);
        }
    }

    public function setOnError($fn)
    {
        $this->onError_ = $fn;
    }

    public function onError()
    {
        if (!is_null($this->onError_)) {
            ($this->onError_)($this);
        }
    }

    public function setOnSuccess($fn)
    {
        $this->onSuccess_ = $fn;
    }

    public function onSuccess()
    {
        if (!is_null($this->onSuccess_)) {
            ($this->onSuccess_)($this);
        }
    }

    public function disableRenderAJAX($flag)
    {
        $this->disableRenderAJAX_ = $flag;
    }

    public function isDisabledAJAXRender()
    {
        return $this->disableRenderAJAX_;
    }

    public function process($options = null)
    {
        if ($this->isInit()) {
            return;
        }

        if (!$this->isPOST()) {
            return;
        }

        if ($this->hasError()) {
            $this->onError();
            return;
        }

        $this->setIsDone(true);
        $this->formProcess($options);

        if ($this->hasError()) {
            $this->setIsDone(false);
            $this->onError();
            return;
        }

        if (!$this->isDone_) {
            return;
        }

        $this->onSuccess();
    }

    public function renderAJAX($options = null)
    {
        if ($this->disableRenderAJAX_) {
            return;
        }
        $this->formRenderAJAX($options);
    }

    public function formInit($options = null)
    {

    }

    public function formInitComponents($options = null)
    {

    }

    public function formProcess($options = null)
    {

    }

    public function formRenderAJAX($options = null)
    {

    }

    public function renderJSReset()
    {
        echo "document.getElementById(\"" . $this->id . "_form\").reset();";
    }

    public function formRenderJSRequest()
    {
        $this->renderJSRequestPostForm($this->getFormId());
    }

}

