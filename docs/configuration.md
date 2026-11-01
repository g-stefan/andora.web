# Configuration

The configuration is a set of PHP files in the site root, loaded in name
order on every request: `config.01.*.php`, `config.02.*.php`, ... Each file
returns an array; the arrays are merged. The setup wizard and the SMTP
settings page write them with `var_export`, so values are stored exactly as
typed. You can edit them by hand; keep the first lines
(`defined("XYO_WEB") or die("Forbidden");`).

The web server must not serve these files (the `.htaccess` denies `config.*`).
They contain passwords: keep them out of version control and backups that
others can read.

## Files written by Andora

### `config.01.language.php` — setup step 1

```php
return array(
    'language' => 'en-US',   // en-US or ro-RO
);
```

### `config.02.datasource.php` — setup step 2

SQLite:

```php
return array(
    'dataSource' => array(
        'connection' => array(
            'db' => array(
                'type' => 'sqlite',
                'database' => './_repository/andora.sqlite',
                'prefix' => '',
            ),
        ),
    ),
);
```

MySQL / PostgreSQL (`type` is `mysql` or `postgresql`):

```php
'db' => array(
    'type' => 'mysql',
    'user' => 'andora',
    'password' => '...',
    'server' => '127.0.0.1',
    'port' => '3306',          // 5432 for PostgreSQL
    'database' => 'andora',
    'prefix' => '',            // added before every table name
),
```

### `config.03.user.php` — setup step 2

```php
return array(
    'user' => array(
        'passwordRecoSalt' => '<random>',  // key of the reversible "reco" password mode
        'loginSalt' => '<random>',         // mixed into the login session token
    ),
);
```

Generated once. Do not change `passwordRecoSalt` if passwords were stored in
`reco` mode (Andora itself stores passwords in `hash` mode).

### `config.04.configured.php` — end of the setup

```php
return array(
    'configured' => true,
    'siteURL' => 'https://example.com/',
);
```

- `configured`: the setup is done; `/setup` is locked and the other pages are
  available (without it every page redirects to `/setup`). Remove this file (and the others) to run the setup again.
- `siteURL`: base address used in the links of the e-mails
  (`<siteURL>user/email-confirmation?uuid=...`). It is the address the setup
  was opened with; change it when the site moves or runs behind a proxy. If it
  is missing, the address of the current request is used.

### `config.05.smtp.php` — `/admin/settings`

```php
return array(
    'smtp' => array(
        'name' => 'Andora',               // sender name
        'username' => 'andora@example.com', // SMTP login and sender address
        'password' => '...',
        'server' => 'smtp.example.com',
        'port' => '465',                  // implicit TLS (SMTPS)
        'isTested' => true,               // set by "Test configuration"
    ),
);
```

### `config.06.schema.php` — setup step 2 / upgrade

```php
return array(
    'schemaVersion' => 1,
);
```

The version of the database content (`Setup::SCHEMA_VERSION`). When it is
missing or older than the version of the files, the first request to
`/admin` upgrades the database: creates the missing tables and columns of
andora/web, adds the rows the code needs (system groups, system permissions,
admin menu), then writes this file. Back up the database before updating
Andora.

### `config.07.package.php` — optional

```php
return array(
    'package' => array(
        'upload' => false,            // default true: upload, export, restore, uninstall of packages
        'requireSignature' => true,   // default false: refuse uploads not signed by a trusted key
        'registry' => 'https://packages.example/index.json',   // default Registry::OFFICIAL_URL (empty): the official registry
        'registryAllowLocal' => false, // tests only: registries over http and on 127.0.0.1 / ::1
    ),
);
```

Written by hand, see [Packages](package.md) and
[Remote catalog](package.md#remote-catalog). Never set `registryAllowLocal` on
a site: it lets the catalog make requests to the server itself.

The import of database backups on `/admin/database` can be turned off the
same way, in a file written by hand (`config.20.local.php`, ...):

```php
return array(
    'database' => array(
        'import' => false,            // default true: import (restore) of a backup on the admin page
    ),
);
```

The command line (`cli.php database import`) still works. See
[Database backups](package.md#database-backups).

### `config.08.route.php` — written by the package manager

```php
return array(
    'route' => array('mount' => array(
        'blog' => '_site/acme/blog/route/blog',
    )),
    'packageRoute' => array('acme/blog' => '1.2.0'),
);
```

The routes of the enabled packages (XYO.Web route mounts) and their installed
versions (a route answers 503 while the files of its package are another
version). Rewritten after every package operation; do not edit it.

### `config.09.package-trust.php` — written by `cli.php trust`

```php
return array(
    'packageTrust' => array(
        'acme' => array('key' => "-----BEGIN PUBLIC KEY-----\n...", 'vendor' => array('acme')),
    ),
);
```

The public keys trusted for signed packages; `vendor` pins those vendors to
the key (empty: the key may sign any vendor). See [Packages](package.md#signed-packages).

### `config.10.package-registry.php` — written by the catalog page and `cli.php registry`

```php
return array(
    'packageRegistry' => array(
        'acme' => array('url' => 'https://packages.acme.example/index.json'),
    ),
);
```

The package registries added on `/admin/package/catalog` (name => https URL
of the index). The official registry is not in this file (`package.registry`
above). See [Remote catalog](package.md#remote-catalog).

### `config.11.event.php` — written by the package manager

```php
return array(
    'packageEvent' => array(
        'provide' => array(
            'acme-blog.post-publish' => 'acme/blog',
            'user.create' => 'andora/web',
        ),
        'listen' => array(
            'user.create' => array(
                array('package' => 'acme/blog', 'version' => '1.2.0', 'method' => 'onUserCreate'),
            ),
        ),
    ),
);
```

The event registry: the package that provides each event and the listeners
of the active packages (read with the configuration, so an event needs no
database query). Written by the setup and after every package operation
(`cli.php event rebuild` writes it again); do not edit it. Without it no
package gets an event. See [Events](package.md#events).

### `config.12.datasource.php` — written by the package manager

```php
return array(
    'packageDataSource' => array(
        'acme-blog-post' => array('package' => 'acme/blog', 'class' => 'Acme\\Blog\\DataSource\\TablePost'),
        'andora-web-user' => array('package' => 'andora/web', 'class' => 'Andora\\Web\\DataSource\\TableUser'),
    ),
);
```

The tables of the packages (`package.json` `datasource`; the table name
without the prefix of the connection): andora/web and every installed
package. The database backups use it to find the tables of a package.
Written by the setup and after every package operation (and by the
**Database** page when it is missing); do not edit it. See
[Tables](package.md#tables).

## Other keys

Any XYO.Web configuration key can be added in a new file, for example
`config.20.local.php`. See the XYO.Web documentation for the framework keys
(for example `api.authorizationBearerToken` for `api.php` routes).

## Files and folders the site writes

| Path | Content |
|---|---|
| `config.*.php` | Configuration (above). |
| `_repository/andora.sqlite` | SQLite database. |
| `_log/<date>.<channel>.log` | Logs: `router` (501 errors), `smtp`, `<driver>-connection`, `package`, `database` (backups). |
| `_repository/database/backup/` | Database backups of the command line and the copies made before a restore (`*.before-restore.zip`, the newest 3). |
| `_repository/database/import/` | An uploaded database backup waiting for the confirmation (removed after a day). |
