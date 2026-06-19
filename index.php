<?php
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
  ob_start('ob_gzhandler');
}
error_reporting(E_ALL & ~E_DEPRECATED);

if (isset($_GET['pwa'])) {
  if ($_GET['pwa'] == 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
      "name" => "PHP Music",
      "short_name" => "Music",
      "start_url" => "./",
      "scope" => "./",
      "display" => "standalone",
      "background_color" => "#030303",
      "theme_color" => "#121212",
      "description" => "A simple, fast music player with user accounts and uploads.",
      "icons" => [[
          "src" => "?action=get_app_icon&size=192",
          "sizes" => "192x192",
          "type" => "image/svg+xml",
          "purpose" => "any maskable"
        ],[
          "src" => "?action=get_app_icon&size=512",
          "sizes" => "512x512",
          "type" => "image/svg+xml",
          "purpose" => "any maskable"
        ]
      ]
    ]);
    exit;
  }
  if ($_GET['pwa'] == 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo <<<SW
const CACHE_NAME = 'php-music-cache-v28';
const STATIC_ASSETS =[
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

  if (isApiCall || isPwaCall || event.request.headers.get('range')) {
    event.respondWith(fetch(event.request));
    return;
  }
  
  if (event.request.mode === 'navigate' || url.pathname.endsWith('/')) {
    event.respondWith(
      fetch(event.request).then(networkResponse => {
        return caches.open(CACHE_NAME).then(cache => {
          cache.put(event.request, networkResponse.clone());
          return networkResponse;
        });
      }).catch(() => {
        return caches.match(event.request);
      })
    );
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
define('APP_VERSION', '1.13');
define('PAGE_SIZE', 25);
define('ADMIN_PAGE_SIZE', 20);
define('ADMIN_PASSWORD', 'admin');
define('ADMIN_PASSWORD_HASH', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT));
define('DAILY_UPLOAD_LIMIT', 10);

function get_db() {
  static $db = null;
  if ($db !== null) return $db; 
  
  try {
    $db = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_TIMEOUT => 30]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA cache_size=-64000; PRAGMA temp_store=MEMORY; PRAGMA mmap_size=30000000000; PRAGMA foreign_keys=ON;");
    
    $db->sqliteCreateFunction('match_artist', function($artist_field, $search_name) {
      if ($artist_field === null) return 0;
      $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist_field);
      if (!is_array($parts)) $parts = [$artist_field]; // Fallback to prevent crash
      foreach ($parts as $part) {
        if (strcasecmp(trim($part), trim($search_name)) === 0) return 1;
      }
      return 0;
    }, 2);

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
    if (isset($_POST['toggle_ban']) && isset($_POST['user_id'])) {
      $db = get_db();
      $user_id = (int)$_POST['user_id'];
      $db->prepare("UPDATE users SET banned = CASE WHEN banned = 1 THEN 0 ELSE 1 END WHERE id = ?")->execute([$user_id]);
      header('Location: ' . $_SERVER['REQUEST_URI']);
      exit;
    }
    if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
      $db = get_db();
      $del_uid = (int)$_POST['user_id'];
      $stmt = $db->prepare("SELECT file FROM music WHERE user_id = ?");
      $stmt->execute([$del_uid]);
      while ($row = $stmt->fetch()) {
        if ($row['file'] && file_exists($row['file'])) @unlink($row['file']);
      }
      $db->prepare("DELETE FROM music WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM users WHERE id = ?")->execute([$del_uid]);
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
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - PHP Music</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
      :root { --ytm-bg: #030303; --ytm-surface: #121212; --ytm-surface-2: #282828; --ytm-primary-text: #ffffff; --ytm-secondary-text: #aaaaaa; --ytm-accent: #ff0000; }
      body { background-color: var(--ytm-bg); color: var(--ytm-primary-text); font-family: 'Roboto', sans-serif; }
      .app-container { display: flex; min-height: 100vh; flex-direction: column; }
      .sidebar { width: 240px; background-color: var(--ytm-bg); padding: 1.5rem 0; display: flex; flex-direction: column; flex-shrink: 0; z-index: 1045; }
      .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; }
      .content-area-wrapper { padding: 1.5rem 2rem; }
      .sidebar .logo { font-size: 1.5rem; font-weight: 700; padding: 0 1.5rem 1.5rem 1.5rem; }
      .sidebar .logo span { color: var(--ytm-accent); }
      .nav-link { color: var(--ytm-secondary-text); display: flex; align-items: center; font-weight: 500; border-left: 3px solid transparent; gap: 1rem; text-decoration: none; padding: 0.75rem 1.5rem; }
      .nav-link:hover { background-color: var(--ytm-surface); color: var(--ytm-primary-text); }
      .page-header { padding: 1.5rem 2rem 1.5rem 2rem; }
      .content-title { font-size: 2rem; font-weight: 700; margin-bottom: 0; }
      .user-list { background-color: var(--ytm-surface); border-radius: 8px; overflow: hidden; }
      .user-list-header { background-color: var(--ytm-surface-2); font-weight: 500; }
      .user-item > *, .user-list-header > * { min-width: 0; }
      .user-item .text-truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .user-list-header, .user-item { display: grid; grid-template-columns: 50px 1fr 1fr 120px 100px 100px 220px; align-items: center; gap: 1rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--ytm-surface-2); }
      .user-item { color: var(--ytm-primary-text); }
      .user-item .badge { font-size: 0.85rem; padding: 0.4em 0.6em; }
      .user-item .btn { padding: .25rem .5rem; font-size: .875rem; margin-right: .25rem; }
      .login-container { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
      .login-card { background-color: var(--ytm-surface); width: 100%; max-width: 400px; padding: 2rem; border-radius: 8px; }
      .form-control { background-color: var(--ytm-surface-2); border: 1px solid #404040; color: var(--ytm-primary-text); }
      .form-control:focus { background-color: var(--ytm-surface-2); border-color: #666; color: var(--ytm-primary-text); box-shadow: none; }
      .pagination .page-link { background-color: var(--ytm-surface-2); border-color: #404040; color: var(--ytm-primary-text); }
      .pagination .page-item.active .page-link { background-color: var(--ytm-accent); border-color: var(--ytm-accent); }
      .pagination .page-item.disabled .page-link { background-color: var(--ytm-surface); border-color: #404040; color: var(--ytm-secondary-text); }
      @media (min-width: 992px) {
        .app-container { flex-direction: row; }
      }
      @media (max-width: 991.98px) {
        .app-container { flex-direction: column; height: auto; }
        .sidebar { padding: 0; }
        .main-content { overflow-y: visible; }
        .content-area-wrapper { padding: 1rem; }
        .page-header { padding: 1rem; flex-direction: column !important; align-items: stretch !important; gap: 1rem; }
        .page-header form { max-width: 100% !important; flex-direction: column; gap: 0.5rem; }
        .page-header form select, .page-header form input { width: 100% !important; margin: 0 !important; }
        .content-title { font-size: 1.5rem; }
        .user-list-header { display: none; }
        .user-item { display: grid; grid-template-columns: 1fr auto; grid-template-rows: auto auto; grid-template-areas: "main action" "stats stats"; padding: 1rem; gap: 0.5rem 1rem; }
        .user-item-id, .user-item-email-desktop, .user-item-artist-desktop, .user-item-verified-desktop, .user-item-last-up-desktop, .user-item-count-desktop { display: none; }
        .user-item-main { grid-area: main; display: flex; flex-direction: column; gap: 0.25rem; }
        .user-item-main .user-id-mobile { font-size: 0.8rem; color: var(--ytm-secondary-text); }
        .user-item-main .user-email { font-weight: 500; }
        .user-item-main .user-artist { font-size: 0.9rem; color: var(--ytm-secondary-text); }
        .user-item-action { grid-area: action; display: flex; flex-direction: column; gap: 0.5rem; align-items: center; }
        .user-item-stats { grid-area: stats; display: flex; justify-content: space-around; align-items: center; border-top: 1px solid var(--ytm-surface-2); padding-top: 0.75rem; margin-top: 0.75rem; font-size: 0.8rem; text-align: center; }
        .user-item-stats > div { display: flex; flex-direction: column; }
        .user-item-stats .label { text-transform: uppercase; color: var(--ytm-secondary-text); font-size: 0.7rem; margin-bottom: 0.25rem; }
      }
      @media (min-width: 992px) { .user-item-main, .user-item-stats { display: none; } }
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
      <div class="d-lg-none d-flex align-items-center justify-content-between p-3 border-bottom" style="border-color: var(--ytm-surface-2) !important; background-color: var(--ytm-bg);">
        <div class="logo" style="font-size: 1.25rem; font-weight: 700;">Admin<span style="color: var(--ytm-accent);">Panel</span></div>
        <button class="btn text-white" type="button" data-bs-toggle="offcanvas" data-bs-target="#admin-sidebar">
          <i class="bi bi-list fs-2"></i>
        </button>
      </div>
      <nav class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="admin-sidebar" style="background-color: var(--ytm-bg);">
        <div class="offcanvas-header border-bottom" style="border-color: var(--ytm-surface-2) !important;">
          <h5 class="offcanvas-title logo m-0" style="font-size: 1.25rem; font-weight: 700;">Admin<span style="color: var(--ytm-accent);">Panel</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#admin-sidebar"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-0 py-lg-4">
          <div class="logo d-none d-lg-block">Admin<span>Panel</span></div>
          <a href="./" class="nav-link"><i class="bi bi-arrow-left-circle-fill d-none d-lg-inline-block"></i><span>Back to Player</span></a>
          <a href="?access=admin&logout=1" class="nav-link"><i class="bi bi-box-arrow-left d-none d-lg-inline-block"></i><span>Logout</span></a>
        </div>
      </nav>
      <main class="main-content">
        <div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
          <h1 class="content-title m-0">User Management</h1>
          <?php $search = $_GET['search'] ?? ''; ?>
          <?php $sort_admin = $_GET['sort'] ?? 'newest'; ?>
          <form method="GET" action="" class="d-flex w-100" style="max-width: 450px;">
            <input type="hidden" name="access" value="admin">
            <select name="sort" class="form-select me-2" style="width: auto;" onchange="this.form.submit()">
              <option value="newest" <?php echo $sort_admin === 'newest' ? 'selected' : ''; ?>>Newest</option>
              <option value="oldest" <?php echo $sort_admin === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
              <option value="verified" <?php echo $sort_admin === 'verified' ? 'selected' : ''; ?>>Verified</option>
              <option value="unverified" <?php echo $sort_admin === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
              <option value="banned" <?php echo $sort_admin === 'banned' ? 'selected' : ''; ?>>Banned</option>
              <option value="not_banned" <?php echo $sort_admin === 'not_banned' ? 'selected' : ''; ?>>Not Banned</option>
            </select>
            <input type="text" name="search" class="form-control me-2" placeholder="Search user..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-danger"><i class="bi bi-search"></i></button>
          </form>
        </div>
        <div class="content-area-wrapper">
          <div class="user-list">
            <div class="user-list-header">
              <div>ID</div><div>Email</div><div>Artist</div><div>Status</div><div>Last Up</div><div>Count</div><div>Action</div>
            </div>
            <?php
              $db = get_db();
              $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
              $offset = ($page - 1) * ADMIN_PAGE_SIZE;
              
              $where = '';
              $params = [];
              if ($search !== '') {
                $where = "WHERE id = ? OR email LIKE ? OR artist LIKE ?";
                $params = [$search, "%$search%", "%$search%"];
              }
              
              $total_users_stmt = $db->prepare("SELECT COUNT(id) FROM users $where");
              $total_users_stmt->execute($params);
              $total_users = $total_users_stmt->fetchColumn();
              $total_pages = ceil($total_users / ADMIN_PAGE_SIZE);
              
              $admin_sort_map = [
                'newest' => 'ORDER BY id DESC',
                'oldest' => 'ORDER BY id ASC',
                'verified' => "ORDER BY verified DESC, id ASC",
                'unverified' => "ORDER BY verified ASC, id ASC",
                'banned' => "ORDER BY banned DESC, id ASC",
                'not_banned' => "ORDER BY banned ASC, id ASC",
              ];
              $admin_order_by = $admin_sort_map[$sort_admin] ?? 'ORDER BY id DESC';
              
              $sql = "SELECT id, email, artist, verified, last_upload_date, daily_upload_count, banned FROM users $where $admin_order_by LIMIT ? OFFSET ?";
              $stmt = $db->prepare($sql);
              $param_index = 1;
              if ($search !== '') {
                $stmt->bindValue($param_index++, $search);
                $stmt->bindValue($param_index++, "%$search%");
                $stmt->bindValue($param_index++, "%$search%");
              }
              $stmt->bindValue($param_index++, (int)ADMIN_PAGE_SIZE, PDO::PARAM_INT);
              $stmt->bindValue($param_index++, (int)$offset, PDO::PARAM_INT);
              $stmt->execute();
              
              $users = $stmt->fetchAll();
              foreach ($users as $user):
            ?>
            <div class="user-item">
              <div class="user-item-id"><?php echo htmlspecialchars($user['id']); ?></div>
              <div class="user-item-email-desktop text-truncate"><?php echo htmlspecialchars($user['email'] ?? 'Anonymous'); ?></div>
              <div class="user-item-artist-desktop text-truncate"><?php echo htmlspecialchars($user['artist']); ?></div>
              <div class="user-item-verified-desktop">
                <span class="badge <?php echo $user['verified'] === 'yes' ? 'bg-success' : 'bg-secondary'; ?>">
                  <?php echo htmlspecialchars(strtoupper($user['verified'])); ?>
                </span>
                <?php if ($user['banned']): ?>
                <span class="badge bg-danger">BANNED</span>
                <?php endif; ?>
              </div>
              <div class="user-item-last-up-desktop"><?php echo htmlspecialchars($user['last_upload_date'] ?? 'N/A'); ?></div>
              <div class="user-item-count-desktop"><?php echo htmlspecialchars($user['daily_upload_count'] ?? '0'); ?></div>
              <div class="user-item-main">
                <div class="user-id-mobile">ID: <?php echo htmlspecialchars($user['id']); ?></div>
                <div class="user-email text-truncate"><?php echo htmlspecialchars($user['email'] ?? 'Anonymous'); ?></div>
                <div class="user-artist text-truncate"><?php echo htmlspecialchars($user['artist']); ?></div>
              </div>
              <div class="user-item-action">
                <form method="POST" action="?access=admin&page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort_admin); ?>" class="d-inline">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" name="toggle_verify" class="btn <?php echo $user['verified'] === 'yes' ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $user['verified'] === 'yes' ? 'Un-verify' : 'Verify'; ?>
                  </button>
                  <button type="submit" name="toggle_ban" class="btn <?php echo $user['banned'] ? 'btn-secondary' : 'btn-warning'; ?>">
                    <?php echo $user['banned'] ? 'Unban' : 'Ban'; ?>
                  </button>
                  <button type="submit" name="delete_user" class="btn btn-danger" onclick="return confirm('Permanently delete this user and all their data?');">Delete</button>
                </form>
              </div>
              <div class="user-item-stats">
                <div>
                  <span class="label">Status</span>
                  <span class="badge <?php echo $user['banned'] ? 'bg-danger' : 'bg-success'; ?>"><?php echo $user['banned'] ? 'BANNED' : 'ACTIVE'; ?></span>
                </div>
                <div>
                  <span class="label">Verified</span>
                  <span class="badge <?php echo $user['verified'] === 'yes' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars(strtoupper($user['verified'])); ?></span>
                </div>
                <div>
                  <span class="label">Last Upload</span>
                  <span><?php echo htmlspecialchars($user['last_upload_date'] ?? 'N/A'); ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($total_pages > 1): ?>
          <nav class="mt-4" aria-label="User pagination">
            <ul class="pagination justify-content-center">
              <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort_admin); ?>">Previous</a>
              </li>
              <?php
                $start_page = max(1, $page - 1);
                $end_page = min($total_pages, $start_page + 2);
                if ($end_page - $start_page < 2) {
                  $start_page = max(1, $end_page - 2);
                }
              ?>
              <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
              <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort_admin); ?>"><?php echo $i; ?></a>
              </li>
              <?php endfor; ?>
              <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?access=admin&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort_admin); ?>">Next</a>
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
    echo '{"status":"error", "message":"JSON encoding error"}';
  } else {
    echo $json;
  }
  exit;
}

$initialViewJS = '';
if (isset($_GET['share_type'])) {
  $db_for_share = get_db();
  $share_type = $_GET['share_type'];
  $view_config = null;

  switch ($share_type) {
    case 'song':
      $share_id = (int)($_GET['id'] ?? 0);
      $stmt = $db_for_share->prepare("SELECT album, user_id FROM music WHERE id = ?");
      $stmt->execute([$share_id]);
      $song_info = $stmt->fetch();
      if ($song_info) {
        $view_config = [
          'type' => 'album_songs',
          'param' => rawurlencode($song_info['album']),
          'sort' => 'title_asc',
          'highlight' => $share_id,
          'filter_user_id' => $song_info['user_id']
        ];
      }
      break;
    case 'album':
      $album_name = $_GET['album_name'] ?? $_GET['id'] ?? null;
      $artist_id = $_GET['artist_id'] ?? null;
      if ($album_name) {
        $view_config = ['type' => 'album_songs', 'param' => rawurlencode($album_name), 'sort' => 'title_asc'];
        if ($artist_id) {
          $view_config['filter_user_id'] = (int)$artist_id;
        }
      }
      break;
    case 'artist':
      $share_id = $_GET['id'] ?? null;
      if (is_numeric($share_id)) {
        $stmt = $db_for_share->prepare("SELECT artist FROM users WHERE id = ?");
        $stmt->execute([(int)$share_id]);
        $artist_name = $stmt->fetchColumn();
        if ($artist_name) {
          $view_config = ['type' => 'artist_songs', 'param' => rawurlencode($artist_name), 'sort' => 'album_asc', 'filter_user_id' => (int)$share_id];
        }
      } else if ($share_id) {
        $view_config = ['type' => 'artist_songs', 'param' => rawurlencode($share_id), 'sort' => 'album_asc'];
      }
      break;
    case 'playlist':
      $share_id = $_GET['id'] ?? null;
      $stmt = $db_for_share->prepare("SELECT id FROM playlists WHERE public_id = ?");
      $stmt->execute([$share_id]);
      if ($stmt->fetch()) {
        $view_config = ['type' => 'playlist_songs', 'param' => rawurlencode($share_id), 'sort' => 'manual_order'];
      }
      break;
    case 'mix':
      $share_id = $_GET['id'] ?? null;
      $stmt = $db_for_share->prepare("SELECT id FROM mixes WHERE public_id = ?");
      $stmt->execute([$share_id]);
      if ($stmt->fetch()) {
        $view_config = ['type' => 'mix_songs', 'param' => rawurlencode($share_id), 'sort' => 'manual_order'];
      }
      break;
  }

  if ($view_config) {
    $initialViewJSON = json_encode($view_config);
    $initialViewJS = "<script>window.initialView = {$initialViewJSON};</script>";
  }
}

function init_db($db) {
  $users_columns = $db->query("PRAGMA table_info(users);")->fetchAll(PDO::FETCH_COLUMN, 1);
  $users_table_exists = !empty($users_columns);

  $db->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      email TEXT UNIQUE,
      artist TEXT COLLATE NOCASE,
      password_hash TEXT,
      last_upload_date TEXT,
      daily_upload_count INTEGER DEFAULT 0,
      verified TEXT DEFAULT 'no',
      profile_picture BLOB,
      profile_picture_type TEXT,
      backup_key TEXT,
      banned INTEGER DEFAULT 0
    );
  ");
  $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_artist_idx ON users(artist);");

  if ($users_table_exists) {
    if (!in_array('last_upload_date', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN last_upload_date TEXT;");
    if (!in_array('daily_upload_count', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN daily_upload_count INTEGER DEFAULT 0;");
    if (!in_array('verified', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN verified TEXT DEFAULT 'no';");
    if (!in_array('profile_picture', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN profile_picture BLOB;");
    if (!in_array('profile_picture_type', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN profile_picture_type TEXT;");
    if (!in_array('backup_key', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN backup_key TEXT;");
    if (!in_array('banned', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN banned INTEGER DEFAULT 0;");
  }

  $music_columns = $db->query("PRAGMA table_info(music);")->fetchAll(PDO::FETCH_COLUMN, 1);
  $music_table_exists = !empty($music_columns);
  
  $db->exec("
    CREATE TABLE IF NOT EXISTS music (
      id INTEGER PRIMARY KEY,
      user_id INTEGER,
      file TEXT UNIQUE,
      title TEXT,
      artist TEXT COLLATE NOCASE,
      album TEXT COLLATE NOCASE,
      genre TEXT COLLATE NOCASE,
      year INTEGER,
      duration INTEGER,
      image BLOB,
      last_modified INTEGER,
      bitrate INTEGER,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
  ");

  if ($music_table_exists) {
    if (!in_array('bitrate', $music_columns)) $db->exec("ALTER TABLE music ADD COLUMN bitrate INTEGER;");
    if (!in_array('lyrics', $music_columns)) $db->exec("ALTER TABLE music ADD COLUMN lyrics TEXT;");
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
    CREATE TABLE IF NOT EXISTS mixes (
      id INTEGER PRIMARY KEY,
      public_id TEXT UNIQUE NOT NULL,
      name TEXT NOT NULL,
      creator TEXT NOT NULL,
      image_id INTEGER,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
  ");
  $db->exec("
    CREATE TABLE IF NOT EXISTS mix_songs (
      mix_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      FOREIGN KEY (mix_id) REFERENCES mixes(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("DELETE FROM mixes WHERE created_at <= datetime('now', '-3 days')");

  $db->exec("
    CREATE TABLE IF NOT EXISTS history (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      played_at TEXT,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS play_counts (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      play_count INTEGER DEFAULT 1,
      last_played TEXT,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS follows (
      follower_id INTEGER NOT NULL,
      following_id INTEGER NOT NULL,
      PRIMARY KEY (follower_id, following_id),
      FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
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
  $db->exec("CREATE INDEX IF NOT EXISTS mix_songs_mix_id_idx ON mix_songs(mix_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS history_user_id_idx ON history(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS play_counts_user_id_idx ON play_counts(user_id);");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO users (email, artist, password_hash, verified) VALUES (?, ?, ?, ?)")
      ->execute(['musiclibrary@mail.com', 'Music Library', password_hash('musiclibrary', PASSWORD_DEFAULT), 'yes']);
  }
}

function romanize_string($string) {
  if (class_exists('Transliterator')) {
    $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
    if ($transliterator) {
      $string = $transliterator->transliterate($string);
    }
  }
  $string = strtolower($string);
  $string = preg_replace('/[^a-z0-9]/', '', $string);
  return empty($string) ? 'unknown' : $string;
}

function sanitize_for_path($string) {
  return romanize_string($string);
}

function get_upload_limit() {
  $max_upload = ini_get('upload_max_filesize');
  $max_post = ini_get('post_max_size');
  return "Max file size: " . min($max_upload, $max_post);
}

function process_image_to_webp($imageData, $target_width = 500, $quality = 75) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return null;
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) return null;

  $src_w = imagesx($sourceImage);
  $src_h = imagesy($sourceImage);
  $min_dim = min($src_w, $src_h);
  $src_x = (int)(($src_w - $min_dim) / 2);
  $src_y = (int)(($src_h - $min_dim) / 2);

  $resizedImage = imagecreatetruecolor($target_width, $target_width);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, $src_x, $src_y, $target_width, $target_width, $min_dim, $min_dim);

  ob_start();
  imagewebp($resizedImage, null, $quality);
  $webpData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

function process_image_to_jpeg($imageData, $target_width = 500, $quality = 85) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) return null;

  $src_w = imagesx($sourceImage);
  $src_h = imagesy($sourceImage);
  $min_dim = min($src_w, $src_h);
  $src_x = (int)(($src_w - $min_dim) / 2);
  $src_y = (int)(($src_h - $min_dim) / 2);

  $resizedImage = imagecreatetruecolor($target_width, $target_width);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, $src_x, $src_y, $target_width, $target_width, $min_dim, $min_dim);

  ob_start();
  imagejpeg($resizedImage, null, $quality);
  $jpegData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $jpegData;
}

if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  
  if (!isset($_SESSION['db_initialized'])) {
    init_db($db);
    $_SESSION['db_initialized'] = true;
  }

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $user_id = $_SESSION['user_id'] ?? null;
  $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  switch ($action) {
    case 'check_update_code':
      error_reporting(0);
      $remote_url = "https://raw.githubusercontent.com/HirotakaDango/PHP-Music/main/index.php";
      $remote_code = false;
      
      if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Music-Update-Checker');
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $remote_code = curl_exec($ch);
        curl_close($ch);
      } 
      
      if (!$remote_code) {
        $context = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: PHP-Music-Update-Checker\r\n"]]);
        $remote_code = @file_get_contents($remote_url, false, $context);
      }
      
      if (!$remote_code) {
        send_json(['status' => 'error', 'message' => 'Could not connect to GitHub. Check your server firewall or internet.']);
      }
      
      $local_code = @file_get_contents(__FILE__);
      
      $remote_normalized = str_replace(["\r\n", "\r"], "\n", trim($remote_code));
      $local_normalized = str_replace(["\r\n", "\r"], "\n", trim($local_code));
      
      $is_matching = hash_equals(hash('sha256', $remote_normalized), hash('sha256', $local_normalized));
      
      while (ob_get_level() > 0) { @ob_end_clean(); }
      send_json([
        'status' => 'success',
        'update_available' => !$is_matching
      ]);
      break;

    case 'get_app_icon':
      header('Content-Type: image/svg+xml');
      $size = intval($_GET['size'] ?? 192);
      echo '<?xml version="1.0" encoding="utf-8"?><svg width="'.$size.'px" height="'.$size.'px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" rx="6" fill="#F3F4F6"/><path d="M0 24L24 0V24H0Z" fill="#E5E7EB" clip-path="inset(0px round 6px)"/><path d="M4 10V13" stroke="#000000" stroke-width="1.7" stroke-linecap="round"/><path d="M16 10V13" stroke="#000000" stroke-width="1.7" stroke-linecap="round"/><path d="M7 7L7 16" stroke="#DF1463" stroke-width="1.7" stroke-linecap="round"/><path d="M13 7L13 16" stroke="#000000" stroke-width="1.7" stroke-linecap="round"/><path d="M19 7L19 16" stroke="#000000" stroke-width="1.7" stroke-linecap="round"/><path d="M10 4L10 19" stroke="#000000" stroke-width="1.7" stroke-linecap="round"/></svg>';
      exit;

    case 'get_session':
      if ($user_id) {
        $stmt = $db->prepare("SELECT id, email, artist, verified, last_upload_date, daily_upload_count, banned FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user && empty($user['banned'])) {
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
      $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR artist = ?");
      $stmt->execute([$email, $artist]);
      if ($stmt->fetch()) {
        http_response_code(409);
        send_json(['status' => 'error', 'message' => 'Email or Artist Name already registered.']);
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
      $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND email IS NOT NULL");
      $stmt->execute([$email]);
      $user = $stmt->fetch();
      if ($user && $user['banned'] == 1) {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'This account has been banned.']);
      } elseif ($user && $user['password_hash'] !== null && password_verify($password, $user['password_hash'])) {
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

    case 'change_name':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $new_name = trim(htmlspecialchars($data['new_name'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($new_name)) {
        http_response_code(400);
        send_json(['status' => 'error', 'message' => 'Name cannot be empty.']);
      }
      $stmt = $db->prepare("SELECT id FROM users WHERE artist = ? AND id != ? COLLATE NOCASE");
      $stmt->execute([$new_name, $user_id]);
      if ($stmt->fetch()) {
        http_response_code(409);
        send_json(['status' => 'error', 'message' => 'Display name already taken.']);
      }
      $stmt = $db->prepare("UPDATE users SET artist = ? WHERE id = ?");
      $stmt->execute([$new_name, $user_id]);
      $_SESSION['user_artist'] = $new_name;
      send_json(['status' => 'success', 'message' => 'Name changed successfully.']);
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
        $stmt2 = $db->prepare("SELECT image FROM music WHERE user_id = ? AND image IS NOT NULL LIMIT 1");
        $stmt2->execute([$pic_user_id]);
        $song_img = $stmt2->fetchColumn();
        if ($song_img) {
          header('Content-Type: image/webp');
          echo $song_img;
        } else {
          header('Content-Type: image/svg+xml');
          echo '<svg xmlns="http://www.w3.org/2000/svg" fill="#404040" viewBox="0 0 16 16"><path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/><path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/></svg>';
        }
      }
      exit;

    case 'delete_account_all':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("SELECT file FROM music WHERE user_id = ?");
      $stmt->execute([$user_id]);
      while ($row = $stmt->fetch()) { if ($row['file'] && file_exists($row['file'])) @unlink($row['file']); }
      $db->prepare("DELETE FROM music WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
      session_destroy();
      send_json(['status' => 'success']);
      break;

    case 'delete_account_keep_data':
      if (!$user_id) { http_response_code(403); exit; }
      $raw_str = bin2hex(random_bytes(16));
      $hash = password_hash($raw_str, PASSWORD_DEFAULT);
      $final_key = $user_id . '-' . $raw_str;
      $new_email = 'deleted_' . $user_id . '_' . time() . '@mail.com';
      $new_artist = 'Anonymous User ' . $user_id;
      $db->prepare("UPDATE users SET email = ?, artist = ?, password_hash = NULL, backup_key = ?, profile_picture = NULL WHERE id = ?")
         ->execute([$new_email, $new_artist, $hash, $user_id]);
      session_destroy();
      send_json(['status' => 'success', 'backup_key' => $final_key]);
      break;

    case 'restore_account':
      $data = json_decode(file_get_contents('php://input'), true);
      $key_parts = explode('-', $data['backup_key'] ?? '');
      if (count($key_parts) !== 2) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid key format.']);
      }
      $r_user_id = (int)$key_parts[0];
      $r_str = $key_parts[1];
      $stmt = $db->prepare("SELECT backup_key FROM users WHERE id = ? AND backup_key IS NOT NULL");
      $stmt->execute([$r_user_id]);
      $user_bk = $stmt->fetchColumn();
      if ($user_bk && password_verify($r_str, $user_bk)) {
        $n_email = filter_var($data['new_email'], FILTER_VALIDATE_EMAIL);
        $n_artist = trim(htmlspecialchars($data['new_artist'] ?? '', ENT_QUOTES, 'UTF-8'));
        $n_password = $data['new_password'];
        if (!$n_email || empty($n_artist) || strlen($n_password) < 6) {
          http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid input data.']);
        }
        $stmt2 = $db->prepare("SELECT id FROM users WHERE email = ? OR artist = ?");
        $stmt2->execute([$n_email, $n_artist]);
        if ($stmt2->fetch()) {
          http_response_code(409); send_json(['status' => 'error', 'message' => 'Email or Artist already taken.']);
        }
        $hash = password_hash($n_password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET email = ?, artist = ?, password_hash = ?, backup_key = NULL WHERE id = ?")
           ->execute([$n_email, $n_artist, $hash, $r_user_id]);
        send_json(['status' => 'success', 'message' => 'Account restored successfully. Please log in.']);
      } else {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid or expired backup key.']);
      }
      break;

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
        $main_artist = trim(preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist)[0]);
        if (empty($main_artist)) $main_artist = 'Unknown Artist';
        
        $artist_path = sanitize_for_path($main_artist);
        $upload_dir = MUSIC_DIR . '/uploads/' . $artist_path;
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid('m_') . '.' . $ext;
        $filePath = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
          $title = trim($info['comments']['title'][0] ?? pathinfo($file['name'], PATHINFO_FILENAME));
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

          send_json(['status' => 'success', 'message' => 'File uploaded.']);
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
        if ($song['file'] && file_exists($song['file'])) {
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
      $stmt = $db->prepare("SELECT file, title, artist FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && file_exists($song['file'])) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $ext = pathinfo($song['file'], PATHINFO_EXTENSION);
        $dl_name = trim(($song['title'] ?? '') . (($song['artist'] && $song['title']) ? ' - ' : '') . ($song['artist'] ?? ''));
        $dl_name = $dl_name ? $dl_name . '.' . $ext : basename($song['file']);
        $encoded = rawurlencode($dl_name);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        header('Content-Type: ' . finfo_file($finfo, $song['file']));
        finfo_close($finfo);
        header('Content-Length: ' . filesize($song['file']));
        header("Content-Disposition: attachment; filename=\"song." . $ext . "\"; filename*=UTF-8''" . $encoded);
        @flush();
        readfile($song['file']);
        exit;
      } else {
        http_response_code(404);
        send_json(['status' => 'error', 'message' => 'File not found.']);
      }
      break;

    case 'edit_metadata':
      if (!$user_id) { http_response_code(403); exit; }
      $song_id = intval($_POST['id'] ?? 0);
      $new_title = trim(htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'));
      $new_artist = trim(htmlspecialchars($_POST['artist'] ?? '', ENT_QUOTES, 'UTF-8'));
      $new_album = trim(htmlspecialchars($_POST['album'] ?? '', ENT_QUOTES, 'UTF-8'));
      $new_genre = trim(htmlspecialchars($_POST['genre'] ?? '', ENT_QUOTES, 'UTF-8'));
      $new_lyrics = trim(htmlspecialchars($_POST['lyrics'] ?? '', ENT_QUOTES, 'UTF-8'));

      $stmt = $db->prepare("SELECT user_id, file FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && ($song['user_id'] == $user_id || $_SESSION['user_artist'] == 'Music Library')) {
        $update_fields = ["title = ?", "artist = ?", "album = ?", "genre = ?", "lyrics = ?"];
        $update_params = [$new_title, $new_artist, $new_album, $new_genre, $new_lyrics];

        $jpeg_data = null;
        $webp_data = null;
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
          $raw_img = file_get_contents($_FILES['cover_image']['tmp_name']);
          $webp_data = process_image_to_webp($raw_img);
          $jpeg_data = process_image_to_jpeg($raw_img);
          if ($webp_data) {
            $update_fields[] = "image = ?";
            $update_params[] = $webp_data;
          }
        }

        $update_params[] = $song_id;
        $stmt = $db->prepare("UPDATE music SET " . implode(", ", $update_fields) . " WHERE id = ?");
        $stmt->execute($update_params);

        if (file_exists(__DIR__ . '/getid3/write.php') && file_exists($song['file'])) {
          require_once __DIR__ . '/getid3/write.php';
          $tagwriter = new getid3_writetags;
          $tagwriter->filename = $song['file'];
          $ext = strtolower(pathinfo($song['file'], PATHINFO_EXTENSION));
          if ($ext === 'mp3') {
            $tagwriter->tagformats = ['id3v1', 'id3v2.3'];
          } elseif ($ext === 'flac') {
            $tagwriter->tagformats = ['metaflac'];
          } elseif ($ext === 'ogg') {
            $tagwriter->tagformats = ['vorbiscomment'];
          } else {
            $tagwriter->tagformats = ['id3v2.3'];
          }
          $tagwriter->overwrite_tags = true;
          $tagwriter->tag_encoding = 'UTF-8';
          $tagwriter->remove_other_tags = false;
          $tagwriter->tag_data = [
            'title' => [htmlspecialchars_decode($new_title, ENT_QUOTES)],
            'artist' => [htmlspecialchars_decode($new_artist, ENT_QUOTES)],
            'album' => [htmlspecialchars_decode($new_album, ENT_QUOTES)],
            'genre' => [htmlspecialchars_decode($new_genre, ENT_QUOTES)]
          ];

          if ($jpeg_data) {
            $tagwriter->tag_data['attached_picture'] = [
              [
                'data' => $jpeg_data,
                'picturetypeid' => 3,
                'description' => 'Cover',
                'mime' => 'image/jpeg'
              ]
            ];
          }
          $tagwriter->WriteTags();
          $new_mtime = filemtime($song['file']);
          $db->prepare("UPDATE music SET last_modified = ? WHERE id = ?")->execute([$new_mtime, $song_id]);
        }

        send_json(['status' => 'success', 'message' => 'Metadata updated successfully.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => 'You do not have permission to edit this song.']);
      }
      break;

    case 'get_explore':
      $shelves = [];
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite";
      $album_fields = "m.album, m.artist, m.user_id, MAX(m.id) as id";

      $discovery_songs_stmt = $db->prepare("
        SELECT {$song_fields} FROM music m
        JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 15) r ON m.id = r.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :fav_user_id
        " . ($user_id ? "WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :hist_user_id)" : "") . "
      ");
      $song_params = [':fav_user_id' => $user_id ? $user_id : 0];
      if ($user_id) {
        $song_params[':hist_user_id'] = $user_id;
      }
      $discovery_songs_stmt->execute($song_params);
      $discovery_songs = $discovery_songs_stmt->fetchAll();
      if (count($discovery_songs) > 0) {
        $shelves[] = ['title' => 'Discover Songs', 'type' => 'songs', 'items' => $discovery_songs];
      }
      
      $discovery_stmt = $db->prepare("
        SELECT {$album_fields} FROM music m
        WHERE m.album != 'Unknown Album' " . ($user_id ? "AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)" : "") . "
        GROUP BY m.album, m.user_id ORDER BY RANDOM() LIMIT 10
      ");
      $album_params = [];
      if ($user_id) {
        $album_params[':user_id'] = $user_id;
      }
      $discovery_stmt->execute($album_params);
      $discovery_albums = $discovery_albums = $discovery_stmt->fetchAll();
      if (count($discovery_albums) > 0) {
        $shelves[] = ['title' => 'Discover Albums', 'type' => 'albums', 'items' => $discovery_albums];
      }

      $rec_artists_stmt = $db->prepare("
        SELECT m.artist AS name, MAX(m.id) AS id
        FROM music m
        WHERE m.artist != 'Unknown Artist' AND m.artist != '' AND m.artist IS NOT NULL
        " . ($user_id ? "AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)" : "") . "
        GROUP BY m.artist
        ORDER BY RANDOM() LIMIT 10
      ");
      $artist_params = [];
      if ($user_id) {
        $artist_params[':user_id'] = $user_id;
      }
      $rec_artists_stmt->execute($artist_params);
      $rec_artists_rows = $rec_artists_stmt->fetchAll();
      $rec_artists = [];
      $stmt_is_user = $db->prepare("SELECT id FROM users WHERE artist = ? COLLATE NOCASE");
      foreach ($rec_artists_rows as $da) {
        $stmt_is_user->execute([$da['name']]);
        $uid = $stmt_is_user->fetchColumn();
        $rec_artists[] = [
          'name' => $da['name'],
          'id' => $uid ? $uid : $da['id'],
          'is_user' => (bool)$uid
        ];
      }
      if (count($rec_artists) > 0) {
        $shelves[] = ['title' => 'Discover Artists', 'type' => 'artists', 'items' => $rec_artists];
      }

      if ($user_id) {
        $freq_played_stmt = $db->prepare("
          SELECT {$song_fields}
          FROM play_counts pc JOIN music m ON pc.song_id = m.id
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
          WHERE pc.user_id = :user_id
          ORDER BY pc.play_count DESC LIMIT 15
        ");
        $freq_played_stmt->execute([':user_id' => $user_id]);
        $freq_played_songs = $freq_played_stmt->fetchAll();
        if (count($freq_played_songs) > 0) {
          $shelves[] = ['title' => 'Frequently Played', 'type' => 'songs', 'items' => $freq_played_songs];
        }

        $rec_followed_songs_stmt = $db->prepare("
          SELECT {$song_fields} FROM music m
          JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
          WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)
          LIMIT 15
        ");
        $rec_followed_songs_stmt->execute([':user_id' => $user_id]);
        $rec_followed_songs = $rec_followed_songs_stmt->fetchAll();
        if (count($rec_followed_songs) > 0) {
          $shelves[] = ['title' => 'Recommended Songs From Your Artists', 'type' => 'songs', 'items' => $rec_followed_songs];
        }

        $rec_followed_albums_stmt = $db->prepare("
          SELECT {$album_fields} FROM music m
          WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id) AND m.album != 'Unknown Album'
          GROUP BY m.album, m.user_id ORDER BY RANDOM() LIMIT 10
        ");
        $rec_followed_albums_stmt->execute([':user_id' => $user_id]);
        $rec_followed_albums = $rec_followed_albums_stmt->fetchAll();
        if (count($rec_followed_albums) > 0) {
          $shelves[] = ['title' => 'Recommended Albums From Your Artists', 'type' => 'albums', 'items' => $rec_followed_albums];
        }
      }

      send_json(['shelves' => $shelves]);
      break;
    
    case 'get_songs':
      $sort_key = $_GET['sort'] ?? 'id_desc';
      $sort_map = [
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'id_desc' => 'ORDER BY m.id DESC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['id_desc'];

      $where_clauses = [];
      $params = [$user_id];

      if (!empty($_GET['artist'])) {
        $where_clauses[] = 'match_artist(m.artist, ?) = 1';
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
      if (!empty($_GET['filter_user_id'])) {
        $where_clauses[] = 'm.user_id = ?';
        $params[] = $_GET['filter_user_id'];
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
        'added_newest' => 'ORDER BY f.sort_order DESC',
        'added_oldest' => 'ORDER BY f.sort_order ASC',
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
      $public_id = $_GET['public_id'];
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY ps.sort_order ASC',
        'added_newest' => 'ORDER BY ps.added_at DESC',
        'added_oldest' => 'ORDER BY ps.added_at ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      
      $is_all = isset($_GET['all']) && $_GET['all'] == '1';
      $current_limit = $is_all ? '' : $limit_clause;
      
      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        JOIN playlist_songs ps ON m.id = ps.song_id
        JOIN playlists p ON ps.playlist_id = p.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE p.public_id = ?
        {$order_by} {$current_limit}
      ");
      $stmt->execute([$user_id, $public_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_following':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("SELECT u.artist as name, u.id as id FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? ORDER BY u.artist COLLATE NOCASE ASC " . $limit_clause);
      $stmt->execute([$user_id]);
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

    case 'toggle_follow':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $following_id = intval($data['following_id']);
      if ($following_id === $user_id) {
        send_json(['status' => 'error', 'message' => 'Cannot follow yourself.']);
      }
      $stmt = $db->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
      $stmt->execute([$user_id, $following_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?")->execute([$user_id, $following_id]);
        send_json(['status' => 'unfollowed']);
      } else {
        $db->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)")->execute([$user_id, $following_id]);
        send_json(['status' => 'followed']);
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
      $filter_user_id = $post_data['filter_user_id'] ?? '';

      if (in_array($view_type, ['artist_songs', 'album_songs', 'genre_songs', 'playlist_songs', 'mix_songs'])) {
        $param = urldecode($param);
      }

      $sql = "SELECT m.id FROM music m ";
      $conditions = "";
      $params = [];
      $default_sort = 'artist_asc';

      switch ($view_type) {
        case 'get_songs':
          $default_sort = 'id_desc';
          break;
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
          $conditions = "WHERE match_artist(m.artist, ?) = 1";
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
        case 'mix_songs':
          $sql = "SELECT ms.song_id as id FROM mix_songs ms JOIN mixes mx ON ms.mix_id = mx.id ";
          $conditions = "WHERE mx.public_id = ?";
          $params[] = $param;
          $default_sort = 'manual_order';
          break;
        case 'search':
          $conditions = "WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?)";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param];
          break;
        case 'get_recommendations':
          send_json([]);
        default:
          send_json([]);
      }
      
      if ($filter_user_id !== '') {
        if (empty($conditions)) {
          $conditions = "WHERE m.user_id = ?";
        } else {
          $conditions .= " AND m.user_id = ?";
        }
        $params[] = $filter_user_id;
      }

      $sort_map = [
        'id_desc' => 'ORDER BY m.id DESC',
        'id_asc' => 'ORDER BY m.id ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'history_desc' => 'ORDER BY MAX(h.played_at) DESC',
        'random' => 'ORDER BY RANDOM()',
      ];
      if ($view_type === 'get_favorites') {
        $sort_map['manual_order'] = 'ORDER BY f.sort_order ASC';
        $sort_map['added_newest'] = 'ORDER BY f.sort_order DESC';
        $sort_map['added_oldest'] = 'ORDER BY f.sort_order ASC';
      } elseif ($view_type === 'playlist_songs') {
        $sort_map['manual_order'] = 'ORDER BY ps.sort_order ASC';
        $sort_map['added_newest'] = 'ORDER BY ps.added_at DESC';
        $sort_map['added_oldest'] = 'ORDER BY ps.added_at ASC';
      } elseif ($view_type === 'mix_songs') {
        $sort_map['manual_order'] = 'ORDER BY ms.sort_order ASC';
      }
      $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];
      
      $stmt = $db->prepare($sql . " " . $conditions . " " . $order_by);
      $stmt->execute($params);
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_artists':
      $sort_key = $_GET['sort'] ?? 'name_asc';
      $stmt = $db->query("SELECT artist, id FROM music WHERE artist != '' AND artist IS NOT NULL ORDER BY id DESC");
      $rows = $stmt->fetchAll();
      $artists = [];
      foreach ($rows as $row) {
        $parts = preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($p);
            if (!isset($artists[$key])) {
              $artists[$key] = ['name' => $p, 'id' => $row['id']];
            }
          }
        }
      }
      if ($sort_key === 'name_desc') {
        usort($artists, function($a, $b) { return strcasecmp($b['name'], $a['name']); });
      } else {
        usort($artists, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
      }
      $sliced = array_slice($artists, $offset, PAGE_SIZE);
      send_json(array_values($sliced));
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
      $stmt = $db->query("SELECT m.album, m.artist, m.user_id, MAX(m.id) as id FROM music m WHERE m.album != '' AND m.album IS NOT NULL GROUP BY m.album, m.user_id " . $order_by . $limit_clause);
      send_json($stmt->fetchAll());
      break;
    
    case 'get_genres':
      $stmt = $db->query("SELECT DISTINCT genre FROM music WHERE genre != '' AND genre IS NOT NULL ORDER BY genre COLLATE NOCASE" . $limit_clause);
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
        $details['user_id'] = $user_id;

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
          SELECT p.name, p.public_id, p.user_id, u.artist as creator,
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
          'manual_order' => 'ORDER BY ps.sort_order ASC', 'added_newest' => 'ORDER BY ps.added_at DESC', 'added_oldest' => 'ORDER BY ps.added_at ASC',
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC', 'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC', 
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['manual_order'];
        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m JOIN playlist_songs ps ON m.id = ps.song_id JOIN playlists p ON ps.playlist_id = p.id LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE p.public_id = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $name]);
        $songs = $stmt_songs->fetchAll();
      } elseif ($type === 'mix') {
        $mix_public_id = $name;
        $stmt_details = $db->prepare("SELECT id, name, creator, image_id FROM mixes WHERE public_id = ?");
        $stmt_details->execute([$mix_public_id]);
        $mix_row = $stmt_details->fetch();
        if ($mix_row) {
          $details = [
            'name' => $mix_row['name'],
            'creator' => $mix_row['creator'],
            'image_url' => '?action=get_image&id=' . ($mix_row['image_id'] ?: 0),
            'song_count' => 0,
            'total_duration' => 0,
            'public_id' => $mix_public_id
          ];
          $stmt_songs = $db->prepare("
            SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
            FROM music m 
            JOIN mix_songs ms ON m.id = ms.song_id 
            LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
            WHERE ms.mix_id = ? ORDER BY ms.sort_order ASC
          ");
          $stmt_songs->execute([$user_id, $mix_row['id']]);
          $songs = $stmt_songs->fetchAll();
          foreach($songs as $s) { $details['total_duration'] += $s['duration']; }
          $details['song_count'] = count($songs);
        }
      } elseif (in_array($type, ['artist', 'album', 'genre'])) {
        if (empty($name)) { http_response_code(400); exit; }
        $field = $type;
        $filter_user_id = $_GET['filter_user_id'] ?? '';
        $user_cond = "";
        $user_params = [];
        
        if ($field === 'artist') {
          $field_cond = "match_artist(m.artist, ?) = 1";
          $user_params[] = $name;
        } else {
          $field_cond = "m.{$field} = ?";
          $user_params[] = $name;
        }
        
        if ($filter_user_id !== '') {
          $user_cond = " AND m.user_id = ?";
          $user_params[] = $filter_user_id;
        }

        $stmt_details = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration, MAX(id) as image_id FROM music m WHERE {$field_cond} {$user_cond}");
        $stmt_details->execute($user_params);
        $details = $stmt_details->fetch();
        $details['name'] = $name;
        $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
        $details['public_id'] = null;

        if ($type === 'artist') {
          $stmt_user = $db->prepare("SELECT id FROM users WHERE artist = ? COLLATE NOCASE");
          $stmt_user->execute([$name]);
          $artist_user_id = $stmt_user->fetchColumn();

          if ($artist_user_id) {
            $details['is_user'] = true;
            $details['user_id'] = $artist_user_id;
            $details['image_url'] = '?action=get_profile_picture&id=' . $artist_user_id;
            if ($user_id) {
              $stmt_follow = $db->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
              $stmt_follow->execute([$user_id, $artist_user_id]);
              $details['is_following'] = (bool)$stmt_follow->fetchColumn();
            } else {
              $details['is_following'] = false;
            }
            $stmt_playlists = $db->prepare("SELECT p.name, p.public_id, (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id FROM playlists p WHERE p.user_id = ? ORDER BY p.created_at DESC");
            $stmt_playlists->execute([$artist_user_id]);
            $details['playlists'] = $stmt_playlists->fetchAll();
          }
        }

        $sort_map = [
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC', 'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC', 'year_desc' => 'ORDER BY m.year DESC', 'year_asc' => 'ORDER BY m.year ASC',
        ];
        $default_sort = ($type === 'album') ? 'title_asc' : 'artist_asc';
        $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];
        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE {$field_cond} {$user_cond} {$order_by} {$limit_clause}
        ");
        $exec_params = array_merge([$user_id], $user_params);
        $stmt_songs->execute($exec_params);
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
      $q = $_GET['q'] ?? '';
      $query = '%' . $q . '%';
      $shelves = [];

      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite";
      $stmt_top = $db->prepare("
        SELECT {$song_fields} FROM music m 
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? 
        WHERE m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ? 
        ORDER BY 
          CASE 
            WHEN m.title LIKE ? THEN 1 
            WHEN m.artist LIKE ? THEN 2 
            WHEN m.album LIKE ? THEN 3 
            ELSE 4 
          END ASC, m.id DESC 
        LIMIT 1
      ");
      $stmt_top->execute([$user_id, $query, $query, $query, $q, $q, $q]);
      $top_result = $stmt_top->fetch();
      
      if ($top_result) {
        $shelves[] = ['title' => 'Top Result', 'type' => 'top_result', 'items' => [$top_result]];
      }

      $artists = [];
      $added_artists = [];
      
      $stmt = $db->prepare("SELECT id, artist as name FROM users WHERE artist LIKE ? AND artist != '' AND artist IS NOT NULL ORDER BY artist ASC LIMIT 15");
      $stmt->execute([$query]);
      $user_artists = $stmt->fetchAll();
      
      foreach ($user_artists as $ua) {
        $artists[] = ['name' => $ua['name'], 'id' => $ua['id'], 'is_user' => true];
        $added_artists[strtolower($ua['name'])] = true;
      }

      $stmt = $db->prepare("SELECT DISTINCT artist, MAX(id) as id FROM music WHERE artist LIKE ? AND artist != '' AND artist IS NOT NULL GROUP BY artist LIMIT 15");
      $stmt->execute([$query]);
      $music_artists = $stmt->fetchAll();
      
      foreach ($music_artists as $ma) {
        $parts = preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $ma['artist']);
        foreach ($parts as $p) {
          $p = trim($p);
          if ($p !== '' && stripos($p, $q) !== false && !isset($added_artists[strtolower($p)])) {
            $artists[] = ['name' => $p, 'id' => $ma['id'], 'is_user' => false];
            $added_artists[strtolower($p)] = true;
          }
        }
      }
      
      if (count($artists) > 0) {
        $shelves[] = ['title' => 'Artists', 'type' => 'artists', 'items' => array_slice($artists, 0, 15)];
      }

      $stmt = $db->prepare("SELECT m.album, m.artist, m.user_id, MAX(m.id) as id FROM music m WHERE m.album LIKE ? AND m.album != '' AND m.album IS NOT NULL GROUP BY m.album, m.user_id ORDER BY m.album ASC LIMIT 15");
      $stmt->execute([$query]);
      $albums = $stmt->fetchAll();
      if (count($albums) > 0) {
        $shelves[] = ['title' => 'Albums', 'type' => 'albums', 'items' => $albums];
      }

      $stmt = $db->prepare("SELECT p.name, p.public_id, u.artist as creator, (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id FROM playlists p JOIN users u ON p.user_id = u.id WHERE p.name LIKE ? ORDER BY p.name ASC LIMIT 15");
      $stmt->execute([$query]);
      $playlists = $stmt->fetchAll();
      if (count($playlists) > 0) {
        $shelves[] = ['title' => 'Playlists', 'type' => 'playlists', 'items' => $playlists];
      }

      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite";
      $stmt = $db->prepare("SELECT {$song_fields} FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?) ORDER BY m.title ASC LIMIT 50");
      $stmt->execute([$user_id, $query, $query, $query]);
      $songs = $stmt->fetchAll();
      if (count($songs) > 0) {
        $shelves[] = ['title' => 'Songs', 'type' => 'songs_list', 'items' => $songs];
      }

      send_json(['shelves' => $shelves]);
      break;

    case 'get_song_data':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT m.id, m.file, m.title, m.artist, m.album, m.genre, m.year, m.duration, m.bitrate, m.lyrics, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.id = ?");
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
        session_write_close();
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $filesize = filesize($file_path);
        
        $mime_type = 'audio/mpeg';
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        switch ($ext) {
          case 'flac': $mime_type = 'audio/flac'; break;
          case 'ogg': $mime_type = 'audio/ogg'; break;
          case 'wav': $mime_type = 'audio/wav'; break;
          case 'm4a': $mime_type = 'audio/mp4'; break;
        }

        header('Content-Type: ' . $mime_type);
        header('Accept-Ranges: bytes');
        
        $start = 0;
        $end = $filesize - 1;
        $length = $filesize;

        if (isset($_SERVER['HTTP_RANGE'])) {
          $range = str_replace('bytes=', '', $_SERVER['HTTP_RANGE']);
          $parts = explode('-', $range, 2);
          $start = intval($parts[0]);
          if (isset($parts[1]) && $parts[1] !== '') {
            $end = intval($parts[1]);
          }
          if ($start > $end || $start >= $filesize) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header("Content-Range: bytes */$filesize");
            exit;
          }
          $length = $end - $start + 1;
          header('HTTP/1.1 206 Partial Content');
          header("Content-Range: bytes $start-$end/$filesize");
        } else {
          header('HTTP/1.1 200 OK');
        }

        header('Content-Length: ' . $length);

        $f = @fopen($file_path, 'rb');
        if ($f) {
          fseek($f, $start);
          $chunk_size = 1024 * 8;
          $bytes_left = $length;
          @flush();
          while ($bytes_left > 0 && !feof($f)) {
            if (connection_aborted()) break;
            $read_size = min($chunk_size, $bytes_left);
            $data = fread($f, $read_size);
            if ($data === false) break;
            echo $data;
            @flush();
            $bytes_left -= strlen($data);
          }
          fclose($f);
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
        echo '<svg xmlns="http://www.w3.org/2000/svg" fill="#404040" class="bi bi-music-note" viewBox="-4 -4 24 24"><path d="M9 13c0 1.105-1.12 2-2.5 2S4 14.105 4 13s1.12-2 2.5-2 2.5.895 2.5 2"/><path fill-rule="evenodd" d="M9 3v10H8V3h1z"/><path d="M8 2.82a1 1 0 0 1 .804-.98l3-.6A1 1 0 0 1 13 2.22V4L8 5V2.82z"/></svg>';
      }
      exit;

    case 'get_user_playlists':
      if (!$user_id) { send_json([]); }
      $song_id = isset($_GET['song_id']) ? $_GET['song_id'] : '0';
      $sort_key = $_GET['sort'] ?? 'name_asc';
      $sort_map = [
        'name_asc' => 'ORDER BY p.name COLLATE NOCASE ASC',
        'name_desc' => 'ORDER BY p.name COLLATE NOCASE DESC',
        'modified_desc' => 'ORDER BY COALESCE((SELECT MAX(added_at) FROM playlist_songs WHERE playlist_id = p.id), p.created_at) DESC',
        'modified_asc' => 'ORDER BY COALESCE((SELECT MAX(added_at) FROM playlist_songs WHERE playlist_id = p.id), p.created_at) ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['name_asc'];
      
      $is_added_sql = ", 0 as is_added";
      if ($song_id !== '0') {
        $sid = intval($song_id);
        $is_added_sql = ", (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM playlist_songs WHERE playlist_id = p.id AND song_id = {$sid}) as is_added";
      }
      
      $stmt = $db->prepare("
        SELECT p.id, p.name, p.public_id, COUNT(ps.song_id) as song_count,
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
        {$is_added_sql}
        FROM playlists p LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id
        WHERE p.user_id = ?
        GROUP BY p.id, p.name, p.public_id
        {$order_by} {$limit_clause}
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
      $song_ids = is_array($data['song_id']) ? $data['song_id'] : [$data['song_id']];
      
      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
      $stmt_owner->execute([$playlist_id, $user_id]);
      if (!$stmt_owner->fetch()) {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Not your playlist.']);
      }

      $stmt_order = $db->prepare("SELECT MAX(sort_order) as max_order FROM playlist_songs WHERE playlist_id = ?");
      $stmt_order->execute([$playlist_id]);
      $max_order = $stmt_order->fetchColumn() ?? 0;

      $stmt_insert = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");
      foreach ($song_ids as $sid) {
        $max_order++;
        $stmt_insert->execute([$playlist_id, intval($sid), $max_order]);
      }
      send_json(['status' => 'success', 'message' => 'Added to playlist.']);
      break;

    case 'add_mix_to_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $playlist_id = intval($data['playlist_id']);
      $mix_public_id = $data['mix_id'];
      
      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
      $stmt_owner->execute([$playlist_id, $user_id]);
      if (!$stmt_owner->fetch()) { http_response_code(403); send_json(['status' => 'error', 'message' => 'Not your playlist.']); }

      $stmt_mix = $db->prepare("SELECT id FROM mixes WHERE public_id = ?");
      $stmt_mix->execute([$mix_public_id]);
      $mix_id = $stmt_mix->fetchColumn();
      if (!$mix_id) { send_json(['status' => 'error', 'message' => 'Mix not found.']); }

      $stmt_songs = $db->prepare("SELECT song_id FROM mix_songs WHERE mix_id = ? ORDER BY sort_order ASC");
      $stmt_songs->execute([$mix_id]);
      $songs = $stmt_songs->fetchAll(PDO::FETCH_COLUMN);

      $stmt_order = $db->prepare("SELECT MAX(sort_order) as max_order FROM playlist_songs WHERE playlist_id = ?");
      $stmt_order->execute([$playlist_id]);
      $max_order = $stmt_order->fetchColumn() ?? 0;

      $stmt_insert = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");
      foreach($songs as $sid) {
        $max_order++;
        $stmt_insert->execute([$playlist_id, $sid, $max_order]);
      }
      send_json(['status' => 'success', 'message' => 'Added all songs to playlist.']);
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
      session_write_close();
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id'] ?? 0);
      $played_at_iso = $data['played_at'] ?? (new DateTime())->format(DateTime::ATOM);
    
      if ($song_id > 0) {
        try {
          $db->beginTransaction();
    
          $db->prepare("DELETE FROM history WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
          $db->prepare("INSERT INTO history (user_id, song_id, played_at) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $played_at_iso]);
    
          $db->prepare("
            INSERT INTO play_counts (user_id, song_id, play_count, last_played) VALUES (?, ?, 1, ?)
            ON CONFLICT(user_id, song_id) DO UPDATE SET
              play_count = play_count + 1,
              last_played = ?
          ")->execute([$user_id, $song_id, $played_at_iso, $played_at_iso]);
          
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
      $album_fields = "m.album, m.artist, m.user_id, MAX(m.id) as id";

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
          JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
          WHERE match_artist(m.artist, :artist_name) = 1 AND m.album != 'Unknown Album'
          AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
          GROUP BY m.album, m.user_id LIMIT 10
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
          JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.genre IN ({$genre_placeholders}) AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = ?)
          LIMIT 15
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
        JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
        WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
        LIMIT 15
      ");
      $discovery_songs_stmt->execute([':user_id' => $user_id]);
      $discovery_songs = $discovery_songs_stmt->fetchAll();
      if (count($discovery_songs) > 0) {
        $shelves[] = ['title' => 'Discover New Songs', 'type' => 'songs', 'items' => $discovery_songs];
      }
      
      $discovery_stmt = $db->prepare("
        SELECT {$album_fields} FROM music m
        JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
        WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id) AND m.album != 'Unknown Album'
        GROUP BY m.album, m.user_id LIMIT 10
      ");
      $discovery_stmt->execute([':user_id' => $user_id]);
      $discovery_albums = $discovery_stmt->fetchAll();
      if (count($discovery_albums) > 0) {
        $shelves[] = ['title' => 'Discover New Albums', 'type' => 'albums', 'items' => $discovery_albums];
      }

      $rec_artists_stmt = $db->prepare("
        SELECT m.artist AS name, MAX(m.id) AS id
        FROM music m
        JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 100) r ON m.id = r.id
        WHERE m.artist != 'Unknown Artist' AND m.artist != '' AND m.artist IS NOT NULL
        AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
        GROUP BY m.artist
        LIMIT 10
      ");
      $rec_artists_stmt->execute([':user_id' => $user_id]);
      $rec_artists_rows = $rec_artists_stmt->fetchAll();
      $rec_artists = [];
      $stmt_is_user = $db->prepare("SELECT id FROM users WHERE artist = ? COLLATE NOCASE");
      foreach ($rec_artists_rows as $da) {
        $stmt_is_user->execute([$da['name']]);
        $uid = $stmt_is_user->fetchColumn();
        $rec_artists[] = [
          'name' => $da['name'],
          'id' => $uid ? $uid : $da['id'],
          'is_user' => (bool)$uid
        ];
      }
      if (count($rec_artists) > 0) {
        $shelves[] = ['title' => 'Discover Artists', 'type' => 'artists', 'items' => $rec_artists];
      }

      $freq_played_stmt = $db->prepare("
        SELECT {$song_fields}
        FROM play_counts pc JOIN music m ON pc.song_id = m.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
        WHERE pc.user_id = :user_id
        ORDER BY pc.play_count DESC LIMIT 15
      ");
      $freq_played_stmt->execute([':user_id' => $user_id]);
      $freq_played_songs = $freq_played_stmt->fetchAll();
      if (count($freq_played_songs) > 0) {
        $shelves[] = ['title' => 'Frequently Played', 'type' => 'songs', 'items' => $freq_played_songs];
      }

      $mix_seeds_stmt = $db->query("
        SELECT * FROM (
          SELECT DISTINCT artist as seed_name, 'artist' as seed_type FROM music WHERE artist != 'Unknown Artist' AND artist != '' AND artist IS NOT NULL
          UNION
          SELECT DISTINCT genre as seed_name, 'genre' as seed_type FROM music WHERE genre != 'Unknown Genre' AND genre != '' AND genre IS NOT NULL
        ) ORDER BY RANDOM() LIMIT 15
      ");
      $seeds = $mix_seeds_stmt->fetchAll();
      $mixes = [];
      foreach ($seeds as $seed) {
        $song_stmt = $db->prepare("SELECT id FROM music WHERE " . $seed['seed_type'] . " = ? ORDER BY RANDOM() LIMIT 60");
        $song_stmt->execute([$seed['seed_name']]);
        $song_ids = $song_stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($song_ids) < 50) {
          $needed = 50 - count($song_ids);
          $pad_stmt = $db->query("SELECT id FROM music ORDER BY RANDOM() LIMIT 100");
          $pad_candidates = $pad_stmt->fetchAll(PDO::FETCH_COLUMN);
          foreach ($pad_candidates as $cid) {
            if (!in_array($cid, $song_ids)) {
              $song_ids[] = $cid;
              $needed--;
              if ($needed <= 0) break;
            }
          }
        }
        if (empty($song_ids)) continue;
        $img_stmt = $db->prepare("SELECT id FROM music WHERE " . $seed['seed_type'] . " = ? ORDER BY RANDOM() LIMIT 1");
        $img_stmt->execute([$seed['seed_name']]);
        $img_id = $img_stmt->fetchColumn() ?: $song_ids[0];
        $public_id = bin2hex(random_bytes(8));
        $name = 'Mix - ' . $seed['seed_name'];
        $db->prepare("INSERT INTO mixes (public_id, name, creator, image_id) VALUES (?, ?, ?, ?)")->execute([$public_id, $name, 'PHP-Music', $img_id]);
        $mix_id = $db->lastInsertId();
        $order = 0;
        $insert_ms = $db->prepare("INSERT INTO mix_songs (mix_id, song_id, sort_order) VALUES (?, ?, ?)");
        foreach ($song_ids as $sid) {
          $insert_ms->execute([$mix_id, $sid, $order++]);
        }
        $mixes[] = [
          'public_id' => $public_id,
          'name' => $name,
          'creator' => 'PHP-Music',
          'image_id' => $img_id
        ];
      }
      if (!empty($mixes)) {
        $shelves[] = ['title' => 'Your Mixes', 'type' => 'mixes', 'items' => $mixes];
      }

      $latest_followed_songs_stmt = $db->prepare("
        SELECT {$song_fields} FROM music m
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = :user_id
        WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)
        ORDER BY m.id DESC LIMIT 15
      ");
      $latest_followed_songs_stmt->execute([':user_id' => $user_id]);
      $latest_followed_songs = $latest_followed_songs_stmt->fetchAll();
      if (count($latest_followed_songs) > 0) {
        $shelves[] = ['title' => 'From Your Artists: New Tracks', 'type' => 'songs', 'items' => $latest_followed_songs];
      }

      $latest_followed_albums_stmt = $db->prepare("
        SELECT {$album_fields} FROM music m
        WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id) AND m.album != 'Unknown Album'
        GROUP BY m.album, m.user_id ORDER BY MAX(m.id) DESC LIMIT 10
      ");
      $latest_followed_albums_stmt->execute([':user_id' => $user_id]);
      $latest_followed_albums = $latest_followed_albums_stmt->fetchAll();
      if (count($latest_followed_albums) > 0) {
        $shelves[] = ['title' => 'From Your Artists: New Albums', 'type' => 'albums', 'items' => $latest_followed_albums];
      }

      send_json(['shelves' => $shelves]);
      break;

    case 'export_favorites':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("
        SELECT m.file, m.title, m.artist, m.album FROM favorites f
        JOIN music m ON f.song_id = m.id 
        WHERE f.user_id = ?
        ORDER BY f.sort_order ASC
      ");
      $stmt->execute([$user_id]);
      $rows = $stmt->fetchAll();
      if (empty($rows)) { http_response_code(404); exit; }

      $song_data = array_map(function($row) {
        return [
          'title' => $row['title'],
          'artist' => $row['artist'],
          'album' => $row['album'],
          'filename' => basename(str_replace('\\', '/', $row['file']))
        ];
      }, $rows);

      $export_data = [
        'name' => 'Favorites',
        'songs' => $song_data
      ];
      
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="favorites.json"');
      echo json_encode($export_data, JSON_PRETTY_PRINT);
      exit;

    case 'import_favorites':
      if (!$user_id) { http_response_code(403); exit; }
      $import_data = json_decode(file_get_contents('php://input'), true);
      if (json_last_error() !== JSON_ERROR_NONE || !isset($import_data['songs']) || !is_array($import_data['songs'])) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid or malformed JSON payload.']);
      }

      $db->beginTransaction();
      try {
        $stmt_taa = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND artist = ? COLLATE NOCASE AND album = ? COLLATE NOCASE LIMIT 1");
        $stmt_ta = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND artist = ? COLLATE NOCASE LIMIT 1");
        $stmt_t_artist_match = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND match_artist(artist, ?) = 1 LIMIT 1");
        $stmt_ta_like = $db->prepare("SELECT id FROM music WHERE title LIKE ? COLLATE NOCASE AND artist LIKE ? COLLATE NOCASE LIMIT 1");
        $stmt_file = $db->prepare("SELECT id FROM music WHERE file LIKE ? OR file LIKE ? LIMIT 1");
        $stmt_insert = $db->prepare("INSERT OR IGNORE INTO favorites (user_id, song_id, sort_order) VALUES (?, ?, ?)");
        
        $stmt_order = $db->prepare("SELECT MAX(sort_order) FROM favorites WHERE user_id = ?");
        $stmt_order->execute([$user_id]);
        $order = (int)$stmt_order->fetchColumn();

        $song_count = 0;
        foreach ($import_data['songs'] as $song) {
          $title = is_array($song) ? trim($song['title'] ?? '') : '';
          $artist = is_array($song) ? trim($song['artist'] ?? '') : '';
          $album = is_array($song) ? trim($song['album'] ?? '') : '';
          $filename = basename(str_replace('\\', '/', is_array($song) ? ($song['filename'] ?? '') : $song));
          
          $found_id = null;
          
          if ($title !== '' && $artist !== '') {
            if ($album !== '') {
              $stmt_taa->execute([$title, $artist, $album]);
              $found_id = $stmt_taa->fetchColumn();
            }
            if (!$found_id) {
              $stmt_ta->execute([$title, $artist]);
              $found_id = $stmt_ta->fetchColumn();
            }
            if (!$found_id) {
              $stmt_t_artist_match->execute([$title, $artist]);
              $found_id = $stmt_t_artist_match->fetchColumn();
            }
            if (!$found_id) {
              $stmt_ta_like->execute(['%' . $title . '%', '%' . $artist . '%']);
              $found_id = $stmt_ta_like->fetchColumn();
            }
          }
          
          if (!$found_id && $filename !== '') {
            $stmt_file->execute(['%/' . $filename, '%\\' . $filename]);
            $found_id = $stmt_file->fetchColumn();
            if (!$found_id) {
              $stmt_file->execute(['%' . $filename, '%' . $filename]);
              $found_id = $stmt_file->fetchColumn();
            }
          }
          
          if ($found_id) {
            $order++;
            $stmt_insert->execute([$user_id, $found_id, $order]);
            if ($stmt_insert->rowCount() > 0) {
              $song_count++;
            }
          }
        }
        $db->commit();
        send_json(['status' => 'success', 'message' => "Favorites imported with {$song_count} songs."]);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'Database error during import: ' . $e->getMessage()]);
      }
      break;
      
    case 'export_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $public_id = $_GET['id'] ?? '';
      if (empty($public_id)) { http_response_code(400); exit; }

      $stmt = $db->prepare("
        SELECT p.name, m.file, m.title, m.artist, m.album FROM playlists p 
        JOIN playlist_songs ps ON p.id = ps.playlist_id 
        JOIN music m ON ps.song_id = m.id 
        WHERE p.public_id = ? AND p.user_id = ?
        ORDER BY ps.sort_order ASC
      ");
      $stmt->execute([$public_id, $user_id]);
      $rows = $stmt->fetchAll();

      if (empty($rows)) { http_response_code(404); exit; }

      $playlist_name = $rows[0]['name'];
      $song_data = array_map(function($row) {
        return [
          'title' => $row['title'],
          'artist' => $row['artist'],
          'album' => $row['album'],
          'filename' => basename(str_replace('\\', '/', $row['file']))
        ];
      }, $rows);

      $export_data = [
        'name' => $playlist_name,
        'songs' => $song_data
      ];
      
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="' . sanitize_for_path($playlist_name) . '.json"');
      echo json_encode($export_data, JSON_PRETTY_PRINT);
      exit;

    case 'import_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $import_data = json_decode(file_get_contents('php://input'), true);
      if (json_last_error() !== JSON_ERROR_NONE || !isset($import_data['name']) || !isset($import_data['songs']) || !is_array($import_data['songs'])) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid or malformed JSON file.']);
      }

      $db->beginTransaction();
      try {
        $public_id = bin2hex(random_bytes(8));
        $stmt_create = $db->prepare("INSERT INTO playlists (user_id, name, public_id) VALUES (?, ?, ?)");
        $stmt_create->execute([$user_id, $import_data['name'], $public_id]);
        $playlist_id = $db->lastInsertId();

        $stmt_taa = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND artist = ? COLLATE NOCASE AND album = ? COLLATE NOCASE LIMIT 1");
        $stmt_ta = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND artist = ? COLLATE NOCASE LIMIT 1");
        $stmt_t_artist_match = $db->prepare("SELECT id FROM music WHERE title = ? COLLATE NOCASE AND match_artist(artist, ?) = 1 LIMIT 1");
        $stmt_ta_like = $db->prepare("SELECT id FROM music WHERE title LIKE ? COLLATE NOCASE AND artist LIKE ? COLLATE NOCASE LIMIT 1");
        $stmt_file = $db->prepare("SELECT id FROM music WHERE file LIKE ? OR file LIKE ? LIMIT 1");
        $stmt_insert = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");
        
        $song_count = 0;
        $order = 0;
        foreach ($import_data['songs'] as $song) {
          $title = is_array($song) ? trim($song['title'] ?? '') : '';
          $artist = is_array($song) ? trim($song['artist'] ?? '') : '';
          $album = is_array($song) ? trim($song['album'] ?? '') : '';
          $filename = basename(str_replace('\\', '/', is_array($song) ? ($song['filename'] ?? '') : $song));
          
          $found_id = null;
          
          if ($title !== '' && $artist !== '') {
            if ($album !== '') {
              $stmt_taa->execute([$title, $artist, $album]);
              $found_id = $stmt_taa->fetchColumn();
            }
            if (!$found_id) {
              $stmt_ta->execute([$title, $artist]);
              $found_id = $stmt_ta->fetchColumn();
            }
            if (!$found_id) {
              $stmt_t_artist_match->execute([$title, $artist]);
              $found_id = $stmt_t_artist_match->fetchColumn();
            }
            if (!$found_id) {
              $stmt_ta_like->execute(['%' . $title . '%', '%' . $artist . '%']);
              $found_id = $stmt_ta_like->fetchColumn();
            }
          }
          
          if (!$found_id && $filename !== '') {
            $stmt_file->execute(['%/' . $filename, '%\\' . $filename]);
            $found_id = $stmt_file->fetchColumn();
            if (!$found_id) {
              $stmt_file->execute(['%' . $filename, '%' . $filename]);
              $found_id = $stmt_file->fetchColumn();
            }
          }
          
          if ($found_id) {
            $stmt_insert->execute([$playlist_id, $found_id, $order]);
            if ($stmt_insert->rowCount() > 0) {
              $order++;
              $song_count++;
            }
          }
        }
        $db->commit();
        send_json(['status' => 'success', 'message' => "Playlist '{$import_data['name']}' imported with {$song_count} songs."]);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'Database error during import: ' . $e->getMessage()]);
      }
      break;

    case 'copy_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $public_id = $_POST['public_id'] ?? '';
      if (empty($public_id)) { http_response_code(400); exit; }

      $stmt_source = $db->prepare("SELECT id, name, user_id FROM playlists WHERE public_id = ?");
      $stmt_source->execute([$public_id]);
      $source_playlist = $stmt_source->fetch();

      if (!$source_playlist) { http_response_code(404); send_json(['status' => 'error', 'message' => 'Playlist not found.']); }
      if ($source_playlist['user_id'] == $user_id) { http_response_code(400); send_json(['status' => 'error', 'message' => 'Cannot copy your own playlist.']); }

      $db->beginTransaction();
      try {
        $new_name = "Copy of " . $source_playlist['name'];
        $new_public_id = bin2hex(random_bytes(8));
        $stmt_create = $db->prepare("INSERT INTO playlists (user_id, name, public_id) VALUES (?, ?, ?)");
        $stmt_create->execute([$user_id, $new_name, $new_public_id]);
        $new_playlist_id = $db->lastInsertId();

        $stmt_get_songs = $db->prepare("SELECT song_id, sort_order FROM playlist_songs WHERE playlist_id = ? ORDER BY sort_order ASC");
        $stmt_get_songs->execute([$source_playlist['id']]);
        $songs = $stmt_get_songs->fetchAll();

        $stmt_insert_song = $db->prepare("INSERT INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");
        foreach ($songs as $song) {
          $stmt_insert_song->execute([$new_playlist_id, $song['song_id'], $song['sort_order']]);
        }
        $db->commit();
        send_json(['status' => 'success', 'message' => "Playlist copied successfully."]);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        send_json(['status' => 'error', 'message' => 'Database error during copy: ' . $e->getMessage()]);
      }
      break;
  }
  exit;
}

function perform_full_scan($db) {
  $start_time = microtime(true);
  ini_set('memory_limit', '512M');
  error_reporting(E_ALL & ~E_DEPRECATED);
  ini_set('display_errors', 1);

  header('Content-Type: text/html; charset=utf-8');
  ob_implicit_flush();

  echo "<style>
    body { 
      color: #e0e0e0; 
      background-color: #030303; 
      font-family: Consolas, 'Courier New', monospace; 
      padding: 10px;
      margin: 0;
    }
    pre { 
      white-space: pre-wrap; 
      word-wrap: break-word; 
      font-family: inherit;
      margin-top: 75px;
    }
    #header-wrap { position: fixed; top: 0; left: 0; right: 0; background: #121212; border-bottom: 1px solid #333; z-index: 10; }
    #prog-wrap { padding: 12px 15px 5px 15px; display: flex; align-items: center; }
    #prog-track { flex-grow: 1; background: #000; height: 12px; border-radius: 6px; overflow: hidden; margin-right: 15px; }
    #prog-bar { width: 0%; height: 100%; background: #ff0000; transition: width 0.1s; }
    #prog-txt { font-weight: bold; min-width: 45px; text-align: right; font-size: 14px;}
    #warning-txt { padding: 0 15px 12px 15px; color: #ffc107; font-size: 12px; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }
  </style>";
  
  echo "<div id='header-wrap'>
          <div id='prog-wrap'><div id='prog-track'><div id='prog-bar'></div></div><div id='prog-txt'>0%</div></div>
          <div id='warning-txt'>⚠️ Please wait and don't close this modal, the process can take very long!</div>
        </div>";
  echo "<pre>";
  echo "PHP Music Library - Full Scan\n";
  echo "===================================\n\n";

  if (!class_exists('getID3')) {
    die("FATAL ERROR: getID3 library not found in " . __DIR__ . "/getid3/\n");
  }

  echo "Step 1: Database ready.\n\n";
  
  init_db($db);

  echo "Step 2: Verifying 'Music Library' user...\n";
  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  $library_user_id = $stmt->fetchColumn();
  if (!$library_user_id) {
    die("FATAL ERROR: 'Music Library' user could not be found or created.\n");
  }
  echo "'Music Library' user ID: {$library_user_id}\n\n";

  echo "Step 3: Fetching existing music records from database...\n";
  $stmt = $db->query("SELECT file, last_modified FROM music");
  $db_files = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  echo "Found " . count($db_files) . " records in the database.\n\n";

  echo "Step 4: Scanning music directory for files...\n";
  $files_on_disk = [];
  
  $music_folders = [MUSIC_DIR]; 

  foreach ($music_folders as $folder) {
    if (!is_dir($folder)) {
      echo "Warning: Directory not found, skipping: {$folder}\n";
      continue;
    }
    echo "Scanning directory: {$folder}\n";
    $directory = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue;
      }
      $filePath = $file->getRealPath();
      if (preg_match('/\.(mp3|m4a|flac|ogg|wav)$/i', $filePath)) {
        $files_on_disk[$filePath] = $file->getMTime();
      }
    }
  }
  
  echo "Found " . count($files_on_disk) . " music files on disk.\n\n";

  echo "Step 5: Comparing disk files with database records...\n";
  $files_to_add = array_diff_key($files_on_disk, $db_files);
  $files_to_delete = array_diff_key($db_files, $files_on_disk);
  $files_to_update = [];

  foreach (array_intersect_key($files_on_disk, $db_files) as $filePath => $mtime) {
    if ($mtime > $db_files[$filePath]) {
      $files_to_update[$filePath] = $mtime;
    }
  }

  echo " - To add: " . count($files_to_add) . "\n";
  echo " - To update: " . count($files_to_update) . "\n";
  echo " - To delete: " . count($files_to_delete) . "\n\n";

  $files_to_process = $files_to_add + $files_to_update;
  if (empty($files_to_process) && empty($files_to_delete)) {
    die("Scan complete. No changes detected.\n</pre>");
  }

  echo "Step 6: Processing changes...\n";
  
  $getID3 = new getID3;
  $getID3->option_md5_data = false;
  $getID3->option_md5_data_source = false;

  $insert_stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, bitrate, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $update_stmt = $db->prepare("UPDATE music SET title = ?, artist = ?, album = ?, genre = ?, year = ?, duration = ?, bitrate = ?, image = COALESCE(?, image), last_modified = ? WHERE file = ?");
  $delete_stmt = $db->prepare("DELETE FROM music WHERE file = ?");
  
  $find_user_stmt = $db->prepare("SELECT id FROM users WHERE artist = ?");
  $check_email_stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
  $insert_user_stmt = $db->prepare("INSERT INTO users (email, artist, password_hash, verified) VALUES (?, ?, ?, 'no')");

  $processed_count = 0;
  $total_to_process = count($files_to_process) + count($files_to_delete);

  $db->beginTransaction();
  try {
    foreach ($files_to_process as $filePath => $mtime) {
      if ((microtime(true) - $start_time) > 45) {
        $db->commit();
        echo "\nTime limit approaching. Pausing to prevent timeout...\n";
        echo "Auto-resuming in 1 second...\n";
        echo "<script>setTimeout(() => window.location.reload(), 1000);</script></pre>";
        exit;
      }

      $processed_count++;
      $percent = floor(($processed_count / $total_to_process) * 100);
      echo sprintf("[%3d%%] [%d/%d] Processing: %s\n", $percent, $processed_count, $total_to_process, basename($filePath));
      
      if (!isset($last_percent) || $percent > $last_percent) {
        echo "<script>document.getElementById('prog-bar').style.width = '{$percent}%'; document.getElementById('prog-txt').innerText = '{$percent}%'; window.scrollTo(0, document.body.scrollHeight);</script>";
        $last_percent = $percent;
      }
      
      try {
        $info = $getID3->analyze($filePath);
        getid3_lib::CopyTagsToComments($info);
        
        $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
        $artist_tag = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
        $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
        $genre = trim($info['comments']['genre'][0] ?? 'Unknown Genre');
        $year = (int)($info['comments']['year'][0] ?? 0);
        $duration = (int)($info['playtime_seconds'] ?? 0);
        $bitrate = (int)($info['audio']['bitrate'] ?? 0);
        $raw_image_data = $info['comments']['picture'][0]['data'] ?? null;
        $webp_image_data = process_image_to_webp($raw_image_data);
        
        $is_update = isset($db_files[$filePath]);

        if ($is_update) {
            $update_stmt->execute([$title, $artist_tag, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $mtime, $filePath]);
        } else {
            $file_user_id = $library_user_id;
            $main_artist = trim(preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist_tag)[0]);

            if ($main_artist !== 'Unknown Artist' && !empty($main_artist)) {
              $find_user_stmt->execute([$main_artist]);
              $found_user_id = $find_user_stmt->fetchColumn();
              if ($found_user_id) {
                $file_user_id = $found_user_id;
              } else {
                echo " -> New artist found: '{$main_artist}'. Creating account.\n";
                $sanitized_artist_base = sanitize_for_path($main_artist);
                $password = $sanitized_artist_base;
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                $email = $sanitized_artist_base . '@mail.com';
                $counter = 1;
                while (true) {
                  $check_email_stmt->execute([$email]);
                  if (!$check_email_stmt->fetch()) break;
                  $counter++;
                  $email = $sanitized_artist_base . $counter . '@mail.com';
                }
                
                $insert_user_stmt->execute([$email, $main_artist, $hash]);
                $file_user_id = $db->lastInsertId();
                echo " -> Account created with ID: {$file_user_id}, Email: {$email}\n";
              }
            }
            $insert_stmt->execute([$file_user_id, $filePath, $title, $artist_tag, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $mtime]);
        }

      } catch (Exception $file_exception) {
        echo " -> Error processing " . basename($filePath) . ": " . $file_exception->getMessage() . "\n";
      }

      unset($info);
      unset($webp_image_data);
    }

    foreach ($files_to_delete as $filePath => $mtime) {
      if ((microtime(true) - $start_time) > 45) {
        $db->commit();
        echo "\nTime limit approaching. Pausing to prevent timeout...\n";
        echo "Auto-resuming in 1 second...\n";
        echo "<script>setTimeout(() => window.location.reload(), 1000);</script></pre>";
        exit;
      }

      $processed_count++;
      $percent = floor(($processed_count / $total_to_process) * 100);
      echo sprintf("[%3d%%] [%d/%d] Deleting: %s\n", $percent, $processed_count, $total_to_process, basename($filePath));
      
      if (!isset($last_percent) || $percent > $last_percent) {
        echo "<script>document.getElementById('prog-bar').style.width = '{$percent}%'; document.getElementById('prog-txt').innerText = '{$percent}%'; window.scrollTo(0, document.body.scrollHeight);</script>";
        $last_percent = $percent;
      }
      $delete_stmt->execute([$filePath]);
    }
    
    $db->commit();
  } catch (Exception $e) {
    if ($db->inTransaction()) {
      $db->rollBack();
    }
    die("\nERROR: An exception occurred during database operations: " . $e->getMessage() . "\nProcess aborted.\n</pre>");
  }

  echo "\n=======================\n";
  echo "Scan completed successfully!\n";
  echo "Total files processed: $processed_count\n</pre>";
}
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="A simple, fast music player with user accounts and uploads.">
    <meta property="og:title" content="PHP Music">
    <meta property="og:description" content="A simple, fast music player with user accounts and uploads.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="?action=get_app_icon&size=512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PHP Music">
    <meta name="application-name" content="PHP Music">
    <title>PHP Music</title>
    <link rel="icon" type="image/svg+xml" href="?action=get_app_icon" />
    <meta name="theme-color" content="#121212"/>
    <link rel="manifest" href="?pwa=manifest" crossorigin="use-credentials">
    <script>
      (async function() {
        const appVersion = '<?php echo APP_VERSION; ?>';
        try {
          const root = await navigator.storage.getDirectory();
          let storedVersion = null;
          try {
            const fileHandle = await root.getFileHandle('appVersion.txt');
            const file = await fileHandle.getFile();
            storedVersion = await file.text();
          } catch (e) {}
          if (storedVersion !== appVersion) {
            const fileHandle = await root.getFileHandle('appVersion.txt', { create: true });
            const writable = await fileHandle.createWritable();
            await writable.write(appVersion);
            await writable.close();
            if ('serviceWorker' in navigator) {
              const registrations = await navigator.serviceWorker.getRegistrations();
              for (let registration of registrations) {
                registration.unregister();
              }
            }
            if ('caches' in window) {
              const names = await caches.keys();
              for (let name of names) {
                caches.delete(name);
              }
            }
          }
        } catch (e) {}
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
      html, body { height: 100%; }
      body {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        font-family: 'Roboto', sans-serif;
        margin: 0;
      }
      body.player-visible { padding-bottom: 90px; }
      ::-webkit-scrollbar { width: 8px; }
      ::-webkit-scrollbar-track { background: var(--ytm-surface); }
      ::-webkit-scrollbar-thumb { background: var(--ytm-surface-2); border-radius: 4px; }
      ::-webkit-scrollbar-thumb:hover { background: #555; }
      .nav-tabs { border-bottom-color: var(--ytm-surface-2); }
      .nav-tabs .nav-link { color: var(--ytm-secondary-text); border: none; border-bottom: 2px solid transparent; padding: 0.75rem 1.5rem; font-weight: 500; cursor: pointer; background: transparent; }
      .nav-tabs .nav-link:hover { border-color: transparent; color: var(--ytm-primary-text); }
      .nav-tabs .nav-link.active { background-color: transparent; color: var(--ytm-primary-text); border-color: transparent; border-bottom-color: var(--ytm-accent); }
      .app-container { display: flex; height: 100%; }
      .sidebar {
        width: 240px; background-color: var(--ytm-bg);
        display: flex; flex-direction: column; flex-shrink: 0;
      }
      .main-content {
        flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto;
      }
      .content-area-wrapper { padding: 1.5rem 2rem; flex-grow: 1; }
      .view-details-header {
        display: flex; align-items: flex-end; gap: 1.5rem; margin-bottom: 2rem;
        padding: 1rem; background-color: var(--ytm-surface); border-radius: 8px;
      }
      .view-details-header-info { min-width: 0; flex-grow: 1; }
      .view-details-header img, .profile-picture-lg {
        width: 150px; height: 150px; object-fit: cover; border-radius: 6px;
        flex-shrink: 0; background-color: var(--ytm-surface-2);
      }
      .view-details-header-info .type {
        font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: var(--ytm-secondary-text);
      }
      .view-details-header-info .name { font-size: 2.5rem; font-weight: 700; margin: 0.5rem 0; }
      .view-details-header-info .stats { font-size: 0.9rem; color: var(--ytm-secondary-text); }
      .view-details-header .share-view-btn { flex-shrink: 0; align-self: flex-start; }
      @media (min-width: 768px) {
        .sidebar { padding: 1.5rem 0; overflow-y: auto; }
        .sidebar .offcanvas-header { display: none; }
        .sidebar .offcanvas-body { padding: 0 !important; }
        .player-bar { background-color: var(--ytm-surface); left: 240px; }
        .page-header {
          position: sticky; top: 0; background-color: var(--ytm-bg);
          z-index: 1010; padding-top: 1.5rem; padding-bottom: 1.5rem;
        }
        .song-item.history-item { grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) 80px 40px; }
      }
      .song-item { cursor: pointer; border-radius: 0.5em; -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
      .offcanvas-body .nav-link { padding: 0.75rem 1.5rem; }
      .sidebar .logo { font-size: 1.5rem; font-weight: 700; padding: 0 1.5rem 1.5rem 1.5rem; }
      .sidebar .logo span { color: var(--ytm-accent); }
      .nav-link {
        color: var(--ytm-secondary-text); display: flex; align-items: center;
        font-weight: 500; border-left: 3px solid transparent; gap: 1rem; text-decoration: none;
      }
      .nav-link:hover, .nav-link.active { background-color: var(--ytm-surface); color: var(--ytm-primary-text); }
      .nav-link.active { border-left-color: var(--ytm-accent); }
      .nav-link .bi { font-size: 1.25rem; width: 24px; text-align: center; }
      .offcanvas { background-color: var(--ytm-bg); color: var(--ytm-primary-text); z-index: 999; }
      .offcanvas .offcanvas-header { padding: 0.75rem 1.5rem; }
      .page-header { padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
      .header-controls { display: flex; gap: 1rem; align-items: center; margin-left: auto; }
      #sort-controls { display: flex; align-items: center; gap: 0.5rem; }
      #sort-select {
        background-color: var(--ytm-surface-2); color: var(--ytm-primary-text);
        border: 1px solid #404040; border-radius: 4px; padding: 0.25rem 0.5rem;
      }
      .search-bar.input-group { width: auto; min-width: 250px; }
      .search-bar.input-group .form-control {
        background-color: var(--ytm-surface-2); border: 1px solid #404040;
        border-right: none; color: var(--ytm-primary-text); border-radius: 50px 0 0 50px !important;
        height: 40px; box-shadow: none; padding-left: 1.25rem;
      }
      .search-bar.input-group .form-control:focus { border-color: #666; }
      .search-bar.input-group .form-control::placeholder { color: var(--ytm-secondary-text); }
      .search-bar.input-group .btn {
        background-color: var(--ytm-surface-2); border: 1px solid #404040;
        color: var(--ytm-secondary-text); border-radius: 0 50px 50px 0 !important; z-index: 5;
        padding-right: 1.25rem;
      }
      .search-bar.input-group .btn:hover { background-color: #383838; color: var(--ytm-primary-text); }
      .content-title { font-size: 2rem; font-weight: 700; margin-bottom: 0; }
      .song-list-header, .song-item {
        display: grid; grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) 80px 40px;
        align-items: center; gap: 1rem; padding: 0.5rem 1rem; font-size: 0.9rem; color: var(--ytm-secondary-text);
      }
      .song-list-header { font-weight: 500; }
      .song-item.ghost { opacity: 0.4; }
      .song-item:hover { background-color: var(--ytm-surface-2); }
      .song-item.multi-selected { background-color: rgba(255, 0, 0, 0.2) !important; }
      .song-item .song-title, .song-item .song-artist, .song-item .song-album,
      .song-artist-name, .view-details-header-info .name {
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .song-item .song-title-wrapper { grid-column: 2; grid-row: 1; min-width: 0; }
      .song-item .song-title { color: var(--ytm-primary-text); font-weight: 500; }
      .song-item .song-thumb {
        width: 40px; height: 40px; object-fit: cover; border-radius: 4px; background-color: var(--ytm-surface);
      }
      .song-item .song-more { justify-self: end; position: relative; }
      .song-item .more-btn, .playlist-more-btn {
        background: none; border: none; color: var(--ytm-secondary-text); padding: 5px; cursor: pointer; border-radius: 50%;
      }
      .song-item:hover .more-btn, .playlist-more-btn:hover { color: var(--ytm-primary-text); }
      .card.playlist-card { position: relative; }
      .playlist-more-btn {
        position: absolute; top: 0.5rem; right: 0.5rem; background: transparent !important;
        border: none; padding: 0.25rem; font-size: 1.25rem; line-height: 1; cursor: pointer;
      }
      .context-menu {
        display: none; position: fixed; background-color: var(--ytm-surface-2); border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5); z-index: 1080; list-style: none; padding: 0.5rem 0;
        min-width: 220px; max-height: 50vh; overflow-y: auto;
      }
      .context-menu-item {
        padding: 0.75rem 1.25rem; color: var(--ytm-primary-text); cursor: pointer; display: flex; align-items: center; gap: 0.75rem;
      }
      .context-menu-item:hover { background-color: #404040; }
      .context-menu-item .bi { font-size: 1.1rem; }
      .player-bar {
        position: fixed; bottom: 0; left: 0; right: 0; height: 90px; background-color: var(--ytm-bg);
        border-top: 1px solid var(--ytm-surface-2); display: grid;
        grid-template-columns: minmax(200px, 1fr) 2fr minmax(200px, 1fr); align-items: center; gap: 1.5rem; padding: 0 1.5rem; z-index: 998;
      }
      .player-bar .track-info { display: flex; align-items: center; gap: 1rem; min-width: 0; }
      .player-bar .track-info-art { width: 56px; height: 56px; object-fit: cover; border-radius: 4px; flex-shrink: 0; }
      .player-bar .track-info-text { overflow: hidden; }
      .player-bar .track-info-text .title, .player-bar .track-info-text .artist { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
      .player-bar .track-info-text .title { font-weight: 500; }
      .player-bar .track-info-text .artist { color: var(--ytm-secondary-text); font-size: 0.875rem; }
      .player-bar .track-info-text .artist:hover { text-decoration: underline; }
      .player-bar .player-controls { display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 0; }
      .player-bar .player-buttons { display: flex; align-items: center; justify-content: center; gap: 1.5rem; width: 100%; }
      .player-btn {
        background: none; border: none; color: var(--ytm-secondary-text); padding: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; transition: color 0.2s;
      }
      .player-btn:hover { color: var(--ytm-primary-text); }
      .player-btn.play-btn {
        color: var(--ytm-primary-text); background-color: var(--ytm-surface); width: 40px; height: 40px;
        border-radius: 50%; transition: transform 0.1s, background-color 0.2s;
      }
      .player-btn.play-btn:hover { transform: scale(1.1); background-color: #383838; }
      .player-btn .bi { font-size: 1.25rem; }
      .player-btn.play-btn .bi { font-size: 1.75rem; }
      .player-btn.active { color: var(--ytm-accent); }
      .player-bar .playback-bar { width: 100%; display: flex; align-items: center; gap: 0.75rem; margin-top: 8px; }
      .playback-bar .time { font-size: 0.75rem; color: var(--ytm-secondary-text); flex-shrink: 0; }
      .progress-bar-container { flex-grow: 1; height: 4px; border-radius: 2px; cursor: pointer; padding: 5px 0; position: relative; margin-bottom: 0.2em; }
      .progress-bar-bg { height: 4px; background-color: #404040; border-radius: 2px; position: absolute; top: 5px; left: 0; right: 0; pointer-events: none; }
      .progress-bar-fg { height: 4px; background-color: var(--ytm-primary-text); border-radius: 2px; width: 0%; position: relative; }
      .progress-bar-container:hover .progress-bar-fg { background-color: var(--ytm-accent); }
      .progress-bar-container:hover .progress-bar-fg::after {
        content: ''; position: absolute; right: -6px; top: -4px; width: 12px; height: 12px;
        border-radius: 50%; background-color: var(--ytm-primary-text);
      }
      .player-bar .extra-controls { display: flex; justify-content: flex-end; align-items: center; gap: 1rem; }
      .volume-control { width: 150px; display: flex; align-items: center; }
      .volume-slider-container { flex-grow: 1; padding: 5px 0.5rem; position: relative; display: flex; align-items: center; }
      #volume-slider.form-range {
        -webkit-appearance: none; appearance: none; width: 100%; cursor: pointer; outline: none; padding: 0;
        height: 4px; border-radius: 2px; background: var(--ytm-surface-2);
      }
      #volume-slider.form-range::-webkit-slider-runnable-track { -webkit-appearance: none; background: none; border: none; height: 4px; }
      #volume-slider.form-range::-moz-range-track { background: none; border: none; height: 4px; }
      #volume-slider.form-range::-webkit-slider-thumb {
        -webkit-appearance: none; appearance: none; height: 12px; width: 12px; background-color: var(--ytm-primary-text);
        border-radius: 50%; margin-top: -4px; opacity: 0; transition: opacity 0.2s ease-in-out;
      }
      #volume-slider.form-range::-moz-range-thumb {
        height: 12px; width: 12px; background-color: var(--ytm-primary-text); border-radius: 50%; border: none;
        opacity: 0; transition: opacity 0.2s ease-in-out;
      }
      .volume-control:hover #volume-slider.form-range::-webkit-slider-thumb { opacity: 1; }
      .volume-control:hover #volume-slider.form-range::-moz-range-thumb { opacity: 1; }
      .volume-control:hover #volume-slider.form-range { --track-fill: var(--ytm-accent) !important; }
      .modal-content { background-color: var(--ytm-surface); border: none; border-radius: 1rem; }
      .modal-footer { border-top: 1px solid var(--ytm-surface-2); }
      .form-control, .form-select { background-color: var(--ytm-surface-2); border: 1px solid #404040; color: var(--ytm-primary-text); }
      .form-control:focus, .form-select:focus { background-color: var(--ytm-surface-2); border-color: #666; color: var(--ytm-primary-text); box-shadow: none; }
      .form-control::placeholder { color: var(--ytm-secondary-text); }
      .dropdown-menu-dark { background-color: var(--ytm-surface-2); }
      #upload-progress-area .progress { height: 10px; }
      body.logged-out .logged-in-only { display: none !important; }
      body.logged-in .logged-out-only { display: none !important; }
      .verified-user-only { display: none !important; }
      body.user-verified .verified-user-only { display: flex !important; }
      .text-truncate-width { max-width: 600px; }
      .song-item .playing-icon { display: none; font-size: 1.5rem; color: var(--ytm-accent); }
      .song-item.now-playing .song-thumb { display: none; }
      .song-item.now-playing .playing-icon { display: inline-block; animation: soundwave-pulse 1.2s ease-in-out infinite; }
      .song-item.now-playing .song-title { color: var(--ytm-accent); }
      .profile-picture, .profile-picture-sm { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; background-color: var(--ytm-surface-2); }
      .profile-picture-lg { border-radius: 50%; }
      .history-item .played-at-text { font-size: 0.8rem; color: var(--ytm-secondary-text); }
      .recommendation-shelf { margin-bottom: 2rem; }
      .shelf-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
      .shelf-title { font-size: 1.5rem; font-weight: 700; }
      .shelf-items {
        display: grid; grid-auto-flow: column; grid-auto-columns: minmax(130px, 1fr); overflow-x: auto;
        gap: 1rem; padding-bottom: 1rem; scrollbar-color: var(--ytm-surface-2) var(--ytm-surface); scrollbar-width: thin;
      }
      @media (min-width: 768px) {
        .shelf-items { grid-auto-columns: minmax(160px, 1fr); }
      }
      .shelf-items::-webkit-scrollbar { height: 8px; }
      .shelf-item { background-color: transparent; border: none; cursor: pointer; }
      .shelf-item img { width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 6px; margin-bottom: 0.5rem; background-color: var(--ytm-surface-2); }
      .shelf-item .item-title { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .shelf-item .item-subtitle { font-size: 0.875rem; color: var(--ytm-secondary-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .shelf-item .item-subtitle:hover { text-decoration: underline; }
      .user-profile-page, .user-stats-page { text-align: center; padding: 2rem; }
      .user-profile-page .profile-picture-lg { width: 200px; height: 200px; margin-bottom: 1.5rem; }
      .user-profile-page .display-name { font-size: 2.5rem; font-weight: 700; }
      .user-stats-page .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 2rem; margin-top: 2rem; }
      .user-stats-page .stat-item .stat-value { font-size: 2.5rem; font-weight: 700; }
      .user-stats-page .stat-item .stat-label { color: var(--ytm-secondary-text); text-transform: uppercase; font-size: 0.9rem; }
      @keyframes soundwave-pulse { 0% { transform: scaleY(0.4); } 25% { transform: scaleY(1); } 50% { transform: scaleY(0.6); } 75% { transform: scaleY(0.8); } 100% { transform: scaleY(0.4); } }
      #playlist-downloader-modal * { color: #ffffff !important; }
      .pd-song-row {
        display: grid;
        grid-template-columns: 50px 2fr 2fr 100px 60px;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      @media (max-width: 768px) {
        .pd-song-row { grid-template-columns: 40px 1fr 30px !important; gap: 0.5rem !important; }
        .pd-song-row .artist-col, .pd-song-row .duration-col { display: none !important; }
        .pd-song-row .title-col { grid-column: 2; }
        .pd-song-row .download-col { grid-column: 3; }
        .pd-mobile-artist { grid-column: 2; font-size: 0.75rem; }
      }
      @media (max-width: 767.98px) {
        .text-truncate-width { max-width: 250px; }
        body.player-visible { padding-bottom: 130px; }
        .main-content { padding-top: var(--header-height-mobile); }
        .content-area-wrapper { padding: 1rem; }
        .mobile-header {
          position: fixed; top: 0; left: 0; right: 0; height: var(--header-height-mobile);
          background-color: var(--ytm-bg); border-bottom: 1px solid var(--ytm-surface-2);
          z-index: 1000; display: flex; align-items: center; padding: 0 1rem; gap: 0.5rem;
        }
        .header-btn { background: none; border: none; color: var(--ytm-primary-text); font-size: 1.5rem; padding: 0.5rem; }
        .page-header { padding: 1rem 1rem 0 1rem; flex-wrap: wrap; }
        .content-title { font-size: 1.75rem; margin-bottom: 0.5rem; width: 100%; }
        .header-controls { margin-left: 0; width: 100%; justify-content: flex-end; }
        .player-bar { grid-template-columns: 1fr; display: flex; flex-direction: column; height: 150px; padding: 0.5rem 1rem; gap: 0; }
        .player-bar .track-info.d-md-none { order: 1; width: 100%; cursor: pointer; justify-content: space-between; }
        .player-bar .track-info-text { flex-grow: 1; }
        #player-more-btn-mobile { flex-shrink: 0; }
        .player-bar .player-controls { display: contents; }
        .player-bar .playback-bar { order: 2; width: 100%; margin-top: 8px; }
        .player-bar .player-buttons-mobile { order: 3; width: 100%; display: flex; justify-content: space-between; align-items: center; margin-top: 4px; margin-bottom: 8px; }
        .player-bar .player-buttons { display: none; }
        .player-bar .extra-controls { display: none; }
        .player-bar .track-info-art { width: 48px; height: 48px; }
        .player-btn.play-btn { width: 52px; height: 52px; }
        .player-btn .bi { font-size: 1.5rem; }
        .player-btn.play-btn .bi { font-size: 2.25rem; }
        .song-list-header { display: none; }
        .song-item { grid-template-columns: 40px minmax(0, 1fr) 40px; grid-template-rows: auto auto; padding: 0.75rem 0.5rem; border-radius: 0; border-bottom: 1px solid var(--ytm-surface-2); }
        .song-item .song-album, .song-item .song-artist, .song-item .song-duration { display: none; }
        .song-item .song-thumb, .song-item .song-indicator-wrapper { grid-row: 1 / span 2; }
        .song-item .song-title-wrapper { grid-column: 2; grid-row: 1; min-width: 0; }
        .song-item .song-artist-mobile { display: flex !important; justify-content: space-between; align-items: center; grid-column: 2; grid-row: 2; font-size: 0.8rem; color: var(--ytm-secondary-text); gap: 0.5rem; min-width: 0; }
        .song-duration-mobile { display: block !important; flex-shrink: 0; }
        .song-item .song-more { grid-column: 3; grid-row: 1 / span 2; }
        .view-details-header { flex-direction: column; align-items: center; text-align: center; }
        .song-list-header { border-bottom: 1px solid var(--ytm-surface-2); }
      }
      .loader { text-align: center; padding: 3rem; font-size: 1.2rem; color: var(--ytm-secondary-text); }
      .player-modal-content { background-color: var(--ytm-bg); color: var(--ytm-primary-text); }
      .player-modal-header { border-bottom: 0; justify-content: space-between; align-items: center; }
      .player-modal-header .player-btn { padding: 0.5rem; color: var(--ytm-primary-text); }
      .player-modal-header .player-btn .bi { font-size: 1.75rem; }
      .player-modal-body { display: flex; flex-direction: column; justify-content: space-evenly; padding: 1rem 2rem; }
      .player-modal-art-wrapper { flex-grow: 1; display: flex; align-items: center; justify-content: center; margin-bottom: 2rem; }
      #player-modal-art { width: 100%; max-width: 400px; aspect-ratio: 1/1; object-fit: cover; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); }
      .player-modal-track-info { text-align: left; margin-bottom: 1rem; }
      .player-modal-track-info .title { font-weight: 700; font-size: 1.5rem; }
      .player-modal-track-info .artist { color: var(--ytm-secondary-text); font-size: 1rem; cursor: pointer; }
      .player-modal-track-info .artist:hover { text-decoration: underline; }
      .player-modal-progress { width: 100%; margin-bottom: 1rem; }
      .player-modal-progress .time-stamps { display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--ytm-secondary-text); margin-top: 0.5rem; }
      .player-modal-controls { display: flex; justify-content: space-between; align-items: center; margin: 0 auto 1.5rem auto; width: 100%; max-width: 400px; }
      .player-modal-controls .player-btn { color: var(--ytm-primary-text); }
      .player-modal-controls .player-btn.active { color: var(--ytm-accent); }
      .player-modal-controls .player-btn .bi { font-size: 2rem; }
      .player-modal-controls .play-btn { width: 70px; height: 70px; }
      .player-modal-controls .play-btn .bi { font-size: 3.5rem; }
      .player-modal-extra-controls { display: flex; justify-content: space-between; align-items: center; }
      .add-to-playlist-item { cursor: pointer; }
      .add-to-playlist-item:hover { background-color: var(--ytm-surface-2); }
      .song-artist:hover, .song-artist-name:hover { text-decoration: underline; }
      #multi-select-bar {
        position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
        background: var(--ytm-surface-2); border-radius: 50px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5); padding: 10px 20px; z-index: 1010;
        display: flex; gap: 15px; align-items: center; justify-content: center;
        width: max-content;
      }
      #synced-lyrics-container {
        text-align: center;
        padding: 40% 0;
        transition: all 0.3s ease;
      }
      .lyric-line {
        font-size: 1.25rem;
        color: var(--ytm-secondary-text);
        margin-bottom: 0.75rem;
        transition: color 0.3s, transform 0.3s, font-weight 0.3s;
        cursor: pointer;
        min-height: 1.5rem;
      }
      .lyric-line:hover {
        color: #ffffff;
      }
      .lyric-line.active {
        color: var(--ytm-accent);
        transform: scale(1.15);
        font-weight: 700;
      }
      @media (min-width: 768px) {
        #player-art-desktop {
          cursor: pointer;
          transition: transform 0.2s;
        }
        #player-art-desktop:hover {
          transform: scale(1.05);
        }
      }
      #desktop-player-modal-lyrics-container::-webkit-scrollbar {
        width: 6px;
      }
      #desktop-player-modal-lyrics-container::-webkit-scrollbar-thumb {
        background: var(--ytm-surface);
        border-radius: 3px;
      }
      .search-dropdown { position: absolute; top: 100%; left: 0; right: 0; background-color: var(--ytm-surface-2); border: 1px solid #404040; border-radius: 0 0 8px 8px; z-index: 2000; max-height: 450px; overflow-y: auto; margin-top: 2px; padding: 0.5rem 0; box-shadow: 0 10px 25px rgba(0,0,0,0.9); }
      .search-dropdown-item { padding: 0.5rem 1rem; cursor: pointer; display: flex; align-items: center; gap: 1rem; color: var(--ytm-primary-text); text-decoration: none; transition: background 0.2s; }
      .search-dropdown-item:hover, .search-dropdown-item.active { background-color: #404040; }
      .search-dropdown-img { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; background-color: var(--ytm-surface); flex-shrink: 0; }
      .search-dropdown-text { display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
      .search-dropdown-title { font-size: 0.95rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff; }
      .search-dropdown-subtitle { font-size: 0.8rem; color: var(--ytm-secondary-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .search-dropdown-header { padding: 0.25rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: var(--ytm-secondary-text); font-weight: bold; margin-top: 0.5rem; margin-bottom: 0.25rem;}
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
            <a href="#" class="nav-link" data-view="get_following">
              <i class="bi bi-person-lines-fill"></i>
              <span>Following</span>
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
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#restore-modal">
              <i class="bi bi-key-fill"></i>
              <span>Restore Account</span>
            </a>
          </div>
          
          <div class="mt-auto">
            <hr class="text-secondary">
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#how-to-use-modal">
              <i class="bi bi-question-circle-fill"></i>
              <span>How to use</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#playlist-downloader-modal">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Downloader</span>
            </a>
            <a href="#" class="nav-link logged-in-only verified-user-only" data-bs-toggle="modal" data-bs-target="#upload-modal">
              <i class="bi bi-cloud-upload-fill"></i>
              <span>Upload Song</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#full-scan-modal">
              <i class="bi bi-hdd-stack-fill"></i>
              <span>Scan All</span>
            </a>
            <a href="#" class="nav-link" id="install-pwa-btn">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Install App</span>
            </a>
            <a href="#" class="nav-link" id="check-update-btn">
              <i class="bi bi-arrow-clockwise"></i>
              <span>Check Update</span>
            </a>
            <a href="#" class="nav-link" id="clear-cache-btn">
              <i class="bi bi-eraser-fill"></i>
              <span>Clear Cache</span>
            </a>
            <a href="#" class="nav-link" id="fullscreen-btn">
              <i class="bi bi-arrows-fullscreen"></i>
              <span>Full Screen</span>
            </a>
            <div class="text-center mt-5 small text-secondary">
              Made by <a href="https://github.com/HirotakaDango" target="_blank" class="text-decoration-none text-white-50">HirotakaDango</a>
            </div>
          </div>
        </div>
      </nav>
      <main class="main-content" id="main-content">
        <div class="mobile-header d-md-none">
          <button class="header-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#main-nav-offcanvas" aria-controls="main-nav-offcanvas">
            <i class="bi bi-list"></i>
          </button>
          <div class="input-group search-bar flex-grow-1 position-relative">
            <input type="text" class="form-control" id="search-input-mobile" placeholder="Search your music" aria-label="Search your music">
            <button class="btn" type="button" id="search-btn-mobile"><i class="bi bi-search"></i></button>
            <div id="search-dropdown-mobile" class="search-dropdown d-none"></div>
          </div>
          <div class="dropdown logged-in-only">
            <img src="" class="profile-picture" id="profile-picture-header-mobile" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
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
            <div class="input-group search-bar d-none d-md-flex position-relative">
              <input type="text" class="form-control" id="search-input-desktop" placeholder="Search..." aria-label="Search...">
              <button class="btn" type="button" id="search-btn-desktop"><i class="bi bi-search"></i></button>
              <div id="search-dropdown-desktop" class="search-dropdown d-none"></div>
            </div>
            <div class="dropdown logged-in-only d-none d-md-block">
              <img src="" class="profile-picture" id="profile-picture-header-desktop" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
              <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
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

    <div class="modal fade" id="how-to-use-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0 pb-2" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-info-circle-fill text-danger me-2"></i>Comprehensive User Guide</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-light" style="line-height: 1.6;">
            
            <h6 class="text-white mb-2"><i class="bi bi-play-circle-fill me-2"></i>1. Basic Playback & Navigation</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Play a Song:</strong> Simply click or tap on any song in the list to start playback immediately.</li>
              <li class="mb-1"><strong>Player Controls:</strong> Use the bottom player bar to pause, play, shuffle, and toggle repeat modes (Repeat All, Repeat One, Repeat Off).</li>
              <li class="mb-1"><strong>Seek Gestures:</strong> Click and hold (long press) the <strong>Next</strong> or <strong>Previous</strong> buttons to fast-forward or rewind the current track in 5-second increments.</li>
              <li class="mb-1"><strong>Fullscreen Player:</strong> Click on the track artwork or title in the mobile player bar to open the expanded, fullscreen player view.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-ui-checks-grid me-2"></i>2. Multi-Select Mode</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Activation:</strong> Click and hold (long press) on any song row for exactly 1 second to enter multi-select mode.</li>
              <li class="mb-1"><strong>Selecting:</strong> Once activated, tap any other songs to add or remove them from your selection. A floating action bar will appear at the bottom.</li>
              <li class="mb-1"><strong>Bulk Actions:</strong> Use the floating bar to add all selected songs directly to a specific playlist or your favorites in one click.</li>
              <li class="mb-1"><strong>Note:</strong> Drag-and-drop sorting is temporarily disabled while multi-select mode is active.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-three-dots-vertical me-2"></i>3. Context Menus & Actions</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Accessing:</strong> Click the three vertical dots <i class="bi bi-three-dots-vertical"></i> on the right side of any song or playlist.</li>
              <li class="mb-1"><strong>Navigation:</strong> Instantly jump to the track's Artist or Album view.</li>
              <li class="mb-1"><strong>Information:</strong> View deeply embedded Metadata (Bitrate, Duration, Year) or read the embedded Lyrics.</li>
              <li class="mb-1"><strong>Sharing:</strong> Generate a shareable link to send specific songs, albums, or playlists to friends via WhatsApp, Telegram, or Twitter.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-sort-down me-2"></i>4. Custom Sorting & Ordering</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Drag and Drop:</strong> In your <em>Favorites</em> and <em>Playlists</em> (when sorted by "My Order"), click and drag a song to manually reposition it. The new order saves automatically.</li>
              <li class="mb-1"><strong>Sort Dropdowns:</strong> Use the dropdown menu at the top right of most views to sort by Title (A-Z), Artist, Album, Year, or Recently Added.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-cloud-upload-fill me-2"></i>5. Uploading & Managing Music</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Verification:</strong> Only verified users can upload music. Admins manage verification via the Admin Panel.</li>
              <li class="mb-1"><strong>Limits & Formats:</strong> You can upload up to 10 tracks per day. Supported formats include MP3, FLAC, M4A, WAV, and OGG.</li>
              <li class="mb-1"><strong>Editing Metadata:</strong> Open the context menu on a song you uploaded and select "Edit Info". You can change the Title, Artist, Album, Genre, Lyrics, and even upload a new 1:1 Cover Art Image.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-box-arrow-down me-2"></i>6. Data Portability & Offline Usage</h6>
            <ul class="small mb-4">
              <li class="mb-1"><strong>Import/Export:</strong> Go to the Playlists or Favorites view and click the Export button to download a `.json` backup of your list. You can restore it anytime using the Import button.</li>
              <li class="mb-1"><strong>Playlist Downloader:</strong> Open the Downloader tool from the sidebar, input a Playlist Public ID, and the app will sequentially download all MP3 files to your local device.</li>
              <li class="mb-1"><strong>Install App (PWA):</strong> Click "Install App" in the sidebar to add PHP Music to your home screen. It will cache assets for much faster loading and a native app feel.</li>
            </ul>

            <h6 class="text-white mb-2"><i class="bi bi-shield-exclamation me-2"></i>7. Disclaimers & Legal</h6>
            <ul class="small mb-4 text-white">
              <li class="mb-1"><strong>Copyright Responsibility:</strong> Users are solely responsible for the audio files they upload. Ensure you have the explicit right or permission to use, stream, and distribute the content.</li>
              <li class="mb-1"><strong>Personal Use Only:</strong> Features like the Playlist Downloader and streaming are intended strictly for personal, private, and non-commercial use.</li>
              <li class="mb-1"><strong>Data Integrity:</strong> While we offer robust backup features via JSON export/import, this application and its services are provided "as-is" without any warranties. Please keep secure local copies of your original music files.</li>
              <li class="mb-1"><strong>Content Moderation:</strong> Administrators reserve the right to indefinitely ban accounts, remove files, or delete metadata that violates copyright laws, platform terms, or community guidelines without prior notice.</li>
            </ul>

            <h6 class="text-white mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="text-danger me-2" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>8. Interface Icons & Buttons Dictionary
            </h6>
            <ul class="small mb-0 text-white" style="list-style: none; padding-left: 0;">
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="m11.596 8.697-6.363 3.692c-.54.313-1.233-.066-1.233-.697V4.308c0-.63.692-1.01 1.233-.696l6.363 3.692a.802.802 0 0 1 0 1.393z"/></svg> / 
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M5.5 3.5A1.5 1.5 0 0 1 7 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5zm5 0A1.5 1.5 0 0 1 12 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5z"/></svg>
                </span>
                <span><strong>Play / Pause:</strong> Tap to start or pause the currently selected audio track.</span>
              </li>
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a.5.5 0 0 1 1 0v3.248l6.267-3.636c.54-.313 1.233.066 1.233.696v7.384c0 .63-.692 1.01-1.233.696L5 8.752V12a.5.5 0 0 1-1 0V4z"/></svg> / 
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 4a.5.5 0 0 0-1 0v3.248L5.233 3.612C4.693 3.3 4 3.678 4 4.308v7.384c0 .63.692 1.01 1.233.696L11.5 8.752V12a.5.5 0 0 0 1 0V4z"/></svg>
                </span>
                <span><strong>Previous / Next:</strong> Skip backward or forward in the queue. Press and hold to rewind/fast-forward the current song.</span>
              </li>
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M0 3.5A.5.5 0 0 1 .5 3H1c2.202 0 3.827 1.24 4.874 2.418.49.552.865 1.102 1.126 1.532.26-.43.636-.98 1.126-1.532C9.173 4.24 10.798 3 13 3v1c-1.798 0-3.173 1.01-4.126 2.082A9.624 9.624 0 0 0 7.556 8a9.624 9.624 0 0 0 1.317 1.918C9.828 10.99 11.204 12 13 12v1c-2.202 0-3.827-1.24-4.874-2.418A10.595 10.595 0 0 1 7 9.05c-.26.43-.636.98-1.126 1.532C4.827 11.76 3.202 13 1 13H.5a.5.5 0 0 1 0-1H1c1.798 0 3.173-1.01 4.126-2.082A9.624 9.624 0 0 0 6.444 8a9.624 9.624 0 0 0-1.317-1.918C4.172 5.01 2.796 4 1 4H.5a.5.5 0 0 1-.5-.5z"/><path d="M13 5.466V1.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384l-2.36 1.966a.25.25 0 0 1-.41-.192zm0 9v-3.932a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384l-2.36 1.966a.25.25 0 0 1-.41-.192z"/></svg>
                </span>
                <span><strong>Shuffle:</strong> Mixes up the current play queue in a random order.</span>
              </li>
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M11 5.466V4H5a4 4 0 0 0-3.584 5.777.5.5 0 1 1-.896.446A5 5 0 0 1 5 3h6V1.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384l-2.36 1.966a.25.25 0 0 1-.41-.192Zm3.81.086a.5.5 0 0 1 .67.225A5 5 0 0 1 11 13H5v1.466a.25.25 0 0 1-.41.192l-2.36-1.966a.25.25 0 0 1 0-.384l2.36-1.966a.25.25 0 0 1 .41.192V12h6a4 4 0 0 0 3.585-5.777.5.5 0 0 1 .225-.67Z"/></svg>
                </span>
                <span><strong>Repeat:</strong> Toggles between Repeat Off, Repeat All, and Repeat One.</span>
              </li>
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/></svg>
                </span>
                <span><strong>Context Menu (More):</strong> Opens advanced options like Sharing, Lyrics, Metadata, and adding to playlists.</span>
              </li>
              <li class="mb-3 d-flex align-items-start">
                <span style="min-width: 45px;" class="text-white text-center me-2">
                  <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143q.09.083.176.171a3 3 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15"/></svg>
                </span>
                <span><strong>Favorite:</strong> Toggles saving a song to your personal Favorites library.</span>
              </li>
            </ul>

          </div>
        </div>
      </div>
    </div>

    <div id="multi-select-bar" class="d-none">
      <span id="multi-select-count" class="badge bg-danger rounded-circle d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;">0</span>
      <button class="btn btn-sm btn-outline-light rounded-circle d-flex align-items-center justify-content-center" id="multi-cancel-btn" style="width: 36px; height: 36px;"><i class="bi bi-x-lg"></i></button>
      <button class="btn btn-sm btn-outline-light rounded-circle d-flex align-items-center justify-content-center" id="multi-add-playlist-btn" style="width: 36px; height: 36px;"><i class="bi bi-music-note-list"></i></button>
      <button class="btn btn-sm btn-outline-light rounded-circle d-flex align-items-center justify-content-center" id="multi-add-favorite-btn" style="width: 36px; height: 36px;"><i class="bi bi-heart-fill"></i></button>
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
        <button class="player-btn ms-2" id="pip-btn-desktop" title="Mini Player"><i class="bi bi-pip"></i></button>
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
              <p id="player-modal-artist" class="artist text-truncate">Artist Name</p>
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

    <div class="modal fade" id="desktop-player-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content" style="background-color: var(--ytm-bg); color: var(--ytm-primary-text);">
          <div class="modal-header player-modal-header py-0 px-4 border-0">
            <button type="button" class="btn player-btn text-white" data-bs-dismiss="modal" aria-label="Close">
              <i class="bi bi-chevron-down fs-2"></i>
            </button>
            <button type="button" class="btn player-btn text-white" id="desktop-player-modal-more-btn" title="More">
              <i class="bi bi-three-dots-vertical fs-3"></i>
            </button>
          </div>
          <div class="modal-body d-flex h-100 overflow-hidden pt-1 gap-4 align-items-center">
            
            <div class="w-50 d-flex flex-column align-items-center justify-content-center h-100">
              <img src="" id="desktop-player-modal-art" class="rounded shadow-lg" style="width: 100%; max-width: 60vh; aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
              <div class="mb-3 text-center mt-4 w-100 px-4">
                <h3 id="desktop-player-modal-title" class="fw-bold mb-1 text-truncate" style="max-width: 100%;">Song Title</h3>
                <p id="desktop-player-modal-artist" class="text-secondary mb-0 text-truncate" style="cursor: pointer; max-width: 100%;">Artist Name</p>
              </div>
            </div>

            <div class="w-50 d-flex flex-column h-100 py-3 pe-4">
              
              <ul class="nav nav-tabs border-secondary d-flex align-items-center justify-content-center border-0" id="dp-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="dp-queue-tab" data-bs-toggle="tab" data-bs-target="#dp-queue-pane" type="button" role="tab">Up Next</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="dp-lyrics-tab" data-bs-toggle="tab" data-bs-target="#dp-lyrics-pane" type="button" role="tab">Lyrics</button>
                </li>
              </ul>
              
              <div class="tab-content flex-grow-1 overflow-hidden d-flex flex-column mb-4 rounded" style="background-color: var(--ytm-surface);" id="dp-tabs-content">
                
                <div class="tab-pane fade show active h-100 overflow-auto" id="dp-queue-pane" role="tabpanel">
                   <div id="desktop-player-queue-list" class="p-2">
                     <!-- Populated dynamically by JS -->
                   </div>
                </div>

                <div class="tab-pane fade h-100 overflow-hidden text-center position-relative fs-4" id="dp-lyrics-pane" role="tabpanel">
                   <div class="h-100 overflow-auto p-4" id="desktop-player-modal-lyrics-container">
                     <div id="desktop-synced-lyrics" style="padding: 20% 0; transition: all 0.3s ease;"></div>
                   </div>
                </div>

              </div>
              
              <div class="mt-auto">
                <div class="d-flex align-items-center gap-3 mb-4">
                  <span id="desktop-player-modal-current-time" class="small text-secondary">0:00</span>
                  <div class="progress-bar-container flex-grow-1" id="desktop-player-modal-progress-container">
                    <div class="progress-bar-bg"></div>
                    <div class="progress-bar-fg" id="desktop-player-modal-progress-bar"></div>
                  </div>
                  <span id="desktop-player-modal-time-left" class="small text-secondary">0:00</span>
                </div>
                <div class="d-flex justify-content-center align-items-center gap-4">
                  <button class="player-btn" id="desktop-player-modal-shuffle-btn" title="Shuffle"><i class="bi bi-shuffle"></i></button>
                  <button class="player-btn fs-2" id="desktop-player-modal-prev-btn" title="Previous"><i class="bi bi-skip-start-fill"></i></button>
                  <button class="player-btn play-btn" id="desktop-player-modal-play-pause-btn" title="Play" style="width: 70px; height: 70px;"><i class="bi bi-play-fill" style="font-size: 3.5rem;"></i></button>
                  <button class="player-btn fs-2" id="desktop-player-modal-next-btn" title="Next"><i class="bi bi-skip-end-fill"></i></button>
                  <button class="player-btn" id="desktop-player-modal-repeat-btn" title="Repeat"><i class="bi bi-repeat"></i></button>
                </div>
              </div>

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
    <div class="modal fade" id="restore-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Restore Account</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="restore-form">
              <div class="mb-3">
                <label for="restore-key" class="form-label">Backup Key</label>
                <input type="text" class="form-control" id="restore-key" required>
              </div>
              <div class="mb-3">
                <label for="restore-email" class="form-label">New Email address</label>
                <input type="email" class="form-control" id="restore-email" required>
              </div>
              <div class="mb-3">
                <label for="restore-artist" class="form-label">New Artist Name</label>
                <input type="text" class="form-control" id="restore-artist" required>
              </div>
              <div class="mb-3">
                <label for="restore-password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="restore-password" required minlength="6">
              </div>
              <button type="submit" class="btn btn-danger w-100">Restore</button>
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
            <h6 class="mt-4">Change Display Name</h6>
            <form id="change-name-form">
              <div class="mb-3">
                <label for="new-name" class="form-label">New Name</label>
                <input type="text" class="form-control" id="new-name" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Name</button>
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
            <hr class="text-secondary">
            <h6 class="mt-4 text-danger">Data Management</h6>
            <button type="button" class="btn btn-outline-danger w-100 mb-2" id="btn-delete-keep">Delete Account but Keep Data</button>
            <button type="button" class="btn btn-danger w-100" id="btn-delete-all">Permanently Delete Account & Data</button>
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
              <label for="song-genre" class="form-label">Custom Genre</label>
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
    <div class="modal fade" id="import-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Import Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="import-playlist-form">
              <div class="mb-3">
                <label for="import-playlist-file" class="form-label">Select JSON file</label>
                <input type="file" class="form-control" id="import-playlist-file" accept="application/json,.json" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Import</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="import-favorites-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Import Favorites</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="import-favorites-form">
              <div class="mb-3">
                <label for="import-favorites-file" class="form-label">Select JSON file</label>
                <input type="file" class="form-control" id="import-favorites-file" accept="application/json,.json" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Import</button>
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
        <div class="modal-content" style="background: rgba(30, 30, 30, 0.95); backdrop-filter: blur(10px); border: 1px solid #444;">
          <div class="modal-header border-0 pb-0">
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body pt-0" id="metadata-modal-body">
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="lyrics-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background: rgba(30, 30, 30, 0.95); backdrop-filter: blur(10px); border: 1px solid #444;">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title w-100 text-center" id="lyrics-modal-title">Lyrics</h5>
            <button type="button" class="btn-close btn-close-white position-absolute end-0 me-3" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="lyrics-modal-body">
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="edit-metadata-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Edit Metadata</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-metadata-form" enctype="multipart/form-data">
              <input type="hidden" id="edit-metadata-id">
              <div class="mb-3 text-center">
                <img id="edit-metadata-cover-preview" src="" class="img-thumbnail bg-transparent border-secondary mb-2" style="width: 150px; height: 150px; object-fit: cover; border-radius: 8px;">
                <input type="file" class="form-control form-control-sm" id="edit-metadata-cover" accept="image/*">
                <small class="text-secondary d-block mt-1">Upload a new cover image (1:1 crop)</small>
              </div>
              <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" id="edit-metadata-title">
              </div>
              <div class="mb-3">
                <label class="form-label">Artist</label>
                <input type="text" class="form-control" id="edit-metadata-artist">
              </div>
              <div class="mb-3">
                <label class="form-label">Album</label>
                <input type="text" class="form-control" id="edit-metadata-album">
              </div>
              <div class="mb-3">
                <label class="form-label">Genre</label>
                <input type="text" class="form-control" id="edit-metadata-genre">
              </div>
              <div class="mb-3">
                <label class="form-label">Lyrics</label>
                <textarea class="form-control" id="edit-metadata-lyrics" rows="4"></textarea>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Changes</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="artists-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--ytm-surface); border: 1px solid #444;">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title">Artists</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0" id="artists-modal-body">
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
    
    <div class="modal fade" id="update-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title"><i class="bi bi-arrow-clockwise"></i> Check for Updates</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center" id="update-modal-body">
            <!-- Dynamic Content populated by JS -->
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="playlist-downloader-modal" tabindex="-1">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content" style="background-color: var(--ytm-bg);">
          <div class="modal-header border-0" style="background-color: var(--ytm-surface-2);">
            <h5 class="modal-title"><i class="bi bi-cloud-arrow-down-fill"></i> Playlist Downloader</h5>
            <button type="button" class="btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-2 p-md-4">
            <div class="container-fluid mx-auto" style="max-width: 1000px;">
              <div class="card mb-4" style="background-color: var(--ytm-surface); border: none;">
                <div class="card-header" style="background-color: var(--ytm-surface-2); font-weight: bold; border: none; color: #ffffff !important;">Load Playlist / Song</div>
                <div class="card-body">
                  <form id="pd-load-form" class="mb-4">
                    <div class="mb-3">
                      <label for="pd-playlist-id" class="form-label" style="color: #ffffff !important;">Playlist ID</label>
                      <input type="text" class="form-control" id="pd-playlist-id" placeholder="Enter Playlist Public ID">
                    </div>
                    <button type="submit" class="btn btn-danger">Load Playlist</button>
                  </form>
                  <hr class="text-secondary">
                  <div class="mb-3">
                    <label for="pd-song-id" class="form-label" style="color: #ffffff !important;">Download a single song by ID</label>
                    <div class="input-group">
                      <input type="number" class="form-control" id="pd-song-id" placeholder="Song ID">
                      <button class="btn btn-outline-light" type="button" id="pd-download-single" style="color: #ffffff !important;">Download</button>
                    </div>
                  </div>
                </div>
              </div>

              <div id="pd-results-card" class="card d-none" style="background-color: var(--ytm-surface); border: none;">
                <div class="card-header" style="background-color: var(--ytm-surface-2); font-weight: bold; border: none; color: #ffffff !important;" id="pd-playlist-title">
                  Playlist Details
                </div>
                <div class="card-body">
                  <button class="btn btn-danger w-100 mb-3" id="pd-start-auto" style="color: #ffffff !important;">
                    <i class="bi bi-play-fill" style="color: #ffffff !important;"></i> Download All Songs (Sequential)
                  </button>
                  <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2" style="color: #ffffff !important;">
                      <span><i class="bi bi-terminal" style="color: #ffffff !important;"></i> Download Log</span>
                      <button class="btn btn-sm btn-outline-secondary" id="pd-clear-log" style="color: #ffffff !important; border-color: #ffffff;">Clear</button>
                    </div>
                    <div class="log-area" id="pd-log" style="background-color: var(--ytm-surface-2); border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.85rem; height: 300px; overflow-y: auto; color: #ffffff !important;"></div>
                  </div>
                  <div>
                    <strong style="color: #ffffff !important;">Song List</strong>
                    <div class="song-list mt-2" style="background-color: var(--ytm-surface); border-radius: 12px; overflow: hidden;">
                      <div class="song-item small d-none d-md-grid pd-song-row" style="color: #ffffff !important;">
                        <div style="color: #ffffff !important;">#</div><div style="color: #ffffff !important;">Title</div><div style="color: #ffffff !important;">Artist</div><div style="color: #ffffff !important;">Duration</div><div></div>
                      </div>
                      <div id="pd-song-rows"></div>
                    </div>
                    <div id="pd-infinite-scroll-loader" class="text-center p-3 d-none" style="color: var(--ytm-secondary-text);">Loading more...</div>
                  </div>
                </div>
              </div>
            </div>
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
        const checkUpdateBtn = document.getElementById('check-update-btn');
        const updateModalEl = document.getElementById('update-modal');
        const updateModal = updateModalEl ? new bootstrap.Modal(updateModalEl) : null;
        const updateModalBody = document.getElementById('update-modal-body');

        const pipBtnDesktop = document.getElementById('pip-btn-desktop');
        
        const pipCanvas = document.createElement('canvas');
        pipCanvas.width = 500;
        pipCanvas.height = 500;
        const pipCtx = pipCanvas.getContext('2d', { willReadFrequently: true });
        const pipVideo = document.createElement('video');
        pipVideo.muted = true;
        pipVideo.playsInline = true;
        try {
          if (pipCanvas.captureStream) {
            pipVideo.srcObject = pipCanvas.captureStream(1);
          } else if (pipBtnDesktop) {
            pipBtnDesktop.style.display = 'none';
          }
        } catch(e) {}
        
        const clearCacheBtn = document.getElementById('clear-cache-btn');
        const fullscreenBtn = document.getElementById('fullscreen-btn');
        const fullScanModalEl = document.getElementById('full-scan-modal');
        const fullScanIframe = document.getElementById('full-scan-iframe');

        const genresModalEl = document.getElementById('genres-modal');
        const genresModal = genresModalEl ? new bootstrap.Modal(genresModalEl) : null;
        const genresModalBody = document.getElementById('genres-modal-body');
        const createPlaylistModalEl = document.getElementById('create-playlist-modal');
        const createPlaylistModal = createPlaylistModalEl ? new bootstrap.Modal(createPlaylistModalEl) : null;
        const editPlaylistModalEl = document.getElementById('edit-playlist-modal');
        const editPlaylistModal = editPlaylistModalEl ? new bootstrap.Modal(editPlaylistModalEl) : null;
        const importPlaylistModalEl = document.getElementById('import-playlist-modal');
        const importPlaylistModal = importPlaylistModalEl ? new bootstrap.Modal(importPlaylistModalEl) : null;
        const importFavoritesModalEl = document.getElementById('import-favorites-modal');
        const importFavoritesModal = importFavoritesModalEl ? new bootstrap.Modal(importFavoritesModalEl) : null;
        const addToPlaylistModalEl = document.getElementById('add-to-playlist-modal');
        const addToPlaylistModal = addToPlaylistModalEl ? new bootstrap.Modal(addToPlaylistModalEl) : null;
        const addToPlaylistModalBody = document.getElementById('add-to-playlist-modal-body');
        const metadataModalEl = document.getElementById('metadata-modal');
        const metadataModal = metadataModalEl ? new bootstrap.Modal(metadataModalEl) : null;
        const metadataModalBody = document.getElementById('metadata-modal-body');
        const editMetadataModalEl = document.getElementById('edit-metadata-modal');
        const editMetadataModal = editMetadataModalEl ? new bootstrap.Modal(editMetadataModalEl) : null;
        const artistsModalEl = document.getElementById('artists-modal');
        const artistsModal = artistsModalEl ? new bootstrap.Modal(artistsModalEl) : null;
        const artistsModalBody = document.getElementById('artists-modal-body');
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
          art: [document.getElementById('player-art-desktop'), document.getElementById('player-art-mobile'), document.getElementById('player-modal-art'), document.getElementById('desktop-player-modal-art')],
          title: [document.getElementById('player-title-desktop'), document.getElementById('player-title-mobile'), document.getElementById('player-modal-title'), document.getElementById('desktop-player-modal-title')],
          artist: [document.getElementById('player-artist-desktop'), document.getElementById('player-artist-mobile'), document.getElementById('player-modal-artist'), document.getElementById('desktop-player-modal-artist')],
          currentTime: [document.getElementById('current-time'), document.getElementById('player-modal-current-time'), document.getElementById('desktop-player-modal-current-time')],
          timeLeft: [document.getElementById('time-left'), document.getElementById('player-modal-time-left'), document.getElementById('desktop-player-modal-time-left')],
          progress: [document.getElementById('progress-bar'), document.getElementById('player-modal-progress-bar'), document.getElementById('desktop-player-modal-progress-bar')],
          progressContainer: [document.getElementById('progress-container'), document.getElementById('player-modal-progress-container'), document.getElementById('desktop-player-modal-progress-container')],
          playPauseBtn: [document.getElementById('play-pause-btn-desktop'), document.getElementById('play-pause-btn-mobile'), document.getElementById('player-modal-play-pause-btn'), document.getElementById('desktop-player-modal-play-pause-btn')],
          prevBtn: [document.getElementById('prev-btn-desktop'), document.getElementById('prev-btn-mobile'), document.getElementById('player-modal-prev-btn'), document.getElementById('desktop-player-modal-prev-btn')],
          nextBtn: [document.getElementById('next-btn-desktop'), document.getElementById('next-btn-mobile'), document.getElementById('player-modal-next-btn'), document.getElementById('desktop-player-modal-next-btn')],
          shuffleBtn: [document.getElementById('shuffle-btn-desktop'), document.getElementById('shuffle-btn-mobile'), document.getElementById('player-modal-shuffle-btn'), document.getElementById('desktop-player-modal-shuffle-btn')],
          repeatBtn: [document.getElementById('repeat-btn-desktop'), document.getElementById('repeat-btn-mobile'), document.getElementById('player-modal-repeat-btn'), document.getElementById('desktop-player-modal-repeat-btn')],
          moreBtn: [document.getElementById('player-more-btn-desktop'), document.getElementById('player-more-btn-mobile'), document.getElementById('player-modal-more-btn'), document.getElementById('desktop-player-modal-more-btn')],
          volumeBtn: document.getElementById('volume-btn'),
          volumeSlider: document.getElementById('volume-slider'),
        };
        
        const desktopPlayerModalEl = document.getElementById('desktop-player-modal');
        const desktopPlayerModal = desktopPlayerModalEl ? new bootstrap.Modal(desktopPlayerModalEl) : null;
        
        const renderDesktopLyrics = () => {
          const lyricsContainer = document.getElementById('desktop-synced-lyrics');
          if (!lyricsContainer || !currentSong) return;

          if (currentSong.lyrics) {
            const lrcData = parseLRC(currentSong.lyrics);
            if (lrcData.length > 0) {
              currentLrcData = lrcData;
              currentLrcSongId = currentSong.id;
              currentLyricIndex = -1;
              
              lyricsContainer.innerHTML = lrcData.map((line, idx) => 
                `<div class="lyric-line" data-index="${idx}" data-time="${line.time}">${escapeHTML(line.text)}</div>`
              ).join('');
            } else {
              currentLrcData = null;
              currentLrcSongId = null;
              lyricsContainer.innerHTML = `<pre style="white-space: pre-wrap; font-family: 'Roboto', sans-serif;">${escapeHTML(currentSong.lyrics)}</pre>`;
            }
          } else {
            currentLrcData = null;
            currentLrcSongId = null;
            lyricsContainer.innerHTML = '<p class="text-center text-secondary">No lyrics available.</p>';
          }
        };

        const renderDesktopQueue = () => {
          const queueContainer = document.getElementById('desktop-player-queue-list');
          if (!queueContainer) return;
          
          if (queueDirty) {
            const mainSongList = document.querySelector('#content-area .song-list') || 
                                 document.querySelector('#songs-pane .song-list') || 
                                 document.querySelector('#search-songs-pane .song-list');
            
            if (mainSongList) {
              queueContainer.innerHTML = '';
              const clone = mainSongList.cloneNode(true);
              clone.classList.remove('sortable');
              queueContainer.appendChild(clone);
              
              queueDirty = false;
            } else {
              queueContainer.innerHTML = '<div class="p-4 text-center text-secondary">Queue not available.</div>';
            }
          }
          
          if (currentSong) {
            const active = queueContainer.querySelector(`.song-item[data-song-id="${currentSong.id}"]`);
            if (active) {
               setTimeout(() => {
                 active.scrollIntoView({ behavior: 'smooth', block: 'center' });
               }, 100);
            }
          }
        };

        const playerArtDesktop = document.getElementById('player-art-desktop');
        if (playerArtDesktop) {
          playerArtDesktop.addEventListener('click', () => {
            if (currentSong && desktopPlayerModal) {
              renderDesktopLyrics();
              renderDesktopQueue();
              desktopPlayerModal.show();
            }
          });
        }

        const desktopQueueList = document.getElementById('desktop-player-queue-list');
        if (desktopQueueList) {
          desktopQueueList.addEventListener('click', e => {
            
            const moreBtn = e.target.closest('.more-btn');
            if (moreBtn) {
              e.preventDefault(); e.stopPropagation();
              showSongItemContextMenu(moreBtn);
              return;
            }
            
            const songArtistEl = e.target.closest('.song-artist, .song-artist-name');
            if (songArtistEl) {
               e.stopPropagation();
               if (desktopPlayerModal) desktopPlayerModal.hide();
               const artistRaw = songArtistEl.dataset.artist ? decodeURIComponent(songArtistEl.dataset.artist) : songArtistEl.textContent.trim();
               const userId = songArtistEl.dataset.userid || '';
               loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId });
               return;
            }

            const songAlbumEl = e.target.closest('.song-album');
            if (songAlbumEl) {
               e.stopPropagation();
               if (desktopPlayerModal) desktopPlayerModal.hide();
               const songItem = e.target.closest('.song-item');
               loadView({ type: 'album_songs', param: songAlbumEl.dataset.album, sort: 'title_asc', filter_user_id: songItem ? songItem.dataset.userid || songItem.dataset.songUserId || '' : '' });
               return;
            }

            const songItem = e.target.closest('.song-item');
            if (songItem) {
              const songId = parseInt(songItem.dataset.songId);
              const idx = queue.findIndex(id => id === songId);
              if (idx !== -1) {
                 queueIndex = idx;
                 playSongById(songId);
              } else {
                 setQueueAndPlay(songId);
              }
            }
          });
        }

        const desktopLyricsContainer = document.getElementById('desktop-player-modal-lyrics-container');
        if (desktopLyricsContainer) {
          desktopLyricsContainer.addEventListener('click', (e) => {
            const line = e.target.closest('.lyric-line');
            if (line && currentSong && currentSong.id === currentLrcSongId && audio) {
              audio.currentTime = parseFloat(line.dataset.time);
            }
          });
        }

        const audio = new Audio();
        let currentView = { type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' };
        let currentUser = null;
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = false;
        let repeatMode = 'none';
        let queueDirty = true;
        let deferredInstallPrompt = null;
        let sortable = null;
        let songIdForPlaylist = null;
        let mixIdForPlaylist = null;
        let contextMenuItemEl = null;
        let previousVolume = 1;
        let cachedExploreData = null;
        let currentLrcData = null;
        let currentLrcSongId = null;
        let currentLyricIndex = -1;
        
        let holdTimer;
        let multiSelectMode = false;
        let selectedSongs = new Set();
        const multiSelectBar = document.getElementById('multi-select-bar');
        const multiSelectCount = document.getElementById('multi-select-count');
        
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
          spinner: `<div class="spinner-border text-secondary" style="width: 1.5rem; height: 1.5rem; border-width: 0.2em;" role="status"></div>`,
        };

        const updateFullscreenIcon = () => {
          if (fullscreenBtn) {
            const isFull = document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || document.mozFullScreenElement;
            fullscreenBtn.innerHTML = isFull ? '<i class="bi bi-fullscreen-exit"></i><span>Exit Full Screen</span>' : '<i class="bi bi-arrows-fullscreen"></i><span>Full Screen</span>';
          }
        };

        if (fullscreenBtn)  {
          fullscreenBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement && !document.mozFullScreenElement) {
              if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen();
              } else if (document.documentElement.webkitRequestFullscreen) {
                document.documentElement.webkitRequestFullscreen();
              } else if (document.documentElement.msRequestFullscreen) {
                document.documentElement.msRequestFullscreen();
              } else               if (document.documentElement.mozRequestFullScreen) {
                document.documentElement.mozRequestFullScreen();
              }
            } else {
              if (document.exitFullscreen) {
                document.exitFullscreen();
              } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
              } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
              } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
              }
            }
          });
        }
        
        document.addEventListener('fullscreenchange', updateFullscreenIcon);
        document.addEventListener('webkitfullscreenchange', updateFullscreenIcon);
        document.addEventListener('MSFullscreenChange', updateFullscreenIcon);
        document.addEventListener('mozfullscreenchange', updateFullscreenIcon);

        const formatTime = (seconds) => {
          if (isNaN(seconds) || seconds < 0) return '0:00';
          const min = Math.floor(seconds / 60);
          const sec = Math.floor(seconds % 60).toString().padStart(2, '0');
          return `${min}:${sec}`;
        };
        
        const parseLRC = (lrc) => {
          if (!lrc) return [];
          const lines = lrc.split('\n');
          const parsed = [];
          const timeReg = /\[\d{2,}:\d{2}(?:\.\d{1,3})?\]/g;
          for (let line of lines) {
            const times = line.match(timeReg);
            if (times) {
              const text = line.replace(timeReg, '').trim();
              times.forEach(t => {
                const min = parseInt(t.slice(1, 3));
                const sec = parseFloat(t.slice(4, -1));
                parsed.push({ time: min * 60 + sec, text: text || '♪' });
              });
            }
          }
          return parsed.sort((a, b) => a.time - b.time);
        };
        
        const escapeHTML = str => str.replace(/[&<>'"]/g, tag => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[tag]));
        
        const timeAgo = (isoString) => {
          if (!isoString) return '';
          const date = new Date(isoString);
          if (isNaN(date)) return isoString;
        
          const seconds = Math.floor((new Date() - date) / 1000);
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
        };

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

        const updateMultiSelectUI = () => {
          if (selectedSongs.size > 0) {
            multiSelectBar.classList.remove('d-none');
            multiSelectCount.textContent = selectedSongs.size;
            if (sortable) sortable.option("disabled", true);
          } else {
            multiSelectBar.classList.add('d-none');
            multiSelectMode = false;
            document.querySelectorAll('.song-item.multi-selected').forEach(el => el.classList.remove('multi-selected'));
            if (sortable) sortable.option("disabled", false);
          }
        };

        const toggleSongSelection = (songItem) => {
          const songId = songItem.dataset.songId;
          if (selectedSongs.has(songId)) {
            selectedSongs.delete(songId);
            songItem.classList.remove('multi-selected');
          } else {
            selectedSongs.add(songId);
            songItem.classList.add('multi-selected');
          }
          updateMultiSelectUI();
        };

        let isDragging = false;
        let startX = 0, startY = 0;

        const startHold = (e) => {
          if (!currentUser) return;
          const songItem = e.target.closest('.song-item');
          if (!songItem || e.target.closest('.song-more')) return;
          
          isDragging = false;
          if (e.touches && e.touches.length > 0) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
          }
          
          holdTimer = setTimeout(() => {
            if (isDragging) return;
            multiSelectMode = true;
            if (sortable) {
              sortable.option("disabled", true);
              if (window.TouchEvent) {
                document.dispatchEvent(new TouchEvent('touchend'));
              }
            }
            if (!selectedSongs.has(songItem.dataset.songId)) {
              toggleSongSelection(songItem);
            }
          }, 1000);
        };

        const moveHold = (e) => {
          if (holdTimer && e.touches && e.touches.length > 0) {
            const dx = Math.abs(e.touches[0].clientX - startX);
            const dy = Math.abs(e.touches[0].clientY - startY);
            if (dx > 10 || dy > 10) {
              isDragging = true;
              clearTimeout(holdTimer);
            }
          }
        };

        const endHold = () => {
          if (holdTimer) clearTimeout(holdTimer);
        };

        contentArea.addEventListener('touchstart', startHold, {passive: true});
        contentArea.addEventListener('touchmove', moveHold, {passive: true});
        contentArea.addEventListener('touchend', endHold);
        contentArea.addEventListener('touchcancel', endHold);

        contentArea.addEventListener('contextmenu', (e) => {
          const songItem = e.target.closest('.song-item');
          const moreBtn = e.target.closest('.song-more');
          if (songItem || e.target.closest('.shelf-item')) {
            e.preventDefault();
            if (songItem && !moreBtn && currentUser) {
              if (!multiSelectMode) {
                multiSelectMode = true;
                if (sortable) sortable.option("disabled", true);
              }
              toggleSongSelection(songItem);
            }
          }
        });

        document.getElementById('multi-cancel-btn').addEventListener('click', () => {
          selectedSongs.clear();
          updateMultiSelectUI();
        });

        document.getElementById('multi-add-favorite-btn').addEventListener('click', async () => {
          if (!currentUser || selectedSongs.size === 0) return;
          let added = 0;
          for (let songId of selectedSongs) {
            const songEl = document.querySelector(`.song-item[data-song-id="${songId}"]`);
            if (songEl && songEl.dataset.isFavorite === '0') {
              await fetchData('?action=toggle_favorite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: songId })
              });
              songEl.dataset.isFavorite = '1';
              added++;
            }
          }
          showToast(`Added ${added} new songs to favorites!`, 'success');
          selectedSongs.clear();
          updateMultiSelectUI();
        });

        document.getElementById('multi-add-playlist-btn').addEventListener('click', async () => {
          songIdForPlaylist = Array.from(selectedSongs);
          mixIdForPlaylist = null;
          const playlists = await fetchData(`?action=get_user_playlists&sort=modified_desc`);
          if (playlists && playlists.length > 0) {
            addToPlaylistModalBody.innerHTML = `
              <div class="list-group list-group-flush">
                ${playlists.map(p => `
                  <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 add-to-playlist-item my-1 p-2 rounded bg-transparent text-white border border-secondary" data-playlist-id="${p.id}">
                    <img src="?action=get_image&id=${p.image_id || 0}" class="rounded" style="width: 48px; height: 48px; object-fit: cover; background-color: var(--ytm-surface-2);">
                    <div class="d-flex flex-column flex-grow-1 text-start overflow-hidden">
                      <span class="text-truncate fw-medium">${p.name}</span>
                      <span class="small text-secondary">${p.song_count} songs</span>
                    </div>
                    <i class="bi bi-plus-circle fs-5"></i>
                  </button>
                `).join('')}
              </div>
            `;
          } else {
            addToPlaylistModalBody.innerHTML = `<p class="text-secondary text-center p-4">No playlists found. Create one first!</p>`;
          }
          addToPlaylistModal.show();
          selectedSongs.clear();
          updateMultiSelectUI();
        });
        
        const renderViewDetailsHeader = (details, type) => {
          let typeText = type.charAt(0).toUpperCase() + type.slice(1);
          let statsText = `${details.song_count || 0} songs &bull; ${formatTime(details.total_duration || 0)}`;
          let shareButtonHTML = '', copyButtonHTML = '', downloadButtonHTML = '', downloadExportPlaylistZipButtonHTML = '';
          
          let shareId = '';
          let shareName = encodeURIComponent(details.name);
          let artistIdForShare = currentView.filter_user_id || details.user_id || '';
          
          if (type === 'playlist') {
            typeText = `Playlist by ${details.creator}`;
            shareId = details.public_id;
            document.title = `${details.name} - ${details.creator} - PHP Music`;
            downloadButtonHTML = `<button class="btn btn-outline-light border-0 open-pd-btn" data-public-id="${details.public_id}" title="Download Playlist"><i class="bi bi-download"></i> <span class="d-none d-md-inline">Download</span></button>`;
            downloadExportPlaylistZipButtonHTML = `<a href="?action=export_playlist&id=${details.public_id}" target="_blank" class="btn btn-outline-light border-0" title="Export Playlist"><i class="bi bi-box-arrow-up"></i> <span class="d-none d-md-inline">Export</span></a>`;
            if (currentUser && currentUser.id !== details.user_id) {
              copyButtonHTML = `<button class="btn btn-outline-light border-0 copy-playlist-btn" data-public-id="${details.public_id}"><i class="bi bi-copy"></i> <span class="d-none d-md-inline">Copy Playlist</span></button>`;
            }
          } else if (type === 'mix') {
            typeText = `My Mix`;
            shareId = details.public_id;
            document.title = `${details.name} - PHP Music`;
            downloadButtonHTML = `<button class="btn btn-outline-light border-0 add-mix-to-playlist-btn" data-mix-id="${details.public_id}" title="Add to Playlist"><i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Add to Playlist</span></button>`;
          } else if (type === 'artist') {
            shareId = artistIdForShare;
            document.title = `${details.name} - Artist - PHP Music`;
          } else if (type === 'album') {
            shareId = shareName;
            document.title = `${details.name} - Album - PHP Music`;
          } else if (type === 'profile') {
            typeText = 'My Profile';
            document.title = `${details.name} - PHP Music`;
          } else {
             document.title = `${details.name} - PHP Music`;
          }
          
          if(type !== 'profile'){
            shareButtonHTML = `
              <button class="btn btn-outline-light border-0 share-view-btn" title="Share ${type}" data-share-type="${type}" data-share-id="${shareId}" data-share-name="${shareName}" data-artist-id="${artistIdForShare}">
                <i class="bi bi-share-fill"></i> <span class="d-none d-md-inline">Share</span>
              </button>`;
          }

          let followButtonHTML = '';
          if (type === 'artist' && details.is_user && currentUser && currentUser.id !== details.user_id) {
             const followText = details.is_following ? 'Unfollow' : 'Follow';
             const followClass = details.is_following ? 'btn-outline-light' : 'btn-danger';
             followButtonHTML = `<button class="btn ${followClass} border-0 follow-btn" data-user-id="${details.user_id}">${followText}</button>`;
          }

          const headerHTML = `
            <div class="view-details-header">
              <img src="${details.image_url}" alt="${details.name}" class="${(type === 'profile' || type === 'artist') ? 'profile-picture-lg' : ''}">
              <div class="view-details-header-info">
                <div class="type">${typeText}</div>
                <h2 class="name text-truncate text-truncate-width">${details.name}</h2>
                <div class="stats">${statsText}</div>
              </div>
              <div class="d-flex align-items-center gap-2">
                ${followButtonHTML}
                ${copyButtonHTML}
                ${shareButtonHTML}
                ${downloadButtonHTML}
                ${downloadExportPlaylistZipButtonHTML}
              </div>
            </div>`;
          contentArea.insertAdjacentHTML('afterbegin', headerHTML);

          if (type === 'artist') {
            const isUser = details.is_user;
            const hasPlaylists = details.playlists && details.playlists.length > 0;
            
            let tabsHTML = `
              <ul class="nav nav-tabs mt-4 mb-3" id="artistTabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="songs-tab" data-bs-toggle="tab" data-bs-target="#songs-pane" type="button" role="tab">All Songs</button>
                </li>
                ${isUser ? `
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="playlists-tab" data-bs-toggle="tab" data-bs-target="#playlists-pane" type="button" role="tab">Playlists</button>
                </li>` : ''}
              </ul>
              <div class="tab-content" id="artistTabsContent">
                <div class="tab-pane fade show active" id="songs-pane" role="tabpanel"></div>
                                ${isUser ? `
                <div class="tab-pane fade" id="playlists-pane" role="tabpanel">
                  ${hasPlaylists ? `
                  <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-4">
                    ${details.playlists.map(p => `
                      <div class="col">
                        <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-playlist="${encodeURIComponent(p.public_id)}" style="cursor: pointer;">
                          <img src="?action=get_image&id=${p.image_id || 0}" class="card-img-top rounded" alt="${p.name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
                          <div class="card-body px-0 py-2">
                            <h5 class="card-title fs-6 fw-normal text-truncate">${p.name}</h5>
                          </div>
                        </div>
                      </div>
                    `).join('')}
                  </div>
                  ` : `<div class="text-center p-5 text-secondary">No playlists found.</div>`}
                </div>` : ''}
              </div>
            `;
            contentArea.insertAdjacentHTML('beforeend', tabsHTML);
          }
        };

        const renderSongs = (songs, append = false) => {
          if (!append) {
            if (sortable) {
              sortable.destroy();
              sortable = null;
            }
            if (!contentArea.querySelector('.view-details-header') && currentView.type !== 'get_favorites' && currentView.type !== 'get_songs') {
              contentArea.innerHTML = '';
            } else if (currentView.type === 'get_favorites' && !contentArea.querySelector('.song-list')) {
            }
          }

          let targetContainer = document.getElementById('songs-pane') || contentArea;

          if (!songs || songs.length === 0) {
            if (currentPage === 1) {
              if (currentView.type === 'get_songs') {
                targetContainer.innerHTML = `
                  <div class="d-flex flex-column align-items-center justify-content-center text-secondary w-100" style="height: 60vh;">
                    <i class="bi bi-music-note-beamed mb-3" style="font-size: 5rem; opacity: 0.3;"></i>
                    <h3 class="fw-bold text-white">No songs detected, scan first</h3>
                    <p class="text-secondary mt-2">Open the sidebar menu and select <strong><i class="bi bi-hdd-stack-fill"></i> Scan All</strong></p>
                  </div>
                `;
              } else {
                targetContainer.insertAdjacentHTML('beforeend', `<div class="text-center p-5 text-secondary">No songs found.</div>`);
              }
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let songList = targetContainer.querySelector('.song-list');
          if (!songList) {
            songList = document.createElement('div');
            songList.className = 'song-list';
            const isHistory = currentView.type === 'get_history';
            const header = `<div class="song-list-header d-none d-md-grid" style="grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) ${isHistory ? 'minmax(0, 2fr)' : ''} 80px 40px;">
              <div></div><div>Title</div><div>Artist</div><div>Album</div>${isHistory ? '<div>Played</div>' : ''}<div>Time</div><div></div>
            </div>`;
            targetContainer.insertAdjacentHTML('beforeend', header);
            targetContainer.appendChild(songList);
          }
          
          const escapeAttr = (str) => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';

          const songsHTML = songs.map((song) => {
            const isNowPlaying = currentSong && currentSong.id === song.id;
            const isHistory = currentView.type === 'get_history';
            const playedAtHTML = isHistory ? `<div class="played-at-text">${timeAgo(song.played_at)}</div>` : '';
            return `
            <div class="song-item py-md-3 ${isNowPlaying ? 'now-playing' : ''} ${isHistory ? 'history-item' : ''}" 
              data-song-id="${song.id}" 
              data-is-favorite="${song.is_favorite == 1 ? '1' : '0'}"
              data-song-title="${escapeAttr(song.title)}"
              data-song-artist="${escapeAttr(song.artist)}"
              data-song-album="${escapeAttr(song.album)}"
              data-song-genre="${escapeAttr(song.genre)}"
              data-song-user-id="${song.user_id}">
              <div class="song-indicator-wrapper d-flex align-items-center justify-content-center">
                <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                <i class="bi bi-soundwave playing-icon"></i>
              </div>
              <div class="song-title-wrapper text-truncate"><div class="song-title text-truncate">${song.title}</div></div>
              <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">${song.artist}</div>
              <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}" data-userid="${song.user_id}">${song.album}</div>
              ${isHistory ? `<div class="d-none d-md-block">${playedAtHTML}</div>` : ''}
              <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
              <div class="song-more">
                <button class="more-btn" data-song-id="${song.id}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
              </div>
              <div class="song-artist-mobile d-md-none w-100">
                <span class="song-artist-name text-truncate flex-grow-1" style="min-width: 0;">${song.artist}</span>
                ${isHistory ? playedAtHTML : ''}
                <span class="song-duration-mobile flex-shrink-0">${formatTime(song.duration)}</span>
              </div>
            </div>
          `}).join('');
          
          songList.insertAdjacentHTML('beforeend', songsHTML);

          const modalQueueList = document.querySelector('#desktop-player-queue-list .song-list');
          if (modalQueueList && append) {
            modalQueueList.insertAdjacentHTML('beforeend', songsHTML);
          }

          const isSortableFavorites = currentView.type === 'get_favorites' && currentView.sort === 'manual_order';
          const isSortablePlaylist = currentView.type === 'playlist_songs' && currentView.sort === 'manual_order';

          if ((isSortableFavorites || isSortablePlaylist) && !sortable) {
            sortable = Sortable.create(songList, {
              animation: 150,
              ghostClass: 'ghost',
              delay: 250,
              delayOnTouchOnly: false,
              disabled: multiSelectMode,
              scroll: document.getElementById('main-content'),
              scrollSensitivity: 150,
              scrollSpeed: 30,
              onStart: function() {
                isDragging = true;
                if (holdTimer) clearTimeout(holdTimer);
              },
              onEnd: async (evt) => {
                isDragging = false;
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
          if (type === 'get_user_playlists' && !append && currentUser) {
            contentArea.innerHTML = `<div class="p-3 d-flex gap-2">
                <button class="btn btn-danger" id="create-new-playlist-btn"><i class="bi bi-plus-lg"></i> Create Playlist</button>
                <button class="btn btn-outline-light" id="import-playlist-btn"><i class="bi bi-box-arrow-in-down"></i> Import Playlist</button>
              </div>`;
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
              let name, subtext, imageId, dataType, dataValue, icon, publicId, imgClass = 'rounded', titleClass = '', subtextClass = '';
              let imgSrc = `?action=get_image&id=`;
              if (type === 'get_albums') {
                name = item.album;
                subtext = item.artist;
                imageId = item.id;
                dataType = 'album';
                dataValue = name;
                publicId = '';
              } else if (type === 'get_artists') {
                name = item.name;
                subtext = null;
                imageId = item.id;
                dataType = 'artist';
                dataValue = name;
                publicId = '';
                imgClass = 'rounded-circle';
                titleClass = 'text-center';
                subtextClass = 'text-center';
              } else if (type === 'get_following') {
                name = item.name;
                subtext = 'Artist';
                imageId = item.id;
                dataType = 'artist';
                dataValue = name;
                publicId = '';
                imgClass = 'rounded-circle';
                titleClass = 'text-center';
                subtextClass = 'text-center';
                imgSrc = `?action=get_profile_picture&id=`;
              } else if (type === 'get_user_playlists') {
                name = item.name;
                subtext = `${item.song_count} songs`;
                imageId = item.image_id;
                dataType = 'playlist';
                dataValue = item.public_id;
                publicId = item.public_id;
              } else {
                name = item.name || item;
                subtext = null;
                dataType = type.replace('get_','').slice(0, -1);
                dataValue = name;
                icon = 'bi-tag-fill';
                publicId = '';
              }
              
              const useridAttr = item.user_id ? `data-userid="${item.user_id}"` : (type === 'get_following' ? `data-userid="${item.id}"` : '');

              const moreButton = (type === 'get_user_playlists') ? `
                <button class="playlist-more-btn" data-public-id="${publicId}" data-name="${name}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>` : '';

              if (type === 'get_albums' || type === 'get_user_playlists' || type === 'get_artists' || type === 'get_following') {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-${dataType}="${encodeURIComponent(dataValue)}" ${useridAttr} style="cursor: pointer;">
                    ${moreButton}
                    <img src="${imgSrc}${imageId || 0}" class="card-img-top ${imgClass}" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate ${titleClass}">${name}</h5>
                      ${subtext ? `<p class="card-text small text-secondary text-truncate ${subtextClass}">${subtext}</p>` : ''}
                    </div>
                  </div>
                </div>`;
              } else {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0" data-${dataType}="${encodeURIComponent(dataValue)}" ${useridAttr} style="cursor: pointer;">
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
            contentArea.innerHTML = `<div class="text-center p-5 text-secondary">No results found.</div>`;
            return;
          }

          data.shelves.forEach(shelf => {
            let itemsHTML = '';

            if (shelf.type === 'top_result') {
              const song = shelf.items[0];
              const shelfHTML = `
                <div class="recommendation-shelf mb-4">
                  <div class="shelf-header">
                    <h3 class="shelf-title">${shelf.title}</h3>
                  </div>
                  <div class="card bg-transparent border-secondary d-flex flex-row align-items-center p-3 top-result-card" data-song-id="${song.id}" style="border-radius: 12px; cursor: pointer; max-width: 600px; transition: background 0.2s;" onmouseover="this.style.backgroundColor='var(--ytm-surface-2)'" onmouseout="this.style.backgroundColor='transparent'">
                    <img src="?action=get_image&id=${song.id}" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; margin-right: 1.5rem; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                    <div class="d-flex flex-column justify-content-center overflow-hidden w-100">
                      <h4 class="text-white text-truncate mb-1 fw-bold">${escapeHTML(song.title)}</h4>
                      <p class="text-secondary text-truncate mb-0">Song • ${escapeHTML(song.artist)}</p>
                    </div>
                    <button class="btn btn-danger rounded-circle ms-auto me-2 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; flex-shrink: 0;"><i class="bi bi-play-fill fs-3"></i></button>
                  </div>
                </div>
              `;
              contentArea.insertAdjacentHTML('beforeend', shelfHTML);
              return;
            }
            
            if (shelf.type === 'songs_list') {
              const shelfHTML = `
                <div class="recommendation-shelf mb-4">
                  <div class="shelf-header">
                    <h3 class="shelf-title">${shelf.title}</h3>
                  </div>
                  <div id="search-songs-pane"></div>
                </div>
              `;
              contentArea.insertAdjacentHTML('beforeend', shelfHTML);
              const targetPane = document.getElementById('search-songs-pane');

              let songList = document.createElement('div');
              songList.className = 'song-list';
              const header = `<div class="song-list-header d-none d-md-grid" style="grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) 80px 40px;">
                <div></div><div>Title</div><div>Artist</div><div>Album</div><div>Time</div><div></div>
              </div>`;
              targetPane.insertAdjacentHTML('beforeend', header);
              
              const escapeAttr = (str) => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';
              const songsHTML = shelf.items.map(song => {
                const isNowPlaying = currentSong && currentSong.id === song.id;
                return `
                <div class="song-item py-md-3 ${isNowPlaying ? 'now-playing' : ''}" 
                  data-song-id="${song.id}" 
                  data-is-favorite="${song.is_favorite == 1 ? '1' : '0'}"
                  data-song-title="${escapeAttr(song.title)}"
                  data-song-artist="${escapeAttr(song.artist)}"
                  data-song-album="${escapeAttr(song.album)}"
                  data-song-genre="${escapeAttr(song.genre)}"
                  data-song-user-id="${song.user_id}">
                  <div class="song-indicator-wrapper d-flex align-items-center justify-content-center">
                    <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                    <i class="bi bi-soundwave playing-icon"></i>
                  </div>
                  <div class="song-title-wrapper text-truncate"><div class="song-title text-truncate">${song.title}</div></div>
                  <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">${song.artist}</div>
                  <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}" data-userid="${song.user_id}">${song.album}</div>
                  <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
                  <div class="song-more">
                    <button class="more-btn" data-song-id="${song.id}">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                  </div>
                  <div class="song-artist-mobile d-md-none w-100">
                    <span class="song-artist-name text-truncate flex-grow-1" style="min-width: 0;">${song.artist}</span>
                    <span class="song-duration-mobile flex-shrink-0">${formatTime(song.duration)}</span>
                  </div>
                </div>
              `}).join('');
              songList.insertAdjacentHTML('beforeend', songsHTML);
              targetPane.appendChild(songList);
              return;
            }

            if (shelf.type === 'songs') {
              itemsHTML = shelf.items.map(song => `
                <div class="shelf-item" data-song-id="${song.id}">
                  <img src="?action=get_image&id=${song.id}" alt="${song.title}">
                  <div class="item-title">${song.title}</div>
                  <div class="item-subtitle" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">${song.artist}</div>
                </div>
              `).join('');
            } else if (shelf.type === 'albums') {
              itemsHTML = shelf.items.map(album => `
                <div class="shelf-item" data-album="${encodeURIComponent(album.album)}" data-userid="${album.user_id || ''}">
                  <img src="?action=get_image&id=${album.id || 0}" alt="${album.album}">
                  <div class="item-title">${album.album}</div>
                  <div class="item-subtitle">${album.artist}</div>
                </div>
              `).join('');
            } else if (shelf.type === 'playlists' || shelf.type === 'mixes') {
              itemsHTML = shelf.items.map(playlist => `
                <div class="shelf-item" data-${shelf.type === 'mixes' ? 'mix' : 'playlist'}="${encodeURIComponent(playlist.public_id)}">
                  <img src="?action=get_image&id=${playlist.image_id || 0}" alt="${playlist.name}">
                  <div class="item-title">${playlist.name}</div>
                  <div class="item-subtitle">by ${playlist.creator}</div>
                </div>
              `).join('');
            } else if (shelf.type === 'artists') {
              itemsHTML = shelf.items.map(artist => `
                <div class="shelf-item text-center" data-artist="${encodeURIComponent(artist.name)}" data-userid="${artist.id || ''}">
                  <img src="${artist.is_user ? `?action=get_profile_picture&id=${artist.id}` : `?action=get_image&id=${artist.id || 0}`}" class="rounded-circle" alt="${artist.name}" style="aspect-ratio: 1/1; object-fit: cover;">
                  <div class="item-title mt-2">${artist.name}</div>
                  <div class="item-subtitle text-uppercase small text-secondary">Artist</div>
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
          const isSortable = ['get_favorites', 'artist_songs', 'album_songs', 'genre_songs', 'user_profile', 'playlist_songs', 'get_history', 'get_albums', 'get_artists', 'get_user_playlists'].includes(viewType);
          
          if (isSortable) {
            let options = {};
            
            switch(viewType) {
              case 'genre_songs':
                options = {
                  'id_desc': 'Recently Added', 'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
                  'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)'
                };
                break;
              case 'artist_songs':
                 options = { 'album_asc': 'Album', 'title_asc': 'Title', 'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)'};
                 break;
              case 'album_songs':
                 options = { 'title_asc': 'Title', 'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)'};
                 break;
              case 'user_profile':
                  options = { 'id_desc': 'Recently Added', 'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
                  'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)'};
                  break;
              case 'get_favorites':
              case 'playlist_songs':
                options = { 
                  'manual_order': 'My Order', 'added_newest': 'Added Newest', 'added_oldest': 'Added Oldest',
                  'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album'
                };
                break;
              case 'get_history':
                options = { 'history_desc': 'Recently Played', 'title_asc': 'Title', 'artist_asc': 'Artist' };
                break;
              case 'get_albums':
                options = {
                  'album_asc': 'Title (A-Z)', 'album_desc': 'Title (Z-A)', 
                  'artist_asc': 'Artist (A-Z)', 'artist_desc': 'Artist (Z-A)',
                  'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)'
                };
                break;
              case 'get_artists':
                options = { 'name_asc': 'Name (A-Z)', 'name_desc': 'Name (Z-A)' };
                break;
              case 'get_user_playlists':
                options = {
                  'name_asc': 'Name (A-Z)', 'name_desc': 'Name (Z-A)',
                  'modified_desc': 'Date Modified (Newest)', 'modified_asc': 'Date Modified (Oldest)'
                };
                break;
            }

            sortSelect.innerHTML = Object.entries(options).map(([value, text]) => `<option value="${value}" ${currentView.sort === value ? 'selected' : ''}>${text}</option>`).join('');
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
          if (isLoadingMore || allContentloaded || currentView.type === 'search') return;
          if (!currentUser && (currentView.type === 'get_favorites' || currentView.type === 'get_history' || currentView.type === 'get_following')) return;
          
          isLoadingMore = true;
          showLoader(false);
          
          currentPage++;
          let data;
          const { type, param, sort, filter_user_id } = currentView;

          const params = new URLSearchParams({ page: currentPage, sort: sort });
          if (filter_user_id) {
            params.append('filter_user_id', filter_user_id);
          }

          switch (type) {
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
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
              const filterType = type.split('_')[0];
              const viewData = await fetchData(`?action=get_view_data&type=${filterType}&name=${param}&${params.toString()}`);
              if (viewData && viewData.songs) {
                renderSongs(viewData.songs, true);
                data = viewData.songs;
              }
              break;
            case 'playlist_songs':
              params.delete('sort');
              const playlistData = await fetchData(`?action=get_view_data&type=playlist&name=${param}&sort=${sort}&page=${currentPage}`);
              if (playlistData && playlistData.songs) {
                renderSongs(playlistData.songs, true);
                data = playlistData.songs;
              }
              break;
            case 'get_albums':
            case 'get_artists':
            case 'get_genres':
            case 'get_user_playlists':
            case 'get_following':
              data = await fetchData(`?action=${type}&${params.toString()}`);
              renderGrid(data, type, true);
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

        const updateActiveNavLink = (viewType) => {
          allNavLinks.forEach(l => l.classList.remove('active'));
          let activeLink;
          switch (viewType) {
            case 'artist_songs': activeLink = document.querySelector('.nav-link[data-view="get_artists"]'); break;
            case 'album_songs': activeLink = document.querySelector('.nav-link[data-view="get_albums"]'); break;
            case 'genre_songs': activeLink = document.querySelector('.nav-link[data-view="get_genres"]'); break;
            case 'playlist_songs': activeLink = document.querySelector('.nav-link[data-view="get_user_playlists"]'); break;
            default: activeLink = document.querySelector(`.nav-link[data-view="${viewType}"]`);
          }
          if (activeLink) {
            activeLink.classList.add('active');
          }
        };

        const loadView = async (viewConfig, pushHistory = true) => {
          if (pushHistory) {
            const isSameView = currentView.type === viewConfig.type &&
                               currentView.param === viewConfig.param &&
                               currentView.sort === viewConfig.sort &&
                               currentView.filter_user_id === viewConfig.filter_user_id;
            if (!isSameView) {
              history.pushState({ viewConfig }, "");
            }
          }

          selectedSongs.clear();
          updateMultiSelectUI();
          
          queueDirty = true;
          const modalQueueList = document.getElementById('desktop-player-queue-list');
          if (modalQueueList) modalQueueList.innerHTML = '';
          
          mainContent.scrollTop = 0;
          currentPage = 1;
          allContentloaded = false;
          isLoadingMore = false;
          showLoader();

          currentView = viewConfig;
          updateActiveNavLink(currentView.type);
          setupSortOptions(currentView.type);
          
          let data;
          let pageParams = new URLSearchParams({ sort: currentView.sort, page: 1 });
          if (currentView.filter_user_id) pageParams.append('filter_user_id', currentView.filter_user_id);
          
          switch (currentView.type) {
            case 'get_songs':
              updateContentTitle('All Songs', false);
              if (!cachedExploreData) {
                cachedExploreData = await fetchData(`?action=get_explore`);
              }
              if (cachedExploreData && cachedExploreData.shelves && cachedExploreData.shelves.length > 0) {
                renderRecommendations(cachedExploreData);
              } else {
                contentArea.innerHTML = '';
              }
              const allTracksHeader = `
                <div class="d-flex justify-content-between align-items-center mt-5 mb-3 px-2">
                  <h3 class="m-0 fw-bold">All Tracks</h3>
                  <div class="d-flex align-items-center gap-2">
                    <label for="sort-select-all" class="text-secondary small d-none d-sm-block">Sort by</label>
                    <select id="sort-select-all" class="form-select form-select-sm" style="width: auto;">
                      <option value="id_desc" ${currentView.sort === 'id_desc' ? 'selected' : ''}>Recently Added</option>
                      <option value="artist_asc" ${currentView.sort === 'artist_asc' ? 'selected' : ''}>Artist</option>
                      <option value="title_asc" ${currentView.sort === 'title_asc' ? 'selected' : ''}>Title</option>
                      <option value="album_asc" ${currentView.sort === 'album_asc' ? 'selected' : ''}>Album</option>
                      <option value="year_desc" ${currentView.sort === 'year_desc' ? 'selected' : ''}>Year (Newest)</option>
                      <option value="year_asc" ${currentView.sort === 'year_asc' ? 'selected' : ''}>Year (Oldest)</option>
                    </select>
                  </div>
                </div>
              `;
              contentArea.insertAdjacentHTML('beforeend', allTracksHeader);
              document.getElementById('sort-select-all').addEventListener('change', (e) => {
                loadView({ ...currentView, sort: e.target.value });
              });
              data = await fetchData(`?action=get_songs&${pageParams.toString()}`);
              renderSongs(data, true);
              document.getElementById('sort-controls').classList.add('d-none');
              break;
            case 'user_profile':
              updateContentTitle('', false);
              const profileViewData = await fetchData(`?action=get_view_data&type=profile&${pageParams.toString()}`);
              contentArea.innerHTML = '';
              if (profileViewData && profileViewData.details) {
                renderViewDetailsHeader(profileViewData.details, 'profile');
                renderSongs(profileViewData.songs, false);
                data = profileViewData.songs;
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your profile.</div>`;
              }
              break;
            case 'get_user_stats':
              updateContentTitle('My Statistics', !!currentUser);
              if (currentUser) {
                const userStatsData = await fetchData(`?action=get_user_stats`);
                if (userStatsData) renderUserStats(userStatsData);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your statistics.</div>`;
              }
              allContentloaded = true;
              break;
            case 'get_favorites':
              updateContentTitle('Favorites', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `<div class="p-3 d-flex gap-2">
                  <a href="?action=export_favorites" class="btn btn-outline-light" id="export-favorites-btn"><i class="bi bi-box-arrow-up"></i> Export Favorites</a>
                  <button class="btn btn-outline-light" id="import-favorites-btn"><i class="bi bi-box-arrow-in-down"></i> Import Favorites</button>
                </div>`;
                data = await fetchData(`?action=get_favorites&${pageParams.toString()}`);
                renderSongs(data, true);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your favorites.</div>`;
              }
              break;
            case 'get_history':
              updateContentTitle('History', !!currentUser);
              if (currentUser) {
                data = await fetchData(`?action=get_history&${pageParams.toString()}`);
                renderSongs(data, false);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your history.</div>`;
              }
              break;
            case 'get_following':
              updateContentTitle('Following', !!currentUser);
              if (currentUser) {
                pageParams.delete('page');
                data = await fetchData(`?action=get_following&${pageParams.toString()}`);
                renderGrid(data, 'get_following', false);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see who you follow.</div>`;
              }
              break;
            case 'get_recommendations':
              updateContentTitle('For You', !!currentUser);
              if (currentUser) {
                data = await fetchData(`?action=get_recommendations`);
                renderRecommendations(data);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to get recommendations.</div>`;
              }
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
              pageParams.delete('page');
              data = await fetchData(`?action=${currentView.type}&${pageParams.toString()}`);
              renderGrid(data, currentView.type, false);
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
            case 'playlist_songs':
            case 'mix_songs':
              const type = currentView.type.split('_')[0];
              const name_param = currentView.param;
              updateContentTitle('', false);
              const typeViewData = await fetchData(`?action=get_view_data&type=${type}&name=${name_param}&${pageParams.toString()}`);
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
              pageParams.delete('sort');
              pageParams.append('q', currentView.param);
              data = await fetchData(`?action=search&${pageParams.toString()}`);
              if (data && data.shelves) {
                renderRecommendations(data);
              }
              allContentloaded = true;
              break;
          }

          if (data && data.length < PAGE_SIZE && currentView.type !== 'search') {
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
          if (!currentUser) return;
          const played_at_iso = new Date().toISOString();
          const url = '?action=log_play';
          const data = JSON.stringify({ id: songId, played_at: played_at_iso });
          
          if (navigator.sendBeacon) {
            const blob = new Blob([data], { type: 'application/json; charset=UTF-8' });
            navigator.sendBeacon(url, blob);
          } else {
            fetch(url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: data,
              keepalive: true,
            }).catch(error => console.error('Beacon fallback fetch error for log_play:', error));
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
          
          if (docPipWindow) {
            docPipWindow.document.title = `${currentSong.title} • ${currentSong.artist}`;
          }

          updatePlayPauseIcons();
          updateFavoriteIcons(currentSong.is_favorite == 1);
          
          document.querySelectorAll('.song-item.now-playing').forEach(el => el.classList.remove('now-playing'));
          document.querySelectorAll(`.song-item[data-song-id="${currentSong.id}"]`).forEach(el => el.classList.add('now-playing'));

          if (typeof renderDesktopLyrics === 'function' && desktopPlayerModalEl && desktopPlayerModalEl.classList.contains('show')) {
            renderDesktopLyrics();
            
            const activeInModal = document.querySelector('#desktop-player-queue-list .song-item.now-playing');
            if (activeInModal) activeInModal.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }

          if (typeof window.renderPipLyrics === 'function') {
            window.renderPipLyrics();
          }

          if (pipCtx) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
              pipCtx.clearRect(0, 0, 500, 500);
              pipCtx.drawImage(img, 0, 0, 500, 500);
              
              const gradient = pipCtx.createLinearGradient(0, 350, 0, 500);
              gradient.addColorStop(0, 'transparent');
              gradient.addColorStop(1, 'rgba(0,0,0,0.9)');
              pipCtx.fillStyle = gradient;
              pipCtx.fillRect(0, 350, 500, 150);
              
              pipCtx.fillStyle = '#ffffff';
              pipCtx.font = 'bold 28px sans-serif';
              pipCtx.shadowColor = "rgba(0,0,0,0.8)";
              pipCtx.shadowBlur = 4;
              const safeTitle = currentSong.title.length > 28 ? currentSong.title.substring(0, 25) + '...' : currentSong.title;
              pipCtx.fillText(safeTitle, 20, 450);
              
              pipCtx.fillStyle = '#cccccc';
              pipCtx.font = '22px sans-serif';
              const safeArtist = currentSong.artist.length > 35 ? currentSong.artist.substring(0, 32) + '...' : currentSong.artist;
              pipCtx.fillText(safeArtist, 20, 480);
              
              pipVideo.play().catch(()=>{});
            };
            img.src = imageUrl;
          }
        };

        const updatePlayPauseIcons = (isBuffering = false) => {
          let icon = isPlaying ? ICONS.pause : ICONS.play;
          if (isBuffering) icon = ICONS.spinner;
          
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
        
        const showShareModal = (type, id, name, artistId) => {
          const decodedName = decodeURIComponent(name);
          let shareUrl = `${window.location.origin}${window.location.pathname}?share_type=${type}`;

          if (type === 'album') {
            shareUrl += `&album_name=${id}&artist_id=${artistId}`;
          } else {
            shareUrl += `&id=${id}`;
          }

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
            <li class="context-menu-item" data-action="export_playlist" data-public-id="${publicId}"><i class="bi bi-box-arrow-up"></i> Export Playlist</li>
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
          const { id: songId, title, artist, album, genre, user_id: songUserId, is_favorite } = songData;
          
          let menuItems = `
            <li class="context-menu-item" data-action="share_song" data-id="${songId}" data-name="${encodeURIComponent(title)}"><i class="bi bi-share-fill"></i> Share Song</li>
            <li class="context-menu-item" data-action="go_artist" data-name="${encodeURIComponent(artist)}" data-userid="${songUserId}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item" data-action="go_album" data-name="${encodeURIComponent(album)}" data-userid="${songUserId}"><i class="bi bi-disc-fill"></i> Go to Album</li>
            <li class="context-menu-item" data-action="show_all_genres"><i class="bi bi-tags-fill"></i> View All Genres</li>
            <li class="context-menu-item" data-action="download_song" data-id="${songId}"><i class="bi bi-download"></i> Download Song</li>
            <li class="context-menu-item" data-action="show_metadata" data-id="${songId}"><i class="bi bi-file-earmark-music"></i> View Metadata</li>
            <li class="context-menu-item" data-action="show_lyrics" data-id="${songId}"><i class="bi bi-music-note-list"></i> Show Lyrics</li>
            `;
          
          if (currentUser) {
            const favText = is_favorite ? "Remove from Favorites" : "Add to Favorites";
            const favIcon = is_favorite ? ICONS.heartFill : ICONS.heart;
            menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1">`;
            menuItems += `<li class="context-menu-item" data-action="toggle_favorite" data-id="${songId}">${favIcon} ${favText}</li>`;
            menuItems += `<li class="context-menu-item" data-action="add_to_playlist" data-id="${songId}"><i class="bi bi-plus-lg"></i> Add to Playlist</li>`;
            if (currentView.type === 'playlist_songs') {
              menuItems += `<li class="context-menu-item text-danger" data-action="remove_from_playlist" data-id="${songId}"><i class="bi bi-x-circle-fill"></i> Remove from Playlist</li>`;
            }
            if (currentUser.id === songUserId || currentUser.artist === 'Music Library') {
              menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1">`;
              menuItems += `<li class="context-menu-item" data-action="edit_metadata" data-id="${songId}" data-title="${encodeURIComponent(title || '')}" data-album="${encodeURIComponent(album || '')}" data-genre="${encodeURIComponent(genre || '')}"><i class="bi bi-pencil-fill"></i> Edit Info</li>`;
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
            genre: songItem.dataset.songGenre,
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
          let fetchedQueue = [];
          
          if (contextView.type === 'get_recommendations' || contextView.type === 'search') {
            const allShelfSongs = [...document.querySelectorAll('.shelf-item[data-song-id], .song-item[data-song-id], .top-result-card[data-song-id]')];
            const allSongIds = allShelfSongs.map(item => parseInt(item.dataset.songId));
            fetchedQueue = [...new Set(allSongIds)];
          } else {
            let viewTypeForIds = contextView.type;
            if (contextView.type === 'user_profile') viewTypeForIds = 'get_profile_songs';
            const allIds = await fetchData('?action=get_view_ids', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ view_type: viewTypeForIds, param: contextView.param, sort: contextView.sort, filter_user_id: contextView.filter_user_id || '' })
            });
            
            if (allIds && allIds.length > 0) {
                fetchedQueue = allIds.map(id => parseInt(id));
            }
          }
          
          if (!fetchedQueue || fetchedQueue.length === 0) {
            fetchedQueue = [startId];
          }
          
          originalQueue = fetchedQueue;
          queue = [...originalQueue];
          
          if (isShuffle) { isShuffle = false; toggleShuffle(); }
          
          queueIndex = queue.findIndex(id => id === startId);
          if (queueIndex === -1) { 
            queue.unshift(startId);
            originalQueue = [...queue];
            queueIndex = 0;
          }
          playSongById(startId);
        };

        const hideMobileSidebar = () => {
          const offcanvasEl = document.getElementById('main-nav-offcanvas');
          if (window.innerWidth < 768 && offcanvasEl) {
            const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
            if (offcanvas) offcanvas.hide();
          }
        };

        const setupHoldToSkip = (buttons, direction, defaultAction) => {
          buttons.forEach(btn => {
            let holdTimerSkip = null;
            let skipInterval = null;
            let isHolding = false;

            const startHoldSkip = (e) => {
              if (e.type === 'touchstart') e.preventDefault();
              isHolding = false;
              holdTimerSkip = setTimeout(() => {
                isHolding = true;
                skipInterval = setInterval(() => {
                  if (!audio.duration || !isFinite(audio.duration)) return;
                  const change = direction === 'next' ? 5 : -5;
                  audio.currentTime = Math.max(0, Math.min(audio.duration, audio.currentTime + change));
                }, 200);
              }, 400);
            };

            const endHoldSkip = (e) => {
              if (e.type === 'touchend' || e.type === 'touchcancel') e.preventDefault();
              if (holdTimerSkip) clearTimeout(holdTimerSkip);
              if (skipInterval) clearInterval(skipInterval);
              
              if (!isHolding) {
                defaultAction();
              }
              isHolding = false;
            };

            btn.addEventListener('mousedown', startHoldSkip);
            btn.addEventListener('mouseup', endHoldSkip);
            btn.addEventListener('mouseleave', () => {
              if (holdTimerSkip) clearTimeout(holdTimerSkip);
              if (skipInterval) clearInterval(skipInterval);
            });

            btn.addEventListener('touchstart', startHoldSkip, {passive: false});
            btn.addEventListener('touchend', endHoldSkip, {passive: false});
            btn.addEventListener('touchcancel', endHoldSkip, {passive: false});
            
            btn.addEventListener('keydown', (e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                defaultAction();
              }
            });
          });
        };
        
        allNavLinks.forEach(link => {
          if (link.getAttribute('data-bs-toggle') === 'modal' || ['logout-btn', 'clear-cache-btn', 'fullscreen-btn', 'install-pwa-btn', 'check-update-btn'].includes(link.id)) return;
          link.addEventListener('click', e => {
            e.preventDefault();
            const navLink = e.currentTarget;
            
            const viewType = navLink.dataset.view;
            let sort = 'artist_asc';
            if (['get_favorites', 'playlist_songs'].includes(viewType)) sort = 'manual_order';
            if (viewType === 'get_user_playlists') sort = 'modified_desc';
            if (viewType === 'get_albums') sort = 'album_asc';
            if (viewType === 'get_artists') sort = 'name_asc';
            if (viewType === 'user_profile') sort = 'id_desc';
            if (viewType === 'get_history') sort = 'history_desc';
            if (viewType === 'get_songs') sort = 'id_desc';

            loadView({ type: viewType, param: '', sort: sort, filter_user_id: '' });
            hideMobileSidebar();
          });
        });

        const performSearch = (query) => {
          if (query.trim() !== '') {
            document.getElementById('search-dropdown-desktop').classList.add('d-none');
            document.getElementById('search-dropdown-mobile').classList.add('d-none');
            searchInputDesktop.value = query;
            searchInputMobile.value = query;
            loadView({ type: 'search', param: query.trim(), sort: 'artist_asc', filter_user_id: '' });
          }
        };

        const renderSearchDropdown = (dropdownEl, data) => {
          dropdownEl.innerHTML = '';
          if (!data || !data.shelves || data.shelves.length === 0) {
            dropdownEl.innerHTML = '<div class="p-3 text-secondary text-center small">No results found</div>';
            dropdownEl.classList.remove('d-none');
            return;
          }

          let html = '';
          data.shelves.forEach(shelf => {
            if (shelf.type === 'top_result') {
                const song = shelf.items[0];
                html += `<div class="search-dropdown-header text-danger">Top Result</div>
                  <div class="search-dropdown-item top-result-item" data-id="${song.id}">
                    <img src="?action=get_image&id=${song.id}" class="search-dropdown-img" style="width: 50px; height: 50px; border-radius: 50%;">
                    <div class="search-dropdown-text">
                      <div class="search-dropdown-title fw-bold" style="font-size: 1rem;">${escapeHTML(song.title)}</div>
                      <div class="search-dropdown-subtitle">Song • ${escapeHTML(song.artist)}</div>
                    </div>
                  </div>`;
            } else if (shelf.type === 'songs_list') {
              html += `<div class="search-dropdown-header">Songs</div>`;
              shelf.items.slice(0, 4).forEach(song => {
                html += `<div class="search-dropdown-item song-dropdown-item" data-id="${song.id}">
                    <img src="?action=get_image&id=${song.id}" class="search-dropdown-img">
                    <div class="search-dropdown-text">
                      <div class="search-dropdown-title">${escapeHTML(song.title)}</div>
                      <div class="search-dropdown-subtitle">${escapeHTML(song.artist)}</div>
                    </div>
                  </div>`;
              });
            } else if (shelf.type === 'artists') {
              html += `<div class="search-dropdown-header">Artists</div>`;
              shelf.items.slice(0, 3).forEach(artist => {
                const img = artist.is_user ? `?action=get_profile_picture&id=${artist.id}` : `?action=get_image&id=${artist.id || 0}`;
                html += `<div class="search-dropdown-item artist-dropdown-item" data-artist="${encodeURIComponent(artist.name)}" data-userid="${artist.id || ''}">
                    <img src="${img}" class="search-dropdown-img" style="border-radius: 50%;">
                    <div class="search-dropdown-text">
                      <div class="search-dropdown-title">${escapeHTML(artist.name)}</div>
                      <div class="search-dropdown-subtitle">Artist</div>
                    </div>
                  </div>`;
              });
            } else if (shelf.type === 'albums') {
              html += `<div class="search-dropdown-header">Albums</div>`;
              shelf.items.slice(0, 2).forEach(album => {
                html += `<div class="search-dropdown-item album-dropdown-item" data-album="${encodeURIComponent(album.album)}" data-userid="${album.user_id || ''}">
                    <img src="?action=get_image&id=${album.id || 0}" class="search-dropdown-img">
                    <div class="search-dropdown-text">
                      <div class="search-dropdown-title">${escapeHTML(album.album)}</div>
                      <div class="search-dropdown-subtitle">${escapeHTML(album.artist)}</div>
                    </div>
                  </div>`;
              });
            }
          });
          
          dropdownEl.innerHTML = html;
          dropdownEl.classList.remove('d-none');
        };

        let searchTimeout;
        const liveSearchHandler = (e) => {
          clearTimeout(searchTimeout);
          const query = e.target.value;
          const isDesktop = e.target === searchInputDesktop;
          const targetDropdown = isDesktop ? document.getElementById('search-dropdown-desktop') : document.getElementById('search-dropdown-mobile');
          
          if (isDesktop) searchInputMobile.value = query;
          else searchInputDesktop.value = query;

          if (query.trim() === '') {
            targetDropdown.classList.add('d-none');
            if (currentView.type === 'search') loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' });
            return;
          }

          searchTimeout = setTimeout(async () => {
            const data = await fetchData(`?action=search&q=${encodeURIComponent(query.trim())}`);
            
            renderSearchDropdown(targetDropdown, data);
            
            currentView = { type: 'search', param: query.trim(), sort: 'artist_asc', filter_user_id: '' };
            updateContentTitle(`Search: "${query.trim()}"`);
            renderRecommendations(data);
            allContentloaded = true;
          }, 350);
        };

        searchInputDesktop.addEventListener('input', liveSearchHandler);
        searchInputMobile.addEventListener('input', liveSearchHandler);

        const instantSearchHandler = (e) => { 
          if (e.key === 'Enter') {
            clearTimeout(searchTimeout);
            performSearch(e.target.value);
            e.target.blur(); 
          }
        };
        
        searchInputDesktop.addEventListener('keyup', instantSearchHandler);
        searchInputMobile.addEventListener('keyup', instantSearchHandler);
        searchBtnDesktop.addEventListener('click', () => { clearTimeout(searchTimeout); performSearch(searchInputDesktop.value); });
        searchBtnMobile.addEventListener('click', () => { clearTimeout(searchTimeout); performSearch(searchInputMobile.value); });

        document.addEventListener('click', e => {
          if (!e.target.closest('.search-bar')) {
            document.getElementById('search-dropdown-desktop').classList.add('d-none');
            document.getElementById('search-dropdown-mobile').classList.add('d-none');
          }

          const dropItem = e.target.closest('.search-dropdown-item');
          if (dropItem) {
            document.getElementById('search-dropdown-desktop').classList.add('d-none');
            document.getElementById('search-dropdown-mobile').classList.add('d-none');

            if (dropItem.classList.contains('song-dropdown-item') || dropItem.classList.contains('top-result-item')) {
              setQueueAndPlay(parseInt(dropItem.dataset.id));
            } else if (dropItem.classList.contains('artist-dropdown-item')) {
              loadView({ type: 'artist_songs', param: dropItem.dataset.artist, sort: 'album_asc', filter_user_id: dropItem.dataset.userid });
            } else if (dropItem.classList.contains('album-dropdown-item')) {
              loadView({ type: 'album_songs', param: dropItem.dataset.album, sort: 'title_asc', filter_user_id: dropItem.dataset.userid });
            }
          }
        });

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
              loadView({type: 'get_history', param: '', sort: 'history_desc', filter_user_id: ''});
            }
          }
        });
        
        playerElements.playPauseBtn.forEach(btn => btn.addEventListener('click', togglePlayPause));
        setupHoldToSkip(playerElements.prevBtn, 'prev', playPrev);
        setupHoldToSkip(playerElements.nextBtn, 'next', playNext);
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

        const closeOpenModals = () => {
          [playerModalEl, genresModalEl, artistsModalEl, document.getElementById('desktop-player-modal')].forEach(el => {
            if (!el) return;
            const instance = bootstrap.Modal.getInstance(el);
            if (instance && instance._isShown) instance.hide();
          });
        };

        playerElements.artist.forEach(el => {
          el.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!currentSong) return;
            const artistRaw = currentSong.artist;
            const userId = currentSong.user_id || '';
            const artistsList = artistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');
            if (artistsList.length > 1) {
              if (artistsModalBody) {
                artistsModalBody.innerHTML = `
                  <div class="list-group list-group-flush rounded">
                    ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userId}">${a}</button>`).join('')}
                  </div>
                `;
                if (artistsModal) artistsModal.show();
              }
            } else {
              closeOpenModals();
              loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId });
            }
          });
        });

        if (artistsModalBody) {
          artistsModalBody.addEventListener('click', e => {
            const btn = e.target.closest('.artist-modal-item');
            if (!btn) return;
            const artistName = decodeURIComponent(btn.dataset.artist);
            const userId = btn.dataset.userid;
            closeOpenModals();
            setTimeout(() => {
              loadView({ type: 'artist_songs', param: artistName, sort: 'album_asc', filter_user_id: userId });
              hideMobileSidebar();
            }, 200);
          });
        }
        
        contentArea.addEventListener('click', async e => {
          const target = e.target;

          if (multiSelectMode) {
            const songItem = target.closest('.song-item');
            if (songItem) {
              e.preventDefault();
              e.stopPropagation();
              toggleSongSelection(songItem);
              return;
            }
          }
          
          const followBtn = target.closest('.follow-btn');
          if (followBtn) {
            e.stopPropagation();
            const userId = followBtn.dataset.userId;
            fetchData('?action=toggle_follow', {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ following_id: userId })
            }).then(res => {
              if (res && res.status === 'followed') {
                followBtn.textContent = 'Unfollow';
                followBtn.classList.remove('btn-danger');
                followBtn.classList.add('btn-outline-light');
              } else if (res && res.status === 'unfollowed') {
                followBtn.textContent = 'Follow';
                followBtn.classList.remove('btn-outline-light');
                followBtn.classList.add('btn-danger');
              } else {
                showToast(res.message || 'Error toggling follow', 'error');
              }
            });
            return;
          }
          const addMixBtn = target.closest('.add-mix-to-playlist-btn');
          if (addMixBtn) {
            e.stopPropagation();
            mixIdForPlaylist = addMixBtn.dataset.mixId;
            songIdForPlaylist = null;
            const playlists = await fetchData(`?action=get_user_playlists&sort=modified_desc`);
            if (playlists && playlists.length > 0) {
              addToPlaylistModalBody.innerHTML = `
                <div class="list-group list-group-flush">
                  ${playlists.map(p => `
                    <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 add-to-playlist-item my-1 p-2 rounded bg-transparent text-white border border-secondary" data-playlist-id="${p.id}">
                      <img src="?action=get_image&id=${p.image_id || 0}" class="rounded" style="width: 48px; height: 48px; object-fit: cover; background-color: var(--ytm-surface-2);">
                      <div class="d-flex flex-column flex-grow-1 text-start overflow-hidden">
                        <span class="text-truncate fw-medium">${p.name}</span>
                        <span class="small text-secondary">${p.song_count} songs</span>
                      </div>
                      <i class="bi bi-plus-circle fs-5"></i>
                    </button>
                  `).join('')}
                </div>
              `;
            } else {
              addToPlaylistModalBody.innerHTML = `<p class="text-secondary text-center p-4">No playlists found. Create one first!</p>`;
            }
            addToPlaylistModal.show();
            return;
          }
          const openPdBtn = target.closest('.open-pd-btn');
          if (openPdBtn) {
            e.stopPropagation();
            const publicId = openPdBtn.dataset.publicId;
            pdPlaylistIdInput.value = publicId;
            const downloaderModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('playlist-downloader-modal'));
            downloaderModal.show();
            pdLoadForm.dispatchEvent(new Event('submit'));
            return;
          }
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
            const { shareType, shareId, shareName, artistId } = shareBtn.dataset;
            showShareModal(shareType, shareId, shareName, artistId);
            return;
          }
          const copyBtn = target.closest('.copy-playlist-btn');
          if (copyBtn) {
            e.stopPropagation();
            if (confirm('Are you sure you want to copy this playlist?')) {
              const formData = new FormData();
              formData.append('public_id', copyBtn.dataset.publicId);
              fetch('?action=copy_playlist', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                  showToast(data.message, data.status);
                  if (data.status === 'success') loadView({ type: 'get_user_playlists', sort: 'name_asc' });
                });
            }
            return;
          }
          const exportFavoritesBtn = target.closest('#export-favorites-btn');
          if (exportFavoritesBtn) {
            return;
          }
          const importFavoritesBtn = target.closest('#import-favorites-btn');
          if (importFavoritesBtn) {
            importFavoritesModal.show();
            return;
          }
          const createPlaylistBtn = target.closest('#create-new-playlist-btn');
          if (createPlaylistBtn) {
            createPlaylistModal.show();
            return;
          }
          const importPlaylistBtn = target.closest('#import-playlist-btn');
          if (importPlaylistBtn) {
            importPlaylistModal.show();
            return;
          }
          const songArtistEl = target.closest('.song-artist, .song-artist-name, .item-subtitle[data-artist]');
          if (songArtistEl) {
            e.stopPropagation();
            const artistRaw = songArtistEl.dataset.artist ? decodeURIComponent(songArtistEl.dataset.artist) : songArtistEl.textContent.trim();
            const userId = songArtistEl.dataset.userid || '';
            const artistsList = artistRaw.split(/\s*(?:,|&|\/)\s*/).filter(a => a.trim() !== '');
            
            if (artistsList.length > 1) {
              if (artistsModalBody) {
                artistsModalBody.innerHTML = `
                  <div class="list-group list-group-flush rounded">
                    ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userId}">${a}</button>`).join('')}
                  </div>
                `;
                if (artistsModal) artistsModal.show();
              }
            } else {
              loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId });
            }
            return;
          }
          const songAlbumEl = target.closest('.song-album, .shelf-item[data-album]');
          if (songAlbumEl) {
            e.stopPropagation();
            const songItem = target.closest('.song-item, .shelf-item');
            loadView({ type: 'album_songs', param: songAlbumEl.dataset.album, sort: 'title_asc', filter_user_id: songItem ? songItem.dataset.userid || songItem.dataset.songUserId || '' : '' });
            return;
          }
          const cardEl = target.closest('.card, .shelf-item[data-playlist], .shelf-item[data-mix], .shelf-item[data-artist]');
          if (cardEl && !target.closest('.playlist-more-btn')) {
            let viewType, param, sort;
            let filterUserId = cardEl.dataset.userid || '';
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
            } else if (cardEl.dataset.mix) {
              viewType = 'mix_songs';
              param = cardEl.dataset.mix;
              sort = 'manual_order';
            }
            
            if (viewType) {
              loadView({ type: viewType, param: param, sort: sort, filter_user_id: filterUserId });
              return;
            }
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

            loadView({ type: viewType, param: viewParam || '', sort: sort, filter_user_id: '' });
            return;
          }

          const songItem = target.closest('.song-item, .shelf-item[data-song-id], .top-result-card');
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
        
        contextMenu.addEventListener('click', async e => {
          const item = e.target.closest('.context-menu-item');
          if (!item) return;
          const { action, name, id, publicId, userid, title, album, genre } = item.dataset;
          contextMenu.style.display = 'none';

          switch (action) {
            case 'close_menu':
              break;
            case 'share_song':
              showShareModal('song', id, name);
              break;
            case 'go_artist':
              closeOpenModals();
              const artistRaw = decodeURIComponent(name);
              const artistsList = artistRaw.split(/\s*(?:,|&|\/)\s*/).filter(a => a.trim() !== '');
              if (artistsList.length > 1) {
                if (artistsModalBody) {
                  artistsModalBody.innerHTML = `
                    <div class="list-group list-group-flush rounded">
                      ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userid}">${a}</button>`).join('')}
                    </div>
                  `;
                  if (artistsModal) artistsModal.show();
                }
              } else {
                loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userid || '' });
                hideMobileSidebar();
              }
              break;
            case 'go_album':
              closeOpenModals();
              loadView({ type: 'album_songs', param: name, sort: 'title_asc', filter_user_id: userid || '' });
              hideMobileSidebar();
              break;
            case 'show_all_genres':
              showAllGenresModal();
              break;
            case 'toggle_favorite':
              toggleFavorite(parseInt(id));
              break;
            case 'add_to_playlist':
              songIdForPlaylist = [parseInt(id)];
              mixIdForPlaylist = null;
              const playlists = await fetchData(`?action=get_user_playlists&song_id=${parseInt(id)}&sort=modified_desc`);
              if (playlists && playlists.length > 0) {
                addToPlaylistModalBody.innerHTML = `
                  <div class="list-group list-group-flush">
                    ${playlists.map(p => `
                      <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 add-to-playlist-item my-1 p-2 rounded ${p.is_added ? 'bg-secondary text-white border-0 opacity-75' : 'bg-transparent text-white border border-secondary'}" data-playlist-id="${p.id}" ${p.is_added ? 'disabled' : ''}>
                        <img src="?action=get_image&id=${p.image_id || 0}" class="rounded" style="width: 48px; height: 48px; object-fit: cover; background-color: var(--ytm-surface-2);">
                        <div class="d-flex flex-column flex-grow-1 text-start overflow-hidden">
                          <span class="text-truncate fw-medium">${p.name}</span>
                          <span class="small ${p.is_added ? 'text-white-50' : 'text-secondary'}">${p.song_count} songs</span>
                        </div>
                        ${p.is_added ? '<span class="badge bg-success">Already Added</span>' : '<i class="bi bi-plus-circle fs-5"></i>'}
                      </button>
                    `).join('')}
                  </div>
                `;
              } else {
                addToPlaylistModalBody.innerHTML = `<p class="text-secondary text-center p-4">No playlists found. Create one first!</p>`;
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
            case 'export_playlist':
              window.location.href = `?action=export_playlist&id=${publicId}`;
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
            case 'edit_metadata':
              const songItemEl = document.querySelector(`.song-item[data-song-id="${id}"]`);
              const songArtist = songItemEl ? decodeURIComponent(songItemEl.dataset.songArtist) : '';
              document.getElementById('edit-metadata-id').value = id;
              document.getElementById('edit-metadata-title').value = decodeURIComponent(title || '');
              document.getElementById('edit-metadata-artist').value = songArtist;
              document.getElementById('edit-metadata-album').value = decodeURIComponent(album || '');
              document.getElementById('edit-metadata-genre').value = decodeURIComponent(genre || '');
              document.getElementById('edit-metadata-cover-preview').src = `?action=get_image&id=${id}&v=${Date.now()}`;
              document.getElementById('edit-metadata-cover').value = '';
              const metaSongDataToEdit = await fetchData(`?action=get_song_data&id=${id}`);
              document.getElementById('edit-metadata-lyrics').value = metaSongDataToEdit ? (metaSongDataToEdit.lyrics || '') : '';
              if (editMetadataModal) editMetadataModal.show();
              break;
            case 'show_metadata':
              const songIdForMeta = parseInt(id);
              const metaSongData = await fetchData(`?action=get_song_data&id=${songIdForMeta}`);
              if (metaSongData && metadataModalBody) {
                metadataModalBody.innerHTML = `
                  <ul class="list-group list-group-flush">
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Title:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.title || 'N/A'}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Artist:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.artist || 'N/A'}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Album:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.album || 'N/A'}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Genre:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.genre || 'N/A'}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Year:</strong> <span>${metaSongData.year || 'N/A'}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Duration:</strong> <span>${formatTime(metaSongData.duration)}</span></li>
                    <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Bitrate:</strong> <span>${metaSongData.bitrate ? Math.round(metaSongData.bitrate / 1000) + ' kbps' : 'N/A'}</span></li>
                  </ul>`;
                metadataModal.show();
              }
              break;
            case 'show_lyrics':
              const lyricsSongData = await fetchData(`?action=get_song_data&id=${id}`);
              if (lyricsSongData) {
                const lyricsTitleEl = document.getElementById('lyrics-modal-title');
                const lyricsBodyEl = document.getElementById('lyrics-modal-body');
                
                if (lyricsTitleEl) lyricsTitleEl.textContent = lyricsSongData.title;
                
                if (lyricsBodyEl) {
                  const lrcData = parseLRC(lyricsSongData.lyrics);
                  if (lrcData.length > 0) {
                    currentLrcData = lrcData;
                    currentLrcSongId = parseInt(id);
                    currentLyricIndex = -1;
                    
                    lyricsBodyEl.innerHTML = `<div id="synced-lyrics-container">` + 
                      lrcData.map((line, idx) => `<div class="lyric-line" data-index="${idx}" data-time="${line.time}">${escapeHTML(line.text)}</div>`).join('') +
                      `</div>`;
                  } else {
                    currentLrcData = null;
                    currentLrcSongId = null;
                    lyricsBodyEl.innerHTML = lyricsSongData.lyrics ? `<pre style="white-space: pre-wrap; font-family: 'Roboto', sans-serif;">${escapeHTML(lyricsSongData.lyrics)}</pre>` : '<p class="text-center text-secondary">No lyrics available.</p>';
                  }
                }
                const lyricsModalEl = document.getElementById('lyrics-modal');
                if (lyricsModalEl) {
                  bootstrap.Modal.getOrCreateInstance(lyricsModalEl).show();
                }
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
        
        const lyricsModalBody = document.getElementById('lyrics-modal-body');
        if (lyricsModalBody) {
          lyricsModalBody.addEventListener('click', (e) => {
            const line = e.target.closest('.lyric-line');
            if (line && currentSong && currentSong.id === currentLrcSongId && audio) {
              audio.currentTime = parseFloat(line.dataset.time);
            }
          });
        }

        if (genresModalBody) {
          genresModalBody.addEventListener('click', e => {
            const target = e.target.closest('.genre-modal-btn');
            if (!target) return;
            
            const genreName = target.dataset.genre;
            closeOpenModals();
            
            setTimeout(() => {
              loadView({ type: 'genre_songs', param: genreName, sort: 'artist_asc', filter_user_id: '' });
              hideMobileSidebar();
            }, 200);
          });
        }
        
        if (addToPlaylistModalBody) {
          addToPlaylistModalBody.addEventListener('click', async e => {
            const item = e.target.closest('.add-to-playlist-item');
            if (!item || item.disabled) return;
            const playlistId = item.dataset.playlistId;
            let result;
            if (mixIdForPlaylist) {
              result = await fetchData('?action=add_mix_to_playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ playlist_id: playlistId, mix_id: mixIdForPlaylist })
              });
            } else if (songIdForPlaylist) {
              result = await fetchData('?action=add_to_playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ playlist_id: playlistId, song_id: songIdForPlaylist })
              });
            }
            if (result) {
              showToast(result.message, result.status === 'success' ? 'success' : 'info');
              addToPlaylistModal.hide();
              if (currentView.type === 'get_user_playlists') {
                loadView(currentView);
              }
              songIdForPlaylist = null;
              mixIdForPlaylist = null;
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

          const lyricsModalEl = document.getElementById('lyrics-modal');
          const isMobileLyricsOpen = lyricsModalEl && lyricsModalEl.classList.contains('show');
          const isDesktopLyricsOpen = desktopPlayerModalEl && desktopPlayerModalEl.classList.contains('show');

          if (currentLrcData && currentSong && currentSong.id === currentLrcSongId && (isMobileLyricsOpen || isDesktopLyricsOpen)) {
            let activeIndex = -1;
            for (let i = 0; i < currentLrcData.length; i++) {
              if (currentTime >= currentLrcData[i].time - 0.3) {
                activeIndex = i;
              } else {
                break;
              }
            }

            if (activeIndex !== -1 && currentLyricIndex !== activeIndex) {
              currentLyricIndex = activeIndex;
              
              if (isMobileLyricsOpen) {
                const container = document.getElementById('synced-lyrics-container');
                if (container) {
                  const lines = container.querySelectorAll('.lyric-line');
                  lines.forEach(l => l.classList.remove('active'));
                  const activeLine = lines[activeIndex];
                  if (activeLine) {
                    activeLine.classList.add('active');
                    activeLine.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  }
                }
              }

              if (isDesktopLyricsOpen) {
                const container = document.getElementById('desktop-synced-lyrics');
                if (container) {
                  const lines = container.querySelectorAll('.lyric-line');
                  lines.forEach(l => l.classList.remove('active'));
                  const activeLine = lines[activeIndex];
                  if (activeLine) {
                    activeLine.classList.add('active');
                    activeLine.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  }
                }
              }
            }
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

        const dpQueuePane = document.getElementById('dp-queue-pane');
        if (dpQueuePane) {
          dpQueuePane.addEventListener('scroll', () => {
            if (dpQueuePane.scrollTop + dpQueuePane.clientHeight >= dpQueuePane.scrollHeight - 300) {
              loadMoreContent();
            }
          });
        }

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault();
          deferredInstallPrompt = e;
        });

        if (installPwaBtn) {
          installPwaBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!deferredInstallPrompt) {
              showToast('To install, use your browser menu (Add to Home Screen).', 'info');
              return;
            }
            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
          });
        }
        
        if (checkUpdateBtn) {
          checkUpdateBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            hideMobileSidebar();

            if (updateModal && updateModalBody) {
              updateModalBody.innerHTML = `
                <div class="spinner-border text-secondary" role="status" style="width: 3rem; height: 3rem; border-width: 0.3em;"></div>
                <p class="mt-3 text-secondary">Analyzing and comparing entire codebase...</p>
              `;
              updateModal.show();
              
              try {
                const response = await fetch('?action=check_update_code', { cache: 'no-store' });
                const result = await response.json();
                
                if (result.status === 'success') {
                  if (result.update_available) {
                    updateModalBody.innerHTML = `
                      <i class="bi bi-info-circle-fill text-warning" style="font-size: 3.5rem;"></i>
                      <h4 class="mt-3">Code Modification Detected!</h4>
                      <p class="text-secondary mb-4">Your current code does not strictly match the latest source code on GitHub.</p>
                      <a href="https://github.com/HirotakaDango/PHP-Music" target="_blank" class="btn btn-warning w-100"><i class="bi bi-github"></i> Download Latest Code</a>
                    `;
                  } else {
                    updateModalBody.innerHTML = `
                      <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                      <h4 class="mt-3">Code is Identical!</h4>
                      <p class="text-secondary mb-0">Your codebase perfectly matches the latest version on GitHub.</p>
                    `;
                  }
                } else {
                  throw new Error(result.message || 'Check failed.');
                }
              } catch (error) {
                updateModalBody.innerHTML = `
                  <i class="bi bi-x-circle-fill text-danger" style="font-size: 3.5rem;"></i>
                  <h4 class="mt-3">Comparison Failed</h4>
                  <p class="text-secondary mb-0">${error.message}</p>
                `;
              }
            }
          });
        }

        let docPipWindow = null;

        if (pipBtnDesktop) {
          pipBtnDesktop.addEventListener('click', async () => {
            if ('documentPictureInPicture' in window) {
              if (docPipWindow) {
                docPipWindow.close();
                return;
              }
              try {
                docPipWindow = await window.documentPictureInPicture.requestWindow({
                  width: 395,
                  height: 780
                });

                docPipWindow.addEventListener('resize', () => {
                  if (docPipWindow.innerWidth !== 395 || docPipWindow.innerHeight !== 780) {
                    docPipWindow.resizeTo(395, 780);
                  }
                });

                [...document.styleSheets].forEach(styleSheet => {
                  try {
                    const cssRules = [...styleSheet.cssRules].map(rule => rule.cssText).join('');
                    const style = document.createElement('style');
                    style.textContent = cssRules;
                    docPipWindow.document.head.appendChild(style);
                  } catch (e) {
                    if (styleSheet.href) {
                      const link = document.createElement('link');
                      link.rel = 'stylesheet';
                      link.href = styleSheet.href;
                      docPipWindow.document.head.appendChild(link);
                    }
                  }
                });

                const iconLink = document.createElement('link');
                iconLink.rel = 'stylesheet';
                iconLink.href = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
                docPipWindow.document.head.appendChild(iconLink);

                const extraStyle = document.createElement('style');
                extraStyle.textContent = `
                  body { margin: 0; font-family: 'Roboto', sans-serif; background: var(--ytm-bg); color: var(--ytm-primary-text); overflow: hidden; }
                  .player-btn { transition: color 0.2s, transform 0.1s; display: flex; align-items: center; justify-content: center; cursor: pointer; }
                  .player-btn:hover { color: var(--ytm-primary-text) !important; }
                  .play-btn:hover { transform: scale(1.1); background-color: #383838 !important; }
                  .progress-bar-fg::after { display: none !important; }
                  .progress-bar-container:hover .progress-bar-fg { background-color: var(--ytm-accent) !important; }
                  .progress-bar-container:hover .slide-range::-webkit-slider-thumb { opacity: 1; }
                  .progress-bar-container:hover .slide-range::-moz-range-thumb { opacity: 1; }
                  .title, .artist { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                  .player-btn.active { color: var(--ytm-accent) !important; }
                  .pip-tabs { display: flex; border-bottom: 1px solid var(--ytm-surface-2); }
                  .pip-tab-btn { flex: 1; background: none; border: none; color: var(--ytm-secondary-text); padding: 1rem; cursor: pointer; font-weight: 500; font-size: 1rem; transition: color 0.2s; border-bottom: 2px solid transparent; }
                  .pip-tab-btn:hover { color: var(--ytm-primary-text); }
                  .pip-tab-btn.active { color: var(--ytm-primary-text); border-bottom-color: var(--ytm-accent); }
                  .pip-pane { display: none; flex-direction: column; height: calc(100vh - 54px); }
                  .pip-pane.active { display: flex; }
                  #pip-lyrics-container { flex-grow: 1; overflow-y: auto; text-align: center; padding: 1rem 1rem 50% 1rem; scrollbar-width: none; }
                  #pip-lyrics-container::-webkit-scrollbar { display: none; }
                  .pip-lyric-line { font-size: 1.15rem; color: var(--ytm-secondary-text); margin-bottom: 0.75rem; transition: all 0.3s ease; cursor: pointer; min-height: 1.5rem; }
                  .pip-lyric-line:hover { color: #ffffff; }
                  .pip-lyric-line.active { color: var(--ytm-accent); transform: scale(1.1); font-weight: 700; }
                  
                  .slide-range { -webkit-appearance: none; appearance: none; width: 100%; background: transparent; height: 14px; position: absolute; top: 0; left: 0; z-index: 10; margin: 0; cursor: pointer; outline: none; }
                  .slide-range::-webkit-slider-runnable-track { -webkit-appearance: none; background: transparent; border: none; height: 14px; }
                  .slide-range::-moz-range-track { background: transparent; border: none; height: 14px; }
                  .slide-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; height: 12px; width: 12px; background: var(--ytm-primary-text); border-radius: 50%; margin-top: 1px; opacity: 0; transition: opacity 0.2s; box-shadow: 0 0 4px rgba(0,0,0,0.5); }
                  .slide-range::-moz-range-thumb { appearance: none; height: 12px; width: 12px; background: var(--ytm-primary-text); border-radius: 50%; opacity: 0; transition: opacity 0.2s; border: none; box-shadow: 0 0 4px rgba(0,0,0,0.5); }
                  
                  @keyframes spinner-border {
                    to { transform: rotate(360deg); }
                  }
                  .spinner-border {
                    display: inline-block;
                    width: 32px !important;
                    height: 32px !important;
                    vertical-align: -0.125em;
                    border: 4px solid currentColor !important;
                    border-right-color: transparent !important;
                    border-radius: 50%;
                    box-sizing: border-box;
                    background-color: transparent;
                    animation: 0.75s linear infinite spinner-border;
                  }
                  .text-secondary { color: var(--ytm-secondary-text) !important; }
                `;
                docPipWindow.document.head.appendChild(extraStyle);

                docPipWindow.document.body.innerHTML = `
                  <div style="display: flex; flex-direction: column; background: var(--ytm-bg); height: 100vh;">
                    <div class="pip-tabs">
                      <button class="pip-tab-btn active" id="pip-tab-player">Player</button>
                      <button class="pip-tab-btn" id="pip-tab-lyrics">Lyrics</button>
                    </div>
                    
                    <div class="pip-pane active" id="pip-pane-player" style="padding: 1rem 2rem 1.5rem 2rem; justify-content: space-evenly;">
                      <div class="player-modal-art-wrapper" style="flex-grow: 1; display: flex; align-items: center; justify-content: center; margin-bottom: 2rem;">
                        <img src="" id="pip-art" alt="Album Art" style="width: 100%; max-width: 400px; aspect-ratio: 1/1; object-fit: cover; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5);">
                      </div>
                      <div class="player-modal-track-info" style="text-align: left; margin-bottom: 1rem;">
                        <h3 id="pip-title" class="title text-truncate" style="font-weight: 700; font-size: 1.5rem; margin-bottom: 0;">Song Title</h3>
                        <p id="pip-artist" class="artist text-truncate" style="color: var(--ytm-secondary-text); font-size: 1rem; margin-bottom: 0;">Artist Name</p>
                      </div>
                      <div class="player-modal-progress" style="width: 100%; margin-bottom: 1rem;">
                        <div class="progress-bar-container" id="pip-progress-container" style="height: 14px; border-radius: 2px; position: relative; margin-bottom: 0.2em;">
                          <div class="progress-bar-bg" style="height: 4px; background-color: #404040; border-radius: 2px; position: absolute; top: 5px; left: 0; right: 0; pointer-events: none;"></div>
                          <div class="progress-bar-fg" id="pip-progress-bar" style="height: 4px; background-color: var(--ytm-primary-text); border-radius: 2px; width: 0%; position: absolute; top: 5px; left: 0; pointer-events: none;"></div>
                          <input type="range" id="pip-seek-slider" class="slide-range" min="0" max="100" value="0" step="0.1">
                        </div>
                        <div class="time-stamps" style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--ytm-secondary-text); margin-top: 0.5rem;">
                          <span id="pip-current-time">0:00</span>
                          <span id="pip-time-left">0:00</span>
                        </div>
                      </div>
                      <div class="player-modal-controls" style="display: flex; justify-content: space-between; align-items: center; margin: 0 auto; width: 100%; max-width: 400px;">
                        <button class="player-btn" id="pip-shuffle-btn" style="background: none; border: none; color: var(--ytm-secondary-text); font-size: 1.5rem;"></button>
                        <button class="player-btn" id="pip-prev-btn" style="background: none; border: none; color: var(--ytm-primary-text); font-size: 2.5rem;"></button>
                        <button class="player-btn play-btn" id="pip-play-pause-btn" style="background: var(--ytm-surface); border: none; color: var(--ytm-primary-text); font-size: 3.5rem; width: 70px; height: 70px; border-radius: 50%;"></button>
                        <button class="player-btn" id="pip-next-btn" style="background: none; border: none; color: var(--ytm-primary-text); font-size: 2.5rem;"></button>
                        <button class="player-btn" id="pip-repeat-btn" style="background: none; border: none; color: var(--ytm-secondary-text); font-size: 1.5rem;"></button>
                      </div>
                    </div>
                    
                    <div class="pip-pane" id="pip-pane-lyrics">
                      <div id="pip-lyrics-container"></div>
                    </div>
                  </div>
                `;

                const pipTabPlayer = docPipWindow.document.getElementById('pip-tab-player');
                const pipTabLyrics = docPipWindow.document.getElementById('pip-tab-lyrics');
                const pipPanePlayer = docPipWindow.document.getElementById('pip-pane-player');
                const pipPaneLyrics = docPipWindow.document.getElementById('pip-pane-lyrics');
                
                pipTabPlayer.addEventListener('click', () => {
                  pipTabPlayer.classList.add('active'); pipTabLyrics.classList.remove('active');
                  pipPanePlayer.classList.add('active'); pipPaneLyrics.classList.remove('active');
                });
                
                pipTabLyrics.addEventListener('click', () => {
                  pipTabLyrics.classList.add('active'); pipTabPlayer.classList.remove('active');
                  pipPaneLyrics.classList.add('active'); pipPanePlayer.classList.remove('active');
                  const activeLine = pipPaneLyrics.querySelector('.pip-lyric-line.active');
                  if (activeLine) activeLine.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });

                window.renderPipLyrics = () => {
                  if (!docPipWindow) return;
                  const container = docPipWindow.document.getElementById('pip-lyrics-container');
                  if (!container || !currentSong) return;
                  
                  if (currentSong.lyrics) {
                    const lrcData = parseLRC(currentSong.lyrics);
                    if (lrcData.length > 0) {
                      currentLrcData = lrcData;
                      currentLrcSongId = currentSong.id;
                      currentLyricIndex = -1;
                      container.innerHTML = lrcData.map((line, idx) => 
                        `<div class="pip-lyric-line" data-index="${idx}" data-time="${line.time}">${escapeHTML(line.text)}</div>`
                      ).join('');
                      
                      container.querySelectorAll('.pip-lyric-line').forEach(line => {
                        line.addEventListener('click', (e) => {
                          if (isFinite(audio.duration)) audio.currentTime = parseFloat(e.target.dataset.time);
                        });
                      });
                    } else {
                      container.innerHTML = `<pre style="white-space: pre-wrap; font-family: 'Roboto', sans-serif; font-size: 1rem; color: var(--ytm-secondary-text); padding: 1rem;">${escapeHTML(currentSong.lyrics)}</pre>`;
                    }
                  } else {
                    container.innerHTML = '<p style="color: var(--ytm-secondary-text); margin-top: 2rem;">No lyrics available.</p>';
                  }
                };

                const pipEls = {
                  art: docPipWindow.document.getElementById('pip-art'),
                  title: docPipWindow.document.getElementById('pip-title'),
                  artist: docPipWindow.document.getElementById('pip-artist'),
                  currentTime: docPipWindow.document.getElementById('pip-current-time'),
                  timeLeft: docPipWindow.document.getElementById('pip-time-left'),
                  progress: docPipWindow.document.getElementById('pip-progress-bar'),
                  progressContainer: docPipWindow.document.getElementById('pip-progress-container'),
                  seekSlider: docPipWindow.document.getElementById('pip-seek-slider'),
                  playPauseBtn: docPipWindow.document.getElementById('pip-play-pause-btn'),
                  prevBtn: docPipWindow.document.getElementById('pip-prev-btn'),
                  nextBtn: docPipWindow.document.getElementById('pip-next-btn'),
                  shuffleBtn: docPipWindow.document.getElementById('pip-shuffle-btn'),
                  repeatBtn: docPipWindow.document.getElementById('pip-repeat-btn')
                };

                playerElements.art.push(pipEls.art);
                playerElements.title.push(pipEls.title);
                playerElements.artist.push(pipEls.artist);
                playerElements.currentTime.push(pipEls.currentTime);
                playerElements.timeLeft.push(pipEls.timeLeft);
                playerElements.progress.push(pipEls.progress);
                playerElements.progressContainer.push(pipEls.progressContainer);
                playerElements.playPauseBtn.push(pipEls.playPauseBtn);
                playerElements.prevBtn.push(pipEls.prevBtn);
                playerElements.nextBtn.push(pipEls.nextBtn);
                playerElements.shuffleBtn.push(pipEls.shuffleBtn);
                playerElements.repeatBtn.push(pipEls.repeatBtn);

                pipEls.playPauseBtn.addEventListener('click', togglePlayPause);
                setupHoldToSkip([pipEls.prevBtn], 'prev', playPrev);
                setupHoldToSkip([pipEls.nextBtn], 'next', playNext);
                pipEls.shuffleBtn.addEventListener('click', toggleShuffle);
                pipEls.repeatBtn.addEventListener('click', () => {
                  repeatMode = (repeatMode === 'none') ? 'all' : (repeatMode === 'all') ? 'one' : 'none';
                  updateRepeatIcons();
                });

                pipEls.artist.style.cursor = 'pointer';
                pipEls.artist.addEventListener('mouseenter', () => pipEls.artist.style.textDecoration = 'underline');
                pipEls.artist.addEventListener('mouseleave', () => pipEls.artist.style.textDecoration = 'none');
                
                pipEls.artist.addEventListener('click', (e) => {
                  e.stopPropagation();
                  if (!currentSong) return;
                  
                  const artistRaw = currentSong.artist;
                  const userId = currentSong.user_id || '';
                  const artistsList = artistRaw.split(/\s*(?:,|&|\/)\s*/).filter(a => a.trim() !== '');
                  
                  if (artistsList.length > 1) {
                    if (artistsModalBody) {
                      artistsModalBody.innerHTML = `
                        <div class="list-group list-group-flush rounded">
                          ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userId}">${a}</button>`).join('')}
                        </div>
                      `;
                      if (artistsModal) artistsModal.show();
                    }
                  } else {
                    closeOpenModals();
                    loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId });
                  }
                });

                let isSeekingPip = false;
                pipEls.seekSlider.addEventListener('input', (e) => {
                  isSeekingPip = true;
                  const percent = e.target.value;
                  pipEls.progress.style.width = `${percent}%`;
                  if (isFinite(audio.duration)) {
                    pipEls.currentTime.textContent = formatTime((percent / 100) * audio.duration);
                  }
                });
                pipEls.seekSlider.addEventListener('change', (e) => {
                  if (isFinite(audio.duration)) {
                    audio.currentTime = (e.target.value / 100) * audio.duration;
                  }
                  isSeekingPip = false;
                });

                const updateSliderPip = () => {
                  if (!isSeekingPip && docPipWindow && isFinite(audio.duration)) {
                    const percent = (audio.currentTime / audio.duration) * 100;
                    pipEls.seekSlider.value = percent;
                  }
                };
                audio.addEventListener('timeupdate', updateSliderPip);

                pipBtnDesktop.classList.add('active');

                docPipWindow.addEventListener('pagehide', () => {
                  audio.removeEventListener('timeupdate', updateSliderPip);
                  playerElements.art = playerElements.art.filter(el => el !== pipEls.art);
                  playerElements.title = playerElements.title.filter(el => el !== pipEls.title);
                  playerElements.artist = playerElements.artist.filter(el => el !== pipEls.artist);
                  playerElements.currentTime = playerElements.currentTime.filter(el => el !== pipEls.currentTime);
                  playerElements.timeLeft = playerElements.timeLeft.filter(el => el !== pipEls.timeLeft);
                  playerElements.progress = playerElements.progress.filter(el => el !== pipEls.progress);
                  playerElements.progressContainer = playerElements.progressContainer.filter(el => el !== pipEls.progressContainer);
                  playerElements.playPauseBtn = playerElements.playPauseBtn.filter(el => el !== pipEls.playPauseBtn);
                  playerElements.prevBtn = playerElements.prevBtn.filter(el => el !== pipEls.prevBtn);
                  playerElements.nextBtn = playerElements.nextBtn.filter(el => el !== pipEls.nextBtn);
                  playerElements.shuffleBtn = playerElements.shuffleBtn.filter(el => el !== pipEls.shuffleBtn);
                  playerElements.repeatBtn = playerElements.repeatBtn.filter(el => el !== pipEls.repeatBtn);
                  
                  docPipWindow = null;
                  pipBtnDesktop.classList.remove('active');
                });

                pipEls.prevBtn.innerHTML = ICONS.prev;
                pipEls.nextBtn.innerHTML = ICONS.next;
                pipEls.shuffleBtn.innerHTML = ICONS.shuffle;
                
                updatePlayerUI();
                updatePlayPauseIcons();
                updateRepeatIcons();
                updateShuffleButtons();

              } catch (err) {
                console.error("Doc PiP failed, falling back to video PiP", err);
                fallbackVideoPip();
              }
            } else {
              fallbackVideoPip();
            }
          });

          function fallbackVideoPip() {
            if (document.pictureInPictureElement) {
              document.exitPictureInPicture().catch(()=>{});
            } else {
              pipVideo.play().then(() => {
                pipVideo.requestPictureInPicture().catch((err) => {
                  console.error("PiP failed", err);
                  showToast("Picture-in-Picture is not supported by your browser or was blocked.", "error");
                });
              }).catch(console.error);
            }
          }
          
          pipVideo.addEventListener('enterpictureinpicture', () => pipBtnDesktop.classList.add('active'));
          pipVideo.addEventListener('leavepictureinpicture', () => {
             if(!docPipWindow) pipBtnDesktop.classList.remove('active');
          });
        }

        clearCacheBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!confirm('This will clear all cached app data and reload the page. Are you sure?')) {
            return;
          }
          try {
            try {
              const root = await navigator.storage.getDirectory();
              const fileHandle = await root.getFileHandle('appVersion.txt');
              await fileHandle.remove();
            } catch (err) {}
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
        const restoreForm = document.getElementById('restore-form');
        const changeNameForm = document.getElementById('change-name-form');
        const changePwForm = document.getElementById('change-password-form');
        const profilePicForm = document.getElementById('profile-picture-form');
        const createPlaylistForm = document.getElementById('create-playlist-form');
        const editPlaylistForm = document.getElementById('edit-playlist-form');
        const importPlaylistForm = document.getElementById('import-playlist-form');
        const importFavoritesForm = document.getElementById('import-favorites-form');
        const editMetadataForm = document.getElementById('edit-metadata-form');
        const btnDeleteKeep = document.getElementById('btn-delete-keep');
        const btnDeleteAll = document.getElementById('btn-delete-all');

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
            cachedExploreData = null;
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
        
        if (restoreForm) {
          restoreForm.addEventListener('submit', async e => {
            e.preventDefault();
            const backup_key = document.getElementById('restore-key').value;
            const new_email = document.getElementById('restore-email').value;
            const new_artist = document.getElementById('restore-artist').value;
            const new_password = document.getElementById('restore-password').value;
            const data = await fetchData('?action=restore_account', {
              method: 'POST', headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ backup_key, new_email, new_artist, new_password })
            });
            if (data && data.status === 'success') {
              bootstrap.Modal.getInstance(document.getElementById('restore-modal')).hide();
              restoreForm.reset();
              showToast(data.message, 'success');
            }
          });
        }
        
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
        
        importPlaylistForm.addEventListener('submit', async e => {
          e.preventDefault();
          const fileInput = document.getElementById('import-playlist-file');
          if (fileInput.files.length === 0) return;
          
          const file = fileInput.files[0];
          const reader = new FileReader();
          
          reader.onload = async (event) => {
            try {
              const importData = JSON.parse(event.target.result);
              if (!importData.name || !importData.songs || !Array.isArray(importData.songs)) {
                showToast('Invalid JSON format.', 'error');
                return;
              }
              
              const btn = importPlaylistForm.querySelector('button[type="submit"]');
              const originalText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Scanning Library...';
              
              const response = await fetch('?action=import_playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(importData)
              });
              
              const result = await response.json();
              if (result) {
                showToast(result.message, result.status);
                if (result.status === 'success') {
                  importPlaylistModal.hide();
                  importPlaylistForm.reset();
                  loadView(currentView);
                }
              }
              btn.disabled = false;
              btn.innerHTML = originalText;
            } catch (err) {
              showToast('Failed to parse JSON or import.', 'error');
              const btn = importPlaylistForm.querySelector('button[type="submit"]');
              btn.disabled = false;
              btn.textContent = 'Import';
            }
          };
          reader.readAsText(file);
        });

        if (importFavoritesForm) {
          importFavoritesForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fileInput = document.getElementById('import-favorites-file');
            if (fileInput.files.length === 0) return;
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            reader.onload = async (event) => {
              try {
                const importData = JSON.parse(event.target.result);
                if (!importData.songs || !Array.isArray(importData.songs)) {
                  showToast('Invalid JSON format.', 'error');
                  return;
                }
                
                const btn = importFavoritesForm.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Scanning Library...';
                
                const response = await fetch('?action=import_favorites', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(importData)
                });
                
                const result = await response.json();
                if (result) {
                  showToast(result.message, result.status);
                  if (result.status === 'success') {
                    importFavoritesModal.hide();
                    importFavoritesForm.reset();
                    loadView(currentView);
                  }
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
              } catch (err) {
                showToast('Failed to parse JSON or import.', 'error');
                const btn = importFavoritesForm.querySelector('button[type="submit"]');
                btn.disabled = false;
                btn.textContent = 'Import';
              }
            };
            reader.readAsText(file);
          });
        }

        if (editMetadataForm) {
          editMetadataForm.addEventListener('submit', async e => {
            e.preventDefault();
            const formData = new FormData();
            formData.append('id', document.getElementById('edit-metadata-id').value);
            formData.append('title', document.getElementById('edit-metadata-title').value);
            formData.append('artist', document.getElementById('edit-metadata-artist').value);
            formData.append('album', document.getElementById('edit-metadata-album').value);
            formData.append('genre', document.getElementById('edit-metadata-genre').value);
            formData.append('lyrics', document.getElementById('edit-metadata-lyrics').value);
            
            const coverInput = document.getElementById('edit-metadata-cover');
            if (coverInput && coverInput.files.length > 0) {
              formData.append('cover_image', coverInput.files[0]);
            }
            
            const result = await fetchData('?action=edit_metadata', {
              method: 'POST',
              body: formData
            });
            
            if (result && result.status === 'success') {
              showToast(result.message, 'success');
              if (editMetadataModal) editMetadataModal.hide();
              loadView(currentView);
            }
          });

          const coverInput = document.getElementById('edit-metadata-cover');
          if (coverInput) {
            coverInput.addEventListener('change', function(e) {
              if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                  document.getElementById('edit-metadata-cover-preview').src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
              }
            });
          }
        }
        
        const handleLogout = async () => {
          await fetchData('?action=logout');
          currentUser = null;
          cachedExploreData = null;
          updateUIForAuthState();
          loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' });
        };
        
        document.getElementById('profile-dropdown-logout-desktop').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-logout-mobile').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-stats-desktop').addEventListener('click', () => loadView({type: 'get_user_stats'}));
        document.getElementById('profile-dropdown-stats-mobile').addEventListener('click', () => loadView({type: 'get_user_stats'}));

        changeNameForm.addEventListener('submit', async e => {
          e.preventDefault();
          const new_name = document.getElementById('new-name').value;
          const data = await fetchData('?action=change_name', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ new_name })
          });
          if (data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
            changeNameForm.reset();
            showToast(data.message, 'success');
            await checkSession();
            if (currentView.type === 'user_profile') loadView(currentView);
          }
        });

        changePwForm.addEventListener('submit', async e => {
          e.preventDefault();
          const new_password = document.getElementById('new-password').value;
          const data = await fetchData('?action=change_password', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ new_password })
          });
          if (data && data.status === 'success') {
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

        if (btnDeleteKeep) {
          btnDeleteKeep.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to delete your account but keep the data? A backup key will be downloaded.')) return;
            const data = await fetchData('?action=delete_account_keep_data');
            if (data && data.status === 'success') {
              const blob = new Blob([data.backup_key], { type: 'text/plain' });
              const a = document.createElement('a');
              a.href = URL.createObjectURL(blob);
              a.download = 'backup.txt';
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
              showToast('Account deleted. Backup key downloaded.', 'success');
              bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
              await checkSession();
              loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' });
            }
          });
        }

        if (btnDeleteAll) {
          btnDeleteAll.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to permanently delete your account and ALL your data? This cannot be undone!')) return;
            const data = await fetchData('?action=delete_account_all');
            if (data && data.status === 'success') {
              showToast('Account and data permanently deleted.', 'success');
              bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
              await checkSession();
              loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' });
            }
          });
        }

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
                  } catch (e) {
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

        let pdAllSongs = [];
        let pdCurrentPage = 1;
        const pdItemsPerPage = 50;
        let pdIsDownloading = false;
        let pdStopRequested = false;

        const pdPlaylistIdInput = document.getElementById('pd-playlist-id');
        const pdSongIdInput = document.getElementById('pd-song-id');
        const pdLoadForm = document.getElementById('pd-load-form');
        const pdDownloadSingleBtn = document.getElementById('pd-download-single');
        const pdResultsCard = document.getElementById('pd-results-card');
        const pdPlaylistTitle = document.getElementById('pd-playlist-title');
        const pdStartAutoBtn = document.getElementById('pd-start-auto');
        const pdLogArea = document.getElementById('pd-log');
        const pdClearLogBtn = document.getElementById('pd-clear-log');
        const pdSongRows = document.getElementById('pd-song-rows');

        const pdEscapeHtml = (str) => {
          if (!str) return '';
          return str.replace(/[&<>]/g, m => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[m] || m))
                    .replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, c => c);
        };
        const pdTruncate = (str, len = 50) => str.length > len ? str.substring(0, len) + '…' : str;

        const pdLog = (message, isError = false) => {
          const time = new Date().toLocaleTimeString();
          const div = document.createElement('div');
          div.className = 'log-line';
          div.style.color = isError ? '#ff8888' : '#aaaaaa';
          div.style.borderBottom = '1px solid #404040';
          div.style.padding = '0.25rem 0';
          div.innerHTML = `[${time}] ${message}`;
          pdLogArea.appendChild(div);
          div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        const pdDownloadSong = async (song, isAuto = false, index = null, total = null) => {
          const safeTitle = (song.title + ' - ' + (song.artist || 'Unknown')).replace(/[^a-zA-Z0-9\s\.\-\(\)\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]/g, '_');
          const url = `?action=download_song&id=${encodeURIComponent(song.id)}`;
          if (isAuto) {
            pdLog(`Downloading ${index+1}/${total}: ${song.title} - ${song.artist || 'Unknown'}`);
          } else {
            pdLog(`Manual download: ${song.title} - ${song.artist || 'Unknown'}`);
          }
          const a = document.createElement('a');
          a.href = url;
          a.download = safeTitle + '.mp3';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          await new Promise(r => setTimeout(r, 1200));
        };

        const handleManualDl = async (e) => {
          e.preventDefault();
          const songId = parseInt(e.currentTarget.dataset.id);
          const song = pdAllSongs.find(s => s.id === songId);
          if (song) await pdDownloadSong(song, false);
        };

        const pdRenderSongRows = () => {
          pdSongRows.innerHTML = '';
          const start = 0;
          const end = pdItemsPerPage;
          const pageSongs = pdAllSongs.slice(start, end);
          
          const rowsHtml = pageSongs.map((song, idx) => {
            const globalIdx = start + idx;
            return `
              <div class="song-item pd-song-row" style="color: #ffffff !important;">
                <div class="text-secondary small d-none d-md-block" style="color: #ffffff !important;">${globalIdx + 1}</div>
                <div class="title-col text-truncate" title="${pdEscapeHtml(song.title)}" style="color: #ffffff !important;">${pdEscapeHtml(pdTruncate(song.title, 60))}</div>
                <div class="artist-col text-truncate d-none d-md-block" title="${pdEscapeHtml(song.artist)}" style="color: #ffffff !important;">${pdEscapeHtml(song.artist ? pdTruncate(song.artist, 40) : 'Unknown')}</div>
                <div class="duration-col d-none d-md-block" style="color: #ffffff !important;">${formatTime(song.duration)}</div>
                <div class="download-col"><button class="btn btn-sm btn-outline-light pd-manual-dl" data-id="${song.id}" style="color: #ffffff !important; border-color: #ffffff !important;"><i class="bi bi-download"></i></button></div>
                <div class="pd-mobile-artist d-md-none text-truncate" style="color: #ffffff !important;">${pdEscapeHtml(song.artist ? pdTruncate(song.artist, 40) : 'Unknown')}</div>
              </div>
            `;
          }).join('');
          
          pdSongRows.innerHTML = rowsHtml;
          
          document.querySelectorAll('.pd-manual-dl').forEach(btn => {
            btn.removeEventListener('click', handleManualDl);
            btn.addEventListener('click', handleManualDl);
          });
        };

        const pdStopAutoDownload = () => {
          if (!pdIsDownloading) return;
          pdStopRequested = true;
          pdLog('⏹️ Stopping download process...', false);
          pdStartAutoBtn.disabled = true;
          pdStartAutoBtn.innerHTML = '<i class="bi bi-stop-fill" style="color: #ffffff !important;"></i> Stopping...';
        };

        const pdStartAutoDownload = async () => {
          if (pdIsDownloading) return;
          pdIsDownloading = true;
          pdStopRequested = false;
          
          pdStartAutoBtn.removeEventListener('click', pdStartAutoDownload);
          pdStartAutoBtn.addEventListener('click', pdStopAutoDownload);
          pdStartAutoBtn.classList.replace('btn-danger', 'btn-warning');
          pdStartAutoBtn.innerHTML = '<i class="bi bi-stop-fill" style="color: #ffffff !important;"></i> Stop Download';
          pdStartAutoBtn.disabled = false;
          
          pdLog('🚀 Starting sequential download of ALL songs. Click "Stop Download" to cancel.');
          for (let i = 0; i < pdAllSongs.length; i++) {
            if (pdStopRequested) {
              pdLog(`⏹️ Download stopped by user after ${i}/${pdAllSongs.length} songs.`);
              break;
            }
            await pdDownloadSong(pdAllSongs[i], true, i, pdAllSongs.length);
          }
          
          pdStartAutoBtn.removeEventListener('click', pdStopAutoDownload);
          pdStartAutoBtn.addEventListener('click', pdStartAutoDownload);
          pdStartAutoBtn.classList.replace('btn-warning', 'btn-danger');
          pdStartAutoBtn.innerHTML = '<i class="bi bi-play-fill" style="color: #ffffff !important;"></i> Download All Songs (Sequential)';
          pdStartAutoBtn.disabled = false;
          
          if (!pdStopRequested) pdLog(`✅ All ${pdAllSongs.length} songs have been sent for download!`);
          pdIsDownloading = false;
          pdStopRequested = false;
        };

        pdLoadForm.addEventListener('submit', async (e) => {
          e.preventDefault();
          const publicId = pdPlaylistIdInput.value.trim();
          if (!publicId) return;
          
          pdLogArea.innerHTML = '';
          pdLog(`Fetching playlist details for: ${publicId}`);
          
          try {
            const data = await fetchData(`?action=get_playlist_songs&public_id=${publicId}&sort=manual_order&all=1`);
            if (data && data.length > 0) {
              pdAllSongs = data;
              pdCurrentPage = 1;
              pdResultsCard.classList.remove('d-none');
              pdPlaylistTitle.innerHTML = `<i class="bi bi-music-note-beamed" style="color: #ffffff !important;"></i> Playlist loaded <span class="badge bg-secondary ms-2">${pdAllSongs.length} songs</span>`;
              pdLog(`✅ Successfully loaded ${pdAllSongs.length} songs.`);
              pdRenderSongRows();
              const loader = document.getElementById('pd-infinite-scroll-loader');
              if (loader) {
                if (pdCurrentPage * pdItemsPerPage < pdAllSongs.length) {
                  loader.classList.remove('d-none');
                } else {
                  loader.classList.add('d-none');
                }
              }
            } else {
              showToast('Playlist not found or empty', 'error');
              pdLog('❌ Failed to load playlist or it is empty.', true);
            }
          } catch (err) {
            showToast('Error fetching playlist', 'error');
          }
        });

        pdDownloadSingleBtn.addEventListener('click', async () => {
          const songId = parseInt(pdSongIdInput.value);
          if (isNaN(songId) || songId <= 0) {
            showToast('Enter a valid song ID', 'error');
            return;
          }
          try {
            const song = await fetchData(`?action=get_song_data&id=${songId}`);
            if (song && song.id) {
              await pdDownloadSong(song, false);
            } else {
              showToast('Song not found', 'error');
            }
          } catch (err) {
            showToast('Error fetching song', 'error');
          }
        });

        pdStartAutoBtn.addEventListener('click', pdStartAutoDownload);
        pdClearLogBtn.addEventListener('click', () => { pdLogArea.innerHTML = ''; pdLog('Log cleared.'); });

        const pdModalBody = document.querySelector('#playlist-downloader-modal .modal-body');
        if (pdModalBody) {
          pdModalBody.addEventListener('scroll', () => {
            if (pdResultsCard.classList.contains('d-none') || pdAllSongs.length === 0) return;
            const scrollBottom = pdModalBody.scrollHeight - pdModalBody.scrollTop - pdModalBody.clientHeight;
            if (scrollBottom < 200 && pdCurrentPage * pdItemsPerPage < pdAllSongs.length) {
              const start = pdCurrentPage * pdItemsPerPage;
              pdCurrentPage++;
              const end = pdCurrentPage * pdItemsPerPage;
              const pageSongs = pdAllSongs.slice(start, end);
              
              const rowsHtml = pageSongs.map((song, idx) => {
                const globalIdx = start + idx;
                return `
                  <div class="song-item pd-song-row" style="color: #ffffff !important;">
                    <div class="text-secondary small d-none d-md-block" style="color: #ffffff !important;">${globalIdx + 1}</div>
                    <div class="title-col text-truncate" title="${pdEscapeHtml(song.title)}" style="color: #ffffff !important;">${pdEscapeHtml(pdTruncate(song.title, 60))}</div>
                    <div class="artist-col text-truncate d-none d-md-block" title="${pdEscapeHtml(song.artist)}" style="color: #ffffff !important;">${pdEscapeHtml(song.artist ? pdTruncate(song.artist, 40) : 'Unknown')}</div>
                    <div class="duration-col d-none d-md-block" style="color: #ffffff !important;">${formatTime(song.duration)}</div>
                    <div class="download-col"><button class="btn btn-sm btn-outline-light pd-manual-dl" data-id="${song.id}" style="color: #ffffff !important; border-color: #ffffff !important;"><i class="bi bi-download"></i></button></div>
                    <div class="pd-mobile-artist d-md-none text-truncate" style="color: #ffffff !important;">${pdEscapeHtml(song.artist ? pdTruncate(song.artist, 40) : 'Unknown')}</div>
                  </div>
                `;
              }).join('');
              pdSongRows.insertAdjacentHTML('beforeend', rowsHtml);
              document.querySelectorAll('.pd-manual-dl').forEach(btn => {
                btn.removeEventListener('click', handleManualDl);
                btn.addEventListener('click', handleManualDl);
              });
              const loader = document.getElementById('pd-infinite-scroll-loader');
              if (loader) {
                if (pdCurrentPage * pdItemsPerPage >= pdAllSongs.length) {
                  loader.classList.add('d-none');
                }
              }
            }
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
            const newNameInput = document.getElementById('new-name');
            if (newNameInput) newNameInput.value = currentUser.artist;
            if (uploadLimitText) uploadLimitText.textContent = data.upload_limit;
            if (uploadRemainingText) {
              uploadRemainingText.textContent = `Today's remaining uploads: ${currentUser.uploads_remaining}`;
            }
          } else {
            currentUser = null;
          }
          updateUIForAuthState();
        }

        const injectMetaTags = () => {
          const metaTags = [
            { name: 'description', content: 'A simple, fast music player with user accounts, streaming, and offline PWA capabilities.' },
            { name: 'keywords', content: 'music, player, php, streaming, audio, webapp' },
            { name: 'author', content: 'PHP Music' },
            { name: 'apple-mobile-web-app-capable', content: 'yes' },
            { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' },
            { name: 'apple-mobile-web-app-title', content: 'PHP Music' },
            { name: 'viewport', content: 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no' },
            { property: 'og:title', content: 'PHP Music Player' },
            { property: 'og:description', content: 'Listen to your favorite music anywhere.' },
            { property: 'og:type', content: 'website' }
          ];
          metaTags.forEach(tag => {
            const meta = document.createElement('meta');
            Object.keys(tag).forEach(key => meta.setAttribute(key, tag[key]));
            document.head.appendChild(meta);
          });
        };

        const init = async () => {
          injectMetaTags();
          
          audio.preload = 'auto';
          
          audio.addEventListener('waiting', () => {
            if (isPlaying) updatePlayPauseIcons(true); 
          });

          audio.addEventListener('playing', () => {
            updatePlayPauseIcons(false); 
          });

          audio.addEventListener('canplay', () => {
            if (isPlaying) updatePlayPauseIcons(false);
          });

          audio.addEventListener('error', (e) => {
            console.error('Audio playback error:', e);
            if (audio.error && audio.error.code === 2 && isPlaying && currentSong) {
              updatePlayPauseIcons(true); 
              const currentPos = audio.currentTime;
              setTimeout(() => {
                audio.load();
                audio.currentTime = currentPos;
                audio.play().catch(() => updatePlayPauseIcons(false));
              }, 3000);
            } else {
              updatePlayPauseIcons(false);
            }
          });

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
            history.replaceState({ viewConfig: window.initialView }, "");
            loadView(window.initialView, false);
          } else {
            const defaultView = { type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' };
            history.replaceState({ viewConfig: defaultView }, "");
            loadView(defaultView, false);
          }
        };

        window.addEventListener('popstate', (e) => {
          if (e.state && e.state.viewConfig) {
            // Load the previous view without pushing it to history again
            loadView(e.state.viewConfig, false);
          } else {
            // Fallback to Home if state is lost
            loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '' }, false);
          }
        });

        init();
      });
    </script>
  </body>
</html>