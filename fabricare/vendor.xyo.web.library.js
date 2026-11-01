// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

messageAction("vendor xyo.web.library");

vendor =  "xyo.web.library.v" + Solution.vendor["xyo.web.library"];

Shell.mkdirRecursivelyIfNotExists("vendor");

if (Shell.fileExists("vendor/" + vendor + ".zip")) {
	return;
};

var vendorSourceGit = "https://github.com/g-stefan";
if (Shell.hasEnv("VENDOR_SOURCE_GIT")) {
	vendorSourceGit = Shell.getenv("VENDOR_SOURCE_GIT");
};

var vendorSourceAuth = "";
if (Shell.hasEnv("VENDOR_SOURCE_AUTH")) {
	vendorSourceAuth = Shell.getenv("VENDOR_SOURCE_AUTH");
};

var webLink = vendorSourceGit + "/xyo.web.library/releases/download/v" + Solution.vendor["xyo.web.library"] + "/" + vendor + ".zip";
Console.writeLn(webLink);
exitIf(Shell.system("curl --ca-native --location " + webLink + " " + vendorSourceAuth + " --output vendor/" + vendor + ".zip"));
if (Shell.getFileSize("vendor/" + vendor + ".zip") < 16384) {
	Shell.remove("vendor/" + vendor + ".zip");
	messageError("not found - "+webLink);
	return;
};
