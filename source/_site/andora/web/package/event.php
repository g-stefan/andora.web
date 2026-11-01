<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// An event: a package (andora/web for user.create, user.delete, user.login,
// user.logout) provides it in package.json ("event": {"provide": {...}}),
// the packages that listen to it ("event": {"listen": {"<event>":
// "<method>"}}) get it in a method of their lifecycle class:
//
//     public function onUserCreate($event)
//     {
//         $userId = $event->get("userId");
//     }
//
// The listeners come from config.11.event.php (EventRegistry), written by
// the package manager: no database query and no scan of the packages when
// an event is dispatched.
class Event
{
    // user.create, acme-blog.post-publish, ...
    public $name = "";
    // <vendor>/<name> of the package that provides the event
    public $source = "";
    // name => value, what the event is about (userId, ...)
    public $data = array();

    public function __construct($name, $source, $data)
    {
        $this->name = $name;
        $this->source = $source;
        $this->data = $data;
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    // Call the listeners of an event, in the order of the package codes.
    // Only the active packages listen (installed, enabled, the files of the
    // installed version). An exception of a listener is written to
    // _log/<date>.package.log and the other listeners still get the event:
    // the request that dispatched it is not affected. Returns <vendor>/<name>
    // => what its listener returned. An event no active package provides
    // is logged (undeclared-event) and not dispatched.
    public static function dispatch($web, $name, $data = array())
    {
        $results = array();
        if (!EventRegistry::exists($web)) {
            // Before the setup wrote the registry: no package
            return $results;
        }
        $source = EventRegistry::provider($web, $name);
        if (is_null($source)) {
            Manager::log("", "", "event", "error", "undeclared-event", "" . $name);
            return $results;
        }
        $event = new Event($name, $source, is_array($data) ? $data : array());
        foreach (EventRegistry::listeners($web, $name) as $listener) {
            try {
                $package = EventRegistry::instance($web, $listener);
                if (is_null($package)) {
                    continue;
                }
                $method = $listener["method"];
                $results[$listener["package"]] = $package->$method($event);
            } catch (\Throwable $e) {
                Manager::log($listener["package"], $listener["version"], $name, "error", $e->getMessage(), $listener["method"]);
            }
        }
        return $results;
    }
}
