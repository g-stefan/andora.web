<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

defined("ANDORA") or die("Forbidden");

// The strings of admin/package are loaded first (Page::init)
return array(
    "loading" => "Loading the registries...",
    "registries" => "Registries",
    "registry" => "Registry",
    "official" => "official",
    "packages" => "packages",
    "packages.title" => "Packages",
    "no-packages" => "No packages.",
    "not-configured" => "Not configured: add the registry of a package publisher.",
    "remove" => "Remove",
    "registry-add" => "Add a registry",
    "registry-add.submit" => "Add",
    "registry-add-hint" => "Name: lower case letters, digits and -. URL: https address of the index (JSON).",
    "registry-name.legend" => "Name",
    "registry-url.legend" => "URL of the index",
    "registry-remove" => "Remove the registry",
    "registry-remove.submit" => "Remove",
    "registry-add-ok" => "The registry was added.",
    "registry-remove-ok" => "The registry was removed.",
    "downloading" => "Downloading...",
    "catalog-action.install" => "Install",
    "catalog-action.upgrade" => "Upgrade",
    "catalog-action.installed" => "Installed",
    "catalog-action.older" => "A newer version is installed",
    "registry-not-found" => "The registry was not found",
    "registry-exists" => "A registry with this name exists",
    "invalid-registry-name" => "Invalid name (lower case letters, digits and -, not official)",
    "invalid-url" => "Invalid URL (https only, no user or password)",
    "host-not-found" => "The host was not found",
    "address-not-allowed" => "Refused: the host is a local or private address",
    "too-many-redirects" => "Too many redirects",
    "http-error" => "The server answered with an error",
    "timeout" => "The server did not answer in time",
    "download-error" => "The download failed",
    "invalid-index" => "The index of the registry is not valid",
    "catalog-expired" => "The catalog is too old, open the page again",
    "package-not-in-catalog" => "The package is not in the catalog",
    "checksum-mismatch" => "Refused: the sha256 of the downloaded zip does not match the index",
    "too-large" => "The file is too large",
    "catalog-mismatch" => "Refused: the zip is another package or version than the index"
);
