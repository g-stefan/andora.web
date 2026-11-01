# Fabricare actions (commands)

Run as `fabricare <action>` from the project root. The action name maps to a script resolved through the include chain (local `fabricare/` folder → embedded). `default` runs `make`.

## Core lifecycle

| Action | What it does |
|--------|--------------|
| `make` (default) | Runs `make.config` → `make.prepare` → for each `category=="make"` project, `make.<project.make>` (`exe`/`lib`/`dll`/`dll-or-lib`) → `make.done`. Compiles into `output/bin`, `output/lib`, `output/include`. Uses `xyoCC` (the `xyo-cc` compiler-driver) with the resolved dependency graph. |
| `test` | Ensures `output/test`, builds every `category=="test"` project (defaults source to `@test`, links `output/lib`), then runs each test exe. Pass/fail reported via `exitIfTest`. |
| `clean` | Deletes `output/` and `temp/`. Vendor overrides also delete extracted `source/`. |
| `version` | For each versioned `make` project, bumps `build` + `date`/`time` in `version.json`. For `xyo-cpp` it also regenerates `Version.rh` from `Version.Template.rh`. Skips projects with `noVersion` or `linkVersion`. |
| `version-major` / `version-minor` / `version-patch` | Bump the respective semver component (via the `xyo-version` tool). |
| `install` | Copies `output/{bin,include,lib}` into the platform SDK repo `~/.xyo-sdk/<platform>/{bin,include,lib}` so other projects can depend on it. No-op if `Solution.noInstall`. |
| `install-bin` | Installs only the `bin` folder. |
| `install-from-release` | Installs from the current packed release instead of a fresh `output/`. |

## Release / distribution

| Action | What it does |
|--------|--------------|
| `release` | Packs `output/bin` → `release/<prefix>-<version>-<platform>.7z` and (if `releaseDev`) `output/` → `...-dev.7z`, recording SHA-512 in `release/<prefix>-<version>.sha512.json`. Controlled by `Solution.release*` flags. No-op if `Solution.hasRelease==false`. |
| `release-extract` | Extracts the current release archive. |
| `release-remove` | Removes the current release archive. |
| `github-release` | Creates + uploads the current release to GitHub (needs `github-release` CLI). |
| `github-release-download` | Downloads the current release from GitHub. |
| `github-release-check` | Checks whether the release exists. |
| `github-release-keep-last-3` | Deletes GitHub releases older than the last 3. |
| `github-release-remove-all` | Removes all GitHub releases. |
| `gitea-release` / `gitea-release-download` / `gitea-release-remove-all` | Same, for a Gitea server. |

## Git

| Action | What it does |
|--------|--------------|
| `git-update` | Updates from/to git (pull/commit helper). |

## Global options (flags)

Parsed before the action; pass as `--flag` or `--flag=value`:

| Flag | Effect |
|------|--------|
| `--help` / `--usage` | Show usage + version, exit. |
| `--license` | Print license. |
| `--version` | Print version/build/date. |
| `--run-script=file` | Run an arbitrary quantum-script file instead of the normal process (bypasses platform bootstrap). |
| `--config=file` | Use a specific `.fabricare.json` (project Config). |
| `--user-config=file` | Use a specific user config. |
| `--workspace=file` | Use a specific workspace file instead of `./fabricare.json`. |
| `--platform=<name>` | Force the target platform (skip auto-detect). See platforms.md. |
| `--debug` | Debug build (also via env `XYO_PLATFORM_COMPILE_DEBUG=1`). `Fabricare.isDebug()`. |
| `--static` | Static build (also via env `XYO_PLATFORM_COMPILE_STATIC=1`). `Fabricare.isStatic()`. |
| `@file` | Response file: read whitespace/newline-separated args from `file` (used internally for the MSVC subroutine re-launch). |

Extra flags forwarded to sub-invocations: `--dependency-path`, `--dependency-name`, `--release-path`, `--release-name`, `--no-message`, `--separate-data`, `--for-platform`, `--replace`, `--debug`.

## Execution flow (for debugging)

1. `Application.cpp` parses flags, then executes the embedded `processSource` (from `Process.js`).
2. `Library.js` (linked in) has already set up globals and loaded config/workspace/solution.
3. `Process.js` detects the platform (`platform/detect`) and includes `platform/<name>.js`.
4. The platform script sets `Platform.*`, configures the SDK repo path/env, and — on MSVC — re-launches `fabricare` inside a `vcvarsall.bat` shell, then calls `Fabricare.processWorkspace()`.
5. `processWorkspace()` runs `./fabricare/workspace.js` if present, else `processSolution()` → includes `solution/<type>.solution.js`, which prepares projects, selects the main project, and includes the action script for `Fabricare.action`.
