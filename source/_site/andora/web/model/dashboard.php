<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0


namespace Andora\Web\Model;

defined("ANDORA") or die("Forbidden");

class Dashboard
{    
    public static $applicationTitle;

    protected function __construct()
    {
        $this->applicationTitle = "";
    }

    public static function init()
    {
        $applicationTitle="";
    }
    public static function setApplicationTitle($title)
    {
        
        $applicationTitle = $title;
    }

}
