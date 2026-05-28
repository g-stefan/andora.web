// Created by Grigore Stefan <g_stefan@yahoo.com>
// Public domain (Unlicense) <http://unlicense.org>
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Unlicense

messageAction("make [" + Project.name + ".tailwind]");

// ---

runInPath("temp", function() {
	if (!Shell.directoryExists("node_modules")) {
		exitIf(Shell.system("7z x -aoa ../vendor/tailwind.7z"));
	};
});

// ---

Shell.remove("output/_site/andora/web/client/andora.css");
Shell.copy("source/_site/andora/web/client/andora.css", "temp/andora.css");
runInPath("temp", function() {
	Shell.system("npx @tailwindcss/cli  -i ./andora.css -o ./../output/_site/andora/web/client/andora.css --minify");
});

// ---
