// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

// source/ -> output/, with the version of version.json in package.json.
// The solution name is <vendor>.<name>: the package is _site/<vendor>/<name>.

messageAction("make [" + Project.name + "]");

var packageName = Solution.name.split(".");
var packagePath = "_site/" + packageName[0] + "/" + packageName[1];

Shell.removeDirRecursivelyForce("output");
Shell.mkdirRecursivelyIfNotExists("output");
Shell.mkdirRecursivelyIfNotExists("temp");

exitIf(!Shell.copyDirRecursively("source", "output"));

Shell.remove("output/" + packagePath + "/package.json");
exitIf(Shell.system("xyo-version --no-bump --project=" + Solution.name + " --version-file=version.json --file-in=source/" + packagePath + "/package.json --file-out=output/" + packagePath + "/package.json"));
