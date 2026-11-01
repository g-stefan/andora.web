<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

defined("ANDORA") or die("Forbidden");

// All routes: redirect to the setup wizard until the site is configured
return \Andora\Web\Authorization::class;
