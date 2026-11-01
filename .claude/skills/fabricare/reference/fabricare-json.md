# `fabricare.json` and `version.json` reference

## Loading model

At startup Fabricare (`Library.js`) does:

1. `loadConfig()` — reads `./.fabricare.json` into global `Config` (override with `--config=`). Optional key/value store.
2. `loadUserConfig()` — reads `~/.fabricare.json` into global `UserConfig` (override with `--user-config=`). Optional.
3. `loadWorkspace()` — reads **`./fabricare.json`** into global `Workspace` (override with `--workspace=`). **Required.**
4. Everything in `Workspace.solution` is copied onto the global `Solution` object.
5. If `Solution.type` is missing it defaults to `"generic"`. Real projects use `"xyo-cpp"`.

So `fabricare.json` is the workspace file. Its top-level shape:

```json
{
  "solution": {
    "name": "<solution-name>",
    "type": "xyo-cpp",
    "projects": [ /* project objects */ ],
    "...": "other Solution.* fields (see below)"
  }
}
```

## `solution` fields

| Field | Meaning |
|-------|---------|
| `name` | Solution name. Also the default main-project name, the release file prefix, and the default key looked up in `version.json`. |
| `type` | Selects the script family. `"xyo-cpp"` for C/C++ projects; `"generic"` is the fallback base. Drives the `solution/<type>.*` script lookups. |
| `projects` | Array of project objects (see below). |
| `version` | Optional explicit solution version string; otherwise taken from `version.json`. |
| `versionName` / `linkVersion` | Alternate key names to look up in `version.json` instead of `name`. |
| `releaseName` | Override the release file prefix (default = `name`). |
| `releaseDev` | `true`/`false`: also pack a `-dev.7z` from `output/` (default true). |
| `releaseBin` | pack `output/bin` as the main `.7z` (default true). |
| `releaseOutput` | pack all of `output/` as the main `.7z` (turns off dev+bin). |
| `releaseNoPlatform` | drop the `-<platform>` suffix from release name. |
| `hasRelease` | set `false` to make the `release` action a no-op. |
| `noInstall` | set `true` to make the `install` action a no-op. |

## `project` fields (type `xyo-cpp`)

Each entry describes one build target. Key fields:

| Field | Meaning |
|-------|---------|
| `name` | Target name (used for output file, version key, dependency references). May be an **array** — `prepareProjects()` expands it into one project per name. |
| `make` | How to build: `"exe"`, `"lib"` (static lib), `"dll"`, or `"dll-or-lib"` (dll when dynamic, lib when static — chosen by `Fabricare.isStatic()`). Also custom values if you provide a `make.<value>.js`. If absent, `make` errors. |
| `category` | Which action loop picks it up: `"make"` (default) or `"test"`. `make`/`version`/`install` iterate `category=="make"`; `test` iterates `category=="test"`. |
| `SPDX-License-Identifier` | License tag stamped into artifacts. |
| `sourcePath` | Subpath(s) under `source/` to scan for `*.hpp/*.cpp/*.rc` (e.g. `"XYO/QuantumScript"`). May be an array. A `@`-prefixed entry (`"@test"`) is treated as a literal path, not under `source/`. |
| `sourcePrefix` | Filename prefix filter when scanning (default `""`; tests default it to the project name). |
| `dependency` | Array of **project names** this target links against. Fabricare resolves the transitive graph via each dependency's installed `<name>.json` (topologically sorted) and turns it into `--use-lib` + defines. Use the `.static` name to link static variants. A leading `:` marks a self/local dependency. |
| `dependencyDefines` | Extra preprocessor defines merged into `defines` (commonly the `XYO_*_LIBRARY` export/import macro). |
| `defines` | Preprocessor defines for this target. |
| `includePath` / `libraryPath` | Extra include / lib search paths (in addition to `source`, `output/*`, the SDK repo). |
| `library` | Raw library names to link (in addition to resolved `dependency`). |
| `crt` | `"static"` to link the static CRT. Combined with a `.static` project name for fully-static builds. |
| `version` | Pin an explicit version instead of reading `version.json`. |
| `versionName` / `linkVersion` | Look up a different key in `version.json`. `linkVersion` also means "this target shares another's version — skip it during `version` bumping." |
| `noVersion` | `true` → skip this target in all `version*` actions (used by vendored projects with upstream versions). |
| `outputPath` | Override where the exe/lib is written (default `output/bin`, tests use `output/test`). |
| `sourceLink` | Informational URL of upstream source (vendor projects). |
| `osWindows` / `osLinux` / `osUnknown` | Nested object with per-OS extra `dependency` / `library`. |

## Worked examples

### Library + static variant + app + exe (quantum-script pattern)

A module typically declares four targets: the shared lib, a `.static` lib, a `.application.static` glue lib, and the final `exe`:

```json
{
  "name": "quantum-script",           "make": "dll-or-lib",
  "sourcePath": "XYO/QuantumScript",  "dependency": ["xyo-system"],
  "versionName": "quantum-script.library"
}
{
  "name": "quantum-script.static",    "make": "lib",  "crt": "static",
  "sourcePath": "XYO/QuantumScript",  "dependency": ["xyo-system.static"],
  "dependencyDefines": ["XYO_QUANTUMSCRIPT_LIBRARY"],
  "linkVersion": "quantum-script.library"
}
{
  "name": "quantum-script",           "make": "exe",
  "sourcePath": "XYO/QuantumScript.Application",
  "dependency": ["quantum-script--console", "quantum-script"]
}
```

### Test target

```json
{
  "name": "test.01", "make": "exe", "category": "test",
  "SPDX-License-Identifier": "Unlicense",
  "dependency": ["file-json"]
}
```
Test projects default `sourcePath` to `@test`, add `source`+`test` to the include path, link `output/lib`, and are built + run by `fabricare test`.

### Vendored library (upstream code, custom build)

```json
{
  "solution": {
    "name": "vendor-zlib", "type": "xyo-cpp", "releaseName": "zlib",
    "projects": [
      { "name": "zlib", "SPDX-License-Identifier": "Zlib",
        "sourceLink": "https://www.zlib.net/", "version": "1.3.1",
        "noVersion": true }
    ]
  }
}
```
Vendor projects have **no `make`** field — they instead override `fabricare/make.js`, `fabricare/vendor.js`, `fabricare/clean.js`.

## `version.json`

One object per versioned key. The key is the project `name` (or its `versionName`/`linkVersion`):

```json
{
  "quantum-script.library": { "version": "6.7.0", "build": "16", "date": "2026-06-27", "time": "23:28:04" },
  "quantum-script":         { "version": "6.7.0", "build": "16", "date": "2026-06-27", "time": "23:28:04" }
}
```

- `version` — semantic `major.minor.patch`.
- `build` — monotonically bumped on every `version`/`make`-time `version` step.
- `date`/`time` — stamped at bump.

Lookups (`getVersion`, `getProjectVersionInfo`) try `linkVersion` → `versionName` → `name`. Missing file during `version`/`clean` yields a `0.0.0` placeholder instead of an error. The `xyo-cpp.version` action also regenerates `source/<sourcePath>/Version.rh` from `Version.Template.rh` via the `xyo-version` tool.
