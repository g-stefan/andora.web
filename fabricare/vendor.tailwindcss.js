// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

messageAction("vendor tailwindcss");

Shell.mkdirRecursivelyIfNotExists("vendor");

if (Shell.fileExists("vendor/tailwindcss.zip")) {
	if (Shell.getFileSize("vendor/tailwindcss.zip") > 16) {
		return;
	};
	Shell.removeFile("vendor/tailwindcss.zip");
};

Shell.mkdirRecursivelyIfNotExists("vendor/tailwindcss");

runInPath("vendor/tailwindcss", function() {
	Shell.system("npm i tailwindcss @tailwindcss/postcss @tailwindcss/cli purgecss");
	Shell.system("npm i tailwindcss @tailwindcss/typography");
	Shell.system("npm i -D daisyui@latest");
	Shell.system("npm install -D tw-animate-css");
	exitIf(Shell.system("7z a -mx9 -mmt4 -r- -sse -w. -y -tzip ../tailwindcss.zip *"));
});

Shell.removeDirRecursively("vendor/tailwindcss");

