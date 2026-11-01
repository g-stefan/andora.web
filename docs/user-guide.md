# User guide

All user pages are under `/user/`. The theme switch (light / dark / system)
is in the top right corner of every page.

## Home page — `/`

After the setup, the site address shows the Andora logo and a **Login**
button. A system built on Andora can replace this page (see
[Development](development.md#replace-the-home-page)).

## Sign up — `/user/sign-up`

Fill in the full name (2 to 64 characters), the e-mail address and the
password twice. Andora creates the account and sends an e-mail with a
confirmation link.

- An e-mail address can have only one account.
- If the e-mail can not be sent (SMTP not configured), the account is still
  created and the page shows the error `configuration-error`.
- A new account has **no access to the admin area**; an administrator has to
  add it to the `administrator` group or give it the `system.admin`
  permission (see [Administration](administration.md)).

## Password rules

A password must have **at least 12 characters** and contain:

- an upper case letter,
- a lower case letter,
- a digit,
- one of the characters `# ? ! @ $ % ^ & * - _`

The same rules apply to sign-up, password reset, the setup wizard and the
admin user forms.

## Confirm the e-mail address — `/user/email-confirmation?uuid=...`

The link in the sign-up e-mail opens this page. It marks the e-mail address as
confirmed. Opening it again says the address is already confirmed. An
administrator can also mark an account as confirmed in the users table.

## Log in — `/user/login`

E-mail and password. A disabled account can not log in. After the login, an
account with access to the admin area (the `system.admin` permission) is sent
to the admin dashboard; the other accounts see "Login successfully".

The login is kept in two cookies, `_user__id` and `_user_session`
(HTTP only, SameSite=Strict, secure on HTTPS), until you log out or the
password is reset.

## Forgot password — `/user/forgot-password`

Enter the e-mail address. If an account with that address exists, Andora sends
an e-mail with a link to `/user/password-reset`. The page gives the same answer
for unknown addresses, so it can not be used to find out who has an account.

## Reset the password — `/user/password-reset?uuid=...`

The link from the e-mail opens the form for the new password.

- The link works only after a forgot password request, **only once**, and for
  **24 hours**. After that the page shows `invalid-request` or
  `request-expired`; ask for a new link.
- After the reset, every open login of the account is closed and a
  confirmation e-mail is sent.

## Profile — `/admin/profile`

In the admin area, the user menu at the bottom of the sidebar has **Profile**,
where you change your own account:

- **Picture** (avatar): **Choose image** (PNG, JPEG, GIF or WebP), drag the
  image to move it and use the slider or the mouse wheel to zoom; the square
  you see is what is saved. **Remove** deletes the picture. The browser sends
  only the cropped square; the server checks it is an image, makes it a
  256 x 256 PNG and stores it in `_repository/avatar/` (never served
  directly). The picture is shown in the user menu, through
  `/user/avatar?id=<user id>`, for logged in users only.
- **Description**: at most 255 characters.
- Name and e-mail are shown read only; an administrator changes them in
  `/admin/user`.
- **Password**: type the current password and the new one twice. The new
  password follows the password rules and must differ from the current one.
  Your login stays open and a confirmation e-mail is sent.

## Log out — `/user/logout`

Closes the login on the server and removes the login cookies. In the admin
area, the user menu at the bottom of the sidebar has **Log out**.
