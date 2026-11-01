# Overriding scripts + the scripting API

Fabricare actions are quantum-script `.js` files. The built-in ones are embedded in the executable (source: `X:\Gitea\CPP\fabricare\source\XYO\Fabricare\Internal\`). Any of them can be replaced per-project by placing a same-named file in the project's `fabricare/` folder.

## Resolution order (`Fabricare.include(name)`)

For a logical script `<name>`, the first existing file wins:

1. `./fabricare/solution/<Solution.type>.<name>.js`
2. `./fabricare/<name>.js`
3. `<exeDir>/fabricare/solution/<Solution.type>.<name>.js`
4. `<exeDir>/fabricare/<name>.js`
5. `fabricare://solution/<Solution.type>.<name>.js`  (embedded)
6. `fabricare://<name>.js`                            (embedded)

Common override targets (drop these into `fabricare/`):

- `make.js` — replace the whole build (vendor projects do this).
- `make.prepare.js` — run code generation before compiling (e.g. `file-to-cs` to embed `.js` into `.cpp`). Called by the default `make`.
- `make.config.js` — build+run a config probe before compiling (e.g. detect platform quirks). Called by the default `make`.
- `clean.js`, `test.js`, `vendor.js`, `install.js`, etc.
- `workspace.js` — replace `processWorkspace()` entirely (advanced).

An override can still call `Fabricare.include("vendor")` etc. to pull in sibling scripts, and can call the embedded default via the `fabricare://` path if needed.

## Example: code-gen in make.prepare (quantum-script)

```js
messageAction("make.prepare");
runInPath("source/XYO/QuantumScript", function () {
    exitIf(Shell.system("file-to-cs --touch=LibStdArray.cpp --file-in=LibStdArray.js --file-out=LibStdArray.Source.cpp --is-string --name=libStdArraySource"));
});
```

## Example: custom vendor build (vendor-zlib/fabricare/make.js)

```js
Fabricare.include("vendor");                 // ensure archive is fetched
messageAction("make");
if (!Shell.directoryExists("source")) {      // extract vendored 7z
    exitIf(Shell.system("7z x -aoa archive/" + Project.vendor + ".7z"));
    Shell.rename(Project.vendor, "source");
};
Shell.mkdirRecursivelyIfNotExists("output/lib");
var compileProject = { project: "libz", includePath: ["output/include","source"], cSource: [ /* ... */ ] };
Shell.filePutContents("temp/libz.compile.json", JSON.encodeWithIndentation(compileProject));
if (Fabricare.isStatic()) exitIf(xyoCC.apply(null, xyoCCExtra("@temp/libz.compile.json","--lib","--output-lib-path=output/lib")));
if (Fabricare.isDynamic()) exitIf(xyoCC.apply(null, xyoCCExtra("@temp/libz.compile.json","--dll","--output-bin-path=output/bin","--output-lib-path=output/lib")));
```

## Globals available inside scripts

### Objects
- `Solution` — the workspace solution (`name`, `type`, `projects`, plus any `Solution.*` config). 
- `Project` — the *current* project during a `forEachProject`/`selectMainProject` iteration.
- `Platform` — `{ name, machine, osName, osType, version, path }` for the active target.
- `Config` / `UserConfig` / `Workspace` — loaded config objects.
- `Fabricare` — the tool object (`action`, `include`, `isDebug/isRelease`, `isStatic/isDynamic`, `loadConfig/saveConfig`, `processWorkspace`, …).
- `OS` — `OS.isWindows()`, `OS.isLinux()`, `OS.isMinGW()`, `OS.isEmscripten()`.

### Project iteration helpers
- `forEachProject(category, fn)` — run `fn()` with `Project` set for each project of `category` (default `"make"`).
- `selectMainProject()` — set `Project` to the main project (name == solution name, or the only one).
- `prepareProjects()` — expand array-valued `project.name` into multiple projects.
- `projectSet(obj)` / `projectReset()`.

### Messaging / control flow
- `messageAction(info)` — green status line.
- `messageError(info)` — red error line.
- `exitIf(retV, msg)` — if `retV` truthy, print error and `Script.exit(retV)`. Use to wrap every shell/compile call.
- `exitIfTest(retV, msg)` — PASS/FAIL reporter for tests.
- `exit(retV, msg)` — error + exit.
- `runInPath(path, fn)` — chdir into `path`, run `fn`, always chdir back.

### Compiling (xyo-cpp)
- `xyoCC(...args)` — invoke the `xyo-cc` compiler driver (auto-appends `--platform=`). Accepts `@file.compile.json` response files.
- `xyoCCExtra(...args)` — append the standard include/lib search paths (`output/`, SDK repo, super dir) and `--debug` if set; returns the arg array. Overridable.
- `compileExe(p)` / `compileLib(p)` / `compileDll(p)` — high-level: resolve dependency defines+libs, write `temp/<project>.compile.json`, call `xyoCC`. `p` is a "compileProject" object: `{ project, defines, includePath, hppSource, cppSource, cSource, libraryPath, resources:{includePath, rcSource}, crt, library, linkerDefinitionsFile }`.
- `compileAndRunTemp(p)` / `compileAndRunTest(p)` — build to temp/test and execute.
- Dependency graph: `getDependency()`, `getDependencyVersion()`, `compileProjectDependencyToLibrary(p)`, `compileProjectDependencyToDefines(p)`.
- Source scanning: `getFileListIgnoreSpecialsSourcePath(base, sourcePath, "*.cpp")` — lists files, skipping `.source.`, `.template.`, `.amalgam.` names.
- Versions: `getVersion()`, `getVersionInfo()`, `getProjectVersion()`, `getProjectVersionInfo()`.

### Utility extensions (quantum-script stdlib, required in Library.js)
`Console`, `Application` (args/flags: `Application.getArgument(n, def)`, `Application.getFlagValue(name, def)`, `Application.hasFlag(name)`, `Application.getPathExecutable()`), `Buffer`, `DateTime`, `JSON` (`JSON.decode`, `JSON.encodeWithIndentation`), `File`, `Shell` (`Shell.system`, `Shell.execute`, `Shell.getcwd`, `Shell.chdir`, `Shell.fileExists`, `Shell.fileGetContents`, `Shell.filePutContents`, `Shell.copyFile`, `Shell.copyDirRecursively`, `Shell.mkdirRecursivelyIfNotExists`, `Shell.removeDirRecursively`, `Shell.getenv`/`setenv`/`hasEnv`, `Shell.getFileList`, `Shell.rename`, `Shell.getFileSize`), `ShellFind`, `Math`, `ProcessInteractive` (`ProcessInteractive.run(cmd)`), `SHA512` (`SHA512.fileHash`), `Thread`, `Task`, `Job`, `Make`, `CSV`, `URL`. Plus `Script` (`Script.include`, `Script.isNil`, `Script.isArray`, `Script.exit`, `Script.requireExtension`).

### Convention
- Compile output goes to `output/bin`, `output/lib`, `output/include`; intermediates to `temp/`.
- Always wrap external commands in `exitIf(Shell.system(...))` so a failure aborts the build.
- Emit a `messageAction("<step>")` at the top of each override so build logs stay readable.
