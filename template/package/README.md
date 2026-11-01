# Andora package template

A package project for Andora: the example package `acme/example` (a public
page, an admin page with a table and add / edit / delete dialogs, a table,
a permission, a menu entry, listeners of the events `user.create`,
`user.delete`, `user.login`, an event of its own `acme-example.item-add`
and a panel on `/admin/dashboard`) and its build. Copy this folder
to start a package; the full guide is Andora's `docs/package.md`.

## Start a package

1. Copy `template/package/` to a new project folder.
2. Choose the vendor and the name (lower case letters, digits, `-`) and rename:
   - `source/_site/acme/example/` → `source/_site/<vendor>/<name>/`,
   - `"vendor"`, `"name"`, `"route"` in `package.json`,
   - the namespaces `Acme\Example\...` → `<Vendor>\<Name>\...` (the class
     name is the file path: `_site/<vendor>/<name>/data-source/table-item.php`
     is `\<Vendor>\<Name>\DataSource\TableItem`),
   - the table name, the permission, the menu code and the events
     (`acme-example...`, `"event"` in `package.json`),
   - the Table classes in `"datasource"` of `package.json` (every table of
     the package must be listed: the database backups of Andora use it),
   - `"name"` in `fabricare.json` and the key in `version.json`:
     `<vendor>.<name>`.
3. Keep runtime data out of the package folder (it is replaced on every
   update): use the database or `_repository/<vendor>/<name>/`.

## Build

```
fabricare make       # source/ -> output/, the version of version.json in package.json
fabricare sign       # optional: output/_site/<vendor>/<name>/package.sig
fabricare release    # release/<vendor>.<name>.v<version>.zip
```

Needs `fabricare`, `xyo-version`, `7z` and `php` on the PATH. A new version:
change `version.json`, add the migration to `upgrade()` in
`package/setup.php`, make, sign, release.

## Sign

```
php <andora>/_site/andora/web/package/tool.php keygen private.pem public.pem
set ANDORA_TOOL=<andora>/_site/andora/web/package/tool.php
set ANDORA_PACKAGE_KEY=<path>/private.pem
fabricare make && fabricare sign && fabricare release
```

Keep `private.pem` out of the project (`*.pem` is in `.gitignore`) and send
`public.pem` to the site owners. They trust it once:

```
php _site/andora/web/package/cli.php trust add <vendor> public.pem --vendor=<vendor>
```

and then the uploads of your packages are accepted without the "I trust this
package" confirmation; with `--vendor` only packages signed by your key are
accepted for your vendor.

## Install

Upload `release/<vendor>.<name>.v<version>.zip` on `/admin/package` (+), or
`php _site/andora/web/package/cli.php deploy <zip>` on the server; put the
zip in `vendor/` of a site project to include it in its build.
