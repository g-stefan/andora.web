# Andora

User accounts and administration area for PHP web applications, built on the
XYO.Web framework.

- Setup wizard: language, database (SQLite, MySQL, PostgreSQL), administrator account
- User pages: sign up, e-mail confirmation, login, forgot password, password reset
- Admin area: users, user groups, permissions (grant / deny), SMTP settings
- Access to the admin area by the `admin` permission

## Build and run

```bash
fabricare make
php -S 127.0.0.1:8080 -t output test/_lib/router.php
```

Then open http://127.0.0.1:8080/setup. Tests: `fabricare test`.

## Documentation

See [docs/README.md](docs/README.md): installation, user guide,
administration, configuration and development.

## License

Copyright (c) 2026 Grigore Stefan <g_stefan@yahoo.com>
Licensed under the [Apache-2.0](LICENSE) license.

**This is a compilation of different works.**
Some files are under other licenses and carry additional requirements and copyright information.
This can be indicated in the license file, license or copyright comment block of the source file or/and binary file help commands.

