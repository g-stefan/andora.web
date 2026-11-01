// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

Fabricare.include("vendor");

// ---

messageAction("make [" + Project.name + "]");

Shell.mkdirRecursivelyIfNotExists("output");
Shell.mkdirRecursivelyIfNotExists("temp");

// ---

if (!Shell.fileExists("temp/build.xyo.web.vendor.flag")) {
    exitIf(Shell.system("7z x vendor/xyo.web.v"+Solution.vendor["xyo.web"]+".zip -aoa -ooutput"));
    Shell.filePutContents("temp/build.xyo.web.vendor.flag", "done");
};

if (!Shell.fileExists("temp/build.xyo.web.library.vendor.flag")) {
    exitIf(Shell.system("7z x vendor/xyo.web.library.v"+Solution.vendor["xyo.web.library"]+".zip -aoa -ooutput"));
    Shell.filePutContents("temp/build.xyo.web.library.vendor.flag", "done");
};

exitIf(!Shell.copyDirRecursively("source", "output"));

// ---

exitIf(xyoVersion("--project=" + Project.name,
                  "--file-in=source/_site/andora/web/andora.php",
                  "--file-out=output/_site/andora/web/andora.php"));

exitIf(xyoVersion("--project=" + Project.name,
                  "--file-in=source/_site/andora/web/package.json",
                  "--file-out=output/_site/andora/web/package.json"));

// ---

Fabricare.include("make.tailwindcss");

