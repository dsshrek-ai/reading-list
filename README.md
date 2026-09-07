# Reading List

A lightweight book tracker on **MyDataWorld** — the same shared database and
single sign-on as My Apps Hub, Choir Connect, and the SoJo member app.

Each book has a **Series**, **Title**, **Author**, an **acquire link**, a **Type**
(Kindle / Audible / Hard Copy) and a **Status** (Not Started / Read Some /
Finished). It is single-user: every row is scoped to the logged-in account.

## Screens

- **Library** (`index.html`) — books grouped by Author → Series. Each series shows
  a rolled-up status badge:
  - every book **Not Started** → *Not Started*
  - every book **Finished** → *Finished*
  - anything in between (including *Read Some*) → *In Progress*

  Expand a series to see each book with an inline status dropdown and its
  *Acquire* link.
- **Manage** (`manage.html`) — a table to add, edit and delete books. Status and
  Type are dropdowns; the *Order* column controls the sequence within a series.
  A **Load Xanth series (49)** button seeds Piers Anthony's Xanth novels in
  reading order (each with an Amazon Kindle search link), once.

## Stack

- Static HTML / CSS / vanilla JS front end on GitHub Pages
  (`https://dsshrek-ai.github.io/reading-list/`). `git push` to `main` deploys.
- One `api/api.php` (mysqli, prepared statements, action router) on
  `seniorfamily.org/reading-api/`, uploaded by FTP. `api/config.php` holds the DB
  credentials and is git-ignored.
- Shared MyDataWorld tables: `users`, `sessions`, `apps`, `app_access`,
  `app_usage_log`. This app adds one table, `reading_books`.
- Auth: a My Apps Hub `app_access` grant for `reading-list`, plus SSO — launching
  from the Hub hands off a session token in `?token=`.

See [SETUP.md](SETUP.md) to deploy.
