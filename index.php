<?php
// PWA (Progressive Web App) Handler
if (isset($_GET['pwa'])) {
  // Serve the Web App Manifest
  if ($_GET['pwa'] == 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      "name" => "PHP Music",
      "short_name" => "Music",
      "start_url" => ".",
      "display" => "standalone",
      "background_color" => "#030303",
      "theme_color" => "#121212",
      "description" => "A simple, fast music player with user accounts and uploads.",
      "icons" => [
        [
          "src" => "?action=get_app_icon",
          "sizes" => "any",
          "type" => "image/svg+xml",
          "purpose" => "any"
        ]
      ]
    ]);
    exit;
  }
  // Serve the Service Worker
  if ($_GET['pwa'] == 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo <<<SW
    const CACHE_NAME = 'php-music-cache-v21';
    const STATIC_ASSETS = [
      './',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
      'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js',
      'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2?v=1.11.3'
    ];

    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)));
    });

    self.addEventListener('activate', event => {
      const cacheWhitelist = [CACHE_NAME];
      event.waitUntil(
        caches.keys().then(cacheNames => {
          return Promise.all(
            cacheNames.map(cacheName => {
              if (cacheWhitelist.indexOf(cacheName) === -1) {
                return caches.delete(cacheName);
              }
            })
          );
        })
      );
    });

    self.addEventListener('fetch', event => {
      const url = new URL(event.request.url);
      const isApiCall = url.searchParams.has('action') || url.searchParams.has('share_type');
      const isPwaCall = url.searchParams.has('pwa');

      if (isApiCall || isPwaCall) {
        event.respondWith(fetch(event.request));
        return;
      }
      
      event.respondWith(
        caches.match(event.request).then(response => {
          return response || fetch(event.request).then(networkResponse => {
            if (networkResponse && networkResponse.ok) {
               const responseToCache = networkResponse.clone();
               caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseToCache));
            }
            return networkResponse;
          });
        })
      );
    });
SW;
    exit;
  }
}

header('Content-Type: text/html; charset=utf-8');
session_start();
set_time_limit(0);

define('MUSIC_DIR', __DIR__);
define('DB_FILE', __DIR__ . '/music.db');
define('APP_VERSION', '1.8');
define('PAGE_SIZE', 25);
define('ADMIN_PAGE_SIZE', 20);
define('ADMIN_PASSWORD', 'admin');
define('ADMIN_PASSWORD_HASH', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT));
define('DAILY_UPLOAD_LIMIT', 10);

function get_db() {
  try {
    $db = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_TIMEOUT => 30]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
  } catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
  }
}

if (isset($_GET['access']) && $_GET['access'] === 'admin') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_POST['toggle_verify']) && isset($_POST['user_id'])) {
      $db = get_db();
      $user_id = (int)$_POST['user_id'];
      $stmt = $db->prepare("SELECT verified FROM users WHERE id = ?");
      $stmt->execute([$user_id]);
      $current_status = $stmt->fetchColumn();
      if ($current_status) {
        $new_status = ($current_status === 'yes') ? 'no' : 'yes';
        $update_stmt = $db->prepare("UPDATE users SET verified = ? WHERE id = ?");
        $update_stmt->execute([$new_status, $user_id]);
      }
      header('Location: ' . $_SERVER['REQUEST_URI']);
      exit;
    }
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
      $_SESSION['admin_logged_in'] = true;
      header('Location: ?access=admin');
      exit;
    }
  }

  if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: ?access=admin');
    exit;
  }
  
  $is_admin_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - PHP Music</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
      :root {
        --ytm-bg: #030303;
        --ytm-surface: #121212;
        --ytm-surface-2: #282828;
        --ytm-primary-text: #ffffff;
        --ytm-secondary-text: #aaaaaa;
        --ytm-accent: #ff0000;
      }
      body {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        font-family: 'Roboto', sans-serif;
      }
      .app-container { display: flex; min-height: 100vh; }
      .sidebar {
        width: 240px; background-color: var(--ytm-bg); padding: 1.5rem 0;
        display: flex; flex-direction: column; flex-shrink: 0;
      }
      .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; }
      .content-area-wrapper { padding: 1.5rem 2rem; }
      .sidebar .logo { font-size: 1.5rem; font-weight: 700; padding: 0 1.5rem 1.5rem 1.5rem; }
      .sidebar .logo span { color: var(--ytm-accent); }
      .nav-link {
        color: var(--ytm-secondary-text); display: flex; align-items: center;
        font-weight: 500; border-left: 3px solid transparent; gap: 1rem;
        text-decoration: none; padding: 0.75rem 1.5rem;
      }
      .nav-link:hover { background-color: var(--ytm-surface); color: var(--ytm-primary-text); }
      .page-header { padding: 1.5rem 2rem 1.5rem 2rem; }
      .content-title { font-size: 2rem; font-weight: 700; margin-bottom: 1.5rem; }
      .user-list { background-color: var(--ytm-surface); border-radius: 8px; overflow: hidden; }
      .user-list-header { background-color: var(--ytm-surface-2); font-weight: 500; }
      .user-item > *, .user-list-header > * { min-width: 0; }
      .user-item .text-truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .user-list-header, .user-item {
        display: grid; grid-template-columns: 50px 1fr 1fr 100px 100px 100px 120px;
        align-items: center; gap: 1rem; padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      .user-item { color: var(--ytm-primary-text); }
      .user-item .badge { font-size: 0.85rem; padding: 0.4em 0.6em; }
      .user-item .btn { padding: .25rem .5rem; font-size: .875rem; }
      .login-container { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
      .login-card { background-color: var(--ytm-surface); width: 100%; max-width: 400px; padding: 2rem; border-radius: 8px; }
      .form-control {
        background-color: var(--ytm-surface-2); border: 1px solid #404040; color: var(--ytm-primary-text);
      }
      .form-control:focus { background-color: var(--ytm-surface-2); border-color: #666; color: var(--ytm-primary-text); box-shadow: none; }
      .pagination .page-link {
        background-color: var(--ytm-surface-2);
        border-color: #404040;
        color: var(--ytm-primary-text);
      }
      .pagination .page-item.active .page-link {
        background-color: var(--ytm-accent);
        border-color: var(--ytm-accent);
      }
      .pagination .page-item.disabled .page-link {
        background-color: var(--ytm-surface);
        border-color: #404040;
        color: var(--ytm-secondary-text);
      }
      @media (max-width: 991.98px) {
        .app-container { flex-direction: column; height: auto; }
        .sidebar {
          width: 100%; flex-direction: row; justify-content: space-between;
          align-items: center; padding: 0.5rem 1rem; height: 60px;
        }
        .sidebar .logo { padding: 0; font-size: 1.25rem; }
        .sidebar .nav-link { padding: 0.5rem; }
        .sidebar .nav-link span { display: none; }
        .main-content { overflow-y: visible; }
        .content-area-wrapper { padding: 1rem; }
        .page-header { padding: 1rem; }
        .content-title { font-size: 1.5rem; }
        .user-list-header { display: none; }
        .user-item {
          display: grid; grid-template-columns: 1fr auto; grid-template-rows: auto auto;
          grid-template-areas: "main action" "stats stats"; padding: 1rem; gap: 0.5rem 1rem;
        }
        .user-item-id, .user-item-email-desktop, .user-item-artist-desktop,
        .user-item-verified-desktop, .user-item-last-up-desktop, .user-item-count-desktop {
          display: none;
        }
        .user-item-main {
          grid-area: main; display: flex; flex-direction: column; gap: 0.25rem;
        }
        .user-item-main .user-id-mobile { font-size: 0.8rem; color: var(--ytm-secondary-text); }
        .user-item-main .user-email { font-weight: 500; }
        .user-item-main .user-artist { font-size: 0.9rem; color: var(--ytm-secondary-text); }
        .user-item-action { grid-area: action; display: flex; align-items: center; }
        .user-item-stats {
          grid-area: stats; display: flex; justify-content: space-around; align-items: center;
          border-top: 1px solid var(--ytm-surface-2); padding-top: 0.75rem; margin-top: 0.75rem;
          font-size: 0.8rem; text-align: center;
        }
        .user-item-stats > div { display: flex; flex-direction: column; }
        .user-item-stats .label {
          text-transform: uppercase; color: var(--ytm-secondary-text);
          font-size: 0.7rem; margin-bottom: 0.25rem;
        }
      }
      @media (min-width: 992px) {
        .user-item-main, .user-item-stats { display: none; }
      }
    </style>
  </head>
  <body>
    <?php if (!$is_admin_logged_in): ?>
    <div class="login-container">
      <div class="login-card">
        <h3 class="text-center mb-4">Admin Login</h3>
        <form method="POST" action="?access=admin">
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
          </div>
          <button type="submit" class="btn btn-danger w-100">Login</button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="app-container">
      <nav class="sidebar">
        <div class="logo">Admin<span>Panel</span></div>
        <a href="./" class="nav-link"><i class="bi bi-arrow-left-circle-fill"></i><span>Back to Player</span></a>
        <a href="?access=admin&logout=1" class="nav-link"><i class="bi bi-box-arrow-left"></i><span>Logout</span></a>
      </nav>
      <main class="main-content">
        <div class="page-header">
          <h1 class="content-title">User Management</h1>
        </div>
        <div class="content-area-wrapper">
          <div class="user-list">
            <div class="user-list-header">
              <div>ID</div><div>Email</div><div>Artist</div><div>Verified</div><div>Last Up</div><div>Count</div><div>Action</div>
            </div>
            <?php
              $db = get_db();
              $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
              $offset = ($page - 1) * ADMIN_PAGE_SIZE;

              $total_users_stmt = $db->query("SELECT COUNT(id) FROM users");
              $total_users = $total_users_stmt->fetchColumn();
              $total_pages = ceil($total_users / ADMIN_PAGE_SIZE);

              $stmt = $db->prepare("SELECT id, email, artist, verified, last_upload_date, daily_upload_count FROM users ORDER BY id ASC LIMIT ? OFFSET ?");
              $stmt->bindValue(1, ADMIN_PAGE_SIZE, PDO::PARAM_INT);
              $stmt->bindValue(2, $offset, PDO::PARAM_INT);
              $stmt->execute();
              $users = $stmt->fetchAll();

              foreach ($users as $user):
            ?>
            <div class="user-item">
              <div class="user-item-id"><?php echo htmlspecialchars($user['id']); ?></div>
              <div class="user-item-email-desktop text-truncate"><?php echo htmlspecialchars($user['email']); ?></div>
              <div class="user-item-artist-desktop text-truncate"><?php echo htmlspecialchars($user['artist']); ?></div>
              <div class="user-item-verified-desktop">
                <span class="badge <?php echo $user['verified'] === 'yes' ? 'bg-success' : 'bg-secondary'; ?>">
                  <?php echo htmlspecialchars(strtoupper($user['verified'])); ?>
                </span>
              </div>
              <div class="user-item-last-up-desktop"><?php echo htmlspecialchars($user['last_upload_date'] ?? 'N/A'); ?></div>
              <div class="user-item-count-desktop"><?php echo htmlspecialchars($user['daily_upload_count'] ?? '0'); ?></div>
              <div class="user-item-main">
                <div class="user-id-mobile">ID: <?php echo htmlspecialchars($user['id']); ?></div>
                <div class="user-email text-truncate"><?php echo htmlspecialchars($user['email']); ?></div>
                <div class="user-artist text-truncate"><?php echo htmlspecialchars($user['artist']); ?></div>
              </div>
              <div class="user-item-action">
                <form method="POST" action="?access=admin&page=<?php echo $page; ?>" class="d-inline">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" name="toggle_verify" class="btn <?php echo $user['verified'] === 'yes' ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $user['verified'] === 'yes' ? 'Un-verify' : 'Verify'; ?>
                  </button>
                </form>
              </div>
              <div class="user-item-stats">
                <div>
                  <span class="label">Verified</span>
                  <span class="badge <?php echo $user['verified'] === 'yes' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars(strtoupper($user['verified'])); ?></span>
                </div>
                <div>
                  <span class="label">Last Upload</span>
                  <span><?php echo htmlspecialchars($user['last_upload_date'] ?? 'N/A'); ?></span>
                </div>
                <div>
                  <span class="label">Daily Count</span>
                  <span><?php echo htmlspecialchars($user['daily_upload_count'] ?? '0'); ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($total_pages > 1): ?>
          <nav class="mt-4" aria-label="User pagination">
            <ul class="pagination justify-content-center">
              <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $page - 1; ?>">Previous</a>
              </li>
              <?php for ($i = 1; $i <= $total_pages; $i++): ?>
              <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $i; ?>"><?php echo $i; ?></a>
              </li>
              <?php endfor; ?>
              <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $page + 1; ?>">Next</a>
              </li>
            </ul>
          </nav>
          <?php endif; ?>
        </div>
      </main>
    </div>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  </body>
</html>
<?php
  exit;
}

if (file_exists(__DIR__ . '/getid3/getid3.php')) {
  require_once __DIR__ . '/getid3/getid3.php';
}

function send_json($data) {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    http_response_code(500);
    echo '{"status":"error", "message":"JSON encoding error: ' . json_last_error_msg() . '"}';
  } else {
    echo $json;
  }
  exit;
}

$initialViewJS = '';
if (isset($_GET['share_type']) && isset($_GET['id'])) {
  $db_for_share = get_db();
  $share_type = $_GET['share_type'];
  $share_id_raw = $_GET['id'];
  $view_config = null;

  switch ($share_type) {
    case 'song':
      $stmt = $db_for_share->prepare("SELECT album FROM music WHERE id = ?");
      $stmt->execute([(int)$share_id_raw]);
      $song_info = $stmt->fetch();
      if ($song_info) {
        $view_config = [
          'type' => 'album_songs',
          'param' => rawurlencode($song_info['album']),
          'sort' => 'title_asc',
          'highlight' => (int)$share_id_raw
        ];
      }
      break;
    case 'album':
      $view_config = ['type' => 'album_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'title_asc'];
      break;
    case 'artist':
      $view_config = ['type' => 'artist_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'album_asc'];
      break;
    case 'playlist':
      $stmt = $db_for_share->prepare("SELECT id FROM playlists WHERE public_id = ?");
      $stmt->execute([$share_id_raw]);
      if ($stmt->fetch()) {
        $view_config = ['type' => 'playlist_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'manual_order'];
      }
      break;
  }

  if ($view_config) {
    $initialViewJSON = json_encode($view_config);
    $initialViewJS = "<script>window.initialView = {$initialViewJSON};</script>";
  }
}

function init_db($db) {
  $db->exec("PRAGMA journal_mode=WAL;");
  $db->exec("PRAGMA foreign_keys = ON;");

  $users_columns = $db->query("PRAGMA table_info(users);")->fetchAll(PDO::FETCH_COLUMN, 1);
  $users_table_exists = !empty($users_columns);

  $db->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      email TEXT UNIQUE,
      artist TEXT,
      password_hash TEXT,
      last_upload_date TEXT,
      daily_upload_count INTEGER DEFAULT 0,
      verified TEXT DEFAULT 'no',
      profile_picture BLOB,
      profile_picture_type TEXT
    );
  ");

  if ($users_table_exists) {
    if (!in_array('last_upload_date', $users_columns)) {
      $db->exec("ALTER TABLE users ADD COLUMN last_upload_date TEXT;");
    }
    if (!in_array('daily_upload_count', $users_columns)) {
      $db->exec("ALTER TABLE users ADD COLUMN daily_upload_count INTEGER DEFAULT 0;");
    }
    if (!in_array('verified', $users_columns)) {
      $db->exec("ALTER TABLE users ADD COLUMN verified TEXT DEFAULT 'no';");
    }
    if (!in_array('profile_picture', $users_columns)) {
      $db->exec("ALTER TABLE users ADD COLUMN profile_picture BLOB;");
    }
    if (!in_array('profile_picture_type', $users_columns)) {
      $db->exec("ALTER TABLE users ADD COLUMN profile_picture_type TEXT;");
    }
  }

  $music_columns = $db->query("PRAGMA table_info(music);")->fetchAll(PDO::FETCH_COLUMN, 1);
  $music_table_exists = !empty($music_columns);
  
  $db->exec("
    CREATE TABLE IF NOT EXISTS music (
      id INTEGER PRIMARY KEY,
      user_id INTEGER,
      file TEXT UNIQUE,
      title TEXT,
      artist TEXT,
      album TEXT,
      genre TEXT,
      year INTEGER,
      duration INTEGER,
      image BLOB,
      last_modified INTEGER,
      bitrate INTEGER,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    );
  ");

  if ($music_table_exists) {
    if (!in_array('bitrate', $music_columns)) {
        $db->exec("ALTER TABLE music ADD COLUMN bitrate INTEGER;");
    }
  }
  
  $db->exec("
    CREATE TABLE IF NOT EXISTS favorites (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");
  $db->exec("
    CREATE TABLE IF NOT EXISTS playlists (
      id INTEGER PRIMARY KEY,
      user_id INTEGER NOT NULL,
      name TEXT NOT NULL,
      public_id TEXT UNIQUE NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
  ");
  $db->exec("
    CREATE TABLE IF NOT EXISTS playlist_songs (
      playlist_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (playlist_id, song_id),
      FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS history (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      played_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS play_counts (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      play_count INTEGER DEFAULT 1,
      last_played DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("CREATE INDEX IF NOT EXISTS music_artist_idx ON music(artist);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_album_idx ON music(album);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_genre_idx ON music(genre);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_user_id_idx ON music(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS fav_user_id_idx ON favorites(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS playlists_user_id_idx ON playlists(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS playlists_public_id_idx ON playlists(public_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS playlist_songs_playlist_id_idx ON playlist_songs(playlist_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS history_user_id_idx ON history(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS play_counts_user_id_idx ON play_counts(user_id);");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO users (email, artist, password_hash, verified) VALUES (?, ?, ?, ?)")
      ->execute(['musiclibrary@mail.com', 'Music Library', password_hash('musiclibrary', PASSWORD_DEFAULT), 'yes']);
  }
}

function sanitize_for_path($string) {
  $string = strtolower($string);
  $string = preg_replace('/[^a-z0-9]/', '', $string);
  return empty($string) ? 'unknown' : $string;
}

function get_upload_limit() {
  $max_upload = ini_get('upload_max_filesize');
  $max_post = ini_get('post_max_size');
  return "Max file size: " . min($max_upload, $max_post);
}

function process_image_to_webp($imageData, $target_width = 500, $quality = 75) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    return null;
  }
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) { return null; }

  $resizedImage = imagecreatetruecolor($target_width, $target_width);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $target_width, $target_width, imagesx($sourceImage), imagesy($sourceImage));

  ob_start();
  imagewebp($resizedImage, null, $quality);
  $webpData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  init_db($db);

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $user_id = $_SESSION['user_id'] ?? null;
  $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  switch ($action) {
    case 'get_app_icon':
      header('Content-Type: image/svg+xml');
      $size = intval($_GET['size'] ?? 192);
      echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" fill="white" class="bi bi-boombox-fill" viewBox="0 0 16 16"><path d="M11.538 6.237a.5.5 0 0 0-.738.03l-1.36 2.04a.5.5 0 0 0 .37.823h2.72a.5.5 0 0 0 .37-.823l-1.359-2.04a.5.5 0 0 0-.363-.17z"/><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM4.5 5.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2m7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2M6 6.5a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 0-1h-3a.5.5 0 0 0-.5.5m-1.5 6a.5.5 0 0 0 .5.5h5a.5.5 0 0 0 0-1h-5a.5.5 0 0 0-.5.5"/></svg>';
      exit;

    case 'get_session':
      if ($user_id) {
        $stmt = $db->prepare("SELECT id, email, artist, verified, last_upload_date, daily_upload_count FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user) {
          $today = date('Y-m-d');
          $uploads_today = 0;
          if ($user['last_upload_date'] === $today) {
            $uploads_today = (int)$user['daily_upload_count'];
          }
          $user['uploads_remaining'] = max(0, DAILY_UPLOAD_LIMIT - $uploads_today);
          $user['profile_picture_url'] = "?action=get_profile_picture&id=" . $user['id'] . "&v=" . time();
          send_json(['status' => 'loggedin', 'user' => $user, 'upload_limit' => get_upload_limit()]);
        } else {
          session_destroy();
          send_json(['status' => 'loggedout']);
        }
      } else {
        send_json(['status' => 'loggedout']);
      }
      break;

    case 'register':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
      $artist = trim(htmlspecialchars($data['artist'], ENT_QUOTES, 'UTF-8'));
      $password = $data['password'];

      if (!$email || empty($artist) || strlen($password) < 6) {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'Invalid data. Password needs 6+ characters.']);
      }
      $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        http_response_code(409);
        send_json(['status' => 'error', 'message' => 'Email already registered.']);
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $db->prepare("INSERT INTO users (email, artist, password_hash) VALUES (?, ?, ?)");
      $stmt->execute([$email, $artist, $hash]);
      send_json(['status' => 'success', 'message' => 'Registration successful. An admin will verify your account soon.']);
      break;

    case 'login':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
      $password = $data['password'];

      if (!$email || empty($password)) {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'Email and password are required.']);
      }
      $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      $user = $stmt->fetch();
      if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_artist'] = $user['artist'];
        unset($user['password_hash']);
        $user['profile_picture_url'] = "?action=get_profile_picture&id=" . $user['id'] . "&v=" . time();
        send_json(['status' => 'success', 'user' => $user, 'upload_limit' => get_upload_limit()]);
      } else {
        http_response_code(401);
        send_json(['status' => 'error', 'message' => 'Invalid credentials.']);
      }
      break;

    case 'logout':
      session_destroy();
      send_json(['status' => 'success']);
      break;

    case 'change_password':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $new_password = $data['new_password'];
      if (strlen($new_password) < 6) {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
      }
      $hash = password_hash($new_password, PASSWORD_DEFAULT);
      $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $stmt->execute([$hash, $user_id]);
      send_json(['status' => 'success', 'message' => 'Password changed successfully.']);
      break;

    case 'upload_profile_picture':
      if (!$user_id) { http_response_code(403); send_json(['status' => 'error', 'message' => 'Not logged in.']); }
      if (isset($_FILES['profile_picture'])) {
        $file = $_FILES['profile_picture'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
          http_response_code(400);
          send_json(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
        }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed_types)) {
          http_response_code(400);
          send_json(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, GIF allowed.']);
        }
        $imageData = file_get_contents($file['tmp_name']);
        $webpData = process_image_to_webp($imageData, 200, 80);
        
        if ($webpData) {
          $stmt = $db->prepare("UPDATE users SET profile_picture = ?, profile_picture_type = ? WHERE id = ?");
          $stmt->execute([$webpData, 'image/webp', $user_id]);
          send_json(['status' => 'success', 'message' => 'Profile picture updated.']);
        } else {
          http_response_code(500);
          send_json(['status' => 'error', 'message' => 'Failed to process image.']);
        }
      } else {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'No file uploaded.']);
      }
      break;
      
    case 'get_profile_picture':
      $pic_user_id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT profile_picture, profile_picture_type FROM users WHERE id = ?");
      $stmt->execute([$pic_user_id]);
      $pic_data = $stmt->fetch();
      if ($pic_data && $pic_data['profile_picture']) {
        header('Content-Type: ' . $pic_data['profile_picture_type']);
        echo $pic_data['profile_picture'];
      } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" fill="#404040" viewBox="0 0 16 16"><path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/><path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/></svg>';
      }
      exit;

    case 'full_scan':
      perform_full_scan($db);
      exit;

    case 'upload_song':
      if (!$user_id) { http_response_code(403); exit; }

      $stmt = $db->prepare("SELECT verified, last_upload_date, daily_upload_count FROM users WHERE id = ?");
      $stmt->execute([$user_id]);
      $user_data = $stmt->fetch();

      if (!$user_data) {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'User not found.']);
      }

      if ($user_data['verified'] !== 'yes') {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'Your account is not verified for uploads.']);
      }

      $today = date('Y-m-d');
      $daily_upload_count = 0;
      if ($user_data['last_upload_date'] === $today) {
        $daily_upload_count = (int)$user_data['daily_upload_count'];
      }

      if ($daily_upload_count >= DAILY_UPLOAD_LIMIT) {
        http_response_code(429);
        send_json(['status' => 'error', 'message' => 'Daily upload limit of ' . DAILY_UPLOAD_LIMIT . ' songs reached.']);
      }

      if (!class_exists('getID3')) {
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'getID3 library is missing.']);
      }
      if (isset($_FILES['song'])) {
        $file = $_FILES['song'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            send_json(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
        }
        $getID3 = new getID3;
        $info = $getID3->analyze($file['tmp_name']);
        getid3_lib::CopyTagsToComments($info);

        $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
        $artist_path = sanitize_for_path($artist);
        $upload_dir = MUSIC_DIR . '/uploads/' . $artist_path;
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }

        $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($file['name']));
        $filePath = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
          $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
          $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
          $year = (int)($info['comments']['year'][0] ?? 0);
          $duration = (int)($info['playtime_seconds'] ?? 0);
          $bitrate = (int)($info['audio']['bitrate'] ?? 0);
          $genre = trim($info['comments']['genre'][0] ?? '') ?: trim($_POST['genre'] ?? '') ?: 'Uploaded';
          $raw_image_data = isset($info['comments']['picture'][0]['data']) ? $info['comments']['picture'][0]['data'] : null;
          $webp_image_data = process_image_to_webp($raw_image_data);

          $stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, bitrate, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $stmt->execute([$user_id, $filePath, $title, $artist, $album, $genre, $year, $duration, $bitrate, $webp_image_data, time()]);

          $new_count = ($user_data['last_upload_date'] === $today) ? $daily_upload_count + 1 : 1;
          $update_stmt = $db->prepare("UPDATE users SET daily_upload_count = ?, last_upload_date = ? WHERE id = ?");
          $update_stmt->execute([$new_count, $today, $user_id]);

          send_json(['status' => 'success', 'message' => 'File ' . $filename . ' uploaded.']);
        } else {
          http_response_code(500);
          send_json(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
        }
      }
      break;

    case 'delete_song':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);

      $stmt = $db->prepare("SELECT file, user_id FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();
      
      if ($song && ($song['user_id'] == $user_id || $_SESSION['user_artist'] == 'Music Library')) {
        $db->prepare("DELETE FROM music WHERE id = ?")->execute([$song_id]);
        if ($song['file'] && file_exists($song['file']) && strpos(realpath($song['file']), realpath(MUSIC_DIR . '/uploads')) === 0) {
          @unlink($song['file']);
        }
        send_json(['status' => 'success', 'message' => 'Song deleted.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'You do not have permission.']);
      }
      break;

    case 'download_song':
      $song_id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && file_exists($song['file'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        header('Content-Type: ' . finfo_file($finfo, $song['file']));
        finfo_close($finfo);
        header('Content-Length: ' . filesize($song['file']));
        header('Content-Disposition: attachment; filename="' . basename($song['file']) . '"');
        ob_clean();
        flush();
        readfile($song['file']);
        exit;
      } else {
        http_response_code(404);
        send_json(['status' => 'error', 'message' => 'File not found.']);
      }
      break;

    case 'edit_genre':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $new_genre = trim(htmlspecialchars($data['genre'] ?? '', ENT_QUOTES, 'UTF-8'));

      if (empty($new_genre)) {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'Genre cannot be empty.']);
      }

      $stmt = $db->prepare("SELECT user_id FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && ($song['user_id'] == $user_id || $_SESSION['user_artist'] == 'Music Library')) {
        $stmt = $db->prepare("UPDATE music SET genre = ? WHERE id = ?");
        $stmt->execute([$new_genre, $song_id]);
        send_json(['status' => 'success', 'message' => 'Genre updated successfully.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'You do not have permission to edit this song.']);
      }
      break;
    
    case 'get_songs':
      $sort_key = $_GET['sort'] ?? 'artist_asc';
      $sort_map = [
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'id_desc' => 'ORDER BY m.id DESC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['artist_asc'];

      $where_clauses = [];
      $params = [$user_id];

      if (!empty($_GET['artist'])) {
        $where_clauses[] = 'm.artist = ?';
        $params[] = $_GET['artist'];
      }
      if (!empty($_GET['album'])) {
        $where_clauses[] = 'm.album = ?';
        $params[] = $_GET['album'];
      }
      if (!empty($_GET['genre'])) {
        $where_clauses[] = 'm.genre = ?';
        $params[] = $_GET['genre'];
      }
      $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
      
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? " . $where_sql . " " . $order_by . $limit_clause);
      $stmt->execute($params);
      send_json($stmt->fetchAll());
      break;

    case 'get_profile_songs':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'title_asc';
      $sort_map = [
        'id_desc' => 'ORDER BY m.id DESC',
        'id_asc' => 'ORDER BY m.id ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['title_asc'];
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_favorites':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY f.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, 1 as is_favorite FROM music m JOIN favorites f ON m.id = f.song_id WHERE f.user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_playlist_songs':
      if (!$user_id) { send_json([]); }
      $public_id = $_GET['public_id'];
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY ps.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      
      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        JOIN playlist_songs ps ON m.id = ps.song_id
        JOIN playlists p ON ps.playlist_id = p.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE p.public_id = ?
        {$order_by} {$limit_clause}
      ");
      $stmt->execute([$user_id, $public_id]);
      send_json($stmt->fetchAll());
      break;

    case 'toggle_favorite':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $stmt = $db->prepare("SELECT song_id FROM favorites WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM favorites WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        send_json(['status' => 'removed', 'is_favorite' => false]);
      } else {
        $stmt = $db->prepare("SELECT MAX(sort_order) as max_order FROM favorites WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $max_order = $stmt->fetchColumn() ?? 0;
        $db->prepare("INSERT INTO favorites (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
        send_json(['status' => 'added', 'is_favorite' => true]);
      }
      break;

    case 'update_favorite_order':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $ordered_ids = $data['ids'];
      $db->beginTransaction();
      try {
        foreach ($ordered_ids as $index => $song_id) {
          $db->prepare("UPDATE favorites SET sort_order = ? WHERE user_id = ? AND song_id = ?")
             ->execute([$index, $user_id, $song_id]);
        }
        $db->commit();
        send_json(['status' => 'success']);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'Failed to update order.']);
      }
      break;

    case 'get_view_ids':
      $post_data = json_decode(file_get_contents('php://input'), true);
      $view_type = $post_data['view_type'] ?? '';
      $param = $post_data['param'] ?? '';
      $sort = $post_data['sort'] ?? '';

      if (in_array($view_type, ['artist_songs', 'album_songs', 'genre_songs', 'playlist_songs'])) {
        $param = urldecode($param);
      }

      $sql = "SELECT m.id FROM music m ";
      $conditions = "";
      $params = [];
      $default_sort = 'artist_asc';

      switch ($view_type) {
        case 'get_songs': break;
        case 'get_profile_songs':
          if (!$user_id) { send_json([]); }
          $conditions = "WHERE m.user_id = ?";
          $params[] = $user_id;
          $default_sort = 'title_asc';
          break;
        case 'get_favorites':
          if (!$user_id) { send_json([]); }
          $sql = "SELECT m.id FROM music m JOIN favorites f ON m.id = f.song_id ";
          $conditions = "WHERE f.user_id = ?";
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'get_history':
          if (!$user_id) { send_json([]); }
          $sql = "SELECT m.id FROM music m JOIN history h ON m.id = h.song_id ";
          $conditions = "WHERE h.user_id = ? GROUP BY m.id";
          $params[] = $user_id;
          $default_sort = 'history_desc';
          break;
        case 'artist_songs':
          $conditions = "WHERE m.artist = ?";
          $params[] = $param;
          $default_sort = 'album_asc';
          break;
        case 'album_songs':
          $conditions = "WHERE m.album = ?";
          $params[] = $param;
          $default_sort = 'title_asc';
          break;
        case 'genre_songs':
          $conditions = "WHERE m.genre = ?";
          $params[] = $param;
          $default_sort = 'artist_asc';
          break;
        case 'playlist_songs':
          $sql = "SELECT m.id FROM music m JOIN playlist_songs ps ON m.id = ps.song_id JOIN playlists p ON ps.playlist_id = p.id ";
          $conditions = "WHERE p.public_id = ?";
          $params[] = $param;
          $default_sort = 'manual_order';
          break;
        case 'search':
          $conditions = "WHERE m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param];
          break;
        case 'get_recommendations':
          send_json([]);
        default:
          send_json([]);
      }
      $sort_map = [
        'manual_order' => 'ORDER BY ps.sort_order ASC',
        'id_desc' => 'ORDER BY m.id DESC',
        'id_asc' => 'ORDER BY m.id ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'history_desc' => 'ORDER BY MAX(h.played_at) DESC',
      ];
      if ($view_type === 'get_favorites') {
        $sort_map['manual_order'] = 'ORDER BY f.sort_order ASC';
      }
      $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];
      
      $stmt = $db->prepare($sql . $conditions . " " . $order_by);
      $stmt->execute($params);
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_artists':
        $sort_key = $_GET['sort'] ?? 'name_asc';
        $sort_map = [
            'name_asc' => 'ORDER BY artist COLLATE NOCASE ASC',
            'name_desc' => 'ORDER BY artist COLLATE NOCASE DESC',
        ];
        $order_by = $sort_map[$sort_key] ?? $sort_map['name_asc'];
        $stmt = $db->query("SELECT DISTINCT artist FROM music WHERE artist != '' AND artist IS NOT NULL " . $order_by);
        send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
        break;

    case 'get_albums':
        $sort_key = $_GET['sort'] ?? 'album_asc';
        $sort_map = [
            'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
            'album_desc' => 'ORDER BY m.album COLLATE NOCASE DESC',
            'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
            'artist_desc' => 'ORDER BY m.artist COLLATE NOCASE DESC',
            'year_desc' => 'ORDER BY MAX(m.year) DESC',
            'year_asc' => 'ORDER BY MAX(m.year) ASC',
        ];
        $order_by = $sort_map[$sort_key] ?? $sort_map['album_asc'];
        $stmt = $db->query("SELECT m.album, m.artist, MAX(m.id) as id FROM music m WHERE m.album != '' AND m.album IS NOT NULL GROUP BY m.album " . $order_by);
        send_json($stmt->fetchAll());
        break;
    
    case 'get_genres':
      $stmt = $db->query("SELECT DISTINCT genre FROM music WHERE genre != '' AND genre IS NOT NULL ORDER BY genre COLLATE NOCASE");
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;
    
    case 'get_all_genres':
      $stmt = $db->query("SELECT DISTINCT genre FROM music WHERE genre != '' AND genre IS NOT NULL ORDER BY genre COLLATE NOCASE");
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_view_data':
      $type = $_GET['type'] ?? '';
      $name = rawurldecode($_GET['name'] ?? '');
      $sort = $_GET['sort'] ?? 'artist_asc';
      if (empty($type)) { http_response_code(400); exit; }
      
      $details = null;
      $songs = [];

      if ($type === 'profile') {
        if (!$user_id) { http_response_code(403); exit; }
        
        $stmt_user = $db->prepare("SELECT artist FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_details = $stmt_user->fetch();

        if (!$user_details) { http_response_code(404); exit; }

        $stmt_stats = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration FROM music WHERE user_id = ?");
        $stmt_stats->execute([$user_id]);
        $details = $stmt_stats->fetch();
        
        $details['name'] = $user_details['artist'];
        $details['image_url'] = '?action=get_profile_picture&id=' . $user_id;
        $details['public_id'] = null;

        $sort_map = [
          'id_desc' => 'ORDER BY m.id DESC',
          'id_asc' => 'ORDER BY m.id ASC',
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
          'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
          'year_desc' => 'ORDER BY m.year DESC',
          'year_asc' => 'ORDER BY m.year ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['title_asc'];

        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.user_id = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $user_id]);
        $songs = $stmt_songs->fetchAll();
      } elseif ($type === 'playlist') {
        if (empty($name)) { http_response_code(400); exit; }
        $stmt_details = $db->prepare("
          SELECT p.name, p.public_id, u.artist as creator,
          (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = p.id) as song_count,
          (SELECT SUM(m.duration) FROM music m JOIN playlist_songs ps ON m.id = ps.song_id WHERE ps.playlist_id = p.id) as total_duration,
          (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
          FROM playlists p JOIN users u ON p.user_id = u.id
          WHERE p.public_id = ?
        ");
        $stmt_details->execute([$name]);
        $details = $stmt_details->fetch();
        if ($details) {
          $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
        }
        $sort_map = [
          'manual_order' => 'ORDER BY ps.sort_order ASC', 'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC',
          'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC', 'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['manual_order'];
        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m JOIN playlist_songs ps ON m.id = ps.song_id JOIN playlists p ON ps.playlist_id = p.id LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE p.public_id = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $name]);
        $songs = $stmt_songs->fetchAll();
      } elseif (in_array($type, ['artist', 'album', 'genre'])) {
        if (empty($name)) { http_response_code(400); exit; }
        $field = $type;
        $stmt_details = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration, MAX(id) as image_id FROM music WHERE {$field} = ?");
        $stmt_details->execute([$name]);
        $details = $stmt_details->fetch();
        $details['name'] = $name;
        $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
        $details['public_id'] = null;

        $sort_map = [
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC', 'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC', 'year_desc' => 'ORDER BY m.year DESC', 'year_asc' => 'ORDER BY m.year ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['artist_asc'];
        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.{$field} = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $name]);
        $songs = $stmt_songs->fetchAll();
      }
      send_json(['details' => $details, 'songs' => $songs]);
      break;

    case 'get_user_profile':
        if (!$user_id) { http_response_code(403); exit; }
        $stmt_user = $db->prepare("SELECT artist FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $profile = $stmt_user->fetch();

        if (!$profile) { http_response_code(404); exit; }
        
        $profile['image_url'] = '?action=get_profile_picture&id=' . $user_id . '&v=' . time();
        send_json($profile);
        break;

    case 'get_user_stats':
        if (!$user_id) { http_response_code(403); exit; }
        $stats = [];
        $stats_queries = [
            'uploads' => "SELECT COUNT(*) FROM music WHERE user_id = {$user_id}",
            'favorites' => "SELECT COUNT(*) FROM favorites WHERE user_id = {$user_id}",
            'playlists' => "SELECT COUNT(*) FROM playlists WHERE user_id = {$user_id}",
            'play_count' => "SELECT SUM(play_count) FROM play_counts WHERE user_id = {$user_id}"
        ];
        foreach ($stats_queries as $key => $query) {
            $stats[$key] = $db->query($query)->fetchColumn() ?: 0;
        }
        send_json(['stats' => $stats]);
        break;

    case 'search':
      $query = '%' . ($_GET['q'] ?? '') . '%';
      $order_by = 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC';
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?) " . $order_by . " " . $limit_clause);
      $stmt->execute([$user_id, $query, $query, $query]);
      send_json($stmt->fetchAll());
      break;

    case 'get_song_data':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT m.id, m.file, m.title, m.artist, m.album, m.genre, m.year, m.duration, m.bitrate, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.id = ?");
      $stmt->execute([$user_id, $id]);
      $song = $stmt->fetch();
      if ($song) {
        $song['stream_url'] = '?action=get_stream&id=' . $song['id'];
        $song['image_url'] = '?action=get_image&id=' . $song['id'];
      }
      send_json($song);
      break;

    case 'get_stream':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $file_path = $stmt->fetchColumn();
      if ($file_path && file_exists($file_path)) {
        $filesize = filesize($file_path);
        header('Content-Type: audio/mpeg');
        header('Accept-Ranges: bytes');
        
        if (isset($_SERVER['HTTP_RANGE'])) {
          $range = $_SERVER['HTTP_RANGE'];
          $range = str_replace('bytes=', '', $range);
          list($start, $end) = explode('-', $range, 2);
          $start = intval($start);
          if (!$end) {
            $end = $filesize - 1;
          } else {
            $end = intval($end);
          }
          $length = $end - $start + 1;
          
          header('HTTP/1.1 206 Partial Content');
          header('Content-Length: ' . $length);
          header("Content-Range: bytes $start-$end/$filesize");

          $f = @fopen($file_path, 'rb');
          if ($f) {
            fseek($f, $start);
            echo fread($f, $length);
            fclose($f);
          }
        } else {
          header('Content-Length: ' . $filesize);
          readfile($file_path);
        }
      } else {
        http_response_code(404);
      }
      exit;

    case 'get_image':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $image_data = $stmt->fetchColumn();
      if ($image_data) {
        header('Content-Type: image/webp');
        echo $image_data;
      } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" fill="#404040" class="bi bi-music-note" viewBox="0 0 16 16"><path d="M9 13c0 1.105-1.12 2-2.5 2S4 14.105 4 13s1.12-2 2.5-2 2.5.895 2.5 2"/><path fill-rule="evenodd" d="M9 3v10H8V3h1z"/><path d="M8 2.82a1 1 0 0 1 .804-.98l3-.6A1 1 0 0 1 13 2.22V4L8 5V2.82z"/></svg>';
      }
      exit;

    case 'get_user_playlists':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'name_asc';
      $sort_map = [
        'name_asc' => 'ORDER BY p.name COLLATE NOCASE ASC',
        'name_desc' => 'ORDER BY p.name COLLATE NOCASE DESC',
        'modified_desc' => 'ORDER BY p.created_at DESC',
        'modified_asc' => 'ORDER BY p.created_at ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['name_asc'];
      $stmt = $db->prepare("
        SELECT p.id, p.name, p.public_id, COUNT(ps.song_id) as song_count,
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
        FROM playlists p LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id
        WHERE p.user_id = ?
        GROUP BY p.id, p.name, p.public_id
        {$order_by}
      ");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'create_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($name)) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Playlist name cannot be empty.']);
      }
      $public_id = bin2hex(random_bytes(8));
      $stmt = $db->prepare("INSERT INTO playlists (user_id, name, public_id) VALUES (?, ?, ?)");
      $stmt->execute([$user_id, $name, $public_id]);
      send_json(['status' => 'success', 'message' => 'Playlist created.']);
      break;

    case 'edit_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $new_name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($new_name)) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Playlist name cannot be empty.']);
      }
      $stmt = $db->prepare("UPDATE playlists SET name = ? WHERE public_id = ? AND user_id = ?");
      $stmt->execute([$new_name, $public_id, $user_id]);
      if ($stmt->rowCount() > 0) {
        send_json(['status' => 'success', 'message' => 'Playlist updated.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'Permission denied or playlist not found.']);
      }
      break;

    case 'delete_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $stmt = $db->prepare("DELETE FROM playlists WHERE public_id = ? AND user_id = ?");
      $stmt->execute([$public_id, $user_id]);
      if ($stmt->rowCount() > 0) {
        send_json(['status' => 'success', 'message' => 'Playlist deleted.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'Permission denied or playlist not found.']);
      }
      break;

    case 'add_to_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $playlist_id = intval($data['playlist_id']);
      $song_id = intval($data['song_id']);
      
      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
      $stmt_owner->execute([$playlist_id, $user_id]);
      if (!$stmt_owner->fetch()) {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Not your playlist.']);
      }

      $stmt_exists = $db->prepare("SELECT song_id FROM playlist_songs WHERE playlist_id = ? AND song_id = ?");
      $stmt_exists->execute([$playlist_id, $song_id]);
      if ($stmt_exists->fetch()) {
        send_json(['status' => 'exists', 'message' => 'Song is already in this playlist.']);
      }

      $stmt_order = $db->prepare("SELECT MAX(sort_order) as max_order FROM playlist_songs WHERE playlist_id = ?");
      $stmt_order->execute([$playlist_id]);
      $max_order = $stmt_order->fetchColumn() ?? 0;

      $stmt = $db->prepare("INSERT INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");
      $stmt->execute([$playlist_id, $song_id, $max_order + 1]);
      send_json(['status' => 'success', 'message' => 'Added to playlist.']);
      break;

    case 'remove_from_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['playlist_public_id'];
      $song_id = intval($data['song_id']);

      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE public_id = ? AND user_id = ?");
      $stmt_owner->execute([$public_id, $user_id]);
      $playlist = $stmt_owner->fetch();
      if (!$playlist) {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'Permission denied or playlist not found.']);
        exit;
      }

      $stmt = $db->prepare("DELETE FROM playlist_songs WHERE playlist_id = ? AND song_id = ?");
      $stmt->execute([$playlist['id'], $song_id]);
      send_json(['status' => 'success', 'message' => 'Song removed from playlist.']);
      break;

    case 'update_playlist_order':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['playlist_public_id'];
      $ordered_ids = $data['ids'];

      $stmt = $db->prepare("SELECT id FROM playlists WHERE public_id = ? AND user_id = ?");
      $stmt->execute([$public_id, $user_id]);
      $playlist = $stmt->fetch();
      if (!$playlist) {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Permission denied.']);
      }

      $db->beginTransaction();
      try {
        foreach ($ordered_ids as $index => $song_id) {
          $db->prepare("UPDATE playlist_songs SET sort_order = ? WHERE playlist_id = ? AND song_id = ?")
             ->execute([$index, $playlist['id'], $song_id]);
        }
        $db->commit();
        send_json(['status' => 'success']);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'Failed to update order.']);
      }
      break;
      
    case 'log_play':
      if (!$user_id) {
        exit;
      }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id'] ?? 0);

      if ($song_id > 0) {
        try {
          $db->beginTransaction();

          $db->prepare("DELETE FROM history WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
          $db->prepare("INSERT INTO history (user_id, song_id) VALUES (?, ?)")->execute([$user_id, $song_id]);

          $db->prepare("
            INSERT INTO play_counts (user_id, song_id, play_count, last_played) VALUES (?, ?, 1, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id, song_id) DO UPDATE SET
              play_count = play_count + 1,
              last_played = CURRENT_TIMESTAMP
          ")->execute([$user_id, $song_id]);
          
          $db->commit();
        } catch (Exception $e) {
          if ($db->inTransaction()) {
            $db->rollBack();
          }
          error_log('PHP Music log_play error: ' . $e->getMessage());
        }
      }
      
      send_json(['status' => 'success']);
      break;
      
    case 'get_history':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("
        SELECT MAX(h.played_at) AS played_at, m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM history h
        JOIN music m ON h.song_id = m.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE h.user_id = ?
        GROUP BY m.id
        ORDER BY played_at DESC
        {$limit_clause}
      ");
      $stmt->execute([$user_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'clear_history':
      if (!$user_id) { http_response_code(403); exit; }
      $db->prepare("DELETE FROM history WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM play_counts WHERE user_id = ?")->execute([$user_id]);
      send_json(['status' => 'success', 'message' => 'Playback history cleared.']);
      break;

    case 'get_recommendations':
      if (!$user_id) { send_json(['shelves' => []]); }
      $shelves = [];
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite";
      $album_fields = "m.album, m.artist, MAX(m.id) as id";

      $recent_songs_stmt = $db->prepare("
        SELECT {$song_fields}
        FROM history h JOIN music m ON h.song_id = m.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
        WHERE h.user_id = :user_id GROUP BY m.id ORDER BY MAX(h.played_at) DESC LIMIT 10
      ");
      $recent_songs_stmt->execute([':user_id' => $user_id]);
      $recent_songs = $recent_songs_stmt->fetchAll();
      if (count($recent_songs) > 0) {
        $shelves[] = ['title' => 'Recently Played', 'type' => 'songs', 'items' => $recent_songs, 'connected_view' => 'get_history'];
      }

      $top_artists_stmt = $db->prepare("
        SELECT m.artist FROM play_counts pc JOIN music m ON pc.song_id = m.id
        WHERE pc.user_id = ? AND m.artist != 'Unknown Artist'
        GROUP BY m.artist ORDER BY SUM(pc.play_count) DESC LIMIT 1
      ");
      $top_artists_stmt->execute([$user_id]);
      $top_artist = $top_artists_stmt->fetchColumn();

      if ($top_artist) {
        $more_from_artist_stmt = $db->prepare("
          SELECT {$album_fields} FROM music m
          WHERE m.artist = :artist_name AND m.album != 'Unknown Album'
          AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
          GROUP BY m.album ORDER BY RANDOM() LIMIT 10
        ");
        $more_from_artist_stmt->execute([':user_id' => $user_id, ':artist_name' => $top_artist]);
        $artist_albums = $more_from_artist_stmt->fetchAll();
        if (count($artist_albums) > 0) {
          $shelves[] = ['title' => 'More from ' . $top_artist, 'type' => 'albums', 'items' => $artist_albums, 'connected_view' => 'artist_songs', 'param' => urlencode($top_artist)];
        }
      }

      $top_genres_stmt = $db->prepare("
        SELECT m.genre FROM play_counts pc JOIN music m ON pc.song_id = m.id
        WHERE pc.user_id = ? AND m.genre != '' AND m.genre IS NOT NULL AND m.genre != 'Unknown Genre'
        GROUP BY m.genre ORDER BY SUM(pc.play_count) DESC LIMIT 2
      ");
      $top_genres_stmt->execute([$user_id]);
      $top_genres = $top_genres_stmt->fetchAll(PDO::FETCH_COLUMN);

      if (count($top_genres) > 0) {
        $genre_placeholders = implode(',', array_fill(0, count($top_genres), '?'));
        $genre_mix_stmt = $db->prepare("
          SELECT {$song_fields} FROM music m
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.genre IN ({$genre_placeholders}) AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = ?)
          ORDER BY RANDOM() LIMIT 15
        ");
        $genre_mix_stmt->execute(array_merge([$user_id], $top_genres, [$user_id]));
        $genre_songs = $genre_mix_stmt->fetchAll();
        if (count($genre_songs) > 0) {
          $shelves[] = ['title' => 'Your Genre Mix', 'type' => 'songs', 'items' => $genre_songs];
        }
      }
      
      $recent_favorites_stmt = $db->prepare("
        SELECT {$song_fields} FROM favorites f JOIN music m ON f.song_id = m.id
        LEFT JOIN favorites f2 ON m.id = f2.song_id AND f2.user_id = :user_id
        WHERE f.user_id = :user_id
        ORDER BY f.sort_order DESC LIMIT 10
      ");
      $recent_favorites_stmt->execute([':user_id' => $user_id]);
      $recent_favorites = $recent_favorites_stmt->fetchAll();
      if (count($recent_favorites) > 0) {
          $shelves[] = ['title' => 'Your Favorites', 'type' => 'songs', 'items' => $recent_favorites, 'connected_view' => 'get_favorites'];
      }

      $recent_playlists_stmt = $db->prepare("
          SELECT p.name, p.public_id, u.artist as creator,
          (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
          FROM playlists p JOIN users u ON p.user_id = u.id
          WHERE p.user_id = :user_id
          ORDER BY p.created_at DESC LIMIT 5
      ");
      $recent_playlists_stmt->execute([':user_id' => $user_id]);
      $recent_playlists = $recent_playlists_stmt->fetchAll();
      if (count($recent_playlists) > 0) {
          $shelves[] = ['title' => 'Your Playlists', 'type' => 'playlists', 'items' => $recent_playlists, 'connected_view' => 'get_user_playlists'];
      }

      $discovery_songs_stmt = $db->prepare("
        SELECT {$song_fields} FROM music m
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
        WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
        ORDER BY RANDOM() LIMIT 15
      ");
      $discovery_songs_stmt->execute([':user_id' => $user_id]);
      $discovery_songs = $discovery_songs_stmt->fetchAll();
      if (count($discovery_songs) > 0) {
        $shelves[] = ['title' => 'Discover New Songs', 'type' => 'songs', 'items' => $discovery_songs];
      }
      
      $discovery_stmt = $db->prepare("
        SELECT {$album_fields} FROM music m
        WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id) AND m.album != 'Unknown Album'
        GROUP BY m.album ORDER BY RANDOM() LIMIT 10
      ");
      $discovery_stmt->execute([':user_id' => $user_id]);
      $discovery_albums = $discovery_stmt->fetchAll();
      if (count($discovery_albums) > 0) {
        $shelves[] = ['title' => 'Discover New Albums', 'type' => 'albums', 'items' => $discovery_albums];
      }

      send_json(['shelves' => $shelves]);
      break;
  }
  exit;
}

function perform_full_scan($db) {
  ini_set('memory_limit', '512M');
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  header('Content-Type: text/plain; charset=utf-8');
  ob_implicit_flush();

  echo "PHP Music Library - Full Scan\n";
  echo "===================================\n\n";

  if (!class_exists('getID3')) {
    die("FATAL ERROR: getID3 library not found in " . __DIR__ . "/getid3/\n");
  }

  echo "Step 1: Database ready.\n\n";

  echo "Step 2: Verifying 'Music Library' user...\n";
  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  $library_user_id = $stmt->fetchColumn();
  if (!$library_user_id) {
    die("FATAL ERROR: 'Music Library' user could not be found or created.\n");
  }
  echo "'Music Library' user ID: {$library_user_id}\n\n";

  echo "Step 3: Fetching existing music records from database...\n";
  $stmt = $db->query("SELECT file, last_modified FROM music WHERE user_id = " . $library_user_id);
  $db_files = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  echo "Found " . count($db_files) . " records in the database.\n\n";

  echo "Step 4: Scanning music directory for files...\n";
  $files_on_disk = [];
  $uploads_path = realpath(MUSIC_DIR . '/uploads');
  $directory = new RecursiveDirectoryIterator(MUSIC_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
  $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
  foreach ($iterator as $file) {
    if ($file->isDir()){
      continue;
    }
    $filePath = $file->getRealPath();
    if ($uploads_path && strpos($filePath, $uploads_path) === 0) {
      continue;
    }
    if (preg_match('/\.(mp3|m4a|flac|ogg|wav)$/i', $filePath)) {
      $files_on_disk[$filePath] = $file->getMTime();
    }
  }
  echo "Found " . count($files_on_disk) . " music files on disk.\n\n";

  echo "Step 5: Comparing disk files with database records...\n";
  $files_to_add = array_diff_key($files_on_disk, $db_files);
  $files_to_delete = array_diff_key($db_files, $files_on_disk);
  $files_to_update = array_intersect_key($files_on_disk, $db_files);

  echo " - To add: " . count($files_to_add) . "\n";
  echo " - To update (all existing): " . count($files_to_update) . "\n";
  echo " - To delete: " . count($files_to_delete) . "\n\n";

  $files_to_process = $files_to_add + $files_to_update;
  if (empty($files_to_process) && empty($files_to_delete)) {
    die("Scan complete. No changes detected.\n");
  }

  echo "Step 6: Processing changes...\n";
  $getID3 = new getID3;
  $insert_stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, bitrate, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $update_stmt = $db->prepare("UPDATE music SET title=?, artist=?, album=?, genre=?, year=?, duration=?, bitrate=?, image=?, last_modified=? WHERE file=?");
  $delete_stmt = $db->prepare("DELETE FROM music WHERE file = ?");
  $processed_count = 0;
  $total_to_process = count($files_to_process) + count($files_to_delete);

  $db->beginTransaction();
  try {
    foreach ($files_to_process as $filePath => $mtime) {
      $processed_count++;
      echo "[$processed_count/$total_to_process] Processing: " . basename($filePath) . "\n";
      
      $info = $getID3->analyze($filePath);
      getid3_lib::CopyTagsToComments($info);
      
      $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
      $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
      $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
      $genre = trim($info['comments']['genre'][0] ?? 'Unknown Genre');
      $year = (int)($info['comments']['year'][0] ?? 0);
      $duration = (int)($info['playtime_seconds'] ?? 0);
      $bitrate = (int)($info['audio']['bitrate'] ?? 0);
      $raw_image_data = $info['comments']['picture'][0]['data'] ?? null;
      $webp_image_data = process_image_to_webp($raw_image_data);
      
      if (isset($files_to_add[$filePath])) {
        $insert_stmt->execute([$library_user_id, $filePath, $title, $artist, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $mtime]);
      } else {
        $update_stmt->execute([$title, $artist, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $mtime, $filePath]);
      }
    }

    foreach ($files_to_delete as $filePath => $mtime) {
      $processed_count++;
      echo "[$processed_count/$total_to_process] Deleting: " . basename($filePath) . "\n";
      $delete_stmt->execute([$filePath]);
    }
    
    $db->commit();
  } catch (Exception $e) {
    if ($db->inTransaction()) {
      $db->rollBack();
    }
    die("\nERROR: An exception occurred during database operations: " . $e->getMessage() . "\nProcess aborted.\n");
  }

  echo "\n=======================\n";
  echo "Scan completed successfully!\n";
  echo "Total files processed: $processed_count\n";
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Music</title>
    <link rel="icon" type="image/svg+xml" href="?action=get_app_icon" />
    <meta name="theme-color" content="#121212"/>
    <link rel="manifest" href="?pwa=manifest">
    <script>
      (function() {
        const appVersion = '<?php echo APP_VERSION; ?>';
        const storedVersion = localStorage.getItem('appVersion');

        if (storedVersion !== appVersion) {
          localStorage.clear();
          sessionStorage.clear();
          localStorage.setItem('appVersion', appVersion);

          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
              for (let registration of registrations) {
                registration.unregister();
              }
            });
          }
          if ('caches' in window) {
            caches.keys().then(function(names) {
              for (let name of names) {
                caches.delete(name);
              }
            });
          }
        }
      })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <?php echo $initialViewJS; ?>
    <style>
      :root {
        --ytm-bg: #030303;
        --ytm-surface: #121212;
        --ytm-surface-2: #282828;
        --ytm-primary-text: #ffffff;
        --ytm-secondary-text: #aaaaaa;
        --ytm-accent: #ff0000;
        --header-height-mobile: 64px;
      }
      html, body {
        height: 100%;
      }
      body {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        font-family: 'Roboto', sans-serif;
        margin: 0;
      }
      body.player-visible {
        padding-bottom: 90px;
      }
      ::-webkit-scrollbar { width: 8px; }
      ::-webkit-scrollbar-track { background: var(--ytm-surface); }
      ::-webkit-scrollbar-thumb { background: var(--ytm-surface-2); border-radius: 4px;}
      ::-webkit-scrollbar-thumb:hover { background: #555; }
      .app-container {
        display: flex;
        height: 100%;
      }
      .sidebar {
        width: 240px;
        background-color: var(--ytm-bg);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
      }
      .main-content {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
      }
      .content-area-wrapper {
        padding: 1.5rem 2rem;
        flex-grow: 1;
      }
      .view-details-header {
        display: flex;
        align-items: flex-end;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1rem;
        background-color: var(--ytm-surface);
        border-radius: 8px;
      }
      .view-details-header-info {
        min-width: 0;
        flex-grow: 1;
      }
      .view-details-header img,
      .profile-picture-lg {
        width: 150px;
        height: 150px;
        object-fit: cover;
        border-radius: 6px;
        flex-shrink: 0;
        background-color: var(--ytm-surface-2);
      }
      .view-details-header-info .type {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--ytm-secondary-text);
      }
      .view-details-header-info .name {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0.5rem 0;
      }
      .view-details-header-info .stats {
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
      }
      .view-details-header .share-view-btn {
        flex-shrink: 0;
        align-self: flex-start;
      }
      @media (min-width: 768px) {
        .sidebar {
          padding: 1.5rem 0;
          overflow-y: auto;
        }
        .sidebar .offcanvas-header {
          display: none;
        }
        .sidebar .offcanvas-body {
          padding: 0 !important;
        }
        .player-bar {
          background-color: var(--ytm-surface);
          left: 240px;
        }
        .page-header {
          position: -webkit-sticky;
          position: sticky;
          top: 0;
          background-color: var(--ytm-bg);
          z-index: 1010;
          padding-top: 1.5rem;
          padding-bottom: 1.5rem;
        }
        .song-item.history-item {
          grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) 80px 40px;
        }
      }
      .offcanvas-body .nav-link {
        padding: 0.75rem 1.5rem;
      }
      .sidebar .logo {
        font-size: 1.5rem;
        font-weight: 700;
        padding: 0 1.5rem 1.5rem 1.5rem;
      }
      .sidebar .logo span {
        color: var(--ytm-accent);
      }
      .nav-link {
        color: var(--ytm-secondary-text);
        display: flex;
        align-items: center;
        font-weight: 500;
        border-left: 3px solid transparent;
        gap: 1rem;
        text-decoration: none;
      }
      .nav-link:hover, .nav-link.active {
        background-color: var(--ytm-surface);
        color: var(--ytm-primary-text);
      }
      .nav-link.active {
        border-left-color: var(--ytm-accent);
      }
      .nav-link .bi {
        font-size: 1.25rem;
        width: 24px;
        text-align: center;
      }
      .offcanvas {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        z-index: 999;
      }
      .offcanvas .offcanvas-header {
        padding: 0.75rem 1.5rem;
      }
      .page-header {
        padding: 1.5rem 2rem 1.5rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
      }
      .header-controls {
        display: flex;
        gap: 1rem;
        align-items: center;
        margin-left: auto;
      }
      #sort-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }
      #sort-select {
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
        border: 1px solid #404040;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
      }
      .search-bar.input-group {
        width: auto;
        min-width: 250px;
      }
      .search-bar.input-group .form-control {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        border-right: none;
        color: var(--ytm-primary-text);
        border-radius: 50px 0 0 50px;
        height: 40px;
        box-shadow: none;
        padding-left: 1rem;
      }
      .search-bar.input-group .form-control:focus {
        border-color: #666;
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
      }
      .search-bar.input-group .form-control::placeholder {
        color: var(--ytm-secondary-text);
      }
      .search-bar.input-group .btn {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        color: var(--ytm-secondary-text);
        border-radius: 0 50px 50px 0;
        z-index: 5;
      }
      .search-bar.input-group .btn:hover {
        background-color: #383838;
        color: var(--ytm-primary-text);
      }
      .content-title {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0; /* Adjusted for sticky header */
      }
      .song-list-header, .song-item {
        display: grid;
        grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) 80px 40px;
        align-items: center;
        gap: 1rem;
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      .song-list-header {
        font-weight: 500;
      }
      .song-item {
        cursor: pointer;
        border-radius: 0;
      }
      .song-item.ghost {
        opacity: 0.4;
      }
      .song-item:hover {
        background-color: var(--ytm-surface-2);
      }
      .song-item .song-title,
      .song-item .song-artist,
      .song-item .song-album,
      .song-artist-name,
      .view-details-header-info .name {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .song-item .song-title {
        color: var(--ytm-primary-text);
        font-weight: 500;
      }
      .song-item .song-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
        background-color: var(--ytm-surface);
      }
      .song-item .song-more {
        justify-self: end;
        position: relative;
      }
      .song-item .more-btn, .playlist-more-btn {
        background: none;
        border: none;
        color: var(--ytm-secondary-text);
        padding: 5px;
        cursor: pointer;
        border-radius: 50%;
      }
      .song-item:hover .more-btn, .playlist-more-btn:hover {
        color: var(--ytm-primary-text);
      }
      .card.playlist-card {
        position: relative;
      }
      .playlist-more-btn {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: transparent !important;
        border: none;
        padding: 0.25rem;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
      }
      .context-menu {
        display: none;
        position: fixed;
        background-color: var(--ytm-surface-2);
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        z-index: 1080;
        list-style: none;
        padding: 0.5rem 0;
        min-width: 220px;
        max-height: 50vh;
        overflow-y: auto;
      }
      .context-menu-item {
        padding: 0.75rem 1.25rem;
        color: var(--ytm-primary-text);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .context-menu-item:hover {
        background-color: #404040;
      }
      .context-menu-item .bi {
        font-size: 1.1rem;
      }
      .player-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 90px;
        background-color: var(--ytm-bg);
        border-top: 1px solid var(--ytm-surface-2);
        display: grid;
        grid-template-columns: minmax(200px, 1fr) 2fr minmax(200px, 1fr);
        align-items: center;
        gap: 1.5rem;
        padding: 0 1.5rem;
        z-index: 998;
      }
      .player-bar .track-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 0;
      }
      .player-bar .track-info-art {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 4px;
        flex-shrink: 0;
      }
      .player-bar .track-info-text {
        overflow: hidden;
      }
      .player-bar .track-info-text .title,
      .player-bar .track-info-text .artist {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .player-bar .track-info-text .title {
        font-weight: 500;
      }
      .player-bar .track-info-text .artist {
        color: var(--ytm-secondary-text);
        font-size: 0.875rem;
      }
      .player-bar .player-controls {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 0;
      }
      .player-bar .player-buttons {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1.5rem;
        width: 100%;
      }
      .player-btn {
        background: none;
        border: none;
        color: var(--ytm-secondary-text);
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.2s;
      }
      .player-btn:hover {
        color: var(--ytm-primary-text);
      }
      .player-btn.play-btn {
        color: var(--ytm-primary-text);
        background-color: var(--ytm-surface);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        transition: transform 0.1s, background-color 0.2s;
      }
      .player-btn.play-btn:hover {
        transform: scale(1.1);
        background-color: #383838;
      }
      .player-btn .bi {
        font-size: 1.25rem;
      }
      .player-btn.play-btn .bi {
        font-size: 1.75rem;
      }
      .player-btn.active {
        color: var(--ytm-accent);
      }
      .player-bar .playback-bar {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-top: 8px;
      }
      .playback-bar .time {
        font-size: 0.75rem;
        color: var(--ytm-secondary-text);
        flex-shrink: 0;
      }
      .progress-bar-container {
        flex-grow: 1;
        height: 4px;
        border-radius: 2px;
        cursor: pointer;
        padding: 5px 0;
        position: relative;
        margin-bottom: 0.2em;
      }
      .progress-bar-bg {
        height: 4px;
        background-color: #404040;
        border-radius: 2px;
        position: absolute;
        top: 5px;
        left: 0;
        right: 0;
        pointer-events: none;
      }
      .progress-bar-fg {
        height: 4px;
        background-color: var(--ytm-primary-text);
        border-radius: 2px;
        width: 0%;
        position: relative;
      }
      .progress-bar-container:hover .progress-bar-fg {
        background-color: var(--ytm-accent);
      }
      .progress-bar-container:hover .progress-bar-fg::after {
        content: '';
        position: absolute;
        right: -6px;
        top: -4px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--ytm-primary-text);
      }
      .player-bar .extra-controls {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 1rem;
      }
      .volume-control {
        width: 150px;
        display: flex;
        align-items: center;
      }
      .volume-slider-container {
        flex-grow: 1;
        padding: 5px 0.5rem;
        position: relative;
        display: flex;
        align-items: center;
      }
      #volume-slider.form-range {
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        cursor: pointer;
        outline: none;
        padding: 0;
        height: 4px;
        border-radius: 2px;
        background: var(--ytm-surface-2);
      }
      #volume-slider.form-range::-webkit-slider-runnable-track {
        -webkit-appearance: none;
        background: none;
        border: none;
        height: 4px;
      }
      #volume-slider.form-range::-moz-range-track {
        background: none;
        border: none;
        height: 4px;
      }
      #volume-slider.form-range::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        height: 12px;
        width: 12px;
        background-color: var(--ytm-primary-text);
        border-radius: 50%;
        margin-top: -4px;
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
      }
      #volume-slider.form-range::-moz-range-thumb {
        height: 12px;
        width: 12px;
        background-color: var(--ytm-primary-text);
        border-radius: 50%;
        border: none;
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
      }
      .volume-control:hover #volume-slider.form-range::-webkit-slider-thumb {
        opacity: 1;
      }
      .volume-control:hover #volume-slider.form-range::-moz-range-thumb {
        opacity: 1;
      }
      .volume-control:hover #volume-slider.form-range {
        --track-fill: var(--ytm-accent) !important;
      }
      .modal-content {
        background-color: var(--ytm-surface);
        border: none;
        border-radius: 1rem;
      }
      .modal-footer {
        border-top: 1px solid var(--ytm-surface-2);
      }
      .form-control, .form-select {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        color: var(--ytm-primary-text);
      }
      .form-control:focus, .form-select:focus {
        background-color: var(--ytm-surface-2);
        border-color: #666;
        color: var(--ytm-primary-text);
        box-shadow: none;
      }
      .form-control::placeholder {
        color: var(--ytm-secondary-text);
      }
      .dropdown-menu-dark {
        background-color: var(--ytm-surface-2);
      }
      #upload-progress-area .progress {
        height: 10px;
      }
      body.logged-out .logged-in-only { display: none !important; }
      body.logged-in .logged-out-only { display: none !important; }
      .verified-user-only { display: none !important; }
      body.user-verified .verified-user-only { display: flex !important; }
      .text-truncate-width {
        max-width: 600px;
      }
      .song-item .playing-icon {
        display: none;
        font-size: 1.5rem;
        color: var(--ytm-accent);
      }
      .song-item.now-playing .song-thumb {
        display: none;
      }
      .song-item.now-playing .playing-icon {
        display: inline-block;
        animation: soundwave-pulse 1.2s ease-in-out infinite;
      }
      .song-item.now-playing .song-title {
        color: var(--ytm-accent);
      }
      .profile-picture, .profile-picture-sm {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        background-color: var(--ytm-surface-2);
      }
      .profile-picture-lg {
        border-radius: 50%;
      }
      .history-item .played-at-text {
        font-size: 0.8rem;
        color: var(--ytm-secondary-text);
      }
      .recommendation-shelf {
        margin-bottom: 2rem;
      }
      .shelf-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
      }
      .shelf-title {
        font-size: 1.5rem;
        font-weight: 700;
      }
      .shelf-items {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(180px, 1fr);
        overflow-x: auto;
        gap: 1rem;
        padding-bottom: 1rem;
        scrollbar-color: var(--ytm-surface-2) var(--ytm-surface);
        scrollbar-width: thin;
      }
      .shelf-items::-webkit-scrollbar { height: 8px; }
      .shelf-item {
        background-color: transparent;
        border: none;
        cursor: pointer;
      }
      .shelf-item img {
        width: 100%;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 6px;
        margin-bottom: 0.5rem;
        background-color: var(--ytm-surface-2);
      }
      .shelf-item .item-title {
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .shelf-item .item-subtitle {
        font-size: 0.875rem;
        color: var(--ytm-secondary-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .user-profile-page, .user-stats-page {
        text-align: center;
        padding: 2rem;
      }
      .user-profile-page .profile-picture-lg {
        width: 200px;
        height: 200px;
        margin-bottom: 1.5rem;
      }
      .user-profile-page .display-name {
        font-size: 2.5rem;
        font-weight: 700;
      }
      .user-stats-page .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 2rem;
        margin-top: 2rem;
      }
      .user-stats-page .stat-item .stat-value {
        font-size: 2rem;
        font-weight: 700;
      }
      .user-stats-page .stat-item .stat-label {
        color: var(--ytm-secondary-text);
        text-transform: uppercase;
        font-size: 0.9rem;
      }
      @keyframes soundwave-pulse {
        0% { transform: scaleY(0.4); }
        25% { transform: scaleY(1); }
        50% { transform: scaleY(0.6); }
        75% { transform: scaleY(0.8); }
        100% { transform: scaleY(0.4); }
      }
      @media (max-width: 767.98px) {
        .text-truncate-width {
          max-width: 250px;
        }
        body.player-visible {
          padding-bottom: 130px;
        }
        .main-content {
          padding-top: var(--header-height-mobile);
        }
        .content-area-wrapper {
          padding: 1rem;
        }
        .mobile-header {
          position: fixed;
          top: 0; left: 0; right: 0;
          height: var(--header-height-mobile);
          background-color: var(--ytm-bg);
          border-bottom: 1px solid var(--ytm-surface-2);
          z-index: 1000;
          display: flex;
          align-items: center;
          padding: 0 1rem;
          gap: 0.5rem;
        }
        .header-btn {
          background: none; border: none; color: var(--ytm-primary-text);
          font-size: 1.5rem; padding: 0.5rem;
        }
        .page-header {
          padding: 1rem 1rem 0 1rem;
          flex-wrap: wrap;
        }
        .content-title {
          font-size: 1.75rem;
          margin-bottom: 0.5rem;
          width: 100%;
        }
        .header-controls {
          margin-left: 0;
          width: 100%;
          justify-content: flex-end;
        }
        .player-bar {
          grid-template-columns: 1fr;
          display: flex;
          flex-direction: column;
          height: 150px;
          padding: 0.5rem 1rem;
          gap: 0;
        }
        .player-bar .track-info.d-md-none {
          order: 1;
          width: 100%;
          cursor: pointer;
          justify-content: space-between;
        }
        .player-bar .track-info-text {
          flex-grow: 1;
        }
        #player-more-btn-mobile {
          flex-shrink: 0;
        }
        .player-bar .player-controls { display: contents; }
        .player-bar .playback-bar { order: 2; width: 100%; margin-top: 8px; }
        .player-bar .player-buttons-mobile {
          order: 3;
          width: 100%;
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-top: 4px;
          margin-bottom: 8px;
        }
        .player-bar .player-buttons { display: none; }
        .player-bar .extra-controls { display: none; }
        .player-bar .track-info-art { width: 48px; height: 48px; }
        .player-btn.play-btn { width: 52px; height: 52px; }
        .player-btn .bi { font-size: 1.5rem; }
        .player-btn.play-btn .bi { font-size: 2.25rem; }
        .song-list-header {
          display: none;
        }
        .song-item {
          grid-template-columns: 40px minmax(0, 1fr) 40px;
          grid-template-rows: auto auto;
          padding: 0.75rem 0.5rem;
        }
        .song-item .song-album, .song-item .song-artist, .song-item .song-duration {
          display: none;
        }
        .song-item .song-thumb, .song-item .song-indicator-wrapper {
          grid-row: 1 / span 2;
        }
        .song-item .song-title-wrapper {
          grid-column: 2; grid-row: 1;
        }
        .song-item .song-artist-mobile {
          display: flex !important;
          justify-content: space-between;
          align-items: center;
          grid-column: 2;
          grid-row: 2;
          font-size: 0.8rem;
          color: var(--ytm-secondary-text);
          gap: 1rem;
        }
        .song-duration-mobile {
          display: block !important;
          flex-shrink: 0;
        }
        .song-item .song-more {
          grid-column: 3;
          grid-row: 1 / span 2;
        }
        .view-details-header {
          flex-direction: column;
          align-items: center;
          text-align: center;
        }
      }
      .loader {
        text-align: center;
        padding: 3rem;
        font-size: 1.2rem;
        color: var(--ytm-secondary-text);
      }
      .player-modal-content {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
      }
      .player-modal-header {
        border-bottom: 0;
        justify-content: space-between;
        align-items: center;
      }
      .player-modal-header .player-btn {
        padding: 0.5rem;
        color: var(--ytm-primary-text);
      }
      .player-modal-header .player-btn .bi {
        font-size: 1.75rem;
      }
      .player-modal-body {
        display: flex;
        flex-direction: column;
        justify-content: space-evenly;
        padding: 1rem 2rem;
      }
      .player-modal-art-wrapper {
        flex-grow: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 2rem;
      }
      #player-modal-art {
        width: 100%;
        max-width: 400px;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
      }
      .player-modal-track-info {
        text-align: left;
        margin-bottom: 1rem;
      }
      .player-modal-track-info .title {
        font-weight: 700;
        font-size: 1.5rem;
      }
      .player-modal-track-info .artist {
        color: var(--ytm-secondary-text);
        font-size: 1rem;
      }
      .player-modal-progress {
        width: 100%;
        margin-bottom: 1rem;
      }
      .player-modal-progress .time-stamps {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        color: var(--ytm-secondary-text);
        margin-top: 0.5rem;
      }
      .player-modal-controls {
        display: flex;
        justify-content: space-around;
        align-items: center;
        margin-bottom: 1.5rem;
      }
      .player-modal-controls .player-btn {
        color: var(--ytm-primary-text);
      }
      .player-modal-controls .player-btn.active {
        color: var(--ytm-accent);
      }
      .player-modal-controls .player-btn .bi {
        font-size: 2rem;
      }
      .player-modal-controls .play-btn {
        width: 70px;
        height: 70px;
      }
      .player-modal-controls .play-btn .bi {
        font-size: 3.5rem;
      }
      .player-modal-extra-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .add-to-playlist-item {
        cursor: pointer;
      }
      .add-to-playlist-item:hover {
        background-color: var(--ytm-surface-2);
      }
    </style>
  </head>
  <body class="logged-out">
    <div class="app-container">
      <nav class="sidebar offcanvas-md offcanvas-start" tabindex="-1" id="main-nav-offcanvas">
        <div class="offcanvas-header">
          <div class="logo">PHP<span>Music</span></div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#main-nav-offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
          <div class="logo d-none d-md-block">PHP<span>Music</span></div>
          
          <a href="#" class="nav-link active" data-view="get_songs">
            <i class="bi bi-music-note-list"></i>
            <span>All Songs</span>
          </a>
          <a href="#" class="nav-link" data-view="get_albums">
            <i class="bi bi-disc-fill"></i>
            <span>Albums</span>
          </a>
          <a href="#" class="nav-link" data-view="get_artists">
            <i class="bi bi-people-fill"></i>
            <span>Artists</span>
          </a>
          <a href="#" class="nav-link" data-view="get_genres">
            <i class="bi bi-tags-fill"></i>
            <span>Genres</span>
          </a>

          <hr class="text-secondary">
          
          <div class="logged-in-only">
            <a href="#" class="nav-link" data-view="get_recommendations">
              <i class="bi bi-magic"></i>
              <span>For You</span>
            </a>
            <a href="#" class="nav-link" data-view="user_profile">
              <i class="bi bi-person-fill"></i>
              <span>My Profile</span>
            </a>
            <a href="#" class="nav-link" data-view="get_history">
              <i class="bi bi-clock-history"></i>
              <span>History</span>
            </a>
            <a href="#" class="nav-link" data-view="get_favorites">
              <i class="bi bi-heart-fill"></i>
              <span>Favorites</span>
            </a>
            <a href="#" class="nav-link" data-view="get_user_playlists">
              <i class="bi bi-music-note-beamed"></i>
              <span>Playlists</span>
            </a>
          </div>
          
          <div class="logged-out-only">
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#login-modal">
              <i class="bi bi-box-arrow-in-right"></i>
              <span>Login</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#register-modal">
              <i class="bi bi-person-plus-fill"></i>
              <span>Register</span>
            </a>
          </div>
          
          <div class="mt-auto">
            <hr class="text-secondary">
            <a href="#" class="nav-link logged-in-only verified-user-only" data-bs-toggle="modal" data-bs-target="#upload-modal">
              <i class="bi bi-cloud-upload-fill"></i>
              <span>Upload Song</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#full-scan-modal">
              <i class="bi bi-hdd-stack-fill"></i>
              <span>Scan All</span>
            </a>
            <a href="#" class="nav-link d-none" id="install-pwa-btn">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Install App</span>
            </a>
            <a href="#" class="nav-link" id="clear-cache-btn">
              <i class="bi bi-eraser-fill"></i>
              <span>Clear Cache</span>
            </a>
          </div>
        </div>
      </nav>
      <main class="main-content" id="main-content">
        <div class="mobile-header d-md-none">
          <button class="header-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#main-nav-offcanvas" aria-controls="main-nav-offcanvas">
            <i class="bi bi-list"></i>
          </button>
          <div class="input-group search-bar flex-grow-1">
            <input type="text" class="form-control" id="search-input-mobile" placeholder="Search your music" aria-label="Search your music">
            <button class="btn" type="button" id="search-btn-mobile"><i class="bi bi-search"></i></button>
          </div>
          <div class="dropdown logged-in-only">
            <img src="" class="profile-picture" id="profile-picture-header-mobile" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <li><a class="dropdown-item" href="#" id="profile-dropdown-profile-mobile"><i class="bi bi-person-fill me-2"></i>My Profile</a></li>
              <li><a class="dropdown-item" href="#" id="profile-dropdown-stats-mobile"><i class="bi bi-bar-chart-line-fill me-2"></i>Statistics</a></li>
              <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settings-modal"><i class="bi bi-gear-fill me-2"></i>Settings</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="#" id="profile-dropdown-logout-mobile"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
            </ul>
          </div>
        </div>
        <div class="page-header">
          <h1 id="content-title" class="content-title">Home</h1>
          <div class="header-controls">
            <div id="sort-controls" class="d-none">
              <label for="sort-select" class="text-secondary small">Sort by</label>
              <select id="sort-select" class="form-select form-select-sm" style="width: auto;"></select>
            </div>
            <div id="history-controls" class="d-none">
                <button class="btn btn-sm btn-outline-danger" id="clear-history-btn">
                    <i class="bi bi-trash"></i> Clear History
                </button>
            </div>
            <div class="input-group search-bar d-none d-md-flex">
              <input type="text" class="form-control" id="search-input-desktop" placeholder="Search songs, albums, artists" aria-label="Search songs, albums, artists">
              <button class="btn" type="button" id="search-btn-desktop"><i class="bi bi-search"></i></button>
            </div>
            <div class="dropdown logged-in-only d-none d-md-block">
              <img src="" class="profile-picture" id="profile-picture-header-desktop" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
              <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                <li><a class="dropdown-item" href="#" id="profile-dropdown-profile-desktop"><i class="bi bi-person-fill me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="#" id="profile-dropdown-stats-desktop"><i class="bi bi-bar-chart-line-fill me-2"></i>Statistics</a></li>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settings-modal"><i class="bi bi-gear-fill me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" id="profile-dropdown-logout-desktop"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
              </ul>
            </div>
          </div>
        </div>
        <div id="content-area" class="content-area-wrapper"></div>
        <div id="infinite-scroll-loader" class="loader d-none">Loading more...</div>
      </main>
    </div>
    <div class="player-bar d-none" id="player-bar">
      <div class="track-info d-none d-md-flex">
        <img src="" alt="Album Art" class="track-info-art" id="player-art-desktop">
        <div class="track-info-text">
          <div class="title" id="player-title-desktop">Song Title</div>
          <div class="artist" id="player-artist-desktop">Artist Name</div>
        </div>
      </div>
      <div class="player-controls">
        <div class="track-info d-md-none">
          <img src="" alt="Album Art" class="track-info-art" id="player-art-mobile">
          <div class="track-info-text">
            <div class="title" id="player-title-mobile">Song Title</div>
            <div class="artist" id="player-artist-mobile">Artist Name</div>
          </div>
          <button class="player-btn" id="player-more-btn-mobile" title="More"><i class="bi bi-three-dots-vertical"></i></button>
        </div>
        <div class="playback-bar">
          <span class="time" id="current-time">0:00</span>
          <div class="progress-bar-container" id="progress-container">
            <div class="progress-bar-bg"></div>
            <div class="progress-bar-fg" id="progress-bar"></div>
          </div>
          <span class="time" id="time-left">0:00</span>
        </div>
        <div class="player-buttons d-none d-md-flex mt-md-2">
          <button class="player-btn" id="shuffle-btn-desktop" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-desktop" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-desktop" title="Play"></button>
          <button class="player-btn" id="next-btn-desktop" title="Next"></button>
          <button class="player-btn" id="repeat-btn-desktop" title="Repeat"></button>
        </div>
         <div class="player-buttons-mobile d-md-none">
          <button class="player-btn" id="shuffle-btn-mobile" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-mobile" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-mobile" title="Play"></button>
          <button class="player-btn" id="next-btn-mobile" title="Next"></button>
          <button class="player-btn" id="repeat-btn-mobile" title="Repeat"></button>
        </div>
      </div>
      <div class="extra-controls d-none d-md-flex">
        <div class="volume-control d-flex align-items-center">
          <button class="player-btn" id="volume-btn" title="Mute">
            <i class="bi bi-volume-up-fill"></i>
          </button>
          <div class="volume-slider-container">
            <input type="range" class="form-range" id="volume-slider" min="0" max="1" step="0.01" value="1">
          </div>
        </div>
        <button class="player-btn" id="player-more-btn-desktop" title="More"><i class="bi bi-three-dots-vertical"></i></button>
      </div>
    </div>
    <ul class="context-menu" id="context-menu"></ul>

    <div class="modal fade" id="player-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content player-modal-content">
          <div class="modal-header player-modal-header">
            <button type="button" class="btn player-btn" data-bs-dismiss="modal" aria-label="Close">
              <i class="bi bi-chevron-down"></i>
            </button>
            <button type="button" class="btn player-btn" id="player-modal-more-btn" title="More">
              <i class="bi bi-three-dots-vertical"></i>
            </button>
          </div>
          <div class="modal-body player-modal-body">
            <div class="player-modal-art-wrapper">
              <img src="" id="player-modal-art" alt="Album Art">
            </div>
            <div class="player-modal-track-info">
              <h3 id="player-modal-title" class="title text-truncate">Song Title</h3>
              <p id="player-modal-artist" class="artist">Artist Name</p>
            </div>
            <div class="player-modal-progress">
              <div class="progress-bar-container" id="player-modal-progress-container">
                <div class="progress-bar-bg"></div>
                <div class="progress-bar-fg" id="player-modal-progress-bar"></div>
              </div>
              <div class="time-stamps">
                <span id="player-modal-current-time">0:00</span>
                <span id="player-modal-time-left">0:00</span>
              </div>
            </div>
            <div class="player-modal-controls">
                <button class="player-btn" id="player-modal-shuffle-btn" title="Shuffle"></button>
                <button class="player-btn" id="player-modal-prev-btn" title="Previous"></button>
                <button class="player-btn play-btn" id="player-modal-play-pause-btn" title="Play"></button>
                <button class="player-btn" id="player-modal-next-btn" title="Next"></button>
                <button class="player-btn" id="player-modal-repeat-btn" title="Repeat"></button>
            </div>
             <div class="player-modal-extra-controls">
             </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="login-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Login</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="login-form">
              <div class="mb-3">
                <label for="login-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="login-email" required>
              </div>
              <div class="mb-3">
                <label for="login-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="login-password" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Login</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="register-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Register</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="register-form">
              <div class="mb-3">
                <label for="register-artist" class="form-label">Artist/Display Name</label>
                <input type="text" class="form-control" id="register-artist" required>
              </div>
              <div class="mb-3">
                <label for="register-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="register-email" required>
              </div>
              <div class="mb-3">
                <label for="register-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="register-password" required minlength="6">
              </div>
              <button type="submit" class="btn btn-danger w-100">Register</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="settings-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Settings</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <h6>Profile Picture</h6>
            <form id="profile-picture-form" class="mb-4 text-center">
                <img src="" id="profile-picture-preview" class="profile-picture-lg mb-3" alt="Profile Picture Preview">
                <div class="mb-3">
                    <label for="profile-picture-input" class="form-label">Upload new picture</label>
                    <input class="form-control" type="file" id="profile-picture-input" accept="image/png, image/jpeg, image/gif">
                </div>
                <button type="submit" class="btn btn-danger w-100">Save Picture</button>
            </form>
            <hr class="text-secondary">
            <h6 class="mt-4">Change Password</h6>
            <form id="change-password-form">
              <div class="mb-3">
                <label for="new-password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new-password" required minlength="6">
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="upload-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Upload Music</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="song-files" class="form-label">Select songs to upload</label>
              <input class="form-control" type="file" id="song-files" multiple accept="audio/*">
              <div class="d-flex justify-content-between">
                <small class="form-text text-secondary" id="upload-limit-text"></small>
                <small class="form-text text-secondary" id="upload-remaining-text"></small>
              </div>
            </div>
            <div class="mb-3">
              <label for="song-genre" class="form-label">Custom Genre (only used if genre tag is missing from the file)</label>
              <input type="text" class="form-control" id="song-genre" placeholder="Pop, Rock, J-Pop">
            </div>
            <button id="start-upload-btn" class="btn btn-danger">Start Upload</button>
            <div id="upload-progress-area" class="mt-3"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="genres-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">All Genres</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="genres-modal-body">
            <div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="create-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Create New Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="create-playlist-form">
              <div class="mb-3">
                <label for="playlist-name" class="form-label">Playlist Name</label>
                <input type="text" class="form-control" id="playlist-name-input" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Create</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="edit-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Edit Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-playlist-form">
              <input type="hidden" id="edit-playlist-id-input">
              <div class="mb-3">
                <label for="edit-playlist-name-input" class="form-label">Playlist Name</label>
                <input type="text" class="form-control" id="edit-playlist-name-input" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Changes</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="add-to-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Add to Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="add-to-playlist-modal-body">
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="metadata-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Song Metadata</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="metadata-modal-body">
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="share-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title" id="share-modal-title">Share</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary text-center mb-4" id="share-modal-text">Share this with your friends!</p>
            <div class="d-flex justify-content-center gap-3 mb-4 fs-2">
              <a href="#" id="share-facebook" target="_blank" class="text-white"><i class="bi bi-facebook"></i></a>
              <a href="#" id="share-twitter" target="_blank" class="text-white"><i class="bi bi-twitter-x"></i></a>
              <a href="#" id="share-whatsapp" target="_blank" class="text-white"><i class="bi bi-whatsapp"></i></a>
              <a href="#" id="share-telegram" target="_blank" class="text-white"><i class="bi bi-telegram"></i></a>
            </div>
            <p class="small text-secondary">Or copy the link</p>
            <div class="input-group">
              <input type="text" class="form-control" id="share-url-input" readonly>
              <button class="btn btn-danger" type="button" id="copy-share-url-btn">Copy</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="full-scan-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Full Library Scan Log</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <iframe id="full-scan-iframe" src="about:blank" style="width: 100%; height: 60vh; border: none; background-color: #030303;"></iframe>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        'use strict';
        const mainContent = document.getElementById('main-content');
        const contentArea = document.getElementById('content-area');
        const contentTitle = document.getElementById('content-title');
        const searchInputDesktop = document.getElementById('search-input-desktop');
        const searchInputMobile = document.getElementById('search-input-mobile');
        const searchBtnDesktop = document.getElementById('search-btn-desktop');
        const searchBtnMobile = document.getElementById('search-btn-mobile');
        const sortControls = document.getElementById('sort-controls');
        const sortSelect = document.getElementById('sort-select');
        const historyControls = document.getElementById('history-controls');
        const clearHistoryBtn = document.getElementById('clear-history-btn');
        const allNavLinks = document.querySelectorAll('.sidebar .nav-link');
        const contextMenu = document.getElementById('context-menu');
        const playerBar = document.getElementById('player-bar');
        const infiniteScrollLoader = document.getElementById('infinite-scroll-loader');
        const installPwaBtn = document.getElementById('install-pwa-btn');
        const clearCacheBtn = document.getElementById('clear-cache-btn');
        const fullScanModalEl = document.getElementById('full-scan-modal');
        const fullScanIframe = document.getElementById('full-scan-iframe');

        const genresModalEl = document.getElementById('genres-modal');
        const genresModal = genresModalEl ? new bootstrap.Modal(genresModalEl) : null;
        const genresModalBody = document.getElementById('genres-modal-body');
        const createPlaylistModalEl = document.getElementById('create-playlist-modal');
        const createPlaylistModal = createPlaylistModalEl ? new bootstrap.Modal(createPlaylistModalEl) : null;
        const editPlaylistModalEl = document.getElementById('edit-playlist-modal');
        const editPlaylistModal = editPlaylistModalEl ? new bootstrap.Modal(editPlaylistModalEl) : null;
        const addToPlaylistModalEl = document.getElementById('add-to-playlist-modal');
        const addToPlaylistModal = addToPlaylistModalEl ? new bootstrap.Modal(addToPlaylistModalEl) : null;
        const addToPlaylistModalBody = document.getElementById('add-to-playlist-modal-body');
        const metadataModalEl = document.getElementById('metadata-modal');
        const metadataModal = metadataModalEl ? new bootstrap.Modal(metadataModalEl) : null;
        const metadataModalBody = document.getElementById('metadata-modal-body');
        const shareModalEl = document.getElementById('share-modal');
        const shareModal = shareModalEl ? new bootstrap.Modal(shareModalEl) : null;
        const shareModalTitle = document.getElementById('share-modal-title');
        const shareModalText = document.getElementById('share-modal-text');
        const shareUrlInput = document.getElementById('share-url-input');
        const copyShareUrlBtn = document.getElementById('copy-share-url-btn');

        const playerTrackInfoMobile = document.querySelector('.player-bar .track-info.d-md-none');
        const playerModalEl = document.getElementById('player-modal');
        const playerModal = playerModalEl ? new bootstrap.Modal(playerModalEl) : null;
        
        const profilePictureHeaderDesktop = document.getElementById('profile-picture-header-desktop');
        const profilePictureHeaderMobile = document.getElementById('profile-picture-header-mobile');
        const profilePicturePreview = document.getElementById('profile-picture-preview');

        const playerElements = {
          art: [document.getElementById('player-art-desktop'), document.getElementById('player-art-mobile'), document.getElementById('player-modal-art')],
          title: [document.getElementById('player-title-desktop'), document.getElementById('player-title-mobile'), document.getElementById('player-modal-title')],
          artist: [document.getElementById('player-artist-desktop'), document.getElementById('player-artist-mobile'), document.getElementById('player-modal-artist')],
          currentTime: [document.getElementById('current-time'), document.getElementById('player-modal-current-time')],
          timeLeft: [document.getElementById('time-left'), document.getElementById('player-modal-time-left')],
          progress: [document.getElementById('progress-bar'), document.getElementById('player-modal-progress-bar')],
          progressContainer: [document.getElementById('progress-container'), document.getElementById('player-modal-progress-container')],
          playPauseBtn: [document.getElementById('play-pause-btn-desktop'), document.getElementById('play-pause-btn-mobile'), document.getElementById('player-modal-play-pause-btn')],
          prevBtn: [document.getElementById('prev-btn-desktop'), document.getElementById('prev-btn-mobile'), document.getElementById('player-modal-prev-btn')],
          nextBtn: [document.getElementById('next-btn-desktop'), document.getElementById('next-btn-mobile'), document.getElementById('player-modal-next-btn')],
          shuffleBtn: [document.getElementById('shuffle-btn-desktop'), document.getElementById('shuffle-btn-mobile'), document.getElementById('player-modal-shuffle-btn')],
          repeatBtn: [document.getElementById('repeat-btn-desktop'), document.getElementById('repeat-btn-mobile'), document.getElementById('player-modal-repeat-btn')],
          moreBtn: [document.getElementById('player-more-btn-desktop'), document.getElementById('player-more-btn-mobile'), document.getElementById('player-modal-more-btn')],
          volumeBtn: document.getElementById('volume-btn'),
          volumeSlider: document.getElementById('volume-slider'),
        };
        
        const audio = new Audio();
        let currentView = { type: 'get_songs', param: '', sort: 'artist_asc' };
        let currentUser = null;
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = false;
        let repeatMode = 'none';
        let deferredInstallPrompt = null;
        let sortable = null;
        let songIdForPlaylist = null;
        let contextMenuItemEl = null;
        let previousVolume = 1;
        
        const PAGE_SIZE = 25;
        let currentPage = 1;
        let isLoadingMore = false;
        let allContentloaded = false;
        const PLAY_LOG_THRESHOLD = 30;

        const ICONS = {
          play: `<i class="bi bi-play-fill"></i>`,
          pause: `<i class="bi bi-pause-fill"></i>`,
          repeat: `<i class="bi bi-repeat"></i>`,
          repeatOne: `<i class="bi bi-repeat-1"></i>`,
          shuffle: `<i class="bi bi-shuffle"></i>`,
          prev: `<i class="bi bi-skip-start-fill"></i>`,
          next: `<i class="bi bi-skip-end-fill"></i>`,
          heart: `<i class="bi bi-heart"></i>`,
          heartFill: `<i class="bi bi-heart-fill"></i>`,
          volumeUp: `<i class="bi bi-volume-up-fill"></i>`,
          volumeDown: `<i class="bi bi-volume-down-fill"></i>`,
          volumeMute: `<i class="bi bi-volume-mute-fill"></i>`,
        };

        const formatTime = (seconds) => {
          if (isNaN(seconds) || seconds < 0) return '0:00';
          const min = Math.floor(seconds / 60);
          const sec = Math.floor(seconds % 60).toString().padStart(2, '0');
          return `${min}:${sec}`;
        };
        
        const timeAgo = (date) => {
          const seconds = Math.floor((new Date() - new Date(date)) / 1000);
          let interval = seconds / 31536000;
          if (interval > 1) return Math.floor(interval) + " years ago";
          interval = seconds / 2592000;
          if (interval > 1) return Math.floor(interval) + " months ago";
          interval = seconds / 86400;
          if (interval > 1) return Math.floor(interval) + " days ago";
          interval = seconds / 3600;
          if (interval > 1) return Math.floor(interval) + " hours ago";
          interval = seconds / 60;
          if (interval > 1) return Math.floor(interval) + " minutes ago";
          return "Just now";
        }

        const fetchData = async (url, options = {}) => {
          try {
            options.cache = 'no-store';
            const response = await fetch(url, options);
            if (!response.ok) {
              const errorData = await response.json().catch(() => null);
              const message = errorData ? errorData.message : `HTTP error! status: ${response.status}`;
              throw new Error(message);
            }
            if (response.headers.get("content-type")?.includes("application/json")) {
              return await response.json();
            }
            return await response.text();
          } catch (error) {
            console.error("Fetch error for " + url, error);
            showToast(error.message, 'error');
            return null;
          }
        };

        const showToast = (message, type = 'info') => {
          const toastContainer = document.createElement('div');
          toastContainer.className = `toast-container position-fixed bottom-0 end-0 p-3`;
          toastContainer.style.zIndex = "1100";
          const toastEl = document.createElement('div');
          toastEl.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : 'success'} border-0`;
          toastEl.setAttribute('role', 'alert');
          toastEl.setAttribute('aria-live', 'assertive');
          toastEl.setAttribute('aria-atomic', 'true');
          toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
          
          document.body.appendChild(toastContainer);
          toastContainer.appendChild(toastEl);
          
          const toast = new bootstrap.Toast(toastEl);
          toast.show();
          toastEl.addEventListener('hidden.bs.toast', () => toastContainer.remove());
        };
        
        const showLoader = (isInitial = true) => {
          if (isInitial) {
            contentArea.innerHTML = `<div class="loader">Loading...</div>`;
            contentTitle.classList.remove('d-none');
          } else {
            infiniteScrollLoader.classList.remove('d-none');
          }
        };

        const hideLoader = () => {
          infiniteScrollLoader.classList.add('d-none');
        };
        
        const updateContentTitle = (text, show = true) => {
          if (!show) {
            contentTitle.classList.add('d-none');
            return;
          }
          contentTitle.classList.remove('d-none');
          try {
            const decodedText = decodeURIComponent(text.replace(/\+/g, ' '));
            contentTitle.textContent = decodedText;
            document.title = decodedText + ' - PHP Music';
          } catch (e) {
            contentTitle.textContent = text;
            document.title = text + ' - PHP Music';
          }
        };
        
        const renderViewDetailsHeader = (details, type) => {
          let typeText = type.charAt(0).toUpperCase() + type.slice(1);
          let statsText = `${details.song_count || 0} songs &bull; ${formatTime(details.total_duration || 0)}`;
          let shareButtonHTML = `
            <button class="btn btn-outline-light border-0 share-view-btn" title="Share ${type}" data-share-id="${details.public_id || encodeURIComponent(details.name)}" data-share-name="${encodeURIComponent(details.name)}">
              <i class="bi bi-share-fill"></i> <span class="d-none d-md-inline">Share</span>
            </button>`;

          if (type === 'playlist') {
            typeText = `Playlist by ${details.creator}`;
          } else if (type === 'profile') {
            typeText = 'My Profile';
            shareButtonHTML = '';
          }

          const headerHTML = `
            <div class="view-details-header">
              <img src="${details.image_url}" alt="${details.name}" class="${type === 'profile' ? 'profile-picture-lg' : ''}">
              <div class="view-details-header-info">
                <div class="type">${typeText}</div>
                <h2 class="name text-truncate text-truncate-width">${details.name}</h2>
                <div class="stats">${statsText}</div>
              </div>
              ${shareButtonHTML}
            </div>`;
          contentArea.insertAdjacentHTML('afterbegin', headerHTML);
        };

        const renderSongs = (songs, append = false) => {
          if (sortable) {
            sortable.destroy();
            sortable = null;
          }

          if (!append) {
            if (!contentArea.querySelector('.view-details-header')) {
              contentArea.innerHTML = '';
            }
          }

          if (!songs || songs.length === 0) {
            if (!append) {
              contentArea.innerHTML += `<div class="text-center p-5 text-secondary">No songs found.</div>`;
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let songList = contentArea.querySelector('.song-list');
          if (!songList) {
            songList = document.createElement('div');
            songList.className = 'song-list';
            const isHistory = currentView.type === 'get_history';
            const header = `<div class="song-list-header d-none d-md-grid" style="grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) ${isHistory ? 'minmax(0, 2fr)' : ''} 80px 40px;">
              <div>#</div><div>Title</div><div>Artist</div><div>Album</div>${isHistory ? '<div>Played</div>' : ''}<div>Time</div><div></div>
            </div>`;
            contentArea.insertAdjacentHTML('beforeend', header);
            contentArea.appendChild(songList);
          }
          
          const escapeAttr = (str) => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';

          const songsHTML = songs.map((song) => {
            const isNowPlaying = currentSong && currentSong.id === song.id;
            const isHistory = currentView.type === 'get_history';
            const playedAtHTML = isHistory ? `<div class="played-at-text">${timeAgo(song.played_at)}</div>` : '';
            return `
            <div class="song-item ${isNowPlaying ? 'now-playing' : ''} ${isHistory ? 'history-item' : ''}" 
              data-song-id="${song.id}" 
              data-is-favorite="${song.is_favorite == 1 ? '1' : '0'}"
              data-song-title="${escapeAttr(song.title)}"
              data-song-artist="${escapeAttr(song.artist)}"
              data-song-album="${escapeAttr(song.album)}"
              data-song-user-id="${song.user_id}">
              <div class="song-indicator-wrapper d-flex align-items-center justify-content-center">
                <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                <i class="bi bi-soundwave playing-icon"></i>
              </div>
              <div class="song-title-wrapper"><div class="song-title">${song.title}</div></div>
              <div class="song-artist" data-artist="${encodeURIComponent(song.artist)}">${song.artist}</div>
              <div class="song-album" data-album="${encodeURIComponent(song.album)}">${song.album}</div>
              ${isHistory ? `<div class="d-none d-md-block">${playedAtHTML}</div>` : ''}
              <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
              <div class="song-more">
                <button class="more-btn" data-song-id="${song.id}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
              </div>
              <div class="song-artist-mobile d-md-none">
                <span class="song-artist-name">${song.artist}</span>
                ${isHistory ? playedAtHTML : ''}
                <span class="song-duration-mobile">${formatTime(song.duration)}</span>
              </div>
            </div>
          `}).join('');
          
          songList.insertAdjacentHTML('beforeend', songsHTML);

          const isSortableFavorites = currentView.type === 'get_favorites' && currentView.sort === 'manual_order';
          const isSortablePlaylist = currentView.type === 'playlist_songs' && currentView.sort === 'manual_order';

          if (isSortableFavorites || isSortablePlaylist) {
            sortable = Sortable.create(songList, {
              animation: 150,
              ghostClass: 'ghost',
              delay: 200,
              delayOnTouchOnly: true,
              onEnd: async (evt) => {
                const songItems = Array.from(songList.querySelectorAll('.song-item'));
                const newOrderIds = songItems.map(item => item.dataset.songId);
                
                const action = isSortableFavorites ? 'update_favorite_order' : 'update_playlist_order';
                const body = { 
                  ids: newOrderIds,
                  ...(isSortablePlaylist && { playlist_public_id: decodeURIComponent(currentView.param) })
                };
                
                await fetchData(`?action=${action}`, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(body)
                });
              }
            });
          }
        };

        const renderGrid = (items, type, append = false) => {
          if (!append) contentArea.innerHTML = '';
          if (type === 'get_user_playlists' && !append) {
            contentArea.innerHTML = `<div class="p-3"><button class="btn btn-danger" id="create-new-playlist-btn"><i class="bi bi-plus-lg"></i> Create New Playlist</button></div>`;
          }
          if (!items || items.length === 0) {
            if (!append && type !== 'get_user_playlists') {
              contentArea.innerHTML += `<div class="text-center p-5 text-secondary">No ${type.replace('get_','')} found.</div>`;
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let grid = contentArea.querySelector('.row');
          if (!grid) {
            grid = document.createElement('div');
            grid.className = 'row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-4';
            contentArea.appendChild(grid);
          }
          
          const itemsHTML = items.map(item => {
              let name, subtext, imageId, dataType, dataValue, icon, publicId;
              if (type === 'get_albums') {
                name = item.album;
                subtext = item.artist;
                imageId = item.id;
                dataType = 'album';
                dataValue = name;
                publicId = '';
              } else if (type === 'get_user_playlists') {
                name = item.name;
                subtext = `${item.song_count} songs`;
                imageId = item.image_id;
                dataType = 'playlist';
                dataValue = item.public_id;
                publicId = item.public_id;
              } else {
                name = item;
                subtext = null;
                dataType = type.replace('get_','').slice(0, -1);
                dataValue = name;
                icon = (type === 'get_artists') ? 'bi-person-fill' : 'bi-tag-fill';
                publicId = '';
              }
              
              const moreButton = (type === 'get_user_playlists') ? `
                <button class="playlist-more-btn" data-public-id="${publicId}" data-name="${name}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>` : '';

              if (type === 'get_albums' || type === 'get_user_playlists') {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-${dataType}="${encodeURIComponent(dataValue)}" style="cursor: pointer;">
                    ${moreButton}
                    <img src="?action=get_image&id=${imageId || 0}" class="card-img-top rounded" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                      ${subtext ? `<p class="card-text small text-secondary text-truncate">${subtext}</p>` : ''}
                    </div>
                  </div>
                </div>`;
              } else {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0" data-${dataType}="${encodeURIComponent(dataValue)}" style="cursor: pointer;">
                    <div class="d-flex align-items-center justify-content-center rounded" style="aspect-ratio: 1/1; background-color: var(--ytm-surface-2);">
                      <i class="bi ${icon}" style="font-size: 4rem; color: var(--ytm-secondary-text);"></i>
                    </div>
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                    </div>
                  </div>
                </div>`;
              }
            }).join('');
          
          grid.insertAdjacentHTML('beforeend', itemsHTML);
        };
        
        const renderRecommendations = (data) => {
            contentArea.innerHTML = '';
            if (!data.shelves || data.shelves.length === 0) {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Not enough listening history to generate recommendations. Keep listening!</div>`;
                return;
            }

            data.shelves.forEach(shelf => {
                let itemsHTML = '';
                if (shelf.type === 'songs') {
                    itemsHTML = shelf.items.map(song => `
                        <div class="shelf-item" data-song-id="${song.id}">
                            <img src="?action=get_image&id=${song.id}" alt="${song.title}">
                            <div class="item-title">${song.title}</div>
                            <div class="item-subtitle">${song.artist}</div>
                        </div>
                    `).join('');
                } else if (shelf.type === 'albums') {
                    itemsHTML = shelf.items.map(album => `
                        <div class="shelf-item" data-album="${encodeURIComponent(album.album)}">
                            <img src="?action=get_image&id=${album.id || 0}" alt="${album.album}">
                            <div class="item-title">${album.album}</div>
                            <div class="item-subtitle">${album.artist}</div>
                        </div>
                    `).join('');
                } else if (shelf.type === 'playlists') {
                    itemsHTML = shelf.items.map(playlist => `
                        <div class="shelf-item" data-playlist="${encodeURIComponent(playlist.public_id)}">
                            <img src="?action=get_image&id=${playlist.image_id || 0}" alt="${playlist.name}">
                            <div class="item-title">${playlist.name}</div>
                            <div class="item-subtitle">by ${playlist.creator}</div>
                        </div>
                    `).join('');
                }


                const shelfHTML = `
                    <div class="recommendation-shelf">
                        <div class="shelf-header">
                            <h3 class="shelf-title">${shelf.title}</h3>
                            ${shelf.connected_view ? `<button class="btn btn-sm btn-outline-light border-0" data-view="${shelf.connected_view}" ${shelf.param ? `data-view-param="${shelf.param}"` : ''}>See All</button>` : ''}
                        </div>
                        <div class="shelf-items">
                            ${itemsHTML}
                        </div>
                    </div>
                `;
                contentArea.insertAdjacentHTML('beforeend', shelfHTML);
            });
        };
        
        const renderUserStats = (data) => {
            contentArea.innerHTML = `
                <div class="user-stats-page">
                    <h2 class="content-title">My Statistics</h2>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-value">${data.stats.uploads}</div>
                            <div class="stat-label">Uploads</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${data.stats.favorites}</div>
                            <div class="stat-label">Favorites</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${data.stats.playlists}</div>
                            <div class="stat-label">Playlists</div>
                         </div>
                         <div class="stat-item">
                            <div class="stat-value">${data.stats.play_count}</div>
                            <div class="stat-label">Total Plays</div>
                        </div>
                    </div>
                </div>
            `;
        };
        
        const setupSortOptions = (viewType) => {
          const isSortable = ['get_songs', 'get_favorites', 'artist_songs', 'album_songs', 'genre_songs', 'user_profile', 'search', 'playlist_songs', 'get_history', 'get_albums', 'get_artists', 'get_user_playlists'].includes(viewType);
          
          if (isSortable) {
            let options = {};
            
            switch(viewType) {
              case 'get_songs':
              case 'artist_songs':
              case 'album_songs':
              case 'genre_songs':
              case 'search':
                options = {
                  'id_desc': 'Recently Added', 'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
                  'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)',
                };
                if (viewType === 'artist_songs') delete options.artist_asc;
                if (viewType === 'album_songs') {
                  delete options.artist_asc;
                  delete options.album_asc;
                }
                break;
              case 'user_profile':
                  options = { 'id_desc': 'Recently Added', 'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
                  'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)', };
                  break;
              case 'get_favorites':
              case 'playlist_songs':
                options = { 
                  'manual_order': 'My Order',
                  'artist_asc': 'Artist', 
                  'title_asc': 'Title', 
                  'album_asc': 'Album',
                };
                break;
              case 'get_history':
                options = { 'history_desc': 'Recently Played', 'title_asc': 'Title', 'artist_asc': 'Artist' };
                break;
              case 'get_albums':
                options = {
                  'album_asc': 'Title (A-Z)', 
                  'album_desc': 'Title (Z-A)', 
                  'artist_asc': 'Artist (A-Z)',
                  'artist_desc': 'Artist (Z-A)',
                  'year_desc': 'Year (Newest)', 
                  'year_asc': 'Year (Oldest)',
                };
                break;
              case 'get_artists':
                options = {
                  'name_asc': 'Name (A-Z)', 
                  'name_desc': 'Name (Z-A)',
                };
                break;
              case 'get_user_playlists':
                options = {
                  'name_asc': 'Name (A-Z)', 
                  'name_desc': 'Name (Z-A)',
                  'modified_desc': 'Date Modified (Newest)',
                  'modified_asc': 'Date Modified (Oldest)',
                };
                break;
            }

            sortSelect.innerHTML = Object.entries(options)
              .map(([value, text]) => `<option value="${value}" ${currentView.sort === value ? 'selected' : ''}>${text}</option>`).join('');
            sortControls.classList.remove('d-none');
          } else {
            sortControls.classList.add('d-none');
          }

          if (viewType === 'get_history') {
            historyControls.classList.remove('d-none');
          } else {
            historyControls.classList.add('d-none');
          }
        };

        const loadMoreContent = async () => {
          if (isLoadingMore || allContentloaded) return;
          isLoadingMore = true;
          showLoader(false);
          
          currentPage++;
          let data;
          const { type, param, sort } = currentView;
          
          const params = new URLSearchParams({ page: currentPage, sort: sort });

          switch(type) {
            case 'get_songs':
            case 'get_favorites':
            case 'get_history':
              data = await fetchData(`?action=${type}&${params.toString()}`);
              renderSongs(data, true);
              break;
            case 'user_profile':
              const profileData = await fetchData(`?action=get_view_data&type=profile&sort=${sort}&page=${currentPage}`);
              if (profileData && profileData.songs) {
                renderSongs(profileData.songs, true);
                data = profileData.songs;
              }
              break;
            case 'search':
              params.delete('sort');
              params.append('q', param);
              data = await fetchData(`?action=search&${params.toString()}`);
              renderSongs(data, true);
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
              const filterType = type.split('_')[0];
              const filterValue = decodeURIComponent(param);
              params.append(filterType, filterValue);
              data = await fetchData(`?action=get_songs&${params.toString()}`);
              renderSongs(data, true);
              break;
            case 'playlist_songs':
              params.append('public_id', decodeURIComponent(param));
              data = await fetchData(`?action=get_playlist_songs&${params.toString()}`);
              renderSongs(data, true);
              break;
            default:
              allContentloaded = true;
          }
          
          if (!data || data.length < PAGE_SIZE) {
            allContentloaded = true;
          }

          isLoadingMore = false;
          hideLoader();
        };

        const loadView = async (viewConfig) => {
          mainContent.scrollTop = 0;
          currentPage = 1;
          allContentloaded = false;
          isLoadingMore = false;
          showLoader();

          currentView = viewConfig;
          setupSortOptions(currentView.type);
          
          let data;
          const params = new URLSearchParams({ sort: currentView.sort, page: 1 });
          
          switch (currentView.type) {
            case 'get_songs':
              updateContentTitle('All Songs');
              data = await fetchData(`?action=get_songs&${params.toString()}`);
              renderSongs(data, false);
              break;
            case 'user_profile':
              updateContentTitle('', false);
              const viewData = await fetchData(`?action=get_view_data&type=profile&sort=${currentView.sort}&page=1`);
              contentArea.innerHTML = '';
              if (viewData && viewData.details) {
                renderViewDetailsHeader(viewData.details, 'profile');
                renderSongs(viewData.songs, false);
                data = viewData.songs;
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your profile.</div>`;
              }
              break;
            case 'get_user_stats':
                updateContentTitle('', false);
                const userStatsData = await fetchData(`?action=get_user_stats`);
                if(userStatsData) {
                    renderUserStats(userStatsData);
                }
                allContentloaded = true;
                break;
            case 'get_favorites':
              updateContentTitle('Favorites');
              data = await fetchData(`?action=get_favorites&${params.toString()}`);
              renderSongs(data, false);
              break;
            case 'get_history':
                updateContentTitle('History');
                data = await fetchData(`?action=get_history&${params.toString()}`);
                renderSongs(data, false);
                break;
            case 'get_recommendations':
                updateContentTitle('For You');
                data = await fetchData(`?action=get_recommendations`);
                renderRecommendations(data);
                allContentloaded = true;
                break;
            case 'get_albums':
            case 'get_artists':
            case 'get_genres':
            case 'get_user_playlists':
              let title = currentView.type.replace('get_', '');
              title = title.charAt(0).toUpperCase() + title.slice(1);
              if (title === 'User_playlists') title = 'Playlists';
              updateContentTitle(title);
              params.delete('page');
              data = await fetchData(`?action=${currentView.type}&${params.toString()}`);
              renderGrid(data, currentView.type, false);
              allContentloaded = true;
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
            case 'playlist_songs':
              const type = currentView.type.split('_')[0];
              const name_param = currentView.param;
              updateContentTitle('', false);

              const typeViewData = await fetchData(`?action=get_view_data&type=${type}&name=${name_param}&sort=${currentView.sort}&page=1`);
              
              contentArea.innerHTML = '';
              if (typeViewData && typeViewData.details) {
                renderViewDetailsHeader(typeViewData.details, type);
                renderSongs(typeViewData.songs, false);
                data = typeViewData.songs;
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Error loading view.</div>`;
              }
              break;
            case 'search':
              updateContentTitle(`Search: "${currentView.param}"`);
              params.delete('sort');
              params.append('q', currentView.param);
              data = await fetchData(`?action=search&${params.toString()}`);
              renderSongs(data, false);
              break;
          }

          if (data && data.length < PAGE_SIZE) {
            allContentloaded = true;
          }
          if (viewConfig.highlight) {
            setTimeout(() => {
              const songToHighlight = contentArea.querySelector(`.song-item[data-song-id="${viewConfig.highlight}"]`);
              if (songToHighlight) {
                songToHighlight.scrollIntoView({ behavior: 'smooth', block: 'center' });
                songToHighlight.style.transition = 'background-color 2s ease';
                songToHighlight.style.backgroundColor = 'rgba(255, 0, 0, 0.3)';
                setTimeout(() => {
                    songToHighlight.style.backgroundColor = '';
                }, 2000);
              }
            }, 500);
          }

          hideLoader();
        };

        const logPlay = (songId) => {
          if (!currentUser) {
            return;
          }
          const url = '?action=log_play';
          const data = JSON.stringify({ id: songId });
          if (navigator.sendBeacon) {
            const blob = new Blob([data], { type: 'application/json; charset=UTF-8' });
            navigator.sendBeacon(url, blob);
          } else {
            fetch(url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: data,
              keepalive: true,
            }).catch(error => {
              console.error('Beacon fallback fetch error for log_play:', error);
            });
          }
        };

        const playSongById = async (songId) => {
          const data = await fetchData(`?action=get_song_data&id=${songId}`);
          if (!data) return;
          currentSong = data;
          currentSong.logged = false;
          audio.src = currentSong.stream_url;
          audio.load();
          audio.play().catch(e => console.error("Audio play failed:", e));
          isPlaying = true;
          updatePlayerUI();
          if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = new MediaMetadata({
              title: currentSong.title, artist: currentSong.artist, album: currentSong.album,
              artwork: [{ src: currentSong.image_url, sizes: '500x500', type: 'image/webp' }]
            });
            navigator.mediaSession.setActionHandler('play', togglePlayPause);
            navigator.mediaSession.setActionHandler('pause', togglePlayPause);
            navigator.mediaSession.setActionHandler('previoustrack', playPrev);
            navigator.mediaSession.setActionHandler('nexttrack', playNext);
            navigator.mediaSession.setActionHandler('seekbackward', () => { audio.currentTime = Math.max(0, audio.currentTime - 10); });
            navigator.mediaSession.setActionHandler('seekforward', () => { audio.currentTime = Math.min(audio.duration, audio.currentTime + 10); });
          }
        };

        const updatePlayerUI = () => {
          if (!currentSong) return;
          if (playerBar.classList.contains('d-none')) {
            playerBar.classList.remove('d-none');
            document.body.classList.add('player-visible');
          }
          const imageUrl = `?action=get_image&id=${currentSong.id}`;
          playerElements.art.forEach(el => el.src = imageUrl);
          playerElements.title.forEach(el => el.textContent = currentSong.title);
          playerElements.artist.forEach(el => el.textContent = currentSong.artist);

          document.title = `${currentSong.title} • ${currentSong.artist}`;
          updatePlayPauseIcons();
          updateFavoriteIcons(currentSong.is_favorite == 1);
          
          document.querySelectorAll('.song-item.now-playing').forEach(el => el.classList.remove('now-playing'));
          document.querySelectorAll(`.song-item[data-song-id="${currentSong.id}"]`).forEach(el => el.classList.add('now-playing'));
        };

        const updatePlayPauseIcons = () => {
          const icon = isPlaying ? ICONS.pause : ICONS.play;
          playerElements.playPauseBtn.forEach(btn => {
            btn.innerHTML = icon;
            btn.title = isPlaying ? "Pause" : "Play";
          });
          if ('mediaSession' in navigator) {
            navigator.mediaSession.playbackState = isPlaying ? "playing" : "paused";
          }
        };
        
        const updateRepeatIcons = () => {
          let icon = ICONS.repeat, title = "Repeat Off";
          playerElements.repeatBtn.forEach(btn => btn.classList.remove('active'));
          if (repeatMode === 'one') {
            icon = ICONS.repeatOne; title = "Repeat One";
            playerElements.repeatBtn.forEach(btn => btn.classList.add('active'));
          } else if (repeatMode === 'all') {
            title = "Repeat All";
            playerElements.repeatBtn.forEach(btn => btn.classList.add('active'));
          }
          playerElements.repeatBtn.forEach(btn => {
            btn.innerHTML = icon; btn.title = title;
          });
        };
        
        const updateShuffleButtons = () => {
          playerElements.shuffleBtn.forEach(btn => {
            btn.classList.toggle('active', isShuffle);
            btn.title = isShuffle ? "Shuffle On" : "Shuffle Off";
          });
        };
        
        const updateFavoriteIcons = (isFav) => {
          const favButtonsInModal = document.querySelectorAll('#player-modal-favorite-btn, .context-menu-item[data-action="toggle_favorite"]');
          const icon = isFav ? ICONS.heartFill : ICONS.heart;
          favButtonsInModal.forEach(btn => {
            btn.innerHTML = icon + (btn.tagName === 'LI' ? ' Remove from Favorites' : '');
            btn.classList.toggle('active', isFav);
          });
          const contextMenuItem = contextMenu.querySelector('.context-menu-item[data-action="toggle_favorite"]');
          if (contextMenuItem) {
            contextMenuItem.innerHTML = `${icon} ${isFav ? 'Remove from Favorites' : 'Add to Favorites'}`;
          }
        };

        const updateVolumeSliderFill = () => {
          if (!playerElements.volumeSlider) return;
          const slider = playerElements.volumeSlider;
          const value = (slider.value - slider.min) / (slider.max - slider.min);
          const percent = value * 100;
          slider.style.background = `linear-gradient(to right, var(--ytm-primary-text) ${percent}%, var(--ytm-surface-2) ${percent}%)`;
        };
        
        const updateVolumeIcon = () => {
          if (!playerElements.volumeBtn) return;
          if (audio.muted || audio.volume === 0) {
            playerElements.volumeBtn.innerHTML = ICONS.volumeMute;
          } else if (audio.volume < 0.5) {
            playerElements.volumeBtn.innerHTML = ICONS.volumeDown;
          } else {
            playerElements.volumeBtn.innerHTML = ICONS.volumeUp;
          }
        };

        const toggleFavorite = async (songId) => {
          if (!currentUser) {
            showToast('Please log in to add favorites.', 'error');
            return;
          }
          const result = await fetchData('?action=toggle_favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: songId })
          });
          if (result) {
            if (currentSong && currentSong.id === songId) {
              currentSong.is_favorite = result.is_favorite ? 1 : 0;
              updateFavoriteIcons(result.is_favorite);
            }
        
            const songItemsInView = document.querySelectorAll(`.song-item[data-song-id="${songId}"]`);
        
            if (currentView.type === 'get_favorites' && !result.is_favorite) {
              songItemsInView.forEach(item => {
                item.style.transition = 'opacity 0.3s ease';
                item.style.opacity = '0';
                setTimeout(() => item.remove(), 300);
              });
            } else {
              songItemsInView.forEach(item => {
                item.dataset.isFavorite = result.is_favorite ? "1" : "0";
              });
            }
        
            const contextMenuItem = contextMenu.querySelector('.context-menu-item[data-action="toggle_favorite"]');
            if (contextMenuItem) {
              const isFav = result.is_favorite;
              const favText = isFav ? "Remove from Favorites" : "Add to Favorites";
              const favIcon = isFav ? ICONS.heartFill : ICONS.heart;
              contextMenuItem.innerHTML = `${favIcon} ${favText}`;
            }
        
            showToast(result.status === 'added' ? 'Added to favorites' : 'Removed from favorites', 'success');
          }
        };

        const showAllGenresModal = async () => {
          if (!genresModal || !genresModalBody) return;
          genresModalBody.innerHTML = `<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>`;
          genresModal.show();
          
          const allGenres = await fetchData('?action=get_all_genres');
          if (allGenres && allGenres.length > 0) {
            const genresHTML = allGenres.map(g => 
              `<button type="button" class="btn btn-light border-0 m-1 genre-modal-btn" data-genre="${encodeURIComponent(g)}">${g}</button>`
            ).join('');
            genresModalBody.innerHTML = `<div class="d-flex flex-wrap justify-content-center">${genresHTML}</div>`;
          } else {
            genresModalBody.innerHTML = `<p class="text-secondary text-center">No genres found.</p>`;
          }
        };
        
        const showShareModal = (type, id, name) => {
          const decodedName = decodeURIComponent(name);
          const shareUrl = `${window.location.origin}${window.location.pathname}?share_type=${type}&id=${id}`;

          shareModalTitle.textContent = `Share "${decodedName}"`;
          shareModalText.textContent = `Share this ${type} with your friends!`;
          shareUrlInput.value = shareUrl;

          document.getElementById('share-facebook').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}`;
          document.getElementById('share-twitter').href = `https://twitter.com/intent/tweet?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(`Check out ${decodedName} on PHP Music`)}`;
          document.getElementById('share-whatsapp').href = `https://api.whatsapp.com/send?text=${encodeURIComponent(`Check out ${decodedName} on PHP Music: ${shareUrl}`)}`;
          document.getElementById('share-telegram').href = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(`Check out ${decodedName} on PHP Music`)}`;
          
          copyShareUrlBtn.textContent = 'Copy';
          copyShareUrlBtn.disabled = false;

          if (shareModal) shareModal.show();
        };
        
        const positionContextMenu = (buttonEl) => {
          const buttonRect = buttonEl.getBoundingClientRect();
          const menuWidth = contextMenu.offsetWidth;
          const menuHeight = contextMenu.offsetHeight;
          const margin = 5;
          
          let x = buttonRect.right - menuWidth;
          let y = buttonRect.bottom + margin;

          if (x < margin) x = margin;
          if (y + menuHeight > window.innerHeight) y = buttonRect.top - menuHeight - margin;
          if (y < margin) y = margin; 

          contextMenu.style.left = `${x}px`;
          contextMenu.style.top = `${y}px`;
        }
        
        const buildAndShowPlaylistContextMenu = (buttonEl, playlistData) => {
          if (contextMenu.style.display === 'block' && contextMenuItemEl === buttonEl) {
            contextMenu.style.display = 'none';
            return;
          }
          contextMenuItemEl = buttonEl;
          const { publicId, name } = playlistData;
          let menuItems = `
            <li class="context-menu-item" data-action="edit_playlist" data-public-id="${publicId}" data-name="${name}"><i class="bi bi-pencil-fill"></i> Edit Playlist</li>
            <li class="context-menu-item text-danger" data-action="delete_playlist" data-public-id="${publicId}" data-name="${name}"><i class="bi bi-trash-fill"></i> Delete Playlist</li>
            <hr class="dropdown-divider bg-secondary mx-2 my-1">
            <li class="context-menu-item" data-action="close_menu"><i class="bi bi-x-lg"></i> Close Menu</li>`;
          contextMenu.innerHTML = menuItems;
          contextMenu.style.display = 'block';
          positionContextMenu(buttonEl);
        };
        
        const buildAndShowSongContextMenu = (buttonEl, songData) => {
          if (contextMenu.style.display === 'block' && contextMenuItemEl === buttonEl) {
            contextMenu.style.display = 'none';
            return;
          }
          contextMenuItemEl = buttonEl;
          const { id: songId, title, artist, album, user_id: songUserId, is_favorite } = songData;
          
          let menuItems = `
            <li class="context-menu-item" data-action="share_song" data-id="${songId}" data-name="${encodeURIComponent(title)}"><i class="bi bi-share-fill"></i> Share Song</li>
            <li class="context-menu-item" data-action="go_artist" data-name="${encodeURIComponent(artist)}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item" data-action="go_album" data-name="${encodeURIComponent(album)}"><i class="bi bi-disc-fill"></i> Go to Album</li>
            <li class="context-menu-item" data-action="show_all_genres"><i class="bi bi-tags-fill"></i> View All Genres</li>
            <li class="context-menu-item" data-action="download_song" data-id="${songId}"><i class="bi bi-download"></i> Download Song</li>
            <li class="context-menu-item" data-action="show_metadata" data-id="${songId}"><i class="bi bi-file-earmark-music"></i> View Metadata</li>
            `;
          
          if (currentUser) {
            const favText = is_favorite == 1 ? "Remove from Favorites" : "Add to Favorites";
            const favIcon = is_favorite == 1 ? ICONS.heartFill : ICONS.heart;
            menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1">`;
            menuItems += `<li class="context-menu-item" data-action="toggle_favorite" data-id="${songId}">${favIcon} ${favText}</li>`;
            menuItems += `<li class="context-menu-item" data-action="add_to_playlist" data-id="${songId}"><i class="bi bi-plus-lg"></i> Add to Playlist</li>`;
            if (currentView.type === 'playlist_songs') {
                menuItems += `<li class="context-menu-item text-danger" data-action="remove_from_playlist" data-id="${songId}"><i class="bi bi-x-circle-fill"></i> Remove from Playlist</li>`;
            }
            if (currentUser.id === songUserId || currentUser.artist === 'Music Library') {
              menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1">`;
              menuItems += `<li class="context-menu-item" data-action="edit_genre" data-id="${songId}"><i class="bi bi-pencil-fill"></i> Edit Genre</li>`;
              menuItems += `<li class="context-menu-item text-danger" data-action="delete_song" data-id="${songId}"><i class="bi bi-trash-fill"></i> Delete Song</li>`;
            }
          }

          menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1"><li class="context-menu-item" data-action="close_menu"><i class="bi bi-x-lg"></i> Close Menu</li>`;

          contextMenu.innerHTML = menuItems;
          contextMenu.style.display = 'block';
          positionContextMenu(buttonEl);
        };

        const showPlayerContextMenu = (e) => {
          e.preventDefault();
          e.stopPropagation();
          buildAndShowSongContextMenu(e.currentTarget, currentSong);
        };
        
        const showSongItemContextMenu = (buttonEl) => {
          const songItem = buttonEl.closest('.song-item');
          if (!songItem) return;

          const songData = {
            id: parseInt(songItem.dataset.songId),
            is_favorite: songItem.dataset.isFavorite === '1',
            title: songItem.dataset.songTitle,
            artist: songItem.dataset.songArtist,
            album: songItem.dataset.songAlbum,
            user_id: parseInt(songItem.dataset.songUserId)
          };
          
          buildAndShowSongContextMenu(buttonEl, songData);
        };
        
        const togglePlayPause = () => {
          if (!currentSong) return;
          isPlaying = !isPlaying;
          isPlaying ? audio.play() : audio.pause();
          updatePlayPauseIcons();
        };

        const playNext = () => {
          if (queue.length === 0) return;
          queueIndex++;
          if (queueIndex >= queue.length) {
            if (repeatMode === 'all') {
              queueIndex = 0;
            } else {
              isPlaying = false; audio.pause();
              updatePlayPauseIcons();
              if (queue.length > 0) queueIndex = queue.length - 1;
              return;
            }
          }
          playSongById(queue[queueIndex]);
        };

        const playPrev = () => {
          if (queue.length === 0) return;
          if (audio.currentTime > 3) {
            audio.currentTime = 0;
            return;
          }
          if (queueIndex === 0) {
            if (repeatMode === 'all') {
              queueIndex = queue.length - 1;
            } else {
              audio.currentTime = 0;
              return;
            }
          } else {
            queueIndex--;
          }
          playSongById(queue[queueIndex]);
        };

        const toggleShuffle = () => {
          isShuffle = !isShuffle;
          if (queue.length > 0 && currentSong) {
            const currentSongId = currentSong.id;
            if (isShuffle) {
              queue = [...originalQueue];
              for (let i = queue.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [queue[i], queue[j]] = [queue[j], queue[i]];
              }
              const shuffledCurrentIndex = queue.findIndex(id => id === currentSongId);
              if (shuffledCurrentIndex > -1) {
                [queue[0], queue[shuffledCurrentIndex]] = [queue[shuffledCurrentIndex], queue[0]];
              }
            } else {
              queue = [...originalQueue];
            }
            queueIndex = queue.findIndex(id => id === currentSongId);
          }
          updateShuffleButtons();
          showToast(isShuffle ? 'Shuffle enabled' : 'Shuffle disabled', 'info');
        };

        const setQueueAndPlay = async (startId, view) => {
            const contextView = view || currentView;
            if (contextView.type === 'get_recommendations') {
                const allShelfSongs = [...document.querySelectorAll('.shelf-item[data-song-id]')];
                const allSongIds = allShelfSongs.map(item => parseInt(item.dataset.songId));
                originalQueue = allSongIds;
            } else {
                let viewTypeForIds = contextView.type;
                if (contextView.type === 'user_profile') {
                    viewTypeForIds = 'get_profile_songs';
                }
                const allIds = await fetchData('?action=get_view_ids', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        view_type: viewTypeForIds,
                        param: contextView.param,
                        sort: contextView.sort,
                    })
                });
                if (!allIds || allIds.length === 0) { return; }
                originalQueue = allIds;
            }
          
            queue = [...originalQueue];
            if (isShuffle) { 
               isShuffle = false; toggleShuffle();
            }
            queueIndex = queue.findIndex(id => id === startId);
            if (queueIndex === -1) { return; }
            playSongById(startId);
        };

        const hideMobileSidebar = () => {
          const offcanvasEl = document.getElementById('main-nav-offcanvas');
          if (window.innerWidth < 768 && offcanvasEl) {
            const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
            if (offcanvas) offcanvas.hide();
          }
        };
        
        allNavLinks.forEach(link => {
          if (link.getAttribute('data-bs-toggle') === 'modal' || link.id === 'logout-btn' || link.id === 'clear-cache-btn') return;
          link.addEventListener('click', e => {
            e.preventDefault();
            const navLink = e.currentTarget;
            allNavLinks.forEach(l => l.classList.remove('active'));
            navLink.classList.add('active');
            
            const viewType = navLink.dataset.view;
            let sort = 'artist_asc';
            if (['get_favorites', 'playlist_songs'].includes(viewType)) sort = 'manual_order';
            if (viewType === 'get_user_playlists') sort = 'name_asc';
            if (viewType === 'get_albums') sort = 'album_asc';
            if (viewType === 'get_artists') sort = 'name_asc';
            if (viewType === 'user_profile') sort = 'id_desc';
            if (viewType === 'get_history') sort = 'history_desc';
            if(viewType === 'get_songs') sort = 'id_desc';

            loadView({ type: viewType, param: '', sort: sort });
            hideMobileSidebar();
          });
        });

        const performSearch = (query) => {
          if (query.trim() !== '') {
            loadView({ type: 'search', param: query.trim(), sort: 'artist_asc' });
          }
        };
        searchInputDesktop.addEventListener('keyup', (e) => { if (e.key === 'Enter') performSearch(e.target.value); });
        searchInputMobile.addEventListener('keyup', (e) => { if (e.key === 'Enter') performSearch(e.target.value); });
        searchBtnDesktop.addEventListener('click', () => performSearch(searchInputDesktop.value));
        searchBtnMobile.addEventListener('click', () => performSearch(searchInputMobile.value));

        sortSelect.addEventListener('change', (e) => {
          loadView({ ...currentView, sort: e.target.value });
        });

        if (fullScanModalEl && fullScanIframe) {
          fullScanModalEl.addEventListener('show.bs.modal', () => {
            fullScanIframe.src = '?action=full_scan';
          });
          fullScanModalEl.addEventListener('hidden.bs.modal', () => {
            fullScanIframe.src = 'about:blank';
            if (currentView.type === 'get_songs') {
              loadView(currentView);
            }
          });
        }
        
        clearHistoryBtn.addEventListener('click', async () => {
            if (confirm('Are you sure you want to clear your entire listening history? This cannot be undone.')) {
                const result = await fetchData('?action=clear_history');
                if (result && result.status === 'success') {
                    showToast(result.message, 'success');
                    loadView({type: 'get_history', param: '', sort: 'history_desc'});
                }
            }
        });
        
        playerElements.playPauseBtn.forEach(btn => btn.addEventListener('click', togglePlayPause));
        playerElements.prevBtn.forEach(btn => btn.addEventListener('click', playPrev));
        playerElements.nextBtn.forEach(btn => btn.addEventListener('click', playNext));
        playerElements.shuffleBtn.forEach(btn => btn.addEventListener('click', toggleShuffle));
        playerElements.repeatBtn.forEach(btn => btn.addEventListener('click', () => {
          repeatMode = (repeatMode === 'none') ? 'all' : (repeatMode === 'all') ? 'one' : 'none';
          updateRepeatIcons();
        }));
        
        playerElements.moreBtn.forEach(btn => btn.addEventListener('click', showPlayerContextMenu));

        if (playerElements.volumeSlider) {
          playerElements.volumeSlider.addEventListener('input', e => {
            audio.volume = e.target.value;
            audio.muted = false;
            updateVolumeSliderFill();
          });
        }
        if (playerElements.volumeBtn) {
          playerElements.volumeBtn.addEventListener('click', () => {
            audio.muted = !audio.muted;
            if (audio.muted) {
              playerElements.volumeSlider.value = 0;
            } else {
              playerElements.volumeSlider.value = audio.volume > 0 ? audio.volume : previousVolume;
              audio.volume = playerElements.volumeSlider.value;
            }
            updateVolumeSliderFill();
            updateVolumeIcon();
          });
        }
        audio.addEventListener('volumechange', () => {
          if (!audio.muted) {
            previousVolume = audio.volume;
            playerElements.volumeSlider.value = audio.volume;
          }
          updateVolumeSliderFill();
          updateVolumeIcon();
        });

        if (playerTrackInfoMobile) {
          playerTrackInfoMobile.addEventListener('click', e => {
            if (!e.target.closest('button') && playerModal) {
              playerModal.show();
            }
          });
        }
        
        contentArea.addEventListener('click', e => {
          const target = e.target;
          const moreBtn = target.closest('.more-btn');
          if (moreBtn) {
            e.preventDefault();
            e.stopPropagation();
            showSongItemContextMenu(moreBtn);
            return;
          }
          const playlistMoreBtn = target.closest('.playlist-more-btn');
          if (playlistMoreBtn) {
            e.preventDefault();
            e.stopPropagation();
            const playlistData = { publicId: playlistMoreBtn.dataset.publicId, name: playlistMoreBtn.dataset.name };
            buildAndShowPlaylistContextMenu(playlistMoreBtn, playlistData);
            return;
          }
          const shareBtn = target.closest('.share-view-btn');
          if (shareBtn) {
            e.stopPropagation();
            const type = currentView.type.split('_')[0];
            const { shareId, shareName } = shareBtn.dataset;
            showShareModal(type, shareId, shareName);
            return;
          }
          const createPlaylistBtn = target.closest('#create-new-playlist-btn');
          if (createPlaylistBtn) {
            createPlaylistModal.show();
            return;
          }
          const songArtistEl = target.closest('.song-artist, .item-subtitle');
          if (songArtistEl) {
            const artistEl = songArtistEl.closest('[data-artist]');
            if(artistEl) {
              e.stopPropagation();
              loadView({ type: 'artist_songs', param: artistEl.dataset.artist, sort: 'album_asc' });
              return;
            }
          }
          const songAlbumEl = target.closest('.song-album, .shelf-item[data-album]');
          if (songAlbumEl) {
            e.stopPropagation();
            loadView({ type: 'album_songs', param: songAlbumEl.dataset.album, sort: 'title_asc' });
            return;
          }
          const cardEl = target.closest('.card');
          if (cardEl && !target.closest('.playlist-more-btn')) {
             let viewType, param, sort;
             if (cardEl.dataset.artist) {
               viewType = 'artist_songs';
               param = cardEl.dataset.artist;
               sort = 'album_asc';
             } else if (cardEl.dataset.album) {
               viewType = 'album_songs';
               param = cardEl.dataset.album;
               sort = 'title_asc';
             } else if (cardEl.dataset.genre) {
               viewType = 'genre_songs';
               param = cardEl.dataset.genre;
               sort = 'artist_asc';
             } else if (cardEl.dataset.playlist) {
               viewType = 'playlist_songs';
               param = cardEl.dataset.playlist;
               sort = 'manual_order';
             }
             if (viewType) {
               loadView({ type: viewType, param: param, sort: sort });
             }
             return;
          }
          const seeAllButton = target.closest('.shelf-header button');
          if (seeAllButton) {
              e.stopPropagation();
              const viewType = seeAllButton.dataset.view;
              const viewParam = seeAllButton.dataset.viewParam;
              let sort = 'artist_asc';
              if (viewType === 'get_favorites' || viewType === 'get_user_playlists') sort = 'manual_order';
              if (viewType === 'get_history') sort = 'history_desc';
              if (viewType === 'artist_songs') sort = 'album_asc';

              loadView({ type: viewType, param: viewParam || '', sort: sort });
              return;
          }

          const songItem = target.closest('.song-item, .shelf-item[data-song-id]');
          if (songItem) {
            const songId = parseInt(songItem.dataset.songId);
            setQueueAndPlay(songId);
          }
        });
        
        document.addEventListener('click', e => {
          if (contextMenu.style.display === 'block' && !contextMenu.contains(e.target) && e.target !== contextMenuItemEl && !contextMenuItemEl?.contains(e.target)) {
            contextMenu.style.display = 'none';
          }
        });

        const closeOpenModals = () => {
          const playerModalInstance = bootstrap.Modal.getInstance(playerModalEl);
          if (playerModalInstance && playerModalInstance._isShown) {
            playerModalInstance.hide();
          }
          const genresModalInstance = bootstrap.Modal.getInstance(genresModalEl);
          if (genresModalInstance && genresModalInstance._isShown) {
            genresModalInstance.hide();
          }
        };
        
        contextMenu.addEventListener('click', async e => {
          const item = e.target.closest('.context-menu-item');
          if (!item) return;
          const { action, name, id, publicId } = item.dataset;
          contextMenu.style.display = 'none';

          switch (action) {
            case 'close_menu':
              break;
            case 'share_song':
              showShareModal('song', id, name);
              break;
            case 'go_artist':
            case 'go_album':
              closeOpenModals();
              let view, sort;
              if (action === 'go_artist') { view = 'artist_songs'; sort = 'album_asc'; }
              if (action === 'go_album') { view = 'album_songs'; sort = 'title_asc'; }
              loadView({ type: view, param: name, sort: sort });
              hideMobileSidebar();
              break;
            case 'show_all_genres':
              showAllGenresModal();
              break;
            case 'toggle_favorite':
              toggleFavorite(parseInt(id));
              break;
            case 'add_to_playlist':
              songIdForPlaylist = parseInt(id);
              const playlists = await fetchData('?action=get_user_playlists');
              if (playlists && playlists.length > 0) {
                  addToPlaylistModalBody.innerHTML = playlists.map(p => 
                      `<li class="list-group-item list-group-item-action bg-transparent text-white add-to-playlist-item my-2 p-2 border rounded" data-playlist-id="${p.id}">${p.name}</li>`
                  ).join('');
              } else {
                  addToPlaylistModalBody.innerHTML = `<p class="text-secondary text-center">No playlists found. Create one first!</p>`;
              }
              addToPlaylistModal.show();
              break;
            case 'remove_from_playlist':
                if (confirm('Are you sure you want to remove this song from the playlist?')) {
                    const result = await fetchData('?action=remove_from_playlist', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ song_id: parseInt(id), playlist_public_id: decodeURIComponent(currentView.param) })
                    });
                    if (result && result.status === 'success') {
                        showToast(result.message, 'success');
                        loadView(currentView);
                    }
                }
                break;
            case 'edit_playlist':
              document.getElementById('edit-playlist-id-input').value = publicId;
              document.getElementById('edit-playlist-name-input').value = name;
              editPlaylistModal.show();
              break;
            case 'delete_playlist':
              if (confirm(`Are you sure you want to delete the playlist "${name}"? This cannot be undone.`)) {
                const result = await fetchData('?action=delete_playlist', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ public_id: publicId })
                });
                if (result.status === 'success') {
                  showToast('Playlist deleted successfully.', 'success');
                  loadView(currentView);
                }
              }
              break;
            case 'edit_genre':
              const songIdToEdit = parseInt(id);
              const songDataForEdit = await fetchData(`?action=get_song_data&id=${songIdToEdit}`);
              if (!songDataForEdit) break;
              const newGenre = prompt("Enter the new genre for this song:", songDataForEdit.genre || "");
              if (newGenre !== null && newGenre.trim() !== '') {
                  const result = await fetchData('?action=edit_genre', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: songIdToEdit, genre: newGenre.trim() })
                  });
                  if (result && result.status === 'success') {
                      showToast('Genre updated!', 'success');
                      loadView(currentView);
                  }
              }
              break;
            case 'show_metadata':
              const songIdForMeta = parseInt(id);
              const metaSongData = await fetchData(`?action=get_song_data&id=${songIdForMeta}`);
              if (metaSongData && metadataModalBody) {
                  metadataModalBody.innerHTML = `
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Title:</strong> <span>${metaSongData.title || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Artist:</strong> <span>${metaSongData.artist || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Album:</strong> <span>${metaSongData.album || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Genre:</strong> <span>${metaSongData.genre || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Year:</strong> <span>${metaSongData.year || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Duration:</strong> <span>${formatTime(metaSongData.duration)}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Bitrate:</strong> <span>${metaSongData.bitrate ? Math.round(metaSongData.bitrate / 1000) + ' kbps' : 'N/A'}</span></li>
                    </ul>`;
                  metadataModal.show();
              }
              break;
            case 'download_song':
              window.location.href = `?action=download_song&id=${id}`;
              break;
            case 'delete_song':
              if (confirm('Are you sure you want to delete this song? This cannot be undone.')) {
                const result = await fetchData('?action=delete_song', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ id: parseInt(id) })
                });
                if (result.status === 'success') {
                  showToast('Song deleted successfully.', 'success');
                  loadView(currentView);
                }
              }
              break;
          }
        });

        if (genresModalBody) {
          genresModalBody.addEventListener('click', e => {
            const target = e.target.closest('.genre-modal-btn');
            if (!target) return;
            
            const genreName = target.dataset.genre;
            closeOpenModals();
            
            setTimeout(() => {
              loadView({ type: 'genre_songs', param: genreName, sort: 'artist_asc' });
              allNavLinks.forEach(l => l.classList.remove('active'));
              const genreNavLink = document.querySelector('.nav-link[data-view="get_genres"]');
              if(genreNavLink) genreNavLink.classList.add('active');
              hideMobileSidebar();
            }, 200);
          });
        }
        
        if (addToPlaylistModalBody) {
          addToPlaylistModalBody.addEventListener('click', async e => {
            const item = e.target.closest('.add-to-playlist-item');
            if (!item || !songIdForPlaylist) return;
            const playlistId = item.dataset.playlistId;
            const result = await fetchData('?action=add_to_playlist', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ playlist_id: playlistId, song_id: songIdForPlaylist })
            });
            if (result) {
                showToast(result.message, result.status === 'success' ? 'success' : 'info');
                addToPlaylistModal.hide();
                if (currentView.type === 'get_user_playlists') {
                    loadView(currentView);
                }
                songIdForPlaylist = null;
            }
          });
        }

        audio.addEventListener('timeupdate', () => {
          const { currentTime, duration } = audio;
          if (!isFinite(duration)) return;
          const progressPercent = (currentTime / duration) * 100;
          playerElements.progress.forEach(el => el.style.width = `${progressPercent}%`);
          const timeLeft = duration - currentTime;
          playerElements.currentTime.forEach(el => el.textContent = formatTime(currentTime));
          playerElements.timeLeft.forEach(el => el.textContent = '-' + formatTime(timeLeft));
          
          if (currentSong && !currentSong.logged && currentTime >= PLAY_LOG_THRESHOLD) {
            logPlay(currentSong.id);
            currentSong.logged = true;
          }
        });

        audio.addEventListener('loadedmetadata', () => {
          const { duration } = audio;
          if (!isFinite(duration)) return;
          playerElements.timeLeft.forEach(el => el.textContent = '-' + formatTime(duration));
        });

        audio.addEventListener('ended', () => (repeatMode === 'one') ? audio.play() : playNext());
        
        playerElements.progressContainer.forEach(container => {
            container.addEventListener('click', e => {
              if (!audio.duration || !isFinite(audio.duration)) return;
              const bounds = container.getBoundingClientRect();
              const percent = Math.max(0, Math.min(1, (e.clientX - bounds.left) / bounds.width));
              audio.currentTime = percent * audio.duration;
            });
        });

        mainContent.addEventListener('scroll', () => {
          if (mainContent.scrollTop + mainContent.clientHeight >= mainContent.scrollHeight - 300) {
            loadMoreContent();
          }
        });

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault();
          deferredInstallPrompt = e;
          installPwaBtn.classList.remove('d-none');
        });

        installPwaBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!deferredInstallPrompt) return;
          deferredInstallPrompt.prompt();
          await deferredInstallPrompt.userChoice;
          deferredInstallPrompt = null;
          installPwaBtn.classList.add('d-none');
        });

        clearCacheBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!confirm('This will clear all cached app data (including localStorage) and reload the page. Are you sure?')) {
            return;
          }
          try {
            localStorage.clear();
            sessionStorage.clear();
            if ('caches' in window) {
              const keys = await caches.keys();
              await Promise.all(keys.map(key => caches.delete(key)));
            }
            if ('serviceWorker' in navigator) {
              const registrations = await navigator.serviceWorker.getRegistrations();
              for(const registration of registrations) {
                await registration.unregister();
              }
            }
            showToast('Cache cleared successfully. Reloading...', 'success');
            setTimeout(() => window.location.reload(true), 1500);
          } catch (error) {
            console.error('Error clearing cache:', error);
            showToast('Failed to clear cache.', 'error');
          }
        });
        
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const changePwForm = document.getElementById('change-password-form');
        const profilePicForm = document.getElementById('profile-picture-form');
        const createPlaylistForm = document.getElementById('create-playlist-form');
        const editPlaylistForm = document.getElementById('edit-playlist-form');

        loginForm.addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('login-email').value;
          const password = document.getElementById('login-password').value;
          const data = await fetchData('?action=login', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ email, password })
          });
          if (data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('login-modal')).hide();
            loginForm.reset();
            showToast('Login successful!', 'success');
            await checkSession();
            loadView(currentView);
          }
        });

        registerForm.addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('register-email').value;
          const artist = document.getElementById('register-artist').value;
          const password = document.getElementById('register-password').value;
          const data = await fetchData('?action=register', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ email, artist, password })
          });
          if (data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('register-modal')).hide();
            registerForm.reset();
            showToast(data.message, 'success');
          }
        });
        
        createPlaylistForm.addEventListener('submit', async e => {
          e.preventDefault();
          const name = document.getElementById('playlist-name-input').value;
          const data = await fetchData('?action=create_playlist', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ name })
          });
          if (data && data.status === 'success') {
            createPlaylistModal.hide();
            createPlaylistForm.reset();
            showToast(data.message, 'success');
            if (currentView.type === 'get_user_playlists') {
              loadView(currentView);
            }
          }
        });
        
        editPlaylistForm.addEventListener('submit', async e => {
          e.preventDefault();
          const publicId = document.getElementById('edit-playlist-id-input').value;
          const name = document.getElementById('edit-playlist-name-input').value;
          const data = await fetchData('?action=edit_playlist', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ public_id: publicId, name: name })
          });
          if (data && data.status === 'success') {
            editPlaylistModal.hide();
            showToast(data.message, 'success');
            if (currentView.type === 'get_user_playlists') {
                loadView(currentView);
            }
          }
        });
        
        const handleLogout = async () => {
          await fetchData('?action=logout');
          currentUser = null;
          updateUIForAuthState();
          loadView({ type: 'get_songs', param: '', sort: 'artist_asc' });
        };
        document.getElementById('profile-dropdown-logout-desktop').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-logout-mobile').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-profile-desktop').addEventListener('click', () => loadView({type: 'user_profile'}));
        document.getElementById('profile-dropdown-profile-mobile').addEventListener('click', () => loadView({type: 'user_profile'}));
        document.getElementById('profile-dropdown-stats-desktop').addEventListener('click', () => loadView({type: 'get_user_stats'}));
        document.getElementById('profile-dropdown-stats-mobile').addEventListener('click', () => loadView({type: 'get_user_stats'}));


        changePwForm.addEventListener('submit', async e => {
          e.preventDefault();
          const new_password = document.getElementById('new-password').value;
          const data = await fetchData('?action=change_password', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ new_password })
          });
          if(data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
            changePwForm.reset();
            showToast(data.message, 'success');
          }
        });
        
        profilePicForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fileInput = document.getElementById('profile-picture-input');
            if (fileInput.files.length === 0) {
                showToast('Please select an image file.', 'error');
                return;
            }
            const formData = new FormData();
            formData.append('profile_picture', fileInput.files[0]);

            const result = await fetchData('?action=upload_profile_picture', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                showToast(result.message, 'success');
                await checkSession();
                bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
            }
        });

        const uploadLimitText = document.getElementById('upload-limit-text');
        const uploadRemainingText = document.getElementById('upload-remaining-text');
        const songFilesInput = document.getElementById('song-files');
        const songGenreInput = document.getElementById('song-genre');
        const startUploadBtn = document.getElementById('start-upload-btn');
        const uploadProgressArea = document.getElementById('upload-progress-area');
        let filesToUpload = [];

        songFilesInput.addEventListener('change', () => { filesToUpload = Array.from(songFilesInput.files); });
        
        startUploadBtn.addEventListener('click', async () => {
          if (filesToUpload.length === 0) {
            showToast('Please select files to upload.', 'error'); return;
          }
          startUploadBtn.disabled = true;
          uploadProgressArea.innerHTML = '';

          for (let i = 0; i < filesToUpload.length; i++) {
            const file = filesToUpload[i];
            const progressId = `progress-${i}`;
            uploadProgressArea.innerHTML += `
              <div class="mb-2">
                <small>${file.name}</small>
                <div class="progress"><div id="${progressId}" class="progress-bar" role="progressbar" style="width: 0%">0%</div></div>
              </div>`;

            const formData = new FormData();
            formData.append('song', file);
            formData.append('genre', songGenreInput.value);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?action=upload_song', true);
            xhr.upload.onprogress = (e) => {
              if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                const progressBar = document.getElementById(progressId);
                progressBar.style.width = `${percentComplete}%`;
                progressBar.textContent = `${percentComplete}%`;
              }
            };
            
            await new Promise(resolve => {
              xhr.onload = () => {
                const progressBar = document.getElementById(progressId);
                if (xhr.status === 200) {
                  progressBar.classList.add('bg-success');
                } else {
                  progressBar.classList.add('bg-danger');
                  progressBar.textContent = 'Error';
                  try {
                    showToast(`Upload failed for ${file.name}: ${JSON.parse(xhr.responseText).message}`, 'error');
                  } catch(e) {
                     showToast(`Upload failed for ${file.name}: Server error.`, 'error');
                  }
                }
                resolve();
              };
              xhr.onerror = () => {
                document.getElementById(progressId).classList.add('bg-danger');
                document.getElementById(progressId).textContent = 'Error';
                showToast(`A network error occurred during upload of ${file.name}.`, 'error');
                resolve();
              };
              xhr.send(formData);
            });
          }
          startUploadBtn.disabled = false;
          showToast('All uploads complete.', 'success');
          await checkSession();
          loadView(currentView);
          filesToUpload = [];
          songFilesInput.value = '';
          songGenreInput.value = '';
        });

        if (copyShareUrlBtn) {
          copyShareUrlBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(shareUrlInput.value).then(() => {
              copyShareUrlBtn.textContent = 'Copied!';
              copyShareUrlBtn.disabled = true;
              setTimeout(() => {
                copyShareUrlBtn.textContent = 'Copy';
                copyShareUrlBtn.disabled = false;
              }, 2000);
            }).catch(err => {
              console.error('Failed to copy: ', err);
              showToast('Failed to copy link.', 'error');
            });
          });
        }

        function updateUIForAuthState() {
          const isLoggedIn = !!currentUser;
          document.body.classList.toggle('logged-in', isLoggedIn);
          document.body.classList.toggle('logged-out', !isLoggedIn);
          if (isLoggedIn) {
            document.body.classList.toggle('user-verified', currentUser.verified === 'yes');
            const picUrl = currentUser.profile_picture_url;
            profilePictureHeaderDesktop.src = picUrl;
            profilePictureHeaderMobile.src = picUrl;
            profilePicturePreview.src = picUrl;
          } else {
            document.body.classList.remove('user-verified');
          }
        }

        async function checkSession() {
          const data = await fetchData('?action=get_session');
          if (data && data.status === 'loggedin') {
            currentUser = data.user;
            if (uploadLimitText) uploadLimitText.textContent = data.upload_limit;
            if (uploadRemainingText) {
              uploadRemainingText.textContent = `Today's remaining uploads: ${currentUser.uploads_remaining}`;
            }
          } else {
            currentUser = null;
          }
          updateUIForAuthState();
        }

        const init = async () => {
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?pwa=sw').catch(err => console.error('SW registration failed:', err));
          }
          playerElements.prevBtn.forEach(b => b.innerHTML = ICONS.prev);
          playerElements.nextBtn.forEach(b => b.innerHTML = ICONS.next);
          playerElements.shuffleBtn.forEach(b => b.innerHTML = ICONS.shuffle);
          updatePlayPauseIcons();
          updateRepeatIcons();
          updateShuffleButtons();
          updateVolumeIcon();
          if (playerElements.volumeSlider) {
            audio.volume = playerElements.volumeSlider.value;
            updateVolumeSliderFill();
          }

          await checkSession();

          if (window.initialView) {
            loadView(window.initialView);
          } else {
            loadView({ type: 'get_songs', param: '', sort: 'id_desc' });
          }
        };

        init();
      });
    </script>
  </body>
</html>
