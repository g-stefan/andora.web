# Andora documentation

Andora is a PHP web application that gives a site its **user accounts and
administration area**. It is the starting point for applications built on the
[XYO.Web](https://github.com/g-stefan/xyo.web) framework: a ready-made setup
wizard, sign-up / login / password recovery pages, and an admin panel to manage
users, user groups, permissions and the e-mail (SMTP) settings.

## What it does

| Area | What you get |
|---|---|
| Setup wizard (`/setup`) | Picks the language, configures the database (SQLite, MySQL or PostgreSQL), creates the tables and the administrator account. Runs once, then locks itself. |
| User pages (`/user/...`) | Sign up, e-mail confirmation, login, logout, forgot password, password reset. |
| Admin area (`/admin/...`) | Dashboard, users, user groups, permissions, packages, SMTP settings. Only for accounts with the `admin` permission. |
| Data model | Users, user groups, permissions and the links between them (with grant / deny). |

## Guides

- [Installation and setup](installation.md): requirements, build, deploy, the setup wizard.
- [User guide](user-guide.md): sign-up, login, password rules, password reset, profile.
- [Administration](administration.md): who can use the admin area, users, groups, permissions, SMTP.
- [Configuration](configuration.md): the `config.*.php` files and every key.
- [Development](development.md): project layout, build, how to add a page, tests, licensing.
- [Packages](package.md): modules installed and upgraded from the admin area, how to write one.

## Quick start

```bash
fabricare make
php -S 127.0.0.1:8080 -t output test/_lib/router.php
```

Open <http://127.0.0.1:8080/setup>, follow the three setup steps, then log in
at <http://127.0.0.1:8080/user/login> and go to
<http://127.0.0.1:8080/admin/dashboard>.

The PHP built-in server is for local use only; see
[Installation](installation.md) for a real web server.
