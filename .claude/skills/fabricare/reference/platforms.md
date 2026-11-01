# Platforms

Fabricare targets a named platform. It is auto-detected (`platform/detect.js`) or forced with `--platform=<name>` / the `XYO_PLATFORM` env var. The name selects `platform/<name>.js`, which sets `Platform.*`, configures the SDK repo, and includes the matching `*.run.js` bootstrap.

## Detection

- **Windows**: machine from `PROCESSOR_ARCHITECTURE` (`win64`/`win32`). If `XYO_PLATFORM` set, use it. If `MSYSTEM` is `MINGW32`/`MINGW64`, use mingw. Otherwise probe for `vcvarsall.bat` under Visual Studio 2026 → 2022 → 2019 → 2017 and pick `win64-msvc-<year>`. On MSVC, Fabricare **re-launches itself inside a `vcvarsall.bat` shell** automatically (writes a temp `.cmd` + response file), so no Developer Prompt is needed.
- **Linux**: `lsb_release` id + release → e.g. `ubuntu-24.04`, `debian-12`.
- **Emscripten**: `emscripten`.

## Known platform names

MSVC (Windows):
`win64-msvc-2017`, `win64-msvc-2019`, `win64-msvc-2022`, `win64-msvc-2022.static`, `win64-msvc-2026`, `win64-msvc-2026.static`, and the `win32-msvc-*` equivalents.

MinGW / MSYS2 (Windows):
`mingw32`, `mingw64`, `msys2-mingw32`, `msys2-mingw64`, `msys2-ucrt64`, `ucrt64`.

Linux:
`ubuntu-18.04`, `ubuntu-20.04`, `ubuntu-22.04`, `ubuntu-24.04`, `ubuntu-26.04`, `debian-11`, `debian-12`, and `wsl-ubuntu-*` variants.

Emscripten:
`emscripten` (native) and `win-emsdk` (Windows emsdk).

`install-platforms.txt` in the tool folder lists the platforms it self-installs to (e.g. `win64-msvc-2022`, `win64-msvc-2022.static`, `wsl-ubuntu-22.04`, `wsl-ubuntu-24.04`).

## SDK repository

Each platform build resolves/installs dependencies through a per-platform SDK repo:

```
~/.xyo-sdk/<platform>/{bin,include,lib}
```

(`pathRepository`; `USERPROFILE` on Windows, `HOME` on Linux). `XYO_PLATFORM_PATH` overrides it. `install` copies a project's `output/{bin,include,lib}` here; dependent projects read `<repo>/lib/<name>.json` to resolve the transitive dependency graph. `PATH`/`INCLUDE`/`LIB` are prepended with the repo + the tool's sibling dirs before compiling.

## Static vs dynamic / debug vs release

- Static: `--static` flag or `XYO_PLATFORM_COMPILE_STATIC=1|ON|TRUE` → `Fabricare.isStatic()`. Also expressed via `.static` platform names and per-project `"crt":"static"`.
- Debug: `--debug` flag or `XYO_PLATFORM_COMPILE_DEBUG=1|ON|TRUE` → `Fabricare.isDebug()`; otherwise release.
