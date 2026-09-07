# Setup Guide

Reading List is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `reading-list`.

## 1. Register the app with My Apps Hub

In **phpMyAdmin**, select the MyDataWorld database, open the **SQL** tab, and run
the "NEW APP: Reading List" block appended to **My Apps Hub's** own
`api/schema.sql` (it adds the `reading-list` row to the `apps` table). Safe to
re-run — it's `ON DUPLICATE KEY UPDATE`.

## 2. Create this app's table

Still in phpMyAdmin's SQL tab, run everything in [`api/schema.sql`](api/schema.sql).
It's safe to run even if `users` / `sessions` already exist (those use
`CREATE TABLE IF NOT EXISTS`). It adds one table, `reading_books`.

## 3. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` (same credentials as your other MyDataWorld
   apps).
2. Upload the whole `api/` folder via FTP / File Manager to
   `seniorfamily.org/reading-api/` (so the endpoint is
   `https://seniorfamily.org/reading-api/api.php`).

## 4. Point the site at your API

`js/api.js` already has:

```js
const CONFIG = {
  API_URL: "https://seniorfamily.org/reading-api/api.php",
};
```

Change it only if you upload the API somewhere else.

## 5. Publish

Push this folder to the GitHub repo `dsshrek-ai/reading-list`, then
Settings → Pages → Deploy from a branch → `main` / `/ (root)`. The app goes live
at `https://dsshrek-ai.github.io/reading-list/`.

## 6. Grant yourself access

1. Sign up through **My Apps Hub** with your email if you haven't already.
2. In the Hub's `admin.html`, grant your account **Reading List**, or run:

   ```sql
   INSERT INTO app_access (user_id, app_id)
   SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'reading-list'
   WHERE u.username = 'you@example.com'
   ON DUPLICATE KEY UPDATE user_id = user_id;
   ```

## 7. Load the Xanth list (optional)

Open the app from My Apps Hub, go to **Manage**, and click **Load Xanth series
(49)** — or use the button on the empty Library screen. It inserts the 49 Xanth
novels in reading order (Series `Xanth`, Author `Piers Anthony`, Type `Kindle`,
Status `Not Started`, each with an Amazon Kindle search link). Runs once — it
does nothing if you already have any `Xanth` rows.

## Single sign-on

The app is seeded with `sso_enabled = 1`, so launching it from My Apps Hub skips
the login screen (`?token=...` handoff). `js/api.js` captures the token, saves it
as the Bearer credential, and strips it from the URL.

## Usage logging

Every successful action upserts a row into the shared `app_usage_log`
(`app_key = 'reading-list'`), one row per day:

```sql
SELECT access_date, hit_count
FROM app_usage_log
WHERE app_key = 'reading-list'
ORDER BY access_date;
```
