// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

// fabricare sign: write output/_site/<vendor>/<name>/package.sig, after make
// and before release. Needs two environment variables:
//   ANDORA_TOOL         _site/andora/web/package/tool.php of an Andora build
//   ANDORA_PACKAGE_KEY  the private key (PEM) of the author, kept out of the project

messageAction("sign [" + Project.name + "]");

var packageName = Solution.name.split(".");
var packagePath = "output/_site/" + packageName[0] + "/" + packageName[1];

if (!Shell.hasEnv("ANDORA_TOOL") || !Shell.hasEnv("ANDORA_PACKAGE_KEY")) {
	messageError("set ANDORA_TOOL (Andora tool.php) and ANDORA_PACKAGE_KEY (private key PEM)");
	Script.exit(1);
};
if (!Shell.fileExists(packagePath + "/package.json")) {
	messageError("run fabricare make first");
	Script.exit(1);
};

exitIf(Shell.system("php \"" + Shell.getenv("ANDORA_TOOL") + "\" sign \"" + packagePath + "\" \"" + Shell.getenv("ANDORA_PACKAGE_KEY") + "\""));
