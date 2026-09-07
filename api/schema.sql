-- Reading List -- schema for MyDataWorld
-- Run in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER My Apps
-- Hub's own api/schema.sql (this uses the shared users/sessions/apps/
-- app_access/app_usage_log tables).
--
-- One table, prefixed reading_ so it can't collide with another app's.

-- ---------- PLATFORM TABLES (shared) -- idempotent for a standalone run ----------

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- READING LIST ----------
-- status:  Not Started | Read Some | Finished
-- book_type: Kindle | Audible | Hard Copy
-- sort_order: reading order within a series (1, 2, 3 ...)

CREATE TABLE IF NOT EXISTS reading_books (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  series       VARCHAR(200) NULL,
  title        VARCHAR(300) NOT NULL,
  author       VARCHAR(200) NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'Not Started',
  book_type    VARCHAR(20) NULL,
  acquire_url  VARCHAR(1000) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_reading_books_user (user_id, series, sort_order),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) Register the app with the Hub -- run the "NEW APP: Reading List" block
--    appended to my-apps-hub/api/schema.sql.
--
-- 2) Grant yourself the app (or use the Hub's admin.html):
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com' AND a.app_key = 'reading-list';
--
-- 3) The 49-book Xanth reading list is seeded from the app itself: open it and
--    click "Load the Xanth reading list", or POST action=seedXanth. It only
--    adds them if you have no Xanth books yet.
-- ============================================================
