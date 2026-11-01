<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web;

defined("ANDORA") or die("Forbidden");

// Every route: until the setup is done there is no database and no account,
// pages are redirected to the setup wizard (AJAX, JSON and API requests get 401).
// setup/authorization.php opens the wizard, a folder with its own
// authorization.php extends this class and calls parent::checkAuthorization().
// A route of a package (mounted from _site/<vendor>/<name>/) answers 503
// while its files are not the installed version (an upgrade is pending).
class Authorization extends \XYO\Web\Authorization
{
    public function checkAuthorization()
    {
        if ($this->config->get("configured", false)) {
            if (!$this->isPackageRouteReady()) {
                $this->setErrorCode("503");
                return false;
            }
            return true;
        }
        if ($this->request->isAJAX() || $this->request->isJSON() || $this->request->isAPI()) {
            return false;
        }
        header("Location: " . $this->info->site . "setup", true, 302);
        exit(0);
    }

    // The installed versions are in config.08.route.php (packageRoute)
    protected function isPackageRouteReady()
    {
        if (preg_match("#^_site/([a-z0-9-]+)/([a-z0-9-]+)/#", ((string) $this->info->routePath) . "/", $match) != 1) {
            return true;
        }
        $code = $match[1] . "/" . $match[2];
        if ($code === "andora/web") {
            return true;
        }
        $versions = $this->config->has("packageRoute") ? $this->config->getArray("packageRoute") : array();
        if (!array_key_exists($code, $versions)) {
            return false;
        }
        $manifest = json_decode("" . @file_get_contents(ANDORA_PATH . "_site/" . $code . "/package.json"), true);
        return is_array($manifest) && (($manifest["version"] ?? null) === $versions[$code]);
    }
}
