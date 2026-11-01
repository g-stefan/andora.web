<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\User\Avatar;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\User;

// user/avatar?id=<user id>&v=<version> - the avatar image of a user (PNG), 404 if none.
// The URL changes with every new image (User::getAvatarURL), so it can be cached.
class Api extends \XYO\Web\Module
{
    public function render($options = null)
    {
        $id = $this->request->get("id", "");
        $file = null;
        if (is_string($id) && preg_match("/^[0-9]{1,19}$/", $id)) {
            $file = User::getAvatarFile($this->web, intval($id));
        }
        if (is_null($file)) {
            http_response_code(404);
            return;
        }

        header("Content-Type: image/png");
        header("Content-Length: " . filesize($file));
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'");
        header("Cache-Control: private, max-age=31536000, immutable");
        readfile($file);
    }
}

return Api::class;
