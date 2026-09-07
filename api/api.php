<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// ---- Auth: shared MyDataWorld login + an app_access grant for 'reading-list' ----

const APP_KEY = 'reading-list';

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];
  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $row;
}

function hasAppAccess(array $user): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $key = APP_KEY;
  $stmt->bind_param('is', $user['id'], $key);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  return (bool)$ok;
}

function logAppUsage(int $userId): void {
  try {
    $key = APP_KEY;
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user)) {
    fail('Not authorized for Reading List', 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// ---- Books ----

const STATUSES = ['Not Started', 'Read Some', 'Finished'];
const TYPES = ['Kindle', 'Audible', 'Hard Copy'];

function normStatus(string $s): string {
  foreach (STATUSES as $v) {
    if (strcasecmp($v, trim($s)) === 0) { return $v; }
  }
  return 'Not Started';
}
function normType(string $t): ?string {
  $t = trim($t);
  if ($t === '') { return null; }
  foreach (TYPES as $v) {
    if (strcasecmp($v, $t) === 0) { return $v; }
  }
  return $t; // keep whatever they typed rather than dropping it
}

function listBooks(int $userId): array {
  $stmt = db()->prepare(
    "SELECT id, series, title, author, status, book_type, acquire_url, sort_order
     FROM reading_books WHERE user_id = ?
     ORDER BY COALESCE(NULLIF(series, ''), '~~~'), sort_order, title, id"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Series' => (string)($r['series'] ?? ''),
      'Title' => (string)($r['title'] ?? ''),
      'Author' => (string)($r['author'] ?? ''),
      'Status' => (string)($r['status'] ?? ''),
      'Type' => (string)($r['book_type'] ?? ''),
      'AcquireUrl' => (string)($r['acquire_url'] ?? ''),
      'SortOrder' => (int)$r['sort_order'],
    ];
  }
  $stmt->close();
  return $out;
}

function addBook(int $userId, array $b): int {
  $series = trim((string)($b['series'] ?? ''));
  $title = trim((string)($b['title'] ?? ''));
  $author = trim((string)($b['author'] ?? ''));
  $status = normStatus((string)($b['status'] ?? 'Not Started'));
  $type = normType((string)($b['type'] ?? ''));
  $url = trim((string)($b['acquireUrl'] ?? ''));
  $order = (int)($b['sortOrder'] ?? 0);
  if ($title === '') {
    fail('Title is required');
  }
  $seriesVal = $series === '' ? null : $series;
  $authorVal = $author === '' ? null : $author;
  $urlVal = $url === '' ? null : $url;
  $stmt = db()->prepare(
    'INSERT INTO reading_books (user_id, series, title, author, status, book_type, acquire_url, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param('issssssi', $userId, $seriesVal, $title, $authorVal, $status, $type, $urlVal, $order);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function updateBook(int $userId, int $id, array $b): void {
  $series = trim((string)($b['series'] ?? ''));
  $title = trim((string)($b['title'] ?? ''));
  $author = trim((string)($b['author'] ?? ''));
  $status = normStatus((string)($b['status'] ?? 'Not Started'));
  $type = normType((string)($b['type'] ?? ''));
  $url = trim((string)($b['acquireUrl'] ?? ''));
  $order = (int)($b['sortOrder'] ?? 0);
  if ($title === '') {
    fail('Title is required');
  }
  $seriesVal = $series === '' ? null : $series;
  $authorVal = $author === '' ? null : $author;
  $urlVal = $url === '' ? null : $url;
  $stmt = db()->prepare(
    'UPDATE reading_books
     SET series = ?, title = ?, author = ?, status = ?, book_type = ?, acquire_url = ?, sort_order = ?
     WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param('ssssssiii', $seriesVal, $title, $authorVal, $status, $type, $urlVal, $order, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function setStatus(int $userId, int $id, string $status): void {
  $s = normStatus($status);
  $stmt = db()->prepare('UPDATE reading_books SET status = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('sii', $s, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function deleteBook(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM reading_books WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// The Piers Anthony "Xanth" reading order (from the reading guide). Acquire
// links are Amazon Kindle searches for the exact title, built below.
const XANTH_TITLES = [
  'A Spell for Chameleon', 'The Source of Magic', 'Castle Roogna', 'Centaur Aisle',
  'Ogre, Ogre', 'Night Mare', 'Dragon on a Pedestal', 'Crewel Lye',
  'Golem in the Gears', 'Vale of the Vole', 'Heaven Cent', 'Man from Mundania',
  'Isle of View', 'Question Quest', 'The Color of Her Panties', "Demons Don't Dream",
  'Harpy Thyme', 'Geis of the Gargoyle', 'Roc and a Hard Place', 'Yon Ill Wind',
  'Faun & Games', 'Zombie Lover', 'Xone of Contention', 'The Dastard',
  'Swell Foop', 'Up in a Heaval', 'Cube Route', 'Currant Events',
  'Pet Peeve', 'Stork Naked', 'Air Apparent', 'Two to the Fifth',
  'Jumper Cable', 'Knot Gneiss', 'Well-Tempered Clavicle', 'Luck of the Draw',
  'Esrever Doom', 'Board Stiff', 'Five Portraits', 'Isis Orb',
  'Ghost Writer in the Sky', 'Fire Sail', 'Jest Right', 'Skeleton Key',
  'A Tryst of Fate', 'Six Crystal Princesses', 'Apoca Lips', 'Three Novel Nymphs',
  'Knickelpede Knight',
];

function seedXanth(int $userId): int {
  $chk = db()->prepare("SELECT 1 FROM reading_books WHERE user_id = ? AND series = 'Xanth' LIMIT 1");
  $chk->bind_param('i', $userId);
  $chk->execute();
  $already = (bool)$chk->get_result()->fetch_row();
  $chk->close();
  if ($already) {
    return 0;
  }
  $series = 'Xanth';
  $author = 'Piers Anthony';
  $status = 'Not Started';
  $type = 'Kindle';
  $stmt = db()->prepare(
    'INSERT INTO reading_books (user_id, series, title, author, status, book_type, acquire_url, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $n = 0;
  foreach (XANTH_TITLES as $i => $title) {
    $order = $i + 1;
    $url = 'https://www.amazon.com/s?k=' . rawurlencode('Piers Anthony "' . $title . '" Kindle Xanth');
    $stmt->bind_param('issssssi', $userId, $series, $title, $author, $status, $type, $url, $order);
    $stmt->execute();
    $n++;
  }
  $stmt->close();
  return $n;
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  case 'books': {
    $user = requireMember();
    respond(['ok' => true, 'books' => listBooks((int)$user['id'])]);
  }

  case 'addBook': {
    $user = requireMember();
    $id = addBook((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateBook': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing book id'); }
    updateBook((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'setStatus': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing book id'); }
    setStatus((int)$user['id'], $id, (string)($body['status'] ?? ''));
    respond(['ok' => true]);
  }

  case 'deleteBook': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing book id'); }
    deleteBook((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  case 'seedXanth': {
    $user = requireMember();
    respond(['ok' => true, 'added' => seedXanth((int)$user['id'])]);
  }

  // -- MyDataWorld login (shared with the other apps) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  case 'whoAmI': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') { respond(['ok' => false]); }
    $stmt = db()->prepare(
      'SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    respond($row ? ['ok' => true, 'email' => $row['username']] : ['ok' => false]);
  }

  case 'checkAccess': {
    $user = requireUser();
    if (!hasAppAccess($user)) {
      fail('Not authorized for Reading List', 403);
    }
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
