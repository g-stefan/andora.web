---
name: fabricare
description: Build, test, version, install, and release C/C++ projects that use the Fabricare build system. Use whenever a project contains a fabricare.json (workspace) and version.json, an optional fabricare/ folder of override scripts, and is built with the `fabricare` command (e.g. the XYO/quantum-script/vendor-* projects under X:\Gitea\CPP). Covers writing/editing fabricare.json, adding projects and dependencies, overriding the default build scripts, and running actions like make/test/version/install/release.
---

# Fabricare

Fabricare is a script-driven build system for C/C++ projects (author: Grigore Stefan). It is a single native executable (`fabricare`, built from quantum-script) that reads a **`fabricare.json`** workspace file and a **`version.json`** file in each project folder, then runs an **action** (make, test, version, install, release, …). Actions are implemented as quantum-script `.js` files that are **embedded inside the executable** but can be **overridden per-project** by dropping a same-named `.js` file into the project's `fabricare/` folder.

Source of the tool: `X:\Gitea\CPP\fabricare`. Many projects that use it live under `X:\Gitea\CPP` (e.g. `xyo-cc`, `quantum-script`, `quantum-script--*`, `file-json`, `xyo-system`, `vendor-*`).

## How to run it

Run `fabricare <action>` **from the project's root folder** (the folder that contains `fabricare.json`). No action = the `default` action = `make`.

```
fabricare                 # default -> make
fabricare make            # compile all projects
fabricare test            # build + run the test projects
fabricare version         # bump build number + date, regenerate Version.rh
fabricare install         # copy output/{bin,include,lib} into the platform SDK repo
fabricare clean           # delete output/ and temp/
fabricare release         # pack output into release/*.7z + sha512.json
fabricare --platform=ubuntu-24.04 make   # force a platform
fabricare --help          # options + version
```

Fabricare auto-detects the platform (MSVC version on Windows, `lsb_release` on Linux, emscripten). On Windows/MSVC it re-launches itself inside a `vcvarsall.bat` shell automatically, so you do **not** need a "Developer Command Prompt". `XYO_PLATFORM` (and the `build.cmd`/`build.sh` wrappers in each project) can override detection.

The `fabricare` executable must be on `PATH`. Prebuilt releases are in `X:\Gitea\CPP\fabricare\release`; it self-installs via `fabricare.self-install`.

## The two config files (every project has these)

- **`fabricare.json`** — the *workspace*. Describes the `solution` and its `projects` (what to build, source paths, dependencies). Loaded into the global `Workspace`/`Solution` objects. See [reference/fabricare-json.md](reference/fabricare-json.md).
- **`version.json`** — the *version store*. One entry per versioned target: `{ "<name>": { version, build, date, time } }`. Bumped by the `version*` actions and read when stamping binaries / naming releases.

There is also an optional `.fabricare.json` (project-local key/value `Config`, `--config=`) and `~/.fabricare.json` (`UserConfig`, `--user-config=`) for machine/user settings — rarely used.

## Overriding the built-in scripts (the `fabricare/` folder)

For each action Fabricare resolves the script to run by searching, **in order**:

1. `./fabricare/solution/<solution.type>.<name>.js`  (local, type-specific)
2. `./fabricare/<name>.js`                            (local, generic)
3. `<exe-dir>/fabricare/solution/<solution.type>.<name>.js`
4. `<exe-dir>/fabricare/<name>.js`
5. `fabricare://solution/<solution.type>.<name>.js`   (embedded, type-specific)
6. `fabricare://<name>.js`                             (embedded, generic)

The first match wins. So to customize a project, put e.g. `fabricare/make.js`, `fabricare/clean.js`, `fabricare/test.js`, `fabricare/make.config.js`, or `fabricare/make.prepare.js` in the project. This is exactly how `vendor-*` projects replace the standard C++ `make` with a custom vendor build. See [reference/custom-scripts.md](reference/custom-scripts.md) for the scripting API (globals like `messageAction`, `exitIf`, `compileExe`, `xyoCC`, `Shell.*`, `Project`, `Solution`, `Platform`, `Fabricare.isStatic()`).

## Available actions

`make`, `test`, `version`, `version-major`, `version-minor`, `version-patch`, `install`, `install-bin`, `install-from-release`, `clean`, `release`, `release-extract`, `release-remove`, `git-update`, and the `github-release-*` / `gitea-release-*` family. Full descriptions in [reference/commands.md](reference/commands.md).

## When editing project config

- Adding a library/module to a C++ project → add a `projects[]` entry and list dependencies in `dependency[]`. Static variants use `"crt":"static"` and a `.static` name suffix. See the schema and worked examples in [reference/fabricare-json.md](reference/fabricare-json.md).
- Custom build steps (code generation, vendored source, non-standard compile) → override `make.prepare.js` or `make.js` in `fabricare/`, don't hand-edit the embedded scripts.
- Bumping/setting versions → edit `version.json` or (preferred) run `fabricare version` / `version-patch` / `version-minor` / `version-major`.

Platform names and detection details: [reference/platforms.md](reference/platforms.md).
