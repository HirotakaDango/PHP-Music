<?php
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
  ob_start('ob_gzhandler');
}
error_reporting(E_ALL & ~E_DEPRECATED);

// AUTOMATIC SECURITY FIREWALL: Generate strict .htaccess to block direct file and DB access
$htaccess_path = __DIR__ . '/.htaccess';
$needs_htaccess_update = false;

if (file_exists($htaccess_path)) {
  // If the old overly-strict FilesMatch is present, force an update to fix X-Sendfile audio playback
  if (strpos(file_get_contents($htaccess_path), 'mp3|m4a') !== false) {
    $needs_htaccess_update = true;
  }
} else {
  $needs_htaccess_update = true;
}

if ($needs_htaccess_update) {
  $htaccess_content = <<<HTACCESS
# ==========================================
# PHP MUSIC - STRICT SECURITY FIREWALL
# ==========================================

# 1. Disable directory listing globally
Options -Indexes

# 2. Block direct web access to database and config files unconditionally
<FilesMatch "\.(db|sqlite|sqlite3|bak|log|ini|sh)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
  </IfModule>
</FilesMatch>

# 3. Block direct web access to media files, but ALLOW internal serving (Fixes X-Sendfile Playback)
<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # If the request is a direct HTTP request from the outside (not an internal redirect)
  RewriteCond %{ENV:REDIRECT_STATUS} ^$
  RewriteRule \.(mp3|m4a|flac|ogg|wav|jpg|jpeg|png|webp|gif)$ - [F,L]

  # Block folder browsing explicitly
  RewriteCond %{ENV:REDIRECT_STATUS} ^$
  RewriteRule ^uploads/.*$ - [F,L]
  
  RewriteCond %{ENV:REDIRECT_STATUS} ^$
  RewriteRule ^getid3/.*$ - [F,L]
</IfModule>
HTACCESS;
  @file_put_contents($htaccess_path, $htaccess_content);
}

// AUTOMATIC ROBOTS.TXT: Prevent search engines and legitimate crawlers from indexing media and APIs
$robots_path = __DIR__ . '/robots.txt';
if (!file_exists($robots_path)) {
  $robots_content = <<<ROBOTS
User-agent: *
Disallow: /uploads/
Disallow: /getid3/
Disallow: /*?action=
Disallow: /*?share_type=
Disallow: /*?pwa=
Disallow: /*.db$
Disallow: /*.sqlite$

# Block AI Scrapers specifically
User-agent: GPTBot
Disallow: /
User-agent: ChatGPT-User
Disallow: /
User-agent: CCBot
Disallow: /
User-agent: anthropic-ai
Disallow: /
User-agent: Claude-Web
Disallow: /
ROBOTS;
  @file_put_contents($robots_path, $robots_content);
}

// GLOBAL CORS ENABLER: Execute BEFORE Firewall to allow Preflight OPTIONS to pass cross-origin!
$http_origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_X_ORIGIN'] ?? '';
$raw_uri_cors = $_SERVER['REQUEST_URI'] ?? '';
$is_api_cors = strpos($raw_uri_cors, 'access=api') !== false || (isset($_GET['access']) && $_GET['access'] === 'api');

if ($is_api_cors) {
  // 1. Pure Public CORS for External API Player (No credentials needed)
  header("Access-Control-Allow-Origin: *");
} else {
  // 2. Standard CORS for Internal Web App (Requires credentials for sessions)
  if ($http_origin === 'null') {
    $http_origin = '*'; // Force wildcard for local testing
  } elseif (!$http_origin && isset($_SERVER['HTTP_REFERER'])) {
    $parsed_url = parse_url($_SERVER['HTTP_REFERER']);
    if (isset($parsed_url['host'])) {
      $http_origin = ($parsed_url['scheme'] ?? 'https') . '://' . $parsed_url['host'];
      if (isset($parsed_url['port'])) $http_origin .= ':' . $parsed_url['port'];
    }
  }

  if ($http_origin) {
    header("Access-Control-Allow-Origin: $http_origin");
    if ($http_origin !== '*') {
      header("Access-Control-Allow-Credentials: true");
    }
  } else {
    header("Access-Control-Allow-Origin: *");
  }
}

header("Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Type, Content-Disposition");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
  header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
} else {
  header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Range, Accept-Ranges, Cache-Control");
}

// INTERCEPT PREFLIGHT: Exit 200 OK immediately so browsers validate CORS securely
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header("Access-Control-Max-Age: 86400"); 
  header("Content-Length: 0");             
  header("Content-Type: text/plain");      
  exit(0);
}

// ANTI-SCRAPING FIREWALL: Now runs safely AFTER CORS validation
$raw_uri = $_SERVER['REQUEST_URI'] ?? '';
$temp_action = $_GET['action'] ?? '';

// ANTI-SCRAPING FIREWALL: Now runs safely AFTER CORS validation
$raw_uri = $_SERVER['REQUEST_URI'] ?? '';
$temp_action = $_GET['action'] ?? '';

// Support JSON bodies sent by cross-origin fetch requests
$json_body = json_decode(file_get_contents('php://input'), true);
if (is_array($json_body)) {
  if (empty($temp_action) && isset($json_body['action'])) {
    $_GET['action'] = $json_body['action'];
    $temp_action = $_GET['action'];
  }
  if (isset($json_body['access'])) {
    $_GET['access'] = $json_body['access'];
  }
}

// Pre-extract action if the URL is mangled by play.html
if (empty($temp_action) && preg_match('/action=([a-zA-Z0-9_]+)/', $raw_uri, $act_match)) {
  $temp_action = $act_match[1];
}

$is_media_request = in_array($temp_action, ['get_stream', 'get_image', 'download_song', 'download_cover']);
$is_explicit_api = strpos($raw_uri, 'access=api') !== false || (isset($_GET['access']) && $_GET['access'] === 'api');

$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$bot_patterns = '/(python|curl|wget|bot|spider|crawl|scraper|scrapy|phantom|headless|selenium|puppet|puppeteer|httpclient|postman|insomnia|slurp|facebookexternalhit)/i';

// Exempt access=api calls from the strict bot User-Agent check
if (!$is_media_request && !$is_explicit_api && (empty($user_agent) || preg_match($bot_patterns, $user_agent))) {
  http_response_code(403);
  die("Access Denied: Automated scraping and bot activity are strictly prohibited.");
}

// MASS USE OPTIMIZATION: Force script into OPcache memory for max execution speed
if (function_exists('opcache_is_script_cached') && function_exists('opcache_compile_file')) {
  if (!@opcache_is_script_cached(__FILE__)) {
    @opcache_compile_file(__FILE__);
  }
}

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
      "background_color" => "#0a0a0a",
      "theme_color" => "#0a0a0a",
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
const CACHE_NAME = 'php-music-cache-v30';
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
          if (cacheWhitelist.indexOf(cacheName) === -1 && cacheName !== 'php-music-offline' && cacheName !== 'php-music-api-cache') {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  const isStream = url.searchParams.get('action') === 'get_stream';
  
  if (isStream) {
    event.respondWith(
      caches.match(event.request.url).then(async cachedResponse => {
        if (cachedResponse) {
          const rangeHeader = event.request.headers.get('range');
          if (!rangeHeader) return cachedResponse;
          
          const buffer = await cachedResponse.clone().arrayBuffer();
          const bytes = rangeHeader.match(/bytes=(\d+)-(\d+)?/);
          const start = Number(bytes[1]);
          const end = bytes[2] ? Number(bytes[2]) : buffer.byteLength - 1;
          
          const chunk = buffer.slice(start, end + 1);
          return new Response(chunk, {
            status: 206,
            statusText: 'Partial Content',
            headers: {
              'Content-Range': `bytes \${start}-\${end}/\${buffer.byteLength}`,
              'Content-Length': chunk.byteLength,
              'Content-Type': cachedResponse.headers.get('Content-Type') || 'audio/mpeg',
              'Accept-Ranges': 'bytes'
            }
          });
        }
        return fetch(event.request);
      })
    );
    return;
  }

  const isApiCall = url.searchParams.has('action') || url.searchParams.has('share_type');
  if (isApiCall) {
    event.respondWith(
      fetch(event.request).then(response => {
        if (event.request.method === 'GET' && response.ok) {
          const clone = response.clone();
          caches.open('php-music-api-cache').then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => {
        return caches.match(event.request, { ignoreSearch: false, ignoreVary: true });
      })
    );
    return;
  }

  const isPwaCall = url.searchParams.has('pwa');
  if (isPwaCall || event.request.headers.get('range')) {
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
ini_set('session.gc_maxlifetime', 31536000); // 1 year
ini_set('session.cookie_lifetime', 31536000);
session_start([
  'cookie_lifetime' => 31536000,
  'gc_maxlifetime' => 31536000,
  'cookie_httponly' => true,
  'cookie_samesite' => 'None',
  'cookie_secure' => true
]);
set_time_limit(0);

// ULTRA-SCALE CONCURRENCY: Release the PHP session write-lock early for read-only requests.
// This allows the user's browser to make multiple AJAX requests at the exact same time without queueing.
$write_actions = ['login', 'register', 'logout', 'change_name', 'change_password', 'upload_song', 'delete_song', 'edit_metadata', 'toggle_favorite', 'toggle_offline', 'toggle_follow', 'update_favorite_order', 'update_offline_order', 'import_offline', 'create_playlist', 'edit_playlist', 'delete_playlist', 'add_to_playlist', 'add_mix_to_playlist', 'remove_from_playlist', 'update_playlist_order', 'log_play', 'save_global_settings', 'save_song_settings', 'reset_song_settings', 'upload_profile_picture', 'toggle_listen_later', 'update_listen_later_order', 'save_note', 'delete_note', 'toggle_song_reaction', 'toggle_comment_reaction', 'add_song_comment', 'edit_song_comment', 'delete_song_comment', 'create_community_post', 'toggle_post_reaction', 'edit_community_post', 'delete_community_post', 'leave_collab', 'request_verification'];
$current_action = $_GET['action'] ?? '';

if (!in_array($current_action, $write_actions) && !isset($_GET['access'])) {
  $session_user_id = $_SESSION['user_id'] ?? null;
  $session_user_artist = $_SESSION['user_artist'] ?? null;
  session_write_close();
}

define('MUSIC_DIR', __DIR__);
define('DB_FILE', __DIR__ . '/music.db');
define('APP_VERSION', '4.1');
define('PAGE_SIZE', 25);
define('ADMIN_PAGE_SIZE', 20);
define('ADMIN_PASSWORD', 'admin');
define('ADMIN_PASSWORD_HASH', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT));
define('DAILY_UPLOAD_LIMIT', 10);

function get_db() {
  static $db = null;
  if ($db !== null) return $db; 
  
  try {
    // Removed ATTR_PERSISTENT as it causes permanent database locking bugs in SQLite
    $db = new PDO('sqlite:' . DB_FILE, null, null, [
      PDO::ATTR_TIMEOUT => 15
    ]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      
    // Optimized PRAGMAs for fast concurrent reads without locking the browser
    $db->exec("
      PRAGMA journal_mode=WAL; 
      PRAGMA synchronous=NORMAL; 
      PRAGMA cache_size=-50000; /* 50MB of RAM for caching */
      PRAGMA temp_store=MEMORY; 
      PRAGMA foreign_keys=ON;
      PRAGMA busy_timeout=5000;
    ");
    
    $db->sqliteCreateFunction('match_artist', function($artist_field, $search_name) {
      if ($artist_field === null || $search_name === null) return 0;
      $db_parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist_field);
      $search_parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $search_name);
      if (!is_array($db_parts)) $db_parts = [$artist_field];
      if (!is_array($search_parts)) $search_parts = [$search_name];
      foreach ($db_parts as $db_part) {
        foreach ($search_parts as $search_part) {
          if (strcasecmp(trim($db_part), trim($search_part)) === 0) return 1;
        }
      }
      return 0;
    }, 2);

    return $db;
  } catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
  }
}

if (isset($_GET['access']) && $_GET['access'] === 'admin') {
  if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $_POST['csrf_token'])) {
      die("Security violation: CSRF token mismatch.");
    }
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
      $db->prepare("DELETE FROM personal_notes WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM history WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM play_counts WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM favorites WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM offline_songs WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM playlists WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM playlist_collaborators WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM follows WHERE follower_id = ? OR following_id = ?")->execute([$del_uid, $del_uid]);
      $db->prepare("DELETE FROM listen_later WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM activity_feed WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM listen_later WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM activity_feed WHERE user_id = ?")->execute([$del_uid]);
      $db->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$del_uid, $del_uid]);
      $db->prepare("DELETE FROM blocks WHERE blocker_id = ? OR blocked_id = ?")->execute([$del_uid, $del_uid]);

      $new_email = 'deleted_' . $del_uid . '_' . time() . '@mail.com';
      $db->prepare("UPDATE users SET email = ?, artist = 'Deleted User', bio = NULL, password_hash = NULL, backup_key = NULL, profile_picture = NULL, profile_background = NULL, profile_background_type = NULL WHERE id = ?")->execute([$new_email, $del_uid]);

      header('Location: ' . $_SERVER['REQUEST_URI']);
      exit;
    }
        
    if (isset($_POST['reset_opcache'])) {
      if (function_exists('opcache_reset')) {
        opcache_reset();
      }
      header('Location: ?access=admin');
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
          <div class="d-flex align-items-center gap-3">
            <h1 class="content-title m-0">User Management</h1>
            <form method="POST" action="" class="m-0">
              <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf_token']; ?>">
              <button type="submit" name="reset_opcache" class="btn btn-sm btn-outline-warning" title="Clear PHP OPcache"><i class="bi bi-lightning-charge-fill"></i> Reset OPcache</button>
            </form>
          </div>
          <?php $search = $_GET['search'] ?? ''; ?>
          <?php $sort_admin = $_GET['sort'] ?? 'newest'; ?>
          <form method="GET" action="" class="d-flex w-100" style="max-width: 450px;">
            <input type="hidden" name="access" value="admin">
            <select name="sort" class="form-select me-2" style="width: auto;" onchange="this.form.submit()">
              <option value="newest" <?php echo $sort_admin === 'newest' ? 'selected' : ''; ?>>Newest</option>
              <option value="oldest" <?php echo $sort_admin === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
              <option value="pending" <?php echo $sort_admin === 'pending' ? 'selected' : ''; ?>>Pending Requests</option>
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
              
              $where_clauses = [];
              $params = [];
              if ($search !== '') {
                $where_clauses[] = "(id = ? OR email LIKE ? OR artist LIKE ?)";
                $params = [$search, "%$search%", "%$search%"];
              }
              if ($sort_admin === 'pending') {
                $where_clauses[] = "verified = 'pending'";
              }
              
              $where = '';
              if (count($where_clauses) > 0) {
                $where = "WHERE " . implode(' AND ', $where_clauses);
              }
              
              $total_users_stmt = $db->prepare("SELECT COUNT(id) FROM users $where");
              $total_users_stmt->execute($params);
              $total_users = $total_users_stmt->fetchColumn();
              $total_pages = ceil($total_users / ADMIN_PAGE_SIZE);
              
              $admin_sort_map = [
                'newest' => 'ORDER BY id DESC',
                'oldest' => 'ORDER BY id ASC',
                'pending' => "ORDER BY id DESC",
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
                <?php if ($user['verified'] === 'yes'): ?>
                  <span class="badge bg-success">YES</span>
                <?php elseif ($user['verified'] === 'pending'): ?>
                  <span class="badge bg-info text-dark">PENDING</span>
                <?php else: ?>
                  <span class="badge bg-secondary">NO</span>
                <?php endif; ?>
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
                  <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_csrf_token']; ?>">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" name="toggle_verify" class="btn <?php echo $user['verified'] === 'yes' ? 'btn-warning' : ($user['verified'] === 'pending' ? 'btn-info text-dark fw-bold' : 'btn-success'); ?>">
                    <?php echo $user['verified'] === 'yes' ? 'Un-verify' : ($user['verified'] === 'pending' ? 'Approve Request' : 'Verify'); ?>
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
                  <span class="badge <?php echo $user['verified'] === 'yes' ? 'bg-success' : ($user['verified'] === 'pending' ? 'bg-info text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars(strtoupper($user['verified'])); ?></span>
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
$og_title = "PHP Music";
$og_desc = "A simple, fast music player with user accounts and uploads.";
$og_image = "?action=get_app_icon&size=512";

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
        $og_title = htmlspecialchars($song_info['album']) . " (Song)";
        $og_desc = "Listen to this track on PHP Music.";
        $og_image = "?action=get_image&id=" . $share_id;
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
      $artist_name = $_GET['artist_name'] ?? null;
      if ($album_name) {
        $og_title = htmlspecialchars($album_name) . " - Album";
        $og_desc = "Listen to this album on PHP Music.";
        $view_config = ['type' => 'album_songs', 'param' => $album_name, 'sort' => 'title_asc'];
        if ($artist_id) {
          $view_config['filter_user_id'] = (int)$artist_id;
        }
        if ($artist_name) {
          $view_config['artist_name'] = $artist_name;
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
          $og_title = htmlspecialchars($artist_name) . " - Artist";
          $og_desc = "Listen to " . htmlspecialchars($artist_name) . " on PHP Music.";
          $og_image = "?action=get_profile_picture&id=" . (int)$share_id;
          $view_config = ['type' => 'artist_songs', 'param' => $artist_name, 'sort' => 'album_asc', 'filter_user_id' => (int)$share_id];
        }
      } else if ($share_id) {
        $og_title = htmlspecialchars($share_id) . " - Artist";
        $og_desc = "Listen to " . htmlspecialchars($share_id) . " on PHP Music.";
        $view_config = ['type' => 'artist_songs', 'param' => $share_id, 'sort' => 'album_asc'];
      }
      break;

    case 'playlist':
      $share_id = $_GET['id'] ?? null;
      $stmt = $db_for_share->prepare("SELECT id, name FROM playlists WHERE public_id = ?");
      $stmt->execute([$share_id]);
      $pl = $stmt->fetch();
      if ($pl) {
        $og_title = htmlspecialchars($pl['name']) . " - Playlist";
        $og_desc = "Listen to this playlist on PHP Music.";
        $view_config = ['type' => 'playlist_songs', 'param' => $share_id, 'sort' => 'manual_order'];
      }
      break;

    case 'mix':
      $share_id = $_GET['id'] ?? null;
      $stmt = $db_for_share->prepare("SELECT id FROM mixes WHERE public_id = ?");
      $stmt->execute([$share_id]);
      if ($stmt->fetch()) {
        $view_config = ['type' => 'mix_songs', 'param' => $share_id, 'sort' => 'manual_order'];
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
      banned INTEGER DEFAULT 0,
      settings TEXT
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
    if (!in_array('settings', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN settings TEXT;");
    if (!in_array('bio', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN bio TEXT;");
    if (!in_array('profile_background', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN profile_background BLOB;");
    if (!in_array('profile_background_type', $users_columns)) $db->exec("ALTER TABLE users ADD COLUMN profile_background_type TEXT;");
  }

  $db->exec("
    CREATE TABLE IF NOT EXISTS user_song_settings (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      volume_multiplier REAL DEFAULT 1.0,
      eq_bands TEXT,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

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
      lyrics TEXT,
      is_private INTEGER DEFAULT 0,
      replaygain REAL DEFAULT 0,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
  ");

  if ($music_table_exists) {
    if (!in_array('bitrate', $music_columns)) $db->exec("ALTER TABLE music ADD COLUMN bitrate INTEGER;");
    if (!in_array('lyrics', $music_columns)) $db->exec("ALTER TABLE music ADD COLUMN lyrics TEXT;");
    if (!in_array('replaygain', $music_columns)) $db->exec("ALTER TABLE music ADD COLUMN replaygain REAL DEFAULT 0;");
  }
  
  $db->exec("
    CREATE TABLE IF NOT EXISTS offline_songs (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");
  $db->exec("CREATE INDEX IF NOT EXISTS offline_user_id_idx ON offline_songs(user_id);");
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
  $playlists_columns = $db->query("PRAGMA table_info(playlists);")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('is_collaborative', $playlists_columns)) {
    $db->exec("ALTER TABLE playlists ADD COLUMN is_collaborative INTEGER DEFAULT 0;");
  }
  if (!in_array('is_private', $playlists_columns)) {
    $db->exec("ALTER TABLE playlists ADD COLUMN is_private INTEGER DEFAULT 0;");
  }
  if (!in_array('play_count', $playlists_columns)) {
    $db->exec("ALTER TABLE playlists ADD COLUMN play_count INTEGER DEFAULT 0;");
  }
  if ($music_table_exists && !in_array('is_private', $music_columns)) {
    $db->exec("ALTER TABLE music ADD COLUMN is_private INTEGER DEFAULT 0;");
  }

  $db->exec("
    CREATE TABLE IF NOT EXISTS playlist_invites (
      token TEXT PRIMARY KEY,
      playlist_id INTEGER NOT NULL,
      expires_at DATETIME,
      FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS playlist_collaborators (
      playlist_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      PRIMARY KEY (playlist_id, user_id),
      FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
  ");

  $db->exec("
    CREATE TABLE IF NOT EXISTS playlist_songs (
      playlist_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      added_by INTEGER,
      PRIMARY KEY (playlist_id, song_id),
      FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");

  $playlist_songs_cols = $db->query("PRAGMA table_info(playlist_songs);")->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('added_by', $playlist_songs_cols)) {
    $db->exec("ALTER TABLE playlist_songs ADD COLUMN added_by INTEGER;");
  }

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

  $db->exec("
    CREATE TABLE IF NOT EXISTS listen_later (
      user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, sort_order INTEGER,
      PRIMARY KEY (user_id, song_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS personal_notes (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS song_reactions (
      user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, reaction TEXT,
      PRIMARY KEY (user_id, song_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS song_comments (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, parent_id INTEGER DEFAULT NULL, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS community_posts (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS community_reactions (
      user_id INTEGER NOT NULL, post_id INTEGER NOT NULL, reaction TEXT,
      PRIMARY KEY (user_id, post_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS comment_reactions (
      user_id INTEGER NOT NULL, comment_id INTEGER NOT NULL, reaction TEXT,
      PRIMARY KEY (user_id, comment_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (comment_id) REFERENCES song_comments(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS blocks (
      blocker_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (blocker_id, blocked_id), FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS messages (
      id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL, receiver_id INTEGER NOT NULL, content TEXT, image BLOB, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_read INTEGER DEFAULT 0,
      FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    );
  ");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  if (!$stmt->fetch()) {
    $initial = 'M';
    $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
    $bg_color = $colors[array_rand($colors)];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="'.$bg_color.'"/><text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">' . htmlspecialchars($initial) . '</text></svg>';
    $db->prepare("INSERT INTO users (email, artist, password_hash, verified, profile_picture, profile_picture_type) VALUES (?, ?, ?, ?, ?, 'image/svg+xml')")
      ->execute(['musiclibrary@mail.com', 'Music Library', password_hash('musiclibrary', PASSWORD_DEFAULT), 'yes', $svg]);
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

function process_image_to_webp($imageData, $target_width = 640, $quality = 78) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return null;
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) return null;

  $src_w = imagesx($sourceImage);
  $src_h = imagesy($sourceImage);
  $min_dim = min($src_w, $src_h);
  $src_x = (int)(($src_w - $min_dim) / 2);
  $src_y = (int)(($src_h - $min_dim) / 2);

  // Prevent upscaling small images to retain pristine quality
  $final_width = min($min_dim, $target_width);

  $resizedImage = imagecreatetruecolor($final_width, $final_width);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, $src_x, $src_y, $final_width, $final_width, $min_dim, $min_dim);

  ob_start();
  imagewebp($resizedImage, null, $quality);
  $webpData = ob_get_clean();
  
  // Strict 70KB limit enforcer. If it exceeds 71,680 bytes, aggressively compress it.
  if (strlen($webpData) > 71680) {
    ob_start();
    imagewebp($resizedImage, null, 55); 
    $webpData = ob_get_clean();
  }

  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

function process_image_to_jpeg($imageData, $target_width = 640, $quality = 82) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) return null;

  $src_w = imagesx($sourceImage);
  $src_h = imagesy($sourceImage);
  $min_dim = min($src_w, $src_h);
  $src_x = (int)(($src_w - $min_dim) / 2);
  $src_y = (int)(($src_h - $min_dim) / 2);

  $final_width = min($min_dim, $target_width);

  $resizedImage = imagecreatetruecolor($final_width, $final_width);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, $src_x, $src_y, $final_width, $final_width, $min_dim, $min_dim);

  ob_start();
  imagejpeg($resizedImage, null, $quality);
  $jpegData = ob_get_clean();
  
  if (strlen($jpegData) > 71680) {
    ob_start();
    imagejpeg($resizedImage, null, 60);
    $jpegData = ob_get_clean();
  }

  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $jpegData;
}

// =========================================================================================
// EXTERNAL API ROUTER & URL UNTANGLER
// =========================================================================================

$raw_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_public_api = false;

// 1. Aggressive URL matching to detect the exact "access=api" flag regardless of slash mangling
if (strpos($raw_uri, 'access=api') !== false || (isset($_GET['access']) && strpos($_GET['access'], 'api') !== false)) {
  $is_public_api = true;

  // 2. URL Untangler: Extract parameters hidden behind bad slashes (e.g., access=api/?action=...)
  preg_match_all('/(?:[?&]|\/\?)([a-zA-Z0-9_]+)=([^&]+)/', $raw_uri, $matches);
    
  if (!empty($matches[1]) && !empty($matches[2])) {
    foreach ($matches[1] as $index => $key) {
      if (!isset($_GET[$key])) {
        $_GET[$key] = urldecode($matches[2][$index]);
      }
    }
  }

  // Ultimate fallback if "action" is still missing from the URI array
  if (empty($_GET['action'])) {
    if (preg_match('/action=([a-zA-Z0-9_]+)/', $raw_uri, $act_match)) {
      $_GET['action'] = $act_match[1];
    } else {
      $_GET['action'] = 'get_songs';
    }
  }

  // 3. Global API Firewall: Require Admin Password (api_key) for ALL external requests
  $api_extracted_action = $_GET['action'] ?? '';
  
  if (($_GET['api_key'] ?? '') !== ADMIN_PASSWORD) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"status":"error", "message":"API access denied. Valid api_key (Admin Password) required."}';
    exit;
  }
}

if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  
  // 4. STRICT INTERNAL API FIREWALL: Absolute Lockdown
  // If the request does NOT have ?access=api, we enforce strict origin checks.
  if (!$is_public_api) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, ':') !== false) {
      $host = explode(':', $host)[0];
    }
    
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_X_ORIGIN'] ?? '';
    if ($origin === 'null') $origin = ''; // Explicitly deny local file:/// origin bypasses
    
    $is_valid_internal = false;
    if ($origin && parse_url($origin, PHP_URL_HOST) === $host) {
      $is_valid_internal = true;
    } elseif ($referer && parse_url($referer, PHP_URL_HOST) === $host) {
      $is_valid_internal = true;
    } elseif (in_array($action, ['get_stream', 'get_image', 'get_app_icon', 'download_song', 'download_cover', 'export_playlist', 'export_favorites', 'export_offline', 'export_notes'])) {
      // Media routes are allowed internally without headers, but data JSON routes are strictly blocked!
      $is_valid_internal = true;
    }
    
    if (!$is_valid_internal) {
      http_response_code(403);
      send_json([
        'status' => 'error', 
        'message' => 'Access Denied: You must append ?access=api to your URL to fetch data externally.'
      ]);
    }
  }
  
  // Rate Limiting API - ONLY trigger write locks on POST requests or heavy actions to prevent locking concurrent reads on page load
  if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, ['search', 'full_scan'])) {
    try { 
      $db->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (ip TEXT PRIMARY KEY, hits INTEGER, last_reset INTEGER)"); 
      $ip = $_SERVER['REMOTE_ADDR'];
      $stmt_rl = $db->prepare("SELECT hits, last_reset FROM api_rate_limits WHERE ip = ?");
      $stmt_rl->execute([$ip]);
      $rl = $stmt_rl->fetch();
      $now = time();
      if ($rl) {
        if ($now - $rl['last_reset'] > 60) {
          $db->prepare("UPDATE api_rate_limits SET hits = 1, last_reset = ? WHERE ip = ?")->execute([$now, $ip]);
        } else {
          if ($rl['hits'] > 150) {
            http_response_code(429);
            die('{"status":"error", "message":"Rate limit exceeded. Try again in a minute."}');
          }
          $db->prepare("UPDATE api_rate_limits SET hits = hits + 1 WHERE ip = ?")->execute([$ip]);
        }
      } else {
        $db->prepare("INSERT INTO api_rate_limits (ip, hits, last_reset) VALUES (?, 1, ?)")->execute([$ip, $now]);
      }
    } catch(Exception $e) {}
  }

  // Run database schema updates ONLY ONCE per version/session to prevent massive write-lock congestion on concurrent AJAX requests
  if (!isset($_SESSION['db_initialized']) || $_SESSION['db_initialized'] !== APP_VERSION) {
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS activity_feed (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, target_name TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } catch(Exception $e) {}
    
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS user_song_settings (
        user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, volume_multiplier REAL DEFAULT 1.0, eq_bands TEXT,
        PRIMARY KEY (user_id, song_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
      );");
    } catch(Exception $e) {}

    try { $db->exec("ALTER TABLE playlist_songs ADD COLUMN added_by INTEGER;"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN is_edited INTEGER DEFAULT 0;"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE community_posts ADD COLUMN parent_id INTEGER DEFAULT NULL;"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE playlists ADD COLUMN is_private INTEGER DEFAULT 0;"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE playlists ADD COLUMN play_count INTEGER DEFAULT 0;"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE music ADD COLUMN is_private INTEGER DEFAULT 0;"); } catch(Exception $e) {}
    
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS playlist_invites (
        token TEXT PRIMARY KEY,
        playlist_id INTEGER NOT NULL,
        expires_at DATETIME,
        FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
      );");
    } catch(Exception $e) {}
    
    init_db($db);
    $_SESSION['db_initialized'] = APP_VERSION;
  }

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $user_id = $_SESSION['user_id'] ?? null;
  $is_super_admin = 0;
  if ($user_id) {
    $stmt_admin = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt_admin->execute([$user_id]);
    $user_email = $stmt_admin->fetchColumn();
    if ($user_email && strtolower(trim($user_email)) === 'musiclibrary@mail.com') {
      $is_super_admin = 1;
    }
  }
  $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  function format_user_text($text) {
    $text = htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    // Unwrap existing [url] tags to prevent double-wrapping during edits
    $text = preg_replace('/\[url\]\s*(.*?)\s*\[\/url\]/i', '$1', $text);
    // Automatically wrap URLs (http, https, www, or naked domains with multiple dots like phpmusic.rf.gd) in [url] tags safely
    $text = preg_replace('/(?<!\S)(https?:\/\/[^\s]+|(?:www\.)?(?:[a-zA-Z0-9\-]+\.)+[a-zA-Z]{2,}(?:\/[^\s]*)?)/i', '[url] $1 [/url]', $text);
    return $text;
  }

  switch ($action) {
    case 'toggle_collaborative':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      
      $stmt = $db->prepare("SELECT id, is_collaborative FROM playlists WHERE public_id = ? AND (user_id = ? OR {$is_super_admin} = 1)");
      $stmt->execute([$public_id, $user_id]);
      $pl = $stmt->fetch();
      
      if ($pl) {
        $new_val = $pl['is_collaborative'] ? 0 : 1;
        $db->prepare("UPDATE playlists SET is_collaborative = ? WHERE id = ?")->execute([$new_val, $pl['id']]);
        
        // If the playlist is made private, securely wipe all existing collaborators
        if ($new_val === 0) {
          $db->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = ?")->execute([$pl['id']]);
        }
        
        send_json([
          'status' => 'success', 
          'is_collaborative' => $new_val, 
          'message' => $new_val ? 'Playlist is collaborative.' : 'Playlist is now private. Collaborators removed.'
        ]);
      } else {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Permission denied.']);
      }
      break;

    case 'get_radio_tracks':
      $seed_id = intval($_GET['seed_id'] ?? 0);
      $stmt = $db->prepare("SELECT artist, genre FROM music WHERE id = ?");
      $stmt->execute([$seed_id]);
      $seed = $stmt->fetch();
      
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count";
      
      if ($seed) {
        $radio_stmt = $db->prepare("SELECT {$song_fields} FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE (m.genre = ? OR match_artist(m.artist, ?) = 1) AND m.id != ? ORDER BY RANDOM() LIMIT 15");
        $radio_stmt->execute([$user_id, $seed['genre'], $seed['artist'], $seed_id]);
        send_json($radio_stmt->fetchAll());
      } else {
        $radio_stmt = $db->prepare("SELECT {$song_fields} FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? ORDER BY RANDOM() LIMIT 15");
        $radio_stmt->execute([$user_id]);
        send_json($radio_stmt->fetchAll());
      }
      break;

    case 'get_queue_songs':
      $data = json_decode(file_get_contents('php://input'), true);
      $ids = $data['ids'] ?? [];
      if (empty($ids)) { send_json([]); }
      
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count";
      
      $stmt = $db->prepare("SELECT {$song_fields} FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.id IN ($placeholders)");
      $params = array_merge([$user_id], $ids);
      $stmt->execute($params);
      $results = $stmt->fetchAll();
      
      // Return the data sorted perfectly to match the queue array
      $sorted_results = [];
      $map = [];
      foreach ($results as $r) { $map[$r['id']] = $r; }
      foreach ($ids as $id) { if (isset($map[$id])) $sorted_results[] = $map[$id]; }
      
      send_json($sorted_results);
      break;

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
      header('Cache-Control: public, max-age=31536000, immutable');
      header('Pragma: cache');
      header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
      $size = intval($_GET['size'] ?? 192);
      echo '<?xml version="1.0" encoding="utf-8"?><svg width="'.$size.'px" height="'.$size.'px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" rx="6" fill="#0a0a0a"/><path d="M0 24L24 0V24H0Z" fill="#141414" clip-path="inset(0px round 6px)"/><path d="M4 10V13" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/><path d="M16 10V13" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/><path d="M7 7L7 16" stroke="#ff0044" stroke-width="1.7" stroke-linecap="round"/><path d="M13 7L13 16" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/><path d="M19 7L19 16" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/><path d="M10 4L10 19" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/></svg>';
      exit;

    case 'get_session':
      if ($user_id) {
        try { $db->exec("ALTER TABLE users ADD COLUMN last_active DATETIME;"); } catch(Exception $e) {}
        $db->prepare("UPDATE users SET last_active = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id]);

        $stmt = $db->prepare("SELECT id, email, artist, bio, verified, last_upload_date, daily_upload_count, banned, settings FROM users WHERE id = ?");
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
      
      $initial = mb_strtoupper(mb_substr($artist, 0, 1, 'UTF-8'));
      $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
      $bg_color = $colors[array_rand($colors)];
      $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="'.$bg_color.'"/><text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">' . htmlspecialchars($initial) . '</text></svg>';
      
      $stmt = $db->prepare("INSERT INTO users (email, artist, password_hash, profile_picture, profile_picture_type) VALUES (?, ?, ?, ?, 'image/svg+xml')");
      $stmt->execute([$email, $artist, $hash, $svg]);
      
      // Automatically log the user in
      $new_user_id = $db->lastInsertId();
      $_SESSION['user_id'] = $new_user_id;
      $_SESSION['user_artist'] = $artist;
      try { $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'logged in', '')")->execute([$new_user_id]); } catch(Exception $e) {}
      
      send_json(['status' => 'success', 'message' => 'Registration successful. You are now logged in!']);
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
        
        try {
          $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'logged in', '')")->execute([$user['id']]);
        } catch(Exception $e) {}

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

    case 'save_bio':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $bio = trim(htmlspecialchars($data['bio'] ?? '', ENT_QUOTES, 'UTF-8'));
      $db->prepare("UPDATE users SET bio = ? WHERE id = ?")->execute([$bio, $user_id]);
      send_json(['status' => 'success', 'message' => 'Bio updated.']);
      break;

    case 'get_connections':
      $target_user_id = (int)($_GET['id'] ?? 0);
      $conn_type = $_GET['conn_type'] ?? 'followers';
      if ($conn_type === 'following') {
        $stmt = $db->prepare("SELECT u.id, u.artist FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? AND u.banned = 0");
      } else {
        $stmt = $db->prepare("SELECT u.id, u.artist FROM follows f JOIN users u ON f.follower_id = u.id WHERE f.following_id = ? AND u.banned = 0");
      }
      $stmt->execute([$target_user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_recommended_artists':
      $target_user_id = (int)($_GET['target_id'] ?? 0);
      $stmt = $db->prepare("SELECT id, artist FROM users WHERE id != ? AND id != ? AND banned = 0 AND email NOT LIKE 'deleted_%' ORDER BY RANDOM() LIMIT 8");
      $stmt->execute([$user_id ?? 0, $target_user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'upload_profile_background':
      if (!$user_id) { http_response_code(403); send_json(['status' => 'error', 'message' => 'Not logged in.']); }
      if (isset($_FILES['profile_background'])) {
        $file = $_FILES['profile_background'];
        if ($file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); send_json(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]); }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) { http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid file type.']); }
        $imageData = file_get_contents($file['tmp_name']);
        
        // Resize horizontally for backgrounds
        $sourceImage = @imagecreatefromstring($imageData);
        if ($sourceImage) {
          $final_width = 1200;
          $final_height = 400;
          $resizedImage = imagecreatetruecolor($final_width, $final_height);
          imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $final_width, $final_height, imagesx($sourceImage), imagesy($sourceImage));
          ob_start();
          imagewebp($resizedImage, null, 80);
          $webpData = ob_get_clean();
          imagedestroy($sourceImage); imagedestroy($resizedImage);
          
          $db->prepare("UPDATE users SET profile_background = ?, profile_background_type = 'image/webp' WHERE id = ?")->execute([$webpData, $user_id]);
          send_json(['status' => 'success', 'message' => 'Background updated.']);
        } else {
          http_response_code(500); send_json(['status' => 'error', 'message' => 'Failed to process image.']);
        }
      } else {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'No file uploaded.']);
      }
      break;

    case 'get_profile_background':
      header('Cache-Control: public, max-age=31536000, immutable');
      $pic_user_id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT profile_background, profile_background_type, artist FROM users WHERE id = ?");
      $stmt->execute([$pic_user_id]);
      $pic_data = $stmt->fetch();
      
      if ($pic_data && $pic_data['profile_background']) {
        header('Content-Type: ' . $pic_data['profile_background_type']);
        echo $pic_data['profile_background'];
        exit;
      }
      
      $artist_name = $pic_data['artist'] ?? 'Unknown';
      $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
      
      // Generate 3 deterministic colors based on the artist name
      $color_index1 = hexdec(substr(md5($artist_name . 'bg1'), 0, 6)) % count($colors);
      $color_index2 = hexdec(substr(md5($artist_name . 'bg2'), 0, 6)) % count($colors);
      $color_index3 = hexdec(substr(md5($artist_name . 'bg3'), 0, 6)) % count($colors);
      
      $c1 = $colors[$color_index1];
      $c2 = $colors[$color_index2];
      $c3 = $colors[$color_index3];
      
      header('Content-Type: image/svg+xml');
      echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 400"><defs><linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="' . $c1 . '" /><stop offset="50%" stop-color="' . $c2 . '" /><stop offset="100%" stop-color="' . $c3 . '" /></linearGradient></defs><rect width="1200" height="400" fill="url(#grad)"/></svg>';
      exit;

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
      header('Cache-Control: public, max-age=31536000, immutable');
      header('Pragma: cache');
      header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
      $pic_user_id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT profile_picture, profile_picture_type, artist FROM users WHERE id = ?");
      $stmt->execute([$pic_user_id]);
      $pic_data = $stmt->fetch();
      
      if ($pic_data && $pic_data['profile_picture']) {
        header('Content-Type: ' . $pic_data['profile_picture_type']);
        echo $pic_data['profile_picture'];
        exit;
      } 
      
      // Advanced Fallback: Search for the newest song uploaded by this user OR featuring this artist name
      $artist_name = $pic_data['artist'] ?? '';
      $song_img = null;
      
      if ($artist_name) {
         $stmt2 = $db->prepare("SELECT image FROM music WHERE (user_id = ? OR match_artist(artist, ?) = 1) AND image IS NOT NULL ORDER BY id DESC LIMIT 1");
         $stmt2->execute([$pic_user_id, $artist_name]);
         $song_img = $stmt2->fetchColumn();
      } else {
         $stmt2 = $db->prepare("SELECT image FROM music WHERE user_id = ? AND image IS NOT NULL ORDER BY id DESC LIMIT 1");
         $stmt2->execute([$pic_user_id]);
         $song_img = $stmt2->fetchColumn();
      }

      if ($song_img) {
        header('Content-Type: image/webp');
        echo $song_img;
      } else {
        header('Content-Type: image/svg+xml');
        $initial = $artist_name ? mb_strtoupper(mb_substr($artist_name, 0, 1, 'UTF-8')) : '?';
        $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
        // Generate deterministic color based on artist name so it stays consistent between reloads
        $color_index = hexdec(substr(md5($artist_name), 0, 6)) % count($colors);
        $bg_color = $colors[$color_index];
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="'.$bg_color.'"/><text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">' . htmlspecialchars($initial) . '</text></svg>';
      }
      exit;

    case 'delete_account_all':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("SELECT file FROM music WHERE user_id = ?");
      $stmt->execute([$user_id]);
      while ($row = $stmt->fetch()) { if ($row['file'] && file_exists($row['file'])) @unlink($row['file']); }
      $db->prepare("DELETE FROM music WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM personal_notes WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM history WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM play_counts WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM favorites WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM offline_songs WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM playlists WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM playlist_collaborators WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM follows WHERE follower_id = ? OR following_id = ?")->execute([$user_id, $user_id]);
      $db->prepare("DELETE FROM listen_later WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM activity_feed WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM listen_later WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM activity_feed WHERE user_id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$user_id, $user_id]);
      $db->prepare("DELETE FROM blocks WHERE blocker_id = ? OR blocked_id = ?")->execute([$user_id, $user_id]);

      $new_email = 'deleted_' . $user_id . '_' . time() . '@mail.com';
      $db->prepare("UPDATE users SET email = ?, artist = 'Deleted User', bio = NULL, password_hash = NULL, backup_key = NULL, profile_picture = NULL, profile_background = NULL, profile_background_type = NULL WHERE id = ?")->execute([$new_email, $user_id]);
      
      session_destroy();
      send_json(['status' => 'success']);
      break;

    case 'delete_account_keep_data':
      if (!$user_id) { http_response_code(403); exit; }
      $raw_str = bin2hex(random_bytes(16));
      $hash = password_hash($raw_str, PASSWORD_DEFAULT);
      $final_key = $user_id . '-' . $raw_str;
      $new_email = 'deleted_' . $user_id . '_' . time() . '@mail.com';
      $new_artist = 'Deleted User';
      $db->prepare("UPDATE users SET email = ?, artist = ?, bio = NULL, password_hash = NULL, backup_key = ?, profile_picture = NULL, profile_background = NULL, profile_background_type = NULL WHERE id = ?")
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
        $initial = mb_strtoupper(mb_substr($n_artist, 0, 1, 'UTF-8'));
        $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
        $bg_color = $colors[array_rand($colors)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="'.$bg_color.'"/><text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">' . htmlspecialchars($initial) . '</text></svg>';
        
        $db->prepare("UPDATE users SET email = ?, artist = ?, password_hash = ?, backup_key = NULL, profile_picture = ?, profile_picture_type = 'image/svg+xml' WHERE id = ?")
           ->execute([$n_email, $n_artist, $hash, $svg, $r_user_id]);
           
        // Automatically log the user in
        $_SESSION['user_id'] = $r_user_id;
        $_SESSION['user_artist'] = $n_artist;
        try { $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'logged in', '')")->execute([$r_user_id]); } catch(Exception $e) {}
        
        send_json(['status' => 'success', 'message' => 'Account restored successfully. You are now logged in!']);
      } else {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid or expired backup key.']);
      }
      break;

    case 'full_scan':
      if (!$is_super_admin) { http_response_code(403); exit; }
      perform_full_scan($db);
      exit;

    case 'request_verification':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("SELECT verified FROM users WHERE id = ?");
      $stmt->execute([$user_id]);
      $current = $stmt->fetchColumn();
      if ($current === 'no') {
        $db->prepare("UPDATE users SET verified = 'pending' WHERE id = ?")->execute([$user_id]);
        send_json(['status' => 'success', 'message' => 'Verification request sent to admin.']);
      } else if ($current === 'pending') {
        send_json(['status' => 'error', 'message' => 'Verification request is already pending.']);
      } else {
        send_json(['status' => 'error', 'message' => 'Account is already verified.']);
      }
      break;

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
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['mp3', 'flac', 'm4a', 'ogg', 'wav'];
        if (!in_array($ext, $allowed_exts)) {
          http_response_code(400);
          send_json(['status' => 'error', 'message' => 'Security Error: Invalid file format. Only MP3, FLAC, M4A, OGG, and WAV are allowed.']);
        }
        
        $getID3 = new getID3;
        $info = $getID3->analyze($file['tmp_name']);
        getid3_lib::CopyTagsToComments($info);

        $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
        $main_artist = trim(preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist)[0]);
        if (empty($main_artist)) $main_artist = 'Unknown Artist';
        
        $artist_path = sanitize_for_path($main_artist);
        // SHARDING: Split uploads into 256 physical subfolders to prevent Linux inode collapse
        $shard = substr(md5($artist_path), 0, 2);
        $upload_dir = MUSIC_DIR . '/uploads/' . $shard . '/' . $artist_path;
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
          
          $replaygain = 0;
          if (!empty($info['tags']['id3v2']['TXXX'])) {
            foreach($info['tags']['id3v2']['TXXX'] as $txxx) {
              if (strtoupper($txxx['description'] ?? '') === 'REPLAYGAIN_TRACK_GAIN') {
                $replaygain = (float)str_replace(' dB', '', $txxx['data']);
              }
            }
          } elseif (!empty($info['comments']['replaygain_track_gain'][0])) {
            $replaygain = (float)str_replace(' dB', '', $info['comments']['replaygain_track_gain'][0]);
          }

          $raw_image_data = isset($info['comments']['picture'][0]['data']) ? $info['comments']['picture'][0]['data'] : null;
          $webp_image_data = process_image_to_webp($raw_image_data);

          $filePath = str_replace('\\', '/', $filePath); // Normalize slashes
          $actual_mtime = filemtime($filePath); // Get exact OS file modification time
          $is_private = intval($_POST['is_private'] ?? 0);
          
          $stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, bitrate, image, last_modified, is_private, replaygain) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $stmt->execute([$user_id, $filePath, $title, $artist, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $actual_mtime, $is_private, $replaygain]);
          
          if ($is_private == 0) {
            $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, ?, ?)")->execute([$user_id, 'uploaded a new song', $title]);
          }

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
      
      if ($song && ($song['user_id'] == $user_id || $is_super_admin)) {
        $db->prepare("DELETE FROM music WHERE id = ?")->execute([$song_id]);
        $file_path = $song['file'];
        if (!file_exists($file_path)) {
          $dynamic_path = MUSIC_DIR . '/uploads/' . basename(dirname(dirname($file_path))) . '/' . basename(dirname($file_path)) . '/' . basename($file_path);
          if (file_exists($dynamic_path)) $file_path = $dynamic_path;
        }
        if ($file_path && file_exists($file_path)) {
          @unlink($file_path);
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

      if ($song) {
        $file_path = $song['file'];
        if (!file_exists($file_path)) {
          $dynamic_path = MUSIC_DIR . '/uploads/' . basename(dirname(dirname($file_path))) . '/' . basename(dirname($file_path)) . '/' . basename($file_path);
          if (file_exists($dynamic_path)) $file_path = $dynamic_path;
        }

        if (file_exists($file_path)) {
          while (ob_get_level() > 0) { @ob_end_clean(); }
          $ext = pathinfo($file_path, PATHINFO_EXTENSION);
          $dl_name = trim(($song['title'] ?? '') . (($song['artist'] && $song['title']) ? ' - ' : '') . ($song['artist'] ?? ''));
          $dl_name = $dl_name ? $dl_name . '.' . $ext : basename($file_path);
          $encoded = rawurlencode($dl_name);
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          header('Content-Type: ' . finfo_file($finfo, $file_path));
          finfo_close($finfo);
          header('Content-Length: ' . filesize($file_path));
          header("Content-Disposition: attachment; filename=\"song." . $ext . "\"; filename*=UTF-8''" . $encoded);
          @flush();
          readfile($file_path);
          exit;
        }
      }
      http_response_code(404);
      send_json(['status' => 'error', 'message' => 'File not found.']);
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

      if ($song && ($song['user_id'] == $user_id || $is_super_admin)) {
        $file_path = $song['file'];
        if (!file_exists($file_path)) {
          $dynamic_path = MUSIC_DIR . '/uploads/' . basename(dirname(dirname($file_path))) . '/' . basename(dirname($file_path)) . '/' . basename($file_path);
          if (file_exists($dynamic_path)) $file_path = $dynamic_path;
        }
        $song['file'] = $file_path; // Override with resolved path

        $is_private = intval($_POST['is_private'] ?? 0);
        $update_fields = ["title = ?", "artist = ?", "album = ?", "genre = ?", "lyrics = ?", "is_private = ?"];
        $update_params = [$new_title, $new_artist, $new_album, $new_genre, $new_lyrics, $is_private];

        $jpeg_data = null;
        $webp_data = null;
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
          $raw_img = file_get_contents($_FILES['cover_image']['tmp_name']);
          $webp_data = process_image_to_webp($raw_img);
          $jpeg_data = process_image_to_jpeg($raw_img);
          if ($webp_data) {
            $update_fields[] = "image = ?";
            $update_params[] = $webp_data;
            
            // Backup the cover to physical disk so it survives database deletions
            if ($jpeg_data && file_exists($song['file'])) {
                $file_info = pathinfo($song['file']);
                $cover_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.jpg';
                @file_put_contents($cover_path, $jpeg_data);
            }
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
            $tagformats = ['metaflac'];
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
            'genre' => [htmlspecialchars_decode($new_genre, ENT_QUOTES)],
            'unsynchronised_lyric' => [htmlspecialchars_decode($new_lyrics, ENT_QUOTES)]
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
          clearstatcache(true, $song['file']);
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
      // MASS USE OPTIMIZATION: 5-Minute Micro-Cache for heavy Discovery queries
      $cache_file = sys_get_temp_dir() . '/phpmusic_explore_cache_' . ($user_id ? $user_id : 'guest') . '.json';
      if (file_exists($cache_file)) {
        $cached_content = @file_get_contents($cache_file);
        if (!empty($cached_content) && (time() - filemtime($cache_file)) < 300) {
          $decoded = json_decode($cached_content);
          if ($decoded && isset($decoded->shelves)) {
            header('Content-Type: application/json; charset=utf-8');
            echo $cached_content;
            exit;
          }
        }
      }

      $shelves = [];
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count";
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
        SELECT m.album, m.artist, m.user_id, m.id, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m
        WHERE m.album != 'Unknown Album' AND m.album != '' AND m.album IS NOT NULL " . ($user_id ? "AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)" : "") . "
        ORDER BY RANDOM() LIMIT 100
      ");
      $album_params = [];
      if ($user_id) {
        $album_params[':user_id'] = $user_id;
      }
      $discovery_stmt->execute($album_params);
      $disc_rows = $discovery_stmt->fetchAll();
      $discovery_albums = [];
      foreach ($disc_rows as $row) {
        $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        if (!is_array($parts)) $parts = [$row['artist']];
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($row['album'] . ':::' . $p);
            if (!isset($discovery_albums[$key])) {
              $discovery_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc']];
            } else {
              $discovery_albums[$key]['song_count']++;
              $discovery_albums[$key]['total_plays'] += $row['pc'];
            }
          }
        }
      }
      shuffle($discovery_albums);
      $discovery_albums = array_slice($discovery_albums, 0, 10);
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
          $shelves[] = ['title' => 'From Your Artists: New Tracks', 'type' => 'songs', 'items' => $rec_followed_songs];
        }

        $rec_followed_albums_stmt = $db->prepare("
          SELECT m.album, m.artist, m.user_id, m.id, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m
          WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id) AND m.album != 'Unknown Album' AND m.album != '' AND m.album IS NOT NULL
          ORDER BY RANDOM() LIMIT 100
        ");
        $rec_followed_albums_stmt->execute([':user_id' => $user_id]);
        $fol_rows = $rec_followed_albums_stmt->fetchAll();
        $rec_followed_albums = [];
        foreach ($fol_rows as $row) {
          $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
          if (!is_array($parts)) $parts = [$row['artist']];
          foreach ($parts as $part) {
            $p = trim($part);
            if ($p !== '') {
              $key = strtolower($row['album'] . ':::' . $p);
              if (!isset($rec_followed_albums[$key])) {
                $rec_followed_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc']];
              } else {
                $rec_followed_albums[$key]['song_count']++;
                $rec_followed_albums[$key]['total_plays'] += $row['pc'];
              }
            }
          }
        }
        shuffle($rec_followed_albums);
        $rec_followed_albums = array_slice($rec_followed_albums, 0, 10);
        if (count($rec_followed_albums) > 0) {
          $shelves[] = ['title' => 'From Your Artists: New Albums', 'type' => 'albums', 'items' => $rec_followed_albums];
        }
      }

      // MASS USE OPTIMIZATION: Save the generated response to the cache file before sending
      $final_json = json_encode(['shelves' => $shelves], JSON_INVALID_UTF8_SUBSTITUTE);
      if ($final_json) {
        @file_put_contents($cache_file, $final_json);
      }
      
      header('Content-Type: application/json; charset=utf-8');
      echo $final_json ?: '{"shelves":[]}';
      exit;
    
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
      $where_clauses[] = "(m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
      $params[] = $user_id;
      $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
      
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? " . $where_sql . " " . $order_by . $limit_clause);
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
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_offline_ids':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("SELECT song_id FROM offline_songs WHERE user_id = ?");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_offline_songs':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY os.sort_order ASC',
        'added_newest' => 'ORDER BY os.sort_order DESC',
        'added_oldest' => 'ORDER BY os.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      
      $is_all = isset($_GET['all']) && $_GET['all'] == '1';
      $current_limit = $is_all ? '' : $limit_clause;

      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count FROM music m JOIN offline_songs os ON m.id = os.song_id LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE os.user_id = ? " . $order_by . " " . $current_limit);
      $stmt->execute([$user_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'toggle_offline':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $stmt = $db->prepare("SELECT song_id FROM offline_songs WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM offline_songs WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        send_json(['status' => 'removed', 'is_offline' => false]);
      } else {
        $stmt = $db->prepare("SELECT MAX(sort_order) as max_order FROM offline_songs WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $max_order = $stmt->fetchColumn() ?? 0;
        $db->prepare("INSERT INTO offline_songs (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
        send_json(['status' => 'added', 'is_offline' => true]);
      }
      break;

    case 'update_offline_order':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $ordered_ids = $data['ids'];
      $db->beginTransaction();
      try {
        foreach ($ordered_ids as $index => $song_id) {
          $db->prepare("UPDATE offline_songs SET sort_order = ? WHERE user_id = ? AND song_id = ?")
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
      
    case 'export_offline':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("SELECT m.file, m.title, m.artist, m.album FROM offline_songs os JOIN music m ON os.song_id = m.id WHERE os.user_id = ? ORDER BY os.sort_order ASC");
      $stmt->execute([$user_id]);
      $rows = $stmt->fetchAll();
      if (empty($rows)) { http_response_code(404); exit; }

      $song_data = array_map(function($row) { return ['title' => $row['title'], 'artist' => $row['artist'], 'album' => $row['album'], 'filename' => basename(str_replace('\\', '/', $row['file']))]; }, $rows);
      $export_data = ['name' => 'Offline Music', 'songs' => $song_data];
      
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="offline_music.json"');
      echo json_encode($export_data, JSON_PRETTY_PRINT);
      exit;

    case 'import_offline':
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
        $stmt_insert = $db->prepare("REPLACE INTO offline_songs (user_id, song_id, sort_order) VALUES (?, ?, ?)");
        
        $stmt_order = $db->prepare("SELECT MAX(sort_order) FROM offline_songs WHERE user_id = ?");
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
            if ($album !== '') { $stmt_taa->execute([$title, $artist, $album]); $found_id = $stmt_taa->fetchColumn(); }
            if (!$found_id) { $stmt_ta->execute([$title, $artist]); $found_id = $stmt_ta->fetchColumn(); }
            if (!$found_id) { $stmt_t_artist_match->execute([$title, $artist]); $found_id = $stmt_t_artist_match->fetchColumn(); }
            if (!$found_id) { $stmt_ta_like->execute(['%' . $title . '%', '%' . $artist . '%']); $found_id = $stmt_ta_like->fetchColumn(); }
          }
          if (!$found_id && $filename !== '') {
            $stmt_file->execute(['%/' . $filename, '%\\' . $filename]); $found_id = $stmt_file->fetchColumn();
            if (!$found_id) { $stmt_file->execute(['%' . $filename, '%' . $filename]); $found_id = $stmt_file->fetchColumn(); }
          }
          if ($found_id) {
            $order++; $stmt_insert->execute([$user_id, $found_id, $order]);
            if ($stmt_insert->rowCount() > 0) $song_count++;
          }
        }
        $db->commit();
        send_json(['status' => 'success', 'message' => "Offline list imported with {$song_count} songs."]);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500); send_json(['status' => 'error', 'message' => 'Database error during import.']);
      }
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
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, 1 as is_favorite FROM music m JOIN favorites f ON m.id = f.song_id WHERE f.user_id = ? " . $order_by . $limit_clause);
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
        SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.bitrate, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT artist FROM users WHERE id = ps.added_by) as added_by_name
        FROM music m
        JOIN playlist_songs ps ON m.id = ps.song_id
        JOIN playlists p ON ps.playlist_id = p.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE p.public_id = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)
        {$order_by} {$current_limit}
      ");
      $stmt->execute([$user_id, $public_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_following':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("SELECT u.artist as name, u.id as id FROM follows f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? AND u.banned = 0 AND u.email NOT LIKE 'deleted_%' ORDER BY u.artist COLLATE NOCASE ASC " . $limit_clause);
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
        
        try { 
          $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'favorited', (SELECT title FROM music WHERE id = ?))")->execute([$user_id, $song_id]); 
        } catch(Exception $e) {}
        
        send_json(['status' => 'added', 'is_favorite' => true]);
      }
      break;
    
    case 'get_listen_later':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY ll.sort_order ASC', 'added_newest' => 'ORDER BY ll.sort_order DESC', 'added_oldest' => 'ORDER BY ll.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC', 'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC', 'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count FROM music m JOIN listen_later ll ON m.id = ll.song_id LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE ll.user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'toggle_listen_later':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $stmt = $db->prepare("SELECT song_id FROM listen_later WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM listen_later WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        send_json(['status' => 'removed']);
      } else {
        $max_order = $db->query("SELECT MAX(sort_order) FROM listen_later WHERE user_id = $user_id")->fetchColumn() ?? 0;
        $db->prepare("INSERT INTO listen_later (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
        send_json(['status' => 'added']);
      }
      break;

    case 'update_listen_later_order':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->beginTransaction();
      foreach ($data['ids'] as $index => $song_id) {
        $db->prepare("UPDATE listen_later SET sort_order = ? WHERE user_id = ? AND song_id = ?")->execute([$index, $user_id, $song_id]);
      }
      $db->commit();
      send_json(['status' => 'success']);
      break;

    case 'get_notes':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'newest';
      $search = $_GET['q'] ?? '';
      $order_by = ['newest' => 'ORDER BY created_at DESC', 'oldest' => 'ORDER BY created_at ASC', 'modified' => 'ORDER BY updated_at DESC'][$sort_key] ?? 'ORDER BY created_at DESC';
      
      $where = "WHERE user_id = ?";
      $params = [$user_id];
      if ($search !== '') {
        $where .= " AND (title LIKE ? OR content LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
      }
      
      $stmt = $db->prepare("SELECT * FROM personal_notes $where $order_by $limit_clause");
      $stmt->execute($params);
      send_json($stmt->fetchAll());
      break;
    
    case 'save_note':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $note_id = $data['id'] ?? null;
      $title = htmlspecialchars($data['title']);
      $content = format_user_text($data['content']);
      if ($note_id) {
        $db->prepare("UPDATE personal_notes SET title = ?, content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$title, $content, $note_id, $user_id]);
      } else {
        $db->prepare("INSERT INTO personal_notes (user_id, title, content) VALUES (?, ?, ?)")->execute([$user_id, $title, $content]);
      }
      send_json(['status' => 'success']);
      break;

    case 'delete_note':
      if (!$user_id) { http_response_code(403); exit; }
      $db->prepare("DELETE FROM personal_notes WHERE id = ? AND user_id = ?")->execute([json_decode(file_get_contents('php://input'), true)['id'], $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'get_song_comments':
      $song_id = intval($_GET['song_id']);
      $sort = $_GET['sort'] ?? 'newest';
      $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
      $offset = ($page - 1) * 25;
      
      $order_by = "ORDER BY c.created_at DESC";
      if ($sort === 'oldest') $order_by = "ORDER BY c.created_at ASC";
      if ($sort === 'most_liked') $order_by = "ORDER BY like_count DESC, c.created_at DESC";
      if ($sort === 'most_replied') $order_by = "ORDER BY (SELECT COUNT(*) FROM song_comments WHERE parent_id = c.id) DESC, c.created_at DESC";

      $root_stmt = $db->prepare("
        SELECT c.*, u.profile_picture_type, u.id as u_id,
        CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as artist,
        CASE WHEN u.banned = 1 OR u.email LIKE 'deleted_%' THEN 1 ELSE 0 END as is_disabled,
        (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction = 'like') as like_count,
        (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction = 'dislike') as dislike_count,
        (SELECT reaction FROM comment_reactions WHERE comment_id = c.id AND user_id = ?) as my_reaction
        FROM song_comments c JOIN users u ON c.user_id = u.id 
        WHERE c.song_id = ? AND c.parent_id IS NULL $order_by LIMIT 25 OFFSET ?
      ");
      $root_stmt->execute([$user_id ?? 0, $song_id, $offset]);
      $roots = $root_stmt->fetchAll();

      $root_ids = array_column($roots, 'id');
      $replies_filtered = [];
      if (!empty($root_ids)) {
        $placeholders = implode(',', array_fill(0, count($root_ids), '?'));
        $reply_stmt = $db->prepare("
          SELECT c.*, u.profile_picture_type, u.id as u_id,
          CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as artist,
          CASE WHEN u.banned = 1 OR u.email LIKE 'deleted_%' THEN 1 ELSE 0 END as is_disabled,
          (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction = 'like') as like_count,
          (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction = 'dislike') as dislike_count,
          (SELECT reaction FROM comment_reactions WHERE comment_id = c.id AND user_id = ?) as my_reaction
          FROM song_comments c JOIN users u ON c.user_id = u.id 
          WHERE c.song_id = ? AND c.parent_id IN ($placeholders)
          ORDER BY c.created_at ASC
        ");
        $params = array_merge([$user_id ?? 0, $song_id], $root_ids);
        $reply_stmt->execute($params);
        $all_replies = $reply_stmt->fetchAll();

        $rcounts = [];
        foreach ($all_replies as $r) {
          $pid = $r['parent_id'];
          if (!isset($rcounts[$pid])) $rcounts[$pid] = 0;
          if ($rcounts[$pid] < 25) { // Strict limit of 25 replies per comment
            $replies_filtered[] = $r;
            $rcounts[$pid]++;
          }
        }
      }

      $likes = $db->prepare("SELECT reaction, COUNT(*) as c FROM song_reactions WHERE song_id = ? GROUP BY reaction");
      $likes->execute([$song_id]);
      $reaction_counts = ['like'=>0, 'dislike'=>0];
      foreach($likes->fetchAll() as $r) { $reaction_counts[$r['reaction']] = $r['c']; }
      
      $my_reaction = null;
      if ($user_id) {
        $stmt = $db->prepare("SELECT reaction FROM song_reactions WHERE user_id = ? AND song_id = ?");
        $stmt->execute([$user_id, $song_id]);
        $my_reaction = $stmt->fetchColumn();
      }
      send_json(['comments' => array_merge($roots, $replies_filtered), 'reactions' => $reaction_counts, 'my_reaction' => $my_reaction]);
      break;

    case 'toggle_song_reaction':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['song_id']);
      $reaction = $data['reaction']; 
      $existing = $db->prepare("SELECT reaction FROM song_reactions WHERE user_id = ? AND song_id = ?");
      $existing->execute([$user_id, $song_id]);
      if ($existing->fetchColumn() === $reaction) {
        $db->prepare("DELETE FROM song_reactions WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
      } else {
        $db->prepare("REPLACE INTO song_reactions (user_id, song_id, reaction) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $reaction]);
      }
      send_json(['status' => 'success']);
      break;

    case 'toggle_comment_reaction':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $comment_id = intval($data['comment_id']);
      $reaction = $data['reaction'];
      $existing = $db->prepare("SELECT reaction FROM comment_reactions WHERE user_id = ? AND comment_id = ?");
      $existing->execute([$user_id, $comment_id]);
      if ($existing->fetchColumn() === $reaction) {
        $db->prepare("DELETE FROM comment_reactions WHERE user_id = ? AND comment_id = ?")->execute([$user_id, $comment_id]);
      } else {
        $db->prepare("REPLACE INTO comment_reactions (user_id, comment_id, reaction) VALUES (?, ?, ?)")->execute([$user_id, $comment_id, $reaction]);
      }
      send_json(['status' => 'success']);
      break;

    case 'add_song_comment':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $content = format_user_text($data['content']);
      $parent_id = empty($data['parent_id']) ? null : intval($data['parent_id']);
      $db->prepare("INSERT INTO song_comments (user_id, song_id, parent_id, content) VALUES (?, ?, ?, ?)")->execute([$user_id, $data['song_id'], $parent_id, $content]);
      send_json(['status' => 'success']);
      break;

    case 'edit_song_comment':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $content = format_user_text($data['content']);
      $db->prepare("UPDATE song_comments SET content = ? WHERE id = ? AND user_id = ?")->execute([$content, intval($data['comment_id']), $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'delete_song_comment':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("DELETE FROM song_comments WHERE id = ? AND (user_id = ? OR {$is_super_admin} = 1)")->execute([intval($data['comment_id']), $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'get_community':
      $sort_key = $_GET['sort'] ?? 'newest';
      $search = $_GET['q'] ?? '';
      $order_by = 'ORDER BY p.created_at DESC';
      $join_cond = "";
      $where_cond = "WHERE p.parent_id IS NULL";
      $params = [$user_id ?? 0];
      
      if ($search !== '') {
        $where_cond .= " AND p.content LIKE ?";
        $params[] = "%$search%";
      }

      if ($sort_key === 'most_liked') {
        $order_by = 'ORDER BY like_count DESC, p.created_at DESC';
      } elseif ($sort_key === 'most_replied') {
        $order_by = 'ORDER BY reply_count DESC, p.created_at DESC';
      } elseif ($sort_key === 'following' && $user_id) {
        $join_cond = "JOIN follows f ON f.following_id = p.user_id AND f.follower_id = " . intval($user_id);
      }
      
      $root_stmt = $db->prepare("
        SELECT p.*, 
        CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as artist,
        CASE WHEN u.banned = 1 OR u.email LIKE 'deleted_%' THEN 1 ELSE 0 END as is_disabled,
        (SELECT COUNT(*) FROM community_reactions WHERE post_id = p.id AND reaction = 'like') as like_count,
        (SELECT COUNT(*) FROM community_reactions WHERE post_id = p.id AND reaction = 'dislike') as dislike_count,
        (SELECT COUNT(*) FROM community_posts WHERE parent_id = p.id) as reply_count,
        (SELECT reaction FROM community_reactions WHERE post_id = p.id AND user_id = ?) as my_reaction 
        FROM community_posts p JOIN users u ON p.user_id = u.id $join_cond $where_cond $order_by $limit_clause
      ");
      $root_stmt->execute($params);
      $roots = $root_stmt->fetchAll();
      
      $root_ids = array_column($roots, 'id');
      $replies_filtered = [];
      if (!empty($root_ids)) {
        $placeholders = implode(',', array_fill(0, count($root_ids), '?'));
        $reply_stmt = $db->prepare("
          SELECT p.*, 
          CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as artist,
          CASE WHEN u.banned = 1 OR u.email LIKE 'deleted_%' THEN 1 ELSE 0 END as is_disabled,
          (SELECT COUNT(*) FROM community_reactions WHERE post_id = p.id AND reaction = 'like') as like_count,
          (SELECT COUNT(*) FROM community_reactions WHERE post_id = p.id AND reaction = 'dislike') as dislike_count,
          (SELECT COUNT(*) FROM community_posts WHERE parent_id = p.id) as reply_count,
          (SELECT reaction FROM community_reactions WHERE post_id = p.id AND user_id = ?) as my_reaction 
          FROM community_posts p JOIN users u ON p.user_id = u.id
          WHERE p.parent_id IN ($placeholders)
          ORDER BY p.created_at ASC
        ");
        $reply_params = array_merge([$user_id ?? 0], $root_ids);
        $reply_stmt->execute($reply_params);
        $all_replies = $reply_stmt->fetchAll();
          
        $rcounts = [];
        foreach ($all_replies as $r) {
          $pid = $r['parent_id'];
          if (!isset($rcounts[$pid])) $rcounts[$pid] = 0;
          if ($rcounts[$pid] < 25) { // Strict limit of 25 replies per post
            $replies_filtered[] = $r;
            $rcounts[$pid]++;
          }
        }
      }
      
      send_json(array_merge($roots, $replies_filtered));
      break;

    case 'create_community_post':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $content = format_user_text($data['content']);
      $parent_id = empty($data['parent_id']) ? null : intval($data['parent_id']);
      $db->prepare("INSERT INTO community_posts (user_id, parent_id, content) VALUES (?, ?, ?)")->execute([$user_id, $parent_id, $content]);
      send_json(['status' => 'success']);
      break;

    case 'toggle_post_reaction':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $post_id = intval($data['post_id']);
      $reaction = $data['reaction'];
      $stmt = $db->prepare("SELECT reaction FROM community_reactions WHERE user_id = ? AND post_id = ?");
      $stmt->execute([$user_id, $post_id]);
      if ($stmt->fetchColumn() === $reaction) {
        $db->prepare("DELETE FROM community_reactions WHERE user_id = ? AND post_id = ?")->execute([$user_id, $post_id]);
      } else {
        $db->prepare("REPLACE INTO community_reactions (user_id, post_id, reaction) VALUES (?, ?, ?)")->execute([$user_id, $post_id, $reaction]);
      }
      send_json(['status' => 'success']);
      break;

    case 'toggle_block':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $blocked_id = intval($data['blocked_id']);
      if ($blocked_id === $user_id) { send_json(['status' => 'error', 'message' => 'Cannot block yourself.']); }
      
      $stmt = $db->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
      $stmt->execute([$user_id, $blocked_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?")->execute([$user_id, $blocked_id]);
        send_json(['status' => 'unblocked']);
      } else {
        $db->prepare("INSERT INTO blocks (blocker_id, blocked_id) VALUES (?, ?)")->execute([$user_id, $blocked_id]);
        // Remove mutual follows
        $db->prepare("DELETE FROM follows WHERE (follower_id = ? AND following_id = ?) OR (follower_id = ? AND following_id = ?)")->execute([$user_id, $blocked_id, $blocked_id, $user_id]);
        send_json(['status' => 'blocked']);
      }
      break;

    case 'get_inbox':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("
        SELECT m.id, m.content, CASE WHEN m.image IS NOT NULL THEN 1 ELSE 0 END as has_image, m.created_at, m.is_read, m.sender_id, m.receiver_id, u.artist as other_name, u.id as other_id, u.last_active
        FROM messages m JOIN users u ON u.id = CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
        WHERE m.id IN (
          SELECT MAX(id) FROM messages WHERE sender_id = ? OR receiver_id = ? GROUP BY CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END
        )
        ORDER BY m.created_at DESC
      ");
      $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
      $inbox = $stmt->fetchAll();
      // Add unread count
      foreach($inbox as &$msg) {
        $ur_stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $ur_stmt->execute([$msg['other_id'], $user_id]);
        $msg['unread_count'] = $ur_stmt->fetchColumn();
      }
      send_json($inbox);
      break;

    case 'get_chat':
      if (!$user_id) { send_json([]); }
      $target_id = intval($_GET['target_id'] ?? 0);
      $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")->execute([$target_id, $user_id]);
      $stmt = $db->prepare("SELECT id, sender_id, content, CASE WHEN image IS NOT NULL THEN 1 ELSE 0 END as has_image, created_at, is_edited FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC");
      $stmt->execute([$user_id, $target_id, $target_id, $user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'edit_message':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("UPDATE messages SET content = ?, is_edited = 1 WHERE id = ? AND sender_id = ?")->execute([format_user_text($data['content']), intval($data['id']), $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'delete_message':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?")->execute([intval($data['id']), $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'send_message':
      if (!$user_id) { http_response_code(403); exit; }
      $target_id = intval($_POST['target_id'] ?? 0);
      $content = trim(htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8'));
      
      $stmt_block = $db->prepare("SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)");
      $stmt_block->execute([$user_id, $target_id, $target_id, $user_id]);
      if ($stmt_block->fetch()) {
         send_json(['status' => 'error', 'message' => 'Cannot send message. A block is active.']);
      }
      
      $webpData = null;
      if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (in_array($_FILES['image']['type'], $allowed_types)) {
          $webpData = process_image_to_webp(file_get_contents($_FILES['image']['tmp_name']), 800, 80);
        }
      }
      
      if ($content !== '' || $webpData !== null) {
        $db->prepare("INSERT INTO messages (sender_id, receiver_id, content, image) VALUES (?, ?, ?, ?)")->execute([$user_id, $target_id, format_user_text($content), $webpData]);
        send_json(['status' => 'success']);
      } else {
        send_json(['status' => 'error', 'message' => 'Empty message.']);
      }
      break;

    case 'get_message_image':
      header('Cache-Control: public, max-age=31536000, immutable');
      $msg_id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image, sender_id, receiver_id FROM messages WHERE id = ?");
      $stmt->execute([$msg_id]);
      $msg = $stmt->fetch();
      if ($msg && $msg['image'] && ($msg['sender_id'] == $user_id || $msg['receiver_id'] == $user_id || $is_super_admin)) {
        header('Content-Type: image/webp');
        echo $msg['image'];
        exit;
      }
      http_response_code(404); exit;

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
        
        try { 
          $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'started following', (SELECT artist FROM users WHERE id = ?))")->execute([$user_id, $following_id]); 
        } catch(Exception $e) {}
        
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
      $artist_name = $post_data['artist_name'] ?? '';

      $sql = "SELECT m.id FROM music m ";

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
        case 'get_listen_later':
          if (!$user_id) { send_json([]); }
          $sql = "SELECT m.id FROM music m JOIN listen_later ll ON m.id = ll.song_id ";
          $conditions = "WHERE ll.user_id = ?";
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'get_offline_songs':
          if (!$user_id) { send_json([]); }
          $sql = "SELECT m.id FROM music m JOIN offline_songs os ON m.id = os.song_id ";
          $conditions = "WHERE os.user_id = ?";
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
        case 'get_trending':
          $sql = "SELECT m.id FROM music m LEFT JOIN (SELECT song_id, SUM(play_count) as total_plays FROM play_counts GROUP BY song_id) pc ON m.id = pc.song_id ";
          $conditions = "WHERE 1=1";
          $default_sort = 'trending';
          break;
        case 'artist_songs':
          if ($filter_user_id !== '') {
            $conditions = "WHERE (match_artist(m.artist, ?) = 1 OR m.user_id = ?) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
            $params[] = $param;
            $params[] = $filter_user_id;
            $params[] = $user_id;
            $filter_user_id = ''; // Processed here, prevent global append
          } else {
            $conditions = "WHERE match_artist(m.artist, ?) = 1 AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
            $params[] = $param;
            $params[] = $user_id;
          }
          $default_sort = 'album_asc';
          break;
        case 'album_songs':
          $conditions = "WHERE m.album = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $params[] = $param;
          $params[] = $user_id;
          if ($artist_name !== '') {
            $conditions .= " AND match_artist(m.artist, ?) = 1";
            $params[] = $artist_name;
          }
          $default_sort = 'title_asc';
          break;
        case 'year_songs':
          $conditions = "WHERE m.year = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $params[] = $param;
          $params[] = $user_id;
          $default_sort = 'artist_asc';
          break;
        case 'genre_songs':
          $conditions = "WHERE m.genre = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $params[] = $param;
          $params[] = $user_id;
          $default_sort = 'artist_asc';
          break;
        case 'playlist_songs':
          $sql = "SELECT m.id FROM music m JOIN playlist_songs ps ON m.id = ps.song_id JOIN playlists p ON ps.playlist_id = p.id ";
          $conditions = "WHERE p.public_id = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $params[] = $param;
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'mix_songs':
          $sql = "SELECT m.id FROM mix_songs ms JOIN mixes mx ON ms.mix_id = mx.id JOIN music m ON ms.song_id = m.id ";
          $conditions = "WHERE mx.public_id = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $params[] = $param;
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'search':
          $conditions = "WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param, $user_id];
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
        'history_asc' => 'ORDER BY MAX(h.played_at) ASC',
        'trending' => 'ORDER BY COALESCE(pc.total_plays, 0) DESC, m.id DESC LIMIT 100',
        'random' => 'ORDER BY RANDOM()',
      ];
      if ($view_type === 'get_favorites' || $view_type === 'get_listen_later') {
        $sort_map['manual_order'] = 'ORDER BY f.sort_order ASC';
        $sort_map['added_newest'] = 'ORDER BY f.sort_order DESC';
        $sort_map['added_oldest'] = 'ORDER BY f.sort_order ASC';
      } elseif ($view_type === 'get_offline_songs') {
        $sort_map['manual_order'] = 'ORDER BY os.sort_order ASC';
        $sort_map['added_newest'] = 'ORDER BY os.sort_order DESC';
        $sort_map['added_oldest'] = 'ORDER BY os.sort_order ASC';
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
      // ADVANCED IMAGE SCANNER: Tracks if the current song actually has an image
      $stmt = $db->prepare("SELECT artist, id, CASE WHEN image IS NOT NULL THEN 1 ELSE 0 END as has_img FROM music WHERE artist != '' AND artist IS NOT NULL AND (is_private = 0 OR user_id = ? OR {$is_super_admin} = 1) ORDER BY id DESC");
      $stmt->execute([$user_id]);
      $rows = $stmt->fetchAll();
      $artists = [];
      foreach ($rows as $row) {
        $parts = preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($p);
            if (!isset($artists[$key])) {
              // Register artist with initial song ID
              $artists[$key] = ['name' => $p, 'id' => $row['id'], 'has_img' => $row['has_img']];
            } elseif (!$artists[$key]['has_img'] && $row['has_img']) {
              // UPGRADE: If previously blank, replace with a song ID that has a cover image!
              $artists[$key]['id'] = $row['id'];
              $artists[$key]['has_img'] = 1;
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
      
      $stmt = $db->prepare("SELECT m.album, m.artist, m.user_id, m.id, m.year, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m WHERE m.album != '' AND m.album != 'Unknown Album' AND m.album IS NOT NULL AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1) ORDER BY m.id DESC");
      $stmt->execute([$user_id]);
      $rows = $stmt->fetchAll();
      
      $albums = [];
      foreach ($rows as $row) {
        $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        if (!is_array($parts)) $parts = [$row['artist']];
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($row['album'] . ':::' . $p);
            if (!isset($albums[$key])) {
              $albums[$key] = [
                'album' => $row['album'],
                'artist' => $p,
                'user_id' => $row['user_id'],
                'id' => $row['id'],
                'year' => $row['year'],
                'song_count' => 1,
                'total_plays' => $row['pc']
              ];
            } else {
              $albums[$key]['song_count']++;
              $albums[$key]['total_plays'] += $row['pc'];
            }
          }
        }
      }
      
      usort($albums, function($a, $b) use ($sort_key) {
        switch ($sort_key) {
          case 'album_desc': return strcasecmp($b['album'], $a['album']);
          case 'artist_asc': return strcasecmp($a['artist'], $b['artist']);
          case 'artist_desc': return strcasecmp($b['artist'], $a['artist']);
          case 'year_desc': return ($b['year'] ?? 0) <=> ($a['year'] ?? 0);
          case 'year_asc': return ($a['year'] ?? 0) <=> ($b['year'] ?? 0);
          case 'album_asc':
          default: return strcasecmp($a['album'], $b['album']);
        }
      });
      
      $sliced = array_slice($albums, $offset, PAGE_SIZE);
      send_json(array_values($sliced));
      break;
    
    case 'get_genres':
      $stmt = $db->prepare("SELECT genre as name, MAX(id) as id FROM music WHERE genre != '' AND genre IS NOT NULL AND (is_private = 0 OR user_id = ? OR {$is_super_admin} = 1) GROUP BY genre ORDER BY genre COLLATE NOCASE" . $limit_clause);
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;
    
    case 'get_years':
      $stmt = $db->prepare("SELECT CAST(year AS TEXT) as name, MAX(id) as id FROM music WHERE year IS NOT NULL AND year > 0 AND (is_private = 0 OR user_id = ? OR {$is_super_admin} = 1) GROUP BY year ORDER BY year DESC" . $limit_clause);
      $stmt->execute([$user_id ?? 0]);
      send_json($stmt->fetchAll());
      break;

    case 'get_listen_later_ids':
      if (!$user_id) { send_json([]); }
      $stmt = $db->prepare("SELECT song_id FROM listen_later WHERE user_id = ?");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'edit_community_post':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("UPDATE community_posts SET content = ? WHERE id = ? AND user_id = ?")->execute([format_user_text($data['content']), intval($data['id']), $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'delete_community_post':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("DELETE FROM community_posts WHERE id = ? AND (user_id = ? OR {$is_super_admin} = 1)")->execute([intval($data['id']), $user_id]);
      send_json(['status' => 'success']);
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
        
        $stmt_user = $db->prepare("SELECT artist, bio FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_details = $stmt_user->fetch();

        if (!$user_details) { http_response_code(404); exit; }

        $stmt_stats = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration, SUM((SELECT COALESCE(SUM(play_count), 0) FROM play_counts WHERE song_id = music.id)) as play_count FROM music WHERE user_id = ?");
        $stmt_stats->execute([$user_id]);
        $details = $stmt_stats->fetch();
        
        $details['name'] = $user_details['artist'];
        $stmt_followers = $db->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
        $stmt_followers->execute([$user_id]);
        $details['followers_count'] = $stmt_followers->fetchColumn();
        $details['image_url'] = '?action=get_profile_picture&id=' . $user_id;
        $details['background_url'] = '?action=get_profile_background&id=' . $user_id;
        $details['bio'] = $user_details['bio'] ?? '';
        
        $stmt_following = $db->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
        $stmt_following->execute([$user_id]);
        $details['following_count'] = $stmt_following->fetchColumn();

        $stmt_rec_art = $db->prepare("SELECT id, artist FROM users WHERE id != ? AND banned = 0 AND email NOT LIKE 'deleted_%' ORDER BY RANDOM() LIMIT 5");
        $stmt_rec_art->execute([$user_id]);
        $details['recommended_artists'] = $stmt_rec_art->fetchAll();

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
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
          FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.user_id = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $user_id]);
        $songs = $stmt_songs->fetchAll();
      } elseif ($type === 'playlist') {
        if (empty($name)) { http_response_code(400); exit; }
        $stmt_details = $db->prepare("
          SELECT p.name, p.public_id, p.user_id, p.is_private, u.artist as creator, p.play_count,
          (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = p.id) as song_count,
          (SELECT SUM(m.duration) FROM music m JOIN playlist_songs ps ON m.id = ps.song_id WHERE ps.playlist_id = p.id) as total_duration,
          (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
          FROM playlists p JOIN users u ON p.user_id = u.id
          WHERE p.public_id = ?
        ");
        $stmt_details->execute([$name]);
        $details = $stmt_details->fetch();
        if ($details) {
          if ($details['is_private'] == 1 && $details['user_id'] != $user_id && $is_super_admin == 0) {
            http_response_code(403); send_json(['status' => 'error', 'message' => 'This playlist is private.']);
          }
          $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
        }
        $sort_map = [
          'manual_order' => 'ORDER BY ps.sort_order ASC', 'added_newest' => 'ORDER BY ps.added_at DESC', 'added_oldest' => 'ORDER BY ps.added_at ASC',
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC', 'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC', 
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['manual_order'];
        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
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
            SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
            FROM music m 
            JOIN mix_songs ms ON m.id = ms.song_id 
            LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
            WHERE ms.mix_id = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1) ORDER BY ms.sort_order ASC
          ");
          $stmt_songs->execute([$user_id, $mix_row['id'], $user_id]);
          $songs = $stmt_songs->fetchAll();
          $details['play_count'] = 0;
          foreach($songs as $s) { 
            $details['total_duration'] += $s['duration']; 
            $details['play_count'] += $s['play_count'] ? $s['play_count'] : 0;
          }
          $details['song_count'] = count($songs);
        }
      } elseif (in_array($type, ['artist', 'album', 'genre', 'year'])) {
        if ($name === '') { http_response_code(400); exit; }
        $field = $type;
        $filter_user_id = $_GET['filter_user_id'] ?? '';
        $artist_name = $_GET['artist_name'] ?? '';
        $user_cond = "";
        $user_params = [];
        
        if ($field === 'artist') {
          if ($filter_user_id !== '') {
            $field_cond = "(match_artist(m.artist, ?) = 1 OR m.user_id = ?) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
            $user_params[] = $name;
            $user_params[] = $filter_user_id;
            $user_params[] = $user_id;
            $filter_user_id = ''; // Processed here, prevent global append
          } else {
            $field_cond = "match_artist(m.artist, ?) = 1 AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
            $user_params[] = $name;
            $user_params[] = $user_id;
          }
        } else {
          $field_cond = "m.{$field} = ? AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)";
          $user_params[] = $name;
          $user_params[] = $user_id;
          if ($field === 'album' && $artist_name !== '') {
            $field_cond .= " AND match_artist(m.artist, ?) = 1";
            $user_params[] = $artist_name;
          }
        }
        
        if ($filter_user_id !== '') {
          $user_cond = " AND m.user_id = ?";
          $user_params[] = $filter_user_id;
        }

        // Advanced Fallback: Fetch basic stats first
        $stmt_details = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration, MAX(user_id) as user_id, SUM((SELECT COALESCE(SUM(play_count), 0) FROM play_counts WHERE song_id = m.id)) as play_count FROM music m WHERE {$field_cond} {$user_cond}");
        $stmt_details->execute($user_params);
        $details = $stmt_details->fetch();
        
        // Advanced Fallback: Query explicitly for the newest song that actually has a cover image
        $stmt_img = $db->prepare("SELECT id FROM music m WHERE {$field_cond} {$user_cond} AND image IS NOT NULL ORDER BY id DESC LIMIT 1");
        $stmt_img->execute($user_params);
        $verified_image_id = $stmt_img->fetchColumn();

        $details['name'] = $name;
        $details['image_url'] = '?action=get_image&id=' . ($verified_image_id ?: 0);
        $details['public_id'] = null;

        if ($type === 'artist') {
          $stmt_user = $db->prepare("SELECT id, banned, email, bio FROM users WHERE artist = ? COLLATE NOCASE");
          $stmt_user->execute([$name]);
          $artist_user = $stmt_user->fetch();

          if ($artist_user) {
            if ($artist_user['banned'] == 1 || strpos((string)$artist_user['email'], 'deleted_') === 0) {
              http_response_code(404);
              send_json(['status' => 'error', 'message' => 'User not found or this user already deleted.']);
            }
            $artist_user_id = $artist_user['id'];
            $details['is_user'] = true;
            $details['user_id'] = $artist_user_id;
            $stmt_followers = $db->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
            $stmt_followers->execute([$artist_user_id]);
            $details['followers_count'] = $stmt_followers->fetchColumn();
            
            $stmt_following = $db->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
            $stmt_following->execute([$artist_user_id]);
            $details['following_count'] = $stmt_following->fetchColumn();
            
            $details['image_url'] = '?action=get_profile_picture&id=' . $artist_user_id;
            $details['background_url'] = '?action=get_profile_background&id=' . $artist_user_id;
            $details['bio'] = $artist_user['bio'] ?? '';
            
            $stmt_rec_art = $db->prepare("SELECT id, artist FROM users WHERE id != ? AND banned = 0 AND email NOT LIKE 'deleted_%' ORDER BY RANDOM() LIMIT 5");
            $stmt_rec_art->execute([$artist_user_id]);
            $details['recommended_artists'] = $stmt_rec_art->fetchAll();

            $stmt_rec_songs = $db->prepare("SELECT id, title, artist, last_modified FROM music WHERE user_id = ? AND is_private = 0 ORDER BY (SELECT SUM(play_count) FROM play_counts WHERE song_id = music.id) DESC LIMIT 5");
            $stmt_rec_songs->execute([$artist_user_id]);
            $details['recommended_songs'] = $stmt_rec_songs->fetchAll();

            if ($user_id) {
              $stmt_follow = $db->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
              $stmt_follow->execute([$user_id, $artist_user_id]);
              $details['is_following'] = (bool)$stmt_follow->fetchColumn();

              $stmt_block = $db->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
              $stmt_block->execute([$user_id, $artist_user_id]);
              $details['is_blocked'] = (bool)$stmt_block->fetchColumn();
            } else {
              $details['is_following'] = false;
            }
            $stmt_playlists = $db->prepare("SELECT p.name, p.public_id, (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id, (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) as song_count FROM playlists p WHERE p.user_id = ? AND (p.is_private = 0 OR {$is_super_admin} = 1) ORDER BY p.created_at DESC");
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
          SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
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
      
      // Parse Filters
      $f_date = $_GET['f_date'] ?? '';
      $f_dur = $_GET['f_dur'] ?? '';
      $f_sort = $_GET['f_sort'] ?? 'relevance';
      
      $time_now = time();
      $date_cond_m = ""; $date_cond_p = "";
      if ($f_date === 'today') { $date_cond_m = " AND m.last_modified >= " . ($time_now - 86400); $date_cond_p = " AND p.created_at >= datetime('now', '-1 day')"; }
      elseif ($f_date === 'week') { $date_cond_m = " AND m.last_modified >= " . ($time_now - 604800); $date_cond_p = " AND p.created_at >= datetime('now', '-7 days')"; }
      elseif ($f_date === 'month') { $date_cond_m = " AND m.last_modified >= " . ($time_now - 2592000); $date_cond_p = " AND p.created_at >= datetime('now', '-1 month')"; }
      elseif ($f_date === 'year') { $date_cond_m = " AND m.last_modified >= " . ($time_now - 31536000); $date_cond_p = " AND p.created_at >= datetime('now', '-1 year')"; }

      $dur_cond_m = "";
      if ($f_dur === 'short') { $dur_cond_m = " AND m.duration < 240"; }
      elseif ($f_dur === 'long') { $dur_cond_m = " AND m.duration > 1200"; }

      $shelves = [];
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as play_count";

      // 1. TOP RESULT (Only if no specific sort is overriding)
      if ($f_sort === 'relevance' || $f_sort === '') {
        $stmt_top = $db->prepare("
          SELECT {$song_fields} FROM music m 
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? 
          WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)
          $date_cond_m $dur_cond_m
          ORDER BY CASE WHEN m.title LIKE ? THEN 1 WHEN m.artist LIKE ? THEN 2 WHEN m.album LIKE ? THEN 3 ELSE 4 END ASC, m.id DESC LIMIT 1
        ");
        $stmt_top->execute([$user_id, $query, $query, $query, $user_id, $q, $q, $q]);
        $top_result = $stmt_top->fetch();
        if ($top_result) $shelves[] = ['title' => 'Top Result', 'type' => 'top_result', 'items' => [$top_result]];
      }

      // 2. ARTISTS (Bypass date/duration filters since they don't apply to profiles)
      $artists = []; $added_artists = [];
      $stmt = $db->prepare("SELECT id, artist as name FROM users WHERE artist LIKE ? AND artist != '' AND artist IS NOT NULL ORDER BY artist ASC LIMIT 15");
      $stmt->execute([$query]);
      foreach ($stmt->fetchAll() as $ua) {
        $artists[] = ['name' => $ua['name'], 'id' => $ua['id'], 'is_user' => true];
        $added_artists[strtolower($ua['name'])] = true;
      }
      $stmt = $db->prepare("SELECT DISTINCT artist, MAX(id) as id FROM music WHERE artist LIKE ? AND artist != '' AND artist IS NOT NULL AND (is_private = 0 OR user_id = ? OR {$is_super_admin} = 1) GROUP BY artist LIMIT 15");
      $stmt->execute([$query, $user_id]);
      foreach ($stmt->fetchAll() as $ma) {
        $parts = preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $ma['artist']);
        foreach ($parts as $p) {
          $p = trim($p);
          if ($p !== '' && stripos($p, $q) !== false && !isset($added_artists[strtolower($p)])) {
            $artists[] = ['name' => $p, 'id' => $ma['id'], 'is_user' => false];
            $added_artists[strtolower($p)] = true;
          }
        }
      }
      if (count($artists) > 0) $shelves[] = ['title' => 'Artists', 'type' => 'artists', 'items' => array_slice($artists, 0, 15)];

      // 3. ALBUMS (Dynamically aggregated and filtered)
      $stmt = $db->prepare("
        SELECT m.album, m.artist, m.user_id, m.id, m.duration, m.last_modified, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc
        FROM music m WHERE m.album LIKE ? AND m.album != '' AND m.album != 'Unknown Album' AND m.album IS NOT NULL AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1) $date_cond_m
      ");
      $stmt->execute([$query, $user_id]);
      $search_albums = [];
      foreach ($stmt->fetchAll() as $row) {
        $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        if (!is_array($parts)) $parts = [$row['artist']];
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($row['album'] . ':::' . $p);
            if (!isset($search_albums[$key])) {
              $search_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc'], 'sum_dur' => $row['duration'], 'max_date' => $row['last_modified']];
            } else {
              $search_albums[$key]['song_count']++; $search_albums[$key]['total_plays'] += $row['pc'];
              $search_albums[$key]['sum_dur'] += $row['duration']; $search_albums[$key]['max_date'] = max($search_albums[$key]['max_date'], $row['last_modified']);
            }
          }
        }
      }
      if ($f_dur === 'short') $search_albums = array_filter($search_albums, function($a) { return $a['sum_dur'] < 240; });
      elseif ($f_dur === 'long') $search_albums = array_filter($search_albums, function($a) { return $a['sum_dur'] > 1200; });
      
      usort($search_albums, function($a, $b) use ($f_sort) {
        if ($f_sort === 'date') return $b['max_date'] <=> $a['max_date'];
        if ($f_sort === 'views') return $b['total_plays'] <=> $a['total_plays'];
        return strcasecmp($a['album'], $b['album']);
      });
      $search_albums = array_slice($search_albums, 0, 15);
      if (count($search_albums) > 0) $shelves[] = ['title' => 'Albums', 'type' => 'albums', 'items' => array_values($search_albums)];

      // 4. PLAYLISTS
      $stmt = $db->prepare("
        SELECT p.name, p.public_id, p.is_collaborative, p.is_private, p.play_count, p.created_at, u.artist as creator, 
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id, 
        (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) as song_count,
        (SELECT SUM(m.duration) FROM playlist_songs ps JOIN music m ON ps.song_id = m.id WHERE ps.playlist_id = p.id) as total_duration
        FROM playlists p JOIN users u ON p.user_id = u.id 
        WHERE p.name LIKE ? AND (p.is_private = 0 OR p.user_id = ? OR {$is_super_admin} = 1) $date_cond_p
      ");
      $stmt->execute([$query, $user_id]);
      $playlists = $stmt->fetchAll();
      if ($f_dur === 'short') $playlists = array_filter($playlists, function($p) { return $p['total_duration'] < 240; });
      elseif ($f_dur === 'long') $playlists = array_filter($playlists, function($p) { return $p['total_duration'] > 1200; });
      usort($playlists, function($a, $b) use ($f_sort) {
        if ($f_sort === 'date') return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        if ($f_sort === 'views') return $b['play_count'] <=> $a['play_count'];
        return strcasecmp($a['name'], $b['name']);
      });
      $playlists = array_slice($playlists, 0, 15);
      if (count($playlists) > 0) $shelves[] = ['title' => 'Playlists', 'type' => 'playlists', 'items' => array_values($playlists)];

      // 5. SONGS
      $order_m = "ORDER BY m.title ASC";
      $song_params = [$user_id, $query, $query, $query, $user_id];
      if ($f_sort === 'relevance' || $f_sort === '') {
        $order_m = "ORDER BY CASE WHEN m.title LIKE ? THEN 1 WHEN m.artist LIKE ? THEN 2 WHEN m.album LIKE ? THEN 3 ELSE 4 END ASC, m.id DESC";
        $song_params[] = $q; $song_params[] = $q; $song_params[] = $q;
      } elseif ($f_sort === 'date') {
        $order_m = "ORDER BY m.last_modified DESC";
      } elseif ($f_sort === 'views') {
        $order_m = "ORDER BY play_count DESC";
      } elseif ($f_sort === 'likes') {
        $order_m = "ORDER BY like_count DESC";
      }
      
      $song_fields_search = $song_fields . ", (SELECT COUNT(*) FROM favorites WHERE song_id = m.id) as like_count";
      $stmt = $db->prepare("SELECT {$song_fields_search} FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1) $date_cond_m $dur_cond_m $order_m LIMIT 50");
      $stmt->execute($song_params);
      $songs = $stmt->fetchAll();
      if (count($songs) > 0) $shelves[] = ['title' => 'Songs', 'type' => 'songs_list', 'items' => $songs];

      send_json(['shelves' => $shelves]);
      break;

    case 'get_song_data':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("
        SELECT m.id, m.file, m.title, m.artist, m.album, m.genre, m.year, m.duration, m.bitrate, m.lyrics, m.user_id, m.is_private, m.last_modified, m.replaygain,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite,
        uss.volume_multiplier, uss.eq_bands
        FROM music m 
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        LEFT JOIN user_song_settings uss ON m.id = uss.song_id AND uss.user_id = ?
        WHERE m.id = ?
      ");
      $stmt->execute([$user_id, $user_id, $id]);
      $song = $stmt->fetch();
      if ($song) {
        if ($song['is_private'] == 1 && $song['user_id'] != $user_id && $is_super_admin == 0) {
           http_response_code(403); send_json(['status' => 'error', 'message' => 'This song is private.']);
        }
        $song['stream_url'] = '?action=get_stream&id=' . $song['id'];
        $song['image_url'] = '?action=get_image&id=' . $song['id'] . '&v=' . ($song['last_modified'] ?? 0);
        
        // STRICT JSON PARSER: Forces DB Equalizer strings into Floats so the JS engine doesn't crash
        if (!empty($song['eq_bands'])) {
            $parsed_eq = json_decode($song['eq_bands'], true);
            $song['eq_bands'] = is_array($parsed_eq) ? array_map('floatval', $parsed_eq) : [0,0,0,0,0];
        } else {
            $song['eq_bands'] = null;
        }
        
        if (!empty($song['volume_multiplier'])) {
            $song['volume_multiplier'] = (float)$song['volume_multiplier'];
        }
      }
      send_json($song);
      break;

    case 'save_global_settings':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $settings_json = json_encode($data['settings']);
      $db->prepare("UPDATE users SET settings = ? WHERE id = ?")->execute([$settings_json, $user_id]);
      send_json(['status' => 'success']);
      break;

    case 'save_song_settings':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $s_id = (int)$data['song_id'];
      $vol = (float)$data['volume'];
      $eq = json_encode($data['eq']);
      $db->prepare("
        REPLACE INTO user_song_settings (user_id, song_id, volume_multiplier, eq_bands) 
        VALUES (?, ?, ?, ?)
      ")->execute([$user_id, $s_id, $vol, $eq]);
      send_json(['status' => 'success']);
      break;

    case 'reset_song_settings':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $s_id = (int)$data['song_id'];
      $db->prepare("DELETE FROM user_song_settings WHERE user_id = ? AND song_id = ?")->execute([$user_id, $s_id]);
      send_json(['status' => 'success']);
      break;

    case 'get_stream':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file, user_id, is_private FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $song_stream = $stmt->fetch();
      
      if ($song_stream) {
        $file_path = $song_stream['file'];
        // Dynamic path resolution to prevent breaking if server/folder changes
        if (!file_exists($file_path)) {
          $dynamic_path = MUSIC_DIR . '/uploads/' . basename(dirname(dirname($file_path))) . '/' . basename(dirname($file_path)) . '/' . basename($file_path);
          if (file_exists($dynamic_path)) $file_path = $dynamic_path;
        }

        if (file_exists($file_path)) {
          if ($song_stream['is_private'] == 1 && $song_stream['user_id'] != $user_id && $is_super_admin == 0) {
            http_response_code(403); exit;
          }
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
          exit;
        }
      }
      http_response_code(404);
      exit;

    case 'get_image':
      header('Cache-Control: public, max-age=31536000, immutable');
      header('Pragma: cache');
      header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image, title, artist FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      
      if ($row && $row['image']) {
        header('Content-Type: image/webp');
        echo $row['image'];
      } else {
        header('Content-Type: image/svg+xml');
        $seed = ($row['title'] ?? 'Unknown') . ($row['artist'] ?? 'Unknown');
        $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722', '#795548'];
        $c1 = $colors[hexdec(substr(md5($seed . 'cov1'), 0, 6)) % count($colors)];
        $c2 = $colors[hexdec(substr(md5($seed . 'cov2'), 0, 6)) % count($colors)];
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="100%" height="100%"><defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="'.$c1.'"/><stop offset="100%" stop-color="'.$c2.'"/></linearGradient></defs><rect width="16" height="16" fill="url(#g)"/><path d="M9 13c0 1.105-1.12 2-2.5 2S4 14.105 4 13s1.12-2 2.5-2 2.5.895 2.5 2" fill="#ffffff" opacity="0.6"/><path fill-rule="evenodd" d="M9 3v10H8V3h1z" fill="#ffffff" opacity="0.6"/><path d="M8 2.82a1 1 0 0 1 .804-.98l3-.6A1 1 0 0 1 13 2.22V4L8 5V2.82z" fill="#ffffff" opacity="0.6"/></svg>';
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
        SELECT p.id, p.name, p.public_id, p.is_collaborative, p.is_private, p.user_id as owner_id, p.play_count, COUNT(ps.song_id) as song_count,
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
        {$is_added_sql}
        FROM playlists p LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id
        WHERE p.user_id = ?
        GROUP BY p.id, p.name, p.public_id, p.is_collaborative, p.is_private, p.user_id, p.play_count
        {$order_by} {$limit_clause}
      ");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_collab_playlists':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'name_asc';
      $sort_map = [
        'name_asc' => 'ORDER BY p.name COLLATE NOCASE ASC',
        'name_desc' => 'ORDER BY p.name COLLATE NOCASE DESC',
        'modified_desc' => 'ORDER BY COALESCE((SELECT MAX(added_at) FROM playlist_songs WHERE playlist_id = p.id), p.created_at) DESC',
        'modified_asc' => 'ORDER BY COALESCE((SELECT MAX(added_at) FROM playlist_songs WHERE playlist_id = p.id), p.created_at) ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['name_asc'];
      
      $stmt = $db->prepare("
        SELECT p.id, p.name, p.public_id, p.is_collaborative, p.is_private, p.user_id as owner_id, u.artist as creator, p.play_count, COUNT(ps.song_id) as song_count,
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
        FROM playlists p 
        JOIN playlist_collaborators pc ON p.id = pc.playlist_id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN playlist_songs ps ON p.id = ps.playlist_id
        WHERE pc.user_id = ?
        GROUP BY p.id, p.name, p.public_id, p.is_collaborative, p.is_private, p.user_id, u.artist, p.play_count
        {$order_by} {$limit_clause}
      ");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'leave_collab':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $stmt = $db->prepare("DELETE FROM playlist_collaborators WHERE user_id = ? AND playlist_id = (SELECT id FROM playlists WHERE public_id = ?)");
      $stmt->execute([$user_id, $public_id]);
      send_json(['status' => 'success', 'message' => 'You have left the collaboration.']);
      break;
      
    case 'manage_collaborators':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $action_type = $data['collab_action']; 
      
      if ($action_type === 'search') {
        $q = '%' . ($data['query'] ?? '') . '%';
        $stmt = $db->prepare("SELECT id, artist FROM users WHERE artist LIKE ? OR email LIKE ? LIMIT 10");
        $stmt->execute([$q, $q]);
        send_json(['status' => 'success', 'users' => $stmt->fetchAll()]);
      }
      
      $stmt = $db->prepare("SELECT id, user_id, is_collaborative FROM playlists WHERE public_id = ?");
      $stmt->execute([$public_id]);
      $pl = $stmt->fetch();
      
      if (!$pl) {
        http_response_code(404); send_json(['status' => 'error', 'message' => 'Playlist not found.']);
      }
      
      if ($action_type === 'generate_link') {
        if ($pl['user_id'] != $user_id && $is_super_admin == 0) {
          http_response_code(403); send_json(['status' => 'error', 'message' => 'Only the owner can generate links.']);
        }
        // Cleanup expired tokens dynamically to save space
        $db->exec("DELETE FROM playlist_invites WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP");
        
        $expire_val = $data['expire'] ?? null;
        $expires_at = null;
        if ($expire_val && is_numeric($expire_val)) {
          $expires_at = date('Y-m-d H:i:s', time() + ((int)$expire_val * 60));
        }
        $token = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO playlist_invites (token, playlist_id, expires_at) VALUES (?, ?, ?)")->execute([$token, $pl['id'], $expires_at]);
        send_json(['status' => 'success', 'token' => $token]);
      }

      if ($action_type === 'join') {
        $token = $data['token'] ?? '';
        $stmt_inv = $db->prepare("SELECT playlist_id FROM playlist_invites WHERE token = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)");
        $stmt_inv->execute([$token]);
        $inv_pl_id = $stmt_inv->fetchColumn();
        
        if (!$inv_pl_id || $inv_pl_id != $pl['id']) {
          send_json(['status' => 'error', 'message' => 'Invalid or expired invite link.']);
        }
        if (!$pl['is_collaborative']) {
          send_json(['status' => 'error', 'message' => 'This playlist is not open for collaboration.']);
        }
        if ($pl['user_id'] == $user_id) {
          send_json(['status' => 'error', 'message' => 'You are already the owner.']);
        }
        $db->prepare("INSERT OR IGNORE INTO playlist_collaborators (playlist_id, user_id) VALUES (?, ?)")->execute([$pl['id'], $user_id]);
        send_json(['status' => 'success', 'message' => 'Joined playlist successfully!']);
      }
      
      if ($pl['user_id'] != $user_id && $is_super_admin == 0) {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Only the owner can manage collaborators.']);
      }
      
      if ($action_type === 'list') {
        $stmt_list = $db->prepare("SELECT u.id, u.artist, u.email FROM playlist_collaborators pc JOIN users u ON pc.user_id = u.id WHERE pc.playlist_id = ?");
        $stmt_list->execute([$pl['id']]);
        send_json(['status' => 'success', 'collaborators' => $stmt_list->fetchAll()]);
      } elseif ($action_type === 'add') {
        $target_id = $data['target_id'] ?? null;
        if ($target_id) {
          $collab_user = $target_id;
        } else {
          $target = $data['target'];
          $stmt_find = $db->prepare("SELECT id FROM users WHERE email = ? OR artist = ? COLLATE NOCASE");
          $stmt_find->execute([$target, $target]);
          $collab_user = $stmt_find->fetchColumn();
        }
        if (!$collab_user) { send_json(['status' => 'error', 'message' => 'User not found.']); }
        if ($collab_user == $user_id) { send_json(['status' => 'error', 'message' => 'You already own this playlist.']); }
          
        $db->prepare("INSERT OR IGNORE INTO playlist_collaborators (playlist_id, user_id) VALUES (?, ?)")->execute([$pl['id'], $collab_user]);
        send_json(['status' => 'success', 'message' => 'Collaborator added successfully.']);
      } elseif ($action_type === 'remove') {
        $remove_id = $data['collab_user_id'];
        $db->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?")->execute([$pl['id'], $remove_id]);
        send_json(['status' => 'success', 'message' => 'Collaborator removed.']);
      }
      break;

    case 'get_invite_info':
      $token = $_GET['token'] ?? '';
      $stmt = $db->prepare("
        SELECT p.public_id, p.name, u.artist as creator, p.is_private, p.user_id 
        FROM playlist_invites pi 
        JOIN playlists p ON pi.playlist_id = p.id 
        JOIN users u ON p.user_id = u.id 
        WHERE pi.token = ? AND (pi.expires_at IS NULL OR pi.expires_at > CURRENT_TIMESTAMP)
      ");
      $stmt->execute([$token]);
      $info = $stmt->fetch();
      if ($info) {
        send_json(['status' => 'success', 'details' => $info]);
      } else {
        http_response_code(404); send_json(['status' => 'error', 'message' => 'Invalid or expired invite link.']);
      }
      break;

    case 'create_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      $is_private = intval($data['is_private'] ?? 0);
      if (empty($name)) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Playlist name cannot be empty.']);
      }
      $public_id = bin2hex(random_bytes(8));
      $stmt = $db->prepare("INSERT INTO playlists (user_id, name, public_id, is_private) VALUES (?, ?, ?, ?)");
      $stmt->execute([$user_id, $name, $public_id, $is_private]);
      if ($is_private == 0) {
          $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, ?, ?)")->execute([$user_id, 'created a playlist', $name]);
      }
      send_json(['status' => 'success', 'message' => 'Playlist created.']);
      break;

    case 'get_activity_feed':
      if (!$user_id) { send_json([]); }
      
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_clear DATETIME;"); } catch(Exception $e) {}
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_read DATETIME;"); } catch(Exception $e) {}
      
      $stmt_times = $db->prepare("SELECT last_notif_clear, last_notif_read FROM users WHERE id = ?");
      $stmt_times->execute([$user_id]);
      $times = $stmt_times->fetch();
      $last_clear = $times['last_notif_clear'] ?: '2000-01-01 00:00:00';
      $last_read = $times['last_notif_read'] ?: '2000-01-01 00:00:00';
      
      $feed = [];
      
      // 1. My standard activity
      $stmt = $db->prepare("SELECT 'activity' as type, action, target_name, created_at FROM activity_feed WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 50");
      $stmt->execute([$user_id, $last_clear]);
      $feed = array_merge($feed, $stmt->fetchAll());
      
      // 2. Incoming Comments and Replies
      $stmt2 = $db->prepare("
        SELECT 
          'comment_notif' as type,
          c.id as comment_id,
          c.song_id,
          c.content,
          c.created_at,
          CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as commenter_name,
          m.title as song_title,
          CASE 
            WHEN c.parent_id IS NOT NULL THEN 'reply'
            ELSE 'comment'
          END as notif_type
        FROM song_comments c
        JOIN users u ON c.user_id = u.id
        JOIN music m ON c.song_id = m.id
        LEFT JOIN song_comments pc ON c.parent_id = pc.id
        WHERE ((m.user_id = ? AND c.parent_id IS NULL AND c.user_id != ?) 
           OR (c.parent_id IS NOT NULL AND pc.user_id = ? AND c.user_id != ?))
           AND c.created_at > ?
        ORDER BY c.created_at DESC LIMIT 50
      ");
      $stmt2->execute([$user_id, $user_id, $user_id, $user_id, $last_clear]);
      $feed = array_merge($feed, $stmt2->fetchAll());
      
      // 3. Incoming Community Replies
      $stmt3 = $db->prepare("
        SELECT 
          'community_notif' as type,
          cp.id as post_id,
          cp.content,
          cp.created_at,
          CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as commenter_name,
          '' as song_title,
          'community_reply' as notif_type
        FROM community_posts cp
        JOIN users u ON cp.user_id = u.id
        JOIN community_posts parent_post ON cp.parent_id = parent_post.id
        WHERE parent_post.user_id = ? AND cp.user_id != ? AND cp.created_at > ?
        ORDER BY cp.created_at DESC LIMIT 50
      ");
      $stmt3->execute([$user_id, $user_id, $last_clear]);
      $feed = array_merge($feed, $stmt3->fetchAll());
      
      // 4. Incoming Messages
      $stmt4 = $db->prepare("
        SELECT 
          'message_notif' as type,
          m.id as message_id,
          m.content,
          m.created_at,
          CASE WHEN u.banned = 1 THEN 'Banned User' WHEN u.email LIKE 'deleted_%' THEN 'Deleted User' ELSE u.artist END as commenter_name,
          u.id as sender_id,
          'message' as notif_type
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ? AND m.created_at > ?
        ORDER BY m.created_at DESC LIMIT 50
      ");
      $stmt4->execute([$user_id, $last_clear]);
      $feed = array_merge($feed, $stmt4->fetchAll());

      // Sort all notifications by date
      usort($feed, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
      });
      
      // Add unread status flags
      $final_feed = array_slice($feed, 0, 50);
      foreach ($final_feed as &$item) {
        $item['is_unread'] = (strtotime($item['created_at']) > strtotime($last_read));
      }
      unset($item);
      
      send_json($final_feed);
      break;

    case 'clear_activity_feed':
      if (!$user_id) { http_response_code(403); exit; }
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_clear DATETIME;"); } catch(Exception $e) {}
      $db->prepare("UPDATE users SET last_notif_clear = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id]);
      $db->prepare("DELETE FROM activity_feed WHERE user_id = ?")->execute([$user_id]);
      send_json(['status' => 'success']);
      break;

    case 'get_unread_notif_count':
      if (!$user_id) { send_json(['count' => 0]); }
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_read DATETIME;"); } catch(Exception $e) {}
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_clear DATETIME;"); } catch(Exception $e) {}
      
      $stmt_times = $db->prepare("SELECT last_notif_read, last_notif_clear FROM users WHERE id = ?");
      $stmt_times->execute([$user_id]);
      $times = $stmt_times->fetch();
      $last_read = $times['last_notif_read'] ?: '2000-01-01 00:00:00';
      $last_clear = $times['last_notif_clear'] ?: '2000-01-01 00:00:00';
      $threshold = max($last_read, $last_clear);

      $stmt2 = $db->prepare("
          SELECT COUNT(*)
          FROM song_comments c
          JOIN music m ON c.song_id = m.id
          LEFT JOIN song_comments pc ON c.parent_id = pc.id
          WHERE ((m.user_id = ? AND c.parent_id IS NULL AND c.user_id != ?) 
             OR (c.parent_id IS NOT NULL AND pc.user_id = ? AND c.user_id != ?))
             AND c.created_at > ?
      ");
      $stmt2->execute([$user_id, $user_id, $user_id, $user_id, $threshold]);
      $count = $stmt2->fetchColumn();
      
      $stmt3 = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND created_at > ?");
      $stmt3->execute([$user_id, $threshold]);
      $msg_count = $stmt3->fetchColumn();
      
      send_json(['count' => (int)$count + (int)$msg_count]);
      break;

    case 'mark_notifs_read':
      if (!$user_id) { http_response_code(403); exit; }
      try { $db->exec("ALTER TABLE users ADD COLUMN last_notif_read DATETIME;"); } catch(Exception $e) {}
      $db->prepare("UPDATE users SET last_notif_read = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id]);
      send_json(['status' => 'success']);
      break;

    case 'edit_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $new_name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      $is_private = intval($data['is_private'] ?? 0);
      if (empty($new_name)) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Playlist name cannot be empty.']);
      }
      $stmt = $db->prepare("UPDATE playlists SET name = ?, is_private = ?, is_collaborative = CASE WHEN ? = 1 THEN 0 ELSE is_collaborative END WHERE public_id = ? AND (user_id = ? OR {$is_super_admin} = 1)");
      $stmt->execute([$new_name, $is_private, $is_private, $public_id, $user_id]);
      
      // Destroy collaborators safely if transitioning to Private
      if ($is_private == 1) {
        $db->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = (SELECT id FROM playlists WHERE public_id = ?)")->execute([$public_id]);
      }
      
      send_json(['status' => 'success', 'message' => 'Playlist updated.']);
      break;

    case 'delete_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'];
      $stmt = $db->prepare("DELETE FROM playlists WHERE public_id = ? AND (user_id = ? OR {$is_super_admin} = 1)");
      $stmt->execute([$public_id, $user_id]);
      if ($stmt->rowCount() > 0) {
        send_json(['status' => 'success', 'message' => 'Playlist deleted.']);
      } else {
        http_response_code(403);
        send_json(['status' => 'error', 'message' => "You're not the owner of this playlist."]);
      }
      break;

    case 'add_to_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $playlist_id = intval($data['playlist_id']);
      $song_ids = is_array($data['song_id']) ? $data['song_id'] : [$data['song_id']];
      
      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND (user_id = ? OR id IN (SELECT playlist_id FROM playlist_collaborators WHERE user_id = ?))");
      $stmt_owner->execute([$playlist_id, $user_id, $user_id]);
      if (!$stmt_owner->fetch()) {
        http_response_code(403); send_json(['status' => 'error', 'message' => 'Not your playlist.']);
      }

      $stmt_order = $db->prepare("SELECT MAX(sort_order) as max_order FROM playlist_songs WHERE playlist_id = ?");
      $stmt_order->execute([$playlist_id]);
      $max_order = $stmt_order->fetchColumn() ?? 0;

      $stmt_insert = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order, added_by) VALUES (?, ?, ?, ?)");
      foreach ($song_ids as $sid) {
        $max_order++;
        $stmt_insert->execute([$playlist_id, intval($sid), $max_order, $user_id]);
      }
      send_json(['status' => 'success', 'message' => 'Added to playlist.']);
      break;

    case 'add_mix_to_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $playlist_id = intval($data['playlist_id']);
      $mix_public_id = $data['mix_id'];
      
      // ALLOW OWNER OR COLLABORATORS
      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND (user_id = ? OR id IN (SELECT playlist_id FROM playlist_collaborators WHERE user_id = ?))");
      $stmt_owner->execute([$playlist_id, $user_id, $user_id]);
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

      $stmt_insert = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order, added_by) VALUES (?, ?, ?, ?)");
      foreach($songs as $sid) {
        $max_order++;
        $stmt_insert->execute([$playlist_id, $sid, $max_order, $user_id]);
      }
      send_json(['status' => 'success', 'message' => 'Added all songs to playlist.']);
      break;

    case 'remove_from_playlist':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['playlist_public_id'];
      $song_id = intval($data['song_id']);

      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE public_id = ? AND (user_id = ? OR is_collaborative = 1 OR {$is_super_admin} = 1)");
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

      $stmt = $db->prepare("SELECT id FROM playlists WHERE public_id = ? AND (user_id = ? OR {$is_super_admin} = 1)");
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
      $playlist_public_id = $data['playlist_public_id'] ?? null;
    
      if ($song_id > 0) {
        // MASS USE OPTIMIZATION: SQLite Write Retry Loop
        $max_retries = 15; // Try up to 15 times before giving up
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
          try {
            $db->beginTransaction();
      
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS history_user_song_idx ON history(user_id, song_id);");
            
            $db->prepare("
              INSERT INTO history (user_id, song_id, played_at) VALUES (?, ?, ?)
              ON CONFLICT(user_id, song_id) DO UPDATE SET played_at = excluded.played_at
            ")->execute([$user_id, $song_id, $played_at_iso]);
      
            $db->prepare("
              INSERT INTO play_counts (user_id, song_id, play_count, last_played) VALUES (?, ?, 1, ?)
              ON CONFLICT(user_id, song_id) DO UPDATE SET
                play_count = play_count + 1,
                last_played = excluded.last_played
            ")->execute([$user_id, $song_id, $played_at_iso]);
            
            $db->prepare("INSERT INTO activity_feed (user_id, action, target_name) VALUES (?, 'listened to', (SELECT title FROM music WHERE id = ?))")->execute([$user_id, $song_id]);

            if ($playlist_public_id) {
              $db->prepare("UPDATE playlists SET play_count = play_count + 1 WHERE public_id = ?")->execute([$playlist_public_id]);
            }

            $db->commit();
            break; // Success, break out of the loop!
            
          } catch (Exception $e) {
            if ($db->inTransaction()) {
              $db->rollBack();
            }
            // If the database is locked by another user, sleep for 10-50 milliseconds and try again
            if (strpos(strtolower($e->getMessage()), 'locked') !== false || strpos(strtolower($e->getMessage()), 'busy') !== false) {
              usleep(rand(10000, 50000)); 
            } else {
              error_log('PHP Music log_play error: ' . $e->getMessage());
              break; // Unrelated error, stop trying
            }
          }
        }
      }
      send_json(['status' => 'success']);
      break;
      
    case 'get_trending':
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count";
      $stmt = $db->prepare("
        SELECT {$song_fields}
        FROM music m
        LEFT JOIN (
            SELECT song_id, SUM(play_count) as total_plays 
            FROM play_counts 
            GROUP BY song_id
        ) pc ON m.id = pc.song_id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        ORDER BY COALESCE(pc.total_plays, 0) DESC, m.id DESC
        LIMIT ? OFFSET ?
      ");
      $stmt->bindValue(1, $user_id ? $user_id : 0, PDO::PARAM_INT);
      $stmt->bindValue(2, (int)PAGE_SIZE, PDO::PARAM_INT);
      $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
      $stmt->execute();
      send_json($stmt->fetchAll());
      break; 
    
    case 'get_history':
      if (!$user_id) { send_json([]); }
      $sort_key = $_GET['sort'] ?? 'history_desc';
      $sort_map = [
        'history_desc' => 'ORDER BY played_at DESC',
        'history_asc'  => 'ORDER BY played_at ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['history_desc'];
      
      $stmt = $db->prepare("
        SELECT MAX(h.played_at) AS played_at, m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite,
        (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
        FROM history h
        JOIN music m ON h.song_id = m.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE h.user_id = ?
        GROUP BY m.id
        {$order_by}
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
      
      // MASS USE OPTIMIZATION: 5-Minute Micro-Cache for heavy Recommendation queries
      $rec_cache_file = sys_get_temp_dir() . '/phpmusic_rec_cache_' . $user_id . '.json';
      if (file_exists($rec_cache_file)) {
        $cached_content = @file_get_contents($rec_cache_file);
        if (!empty($cached_content) && (time() - filemtime($rec_cache_file)) < 300) {
          $decoded = json_decode($cached_content);
          if ($decoded && isset($decoded->shelves)) {
            header('Content-Type: application/json; charset=utf-8');
            echo $cached_content;
            exit;
          }
        }
      }

      $shelves = [];
      $song_fields = "m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.last_modified, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite, (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count";
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
          SELECT m.album, m.artist, m.user_id, m.id, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m
          JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 200) r ON m.id = r.id
          WHERE match_artist(m.artist, :artist_name) = 1 AND m.album != 'Unknown Album' AND m.album != '' AND m.album IS NOT NULL
          AND m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id)
        ");
        $more_from_artist_stmt->execute([':user_id' => $user_id, ':artist_name' => $top_artist]);
        $artist_rows = $more_from_artist_stmt->fetchAll();
        $artist_albums = [];
        foreach ($artist_rows as $row) {
          $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
          if (!is_array($parts)) $parts = [$row['artist']];
          foreach ($parts as $part) {
            $p = trim($part);
            if ($p !== '' && strcasecmp($p, $top_artist) === 0) {
              $key = strtolower($row['album'] . ':::' . $p);
              if (!isset($artist_albums[$key])) {
                $artist_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc']];
              } else {
                $artist_albums[$key]['song_count']++;
                $artist_albums[$key]['total_plays'] += $row['pc'];
              }
            }
          }
        }
        $artist_albums = array_slice(array_values($artist_albums), 0, 10);
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
        SELECT p.name, p.public_id, p.is_collaborative, p.is_private, u.artist as creator, p.play_count,
        (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id,
        (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) as song_count
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
        SELECT m.album, m.artist, m.user_id, m.id, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m
        JOIN (SELECT id FROM music ORDER BY RANDOM() LIMIT 200) r ON m.id = r.id
        WHERE m.id NOT IN (SELECT song_id FROM history WHERE user_id = :user_id) AND m.album != 'Unknown Album' AND m.album != '' AND m.album IS NOT NULL
      ");
      $discovery_stmt->execute([':user_id' => $user_id]);
      $disc_rec_rows = $discovery_stmt->fetchAll();
      $discovery_albums = [];
      foreach ($disc_rec_rows as $row) {
        $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        if (!is_array($parts)) $parts = [$row['artist']];
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($row['album'] . ':::' . $p);
            if (!isset($discovery_albums[$key])) {
              $discovery_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc']];
            } else {
              $discovery_albums[$key]['song_count']++;
              $discovery_albums[$key]['total_plays'] += $row['pc'];
            }
          }
        }
      }
      $discovery_albums = array_slice(array_values($discovery_albums), 0, 10);
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
          
          $mix_pc_stmt = $db->prepare("SELECT SUM(play_count) FROM play_counts WHERE song_id IN (" . implode(',', $song_ids) . ")");
          $mix_pc_stmt->execute();
          $mix_play_count = $mix_pc_stmt->fetchColumn() ?: 0;
          
          $mixes[] = [
            'public_id' => $public_id,
            'name' => $name,
            'creator' => 'PHP-Music',
            'image_id' => $img_id,
            'song_count' => count($song_ids),
            'play_count' => $mix_play_count
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
        SELECT m.album, m.artist, m.user_id, m.id, COALESCE((SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id), 0) as pc FROM music m
        WHERE m.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id) AND m.album != 'Unknown Album' AND m.album != '' AND m.album IS NOT NULL
        ORDER BY id DESC LIMIT 200
      ");
      $latest_followed_albums_stmt->execute([':user_id' => $user_id]);
      $lf_rows = $latest_followed_albums_stmt->fetchAll();
      $latest_followed_albums = [];
      foreach ($lf_rows as $row) {
        $parts = @preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $row['artist']);
        if (!is_array($parts)) $parts = [$row['artist']];
        foreach ($parts as $part) {
          $p = trim($part);
          if ($p !== '') {
            $key = strtolower($row['album'] . ':::' . $p);
            if (!isset($latest_followed_albums[$key])) {
              $latest_followed_albums[$key] = ['album' => $row['album'], 'artist' => $p, 'user_id' => $row['user_id'], 'id' => $row['id'], 'song_count' => 1, 'total_plays' => $row['pc']];
            } else {
              $latest_followed_albums[$key]['song_count']++;
              $latest_followed_albums[$key]['total_plays'] += $row['pc'];
            }
          }
        }
      }
      $latest_followed_albums = array_slice(array_values($latest_followed_albums), 0, 10);
      if (count($latest_followed_albums) > 0) {
        $shelves[] = ['title' => 'From Your Artists: New Albums', 'type' => 'albums', 'items' => $latest_followed_albums];
      }

      // MASS USE OPTIMIZATION: Save to cache
      $final_rec_json = json_encode(['shelves' => $shelves], JSON_INVALID_UTF8_SUBSTITUTE);
      if ($final_rec_json) {
        @file_put_contents($rec_cache_file, $final_rec_json);
      }
      
      header('Content-Type: application/json; charset=utf-8');
      echo $final_rec_json ?: '{"shelves":[]}';
      exit;

    case 'export_notes':
      if (!$user_id) { http_response_code(403); exit; }
      $stmt = $db->prepare("SELECT title, content, created_at, updated_at FROM personal_notes WHERE user_id = ? ORDER BY created_at ASC");
      $stmt->execute([$user_id]);
      $rows = $stmt->fetchAll();
      if (empty($rows)) { http_response_code(404); exit; }

      $export_data = [
        'name' => 'Personal Notes',
        'notes' => $rows
      ];
      
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="personal_notes.json"');
      echo json_encode($export_data, JSON_PRETTY_PRINT);
      exit;

    case 'import_notes':
      if (!$user_id) { http_response_code(403); exit; }
      $import_data = json_decode(file_get_contents('php://input'), true);
      if (json_last_error() !== JSON_ERROR_NONE || !isset($import_data['notes']) || !is_array($import_data['notes'])) {
        http_response_code(400); send_json(['status' => 'error', 'message' => 'Invalid or malformed JSON payload.']);
      }

      $db->beginTransaction();
      try {
        $stmt_insert = $db->prepare("INSERT INTO personal_notes (user_id, title, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($import_data['notes'] as $note) {
          $title = isset($note['title']) ? htmlspecialchars($note['title']) : 'Imported Note';
          // Raw content from export might already be formatted, but we run it through our standard text wrapper safely
          $content = isset($note['content']) ? format_user_text($note['content']) : ''; 
          $created_at = isset($note['created_at']) ? $note['created_at'] : date('Y-m-d H:i:s');
          $updated_at = isset($note['updated_at']) ? $note['updated_at'] : date('Y-m-d H:i:s');
          
          if ($content !== '') {
            $stmt_insert->execute([$user_id, $title, $content, $created_at, $updated_at]);
            $count++;
          }
        }
        $db->commit();
        send_json(['status' => 'success', 'message' => "Successfully imported {$count} notes."]);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500); send_json(['status' => 'error', 'message' => 'Database error during import.']);
      }
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
        WHERE p.public_id = ? AND (p.user_id = ? OR {$is_super_admin} = 1) AND (m.is_private = 0 OR m.user_id = ? OR {$is_super_admin} = 1)
        ORDER BY ps.sort_order ASC
      ");
      $stmt->execute([$public_id, $user_id, $user_id]);
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
  $db->exec("UPDATE music SET file = REPLACE(file, '\\', '/')"); 
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
      $filePath = str_replace('\\', '/', $file->getRealPath()); // Normalize slashes
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
    // SMART DIFF: Only update if the timestamp doesn't match perfectly.
    // This is blazing fast, but still catches files moved between servers/drives!
    if ($mtime != $db_files[$filePath]) {
      $files_to_update[$filePath] = $mtime;
    }
  }

  $unchanged_count = count(array_intersect_key($files_on_disk, $db_files)) - count($files_to_update);

  echo " - Existing (Unchanged): " . $unchanged_count . "\n";
  echo " - To add: " . count($files_to_add) . "\n";
  echo " - To update: " . count($files_to_update) . "\n";
  echo " - To delete: " . count($files_to_delete) . "\n\n";

  $files_to_process = $files_to_add + $files_to_update;
  if (empty($files_to_process) && empty($files_to_delete)) {
    die("Scan complete. No changes detected.\n</pre>");
  }

  echo "Step 6: Processing changes...\n";
  
  $getID3 = new getID3;
  // EXTREME OPTIMIZATION: Disable heavy hashing and extra parsing
  $getID3->option_md5_data = false;
  $getID3->option_md5_data_source = false;
  $getID3->option_sha1_data = false;
  $getID3->option_tags_html = false;

  $insert_stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, bitrate, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $update_stmt_with_image = $db->prepare("UPDATE music SET title = ?, artist = ?, album = ?, genre = ?, year = ?, duration = ?, bitrate = ?, image = ?, last_modified = ? WHERE file = ?");
  $update_stmt_no_image = $db->prepare("UPDATE music SET title = ?, artist = ?, album = ?, genre = ?, year = ?, duration = ?, bitrate = ?, last_modified = ? WHERE file = ?");
  $delete_stmt = $db->prepare("DELETE FROM music WHERE file = ?");
  
  // EXTREME OPTIMIZATION: Cache Users in RAM to skip 1000s of SELECT queries
  $user_cache = [];
  $stmt_all_users = $db->query("SELECT id, artist FROM users");
  while ($row = $stmt_all_users->fetch()) {
      $user_cache[strtolower($row['artist'])] = $row['id'];
  }
  
  $check_email_stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
  $insert_user_stmt = $db->prepare("INSERT INTO users (email, artist, password_hash, verified, profile_picture, profile_picture_type) VALUES (?, ?, ?, 'no', ?, 'image/svg+xml')");

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
        
        // Advanced Fallback: Check local directory for cover image files if ID3 tag is missing
        if (!$raw_image_data) {
          $file_info = pathinfo($filePath);
          $dir = $file_info['dirname'];
          $filename = $file_info['filename'];
            
          $possible_covers = [
            $filename . '.jpg',
            $filename . '.png',
            'cover.jpg',
            'cover.png',
            'folder.jpg',
            'folder.png',
            'artwork.jpg'
          ];
            
          foreach ($possible_covers as $cfile) {
            $cpath = $dir . '/' . $cfile;
              if (file_exists($cpath)) {
                $raw_image_data = @file_get_contents($cpath);
              if ($raw_image_data) break;
            }
          }
        }
        
        $webp_image_data = process_image_to_webp($raw_image_data);
        
        $is_update = isset($db_files[$filePath]);

        if ($is_update) {
            if ($webp_image_data !== null) {
                $update_stmt_with_image->execute([$title, $artist_tag, $album, $genre, $year, $duration, $bitrate, $webp_image_data, $mtime, $filePath]);
            } else {
                $update_stmt_no_image->execute([$title, $artist_tag, $album, $genre, $year, $duration, $bitrate, $mtime, $filePath]);
            }
        } else {
            $file_user_id = $library_user_id;
            $main_artist = trim(preg_split('/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i', $artist_tag)[0]);

            if ($main_artist !== 'Unknown Artist' && !empty($main_artist)) {
              $artist_lower = strtolower($main_artist);
              // Read from RAM cache instead of hitting the database!
              if (isset($user_cache[$artist_lower])) {
                $file_user_id = $user_cache[$artist_lower];
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
                
                $initial = mb_strtoupper(mb_substr($main_artist, 0, 1, 'UTF-8'));
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="#ffffff"/><text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#121212">' . htmlspecialchars($initial) . '</text></svg>';
                
                $insert_user_stmt->execute([$email, $main_artist, $hash, $svg]);
                $file_user_id = $db->lastInsertId();
                // Add the new user to RAM Cache
                $user_cache[$artist_lower] = $file_user_id; 
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
      unset($raw_image_data);
      
      // OPTIMIZATION: Periodically dump SQLite WAL memory to disk to prevent RAM exhaustion
      if ($processed_count % 200 === 0) {
          $db->commit();
          gc_collect_cycles();
          $db->beginTransaction();
      }
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
    <meta property="og:title" content="<?php echo $og_title; ?>">
    <meta property="og:description" content="<?php echo $og_desc; ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?php echo $og_image; ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PHP Music">
    <meta name="application-name" content="PHP Music">
    <title>PHP Music</title>
    <link rel="icon" type="image/svg+xml" href="?action=get_app_icon" />
    <meta name="theme-color" content="#0a0a0a"/>
    <link rel="manifest" href="?pwa=manifest" crossorigin="use-credentials">
    <script>
      // ANTI-INSPECT: Block Eruda/vConsole, with Super Admin Bypass
      (function() {
        const blockInspect = () => {
          document.documentElement.innerHTML = '<div style="background-color:#030303; color:#ff0000; font-family:sans-serif; padding: 5rem 1rem; text-align:center; height:100vh; width:100vw; z-index:999999; position:fixed; top:0; left:0;"><h3>Security Violation</h3><p>Inspection tools are strictly forbidden on this application.</p></div>';
          setTimeout(() => { window.location.replace('about:blank'); }, 500);
        };

        const checkBypass = () => {
          if (localStorage.getItem('dev_mode_token') === 'admin') return true;
          const pwd = prompt("Developer tools locked. Enter admin password:");
          if (pwd === 'admin') {
            localStorage.setItem('dev_mode_token', 'admin');
            return true;
          }
          if (pwd !== null) {
            alert("Password incorrect. You want to try exploit the site?");
          }
          return false;
        };
        
        let _eruda, _vConsole;
        try {
          Object.defineProperty(window, 'eruda', { 
            get: () => _eruda, 
            set: (val) => { if (checkBypass()) _eruda = val; else blockInspect(); }, 
            configurable: true 
          });
          Object.defineProperty(window, 'vConsole', { 
            get: () => _vConsole, 
            set: (val) => { if (checkBypass()) _vConsole = val; else blockInspect(); }, 
            configurable: true 
          });
        } catch (e) {}
        
        const observer = new MutationObserver((mutations) => {
          if (localStorage.getItem('dev_mode_token') === 'admin') return;
          for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
              if (node.id === 'eruda' || node.id === '__vconsole' || 
                 (node.className && typeof node.className === 'string' && (node.className.includes('__eruda') || node.className.includes('vc-switch')))) {
                if (!checkBypass()) blockInspect();
              }
            }
          }
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });

        setInterval(() => {
          if (localStorage.getItem('dev_mode_token') === 'admin') return;
          if (document.getElementById('eruda') || document.querySelector('[class^="__eruda"]') || document.getElementById('__vconsole') || document.querySelector('.vc-switch')) {
            if (!checkBypass()) blockInspect();
          }
        }, 500);
      })();

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
                if (name !== 'php-music-offline') {
                  caches.delete(name);
                }
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
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
      .nav-tabs { border-bottom-color: var(--ytm-surface-2) !important; }
      .nav-tabs .nav-link { color: var(--ytm-secondary-text) !important; border: none !important; border-bottom: 2px solid transparent !important; padding: 0.75rem 1.5rem; font-weight: 500; cursor: pointer; background: transparent !important; transition: color 0.2s, border-color 0.2s; }
      .nav-tabs .nav-link:hover { color: var(--ytm-primary-text) !important; border-bottom: 2px solid rgba(255,255,255,0.2) !important; }
      /* Locks the active tab highlight so it never vanishes */
      .nav-tabs .nav-link.active { background-color: transparent !important; color: var(--ytm-primary-text) !important; border-bottom: 2px solid var(--ytm-accent) !important; font-weight: bold !important; text-shadow: 0 0 10px rgba(255,255,255,0.3); }
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
        .song-item.history-item { grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) minmax(0, 2fr) 80px 40px; }
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
        display: grid; grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) 80px 40px;
        align-items: center; gap: 1rem; padding: 0.5rem 1rem; font-size: 0.9rem; color: var(--ytm-secondary-text);
      }
      .song-list-header, .song-item {
        display: grid; grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) 80px 40px;
        align-items: center; gap: 1rem; padding: 0.5rem 1rem; font-size: 0.9rem; color: var(--ytm-secondary-text);
      }
      @media (min-width: 768px) {
        .song-item.history-item { grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) minmax(0, 2fr) 80px 40px !important; }
      }
      .song-list-header { font-weight: 500; }
      .song-item .song-artist-mobile { display: none !important; }
      /* Clean, spacious layout for the constrained Desktop Player Modal queue */
      #desktop-player-queue-list .song-item {
        grid-template-columns: 40px minmax(0, 3fr) minmax(0, 2fr) 80px 40px !important;
      }
      #desktop-player-queue-list .song-item .song-album,
      #desktop-player-queue-list .song-item .song-views {
        display: none !important;
      }
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
        z-index: 10; color: #ffffff; text-shadow: 0 2px 6px rgba(0,0,0,0.9);
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
        display: grid; grid-auto-flow: column; grid-auto-columns: 130px; justify-content: start; overflow-x: auto;
        gap: 1rem; padding-bottom: 1rem; scrollbar-color: var(--ytm-surface-2) var(--ytm-surface); scrollbar-width: thin;
      }
      @media (min-width: 768px) {
        .shelf-items { grid-auto-columns: 160px; }
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
        .song-item .song-artist-mobile { display: flex !important; flex-direction: column; align-items: flex-start; justify-content: center; grid-column: 2; grid-row: 2; font-size: 0.8rem; color: var(--ytm-secondary-text); min-width: 0; gap: 0; }
        .song-duration-mobile { display: block !important; flex-shrink: 0; }
        .song-item .song-more { grid-column: 3; grid-row: 1 / span 2; }
        .view-details-header { flex-direction: column; align-items: center; text-align: center; }
        .song-list-header { border-bottom: 1px solid var(--ytm-surface-2); }
      }
      .loader { text-align: center; padding: 3rem; font-size: 1.2rem; color: var(--ytm-secondary-text); }
      /* Solid dark base blocks the main page from bleeding through */
      .player-modal-content { position: relative; overflow: hidden; background-color: #050505 !important; color: var(--ytm-primary-text); transition: color 0.4s ease; }
      /* The blurred cover image sits inside the modal */
      .dynamic-blur-bg { position: absolute; top: -60px; left: -60px; right: -60px; bottom: -60px; background-size: cover; background-position: center; filter: blur(45px) brightness(0.4); opacity: 0.85; z-index: 0; transition: background-image 0.8s ease, filter 0.4s ease; pointer-events: none; }
      
      /* Dynamic Light Theme Overrides for Player Modals */
      .player-modal-content.theme-light-bg { background-color: #ffffff !important; color: #000000 !important; }
      .player-modal-content.theme-light-bg .dynamic-blur-bg { filter: blur(50px) brightness(1.05); opacity: 0.85; }
      .player-modal-content.theme-light-bg .text-white { color: #000000 !important; }
      .player-modal-content.theme-light-bg .text-secondary { color: #444444 !important; font-weight: 500; }
      .player-modal-content.theme-light-bg .player-btn { color: #333333 !important; }
      .player-modal-content.theme-light-bg .player-btn:hover,
      .player-modal-content.theme-light-bg .player-btn.active { color: #000000 !important; }
      .player-modal-content.theme-light-bg .play-btn { background-color: #000000 !important; color: #ffffff !important; }
      .player-modal-content.theme-light-bg .play-btn .bi { color: #ffffff !important; }
      .player-modal-content.theme-light-bg .progress-bar-bg { background-color: rgba(0, 0, 0, 0.2) !important; }
      .player-modal-content.theme-light-bg .progress-bar-fg { background-color: #000000 !important; }
      .player-modal-content.theme-light-bg .progress-bar-container:hover .progress-bar-fg { background-color: var(--ytm-accent) !important; }
      .player-modal-content.theme-light-bg .progress-bar-fg::after { background-color: #000000 !important; }
      .player-modal-content.theme-light-bg .nav-tabs .nav-link { color: rgba(0,0,0,0.6) !important; font-weight: 600 !important; border-bottom: 2px solid transparent !important; }
      .player-modal-content.theme-light-bg .nav-tabs .nav-link:hover { color: #000000 !important; border-bottom: 2px solid rgba(0,0,0,0.2) !important; }
      /* Locks the active tab highlight in Light Mode */
      .player-modal-content.theme-light-bg .nav-tabs .nav-link.active { color: #000000 !important; border-bottom: 2px solid #000000 !important; font-weight: 800 !important; text-shadow: none !important; }
      .player-modal-content.theme-light-bg .song-item .song-title { color: #000000 !important; font-weight: 600; }
      .player-modal-content.theme-light-bg .song-item:hover { background-color: rgba(0,0,0,0.08) !important; }
      .player-modal-content.theme-light-bg .lyric-line { color: rgba(0,0,0,0.6) !important; }
      .player-modal-content.theme-light-bg .lyric-line:hover { color: #000000 !important; }
      .player-modal-content.theme-light-bg .lyric-line.active { color: var(--ytm-accent) !important; text-shadow: none; font-weight: 800; }
      .player-modal-content.theme-light-bg #dp-tabs-content { background-color: rgba(255, 255, 255, 0.6) !important; border-color: rgba(0, 0, 0, 0.15) !important; }
      
      .player-modal-header, .player-modal-body { position: relative; z-index: 1; }
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
      .song-artist:hover, .song-artist-name:hover, .hover-underline:hover { text-decoration: underline; }
      #multi-select-bar {
        position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
        background: rgba(40, 40, 40, 0.95); backdrop-filter: blur(10px);
        border: 1px solid #555; border-radius: 50px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.8); padding: 12px 20px; z-index: 1010;
        display: flex; align-items: center; justify-content: center;
        width: max-content; max-width: 95vw; overflow: visible;
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
      .hover-white:hover { color: #ffffff !important; }
      
      #sleep-timer-bubble { position: fixed; top: 80px; right: 20px; background: rgba(30, 30, 30, 0.9); backdrop-filter: blur(10px); border: 1px solid var(--ytm-surface-2); border-radius: 50px; padding: 8px 16px; z-index: 1060; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.5); cursor: grab; user-select: none; touch-action: none; }
      #sleep-timer-bubble:active { cursor: grabbing; }
      #sleep-timer-bubble .time { font-weight: bold; font-family: monospace; font-size: 1.1rem; color: #fff; }
      #sleep-timer-bubble .action-btn { background: none; border: none; padding: 0; font-size: 1.2rem; display: flex; align-items: center; transition: color 0.2s; }
      #sleep-timer-bubble .action-btn:hover { color: var(--ytm-primary-text) !important; }
      #sleep-timer-bubble #sleep-timer-cancel-btn:hover { color: var(--ytm-accent) !important; }
      #sleep-timer-bubble #sleep-timer-wake-btn.active { color: var(--ytm-accent) !important; }
      
      #artist-hover-tooltip {
        position: absolute; z-index: 1080; background: var(--ytm-surface-2); border: 1px solid #404040;
        border-radius: 12px; padding: 1rem; width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,0.8);
        display: none; opacity: 0; transition: opacity 0.2s ease-in-out; pointer-events: auto;
      }
      .tooltip-follow-btn { transition: background-color 0.2s, color 0.2s; }
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
          <a href="#" class="nav-link" data-view="get_years">
            <i class="bi bi-calendar-event-fill"></i>
            <span>Years</span>
          </a>
          <a href="#" class="nav-link" data-view="get_trending">
            <i class="bi bi-graph-up-arrow"></i>
            <span>Top 100 Trending</span>
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
            <a href="#" class="nav-link" data-view="get_collab_playlists">
              <i class="bi bi-people-fill"></i>
              <span>Shared With Me</span>
            </a>
            <a href="#" class="nav-link" data-view="get_offline_songs">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Offline Library</span>
            </a>
            <a href="#" class="nav-link" data-view="get_following">
              <i class="bi bi-person-lines-fill"></i>
              <span>Following</span>
            </a>
            <a href="#" class="nav-link" data-view="get_listen_later">
              <i class="bi bi-clock-fill"></i>
              <span>Listen Later</span>
            </a>
            <a href="#" class="nav-link" data-view="get_community">
              <i class="bi bi-people"></i>
              <span>Community</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#inbox-modal">
              <i class="bi bi-chat-dots-fill"></i>
              <span>Messages</span>
              <span class="badge bg-danger rounded-pill d-none ms-auto inbox-badge">0</span>
            </a>
            <a href="#" class="nav-link" data-view="get_notes">
              <i class="bi bi-journal-text"></i>
              <span>Personal Notes</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#calendar-modal">
              <i class="bi bi-calendar3"></i>
              <span>Calendar</span>
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
              <span>How To Use</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#shortcuts-modal">
              <i class="bi bi-keyboard-fill"></i>
              <span>Keyboard Shortcuts</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#playlist-downloader-modal">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Downloader</span>
            </a>
            <a href="#" class="nav-link logged-in-only" id="nav-upload-btn">
              <i class="bi bi-cloud-upload-fill"></i>
              <span>Upload Song</span>
            </a>
            <a href="#" class="nav-link logged-in-only" id="nav-scan-all" data-bs-toggle="modal" data-bs-target="#full-scan-modal" style="display: none !important;">
              <i class="bi bi-hdd-stack-fill"></i>
              <span>Scan All</span>
            </a>
            <a href="#" class="nav-link d-none" id="install-pwa-btn">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span>Install App</span>
            </a>
            <a href="#" class="nav-link" id="get-api-btn">
              <i class="bi bi-code-slash"></i>
              <span>Get API</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#license-modal">
              <i class="bi bi-file-earmark-text-fill"></i>
              <span>License</span>
            </a>
            <a href="#" class="nav-link" id="check-update-btn">
              <i class="bi bi-arrow-clockwise"></i>
              <span>Check Update</span>
            </a>
            <a href="https://github.com/HirotakaDango/PHP-Music/archive/refs/heads/main.zip" target="_blank" class="dl-independent" style="color:var(--ytm-secondary-text);display:flex;align-items:center;font-weight:500;border-left:3px solid transparent;gap:1rem;text-decoration:none;padding:0.75rem 1.5rem;transition:background 0.2s;" onmouseover="this.style.backgroundColor='var(--ytm-surface)';this.style.color='var(--ytm-primary-text)'" onmouseout="this.style.backgroundColor='transparent';this.style.color='var(--ytm-secondary-text)'">
              <i class="bi bi-file-earmark-zip-fill" style="font-size:1.25rem;width:24px;text-align:center;"></i>
              <span>Download Source Code</span>
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
            <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" class="profile-picture" id="profile-picture-header-mobile" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <li><a class="dropdown-item d-flex justify-content-between align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#activity-modal">
                <span><i class="bi bi-bell-fill me-2"></i>My Activity</span>
                <span class="badge bg-danger rounded-pill d-none notif-badge">0</span>
              </a></li>
              <li><a class="dropdown-item d-flex justify-content-between align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#inbox-modal">
                <span><i class="bi bi-chat-dots-fill me-2"></i>Direct Messages</span>
                <span class="badge bg-danger rounded-pill d-none inbox-badge">0</span>
              </a></li>
              <li><a class="dropdown-item" href="#" id="profile-dropdown-stats-mobile"><i class="bi bi-bar-chart-line-fill me-2"></i>Statistics</a></li>
              <li><a class="dropdown-item" href="#" id="sleep-timer-btn-mobile"><i class="bi bi-moon-stars-fill me-2"></i>Sleep Timer</a></li>
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
              <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" class="profile-picture" id="profile-picture-header-desktop" alt="Profile" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
              <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                <li><a class="dropdown-item d-flex justify-content-between align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#activity-modal">
                  <span><i class="bi bi-bell-fill me-2"></i>My Activity</span>
                  <span class="badge bg-danger rounded-pill d-none notif-badge">0</span>
                </a></li>
                <li><a class="dropdown-item d-flex justify-content-between align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#inbox-modal">
                  <span><i class="bi bi-chat-dots-fill me-2"></i>Direct Messages</span>
                  <span class="badge bg-danger rounded-pill d-none inbox-badge">0</span>
                </a></li>
                <li><a class="dropdown-item" href="#" id="profile-dropdown-stats-desktop"><i class="bi bi-bar-chart-line-fill me-2"></i>Statistics</a></li>
                <li><a class="dropdown-item" href="#" id="profile-dropdown-stats-desktop"><i class="bi bi-bar-chart-line-fill me-2"></i>Statistics</a></li>
                <li><a class="dropdown-item" href="#" id="sleep-timer-btn-desktop"><i class="bi bi-moon-stars-fill me-2"></i>Sleep Timer</a></li>
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
          <div class="modal-body text-light p-4" style="line-height: 1.7; font-size: 0.95rem;">
            
            <div class="text-center mb-5 mt-2">
              <i class="bi bi-journal-album text-danger" style="font-size: 4rem;"></i>
              <h2 class="fw-bold mt-3 text-white">The Ultimate Guide</h2>
              <p class="text-secondary">Master every advanced feature, gesture, and tool available in the PHP Music platform.</p>
            </div>

            <!-- SECTION 1: PLAYBACK & NAVIGATION -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-play-circle-fill text-danger me-2"></i> 1. Playback & Core Navigation</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-mouse-fill"></i></div>
                      <div>
                        <strong class="text-white">Instant Playback</strong><br>
                        <span class="text-secondary">Simply click or tap on any song row in any list, album, or playlist. The audio will immediately stream, and the persistent player bar will appear at the bottom of your screen.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-arrows-collapse"></i></div>
                      <div>
                        <strong class="text-white">Fullscreen Mode & Visualizer</strong><br>
                        <span class="text-secondary">Click the square album artwork inside the bottom player bar. This triggers the immersive Fullscreen Player, which features an audio-reactive visualizer that bounces to the beat, synced lyrics, and the Up Next queue.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-fast-forward-fill"></i></div>
                      <div>
                        <strong class="text-white">Seek Gestures</strong><br>
                        <span class="text-secondary">Click and hold (long press) the <strong>Next</strong> <i class="bi bi-skip-end-fill"></i> or <strong>Previous</strong> <i class="bi bi-skip-start-fill"></i> buttons. This will seamlessly fast-forward or rewind the currently playing track in precise 5-second increments.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-pip"></i></div>
                      <div>
                        <strong class="text-white">Picture-in-Picture (PiP) Mini Player</strong><br>
                        <span class="text-secondary">On desktop, click the <i class="bi bi-pip"></i> icon to detach the player into a floating window. It stays visible over all other tabs and applications, complete with interactive playback controls and real-time scrolling lyrics!</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-moon-stars-fill"></i></div>
                      <div>
                        <strong class="text-white">Sleep Timer & Wake Lock</strong><br>
                        <span class="text-secondary">Open your profile dropdown (top right) and select "Sleep Timer". Enter the number of minutes, and the music will gracefully pause when time is up. You can optionally toggle the <i class="bi bi-display"></i> Wake Lock icon to physically prevent your phone screen from dimming while you read lyrics.</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 2: AUDIO ENGINE -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-sliders text-info me-2"></i> 2. The Advanced Audio Engine</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-soundwave"></i></div>
                      <div>
                        <strong class="text-white">5-Band Equalizer & Crossfade</strong><br>
                        <span class="text-secondary">Inside the <i class="bi bi-gear-fill"></i> Settings menu, toggle the Equalizer to sculpt the frequencies (Bass, Mids, Treble). You can also adjust the Crossfade slider to seamlessly blend the end of one song into the beginning of the next!</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-arrow-down-up"></i></div>
                      <div>
                        <strong class="text-white">Volume Normalization (AGC)</strong><br>
                        <span class="text-secondary">Enabled by default, Automatic Gain Control ensures that quiet songs and loud songs play at the exact same volume level, eliminating the need to constantly adjust your speakers.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-headset"></i></div>
                      <div>
                        <strong class="text-white">3D Spatial Audio (HRTF)</strong><br>
                        <span class="text-secondary">Enable this toggle in Settings to process the stereo signal through a Head-Related Transfer Function, simulating a surround-sound room environment for headphone users.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-toggles"></i></div>
                      <div>
                        <strong class="text-white">Per-Song Overrides</strong><br>
                        <span class="text-secondary">If a specific song was mastered poorly, open its Context Menu <i class="bi bi-three-dots-vertical"></i> and click "Audio Settings (This Song)". You can set a unique volume multiplier and EQ curve that will permanently trigger <i>only</i> when that specific song plays!</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 3: MULTI-SELECT & BULK ACTIONS -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-ui-checks-grid text-success me-2"></i> 3. Multi-Select Mode</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-hand-index-thumb-fill"></i></div>
                      <div>
                        <strong class="text-white">Activating Selection Mode</strong><br>
                        <span class="text-secondary">To manage many songs at once, press and hold (long-click) on any song row for exactly 1 second. A translucent red highlight will appear, and a floating toolbar will slide up from the bottom of your screen.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-check2-square"></i></div>
                      <div>
                        <strong class="text-white">Selecting Multiple Tracks</strong><br>
                        <span class="text-secondary">Once activated, you can tap on any other song rows to add them to your selection bundle. You can also click the <i class="bi bi-check-all"></i> icon in the floating bar to instantly select every track currently loaded on the screen.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-layers-fill"></i></div>
                      <div>
                        <strong class="text-white">Bulk Actions</strong><br>
                        <span class="text-secondary">Click the three dots <i class="bi bi-three-dots-vertical"></i> on the floating bar. From here, you can instantly inject all selected tracks into a Playlist, dump them into your Favorites, forcefully Cache them for Offline playback, or Bulk Delete them!</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 4: PLAYLISTS & SORTING -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-music-note-list text-warning me-2"></i> 4. Playlists & Organization</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-lock-fill"></i></div>
                      <div>
                        <strong class="text-white">Public vs. Private Playlists</strong><br>
                        <span class="text-secondary">When creating a playlist, you can toggle privacy. Public playlists can be searched and viewed by anyone, while Private playlists are strictly visible only to your account.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-people-fill"></i></div>
                      <div>
                        <strong class="text-white">Collaborative Sessions</strong><br>
                        <span class="text-secondary">Open your playlist's menu and select "Make Collaborative". You can then click "Manage Collaborators" and invite friends by typing their exact Username or Email. They will instantly gain the ability to add, reorder, and remove tracks in your playlist!</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-arrows-move"></i></div>
                      <div>
                        <strong class="text-white">Drag and Drop Sorting</strong><br>
                        <span class="text-secondary">When viewing your Playlists, Favorites, or Offline library, ensure the sort dropdown is set to "My Order". You can then seamlessly drag and drop songs up and down the list. The database saves your new arrangement automatically!</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-sort-down-alt"></i></div>
                      <div>
                        <strong class="text-white">Intelligent Filtering</strong><br>
                        <span class="text-secondary">Use the Sort Dropdown located at the top right of the interface to instantly reorganize massive lists by Title, Artist, Album, Release Year, or Recently Added.</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 5: OFFLINE CACHING & PWA -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-cloud-arrow-down-fill text-primary me-2"></i> 5. Offline Library & Caching</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-phone-fill"></i></div>
                      <div>
                        <strong class="text-white">Installing the App (PWA)</strong><br>
                        <span class="text-secondary">Click "Install App" in the sidebar. This registers PHP Music as a Progressive Web App directly onto your Home Screen, bypassing the browser UI, accelerating load times via Service Workers, and enabling true disconnected offline usage.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-cloud-check-fill"></i></div>
                      <div>
                        <strong class="text-white">Downloading Tracks</strong><br>
                        <span class="text-secondary">Open a song's menu and select "Make Available Offline". The audio stream and album art will physically download into your browser's encrypted Storage Quota. A green checkmark <i class="bi bi-cloud-check-fill text-success"></i> will confirm it is secure.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-exclamation-triangle-fill"></i></div>
                      <div>
                        <strong class="text-white">Cache Management & Warnings</strong><br>
                        <span class="text-secondary">If your device runs extremely low on storage, iOS/Android might silently delete cache chunks. If a song shows a warning <i class="bi bi-cloud-slash-fill text-warning"></i>, simply click "Re-download Cache" to repair the file, or use "Re-cache All" in the Offline view.</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 6: SOCIAL & COMMUNITY -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-people-fill text-secondary me-2"></i> 6. Community & Interaction</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-chat-quote-fill"></i></div>
                      <div>
                        <strong class="text-white">Global Community Feed</strong><br>
                        <span class="text-secondary">Access the Community tab to broadcast text posts to all users on the server. You can edit, delete, and Like/Dislike posts globally.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-chat-left-text-fill"></i></div>
                      <div>
                        <strong class="text-white">Song Threads & Mentions</strong><br>
                        <span class="text-secondary">Every single track contains a dedicated comment section. Open the song menu and click "View Comments". You can reply to specific users, use <code>@Username</code> to tag them, and vote on track popularity.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-person-plus-fill"></i></div>
                      <div>
                        <strong class="text-white">Following Users</strong><br>
                        <span class="text-secondary">Click the "Follow" button on any user's profile. Their newly uploaded songs and albums will automatically surface in your personalized "For You" feed!</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-journal-check"></i></div>
                      <div>
                        <strong class="text-white">Personal Notes</strong><br>
                        <span class="text-secondary">Need to write down a lyric draft or a diary entry while listening? Use the Personal Notes tab. These notes are completely private, heavily encrypted in the database, and timestamped upon modification.</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 7: UPLOADING & METADATA -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-file-earmark-music-fill text-muted me-2"></i> 7. Uploading & Deep Metadata</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-upload"></i></div>
                      <div>
                        <strong class="text-white">Upload Pipeline</strong><br>
                        <span class="text-secondary">Verified users can upload up to 10 tracks daily. The system accepts MP3, FLAC, M4A, OGG, and WAV. The server automatically parses the ID3 Tags (Title, Artist, Album, Genre) and extracts embedded Cover Art during upload.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-pencil-square"></i></div>
                      <div>
                        <strong class="text-white">Live Metadata Editor</strong><br>
                        <span class="text-secondary">If the automated ID3 parsing is incorrect, click "Edit Info" on any song you uploaded. You can dynamically overwrite the database fields and attach a new cover art file (which will be perfectly 1:1 cropped using the CropperJS engine).</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-mic-fill"></i></div>
                      <div>
                        <strong class="text-white">Synchronized LRC Lyrics</strong><br>
                        <span class="text-secondary">When editing Metadata, you can paste standard <code>[mm:ss.xx]</code> LRC format strings into the Lyrics box. The player engine will automatically parse these timestamps and scroll the lyrics perfectly to the audio track during playback!</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 8: PORTABILITY & BACKUPS -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-box-arrow-up text-info me-2"></i> 8. Data Portability & Downloads</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-filetype-json"></i></div>
                      <div>
                        <strong class="text-white">JSON Library Exports</strong><br>
                        <span class="text-secondary">Never lose your curated lists. Navigate to any Playlist, your Favorites, or your Offline Library, and click the Export button. The server generates a lightweight `.json` manifest that can be imported to perfectly reconstruct the list anywhere.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-hdd-network-fill"></i></div>
                      <div>
                        <strong class="text-white">The Playlist Downloader</strong><br>
                        <span class="text-secondary">Open the Downloader tool from the sidebar. Paste the Public ID of any playlist, and the system will queue all tracks. Click "Start Sequential Download" to rip the physical MP3s directly to your hard drive, one by one.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-shield-lock-fill"></i></div>
                      <div>
                        <strong class="text-white">Cryptographic Account Backups</strong><br>
                        <span class="text-secondary">In Settings, you can choose to "Delete Account but Keep Data". The server destroys your email/password logic, turns you into an anonymous ghost account, and provides a complex Backup Key. Keep this key safe—you can enter it into the "Restore Account" module later to reclaim your exact library under a totally different name!</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 9: DEVELOPER & ADMIN TOOLS -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-terminal-fill text-light me-2"></i> 9. Developer & Power-User Tools</h5>
              </div>
              <div class="card-body">
                <ul class="list-unstyled mb-0">
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-code-slash"></i></div>
                      <div>
                        <strong class="text-white">Open API Endpoints</strong><br>
                        <span class="text-secondary">Click "Get API" in the sidebar. This tool reveals all the internal backend URL hooks (e.g., <code>?action=get_songs</code>). You can copy these endpoints to write python scripts, discord bots, or external UI interfaces that tap directly into your PHP Music database.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-hdd-stack-fill"></i></div>
                      <div>
                        <strong class="text-white">Full Library Scan</strong><br>
                        <span class="text-secondary">If a server administrator physically drags thousands of MP3s into the server folder using FTP, they can trigger a "Scan All" from the sidebar. The engine sweeps the directory tree, analyzes every ID3 tag, and aggressively injects them into the SQLite database.</span>
                      </div>
                    </div>
                  </li>
                  <li class="mb-0">
                    <div class="d-flex align-items-start gap-3">
                      <div class="p-2 bg-dark rounded text-white fs-5"><i class="bi bi-eraser-fill"></i></div>
                      <div>
                        <strong class="text-white">Clear Application Cache</strong><br>
                        <span class="text-secondary">If the player starts acting buggy or storage quota is overloaded, click "Clear Cache" in the sidebar. This securely unregisters Service Workers, deletes old Offline caches, resets DOM memory, and forces a hard reload of the interface.</span>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- SECTION 10: ICONS DICTIONARY -->
            <div class="card bg-transparent border-secondary mb-4">
              <div class="card-header bg-dark border-secondary">
                <h5 class="mb-0 text-white"><i class="bi bi-info-circle-fill text-primary me-2"></i> 10. Core Icon Dictionary</h5>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-play-fill"></i></div>
                    <span class="text-secondary"><strong>Play / Pause:</strong> Tap to toggle audio playback state.</span>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-skip-start-fill"></i></div>
                    <span class="text-secondary"><strong>Prev / Next:</strong> Skip tracks, or hold to scrub the timeline.</span>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-shuffle"></i></div>
                    <span class="text-secondary"><strong>Shuffle:</strong> Randomize the play queue securely.</span>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-repeat"></i></div>
                    <span class="text-secondary"><strong>Repeat:</strong> Cycle (Off → Repeat All → Repeat One).</span>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-three-dots-vertical"></i></div>
                    <span class="text-secondary"><strong>Context Menu:</strong> Access sharing, metadata, and playlist tools.</span>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                    <div class="p-2 bg-dark rounded text-white fs-5" style="min-width: 45px; text-align: center;"><i class="bi bi-heart-fill"></i></div>
                    <span class="text-secondary"><strong>Favorite:</strong> Pin a song globally to your profile collection.</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- SECTION 11: LEGAL -->
            <div class="card bg-transparent border-danger">
              <div class="card-header bg-dark border-danger">
                <h5 class="mb-0 text-danger"><i class="bi bi-shield-exclamation text-danger me-2"></i> 11. Disclaimers & Fair Use</h5>
              </div>
              <div class="card-body">
                <p class="text-secondary mb-2"><strong>Copyright Responsibility:</strong> Users are solely responsible for the audio files they upload. Ensure you have the explicit right, license, or explicit permission from the original artist to use, stream, and distribute the content on this instance.</p>
                <p class="text-secondary mb-2"><strong>Personal Use Only:</strong> Advanced scraping utilities, including the Playlist Downloader, Open API, and high-quality streaming engines, are intended strictly for personal, private, and entirely non-commercial listening curation.</p>
                <p class="text-secondary mb-0"><strong>Content Moderation:</strong> Instance administrators reserve the unconditional right to instantly terminate sessions, indefinitely ban accounts, remove files, or delete altered metadata that violates copyright laws or general community guidelines without any prior notice.</p>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="shortcuts-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0 pb-2" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-keyboard-fill text-info me-2"></i>Detailed Keyboard Shortcuts</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-light">
            <p class="text-secondary mb-4 small">Master the music player instantly with these advanced, hands-free keyboard commands.</p>
            
            <div class="d-flex flex-column gap-3">
              
              <h5 class="text-white mt-2 mb-2 fw-bold" style="border-bottom: 2px solid #444; padding-bottom: 8px;">Playback Controls</h5>
              
              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="70" height="36" viewBox="0 0 70 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="70" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="12" font-weight="bold">SPACE</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Play / Pause</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The Spacebar serves as the universal standard command for controlling media playback across nearly every modern software application and streaming platform. By pressing the Spacebar while a track is loaded into the PHP Music player, you can instantly toggle the current playback state between active playing and paused. This operates flawlessly regardless of which tab, menu, or view you are currently browsing, provided you are not actively typing inside a text input field, search bar, or comment box. Using this critical shortcut drastically reduces your reliance on moving the mouse to the bottom player bar, offering a seamless, hands-free listening experience. Whether you need to abruptly pause the audio to answer a sudden phone call, speak to someone in the room, or quickly resume the beat once you are ready, the Spacebar delivers instantaneous zero-latency response directly to the internal Web Audio API engine. It is arguably the most essential and frequently used keyboard command for managing your continuous stream of music.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M14 12L22 18L14 24V12Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Next Track</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the Right Arrow key triggers an immediate skip to the next track in your active playing queue. This powerful shortcut allows you to rapidly cycle through playlists, albums, or randomly shuffled recommendations without ever needing to physically click the 'Next' button on the user interface. It evaluates your current repeat and shuffle settings intelligently; if you have Repeat All enabled, it will seamlessly loop back to the beginning of the queue once it hits the end. By keeping your hands on the keyboard, you can effortlessly browse and curate your listening session, bypassing tracks that do not fit your current mood with a single keystroke. This functionality is heavily optimized for speed, instantly dumping the current audio buffer and requesting the subsequent stream from the server, ensuring minimal delay between track transitions. It is an indispensable tool for power users who want to aggressively filter through massive discovery mixes.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M22 12L14 18L22 24V12Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Previous Track</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The Left Arrow key is your dedicated command for navigating backward through your listening session. Its behavior is contextually aware of the current playback position. If the currently playing track has progressed beyond the first three seconds, pressing this key will instantly rewind the audio back to the absolute beginning of the song, allowing you to restart your favorite verses effortlessly. However, if the track is still within its opening three seconds, pressing the Left Arrow key will physically pull the previous song from the queue history and begin playing it immediately. This dual-function logic mirrors industry-standard professional audio players, giving you precise chronological control over your playlists. It is exceptionally useful when you accidentally skip a song you wanted to hear, or when a track transitions and you realize you weren't finished vibing with the prior masterpiece. The transition occurs instantly via the background audio engine.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="12" font-weight="bold">SHIFT</text></svg>
                    <span class="text-secondary fw-bold">+</span>
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M14 12L22 18L14 24V12Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Seek Forward 10s</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">By holding the Shift key and pressing the Right Arrow, you initiate a precise chronological seek forward within the currently playing audio track. Specifically, this combination jumps the playback head exactly ten seconds into the future. This is a highly technical tool designed for bypassing long, drawn-out instrumental intros, skipping over podcast-style dialogue segments in mixes, or fast-forwarding to your absolute favorite chorus or drop in a song. Because it interacts directly with the HTML5 Media Element API, the time-shift is executed with frame-perfect accuracy and zero graphical stuttering. You can rapidly tap this key combination multiple times in succession to jump 20, 30, or 40 seconds ahead in a matter of milliseconds. This shortcut completely removes the friction of attempting to click a tiny position on the visual progress bar, granting you authoritative control over the exact timestamp of your listening experience.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="12" font-weight="bold">SHIFT</text></svg>
                    <span class="text-secondary fw-bold">+</span>
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M22 12L14 18L22 24V12Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Seek Backward 10s</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Engaging the Shift key while pressing the Left Arrow instantly rewinds the active audio stream by exactly ten seconds. This is the ultimate tool for lyrical comprehension and musical appreciation. If an artist delivers a complex, rapid-fire rap verse, or if a guitarist executes a mesmerizing, lightning-fast solo that you couldn't quite process on the first listen, this shortcut allows you to instantly pull the track back to analyze it again. It provides a massive quality-of-life improvement over manually dragging the progress bar with your cursor, which is often inaccurate and frustrating. The internal engine dynamically recalculates the buffer position to ensure that the audio resumes smoothly without popping or artifacting. You can reliably spam this shortcut to scrub backwards through minutes of audio in seconds, giving you unparalleled precision to catch every single hidden detail, background vocal, or subtle instrumental layer buried in the mix.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="50" height="36" viewBox="0 0 50 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="50" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="14" font-weight="bold">1-9</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Jump 10% - 90%</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The number keys across the top of your keyboard (1 through 9) act as absolute percentage markers for the currently playing track. Pressing '1' instantly teleports the playback head to the 10% mark of the song's total duration, '5' jumps exactly to the halfway point (50%), and '9' skips straight to the final 10% of the track. This feature is heavily inspired by professional video and audio editing timelines, providing a mathematically perfect way to scrub through a file. If you are listening to a massive, hour-long DJ mix or a lengthy ambient compilation, using these number keys allows you to slice through the file in massive chunks to find the exact transition or track you are looking for. It mathematically calculates the total duration metadata and applies the percentage instantly. This is a highly advanced navigation method that significantly elevates your ability to browse long-form audio without touching the mouse.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">0</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Restart Song</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the '0' key acts as a hard reset for the current audio stream. Regardless of whether you are two seconds into the track or three milliseconds away from the final fade-out, striking this key instantly snaps the playback head back to the 0:00 timestamp. This is incredibly satisfying when you want to experience the atmospheric build-up of a song's intro all over again, or if you were momentarily distracted and want to give the track the undivided attention it deserves from the very first beat. It completely bypasses the nuanced logic of the Left Arrow key, functioning purely as an absolute zeroing mechanism. The underlying Web Audio context handles the buffer reset cleanly, ensuring that any active equalizers, spatial audio filters, or volume normalizations remain perfectly intact while the song begins its playback cycle anew. It is the absolute fastest way to start your listening experience fresh.</p>
              </div>

              <!-- Audio & Modes -->
              <h5 class="text-white mt-4 mb-2 fw-bold" style="border-bottom: 2px solid #444; padding-bottom: 8px;">Audio & Modes</h5>
              
              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M18 12L12 18H24L18 12Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Volume Up</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The Up Arrow key serves as your primary hardware interface for increasing the master audio output of the PHP Music platform. Every single press of this key digitally increments the internal volume multiplier by exactly five percent (0.05). This grants you granular, step-by-step control over your listening levels without needing to squint at the tiny volume slider in the bottom corner of the interface. If a specific track was mastered too quietly, you can rapidly tap the Up Arrow to compensate in real-time. Because the volume logic is routed through a specialized Gain Node within the Web Audio API context, the increase is applied smoothly, preventing harsh digital clipping or sudden audio spikes that could damage your hearing or speaker equipment. It synchronizes visually with the on-screen volume slider, ensuring that the graphical interface accurately reflects the physical adjustments you are making through your keyboard.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><path d="M18 24L12 18H24L18 24Z" fill="#ffffff"/></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Volume Down</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the Down Arrow key systematically decreases the application's master volume by five percent (0.05) per keystroke. This is an absolutely vital shortcut for instantly managing sudden, overwhelmingly loud audio mastering differences between tracks, especially when listening through sensitive studio headphones. Instead of frantically searching for your mouse to drag a slider, you can swiftly tap this key to bring the audio down to a comfortable, ambient background level. The underlying math ensures that the volume scales linearly down to absolute zero, and it perfectly updates the graphical UI slider in the player bar to reflect the new state. If you need to quickly lower the volume to hear a notification from another application, converse with a coworker, or just reduce ear fatigue during a long listening session, the Down Arrow provides an immediate, reliable, and ergonomically superior solution compared to manual cursor adjustments.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">M</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Mute / Unmute</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'M' key acts as a digital kill switch for the master audio output. Striking this key instantly mutes all sound emanating from the player without actually pausing the track's progression. Pressing it again will perfectly restore the volume to the exact level it was at before you muted it. This is a highly strategic shortcut for situations where you need absolute silence immediately—such as answering an unexpected phone call, joining a virtual meeting, or watching a video in another tab—but you don't necessarily want to interrupt the flow of a live, synchronized listening session or a live radio queue. The graphical user interface responds in real-time, changing the speaker icon in the bottom right corner to visually confirm the muted state. The memory retention system ensures that your carefully calibrated volume preferences are never lost when toggling this essential hardware shortcut.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">S</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Toggle Shuffle</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'S' key is a powerful algorithmic trigger that instantly toggles the randomized shuffle state of your active playing queue. When activated, the system takes your currently loaded playlist, album, or history view and applies a cryptographic Fisher-Yates shuffle algorithm to completely randomize the upcoming track order. This mathematically guarantees that every single song will be played exactly once before the queue repeats, completely eliminating the repetitive boredom of listening to a playlist in the exact same chronological order every day. Pressing the 'S' key again disables the algorithm, instantly snapping the remaining unplayed tracks back into their original, sequential database order. A visual highlight on the shuffle icon in the main player bar confirms your selection. This hands-free shortcut is the ultimate tool for injecting spontaneity and unpredictability into your daily listening habits without disrupting your workflow.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">R</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Toggle Repeat Mode</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the 'R' key allows you to cycle seamlessly through the three fundamental repeat modes of the audio engine: Repeat Off, Repeat All, and Repeat One. The first press locks the queue into a continuous loop, ensuring that once your playlist or album reaches the final track, it will instantly wrap around and start from the beginning without any dead silence. Pressing the key a second time activates the 'Repeat One' protocol, which traps the currently playing song in an infinite loop—perfect for when you become completely obsessed with a new track and need to hear it dozens of times in a row to dissect the lyrics and production. A third press disengages the system, allowing playback to stop naturally at the end of the queue. The visual icon dynamically updates to reflect your current mode, giving you total authoritative control over the continuous flow of your music.</p>
              </div>

              <!-- Interface & Actions -->
              <h5 class="text-white mt-4 mb-2 fw-bold" style="border-bottom: 2px solid #444; padding-bottom: 8px;">Interface & Actions</h5>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">F</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Toggle Fullscreen Player</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'F' key is your gateway to an incredibly immersive, distraction-free visual experience. Striking this key instantly sends a request to the browser's Fullscreen API, stripping away all browser tabs, URL bars, and operating system taskbars to dedicate your entire monitor exclusively to the PHP Music interface. This is specifically designed for users who want to leave the application running on a secondary monitor, a living room television, or a party display. When combined with the dynamic blurred background generation and the audio-reactive visualizer canvas, the fullscreen mode transforms your screen into a mesmerizing, professional-grade media centerpiece. Pressing the 'F' key a second time (or hitting the Escape key) gracefully collapses the view back into standard windowed mode. It completely redefines the aesthetic atmosphere of your listening environment with a single, effortless keystroke.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">P</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Picture-in-Picture (PiP)</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the 'P' key activates one of the most advanced technical features of the entire platform: the Document Picture-in-Picture (PiP) mode. This command detaches the core playback interface, including the high-resolution album art, synchronized lyrics, and media controls, into a compact, floating window that hovers persistently above all other applications on your computer. This means you can actively read along with the lyrics or skip tracks while browsing completely different websites, writing documents, or playing video games. The PiP window operates in its own isolated DOM context, maintaining a flawless, real-time connection to the main audio engine without consuming massive amounts of system memory. If your browser does not support Document PiP, the system intelligently falls back to standard Video PiP, rendering the album art and a dynamic audio visualizer onto a canvas element. It is the ultimate multitasking shortcut for power users.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">L</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Show Lyrics</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'L' key is a dedicated shortcut that instantly summons the synchronized lyrics modal over your current view. If the currently playing track contains deeply embedded LRC timestamp metadata, this interface will automatically display the lyrics scrolling in perfect, real-time synchronization with the artist's vocals. The active line will be beautifully highlighted and magnified, allowing you to easily read along or host impromptu karaoke sessions. Even if the track only contains standard, unsynchronized text lyrics, this shortcut provides immediate access to the full textual composition, completely bypassing the need to navigate through the context menu with your mouse. The modal heavily utilizes the dynamic blurred background system, creating a stunning visual contrast that makes the text highly readable. It is an essential tool for music enthusiasts who want to deeply understand the poetry and storytelling behind their favorite compositions at a moment's notice.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">C</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Open Comments</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">By pressing the 'C' key, you instantly open the dynamic community interaction modal for the currently playing track. This specialized shortcut bridges the gap between solitary listening and global social engagement. Inside the modal, you can read the thoughts, analyses, and reactions of other users across the server, reply directly to their insights, or drop your own hot take on the production quality of the song. You can also view the aggregate Like and Dislike statistics to gauge the track's popularity. By assigning this function to a single keystroke, the application encourages spontaneous community interaction; if a specific beat drop or lyrical punchline amazes you, you can hit 'C', type your reaction, and return to your work in a matter of seconds. It transforms the music player from a passive audio stream into an active, collaborative social experience.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">I</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">View Metadata (Info)</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'I' (Information) key is designed specifically for audiophiles, data hoarders, and library curators. Pressing this shortcut instantly generates a clean, readable overlay detailing the absolute core metadata of the currently playing track. You will be presented with the exact Title, Artist, Album, and Genre tags, alongside the release Year, the mathematically calculated Duration, and the precise Bitrate (e.g., 320 kbps) of the physical audio file. This is incredibly useful when you are listening to a massive, auto-generated discovery mix and suddenly encounter a track with pristine audio fidelity or an obscure genre classification that you want to investigate further. It completely removes the guesswork from understanding exactly what format and quality of audio your browser is currently decoding. For users who obsess over their library's organization and file integrity, this shortcut provides instant, transparent verification of the backend database.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">E</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Per-Song Audio Settings</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the 'E' key launches the advanced Per-Song Audio Settings panel, which is an absolute game-changer for users with highly diverse music libraries. If you encounter a track that was mastered in the 1980s and sounds incredibly quiet, or an underground rap song with overpowering, muddy bass frequencies, you can use this shortcut to fix it permanently. The interface allows you to define a specific volume multiplier and a custom 5-band Equalizer curve that applies exclusively to that exact song. The database permanently memorizes these adjustments, ensuring that every time this specific track plays in the future—whether in a playlist or on shuffle—the custom audio filters will automatically engage to correct the mastering flaws. It is an unparalleled quality-of-life feature that prevents you from constantly adjusting your master volume or global EQ when transitioning between drastically different eras and genres of music.</p>
              </div>

              <!-- Library Management -->
              <h5 class="text-white mt-4 mb-2 fw-bold" style="border-bottom: 2px solid #444; padding-bottom: 8px;">Library Management</h5>
              
              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">H</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Toggle Favorite (Heart)</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'H' (Heart) key is the fastest and most ergonomic way to curate your personal music library. Whenever a song resonates with you, simply strike the 'H' key to instantly pin it to your global Favorites collection. The system communicates directly with the SQLite database via an asynchronous background API request, permanently saving the association without interrupting the audio playback or forcing the page to reload. A visual toast notification will appear confirming the addition, and the heart icon on the interface will illuminate. If you press 'H' while listening to a song that is already in your Favorites, it will intelligently remove it from the list. This hands-free bookmarking system is essential for rapidly building an elite collection of top-tier tracks while passively listening to complex "For You" algorithmic recommendations or expansive radio mixes.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">O</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Make Offline / Re-cache</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Pressing the 'O' key triggers the platform's incredibly sophisticated Progressive Web App (PWA) caching engine. When activated, the browser forcefully downloads the physical MP3/FLAC audio stream, the high-resolution cover art, and the core JSON metadata of the currently playing track, securely encrypting and storing them directly into your device's internal hard drive via the Cache Storage API. Once cached, the song will play instantaneously, with zero buffering, even if you completely disconnect from the internet or board a flight. If the song is already cached but the file has become corrupted due to your operating system aggressively clearing space, pressing 'O' will force the engine to securely re-download and repair the cache chunk. This shortcut gives you absolute, authoritative control over your bandwidth consumption and guarantees that your most critical tracks are fundamentally immune to network outages or server downtime.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">B</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Toggle Listen Later (Bookmark)</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'B' (Bookmark) key is a highly strategic organizational tool designed for heavy music discoverers. When you are listening to a new album or a shared community playlist and you hear a track that sounds promising but you don't have the time to fully appreciate it right now, pressing 'B' instantly sends it to your "Listen Later" queue. This acts as a temporary holding zone—a purgatory for tracks that require a second, more focused listening session before you decide whether to permanently Favorite them or discard them. It prevents your primary Favorites list from becoming cluttered with songs you are unsure about. The background API handles the insertion seamlessly, allowing you to rapidly tag dozens of tracks during a fast-paced browsing session without breaking your focus or navigating away from your current view.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">D</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Download MP3 File</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">By pressing the 'D' key, you bypass the internal browser player and directly request the raw, physical audio file from the backend server. The system immediately initiates a forced file download, transferring the pristine MP3, FLAC, or M4A file straight into your operating system's native 'Downloads' folder. The backend dynamically intercepts the request and renames the file using the clean, properly formatted "Title - Artist" metadata, ensuring your local hard drive remains perfectly organized instead of being cluttered with random alphanumeric database hashes. This shortcut is heavily utilized by DJs, local hoarders, and users who want to transfer their music to legacy hardware devices like iPods or USB drives for car stereos. It is the ultimate bridge between the cloud-based streaming architecture and pure, localized file ownership.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">U</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Copy Share Link</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The 'U' key is a lightning-fast social utility that instantly generates a cryptographic URL hook for the currently playing track and copies it directly to your operating system's clipboard. This completely bypasses the need to open the share modal, click the copy button, and close the interface. As soon as you hit 'U', you can immediately paste the link into Discord, WhatsApp, Twitter, or an email to share the exact song with your friends. The link contains specialized routing parameters that will automatically boot up the PHP Music platform, query the database, and load the specific track on the recipient's screen. It is an incredibly efficient workflow optimization for users who heavily interact in communities, allowing you to instantly broadcast the music you are currently enjoying with absolute minimal physical effort.</p>
              </div>

              <!-- System Actions -->
              <h5 class="text-white mt-4 mb-2 fw-bold" style="border-bottom: 2px solid #444; padding-bottom: 8px;">System Actions</h5>
              
              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="12" font-weight="bold">SHIFT</text></svg>
                    <span class="text-secondary fw-bold">+</span>
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">S</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Focus Search Bar</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Engaging the Shift key and pressing 'S' immediately teleports your cursor directly into the master search bar, regardless of how far down the page you have scrolled or which menu you are currently viewing. It automatically summons your keyboard focus, meaning you can begin typing your search query the exact millisecond you execute the shortcut. This completely eliminates the tedious necessity of grabbing your mouse, scrolling all the way back to the top of the interface, and physically clicking inside the input box. Because the search engine features ultra-fast, live-updating dropdown results, this shortcut allows you to dynamically pivot from listening to a track to searching for a completely different artist or album in a matter of seconds. It is a fundamental navigation tool for power users with massive libraries.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="12" font-weight="bold">SHIFT</text></svg>
                    <span class="text-secondary fw-bold">+</span>
                    <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="36" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="16" font-weight="bold">C</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Clear Listening History</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">Holding Shift and pressing 'C' serves as a rapid execution trigger for wiping your personalized listening history. If you are currently inside the dedicated "Playback History" tab, this shortcut instantly simulates a click on the "Clear History" button. You will be prompted with a secure confirmation dialog to ensure you don't accidentally execute the wipe. Once confirmed, the system communicates with the SQLite database to permanently incinerate your chronological playback logs and wipe your analytical play count arrays, effectively resetting your algorithmic recommendations back to zero. This is exceptionally useful if you accidentally left the player running overnight on a genre you dislike, or if you simply want to aggressively purge your profile statistics to rebuild your "For You" algorithms from scratch with entirely new musical tastes.</p>
              </div>

              <div class="d-flex flex-column p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="60" height="36" rx="6" fill="#282828" stroke="#555555" stroke-width="2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="monospace" font-size="14" font-weight="bold">ESC</text></svg>
                  </div>
                  <span class="fw-bold text-white fs-5">Close Modals / Context Menu</span>
                </div>
                <p class="text-secondary mb-0" style="font-size: 0.9rem; line-height: 1.6;">The Escape key serves as the ultimate, universal panic button and interface reset tool. Striking this key instantly obliterates any active overlay, context menu, modal window, or full-screen view currently obstructing the main application interface. Whether you are deeply buried inside the metadata editor, browsing the synchronized lyrics panel, reading community comments, managing a collaborative playlist, or trapped in the immersive Fullscreen visualizer, hitting Escape guarantees an immediate return to the core browsing experience. It forcefully unbinds focus states, hides translucent backdrops, and restores scrolling capabilities to the main document body. This hardware standard provides a psychological safety net, ensuring that you can rapidly back out of complex menus without needing to hunt down tiny, microscopic "X" buttons with your mouse cursor.</p>
              </div>

            </div>

          </div>
        </div>
      </div>
    </div>

    <div id="multi-select-bar" class="d-none shadow-lg dropup">
      <div class="d-flex align-items-center gap-2">
        <span id="multi-select-count" class="badge bg-danger rounded-pill fs-6 px-3 py-2 me-1 shadow-sm">0</span>
        <button class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center border-0" id="multi-cancel-btn" title="Cancel" style="width: 44px; height: 44px; background: rgba(255,255,255,0.1);"><i class="bi bi-x-lg fs-5"></i></button>
        <button class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center border-0" id="multi-select-all-btn" title="Select All Loaded" style="width: 44px; height: 44px; background: rgba(255,255,255,0.1);"><i class="bi bi-check-all fs-4"></i></button>
        
        <div class="vr bg-secondary opacity-50 mx-1" style="width: 2px; min-height: 30px;"></div>
        
        <button class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions" style="width: 44px; height: 44px; background: rgba(255,255,255,0.1);">
          <i class="bi bi-three-dots-vertical fs-5"></i>
        </button>
        
        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg mb-2" style="background-color: var(--ytm-surface-2); border: 1px solid #404040; border-radius: 12px; overflow: hidden;">
          <li><button class="dropdown-item d-flex align-items-center gap-3 py-2 text-white" id="multi-add-playlist-btn"><i class="bi bi-music-note-list fs-5 text-secondary"></i> Add to Playlist</button></li>
          <li><button class="dropdown-item d-flex align-items-center gap-3 py-2 text-white" id="multi-add-favorite-btn"><i class="bi bi-heart-fill fs-5 text-danger"></i> Add to Favorites</button></li>
          <li><button class="dropdown-item d-flex align-items-center gap-3 py-2 text-white" id="multi-offline-btn"><i class="bi bi-cloud-arrow-down-fill fs-5 text-info"></i> Re-cache / Offline</button></li>
          <li><hr class="dropdown-divider border-secondary opacity-50 my-1"></li>
          <li><button class="dropdown-item d-flex align-items-center gap-3 py-2 text-danger" id="multi-remove-btn"><i class="bi bi-trash-fill fs-5"></i> Remove</button></li>
        </ul>
      </div>
    </div>
    <div class="player-bar d-none" id="player-bar">
      <div class="track-info d-none d-md-flex">
        <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Album Art" class="track-info-art" id="player-art-desktop">
        <div class="track-info-text">
          <div class="title" id="player-title-desktop">Song Title</div>
          <div class="artist" id="player-artist-desktop">Artist Name</div>
        </div>
      </div>
      <div class="player-controls">
        <div class="track-info d-md-none">
          <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Album Art" class="track-info-art" id="player-art-mobile">
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
          <div class="dynamic-blur-bg" id="mobile-player-bg"></div>
          
          <div class="modal-header border-0 pb-0 d-flex justify-content-between align-items-center">
            <button type="button" class="btn player-btn text-white" data-bs-dismiss="modal"><i class="bi bi-chevron-down fs-2"></i></button>
            <ul class="nav nav-tabs border-0 d-flex align-items-center justify-content-center m-0" id="mp-tabs" role="tablist">
              <li class="nav-item"><button class="nav-link active px-3 py-2" data-bs-toggle="tab" data-bs-target="#mp-player-pane">Player</button></li>
              <li class="nav-item"><button class="nav-link px-3 py-2" data-bs-toggle="tab" data-bs-target="#mp-queue-pane">Up Next</button></li>
            </ul>
            <button type="button" class="btn player-btn text-white" id="player-modal-more-btn" title="More"><i class="bi bi-three-dots-vertical fs-3"></i></button>
          </div>
          
          <!-- Notice: Removed .player-modal-body and .d-flex here to fix the empty space bug -->
          <div class="modal-body p-0 tab-content flex-grow-1 overflow-hidden d-block">
            
            <!-- PLAYER TAB -->
            <div class="tab-pane fade show active h-100" id="mp-player-pane">
              <!-- Wrapped the flexbox INSIDE the tab -->
              <div class="h-100 w-100 overflow-auto p-4 d-flex flex-column justify-content-evenly">
                
                <div class="d-flex flex-column justify-content-center align-items-center w-100" style="flex-grow: 1; margin-bottom: 2rem; min-height: 0;">
                  <div class="position-relative shadow-lg" style="width: 100%; max-width: 400px; max-height: 45vh; aspect-ratio: 1/1; border-radius: 12px; overflow: hidden; margin: 0 auto;">
                    <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" id="player-modal-art" alt="Album Art" style="width: 100%; height: 100%; object-fit: cover;">
                    <canvas class="visualizer-canvas" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; pointer-events: none; z-index: 5;"></canvas>
                  </div>
                </div>
                
                <div class="text-start mb-3">
                  <h3 id="player-modal-title" class="title text-truncate fw-bold mb-1">Song Title</h3>
                  <p id="player-modal-artist" class="artist text-truncate text-secondary mb-0">Artist Name</p>
                </div>
                
                <div class="mb-3 w-100">
                  <div class="progress-bar-container" id="player-modal-progress-container">
                    <div class="progress-bar-bg"></div><div class="progress-bar-fg" id="player-modal-progress-bar"></div>
                  </div>
                  <div class="d-flex justify-content-between small text-secondary mt-2">
                    <span id="player-modal-current-time">0:00</span><span id="player-modal-time-left">0:00</span>
                  </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center w-100 mx-auto" style="max-width: 400px;">
                  <button class="player-btn fs-2" id="player-modal-shuffle-btn"><i class="bi bi-shuffle"></i></button>
                  <button class="player-btn text-white fs-1" id="player-modal-prev-btn"><i class="bi bi-skip-start-fill"></i></button>
                  <button class="player-btn play-btn bg-white text-dark rounded-circle d-flex align-items-center justify-content-center" id="player-modal-play-pause-btn" style="width: 70px; height: 70px;"><i class="bi bi-play-fill fs-1"></i></button>
                  <button class="player-btn text-white fs-1" id="player-modal-next-btn"><i class="bi bi-skip-end-fill"></i></button>
                  <button class="player-btn fs-2" id="player-modal-repeat-btn"><i class="bi bi-repeat"></i></button>
                </div>
                
              </div>
            </div>

            <!-- UP NEXT TAB (No longer squished to the bottom!) -->
            <div class="tab-pane fade h-100 overflow-auto" id="mp-queue-pane">
               <div id="mobile-player-queue-list" class="p-2"></div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="desktop-player-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content player-modal-content">
          <div class="dynamic-blur-bg" id="desktop-player-bg"></div>
          <div class="modal-header player-modal-header py-0 px-4 border-0">
            <button type="button" class="btn player-btn text-white" data-bs-dismiss="modal" aria-label="Close">
              <i class="bi bi-chevron-down fs-2"></i>
            </button>
            <button type="button" class="btn player-btn text-white" id="desktop-player-modal-more-btn" title="More">
              <i class="bi bi-three-dots-vertical fs-3"></i>
            </button>
          </div>
          <div class="modal-body d-flex h-100 overflow-hidden pt-1 gap-4 align-items-center">
            
            <div class="w-50 d-flex flex-column align-items-center justify-content-center h-100 px-4" style="min-width: 0;">
              <div class="position-relative shadow-lg mx-auto" style="width: 100%; max-width: 50vh; aspect-ratio: 1/1; border-radius: 12px; overflow: hidden; flex-shrink: 1;">
                <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" id="desktop-player-modal-art" style="width: 100%; height: 100%; object-fit: cover; background-color: var(--ytm-surface-2);">
                <canvas class="visualizer-canvas" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; pointer-events: none; z-index: 5;"></canvas>
              </div>
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
              
              <div class="tab-content flex-grow-1 overflow-hidden d-flex flex-column mb-4 rounded" style="background-color: rgba(18, 18, 18, 0.4); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.05);" id="dp-tabs-content">
                
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

    <div class="modal fade" id="connections-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-secondary">
            <h5 class="modal-title text-white" id="connections-modal-title">Connections</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <div class="list-group list-group-flush bg-transparent" id="connections-list"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="comments-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background: rgba(30, 30, 30, 0.95); backdrop-filter: blur(10px); border: 1px solid #444;">
          <div class="modal-header border-secondary">
            <h5 class="modal-title w-100 text-white">Song Community</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <select id="comments-sort-select" class="form-select form-select-sm w-auto bg-dark text-white border-secondary">
                <option value="newest">Newest</option>
                <option value="oldest">Oldest</option>
                <option value="most_liked">Most Liked</option>
                <option value="most_replied">Most Replied</option>
              </select>
            </div>
            <div class="d-flex justify-content-center gap-4 mb-4">
              <button class="btn btn-outline-light d-flex align-items-center gap-2" id="song-like-btn">
                <i class="bi bi-hand-thumbs-up"></i> <span id="song-like-count">0</span>
              </button>
              <button class="btn btn-outline-light d-flex align-items-center gap-2" id="song-dislike-btn">
                <i class="bi bi-hand-thumbs-down"></i> <span id="song-dislike-count">0</span>
              </button>
            </div>
            <form id="comment-form" class="mb-2 d-flex gap-2">
              <input type="hidden" id="comment-parent-id" value="">
              <input type="text" id="comment-input" class="form-control bg-dark text-white border-secondary" placeholder="Add a comment... (use @ to mention)" maxlength="2000" required>
              <button type="submit" class="btn btn-danger"><i class="bi bi-send"></i></button>
            </form>
            <div class="d-flex justify-content-end mb-4">
              <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
            </div>
            <div id="comments-list" class="d-flex flex-column gap-3"></div>
            <div class="text-center mt-4 mb-2 d-none" id="load-more-comments-container">
              <button class="btn btn-outline-light btn-sm px-4 rounded-pill" id="load-more-comments-btn">Load More Comments</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="view-note-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-secondary pb-3">
            <h5 class="modal-title text-white fw-bold fs-4" id="view-note-title"></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-white p-4 d-flex flex-column">
            <div style="white-space: pre-wrap; font-size: 1.1rem; line-height: 1.7; flex-grow: 1;" id="view-note-content"></div>
            <div class="mt-4 pt-3 border-top border-secondary text-secondary small d-flex align-items-center gap-2" id="view-note-date">
              <!-- Date injected here -->
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="bbcode-info-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0 pb-2" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-info-circle text-info me-2"></i> Formatting Help</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-light">
            <p class="text-secondary small mb-3">You can use special formatting in your text!</p>
            <ul class="list-group list-group-flush rounded">
              <li class="list-group-item bg-dark text-white border-secondary">
                <strong>Auto Links:</strong><br>
                <span class="text-secondary small">URLs like <code>phpmusic.rf.gd</code> or <code>https://example.com</code> will automatically become clickable links.</span>
              </li>
              <li class="list-group-item bg-dark text-white border-secondary">
                <strong>Manual Links:</strong><br>
                <span class="text-secondary small">Use <code>[url] yourlink.com [/url]</code> to explicitly create a clickable link.</span>
              </li>
              <li class="list-group-item bg-dark text-white border-secondary">
                <strong>Mentions:</strong><br>
                <span class="text-secondary small">Use <code>@Username</code> (without spaces) to link directly to a user's profile.</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="calendar-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-secondary pb-2">
            <h5 class="modal-title text-white"><i class="bi bi-calendar3 text-info me-2"></i>Calendar & Time</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center p-4">
            <h2 id="calendar-time-display" class="fw-bold text-white mb-1" style="font-size: clamp(2rem, 8vw, 3.5rem); font-family: monospace; letter-spacing: 2px;">00:00:00</h2>
            <p id="calendar-date-display" class="text-secondary fs-5 mb-4"></p>
            <div id="calendar-grid" class="bg-dark rounded p-3 border border-secondary shadow-sm">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <button class="btn btn-sm btn-outline-light" id="cal-prev-month"><i class="bi bi-chevron-left"></i></button>
                <h5 id="cal-month-year" class="mb-0 text-white fw-bold"></h5>
                <button class="btn btn-sm btn-outline-light" id="cal-next-month"><i class="bi bi-chevron-right"></i></button>
              </div>
              <div class="d-grid" style="grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center;">
                <div class="text-danger small fw-bold">Su</div>
                <div class="text-secondary small fw-bold">Mo</div>
                <div class="text-secondary small fw-bold">Tu</div>
                <div class="text-secondary small fw-bold">We</div>
                <div class="text-secondary small fw-bold">Th</div>
                <div class="text-secondary small fw-bold">Fr</div>
                <div class="text-primary small fw-bold">Sa</div>
              </div>
              <div id="cal-days-grid" class="d-grid mt-2" style="grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; min-height: 200px; align-items: start;">
              </div>
            </div>
            <div class="d-flex gap-2 mt-3">
              <input type="date" id="cal-jump-date" class="form-control form-control-sm bg-dark text-white border-secondary" title="Jump to Date">
              <button class="btn btn-sm btn-outline-info fw-bold w-100" id="cal-today-btn">Jump to Today</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="note-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title text-white">Edit Note</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="note-form">
              <input type="hidden" id="note-id">
              <input type="text" id="note-title" class="form-control bg-dark text-white border-secondary mb-3" placeholder="Title" required>
              <textarea id="note-content" class="form-control bg-dark text-white border-secondary mb-2" rows="6" placeholder="Write your note here..." maxlength="25000" required></textarea>
              <div class="d-flex justify-content-end mb-3">
                <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Note</button>
            </form>
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

    <div class="modal fade" id="edit-comment-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title text-white">Edit Comment</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-comment-form">
              <input type="hidden" id="edit-comment-id">
              <input type="text" id="edit-comment-input" class="form-control bg-dark text-white border-secondary mb-2" maxlength="2000" required>
              <div class="d-flex justify-content-end mb-3">
                <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Changes</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="edit-post-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title text-white">Edit Post</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-post-form">
              <input type="hidden" id="edit-post-id">
              <textarea id="edit-post-input" class="form-control bg-dark text-white border-secondary mb-2" rows="4" placeholder="Edit your post..." maxlength="2000" required></textarea>
              <div class="d-flex justify-content-end mb-3">
                <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
              </div>
              <button type="submit" class="btn btn-info text-dark fw-bold w-100">Save Changes</button>
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
              <div style="max-width: 300px; margin: 0 auto;" class="mb-3">
                <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" id="profile-picture-preview" class="profile-picture-lg" style="width: 100%; display: block; max-width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 8px;" alt="Profile Picture Preview">
              </div>
              <div class="mb-3">
                <label for="profile-picture-input" class="form-label">Upload new picture</label>
                <input class="form-control" type="file" id="profile-picture-input" accept="image/png, image/jpeg, image/gif">
              </div>
              <button type="submit" class="btn btn-danger w-100" id="profile-picture-submit-btn">Save Picture</button>
              <div class="progress mt-3 d-none" id="profile-pic-progress-container" style="height: 15px;">
                <div id="profile-pic-progress" class="progress-bar progress-bar-striped progress-bar-animated bg-danger" role="progressbar" style="width: 0%;">0%</div>
              </div>
            </form>
            <hr class="text-secondary">
            <h6>Profile Background Picture</h6>
            <form id="profile-bg-form" class="mb-4">
              <div class="mb-3">
                <input class="form-control" type="file" id="profile-bg-input" accept="image/png, image/jpeg, image/gif, image/webp">
              </div>
              <button type="submit" class="btn btn-danger w-100" id="profile-bg-submit-btn">Save Background</button>
            </form>
            <hr class="text-secondary">
            <h6 class="mt-4">Profile Info</h6>
            <form id="bio-form" class="mb-4">
              <div class="mb-3">
                <label for="settings-bio" class="form-label">Bio</label>
                <textarea class="form-control" id="settings-bio" rows="3" placeholder="Tell us about yourself..."></textarea>
                <div class="d-flex justify-content-end mt-1">
                  <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
                </div>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Bio</button>
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
            <h6 class="mt-4"><i class="bi bi-sliders me-2"></i>Audio Enhancements</h6>
            <div class="mb-3">
              <label class="form-label d-flex justify-content-between">
                <span>Global Volume Multiplier</span>
                <span id="global-vol-val">1.0x</span>
              </label>
              <input type="range" class="form-range" id="global-vol-slider" min="0" max="3" step="0.1" value="1">
            </div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="toggle-normalization" checked>
              <label class="form-check-label" for="toggle-normalization">Volume Normalization (AGC)</label>
            </div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="toggle-eq">
              <label class="form-check-label" for="toggle-eq">Enable Equalizer</label>
            </div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="toggle-spatial">
              <label class="form-check-label" for="toggle-spatial">Enable Spatial Audio (3D HRTF)</label>
            </div>
            <div class="mb-3">
              <label class="form-label d-flex justify-content-between">
                <span>Crossfade Duration</span>
                <span id="crossfade-val">3.0s</span>
              </label>
              <input type="range" class="form-range" id="crossfade-slider" min="0" max="10" step="0.5" value="3.0">
            </div>
            <div id="eq-sliders" class="d-none mb-4">
               <div class="mb-3">
                 <select class="form-select form-select-sm" id="eq-preset-select">
                   <option value="Custom">Custom</option>
                   <option value="Flat">Flat</option>
                   <option value="Rock">Rock</option>
                   <option value="Jazz">Jazz</option>
                   <option value="Classical">Classical</option>
                   <option value="Pop">Pop</option>
                   <option value="Bass Boost">Bass Boost</option>
                 </select>
               </div>
               <div class="d-flex justify-content-between text-center small text-secondary">
                 <span>60Hz</span><span>230Hz</span><span>910Hz</span><span>3.6kHz</span><span>14kHz</span>
               </div>
               <div class="d-flex justify-content-between mt-2">
                 <input type="range" class="form-range eq-band" data-band="0" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range eq-band" data-band="1" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range eq-band" data-band="2" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range eq-band" data-band="3" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range eq-band" data-band="4" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
               </div>
            </div>
            <hr class="text-secondary">
            <h6 class="mt-4 text-danger">Data Management</h6>
            <button type="button" class="btn btn-outline-danger w-100 mb-2" id="btn-delete-keep">Delete Account but Keep Data</button>
            <button type="button" class="btn btn-danger w-100" id="btn-delete-all">Permanently Delete Account & Data</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="inbox-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040; height: 85vh;">
          <div class="modal-header border-secondary pb-2">
            <h5 class="modal-title text-white"><i class="bi bi-envelope-paper-heart-fill text-danger me-2"></i>Direct Messages</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0 position-relative">
            <div class="p-3 border-bottom border-secondary position-relative">
              <input type="text" id="inbox-search-input" class="form-control bg-dark text-white border-secondary" placeholder="Search Artist Name...">
              <div id="inbox-search-dropdown" class="search-dropdown d-none w-100" style="top: 100%; position: absolute; z-index: 2000; background-color: var(--ytm-surface-2); border: 1px solid #404040; border-radius: 0 0 8px 8px; max-height: 250px; overflow-y: auto; left: 0; width: calc(100% - 2rem) !important; margin: 0 1rem; box-shadow: 0 8px 24px rgba(0,0,0,0.8);"></div>
            </div>
            <div class="list-group list-group-flush bg-transparent" id="inbox-list"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="chat-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040; height: 80vh;">
          <div class="modal-header border-secondary pb-2">
            <h5 class="modal-title text-white fw-bold d-flex align-items-center gap-2" id="chat-modal-title"></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body d-flex flex-column gap-3 p-3 overflow-auto" id="chat-messages-list" style="background-color: #000;">
          </div>
          <div class="modal-footer border-secondary p-2">
            <form id="chat-form" class="w-100 d-flex gap-2">
              <input type="hidden" id="chat-target-id">
              <label class="btn btn-outline-secondary mb-0 d-flex align-items-center justify-content-center" style="cursor: pointer;">
                 <i class="bi bi-image"></i>
                 <input type="file" id="chat-image-input" accept="image/*" class="d-none">
              </label>
              <input type="text" id="chat-input" class="form-control bg-dark text-white border-secondary" placeholder="Type a message..." autocomplete="off">
              <button type="submit" class="btn btn-danger"><i class="bi bi-send"></i></button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="edit-message-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title text-white">Edit Message</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-message-form">
              <input type="hidden" id="edit-message-id">
              <input type="text" id="edit-message-input" class="form-control bg-dark text-white border-secondary mb-3" maxlength="2000" required>
              <button type="submit" class="btn btn-danger w-100">Save Changes</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="activity-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0 pb-2 d-flex justify-content-between align-items-center">
            <h5 class="modal-title mb-0"><i class="bi bi-activity text-danger me-2"></i>Activity Feed</h5>
            <div>
              <button class="btn btn-sm btn-outline-secondary me-3" id="clear-activity-btn">Clear All</button>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
          </div>
          <div class="modal-body p-0" id="activity-modal-body"></div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="request-verification-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0 pb-2" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-patch-check-fill text-info me-2"></i>Account Verification</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center p-4">
            <i class="bi bi-cloud-upload text-secondary mb-3" style="font-size: 3rem; display: block;"></i>
            <h5 class="text-white mb-3">Upload Permissions Required</h5>
            <p class="text-secondary mb-4">Please notify the admin to verify your account so you can upload your own songs and share them with the community.</p>
            <button class="btn btn-info w-100 fw-bold text-dark" id="send-verification-request-btn">Request Verification</button>
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
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="song-is-private">
              <label class="form-check-label text-white" for="song-is-private"><i class="bi bi-lock-fill text-warning"></i> Private Song</label>
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
    <div class="modal fade" id="collab-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Manage Collaborators</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="position-relative mb-3">
              <div class="input-group">
                <input type="text" id="collab-input" class="form-control" placeholder="Search Artist Name...">
                <button class="btn btn-danger" id="collab-add-btn">Add User</button>
              </div>
              <div id="collab-search-dropdown" class="search-dropdown d-none w-100" style="top: 100%; position: absolute; z-index: 2000; background-color: var(--ytm-surface-2); border: 1px solid #404040; border-radius: 0 0 8px 8px; max-height: 250px; overflow-y: auto;"></div>
            </div>
            <div class="mb-3 p-3 rounded" style="background-color: var(--ytm-surface-2); border: 1px solid #404040;">
              <label for="collab-expire-select" class="form-label text-white small mb-1">Invite Link Expiration</label>
              <select id="collab-expire-select" class="form-select form-select-sm bg-dark text-white border-secondary mb-2">
                <option value="1440">1 Day</option>
                <option value="10080">1 Week</option>
                <option value="43200">1 Month</option>
                <option value="forever">Forever</option>
                <option value="custom">Custom (Minutes)</option>
              </select>
              <input type="number" id="collab-custom-expire" class="form-control form-control-sm bg-dark text-white border-secondary d-none mb-2" placeholder="Enter minutes (e.g. 60)">
              <button class="btn btn-outline-light w-100" id="collab-copy-link-btn"><i class="bi bi-link-45deg"></i> Generate & Copy Link</button>
            </div>
            <h6 class="text-secondary mt-2">Current Collaborators</h6>
            <div id="collab-list" class="list-group list-group-flush bg-transparent"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="collab-invite-modal" tabindex="-1" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-secondary">
            <h5 class="modal-title text-white">Collaboration Invite</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center p-4">
            <i class="bi bi-envelope-paper-heart text-danger mb-3" style="font-size: 3rem; display: block;"></i>
            <h5 class="text-white mb-2">You've been invited!</h5>
            <p class="text-secondary mb-4">You have been invited to collaborate on the playlist <strong id="invite-playlist-name" class="text-white"></strong> by <strong id="invite-playlist-creator" class="text-white"></strong>.</p>
            <div class="d-flex gap-2 justify-content-center">
              <button class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" id="invite-reject-btn">Decline</button>
              <button class="btn btn-danger px-4" id="invite-accept-btn">Accept & Join</button>
            </div>
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
                <label for="playlist-name-input" class="form-label">Playlist Name</label>
                <input type="text" class="form-control" id="playlist-name-input" required>
              </div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="playlist-is-private">
                <label class="form-check-label text-white" for="playlist-is-private"><i class="bi bi-lock-fill text-warning"></i> Private Playlist</label>
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
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="edit-playlist-is-private">
                <label class="form-check-label text-white" for="edit-playlist-is-private"><i class="bi bi-lock-fill text-warning"></i> Private Playlist</label>
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
    <div class="modal fade" id="import-offline-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Import Offline Library</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="import-offline-form">
              <div class="mb-3">
                <label for="import-offline-file" class="form-label">Select JSON file</label>
                <input type="file" class="form-control" id="import-offline-file" accept="application/json,.json" required>
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
    <div class="modal fade" id="import-notes-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface);">
          <div class="modal-header border-0">
            <h5 class="modal-title text-white">Import Notes</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="import-notes-form">
              <div class="mb-3">
                <label for="import-notes-file" class="form-label text-white">Select JSON file</label>
                <input type="file" class="form-control bg-dark text-white border-secondary" id="import-notes-file" accept="application/json,.json" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Import Notes</button>
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
                <div style="max-width: 300px; margin: 0 auto;" class="mb-2">
                   <img id="edit-metadata-cover-preview" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" class="img-thumbnail bg-transparent border-secondary" style="width: 100%; display: block; max-width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 8px;">
                </div>
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
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="edit-metadata-is-private">
                <label class="form-check-label text-white" for="edit-metadata-is-private"><i class="bi bi-lock-fill text-warning"></i> Private Song</label>
              </div>
              <button type="submit" class="btn btn-danger w-100" id="edit-metadata-submit-btn">Save Changes</button>
              <div class="progress mt-3 d-none" id="metadata-progress-container" style="height: 15px;">
                <div id="metadata-progress" class="progress-bar progress-bar-striped progress-bar-animated bg-danger" role="progressbar" style="width: 0%;">0%</div>
              </div>
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

    <div class="modal fade" id="api-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-code-slash text-danger me-2"></i> API Access</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary mb-3 small">Use the following endpoints to interface with your music library programmatically.</p>
            
            <div class="mb-3">
              <label for="api-action-select" class="form-label text-white">Select API Action</label>
              <select class="form-select mb-3" id="api-action-select">
                <optgroup label="Library Data (GET)">
                  <option value="get_songs">Fetch All Songs</option>
                  <option value="get_artists">Fetch Artists</option>
                  <option value="get_albums">Fetch Albums</option>
                  <option value="get_genres">Fetch Genres</option>
                  <option value="get_explore">Fetch Explore / Discovery Data</option>
                  <option value="search&q=YOUR_QUERY">Search Music</option>
                  <option value="get_song_data&id=SONG_ID">Get Song Metadata</option>
                  <option value="get_playlist_songs&public_id=PLAYLIST_ID">Get Playlist Songs</option>
                </optgroup>
                <optgroup label="Media & Files (GET)">
                  <option value="get_stream&id=SONG_ID">Stream Audio Data</option>
                  <option value="get_image&id=SONG_ID">Get Cover Art Image</option>
                  <option value="get_profile_picture&id=USER_ID">Get User Profile Picture</option>
                  <option value="download_song&id=SONG_ID">Download MP3 File</option>
                </optgroup>
                <optgroup label="User Data (Auth Required - GET)">
                  <option value="get_session">Get Current Logged-in User</option>
                  <option value="get_favorites">Get User Favorites</option>
                  <option value="get_history">Get Playback History</option>
                  <option value="get_user_playlists">Get User Playlists</option>
                  <option value="get_recommendations">Get Personalized Recommendations</option>
                </optgroup>
                <optgroup label="Interactions (Auth Required - POST)">
                  <option value="toggle_favorite" data-method="POST" data-body='{"id": 123}'>Toggle Favorite</option>
                  <option value="log_play" data-method="POST" data-body='{"id": 123}'>Log Song Play</option>
                  <option value="create_playlist" data-method="POST" data-body='{"name": "My Playlist"}'>Create Playlist</option>
                </optgroup>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label text-white d-flex justify-content-between">
                <span>Endpoint URL</span>
                <span id="api-method-badge" class="badge bg-primary">GET</span>
              </label>
              <div class="input-group">
                <input type="text" class="form-control" id="api-url-input" readonly>
                <button class="btn btn-danger" type="button" id="copy-api-btn">Copy</button>
              </div>
            </div>
            
            <div class="bg-dark p-3 rounded border border-secondary mt-3 d-none" id="api-payload-container">
              <h6 class="text-white mb-2" style="font-size: 0.85rem;">JSON Payload Required (Body)</h6>
              <code class="text-warning" id="api-payload-code"></code>
            </div>
            
            <!-- NEW IFRAME SECTION FOR RANDOM EXAMPLE DATA -->
            <div class="mt-4">
              <h6 class="text-white mb-2" style="font-size: 0.85rem;">Example Output (Random Data)</h6>
              <iframe id="api-example-iframe" class="w-100 rounded border border-secondary" style="height: 180px; background-color: #000000; overflow: auto;" src="about:blank"></iframe>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="license-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background-color: var(--ytm-surface); border: 1px solid #404040;">
          <div class="modal-header border-0 pb-2" style="border-bottom: 1px solid var(--ytm-surface-2) !important;">
            <h5 class="modal-title text-white"><i class="bi bi-shield-check text-success me-2"></i> Software License</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-light">
            <div class="p-3 rounded" style="background: rgba(0,0,0,0.2); font-family: 'Courier New', Courier, monospace; font-size: 0.85rem; line-height: 1.5; white-space: pre-wrap;">MIT License

Copyright (c) 2026 赤葦だんご

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.</div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Close</button>
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
                        <div style="color: #ffffff !important;" class="d-flex align-items-center"><input class="form-check-input me-2" type="checkbox" id="pd-select-all" checked> #</div>
                        <div style="color: #ffffff !important;">Title</div><div style="color: #ffffff !important;">Artist</div><div style="color: #ffffff !important;">Duration</div><div></div>
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
    
    <div class="modal fade" id="song-audio-settings-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: rgba(30, 30, 30, 0.95); backdrop-filter: blur(10px); border: 1px solid #444;">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title w-100 text-center text-white">Audio Settings for <br><small id="sas-song-title" class="text-secondary"></small></h5>
            <button type="button" class="btn-close btn-close-white position-absolute end-0 me-3" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-white">
            <input type="hidden" id="sas-song-id">
            
            <div class="mb-4">
              <label class="form-label d-flex justify-content-between">
                <span>Song Volume Multiplier</span>
                <span id="sas-vol-val">1.0x</span>
              </label>
              <input type="range" class="form-range" id="sas-vol-slider" min="0" max="3" step="0.1" value="1">
              <small class="text-secondary d-block mt-1">Adjust if this specific song is too quiet or loud compared to others.</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label d-flex justify-content-between align-items-center">
                <span>Per-Song Equalizer</span>
                <select class="form-select form-select-sm w-auto" id="sas-eq-preset-select">
                   <option value="Custom">Custom</option>
                   <option value="Flat">Flat</option>
                   <option value="Rock">Rock</option>
                   <option value="Jazz">Jazz</option>
                   <option value="Classical">Classical</option>
                   <option value="Pop">Pop</option>
                   <option value="Bass Boost">Bass Boost</option>
                 </select>
              </label>
              <div class="d-flex justify-content-between text-center small text-secondary mt-3">
                 <span>60Hz</span><span>230Hz</span><span>910Hz</span><span>3.6kHz</span><span>14kHz</span>
              </div>
              <div class="d-flex justify-content-between mt-2 mb-4">
                 <input type="range" class="form-range sas-eq-band" data-band="0" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range sas-eq-band" data-band="1" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range sas-eq-band" data-band="2" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range sas-eq-band" data-band="3" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
                 <input type="range" class="form-range sas-eq-band" data-band="4" min="-12" max="12" step="1" value="0" style="width:18%; transform: rotate(-90deg); margin-top: 40px; margin-bottom: 40px;">
              </div>
            </div>

            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary w-50" id="sas-reset-btn">Reset to Global</button>
              <button type="button" class="btn btn-danger w-50" id="sas-save-btn">Save to Song</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="sleep-timer-bubble" class="d-none">
      <i class="bi bi-moon-stars-fill text-info"></i>
      <span class="time" id="sleep-timer-countdown">00:00</span>
      <button class="action-btn text-secondary" id="sleep-timer-wake-btn" title="Toggle Screen Awake"><i class="bi bi-display"></i></button>
      <button class="action-btn text-secondary" id="sleep-timer-cancel-btn" title="Cancel Timer"><i class="bi bi-x-circle-fill"></i></button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/nosleep/0.12.0/NoSleep.min.js"></script>
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
        
        // FIX INFINITE SPINNER: Do not assign endless MediaStreams to video elements before the window finishes loading!
        // Chromium treats captureStream() as a pending network resource if attached too early.
        window.addEventListener('load', () => {
          try {
            if (pipCanvas.captureStream) {
              pipVideo.srcObject = pipCanvas.captureStream(1);
            } else if (pipBtnDesktop) {
              pipBtnDesktop.style.display = 'none';
            }
          } catch(e) {}
        });
        
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
        const activityModalEl = document.getElementById('activity-modal');
        
        if (activityModalEl) {
          const body = document.getElementById('activity-modal-body');
          
          const loadActivityFeed = async () => {
            body.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-secondary" role="status"></div></div>';
            const feed = await fetchData('?action=get_activity_feed');
            if (feed && feed.length > 0) {
              body.innerHTML = '<ul class="list-group list-group-flush">' + feed.map(item => {
                if (item.type === 'message_notif') {
                  const unreadBadge = item.is_unread ? '<span class="badge bg-danger ms-2" style="font-size: 0.65rem;">New</span>' : '';
                  const bgClass = item.is_unread ? 'bg-dark' : 'bg-transparent';
                  const cleanContent = item.content ? parseUserText(item.content).replace(/<[^>]*>?/gm, '') : 'Sent an image';

                  return `
                    <li class="list-group-item ${bgClass} text-white border-secondary py-3 open-chat-btn" data-userid="${item.sender_id}" data-artist="${escapeHTML(item.commenter_name)}" style="cursor: pointer;">
                      <div class="d-flex align-items-start gap-3">
                        <div class="mt-1"><i class="bi bi-chat-dots-fill text-info fs-5"></i></div>
                        <div class="flex-grow-1" style="min-width: 0;">
                          <div style="font-size: 0.95rem;"><span class="fw-bold">${escapeHTML(item.commenter_name)}</span> sent you a message${unreadBadge}</div>
                          <div class="text-secondary fst-italic my-1 text-truncate" style="font-size: 0.9rem; border-left: 2px solid #555; padding-left: 8px;">"${cleanContent}"</div>
                          <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(item.created_at)}</small>
                            <button class="btn btn-sm btn-outline-light"><i class="bi bi-reply-fill"></i> Reply</button>
                          </div>
                        </div>
                      </div>
                    </li>
                  `;
                } else if (item.type === 'comment_notif' || item.type === 'community_notif') {
                  const isCommunity = item.type === 'community_notif';
                  const isReply = item.notif_type === 'reply' || item.notif_type === 'community_reply';
                  const unreadBadge = item.is_unread ? '<span class="badge bg-danger ms-2" style="font-size: 0.65rem;">New</span>' : '';
                  const bgClass = item.is_unread ? 'bg-dark' : 'bg-transparent';

                  let actionText = '';
                  let iconHTML = '';
                  let replyBtnHTML = '';

                  if (isCommunity) {
                    actionText = `<span class="fw-bold">${escapeHTML(item.commenter_name)}</span> replied to your community post${unreadBadge}`;
                    iconHTML = `<i class="bi bi-people-fill text-info fs-5"></i>`;
                    replyBtnHTML = `<button class="btn btn-sm btn-outline-light notif-comm-reply-btn" data-post-id="${item.post_id}" data-username="${escapeHTML(item.commenter_name)}"><i class="bi bi-reply-fill"></i> Reply</button>`;
                  } else {
                    actionText = isReply 
                      ? `<span class="fw-bold">${escapeHTML(item.commenter_name)}</span> replied to your comment on <span class="text-info">${escapeHTML(item.song_title)}</span>${unreadBadge}`
                      : `<span class="fw-bold">${escapeHTML(item.commenter_name)}</span> commented on your song <span class="text-info">${escapeHTML(item.song_title)}</span>${unreadBadge}`;
                    iconHTML = `<i class="bi bi-chat-dots-fill text-primary fs-5"></i>`;
                    replyBtnHTML = `<button class="btn btn-sm btn-outline-light notif-reply-btn" data-song-id="${item.song_id}" data-comment-id="${item.comment_id}" data-username="${escapeHTML(item.commenter_name)}"><i class="bi bi-reply-fill"></i> Reply</button>`;
                  }
                     
                  const cleanContent = parseUserText(item.content).replace(/<[^>]*>?/gm, ''); // Parse BBCode then strip HTML entirely for notifications

                  return `
                    <li class="list-group-item ${bgClass} text-white border-secondary py-3">
                      <div class="d-flex align-items-start gap-3">
                        <div class="mt-1">${iconHTML}</div>
                        <div class="flex-grow-1" style="min-width: 0;">
                          <div style="font-size: 0.95rem;">${actionText}</div>
                          <div class="text-secondary fst-italic my-1 text-truncate" style="font-size: 0.9rem; border-left: 2px solid #555; padding-left: 8px;">"${cleanContent}"</div>
                          <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(item.created_at)}</small>
                            ${replyBtnHTML}
                          </div>
                        </div>
                      </div>
                    </li>
                  `;
                } else {
                  const unreadBadge = item.is_unread ? '<span class="badge bg-danger ms-2" style="font-size: 0.65rem;">New</span>' : '';
                  const bgClass = item.is_unread ? 'bg-dark' : 'bg-transparent';
                  return `
                    <li class="list-group-item ${bgClass} text-white border-secondary py-3">
                      <div class="d-flex align-items-center gap-3">
                        <div class="mt-1"><i class="bi bi-clock-history text-secondary fs-5"></i></div>
                        <div>
                          <div style="font-size: 0.95rem;">You ${escapeHTML(item.action)} ${item.target_name ? `<span class="text-info">${escapeHTML(item.target_name)}</span>` : ''}${unreadBadge}</div>
                          <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(item.created_at)}</small>
                        </div>
                      </div>
                    </li>
                  `;
                }
              }).join('') + '</ul>';
               
              // Automatically mark all notifications as read when the feed is opened
              setTimeout(async () => {
                document.querySelectorAll('.notif-badge').forEach(b => b.classList.add('d-none'));
                await fetchData('?action=mark_notifs_read', { method: 'POST' });
                updateNotifBadge();
              }, 1500);
               
            } else {
              body.innerHTML = '<p class="text-secondary text-center p-4">No recent activity.</p>';
            }
          };

          activityModalEl.addEventListener('show.bs.modal', loadActivityFeed);

          const inboxModalEl = document.getElementById('inbox-modal');
          const chatModalEl = document.getElementById('chat-modal');
          const chatForm = document.getElementById('chat-form');
          
          let chatPollingInterval = null;

          const getPreviewText = (htmlStr) => {
            if (!htmlStr) return '';
            const tmp = document.createElement('div');
            tmp.innerHTML = htmlStr;
            return tmp.textContent || tmp.innerText || '';
          };

          const loadInbox = async () => {
            const listEl = document.getElementById('inbox-list');
            listEl.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-secondary"></div></div>';
            const inbox = await fetchData('?action=get_inbox');
            if (inbox && inbox.length > 0) {
              listEl.innerHTML = inbox.map(m => {
                const previewText = m.has_image ? 'Photo' : getPreviewText(m.content);
                const isMe = m.sender_id == currentUser.id;
                
                let readStatusHtml = '';
                if (isMe) {
                  readStatusHtml = `<i class="bi bi-check2-all ms-1 ${m.is_read ? 'text-info' : 'text-secondary'}" title="${m.is_read ? 'Read' : 'Delivered'}"></i>`;
                }

                let activeStatusHtml = '';
                if (m.last_active) {
                  let isoTime = m.last_active;
                  if (!isoTime.endsWith('Z')) isoTime += 'Z'; 
                  const lastActiveDate = new Date(isoTime);
                  const minsDiff = Math.floor((new Date() - lastActiveDate) / 60000);
                  if (minsDiff < 5) {
                    activeStatusHtml = `<span class="position-absolute bottom-0 end-0 p-1 bg-success border border-dark rounded-circle" title="Active now"></span>`;
                  } else {
                    activeStatusHtml = `<span class="position-absolute bottom-0 end-0 p-1 bg-secondary border border-dark rounded-circle" title="Active ${timeAgo(m.last_active)}"></span>`;
                  }
                }

                return `
                <div class="list-group-item bg-transparent text-white border-secondary px-3 py-3 d-flex align-items-center gap-3 hover-bg-dark open-chat-btn" data-userid="${m.other_id}" data-artist="${encodeURIComponent(m.other_name)}" style="cursor: pointer;" title="Preview: ${escapeHTML(previewText)}">
                  <div class="position-relative user-profile-link" data-userid="${m.other_id}" data-artist="${encodeURIComponent(m.other_name)}">
                    <img src="?action=get_profile_picture&id=${m.other_id}" class="rounded-circle" style="width: 48px; height: 48px; object-fit: cover;">
                    ${activeStatusHtml}
                  </div>
                  <div class="flex-grow-1 overflow-hidden">
                    <div class="d-flex justify-content-between align-items-center">
                      <span class="fw-bold text-truncate user-profile-link hover-underline" data-userid="${m.other_id}" data-artist="${encodeURIComponent(m.other_name)}">${escapeHTML(m.other_name)}</span>
                      <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(m.created_at)}</small>
                    </div>
                    <div class="text-secondary small text-truncate ${m.unread_count > 0 ? 'text-white fw-bold' : ''}">
                       ${isMe ? 'You: ' : ''}${m.has_image ? '<i class="bi bi-image"></i> ' : ''}${escapeHTML(previewText)}${readStatusHtml}
                    </div>
                  </div>
                  ${m.unread_count > 0 ? `<span class="badge bg-danger rounded-pill">${m.unread_count}</span>` : ''}
                </div>
              `}).join('');
            } else {
              listEl.innerHTML = '<div class="p-4 text-center text-secondary">No conversations yet. Go to a profile to send a message!</div>';
            }
          };

          if (inboxModalEl) {
            inboxModalEl.addEventListener('show.bs.modal', loadInbox);
            inboxModalEl.addEventListener('click', (e) => {
              const userLink = e.target.closest('.user-profile-link');
              if (userLink) {
                e.stopPropagation();
                bootstrap.Modal.getInstance(inboxModalEl).hide();
                loadView({ type: 'artist_songs', param: decodeURIComponent(userLink.dataset.artist), sort: 'album_asc', filter_user_id: userLink.dataset.userid, artist_name: '' });
                return;
              }
              const chatBtn = e.target.closest('.open-chat-btn');
              if (chatBtn) {
                bootstrap.Modal.getInstance(inboxModalEl).hide();
                window.openChat(chatBtn.dataset.userid, decodeURIComponent(chatBtn.dataset.artist));
              }
            });
            
            const inboxSearchInput = document.getElementById('inbox-search-input');
            const inboxSearchDropdown = document.getElementById('inbox-search-dropdown');
            let inboxSearchTimeout;

            if (inboxSearchInput) {
              inboxSearchInput.addEventListener('input', (e) => {
                clearTimeout(inboxSearchTimeout);
                const q = e.target.value.trim();
                if (q === '') {
                  inboxSearchDropdown.classList.add('d-none');
                  return;
                }
                inboxSearchTimeout = setTimeout(async () => {
                  const res = await fetchData(`?action=search&q=${encodeURIComponent(q)}`);
                  inboxSearchDropdown.innerHTML = '';
                  let usersFound = false;
                  if (res && res.shelves) {
                    const artistShelf = res.shelves.find(s => s.type === 'artists');
                    if (artistShelf && artistShelf.items.length > 0) {
                      const users = artistShelf.items.filter(u => u.is_user);
                      if (users.length > 0) {
                        usersFound = true;
                        inboxSearchDropdown.innerHTML = users.map(u => `
                          <div class="search-dropdown-item d-flex justify-content-between align-items-center w-100 px-3 py-2">
                            <div class="d-flex align-items-center gap-3 user-profile-link" data-userid="${u.id}" data-artist="${encodeURIComponent(u.name)}">
                              <img src="?action=get_profile_picture&id=${u.id}" class="search-dropdown-img rounded-circle" style="width: 32px; height: 32px;">
                              <div class="search-dropdown-text">
                                <div class="search-dropdown-title text-white fw-bold hover-underline">${escapeHTML(u.name)}</div>
                                <div class="search-dropdown-subtitle text-secondary">ID: ${u.id}</div>
                              </div>
                            </div>
                            <button class="btn btn-sm btn-outline-info rounded-pill ms-2 start-chat-btn" data-userid="${u.id}" data-artist="${escapeHTML(u.name)}">
                              <i class="bi bi-chat-dots-fill"></i> Message
                            </button>
                          </div>
                        `).join('');
                      }
                    }
                  }
                  if (!usersFound) {
                    inboxSearchDropdown.innerHTML = '<div class="p-3 text-secondary text-center small">No users found</div>';
                  }
                  inboxSearchDropdown.classList.remove('d-none');
                }, 300);
              });

              inboxSearchDropdown.addEventListener('click', (e) => {
                const startChatBtn = e.target.closest('.start-chat-btn');
                if (startChatBtn) {
                  e.preventDefault();
                  e.stopPropagation();
                  const userId = startChatBtn.dataset.userid;
                  const artistName = startChatBtn.dataset.artist;
                  
                  inboxSearchDropdown.classList.add('d-none');
                  inboxSearchInput.value = '';
                  bootstrap.Modal.getInstance(inboxModalEl).hide();
                  
                  window.openChat(userId, artistName);
                }
              });

              document.addEventListener('click', (e) => {
                if (inboxSearchDropdown && !inboxSearchDropdown.contains(e.target) && e.target !== inboxSearchInput) {
                  inboxSearchDropdown.classList.add('d-none');
                }
              });
            }
          }

          window.openChat = async (targetId, artistName) => {
            document.getElementById('chat-target-id').value = targetId;
            document.getElementById('chat-modal-title').innerHTML = `<img src="?action=get_profile_picture&id=${targetId}" class="rounded-circle" style="width: 32px; height: 32px;"> ${escapeHTML(artistName)}`;
            bootstrap.Modal.getOrCreateInstance(chatModalEl).show();
            await refreshChat();
            if (chatPollingInterval) clearInterval(chatPollingInterval);
            chatPollingInterval = setInterval(refreshChat, 5000);
          };

          const refreshChat = async () => {
            const targetId = document.getElementById('chat-target-id').value;
            if (!targetId) return;
            const messages = await fetchData(`?action=get_chat&target_id=${targetId}`);
            const listEl = document.getElementById('chat-messages-list');
             
            if (messages && messages.length > 0) {
              listEl.innerHTML = messages.map(m => {
                const isMe = m.sender_id == currentUser.id;
                const align = isMe ? 'align-self-end' : 'align-self-start';
                const bg = isMe ? 'bg-danger text-white' : 'bg-dark border border-secondary text-white';
                const imgHtml = m.has_image ? `<img src="?action=get_message_image&id=${m.id}" class="img-fluid rounded-3 mb-2" style="max-height: 200px;">` : '';
                const editedHtml = m.is_edited ? '<small class="text-white-50 ms-2" style="font-size: 0.65rem;">(Edited)</small>' : '';
                 
                let actionHtml = '';
                if (isMe) {
                  actionHtml = `
                    <div class="d-flex justify-content-end gap-2 mt-1">
                      <button class="btn btn-link btn-sm text-white-50 p-0 edit-msg-btn" data-id="${m.id}" data-content="${escapeHTML(m.content)}"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-link btn-sm text-white-50 p-0 del-msg-btn" data-id="${m.id}"><i class="bi bi-trash"></i></button>
                    </div>
                  `;
                }

                return `
                  <div class="${align} ${bg} p-2 rounded-4 shadow-sm" style="max-width: 80%; min-width: 100px;">
                    ${imgHtml}
                    ${m.content ? `<div style="font-size: 0.95rem; white-space: pre-wrap;">${parseUserText(m.content)}</div>` : ''}
                    <div class="d-flex justify-content-between align-items-center mt-1">
                      <div style="font-size: 0.65rem; opacity: 0.7;">${timeAgo(m.created_at)}${editedHtml}</div>
                      ${actionHtml}
                    </div>
                  </div>
                `;
              }).join('');
              listEl.scrollTop = listEl.scrollHeight;
            } else {
              listEl.innerHTML = '<div class="text-center text-secondary p-4 mt-auto mb-auto">Start the conversation!</div>';
            }
            updateNotifBadge();
          };

          if (chatModalEl) {
             chatModalEl.addEventListener('click', async (e) => {
               const editBtn = e.target.closest('.edit-msg-btn');
               if (editBtn) {
                 document.getElementById('edit-message-id').value = editBtn.dataset.id;
                 document.getElementById('edit-message-input').value = decodeHTML(editBtn.dataset.content);
                 bootstrap.Modal.getOrCreateInstance(document.getElementById('edit-message-modal')).show();
               }
               const delBtn = e.target.closest('.del-msg-btn');
               if (delBtn) {
                 if (confirm('Delete this message?')) {
                   await fetchData('?action=delete_message', { method: 'POST', body: JSON.stringify({ id: delBtn.dataset.id }) });
                   refreshChat();
                 }
               }
             });

             chatModalEl.addEventListener('hidden.bs.modal', () => {
               if (chatPollingInterval) clearInterval(chatPollingInterval);
               document.getElementById('chat-target-id').value = '';
             });
          }

          const editMsgForm = document.getElementById('edit-message-form');
          if (editMsgForm) {
            editMsgForm.addEventListener('submit', async e => {
              e.preventDefault();
              const id = document.getElementById('edit-message-id').value;
              const content = document.getElementById('edit-message-input').value;
              await fetchData('?action=edit_message', { method: 'POST', body: JSON.stringify({ id, content }) });
              bootstrap.Modal.getInstance(document.getElementById('edit-message-modal')).hide();
              refreshChat();
            });
          }

          if (chatForm) {
             chatForm.addEventListener('submit', async e => {
               e.preventDefault();
               const targetId = document.getElementById('chat-target-id').value;
               const input = document.getElementById('chat-input');
               const imgInput = document.getElementById('chat-image-input');
               
               if (!input.value.trim() && imgInput.files.length === 0) return;
               
               const formData = new FormData();
               formData.append('target_id', targetId);
               formData.append('content', input.value);
               if (imgInput.files.length > 0) {
                 formData.append('image', imgInput.files[0]);
               }

               const submitBtn = chatForm.querySelector('button[type="submit"]');
               submitBtn.disabled = true;

               const res = await fetch('?action=send_message', { method: 'POST', body: formData });
               const result = await res.json();
               
               submitBtn.disabled = false;
               
               if (result && result.status === 'success') {
                 input.value = '';
                 imgInput.value = '';
                 await refreshChat();
               } else {
                 showToast(result.message || 'Failed to send', 'error');
               }
             });
          }
          
          const clearBtn = document.getElementById('clear-activity-btn');
          if (clearBtn) {
            clearBtn.addEventListener('click', async () => {
              if (confirm('Are you sure you want to clear all your notifications?')) {
                const res = await fetchData('?action=clear_activity_feed', { method: 'POST' });
                if (res && res.status === 'success') {
                  showToast('Notifications cleared.', 'success');
                  loadActivityFeed();
                  updateNotifBadge();
                }
              }
            });
          }
          
          body.addEventListener('click', (e) => {
            const replyBtn = e.target.closest('.notif-reply-btn');
            if (replyBtn) {
              const songId = parseInt(replyBtn.dataset.songId);
              const commentId = parseInt(replyBtn.dataset.commentId);
              const username = replyBtn.dataset.username;
              
              bootstrap.Modal.getInstance(activityModalEl).hide();
              window.openCommentsModal(songId, commentId, username);
              return;
            }
            const commReplyBtn = e.target.closest('.notif-comm-reply-btn');
            if (commReplyBtn) {
              bootstrap.Modal.getInstance(activityModalEl).hide();
              loadView({ type: 'get_community', param: '', sort: 'newest', filter_user_id: '', artist_name: '' });
              setTimeout(() => {
                const input = document.getElementById('community-post-input');
                const parentId = document.getElementById('community-parent-id');
                if (input && parentId) {
                  parentId.value = commReplyBtn.dataset.postId;
                  input.placeholder = "Replying to post...";
                  const username = commReplyBtn.dataset.username.replace(/\s+/g, '');
                  input.value = `@${username} `;
                  input.focus();
                  window.scrollTo({ top: 0, behavior: 'smooth' });
                }
              }, 800);
            }
          });
        }

        // API Modal logic
        const apiUrlInput = document.getElementById('api-url-input');
        const copyApiBtn = document.getElementById('copy-api-btn');
        const apiModalEl = document.getElementById('api-modal');
        const apiActionSelect = document.getElementById('api-action-select');
        const apiMethodBadge = document.getElementById('api-method-badge');
        const apiPayloadContainer = document.getElementById('api-payload-container');
        const apiPayloadCode = document.getElementById('api-payload-code');

        const updateApiUrl = () => {
          if(!apiUrlInput || !apiActionSelect) return;
          const selectedOption = apiActionSelect.options[apiActionSelect.selectedIndex];
          const actionVal = selectedOption.value;
          
          // Generate the full URL for the Dedicated Public API
          let url = window.location.origin + window.location.pathname + '?access=api&action=' + actionVal;
          apiUrlInput.value = url;
          
          // Update the GET/POST badge
          const method = selectedOption.getAttribute('data-method') || 'GET';
          if(apiMethodBadge) {
            apiMethodBadge.textContent = method;
            apiMethodBadge.className = method === 'POST' ? 'badge bg-warning text-dark' : 'badge bg-primary';
          }

          // Show or hide the JSON payload requirement example
          const payload = selectedOption.getAttribute('data-body');
          if (payload && apiPayloadContainer && apiPayloadCode) {
            apiPayloadCode.textContent = payload;
            apiPayloadContainer.classList.remove('d-none');
          } else if (apiPayloadContainer) {
            apiPayloadContainer.classList.add('d-none');
          }

          // NEW: Fetch Real Data from Database, Fallback to Fake Random Data
          const apiExampleIframe = document.getElementById('api-example-iframe');
          if (apiExampleIframe) {
            
            // Show loading state while fetching from SQLite
            apiExampleIframe.srcdoc = `<html><body style="background-color: #000000; color: #aaaaaa; font-family: monospace; font-size: 13px; margin: 0; padding: 12px;">Fetching real database data...</body></html>`;
            
            const injectIframe = (dataObj, color) => {
              const jsonString = JSON.stringify(dataObj, null, 2);
              apiExampleIframe.srcdoc = `<html><body style="background-color: #000000; color: ${color}; font-family: monospace; font-size: 13px; margin: 0; padding: 12px; white-space: pre-wrap; word-wrap: break-word;">${jsonString}</body></html>`;
            };

            const fallbackToFakeData = () => {
              const randomData = {
                status: "success", action_requested: actionVal, method: method,
                results: [{
                    id: Math.floor(Math.random() * 9000) + 1000, 
                    title: "Example Track " + Math.floor(Math.random() * 100),
                    artist: "Random Artist " + Math.floor(Math.random() * 50),
                    album: "Awesome Album Vol " + Math.floor(Math.random() * 10), 
                    duration: Math.floor(Math.random() * 200) + 120,
                    is_favorite: Math.random() > 0.5 ? 1 : 0
                }]
              };
              injectIframe(randomData, '#4ade80'); // Green text means FAKE data
            };

            if (method === 'GET') {
              // Replace UI placeholders with generic '1' or 'a' so the database actually returns valid matches
              let testUrl = apiUrlInput.value.replace('SONG_ID', '1').replace('USER_ID', '1').replace('PLAYLIST_ID', '1').replace('YOUR_QUERY', 'a');
              if (window.adminApiKey) {
                testUrl += '&api_key=' + encodeURIComponent(window.adminApiKey);
              }
              
              fetch(testUrl)
                .then(res => res.json())
                .then(data => {
                  // If db is empty [] or throws an error, trigger the fallback to fake data
                  if (!data || data.status === 'error' || (Array.isArray(data) && data.length === 0)) {
                    fallbackToFakeData();
                  } else {
                    // Limit output size to 3 items so the iframe doesn't freeze the browser
                    let displayData = Array.isArray(data) ? data.slice(0, 3) : data;
                    injectIframe(displayData, '#60a5fa'); // Blue text means REAL database data
                  }
                })
                .catch(() => fallbackToFakeData());
            } else {
              // Always use fake data for POST requests to avoid accidentally creating playlists or deleting things!
              fallbackToFakeData(); 
            }
          }
        };

        if (apiModalEl && apiUrlInput) {
          apiModalEl.addEventListener('show.bs.modal', updateApiUrl);
          if (apiActionSelect) {
            apiActionSelect.addEventListener('change', updateApiUrl);
          }
        }

        if (copyApiBtn) {
          copyApiBtn.addEventListener('click', () => {
            const onSuccess = () => {
              copyApiBtn.textContent = 'Copied!';
              copyApiBtn.classList.replace('btn-danger', 'btn-success');
              setTimeout(() => {
                copyApiBtn.textContent = 'Copy';
                copyApiBtn.classList.replace('btn-success', 'btn-danger');
              }, 2000);
            };
            const onError = () => showToast('Failed to copy API link.', 'error');

            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(apiUrlInput.value).then(onSuccess).catch(onError);
            } else {
              const textArea = document.createElement("textarea");
              textArea.value = apiUrlInput.value;
              textArea.style.position = "fixed";
              textArea.style.top = "0";
              textArea.style.left = "0";
              textArea.style.opacity = "0";
              document.body.appendChild(textArea);
              textArea.focus();
              textArea.select();
              try {
                const successful = document.execCommand('copy');
                if (successful) onSuccess();
                else onError();
              } catch (err) {
                onError();
              }
              document.body.removeChild(textArea);
            }
          });
        }

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

        const renderQueue = async (reset = false) => {
          const queueContainerDesktop = document.getElementById('desktop-player-queue-list');
          const queueContainerMobile = document.getElementById('mobile-player-queue-list');
          
          if (reset) {
            renderedQueueCount = 0;
            if (queueContainerDesktop) queueContainerDesktop.innerHTML = '<div class="song-list"></div>';
            if (queueContainerMobile) queueContainerMobile.innerHTML = '<div class="song-list"></div>';
          }
          
          if (renderedQueueCount >= queue.length || isQueueLoading) return;
          isQueueLoading = true;
          
          // Grab the next 25 IDs
          const nextChunkIds = queue.slice(renderedQueueCount, renderedQueueCount + 25);
          const missingIds = nextChunkIds.filter(id => !globalSongCache[id]);
          
          // Fetch any data that isn't cached yet
          if (missingIds.length > 0) {
            const fetchedData = await fetchData('?action=get_queue_songs', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ids: missingIds })
            }, true); // Silent flag set to true
            if (fetchedData) {
              fetchedData.forEach(song => globalSongCache[song.id] = song);
            }
          }
          
          let html = '';
          const escapeAttr = (str) => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';
          
          nextChunkIds.forEach((songId) => {
            const song = globalSongCache[songId];
            if (!song) return;
            const isNowPlaying = currentSong && (songId === currentSong.id);
            
            html += `
              <div class="song-item py-md-3 ${isNowPlaying ? 'now-playing' : ''}" 
                data-song-id="${song.id}" 
                data-is-favorite="${song.is_favorite == 1 ? '1' : '0'}"
                data-song-title="${escapeAttr(song.title)}"
                data-song-artist="${escapeAttr(song.artist)}"
                data-song-album="${escapeAttr(song.album)}"
                data-song-genre="${escapeAttr(song.genre)}"
                data-song-user-id="${song.user_id}">
                <div class="song-indicator-wrapper d-flex align-items-center justify-content-center">
                  <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                  <i class="bi bi-soundwave playing-icon"></i>
                </div>
                <div class="song-title-wrapper text-truncate"><div class="song-title text-truncate">${song.is_private == 1 ? '<i class="bi bi-lock-fill text-warning me-1" title="Private Song"></i>' : ''}${song.title}</div></div>
                <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">
                  <div class="text-truncate">${song.artist}</div>
                  <div class="text-secondary d-md-none" style="font-size: 0.8rem; margin-top: 2px;" title="Play Count"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
                </div>
                <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}" data-userid="${song.user_id}">${song.album}</div>
                <div class="song-views d-none d-md-block text-truncate" title="${song.play_count || 0} views">${formatSongCount(song.play_count || 0)}</div>
                <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
                <div class="song-more"><button class="more-btn" data-song-id="${song.id}"><i class="bi bi-three-dots-vertical"></i></button></div>
                <div class="song-artist-mobile w-100 flex-column align-items-start">
                  <div class="d-flex justify-content-between align-items-center w-100">
                     <span class="song-artist-name text-truncate flex-grow-1" style="min-width: 0;">${song.artist}</span>
                     <span class="song-duration-mobile flex-shrink-0">${formatTime(song.duration)}</span>
                  </div>
                  <div class="text-secondary text-start" style="font-size: 0.8rem; margin-top: 2px;"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
                </div>
              </div>`;
          });
          
          // Append to the queue DOM
          if (queueContainerDesktop && queueContainerDesktop.querySelector('.song-list')) queueContainerDesktop.querySelector('.song-list').insertAdjacentHTML('beforeend', html);
          if (queueContainerMobile && queueContainerMobile.querySelector('.song-list')) queueContainerMobile.querySelector('.song-list').insertAdjacentHTML('beforeend', html);
          
          renderedQueueCount += nextChunkIds.length;
          isQueueLoading = false;

          // Dynamically indicate offline availability for ALL queue items
          caches.open('php-music-offline').then(cache => {
            const addedItems = document.querySelectorAll('#desktop-player-queue-list .song-item:not(.queue-cache-checked), #mobile-player-queue-list .song-item:not(.queue-cache-checked)');
            addedItems.forEach(async item => {
              item.classList.add('queue-cache-checked');
              const sid = item.dataset.songId;
              const req = await cache.match(`?action=get_stream&id=${sid}`, { ignoreSearch: false, ignoreVary: true });
              const titleWrapper = item.querySelector('.song-title-wrapper');
              
              if (!req) {
                // It is NOT offline
                item.classList.add('offline-missing');
                if (!navigator.onLine) {
                  item.style.transition = 'opacity 0.3s ease';
                  item.style.opacity = '0.4';
                }
                if (titleWrapper && !titleWrapper.querySelector('.offline-status-icon')) {
                   titleWrapper.insertAdjacentHTML('beforeend', ' <i class="bi bi-cloud-slash text-secondary offline-status-icon ms-1" title="Not saved offline" style="font-size: 0.85rem;"></i>');
                }
              } else {
                // It IS offline
                item.classList.remove('offline-missing');
                item.style.opacity = '1';
                if (titleWrapper && !titleWrapper.querySelector('.offline-status-icon')) {
                   titleWrapper.insertAdjacentHTML('beforeend', ' <i class="bi bi-cloud-check-fill text-success offline-status-icon ms-1" title="Available offline" style="font-size: 0.85rem;"></i>');
                }
              }
            });
          });
          
          // Focus the playing song if it was a total reset
          if (reset && currentSong) {
            setTimeout(() => {
              const active = document.querySelector('#desktop-player-queue-list .song-item.now-playing');
              if (active) active.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
          }
        };

        const playerArtDesktop = document.getElementById('player-art-desktop');
        if (playerArtDesktop) {
          playerArtDesktop.addEventListener('click', () => {
            if (currentSong && desktopPlayerModal) {
              renderDesktopLyrics();
              renderQueue();
              desktopPlayerModal.show();
            }
          });
        }

        // Attach click events to BOTH desktop and mobile Up Next queues
        ['desktop-player-queue-list', 'mobile-player-queue-list'].forEach(qId => {
          const queueList = document.getElementById(qId);
          if (queueList) {
            queueList.addEventListener('click', e => {
              
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
                 if (playerModal) playerModal.hide();
                 const artistRaw = songArtistEl.dataset.artist ? decodeURIComponent(songArtistEl.dataset.artist) : songArtistEl.textContent.trim();
                 const userId = songArtistEl.dataset.userid || '';
                 loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId });
                 return;
              }

              const songAlbumEl = e.target.closest('.song-album');
              if (songAlbumEl) {
                e.stopPropagation();
                if (desktopPlayerModal) desktopPlayerModal.hide();
                if (playerModal) playerModal.hide();
                const songItem = e.target.closest('.song-item');
                
                let rawUserId = songAlbumEl.getAttribute('data-userid') || (songItem ? (songItem.getAttribute('data-userid') || songItem.getAttribute('data-song-user-id')) : '') || '';
                let validUserId = parseInt(rawUserId, 10);
                
                let albumRaw = decodeURIComponent(songAlbumEl.getAttribute('data-album') || '');
                let songArtistRaw = songItem ? (songItem.getAttribute('data-song-artist') || '') : '';
                const songArtistsList = songArtistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');

                if (songArtistsList.length > 1) {
                  if (artistsModalBody) {
                    const modalTitle = artistsModalEl.querySelector('.modal-title');
                    if (modalTitle) modalTitle.textContent = 'Select Album Artist';
                    artistsModalBody.innerHTML = `
                      <div class="list-group list-group-flush rounded">
                        ${songArtistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary album-modal-item py-3" data-album="${encodeURIComponent(albumRaw)}" data-artist="${encodeURIComponent(a)}" data-userid="${rawUserId}">${escapeHTML(albumRaw)} (${escapeHTML(a)})</button>`).join('')}
                      </div>
                    `;
                    if (artistsModal) artistsModal.show();
                  }
                } else {
                  loadView({ 
                    type: 'album_songs', 
                    param: albumRaw, 
                    sort: 'title_asc', 
                    filter_user_id: (isNaN(validUserId) || validUserId <= 0) ? '' : validUserId,
                    artist_name: songArtistRaw
                  });
                }
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
        });

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
        audio.crossOrigin = 'anonymous'; // CRITICAL: Unlocks Web Audio API effects like Equalizer
        const audioB = new Audio();
        audioB.crossOrigin = 'anonymous';
        
        let audioCtx, sourceA, sourceB, gainA, gainB, perSongGain, compressor, analyser;
        let visualizerDataArray;
        let eqBands = [];
        let spatialPanner, spatialBypass, spatialMix;
        let enableNormalization = true;
        let isEQEnabled = false;
        let isSpatialEnabled = false;
        let globalVolumeMultiplier = 1.0;
        let globalEQBands = [0, 0, 0, 0, 0];
        let crossfadeDuration = 3.0;
        let settingsSaveTimeout = null;

        const EQ_PRESETS = {
          'Flat': [0, 0, 0, 0, 0],
          'Rock': [5, 3, -2, 4, 6],
          'Jazz': [4, 2, -2, 2, 5],
          'Classical': [5, 3, -2, 4, 4],
          'Pop': [-2, 2, 5, 2, -2],
          'Bass Boost': [8, 5, 0, 0, 0]
        };

        const saveGlobalAudioSettings = async () => {
          if (!currentUser) return;
          clearTimeout(settingsSaveTimeout);
          settingsSaveTimeout = setTimeout(async () => {
            try {
               const settings = { 
                  enableNormalization: enableNormalization, 
                  isEQEnabled: isEQEnabled, 
                  isSpatialEnabled: isSpatialEnabled, 
                  globalVolumeMultiplier: parseFloat(globalVolumeMultiplier), 
                  globalEQBands: globalEQBands.map(b => parseFloat(b)), 
                  crossfadeDuration: parseFloat(crossfadeDuration) 
               };
               
               const response = await fetchData('?action=save_global_settings', {
                 method: 'POST', headers: {'Content-Type': 'application/json'},
                 body: JSON.stringify({ settings: settings })
               });
               
               if (response && response.status === 'success') {
                  // Update the local current user memory so it doesn't revert on next checkSession
                  currentUser.settings = JSON.stringify(settings);
               }
            } catch(e) {
               console.error("Failed to save audio settings", e);
            }
          }, 800); // 800ms debounce
        };

        const initWebAudio = () => {
          if (audioCtx) return;
          audioCtx = new (window.AudioContext || window.webkitAudioContext)();
          
          const freqs = [60, 230, 910, 3600, 14000];
          let prevNode = null;
          freqs.forEach((freq, i) => {
            let band = audioCtx.createBiquadFilter();
            band.type = i === 0 ? "lowshelf" : (i === freqs.length - 1 ? "highshelf" : "peaking");
            band.frequency.value = freq;
            band.gain.value = 0;
            eqBands.push(band);
            if (prevNode) prevNode.connect(band);
            prevNode = band;
          });

          compressor = audioCtx.createDynamicsCompressor();
          compressor.threshold.value = -24; 
          compressor.knee.value = 30;
          compressor.ratio.value = 12;      
          compressor.attack.value = 0.003;
          compressor.release.value = 0.25;
          
          spatialPanner = audioCtx.createPanner();
          spatialPanner.panningModel = 'HRTF';
          spatialPanner.distanceModel = 'inverse';
          if (spatialPanner.positionZ) spatialPanner.positionZ.value = 1.0;
          else spatialPanner.setPosition(0, 0, 1.0);
          
          spatialBypass = audioCtx.createGain();
          spatialMix = audioCtx.createGain();
          
          eqBands[eqBands.length - 1].connect(spatialBypass);
          eqBands[eqBands.length - 1].connect(spatialPanner);
          
          spatialBypass.connect(compressor);
          spatialPanner.connect(spatialMix);
          spatialMix.connect(compressor);

          analyser = audioCtx.createAnalyser();
          analyser.fftSize = 128;
          visualizerDataArray = new Uint8Array(analyser.frequencyBinCount);
          compressor.connect(analyser);
          analyser.connect(audioCtx.destination);

          sourceA = audioCtx.createMediaElementSource(audio);
          sourceB = audioCtx.createMediaElementSource(audioB);
          gainA = audioCtx.createGain();
          gainB = audioCtx.createGain();
          perSongGain = audioCtx.createGain();
          perSongGain.gain.value = 1;
          
          sourceA.connect(gainA);
          sourceB.connect(gainB);
          gainA.connect(perSongGain);
          gainB.connect(perSongGain);
          perSongGain.connect(eqBands[0]);
          
          toggleAudioEnhancements();
        };

        const applyAudioSettings = () => {
          if (!audioCtx || !currentSong) return;
          
          let targetVol = parseFloat(globalVolumeMultiplier);
          let targetEQ = globalEQBands.map(v => parseFloat(v) || 0);

          if (currentSong.replaygain && enableNormalization) {
            const rgLinear = Math.pow(10, parseFloat(currentSong.replaygain) / 20);
            targetVol *= Math.max(0.1, Math.min(rgLinear, 3.0));
          }

          if (currentSong.volume_multiplier !== null && currentSong.volume_multiplier !== undefined) {
            targetVol = parseFloat(currentSong.volume_multiplier);
          }
          
          if (currentSong.eq_bands) {
            try {
              const parsedEq = typeof currentSong.eq_bands === 'string' ? JSON.parse(currentSong.eq_bands) : currentSong.eq_bands;
              if (Array.isArray(parsedEq) && parsedEq.length === 5) {
                targetEQ = parsedEq.map(v => parseFloat(v) || 0);
              }
            } catch (err) {
              console.error("EQ Parse Error:", err);
            }
          } else if (!isEQEnabled) {
            targetEQ = [0, 0, 0, 0, 0];
          }

          if (audioCtx.state === 'suspended') {
            audioCtx.resume();
          }

          setTimeout(() => {
            if (perSongGain && perSongGain.gain) {
              perSongGain.gain.setTargetAtTime(targetVol, audioCtx.currentTime, 0.05);
            }
            eqBands.forEach((band, i) => {
              if (band && band.gain) {
                band.gain.setTargetAtTime(targetEQ[i], audioCtx.currentTime, 0.05);
              }
            });
          }, 50);
        };

        const toggleAudioEnhancements = () => {
          if (!audioCtx) return;
          compressor.ratio.value = enableNormalization ? 12 : 1; 
          
          // Direct value assignment to avoid context freezing bugs
          spatialBypass.gain.value = isSpatialEnabled ? 0 : 1;
          spatialMix.gain.value = isSpatialEnabled ? 1 : 0;

          applyAudioSettings();
        };

        // UI Event Listeners for Global Audio Settings
        document.getElementById('toggle-spatial').addEventListener('change', (e) => {
          isSpatialEnabled = e.target.checked;
          toggleAudioEnhancements();
          saveGlobalAudioSettings();
        });
        document.getElementById('global-vol-slider').addEventListener('input', (e) => {
          globalVolumeMultiplier = parseFloat(e.target.value);
          document.getElementById('global-vol-val').textContent = globalVolumeMultiplier + 'x';
          applyAudioSettings();
          saveGlobalAudioSettings();
        });

        document.getElementById('toggle-normalization').addEventListener('change', (e) => {
          enableNormalization = e.target.checked;
          toggleAudioEnhancements();
          saveGlobalAudioSettings();
        });

        document.getElementById('toggle-eq').addEventListener('change', (e) => {
          isEQEnabled = e.target.checked;
          document.getElementById('eq-sliders').classList.toggle('d-none', !isEQEnabled);
          toggleAudioEnhancements();
          saveGlobalAudioSettings();
        });

        document.querySelectorAll('#eq-sliders .eq-band').forEach(slider => {
          slider.addEventListener('input', (e) => {
            if (!audioCtx) initWebAudio();
            const bandIndex = parseInt(e.target.dataset.band);
            globalEQBands[bandIndex] = parseFloat(e.target.value);
            document.getElementById('eq-preset-select').value = 'Custom';
            applyAudioSettings();
            saveGlobalAudioSettings();
          });
        });

        document.getElementById('crossfade-slider').addEventListener('input', (e) => {
          crossfadeDuration = parseFloat(e.target.value);
          document.getElementById('crossfade-val').textContent = crossfadeDuration + 's';
          saveGlobalAudioSettings();
        });

        document.getElementById('eq-preset-select').addEventListener('change', (e) => {
          if (e.target.value === 'Custom') return;
          const preset = EQ_PRESETS[e.target.value];
          if (preset) {
            globalEQBands = [...preset];
            const eqSliders = document.querySelectorAll('#eq-sliders .eq-band');
            globalEQBands.forEach((val, i) => { if (eqSliders[i]) eqSliders[i].value = val; });
            if (!audioCtx) initWebAudio();
            applyAudioSettings();
            saveGlobalAudioSettings();
          }
        });

        document.body.addEventListener('click', initWebAudio, { once: true });
        let currentView = { type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' };
        let currentUser = null;
        let currentViewOwnerId = null;
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = localStorage.getItem('isShuffle') === 'true';
        let repeatMode = localStorage.getItem('repeatMode') || 'none';
        let queueDirty = true;
        let deferredInstallPrompt = null;
        let sortable = null;
        let songIdForPlaylist = null;
        let mixIdForPlaylist = null;
        let contextMenuItemEl = null;
        let previousVolume = 1;
        let cachedExploreData = null;
        let offlineViewSongsData = [];
        let currentLrcData = null;
        let currentLrcSongId = null;
        let currentLyricIndex = -1;
        let globalSongCache = {};
        let offlineSongsSet = new Set();
        let listenLaterSet = new Set();
        let renderedQueueCount = 0;
        let isQueueLoading = false;
        let sleepTimerInterval = null;
        let sleepTimerEndTime = 0;
        let wakeLockSentinel = null;
        let sleepTimerKeepAwake = false;
        
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

        const formatSongCount = (count) => {
          let num = parseInt(count, 10);
          if (isNaN(num)) return '0';
          if (num >= 1000000000000) {
            return (num / 1000000000000).toFixed(1).replace(/\.0$/, '') + 't';
          }
          if (num >= 1000000000) {
            return (num / 1000000000).toFixed(1).replace(/\.0$/, '') + 'b';
          }
          if (num >= 1000000) {
            return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'm';
          }
          if (num >= 1000) {
            return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
          }
          return num.toString();
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
        
        const escapeHTML = str => {
          if (!str) return '';
          return str.replace(/[&<>'"]/g, tag => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[tag]));
        };
        
        // Safely decodes HTML entities back into symbols (like I&#039;m -> I'm) before pasting them into Input fields.
        const decodeHTML = (html) => {
          if (!html) return '';
          const txt = document.createElement("textarea");
          txt.innerHTML = html;
          return txt.value;
        };

        // Parses DB content to active HTML at runtime (Applies [url] and @mentions)
        const parseUserText = (text) => {
          if (!text) return '';
          let parsed = text;
          // Safely transform explicit [url] tags into active clickable links, auto-appending https:// if missing!
          parsed = parsed.replace(/\[url\]\s*([^\s<>\[\]]+)\s*\[\/url\]/gi, function(match, url) {
            let href = url.match(/^https?:\/\//i) ? url : 'https://' + url;
            return `<a href="${href}" target="_blank" class="text-info text-decoration-none hover-underline">${url}</a>`;
          });
          // Convert mentions
          parsed = parsed.replace(/@([\w\.\-_]+)/g, '<strong class="text-info mention-link" data-artist="$1" style="cursor:pointer;" title="Go to Profile">@$1</strong>');
          return parsed;
        };

        // Global Event listener for toggling nested replies in Comments/Community
        document.addEventListener('click', e => {
          const toggleBtn = e.target.closest('.toggle-replies-btn');
          if (toggleBtn) {
            const targetId = toggleBtn.dataset.target;
            const container = document.getElementById(targetId);
            if (container) {
              container.classList.toggle('d-none');
              const icon = toggleBtn.querySelector('i');
              if (container.classList.contains('d-none')) {
                icon.classList.replace('bi-chevron-up', 'bi-chevron-down');
                toggleBtn.innerHTML = `<i class="bi bi-chevron-down"></i> View ${container.children.length} replies`;
              } else {
                icon.classList.replace('bi-chevron-down', 'bi-chevron-up');
                toggleBtn.innerHTML = `<i class="bi bi-chevron-up"></i> Hide replies`;
              }
            }
          }
        });

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

        const fetchData = async (url, options = {}, silent = false) => {
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
            if (!silent) showToast(error.message, 'error');
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
          let decodedText = text;
          try {
            decodedText = decodeURIComponent(text.replace(/\+/g, ' '));
          } catch (e) {}

          if (decodedText) {
            document.title = decodedText + ' - PHP Music';
          }

          if (!show) {
            contentTitle.classList.add('d-none');
            return;
          }
          contentTitle.classList.remove('d-none');
          contentTitle.textContent = decodedText;
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
          const songItem = e.target.closest('.song-item');
          if (!songItem || e.target.closest('.song-more')) return;
          
          isDragging = false;
          if (e.touches && e.touches.length > 0) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
          }
          
          holdTimer = setTimeout(() => {
            if (isDragging) return;
            if (!currentUser) {
              showToast('Please log in to use multi-select mode.', 'error');
              return;
            }
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
            if (songItem && !moreBtn) {
              if (!currentUser) {
                showToast('Please log in to use multi-select mode.', 'error');
                return;
              }
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

        document.getElementById('multi-select-all-btn').addEventListener('click', () => {
          const songItems = document.querySelectorAll('#content-area .song-item');
          if (songItems.length === 0) return;
          
          let allSelected = true;
          songItems.forEach(item => {
            if (!selectedSongs.has(item.dataset.songId)) allSelected = false;
          });

          if (allSelected) {
            selectedSongs.clear();
            songItems.forEach(item => item.classList.remove('multi-selected'));
          } else {
            songItems.forEach(item => {
              const songId = item.dataset.songId;
              if (songId) {
                selectedSongs.add(songId);
                item.classList.add('multi-selected');
              }
            });
          }
          updateMultiSelectUI();
        });

        document.getElementById('multi-add-favorite-btn').addEventListener('click', async () => {
          if (!currentUser || selectedSongs.size === 0) return;
          let added = 0;
          
          const favBtn = document.getElementById('multi-add-favorite-btn');
          const originalBtnHtml = favBtn.innerHTML;
          favBtn.innerHTML = '<i class="bi bi-hourglass-split text-danger" style="animation: spinner-border 1s linear infinite;"></i> <span class="text-white">Adding...</span>';
          
          for (let songId of selectedSongs) {
            let isFav = false;
            if (globalSongCache[songId]) {
              isFav = globalSongCache[songId].is_favorite == 1;
            } else {
              const el = document.querySelector(`.song-item[data-song-id="${songId}"]`);
              if (el && el.dataset.isFavorite === '1') isFav = true;
            }

            if (!isFav) {
              const result = await fetchData('?action=toggle_favorite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(songId) })
              });
              
              if (result && result.is_favorite) {
                added++;
                if (globalSongCache[songId]) globalSongCache[songId].is_favorite = 1;
                
                const songItemsInView = document.querySelectorAll(`.song-item[data-song-id="${songId}"]`);
                songItemsInView.forEach(item => {
                  item.dataset.isFavorite = "1";
                });
                
                if (currentSong && currentSong.id == songId) {
                  currentSong.is_favorite = 1;
                  updateFavoriteIcons(true);
                }
              }
            }
          }
          
          favBtn.innerHTML = originalBtnHtml;
          if (added > 0) {
            showToast(`Added ${added} new songs to favorites!`, 'success');
          } else {
            showToast(`Selected songs are already in favorites.`, 'info');
          }
          selectedSongs.clear();
          updateMultiSelectUI();
        });

        const recacheOfflineSong = async (id) => {
          let tco = document.querySelector('.toast-container');
          if (!tco) {
            tco = document.createElement('div');
            tco.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            tco.style.zIndex = "1100";
            document.body.appendChild(tco);
          }
          const pToast = document.createElement('div');
          pToast.className = 'toast align-items-center text-white bg-warning text-dark border-0 show mb-2';
          pToast.innerHTML = `<div class="d-flex"><div class="toast-body" id="re-offline-progress-${id}">Re-caching song (0%)...</div></div>`;
          tco.appendChild(pToast);

          try {
            const cache = await caches.open('php-music-offline');
            
            const keys = await cache.keys();
            for (let req of keys) {
              const u = new URL(req.url);
              if (u.searchParams.get('id') == id) await cache.delete(req);
            }
            
            const v = globalSongCache[id] ? (globalSongCache[id].last_modified || 0) : 0;
            await cache.add(`?action=get_image&id=${id}&v=${v}`);
            await cache.add(`?action=get_song_data&id=${id}`);

            const response = await fetch(`?action=get_stream&id=${id}`);
            const contentLength = response.headers.get('content-length');
            
            if (!contentLength) {
              await cache.put(`?action=get_stream&id=${id}`, response.clone());
            } else {
              const total = parseInt(contentLength, 10);
              let loaded = 0;
              const reader = response.body.getReader();
              const stream = new ReadableStream({
                async start(controller) {
                  while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    loaded += value.length;
                    const pct = Math.round((loaded / total) * 100);
                    const pctText = document.getElementById(`re-offline-progress-${id}`);
                    if(pctText) pctText.innerText = `Re-caching song (${pct}%)...`;
                    controller.enqueue(value);
                  }
                  controller.close();
                }
              });
              const newResponse = new Response(stream, { headers: response.headers, status: response.status, statusText: response.statusText });
              await cache.put(`?action=get_stream&id=${id}`, newResponse);
            }
            
            pToast.classList.replace('bg-warning', 'bg-success');
            pToast.classList.replace('text-dark', 'text-white');
            document.getElementById(`re-offline-progress-${id}`).innerText = 'Successfully re-cached!';
            setTimeout(() => pToast.remove(), 3000);
            
            const itemRow = document.querySelector(`.song-item[data-song-id="${id}"]`);
            if(itemRow) {
              itemRow.classList.remove('offline-missing');
              itemRow.style.opacity = '1';
              const warningIcon = itemRow.querySelector('.offline-missing-icon');
              if(warningIcon) warningIcon.remove();
            }
            offlineSongsSet.add(parseInt(id));
          } catch(e) {
            pToast.classList.replace('bg-warning', 'bg-danger');
            pToast.classList.replace('text-dark', 'text-white');
            document.getElementById(`re-offline-progress-${id}`).innerText = 'Failed to re-cache.';
            setTimeout(() => pToast.remove(), 3000);
          }
        };

        document.getElementById('multi-offline-btn').addEventListener('click', async () => {
          if (!currentUser || selectedSongs.size === 0) return;
          let processed = 0;
          const songIdsArray = Array.from(selectedSongs);
          
          const offlineBtn = document.getElementById('multi-offline-btn');
          offlineBtn.disabled = true;
          const originalBtnHtml = offlineBtn.innerHTML;
          offlineBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
          
          for (let songId of songIdsArray) {
            if (!offlineSongsSet.has(parseInt(songId))) {
               await fetchData('?action=toggle_offline', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(songId) }) });
            }
            await recacheOfflineSong(parseInt(songId));
            processed++;
          }
          
          offlineBtn.disabled = false;
          offlineBtn.innerHTML = originalBtnHtml;
          showToast(`Processed ${processed} songs for offline playback!`, 'success');
          selectedSongs.clear();
          updateMultiSelectUI();
        });

        document.getElementById('multi-remove-btn').addEventListener('click', async () => {
          if (!currentUser || selectedSongs.size === 0) return;
          if (['playlist_songs', 'get_favorites', 'get_offline_songs'].indexOf(currentView.type) === -1) {
            showToast('This action is only available inside a Playlist, Favorites, or Offline Library.', 'error');
            return;
          }
          if (!confirm('Are you sure you want to remove the selected songs from this view?')) return;

          let removed = 0;
          for (let songId of selectedSongs) {
            if (currentView.type === 'playlist_songs') {
              await fetchData('?action=remove_from_playlist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ song_id: parseInt(songId), playlist_public_id: decodeURIComponent(currentView.param) }) });
              removed++;
            } else if (currentView.type === 'get_favorites') {
              await fetchData('?action=toggle_favorite', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(songId) }) });
              if (globalSongCache[songId]) globalSongCache[songId].is_favorite = 0;
              if (currentSong && currentSong.id == songId) {
                currentSong.is_favorite = 0;
                updateFavoriteIcons(false);
              }
              removed++;
            } else if (currentView.type === 'get_offline_songs') {
              await fetchData('?action=toggle_offline', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(songId) }) });
              try {
                const cache = await caches.open('php-music-offline');
                const keys = await cache.keys();
                for (let req of keys) {
                  const u = new URL(req.url);
                  if (u.searchParams.get('id') == songId) await cache.delete(req);
                }
              } catch (e) {}
              offlineSongsSet.delete(parseInt(songId));
              removed++;
            }
          }

          showToast(`Removed ${removed} songs.`, 'success');
          selectedSongs.clear();
          updateMultiSelectUI();
          loadView(currentView); 
        });

        const buildPlaylistModalContent = (playlists) => {
          let html = `
            <div class="mb-3 d-flex gap-2">
              <input type="text" id="quick-create-playlist-input" class="form-control bg-transparent text-white border-secondary" placeholder="New Playlist Name">
              <button class="btn btn-danger" id="quick-create-playlist-btn"><i class="bi bi-plus"></i></button>
            </div>
          `;
          if (playlists && playlists.length > 0) {
            html += `
              <div class="list-group list-group-flush">
                ${playlists.map(p => `
                  <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 add-to-playlist-item my-1 p-2 rounded ${p.is_added == 1 ? 'bg-secondary text-white border-0 opacity-75' : 'bg-transparent text-white border border-secondary'}" data-playlist-id="${p.id}" ${p.is_added == 1 ? 'disabled' : ''}>
                    <img src="?action=get_image&id=${p.image_id || 0}" class="rounded" style="width: 48px; height: 48px; object-fit: cover; background-color: var(--ytm-surface-2);">
                    <div class="d-flex flex-column flex-grow-1 text-start overflow-hidden">
                      <span class="text-truncate fw-medium">${escapeHTML(p.name)}</span>
                      <span class="small ${p.is_added == 1 ? 'text-white-50' : 'text-secondary'}">${p.song_count} songs</span>
                    </div>
                    ${p.is_added == 1 ? '<span class="badge bg-success">Already Added</span>' : '<i class="bi bi-plus-circle fs-5"></i>'}
                  </button>
                `).join('')}
              </div>
            `;
          } else {
            html += `<p class="text-secondary text-center p-4">No playlists found. Create one above!</p>`;
          }
          return html;
        };

        const reRenderPlaylistModal = async () => {
           const songId = (songIdForPlaylist && songIdForPlaylist.length === 1) ? songIdForPlaylist[0] : '0';
           const playlists = await fetchData(`?action=get_user_playlists&song_id=${songId}&sort=modified_desc`);
           addToPlaylistModalBody.innerHTML = buildPlaylistModalContent(playlists);
        };

        document.getElementById('multi-add-playlist-btn').addEventListener('click', async () => {
          songIdForPlaylist = Array.from(selectedSongs);
          mixIdForPlaylist = null;
          await reRenderPlaylistModal();
          addToPlaylistModal.show();
          selectedSongs.clear();
          updateMultiSelectUI();
        });
        
        const renderViewDetailsHeader = (details, type, songsList = []) => {
          currentViewOwnerId = details ? details.user_id : null;
          let typeText = type.charAt(0).toUpperCase() + type.slice(1);
          let statsText = `${formatSongCount(details.song_count || 0)} songs &bull; ${formatTime(details.total_duration || 0)}`;
          if (details.play_count !== undefined && details.play_count !== null) {
            statsText += ` &bull; ${formatSongCount(details.play_count)} plays`;
          }
          if (details.followers_count !== undefined && details.followers_count !== null) {
            statsText += ` &bull; ${formatSongCount(details.followers_count)} followers`;
          }
          let shareButtonHTML = '', copyButtonHTML = '', downloadButtonHTML = '', downloadExportPlaylistZipButtonHTML = '';
          
          let shareId = '';
          let shareName = encodeURIComponent(details.name);
          
          // ADVANCED ALBUM ARTIST MATCHER
          let rawArtistId = currentView.filter_user_id || details.user_id;
          if (!rawArtistId && songsList && songsList.length > 0) {
            rawArtistId = songsList[0].user_id;
          }
          let artistIdForShare = parseInt(rawArtistId, 10);
          if (isNaN(artistIdForShare) || artistIdForShare <= 0) {
            artistIdForShare = '';
          }

          if (type === 'playlist') {
            typeText = `Playlist by ${escapeHTML(details.creator)}`;
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
            shareId = artistIdForShare ? artistIdForShare : shareName; 
            document.title = `${details.name} - Artist - PHP Music`;
          } else if (type === 'album') {
            shareId = shareName;
            if (!artistIdForShare && details.user_id) artistIdForShare = details.user_id; 
            document.title = `${details.name} - Album - PHP Music`;
          } else if (type === 'profile') {
            typeText = 'My Profile';
            document.title = `${details.name} - PHP Music`;
          } else {
             document.title = `${details.name} - PHP Music`;
          }
          
          if(type !== 'profile'){
            let shareArtistName = currentView.artist_name || '';
            if (!shareArtistName && songsList && songsList.length > 0) {
              shareArtistName = songsList[0].artist;
            }
            shareButtonHTML = `
              <button class="btn btn-outline-light border-0 share-view-btn" title="Share ${type}" data-share-type="${type}" data-share-id="${shareId}" data-share-name="${shareName}" data-artist-id="${artistIdForShare}" data-artist-name="${encodeURIComponent(shareArtistName)}">
                <i class="bi bi-share-fill"></i> <span class="d-none d-md-inline">Share</span>
              </button>`;
          }

          let followButtonHTML = '';
          let messageButtonHTML = '';
          let blockButtonHTML = '';

          if (type === 'artist' && details.is_user && currentUser && currentUser.id !== details.user_id) {
            const followText = details.is_following ? 'Unfollow' : 'Follow';
            const followClass = details.is_following ? 'btn-outline-light' : 'btn-danger';
            followButtonHTML = `<button class="btn ${followClass} border-0 follow-btn" data-user-id="${details.user_id}">${followText}</button>`;
             
            messageButtonHTML = `<button class="btn btn-outline-light border-0 message-btn" data-user-id="${details.user_id}" data-artist="${encodeURIComponent(details.name)}" title="Message User"><i class="bi bi-chat-dots-fill"></i> <span class="d-none d-md-inline">Message</span></button>`;
             
            const blockText = details.is_blocked ? 'Unblock' : 'Block';
            const blockClass = details.is_blocked ? 'text-danger' : 'text-secondary';
            blockButtonHTML = `<button class="btn btn-outline-light border-0 block-btn" data-user-id="${details.user_id}" title="${blockText} User"><i class="bi bi-slash-circle-fill ${blockClass}"></i> <span class="d-none d-md-inline">${blockText}</span></button>`;
          }

          // Make Connections Clickable if it's an artist/profile
          let finalStatsText = statsText;
          if (type === 'artist' || type === 'profile') {
            finalStatsText = `${formatSongCount(details.song_count || 0)} songs &bull; ${formatTime(details.total_duration || 0)}
              &bull; <a href="#" class="text-info text-decoration-none connection-trigger" data-id="${details.user_id}" data-type="followers">${formatSongCount(details.followers_count || 0)} followers</a>
              &bull; <a href="#" class="text-info text-decoration-none connection-trigger" data-id="${details.user_id}" data-type="following">${formatSongCount(details.following_count || 0)} following</a>`;
          }

          const bioHTML = (details.bio && (type === 'artist' || type === 'profile')) ? 
            `<div class="mt-2 text-white" style="font-size: 0.95rem; white-space: pre-wrap; max-width: 800px;">${parseUserText(details.bio)}</div>` : '';

          const headerHTML = `
            <div class="view-details-header position-relative overflow-hidden" style="min-height: 250px; background-color: var(--ytm-surface);">
              ${(type === 'profile' || type === 'artist') && details.background_url ? `<div class="position-absolute w-100 h-100 top-0 start-0" style="background-image: url('${details.background_url}'); background-size: cover; background-position: center; filter: brightness(0.4) blur(2px); z-index: 0;"></div>` : ''}
              <div class="d-flex flex-column flex-md-row align-items-center align-items-md-end gap-4 position-relative w-100" style="z-index: 1;">
                <img src="${details.image_url}" alt="${escapeHTML(details.name)}" class="${(type === 'profile' || type === 'artist') ? 'profile-picture-lg' : 'rounded'}" style="width: 220px; height: 220px; box-shadow: 0 8px 30px rgba(0,0,0,0.7);">
                <div class="view-details-header-info text-center text-md-start">
                  <div class="type text-uppercase fw-bold mb-2" style="letter-spacing: 2px; color: rgba(255,255,255,0.8); text-shadow: 0 2px 4px rgba(0,0,0,0.5);">${typeText}</div>
                  <h2 class="name text-white fw-bold mb-3" style="font-size: clamp(2rem, 6vw, 4.5rem); line-height: 1.1; white-space: normal !important; word-break: break-word; text-shadow: 0 4px 12px rgba(0,0,0,0.6);">${escapeHTML(details.name)}</h2>
                  <div class="stats mb-2" style="color: rgba(255,255,255,0.9); font-size: 1rem; text-shadow: 0 1px 3px rgba(0,0,0,0.5);">${finalStatsText}</div>
                  ${bioHTML}
                </div>
                <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-start gap-2 mt-4 mt-md-0 ms-md-auto align-self-md-end">
                  ${followButtonHTML}
                  ${messageButtonHTML}
                  ${blockButtonHTML}
                  ${copyButtonHTML}
                  ${shareButtonHTML}
                  ${downloadButtonHTML}
                  ${downloadExportPlaylistZipButtonHTML}
                </div>
              </div>
              <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 100px; background: linear-gradient(to top, var(--ytm-bg), transparent); z-index: 0;"></div>
            </div>
            <div id="recommendation-alert-container"></div>
          `;
          contentArea.insertAdjacentHTML('afterbegin', headerHTML);

          // Extract color and apply beautiful gradient background
          const headerImg = new Image();
          headerImg.crossOrigin = 'anonymous';
          headerImg.onload = () => {
             const rgb = getAverageColor(headerImg);
             const headerBg = document.getElementById('dynamic-view-header');
             if (headerBg && !details.background_url) { // Prioritize user bg image if it exists
               headerBg.style.background = `linear-gradient(135deg, rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.7) 0%, rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.2) 50%, var(--ytm-bg) 100%)`;
             } else if (headerBg && details.background_url) {
               // If bg exists, just tint it slightly to match the theme
               headerBg.style.boxShadow = `inset 0 0 150px rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.3)`;
             }
          };
          headerImg.src = details.image_url + (details.image_url.includes('?') ? '&' : '?') + 't=' + new Date().getTime();

          if (type === 'artist' || type === 'profile') {
            const isUser = details.is_user || type === 'profile';
            const hasPlaylists = details.playlists && details.playlists.length > 0;
            
            // Build the Desktop Sidebar for Recommended content
            let recommendedSidebarHTML = ``;
            if (details.recommended_artists && details.recommended_artists.length > 0) {
              recommendedSidebarHTML += `
                <div class="d-flex flex-column" style="min-height: 0; flex: 1 1 40%;">
                  <h6 class="text-white fw-bold border-bottom border-secondary pb-2 mb-2 text-uppercase d-flex align-items-center gap-2" style="letter-spacing: 1px; font-size: 0.85rem;"><i class="bi bi-stars text-warning fs-5"></i> Similar Artists</h6>
                  <div class="list-group list-group-flush bg-transparent overflow-auto" style="scrollbar-width: thin; padding-right: 4px; flex-grow: 1;">
                    ${details.recommended_artists.map(a => `
                      <div class="list-group-item list-group-item-action bg-transparent text-white border-0 rounded px-2 py-2 mb-1 d-flex align-items-center gap-3 user-profile-link" data-userid="${a.id}" data-artist="${encodeURIComponent(a.artist)}" style="cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.08)'" onmouseout="this.style.backgroundColor='transparent'">
                        <img src="?action=get_profile_picture&id=${a.id}" class="rounded-circle shadow-sm" style="width: 48px; height: 48px; object-fit: cover; border: 1px solid rgba(255,255,255,0.1);">
                        <div class="fw-bold text-truncate" style="font-size: 0.95rem;">${escapeHTML(a.artist)}</div>
                      </div>
                    `).join('')}
                  </div>
                </div>
              `;
            }
            if (details.recommended_songs && details.recommended_songs.length > 0) {
              recommendedSidebarHTML += `
                <div class="d-flex flex-column" style="min-height: 0; flex: 1 1 60%;">
                  <h6 class="text-white fw-bold border-bottom border-secondary pb-2 mb-2 text-uppercase d-flex align-items-center gap-2" style="letter-spacing: 1px; font-size: 0.85rem;"><i class="bi bi-fire text-danger fs-5"></i> Popular Tracks</h6>
                  <div class="list-group list-group-flush bg-transparent overflow-auto" style="scrollbar-width: thin; padding-right: 4px; flex-grow: 1;">
                    ${details.recommended_songs.map(s => `
                      <div class="list-group-item list-group-item-action bg-transparent text-white border-0 rounded px-2 py-2 mb-1 d-flex align-items-center gap-3 top-result-card" data-song-id="${s.id}" style="cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.08)'" onmouseout="this.style.backgroundColor='transparent'">
                        <img src="?action=get_image&id=${s.id}&v=${s.last_modified || 0}" class="rounded shadow-sm" style="width: 48px; height: 48px; object-fit: cover; border: 1px solid rgba(255,255,255,0.1);">
                        <div class="d-flex flex-column text-truncate justify-content-center">
                          <div class="fw-bold text-truncate" style="font-size: 0.95rem;">${escapeHTML(s.title)}</div>
                          <div class="text-secondary text-truncate" style="font-size: 0.8rem;">${escapeHTML(s.artist)}</div>
                        </div>
                      </div>
                    `).join('')}
                  </div>
                </div>
              `;
            }
            
            let tabsHTML = `
              <div class="row mt-4">
                <div class="col-12 col-lg-8">
                  <ul class="nav nav-tabs mb-3" id="artistTabs" role="tablist">
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
                      <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-4">
                        ${details.playlists.map(p => `
                          <div class="col">
                            <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-playlist="${encodeURIComponent(p.public_id)}" style="cursor: pointer;">
                              ${(currentUser && (currentUser.id == details.user_id || currentUser.email === 'musiclibrary@mail.com')) ? `<button class="playlist-more-btn" data-public-id="${p.public_id}" data-name="${escapeHTML(p.name)}" data-is-collab="${p.is_collaborative || 0}" data-is-private="${p.is_private || 0}" data-owner-id="${details.user_id}"><i class="bi bi-three-dots-vertical"></i></button>` : ''}
                              <div style="position: relative; display: block; border-radius: 6px; overflow: hidden;">
                                <img src="?action=get_image&id=${p.image_id || 0}" class="card-img-top" alt="${escapeHTML(p.name)}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2); margin-bottom: 0 !important;">
                                ${p.song_count !== undefined ? `<div class="position-absolute bottom-0 start-0 ms-2 mb-1 px-2 py-1 bg-dark bg-opacity-75 text-white rounded fw-bold" style="font-size: 0.75rem; backdrop-filter: blur(4px); line-height: 1;"><i class="bi bi-music-note-list"></i> ${formatSongCount(p.song_count)}</div>` : ''}
                              </div>
                              <div class="card-body px-0 py-2">
                                <h5 class="card-title fs-6 fw-normal text-truncate">${escapeHTML(p.name)}</h5>
                              </div>
                            </div>
                          </div>
                        `).join('')}
                      </div>
                      ` : `<div class="text-center p-5 text-secondary">No playlists found.</div>`}
                    </div>` : ''}
                  </div>
                </div>
                <div class="col-12 col-lg-4 d-none d-lg-block">
                  <div class="p-3 rounded shadow-sm sticky-top d-flex flex-column gap-3" style="top: 100px; height: calc(100vh - 210px); background-color: var(--ytm-surface-2); border: 1px solid rgba(255,255,255,0.05);">
                    ${recommendedSidebarHTML || '<div class="text-center text-secondary small m-auto">No recommendations yet.</div>'}
                  </div>
                </div>
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
            const header = `<div class="song-list-header d-none d-md-grid" style="grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) ${isHistory ? 'minmax(0, 2fr)' : ''} 80px 40px;">
              <div></div><div>Title</div><div>Artist</div><div>Album</div><div>Views</div>${isHistory ? '<div>Played</div>' : ''}<div>Time</div><div></div>
            </div>`;
            targetContainer.insertAdjacentHTML('beforeend', header);
            targetContainer.appendChild(songList);
          }
          
          const escapeAttr = (str) => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';

          const songsHTML = songs.map((song) => {
            globalSongCache[song.id] = song;
            const isNowPlaying = currentSong && currentSong.id === song.id;
            const isHistory = currentView.type === 'get_history';
            const playedAtHTML = isHistory ? `<div class="played-at-text text-truncate" title="${timeAgo(song.played_at)}">${timeAgo(song.played_at)}</div>` : '';
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
                <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                <i class="bi bi-soundwave playing-icon"></i>
              </div>
              <div class="song-title-wrapper text-truncate"><div class="song-title text-truncate">${song.is_private == 1 ? '<i class="bi bi-lock-fill text-warning me-1" title="Private Song"></i>' : ''}${escapeHTML(song.title)}</div></div>
              <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">
                <div class="text-truncate">${escapeHTML(song.artist)}</div>
                <div class="text-secondary d-md-none" style="font-size: 0.8rem; margin-top: 2px;" title="Play Count"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
                ${song.added_by_name ? `<div style="font-size: 0.7rem; color: var(--ytm-secondary-text); margin-top: 2px;">Added by: ${escapeHTML(song.added_by_name)}</div>` : ''}
              </div>
              <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}" data-userid="${song.user_id}">${escapeHTML(song.album)}</div>
              <div class="song-views d-none d-md-block text-truncate" title="${song.play_count || 0} views">${formatSongCount(song.play_count || 0)}</div>
              ${isHistory ? `<div class="d-none d-md-block text-truncate">${playedAtHTML}</div>` : ''}
              <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
              <div class="song-more">
                <button class="more-btn" data-song-id="${song.id}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
              </div>
              <div class="song-artist-mobile w-100 flex-column align-items-start">
                <div class="d-flex justify-content-between align-items-center w-100">
                   <span class="song-artist-name text-truncate flex-grow-1" style="min-width: 0;">${escapeHTML(song.artist)}</span>
                   ${isHistory ? playedAtHTML : ''}
                   <span class="song-duration-mobile flex-shrink-0">${formatTime(song.duration)}</span>
                </div>
                <div class="text-secondary text-start" style="font-size: 0.8rem; margin-top: 2px;"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
              </div>
            </div>
          `}).join('');
          
          songList.insertAdjacentHTML('beforeend', songsHTML);
          
          // Verify cache and show warning indicator for missing offline files
          if (currentView.type === 'get_offline_songs') {
            caches.open('php-music-offline').then(cache => {
              const addedItems = songList.querySelectorAll('.song-item:not(.cache-checked)');
              addedItems.forEach(async item => {
                item.classList.add('cache-checked');
                const sid = item.dataset.songId;
                const req = await cache.match(`?action=get_stream&id=${sid}`, { ignoreSearch: false, ignoreVary: true });
                if (!req) {
                  item.classList.add('offline-missing');
                  item.style.opacity = '0.4';
                  const titleWrapper = item.querySelector('.song-title-wrapper');
                  if (titleWrapper && !titleWrapper.querySelector('.offline-missing-icon')) {
                     titleWrapper.insertAdjacentHTML('beforeend', ' <i class="bi bi-cloud-slash-fill text-warning offline-missing-icon" title="Not fully cached. Please Re-download." style="font-size: 0.85rem; cursor: help;"></i>');
                  }
                }
              });
            });
          }

          const isSortableFavorites = currentView.type === 'get_favorites' && currentView.sort === 'manual_order';
          const isSortablePlaylist = currentView.type === 'playlist_songs' && currentView.sort === 'manual_order';
          const isSortableOffline = currentView.type === 'get_offline_songs' && currentView.sort === 'manual_order';

          if ((isSortableFavorites || isSortablePlaylist || isSortableOffline) && !sortable) {
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
                
                const isSuperAdmin = currentUser && currentUser.email && currentUser.email.toLowerCase() === 'musiclibrary@mail.com';
                if (isSortablePlaylist && (!currentUser || (currentViewOwnerId !== currentUser.id && !isSuperAdmin))) {
                  showToast("You're not the owner of this playlist.", "error");
                  loadView(currentView);
                  return;
                }
                
                const songItems = Array.from(songList.querySelectorAll('.song-item'));
                const newOrderIds = songItems.map(item => item.dataset.songId);
                
                let action = 'update_playlist_order';
                if (isSortableFavorites) action = 'update_favorite_order';
                else if (isSortableOffline) action = 'update_offline_order';

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
            contentArea.innerHTML = `
              <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-music-note-list text-danger me-3 fs-3"></i> Manage Playlists</div>
                <div class="d-flex gap-2 flex-wrap">
                  <button class="btn btn-danger rounded-pill px-4 fw-medium shadow-sm" id="create-new-playlist-btn"><i class="bi bi-plus-lg me-1"></i> Create</button>
                  <button class="btn btn-outline-light rounded-pill px-4 fw-medium shadow-sm" id="import-playlist-btn"><i class="bi bi-box-arrow-in-down me-1"></i> Import</button>
                </div>
              </div>`;
          } else if (type === 'get_collab_playlists' && !append && currentUser) {
            contentArea.innerHTML = `
              <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-people-fill text-info me-3 fs-3"></i> Shared With Me</div>
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
              } else if (type === 'get_user_playlists' || type === 'get_collab_playlists') {
                name = item.name;
                subtext = type === 'get_collab_playlists' ? `by ${item.creator} • ${formatSongCount(item.song_count)} songs` : `${formatSongCount(item.song_count)} songs`;
                imageId = item.image_id;
                dataType = 'playlist';
                dataValue = item.public_id;
                publicId = item.public_id;
              } else if (type === 'get_genres' || type === 'get_years') {
                name = item.name;
                subtext = null;
                imageId = item.id;
                dataType = type === 'get_years' ? 'year' : 'genre';
                dataValue = name;
                publicId = '';
              } else {
                name = item.name || item;
                subtext = null;
                dataType = type.replace('get_','').slice(0, -1);
                dataValue = name;
                icon = 'bi-tag-fill';
                publicId = '';
              }
              
              const useridAttr = item.user_id ? `data-userid="${item.user_id}"` : (type === 'get_following' ? `data-userid="${item.id}"` : '');
              const artistNameAttr = (type === 'get_albums' && item.artist) ? `data-artistname="${encodeURIComponent(item.artist)}"` : '';

              const moreButton = (type === 'get_user_playlists' || type === 'get_collab_playlists') ? `
                <button class="playlist-more-btn" data-public-id="${publicId}" data-name="${name}" data-is-collab="${item.is_collaborative || 0}" data-is-private="${item.is_private || 0}" data-owner-id="${item.owner_id || ''}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>` : '';

              if (type === 'get_albums' || type === 'get_user_playlists' || type === 'get_collab_playlists' || type === 'get_artists' || type === 'get_following' || type === 'get_genres' || type === 'get_years') {
                let songCountBadge = '';
                if ((type === 'get_albums' || type === 'get_user_playlists' || type === 'get_collab_playlists') && item.song_count !== undefined) {
                  songCountBadge = `<div class="position-absolute bottom-0 start-0 ms-2 mb-1 px-2 py-1 bg-dark bg-opacity-75 text-white rounded fw-bold" style="font-size: 0.75rem; backdrop-filter: blur(4px); line-height: 1;"><i class="bi bi-music-note-list"></i> ${formatSongCount(item.song_count)}</div>`;
                }
                
                let viewCountHtml = '';
                if (type === 'get_albums' && item.total_plays !== undefined) {
                  viewCountHtml = `<div class="card-text small text-secondary text-truncate mt-1" style="font-size: 0.75rem;"><i class="bi bi-eye"></i> ${formatSongCount(item.total_plays)} views</div>`;
                } else if ((type === 'get_user_playlists' || type === 'get_collab_playlists') && item.play_count !== undefined) {
                  viewCountHtml = `<div class="card-text small text-secondary text-truncate mt-1" style="font-size: 0.75rem;"><i class="bi bi-eye"></i> ${formatSongCount(item.play_count)} views</div>`;
                }

                // Determine whether it needs circle or standard card rounded corners
                let borderStyle = (imgClass === 'rounded-circle') ? 'border-radius: 50%;' : 'border-radius: 6px;';
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-${dataType}="${encodeURIComponent(dataValue)}" ${useridAttr} ${artistNameAttr} style="cursor: pointer;">
                    ${moreButton}
                    <div style="position: relative; display: block; ${borderStyle} overflow: hidden;">
                      <img src="${imgSrc}${imageId || 0}" class="card-img-top" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2); margin-bottom: 0 !important;">
                      ${songCountBadge}
                    </div>
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate ${titleClass}">
                        ${type === 'get_user_playlists' && item.is_private == 1 ? '<i class="bi bi-lock-fill text-warning me-1"></i>' : ''}${escapeHTML(name)}
                      </h5>
                      ${subtext ? `<p class="card-text small text-secondary text-truncate mb-0 ${subtextClass}">${escapeHTML(subtext)}</p>` : ''}
                      ${viewCountHtml}
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
                      <h5 class="card-title fs-6 fw-normal text-truncate">${escapeHTML(name)}</h5>
                    </div>
                  </div>
                </div>`;
              }
            }).join('');
          
          grid.insertAdjacentHTML('beforeend', itemsHTML);
        };
        
        const renderRecommendations = (data) => {
          contentArea.innerHTML = '';
          
          if (currentView.type === 'search') {
            const fd = currentView.f_date || '';
            const fdu = currentView.f_dur || '';
            const fs = currentView.f_sort || 'relevance';

            const filterHTML = `
              <div class="search-filters-container w-100 mb-4 px-2 order-first" style="grid-column: 1 / -1;">
                <button class="btn btn-outline-light rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#searchFiltersCollapse">
                  <i class="bi bi-sliders"></i> Filters
                </button>
                <div class="collapse mt-3" id="searchFiltersCollapse">
                  <div class="card card-body bg-dark border-secondary text-white d-flex flex-row flex-wrap gap-5" style="border-radius: 12px;">
                    <div class="d-flex flex-column gap-2">
                      <h6 class="border-bottom border-secondary pb-2 mb-1 fw-bold text-secondary">UPLOAD DATE</h6>
                      <a href="#" class="text-decoration-none ${fd === '' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_date" data-val="">Any time</a>
                      <a href="#" class="text-decoration-none ${fd === 'today' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_date" data-val="today">Today</a>
                      <a href="#" class="text-decoration-none ${fd === 'week' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_date" data-val="week">This week</a>
                      <a href="#" class="text-decoration-none ${fd === 'month' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_date" data-val="month">This month</a>
                      <a href="#" class="text-decoration-none ${fd === 'year' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_date" data-val="year">This year</a>
                    </div>
                    <div class="d-flex flex-column gap-2">
                      <h6 class="border-bottom border-secondary pb-2 mb-1 fw-bold text-secondary">DURATION</h6>
                      <a href="#" class="text-decoration-none ${fdu === '' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_dur" data-val="">Any</a>
                      <a href="#" class="text-decoration-none ${fdu === 'short' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_dur" data-val="short">Under 4 minutes</a>
                      <a href="#" class="text-decoration-none ${fdu === 'long' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_dur" data-val="long">Over 20 minutes</a>
                    </div>
                    <div class="d-flex flex-column gap-2">
                      <h6 class="border-bottom border-secondary pb-2 mb-1 fw-bold text-secondary">SORT BY</h6>
                      <a href="#" class="text-decoration-none ${fs === 'relevance' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_sort" data-val="relevance">Relevance</a>
                      <a href="#" class="text-decoration-none ${fs === 'date' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_sort" data-val="date">Upload date</a>
                      <a href="#" class="text-decoration-none ${fs === 'views' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_sort" data-val="views">View count</a>
                      <a href="#" class="text-decoration-none ${fs === 'likes' ? 'text-white fw-bold' : 'text-secondary hover-white'} filter-opt" data-filter="f_sort" data-val="likes">Rating (Songs only)</a>
                    </div>
                  </div>
                </div>
              </div>
            `;
            // Insert filters at the very top of content area
            contentArea.insertAdjacentHTML('afterbegin', filterHTML);
            
            document.querySelectorAll('.filter-opt').forEach(opt => {
              opt.addEventListener('click', (e) => {
                e.preventDefault();
                const key = opt.dataset.filter;
                const val = opt.dataset.val;
                currentView[key] = val;
                
                // Track if the collapse is currently open
                const collapseEl = document.getElementById('searchFiltersCollapse');
                const isExpanded = collapseEl && collapseEl.classList.contains('show');
                
                loadView(currentView);
                
                // Re-open collapse slightly after render completes
                if (isExpanded) {
                  setTimeout(() => {
                    const newCollapse = document.getElementById('searchFiltersCollapse');
                    if (newCollapse) newCollapse.classList.add('show');
                  }, 300);
                }
              });
            });
          }

          if (!data || !data.shelves || data.shelves.length === 0) {
            contentArea.insertAdjacentHTML('beforeend', `<div class="text-center p-5 text-secondary">No results found.</div>`);
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
                    <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; margin-right: 1.5rem; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
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
              const header = `<div class="song-list-header d-none d-md-grid" style="grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) 80px 40px;">
                <div></div><div>Title</div><div>Artist</div><div>Album</div><div>Views</div><div>Time</div><div></div>
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
                    <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" class="song-thumb" loading="lazy" alt="${escapeAttr(song.title)}">
                    <i class="bi bi-soundwave playing-icon"></i>
                  </div>
                  <div class="song-title-wrapper text-truncate"><div class="song-title text-truncate">${song.is_private == 1 ? '<i class="bi bi-lock-fill text-warning me-1" title="Private Song"></i>' : ''}${song.title}</div></div>
                  <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">
                    <div class="text-truncate">${song.artist}</div>
                    <div class="text-secondary d-md-none" style="font-size: 0.8rem; margin-top: 2px;" title="Play Count"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
                  </div>
                  <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}" data-userid="${song.user_id}">${song.album}</div>
                  <div class="song-views d-none d-md-block text-truncate" title="${song.play_count || 0} views">${formatSongCount(song.play_count || 0)}</div>
                  <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
                  <div class="song-more">
                    <button class="more-btn" data-song-id="${song.id}">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                  </div>
                  <div class="song-artist-mobile w-100 flex-column align-items-start">
                    <div class="d-flex justify-content-between align-items-center w-100">
                       <span class="song-artist-name text-truncate flex-grow-1" style="min-width: 0;">${song.artist}</span>
                       <span class="song-duration-mobile flex-shrink-0">${formatTime(song.duration)}</span>
                    </div>
                    <div class="text-secondary text-start" style="font-size: 0.8rem; margin-top: 2px;"><i class="bi bi-eye"></i> ${formatSongCount(song.play_count || 0)}</div>
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
                  <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" alt="${escapeHTML(song.title)}">
                  <div class="item-title">${escapeHTML(song.title)}</div>
                  <div class="item-subtitle" data-artist="${encodeURIComponent(song.artist)}" data-userid="${song.user_id}">${escapeHTML(song.artist)}</div>
                </div>
              `).join('');
            } else if (shelf.type === 'albums') {
              itemsHTML = shelf.items.map(album => `
                <div class="shelf-item" data-album="${encodeURIComponent(album.album)}" data-userid="${album.user_id || ''}">
                  <div style="position: relative; display: block; margin-bottom: 0.5rem; border-radius: 6px; overflow: hidden;">
                    <img src="?action=get_image&id=${album.id || 0}" alt="${escapeHTML(album.album)}" style="margin-bottom: 0 !important; border-radius: 0 !important;">
                    ${album.song_count !== undefined ? `<div class="position-absolute bottom-0 start-0 ms-1 mb-1 px-2 py-1 bg-dark bg-opacity-75 text-white rounded fw-bold" style="font-size: 0.7rem; backdrop-filter: blur(4px); line-height: 1;"><i class="bi bi-music-note-list"></i> ${formatSongCount(album.song_count)}</div>` : ''}
                  </div>
                  <div class="item-title">${escapeHTML(album.album)}</div>
                  <div class="item-subtitle">${escapeHTML(album.artist)}</div>
                  ${album.total_plays !== undefined ? `<div class="item-subtitle mt-1" style="font-size: 0.75rem;"><i class="bi bi-eye"></i> ${formatSongCount(album.total_plays)} views</div>` : ''}
                </div>
              `).join('');
            } else if (shelf.type === 'playlists' || shelf.type === 'mixes') {
              itemsHTML = shelf.items.map(playlist => `
                <div class="shelf-item" data-${shelf.type === 'mixes' ? 'mix' : 'playlist'}="${encodeURIComponent(playlist.public_id)}">
                  <div style="position: relative; display: block; margin-bottom: 0.5rem; border-radius: 6px; overflow: hidden;">
                    <img src="?action=get_image&id=${playlist.image_id || 0}" alt="${escapeHTML(playlist.name)}" style="margin-bottom: 0 !important; border-radius: 0 !important;">
                    ${playlist.song_count !== undefined ? `<div class="position-absolute bottom-0 start-0 ms-1 mb-1 px-2 py-1 bg-dark bg-opacity-75 text-white rounded fw-bold" style="font-size: 0.7rem; backdrop-filter: blur(4px); line-height: 1;"><i class="bi bi-music-note-list"></i> ${formatSongCount(playlist.song_count)}</div>` : ''}
                  </div>
                  <div class="item-title">${escapeHTML(playlist.name)}</div>
                  <div class="item-subtitle">by ${escapeHTML(playlist.creator)}</div>
                  ${playlist.play_count !== undefined ? `<div class="item-subtitle mt-1" style="font-size: 0.75rem;"><i class="bi bi-eye"></i> ${formatSongCount(playlist.play_count)} views</div>` : ''}
                </div>
              `).join('');
            } else if (shelf.type === 'artists') {
              itemsHTML = shelf.items.map(artist => `
                <div class="shelf-item text-center" data-artist="${encodeURIComponent(artist.name)}" data-userid="${artist.is_user ? artist.id : ''}">
                  <img src="${artist.is_user ? `?action=get_profile_picture&id=${artist.id}` : `?action=get_image&id=${artist.id || 0}`}" class="rounded-circle" alt="${escapeHTML(artist.name)}" style="aspect-ratio: 1/1; object-fit: cover;">
                  <div class="item-title mt-2">${escapeHTML(artist.name)}</div>
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
          const isSortable = ['get_favorites', 'get_listen_later', 'get_notes', 'get_community', 'get_offline_songs', 'artist_songs', 'album_songs', 'genre_songs', 'year_songs', 'user_profile', 'playlist_songs', 'get_history', 'get_albums', 'get_artists', 'get_user_playlists', 'get_collab_playlists'].includes(viewType);
          
          if (isSortable) {
            let options = {};
            
            switch(viewType) {
              case 'genre_songs':
              case 'year_songs':
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
              case 'get_listen_later':
              case 'get_offline_songs':
              case 'playlist_songs':
                options = { 
                  'manual_order': 'My Order', 'added_newest': 'Added Newest', 'added_oldest': 'Added Oldest',
                  'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album'
                };
                break;
              case 'get_history':
                options = { 
                  'history_desc': 'Recently Played', 'history_asc': 'Oldest Played',
                  'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album'
                };
                break;
              case 'get_notes':
                options = { 'newest': 'Newest', 'oldest': 'Oldest', 'modified': 'Recently Modified' };
                break;
              case 'get_community':
                options = { 'newest': 'Newest', 'most_liked': 'Most Liked', 'following': 'Following Users' };
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
              case 'get_collab_playlists':
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
          if (!currentUser && (currentView.type === 'get_favorites' || currentView.type === 'get_offline_songs' || currentView.type === 'get_history' || currentView.type === 'get_following')) return;
          
          isLoadingMore = true;
          showLoader(false);
          
          currentPage++;
          if (currentView.type === 'get_trending' && currentPage > 4) {
            allContentloaded = true;
            isLoadingMore = false;
            hideLoader();
            return;
          }
          let data;
          const { type, param, sort, filter_user_id, artist_name } = currentView;

          const params = new URLSearchParams({ page: currentPage, sort: sort });
          if (filter_user_id) {
            params.append('filter_user_id', filter_user_id);
          }
          if (artist_name) {
            params.append('artist_name', artist_name);
          }

          switch (type) {
            case 'get_songs':
            case 'get_favorites':
            case 'get_listen_later':
            case 'get_history':
            case 'get_trending':
              data = await fetchData(`?action=${type}&${params.toString()}`);
              renderSongs(data, true);
              break;
            case 'get_offline_songs':
              if (offlineViewSongsData && offlineViewSongsData.length > 0) {
                 const startIndex = (currentPage - 1) * PAGE_SIZE;
                 const endIndex = startIndex + PAGE_SIZE;
                 data = offlineViewSongsData.slice(startIndex, endIndex);
                 if (data.length > 0) {
                   renderSongs(data, true);
                 }
                 if (endIndex >= offlineViewSongsData.length) {
                   allContentloaded = true;
                 }
              } else {
                 data = [];
                 allContentloaded = true;
              }
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
            case 'year_songs':
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
            case 'get_years':
            case 'get_user_playlists':
            case 'get_collab_playlists':
            case 'get_following':
              data = await fetchData(`?action=${type}&${params.toString()}`);
              renderGrid(data, type, true);
              break;
            case 'get_notes':
              data = await fetchData(`?action=get_notes&${params.toString()}`);
              if (data && data.length > 0) {
                 const grid = document.getElementById('notes-grid');
                 if(grid) {
                   grid.insertAdjacentHTML('beforeend', data.map(n => `
                    <div class="col">
                      <div class="card h-100 bg-dark text-white border-secondary">
                        <div class="card-body">
                          <h5 class="card-title fw-bold text-truncate">${escapeHTML(n.title)}</h5>
                          <p class="card-text text-secondary small" style="white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden;">${parseUserText(n.content)}</p>
                        </div>
                        <div class="card-footer border-secondary d-flex justify-content-between align-items-center">
                          <small class="text-secondary">${timeAgo(n.updated_at)}</small>
                          <div>
                            <button class="btn btn-sm btn-outline-light me-1 edit-note-btn" data-id="${n.id}" data-title="${escapeHTML(n.title)}" data-content="${escapeHTML(n.content)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-note-btn" data-id="${n.id}"><i class="bi bi-trash"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>`).join(''));
                 }
              }
              break;
            case 'get_community':
              data = await fetchData(`?action=get_community&${params.toString()}`);
              if (data && data.length > 0) {
                 const feed = document.getElementById('community-feed');
                 if(feed) {
                   feed.insertAdjacentHTML('beforeend', data.map(p => `
                    <div class="card bg-transparent border-secondary text-white">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                          <div class="d-flex align-items-center gap-3 ${p.is_disabled ? '' : 'user-profile-link'}" data-userid="${p.user_id}" data-artist="${encodeURIComponent(p.artist)}" style="${p.is_disabled ? 'cursor: default;' : 'cursor: pointer;'}" ${p.is_disabled ? '' : 'title="View Profile"'}>
                            <img src="?action=get_profile_picture&id=${p.user_id}" style="width:40px; height:40px; border-radius:50%; object-fit:cover;">
                            <div>
                              <div class="fw-bold hover-underline">${escapeHTML(p.artist)}</div>
                              <small class="text-secondary">${timeAgo(p.created_at)}</small>
                            </div>
                          </div>
                          ${(currentUser && (currentUser.id == p.user_id || currentUser.email === 'musiclibrary@mail.com')) ? `
                          <div>
                            <button class="btn btn-sm btn-outline-light me-1 edit-post-btn" data-id="${p.id}" data-content="${escapeHTML(p.content)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-post-btn" data-id="${p.id}"><i class="bi bi-trash"></i></button>
                          </div>` : ''}
                        </div>
                        <p class="mb-3" style="font-size: 1.05rem;">${escapeHTML(p.content)}</p>
                        <div class="d-flex gap-3">
                          <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'like' ? 'text-info' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="like">
                            <i class="bi ${p.my_reaction === 'like' ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'} fs-5"></i> <span>${p.like_count}</span>
                          </button>
                          <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'dislike' ? 'text-danger' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="dislike">
                            <i class="bi ${p.my_reaction === 'dislike' ? 'bi-hand-thumbs-down-fill' : 'bi-hand-thumbs-down'} fs-5"></i> <span>${p.dislike_count}</span>
                          </button>
                        </div>
                      </div>
                    </div>`).join(''));
                 }
              }
              break;
            default:
              allContentloaded = true;
          }
          
          let countCheck = data ? data.length : 0;
          if (data && currentView.type === 'get_community') {
            countCheck = data.filter(i => i.parent_id == null).length; // Only count root items for infinite scroll bounds
          }
          if (!data || countCheck < PAGE_SIZE && currentView.type !== 'search') {
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
            case 'year_songs': activeLink = document.querySelector('.nav-link[data-view="get_years"]'); break;
            case 'playlist_songs': activeLink = document.querySelector('.nav-link[data-view="get_user_playlists"]'); break;
            default: activeLink = document.querySelector(`.nav-link[data-view="${viewType}"]`);
          }
          if (activeLink) {
            activeLink.classList.add('active');
          }
        };

        let viewDebounceTimer = null;
        let viewLoadCounter = 0;
        const loadView = async (viewConfig, pushHistory = true) => {
          viewLoadCounter++;
          const currentLoadId = viewLoadCounter;

          // DEBOUNCE: Prevent rapid firing of database queries on fast clicks
          if (viewDebounceTimer) clearTimeout(viewDebounceTimer);
          showLoader(true);
          
          await new Promise(resolve => {
            viewDebounceTimer = setTimeout(resolve, 200);
          });
          
          if (currentLoadId !== viewLoadCounter) return;

          if (pushHistory) {
            const isSameView = currentView.type === viewConfig.type &&
                               currentView.param === viewConfig.param &&
                               currentView.sort === viewConfig.sort &&
                               currentView.filter_user_id === viewConfig.filter_user_id &&
                               currentView.artist_name === viewConfig.artist_name;
            if (!isSameView) {
              history.pushState({ viewConfig }, "");
            }
          }

          selectedSongs.clear();
          updateMultiSelectUI();
          
          if (sortable) {
            sortable.destroy();
            sortable = null;
          }
          
          mainContent.scrollTop = 0;
          currentPage = 1;
          allContentloaded = false;
          isLoadingMore = false;
          currentViewOwnerId = null;
          showLoader();

          currentView = viewConfig;
          updateActiveNavLink(currentView.type);
          setupSortOptions(currentView.type);
          
          let data;
          let pageParams = new URLSearchParams({ sort: currentView.sort, page: 1 });
          if (currentView.filter_user_id) pageParams.append('filter_user_id', currentView.filter_user_id);
          if (currentView.artist_name) pageParams.append('artist_name', currentView.artist_name);
          if (currentView.f_date) pageParams.append('f_date', currentView.f_date);
          if (currentView.f_dur) pageParams.append('f_dur', currentView.f_dur);
          if (currentView.f_sort) pageParams.append('f_sort', currentView.f_sort);
          
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
            case 'get_offline_songs':
              updateContentTitle('Offline Music', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-cloud-arrow-down-fill text-info me-3 fs-3"></i> Offline Library</div>
                    <div class="d-flex gap-2 flex-wrap">
                      <button class="btn btn-warning rounded-pill px-4 fw-medium shadow-sm text-dark" id="recache-all-offline-btn"><i class="bi bi-arrow-repeat me-1"></i> Re-cache All</button>
                      <a href="?action=export_offline" class="btn btn-outline-light rounded-pill px-4 fw-medium shadow-sm" id="export-offline-btn"><i class="bi bi-box-arrow-up me-1"></i> Export</a>
                      <button class="btn btn-outline-light rounded-pill px-4 fw-medium shadow-sm" id="import-offline-btn"><i class="bi bi-box-arrow-in-down me-1"></i> Import</button>
                    </div>
                  </div>`;
                
                const allData = await fetchData(`?action=get_offline_songs&sort=${currentView.sort}&all=1`, {}, true);
                if (allData && allData.length > 0) {
                  offlineViewSongsData = allData;
                  data = offlineViewSongsData.slice(0, PAGE_SIZE);
                  renderSongs(data, true);
                  if (offlineViewSongsData.length <= PAGE_SIZE) allContentloaded = true;
                } else {
                  offlineViewSongsData = [];
                  renderSongs([], true);
                  allContentloaded = true;
                }
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your offline music.</div>`;
              }
              break;
            case 'get_favorites':
              updateContentTitle('Favorites', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-heart-fill text-danger me-3 fs-3"></i> My Favorites</div>
                    <div class="d-flex gap-2 flex-wrap">
                      <a href="?action=export_favorites" class="btn btn-outline-light rounded-pill px-4 fw-medium shadow-sm" id="export-favorites-btn"><i class="bi bi-box-arrow-up me-1"></i> Export</a>
                      <button class="btn btn-outline-light rounded-pill px-4 fw-medium shadow-sm" id="import-favorites-btn"><i class="bi bi-box-arrow-in-down me-1"></i> Import</button>
                    </div>
                  </div>`;
                data = await fetchData(`?action=get_favorites&${pageParams.toString()}`);
                renderSongs(data, true);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your favorites.</div>`;
              }
              break;
            case 'get_listen_later':
              updateContentTitle('Listen Later', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-clock-fill text-warning me-3 fs-3"></i> Listen Later</div>
                  </div>`;
                data = await fetchData(`?action=get_listen_later&${pageParams.toString()}`);
                renderSongs(data, true);
                
                setTimeout(() => {
                  if (currentView.sort === 'manual_order' && !sortable) {
                    const sList = contentArea.querySelector('.song-list');
                    if (sList) {
                      sortable = Sortable.create(sList, {
                        animation: 150, delay: 250, delayOnTouchOnly: false,
                        onEnd: async () => {
                          const ids = Array.from(sList.querySelectorAll('.song-item')).map(item => item.dataset.songId);
                          await fetchData('?action=update_listen_later_order', { method: 'POST', body: JSON.stringify({ids}) });
                        }
                      });
                    }
                  }
                }, 500);

              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see listen later.</div>`;
              }
              break;

            case 'get_history':
              updateContentTitle('History', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-clock-history text-secondary me-3 fs-3"></i> Playback History</div>
                  </div>`;
                data = await fetchData(`?action=get_history&${pageParams.toString()}`);
                renderSongs(data, true);
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your history.</div>`;
              }
              break;

            case 'get_trending':
              updateContentTitle('Top 100 Trending', true);
              contentArea.innerHTML = `
                <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                  <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-graph-up-arrow text-success me-3 fs-3"></i> Top 100 Trending</div>
                </div>`;
              data = await fetchData(`?action=get_trending&${pageParams.toString()}`);
              renderSongs(data, true);
              break;

            case 'get_notes':
              updateContentTitle('Personal Notes', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="d-flex w-100 justify-content-between align-items-center flex-wrap gap-2 mb-3">
                      <div class="text-white fw-bold fs-5 d-flex align-items-center"><i class="bi bi-journal-text text-primary me-3 fs-3"></i> My Notes</div>
                      <div class="d-flex gap-2 flex-wrap">
                        <a href="?action=export_notes" class="btn btn-outline-light rounded-pill px-3 fw-medium shadow-sm" id="export-notes-btn"><i class="bi bi-box-arrow-up me-1"></i> Export</a>
                        <button class="btn btn-outline-light rounded-pill px-3 fw-medium shadow-sm" id="import-notes-btn"><i class="bi bi-box-arrow-in-down me-1"></i> Import</button>
                        <button class="btn btn-primary rounded-pill px-3 fw-medium shadow-sm new-note-btn"><i class="bi bi-plus-lg me-1"></i> New</button>
                      </div>
                    </div>
                    <div class="w-100 position-relative">
                      <input type="text" id="notes-search-input" class="form-control bg-dark text-white border-secondary" placeholder="Search notes..." value="${escapeHTML(currentView.searchQuery || '')}">
                    </div>
                  </div>
                  <div id="notes-grid" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mx-md-1 mb-4"></div>`;
                
                document.getElementById('notes-search-input').addEventListener('input', (e) => {
                  clearTimeout(window.notesSearchTimeout);
                  window.notesSearchTimeout = setTimeout(() => {
                    currentView.searchQuery = e.target.value;
                    loadView(currentView);
                  }, 400);
                });
                
                if (currentView.searchQuery) pageParams.append('q', currentView.searchQuery);
                const notes = await fetchData(`?action=get_notes&${pageParams.toString()}`);
                const grid = document.getElementById('notes-grid');
                if (notes && notes.length > 0) {
                  grid.innerHTML = notes.map(n => {
                    const truncContent = n.content.length > 300 ? n.content.substring(0, 300) + '...' : n.content;
                    return `
                    <div class="col">
                      <div class="card h-100 bg-dark text-white border-secondary">
                        <div class="card-body view-note-trigger" data-title="${escapeHTML(n.title)}" data-content="${escapeHTML(n.content)}" data-date="${n.updated_at}" style="cursor: pointer;">
                          <h5 class="card-title fw-bold text-truncate">${escapeHTML(n.title)}</h5>
                          <p class="card-text text-secondary small" style="white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden;">${parseUserText(truncContent)}</p>
                        </div>
                        <div class="card-footer border-secondary d-flex justify-content-between align-items-center">
                          <small class="text-secondary"><i class="bi bi-clock"></i> ${timeAgo(n.updated_at)}</small>
                          <div>
                            <button class="btn btn-sm btn-outline-light me-1 edit-note-btn" data-id="${n.id}" data-title="${escapeHTML(n.title)}" data-content="${escapeHTML(n.content)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-note-btn" data-id="${n.id}"><i class="bi bi-trash"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>`;
                  }).join('');
                } else {
                  grid.innerHTML = '<div class="col-12 text-center text-secondary py-5">No notes found.</div>';
                }
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to see your notes.</div>`;
              }
              break;

            case 'get_community':
              updateContentTitle('Community', !!currentUser);
              if (currentUser) {
                contentArea.innerHTML = `
                  <div class="d-flex flex-wrap align-items-center justify-content-between p-3 mx-md-3 mt-3 mb-4 rounded-4 shadow-sm" style="background-color: var(--ytm-surface-2); border: 1px solid #333;">
                    <div class="text-white fw-bold fs-5 mb-3 mb-md-0 d-flex align-items-center"><i class="bi bi-people text-info me-3 fs-3"></i> Community</div>
                    <div class="d-flex gap-2 align-items-center w-100 mt-2">
                      <input type="text" id="community-search-input" class="form-control bg-dark text-white border-secondary" placeholder="Search posts..." value="${escapeHTML(currentView.searchQuery || '')}">
                      <select id="community-sort-select" class="form-select bg-dark text-white border-secondary w-auto">
                        <option value="newest" ${currentView.sort === 'newest' ? 'selected' : ''}>Newest</option>
                        <option value="most_liked" ${currentView.sort === 'most_liked' ? 'selected' : ''}>Most Liked</option>
                        <option value="most_replied" ${currentView.sort === 'most_replied' ? 'selected' : ''}>Most Replied</option>
                        <option value="following" ${currentView.sort === 'following' ? 'selected' : ''}>Following</option>
                      </select>
                    </div>
                  </div>
                  <div class="mx-md-3 mb-5">
                    <form id="community-post-form" class="d-flex gap-2 mb-2">
                      <input type="hidden" id="community-parent-id" value="">
                      <input type="text" id="community-post-input" class="form-control bg-dark text-white border-secondary" placeholder="What's on your mind? (use @ to mention)" maxlength="2000" required>
                      <button type="submit" class="btn btn-info text-dark fw-bold px-4">Post</button>
                    </form>
                    <div class="d-flex justify-content-end">
                      <a href="#" class="text-info small text-decoration-none" data-bs-toggle="modal" data-bs-target="#bbcode-info-modal"><i class="bi bi-info-circle"></i> Formatting Help</a>
                    </div>
                  </div>
                  <div id="community-feed" class="d-flex flex-column gap-3 mx-md-3 mb-4"></div>`;
                  
                document.getElementById('community-search-input').addEventListener('input', (e) => {
                  clearTimeout(window.communitySearchTimeout);
                  window.communitySearchTimeout = setTimeout(() => {
                    currentView.searchQuery = e.target.value;
                    loadView(currentView);
                  }, 400);
                });
                
                document.getElementById('community-sort-select').addEventListener('change', (e) => {
                  currentView.sort = e.target.value;
                  loadView(currentView);
                });

                document.getElementById('community-post-form').addEventListener('submit', async e => {
                  e.preventDefault();
                  const input = document.getElementById('community-post-input');
                  const parentId = document.getElementById('community-parent-id').value;
                  await fetchData('?action=create_community_post', { method:'POST', body: JSON.stringify({content: input.value, parent_id: parentId || null}) });
                  input.value = '';
                  document.getElementById('community-parent-id').value = '';
                  input.placeholder = "What's on your mind? (use @ to mention)";
                  loadView(currentView);
                });

                if (currentView.searchQuery) pageParams.append('q', currentView.searchQuery);
                const posts = await fetchData(`?action=get_community&${pageParams.toString()}`);
                const feed = document.getElementById('community-feed');
                
                if (posts && posts.length > 0) {
                  const buildCommunityTree = (postsList, parent = null) => {
                    const children = postsList.filter(p => p.parent_id == parent);
                    if (children.length === 0) return '';
                    
                    if (parent === null) {
                      return children.map(p => `
                        <div class="card bg-transparent border-secondary text-white mb-2">
                          <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                              <div class="d-flex align-items-center gap-3 ${p.is_disabled ? '' : 'user-profile-link'}" data-userid="${p.user_id}" data-artist="${encodeURIComponent(p.artist)}" style="${p.is_disabled ? 'cursor: default;' : 'cursor: pointer;'}" ${p.is_disabled ? '' : 'title="View Profile"'}>
                                <img src="?action=get_profile_picture&id=${p.user_id}" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                                <div>
                                  <div class="fw-bold fs-6 hover-underline">${p.artist}</div>
                                  <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(p.created_at)}</small>
                                </div>
                              </div>
                              ${(currentUser && (currentUser.id == p.user_id || currentUser.email === 'musiclibrary@mail.com')) ? `
                              <div>
                                <button class="btn btn-sm btn-outline-light me-1 edit-post-btn" data-id="${p.id}" data-content="${escapeHTML(p.content)}"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger delete-post-btn" data-id="${p.id}"><i class="bi bi-trash"></i></button>
                              </div>` : ''}
                            </div>
                            <div class="mb-2" style="font-size: 1rem; white-space: pre-wrap;">${parseUserText(p.content)}</div>
                            <div class="d-flex gap-3 align-items-center">
                              <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'like' ? 'text-info' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="like">
                                <i class="bi ${p.my_reaction === 'like' ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'}"></i> <span>${p.like_count}</span>
                              </button>
                              <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'dislike' ? 'text-danger' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="dislike">
                                <i class="bi ${p.my_reaction === 'dislike' ? 'bi-hand-thumbs-down-fill' : 'bi-hand-thumbs-down'}"></i> <span>${p.dislike_count}</span>
                              </button>
                              <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none community-reply-btn" data-id="${p.id}" data-username="${escapeHTML(p.artist)}">Reply</button>
                            </div>
                            <div class="mt-2">${buildCommunityTree(postsList, p.id)}</div>
                          </div>
                        </div>`).join('');
                    } else {
                      const repliesHtml = children.map(p => `
                        <div class="card bg-transparent border-secondary text-white mb-2 ms-4 border-start border-top-0 border-bottom-0 border-end-0 rounded-0">
                          <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                              <div class="d-flex align-items-center gap-3 ${p.is_disabled ? '' : 'user-profile-link'}" data-userid="${p.user_id}" data-artist="${encodeURIComponent(p.artist)}" style="${p.is_disabled ? 'cursor: default;' : 'cursor: pointer;'}" ${p.is_disabled ? '' : 'title="View Profile"'}>
                                <img src="?action=get_profile_picture&id=${p.user_id}" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                                <div>
                                  <div class="fw-bold fs-6 hover-underline">${p.artist}</div>
                                  <small class="text-secondary" style="font-size: 0.75rem;">${timeAgo(p.created_at)}</small>
                                </div>
                              </div>
                              ${(currentUser && (currentUser.id == p.user_id || currentUser.email === 'musiclibrary@mail.com')) ? `
                              <div>
                                <button class="btn btn-sm btn-outline-light me-1 edit-post-btn" data-id="${p.id}" data-content="${escapeHTML(p.content)}"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger delete-post-btn" data-id="${p.id}"><i class="bi bi-trash"></i></button>
                              </div>` : ''}
                            </div>
                            <div class="mb-2" style="font-size: 1rem; white-space: pre-wrap;">${parseUserText(p.content)}</div>
                            <div class="d-flex gap-3 align-items-center">
                              <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'like' ? 'text-info' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="like">
                                <i class="bi ${p.my_reaction === 'like' ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'}"></i> <span>${p.like_count}</span>
                              </button>
                              <button class="btn btn-sm border-0 px-0 d-flex align-items-center gap-2 ${p.my_reaction === 'dislike' ? 'text-danger' : 'text-secondary'} community-react-btn" data-id="${p.id}" data-reaction="dislike">
                                <i class="bi ${p.my_reaction === 'dislike' ? 'bi-hand-thumbs-down-fill' : 'bi-hand-thumbs-down'}"></i> <span>${p.dislike_count}</span>
                              </button>
                              <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none community-reply-btn" data-id="${p.id}" data-username="${escapeHTML(p.artist)}">Reply</button>
                            </div>
                            <div class="mt-2">${buildCommunityTree(postsList, p.id)}</div>
                          </div>
                        </div>`).join('');
                      
                      return `
                        <button class="btn btn-link btn-sm text-info p-0 text-decoration-none toggle-replies-btn ms-4 mb-2" data-target="comm-reply-container-${parent}">
                          <i class="bi bi-chevron-down"></i> View ${children.length} replies
                        </button>
                        <div id="comm-reply-container-${parent}" class="d-none">
                          ${repliesHtml}
                        </div>
                      `;
                    }
                  };
                  
                  feed.innerHTML = buildCommunityTree(posts);
                } else {
                  feed.innerHTML = '<div class="text-center text-secondary py-5">No posts found. Be the first!</div>';
                }
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">Log in to view the community.</div>`;
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
            case 'get_years':
            case 'get_user_playlists':
            case 'get_collab_playlists':
              let title = currentView.type.replace('get_', '');
              title = title.charAt(0).toUpperCase() + title.slice(1);
              if (title === 'User_playlists') title = 'Playlists';
              if (title === 'Collab_playlists') title = 'Shared with me';
              updateContentTitle(title);
              pageParams.delete('page');
              data = await fetchData(`?action=${currentView.type}&${pageParams.toString()}`);
              renderGrid(data, currentView.type, false);
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
            case 'year_songs':
            case 'playlist_songs':
            case 'mix_songs':
              const type = currentView.type.split('_')[0];
              const name_param = encodeURIComponent(currentView.param);
              updateContentTitle('', false);
              const typeViewData = await fetchData(`?action=get_view_data&type=${type}&name=${name_param}&${pageParams.toString()}`);
              contentArea.innerHTML = '';
              if (typeViewData && typeViewData.details) {
                renderViewDetailsHeader(typeViewData.details, type, typeViewData.songs);
                renderSongs(typeViewData.songs, false);
                data = typeViewData.songs;
              } else {
                contentArea.innerHTML = `<div class="text-center p-5 text-secondary">User not found or this user already deleted.</div>`;
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

          let countCheckView = data ? data.length : 0;
          if (data && currentView.type === 'get_community') {
            countCheckView = data.filter(i => i.parent_id == null).length; // Only count root items
          }
          if (data && countCheckView < PAGE_SIZE && currentView.type !== 'search') {
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
          
          const payload = { id: songId, played_at: played_at_iso };
          if (window.activeQueueContext && window.activeQueueContext.type === 'playlist_songs') {
            payload.playlist_public_id = decodeURIComponent(window.activeQueueContext.param);
          }
          const data = JSON.stringify(payload);
          
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

        let playDebounceTimer = null;
        let playRequestCounter = 0;
        const playSongById = async (songId) => {
          playRequestCounter++;
          const currentPlayId = playRequestCounter;
          
          // DEBOUNCE: Protect rapid track skipping to prevent DB and audio buffer locks
          if (playDebounceTimer) clearTimeout(playDebounceTimer);
          updatePlayPauseIcons(true); // Show buffering spinner immediately
          
          await new Promise(resolve => {
            playDebounceTimer = setTimeout(resolve, 250);
          });
          
          if (currentPlayId !== playRequestCounter) return;

          // AUTOMATIC OFFLINE SKIP: If offline, ensure song is cached first
          if (!navigator.onLine) {
            const cache = await caches.open('php-music-offline');
            const req = await cache.match(`?action=get_stream&id=${songId}`, { ignoreSearch: false, ignoreVary: true });
            if (!req) {
              console.warn(`Song ${songId} not cached. Skipping automatically because you are offline.`);
              showToast("Skipped: Not available offline.", "info");
              // Delay slightly to prevent infinite loop locking if all songs are missing
              setTimeout(() => playNext(), 500);
              return;
            }
          }

          let data = await fetchData(`?action=get_song_data&id=${songId}`, {}, true);
          
          // OFFLINE FALLBACK: If API fails, check the local memory cache!
          if (!data) {
            if (globalSongCache[songId]) {
              data = Object.assign({}, globalSongCache[songId]);
              if (!data.stream_url) data.stream_url = `?action=get_stream&id=${songId}`;
              if (!data.image_url) data.image_url = `?action=get_image&id=${songId}&v=${globalSongCache[songId]?.last_modified || 0}`;
            } else {
              showToast("Song data not available offline.", "error");
              return;
            }
          }
          
          currentSong = data;
          globalSongCache[currentSong.id] = currentSong;
          currentSong.logged = false;
          
          initWebAudio();
          if (audioCtx.state === 'suspended') audioCtx.resume();
          applyAudioSettings(currentSong.id);

          if (isPlaying && audio.currentTime > 0 && !audio.paused) {
            audioB.src = audio.src;
            audioB.currentTime = audio.currentTime;
            audioB.play().catch(e => console.error(e));
            
            gainB.gain.setValueAtTime(1, audioCtx.currentTime);
            gainB.gain.linearRampToValueAtTime(0, audioCtx.currentTime + crossfadeDuration);
            
            setTimeout(() => { audioB.pause(); audioB.src = ''; }, crossfadeDuration * 1000);
            
            gainA.gain.setValueAtTime(0, audioCtx.currentTime);
            gainA.gain.linearRampToValueAtTime(1, audioCtx.currentTime + crossfadeDuration);
          } else {
            gainA.gain.value = 1;
            gainB.gain.value = 0;
          }

          audio.src = currentSong.stream_url;
          audio.load();
          audio.play().catch(e => console.error("Audio play failed:", e));
          isPlaying = true;
          updatePlayerUI();
          if (!visAnimId) drawVisualizer();
          
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
          const imageUrl = `?action=get_image&id=${currentSong.id}&v=${currentSong.last_modified || 0}`;
          
          // Apply dynamic blurred background to modals
          const mobileBg = document.getElementById('mobile-player-bg');
          const desktopBg = document.getElementById('desktop-player-bg');
          if (mobileBg) mobileBg.style.backgroundImage = `url('${imageUrl}')`;
          if (desktopBg) desktopBg.style.backgroundImage = `url('${imageUrl}')`;
          if (docPipWindow) {
            const pipBg = docPipWindow.document.getElementById('pip-bg');
            if (pipBg) pipBg.style.backgroundImage = `url('${imageUrl}')`;
          }

          playerElements.art.forEach(el => el.src = imageUrl);

          // Clear previous theme instantly on track change to prevent getting stuck
          document.querySelectorAll('.player-modal-content').forEach(modal => {
            modal.classList.remove('theme-light-bg');
          });
          if (docPipWindow && docPipWindow.document.body) {
            docPipWindow.document.body.classList.remove('theme-light-bg');
          }

          // Dynamically adjust modal text theme based on cover brightness
          const themeImg = new Image();
          themeImg.crossOrigin = 'anonymous';
          themeImg.onload = () => {
            const rgb = getAverageColor(themeImg);
            const brightness = Math.round(((parseInt(rgb.r) * 299) + (parseInt(rgb.g) * 587) + (parseInt(rgb.b) * 114)) / 1000);
             
            // If brightness is high (light cover art), trigger light mode
            if (brightness > 130) {
              document.querySelectorAll('.player-modal-content').forEach(modal => {
                modal.classList.add('theme-light-bg');
              });
              if (docPipWindow && docPipWindow.document.body) {
                 docPipWindow.document.body.classList.add('theme-light-bg');
              }
            }
          };
          // Append timestamp to bypass stubborn browser caches that prevent onload triggering
          themeImg.src = imageUrl + '&t=' + new Date().getTime();
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
              currentCoverImageObj = img;
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

        let isTogglingFavorite = false;
        const toggleFavorite = async (songId) => {
          if (!currentUser) {
            showToast('Please log in to add favorites.', 'error');
            return;
          }
          
          // DEBOUNCE: Protect SQLite from write-locks if user spams the heart button
          if (isTogglingFavorite) return;
          isTogglingFavorite = true;
          setTimeout(() => { isTogglingFavorite = false; }, 500); 
          
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
        
        const showShareModal = (type, id, name, artistId, artistName = '') => {
          const decodedName = decodeURIComponent(name || '');
          let shareUrl = `${window.location.origin}${window.location.pathname}?share_type=${type}`;

          const cleanId = String(id).replace(/undefined|null|nan/gi, '').trim();
          const cleanName = String(name).replace(/undefined|null|nan/gi, '').trim();
          const cleanArtistId = parseInt(artistId, 10);
          const finalArtistId = (!isNaN(cleanArtistId) && cleanArtistId > 0) ? cleanArtistId : '';
          const cleanArtistName = String(artistName).replace(/undefined|null|nan/gi, '').trim();
          
          if (type === 'album') {
            shareUrl += `&album_name=${encodeURIComponent(decodedName || cleanId)}`;
            if (finalArtistId !== '') {
              shareUrl += `&artist_id=${finalArtistId}`;
            }
            if (cleanArtistName !== '') {
              shareUrl += `&artist_name=${encodeURIComponent(decodeURIComponent(cleanArtistName))}`;
            }
          } else {
            if (cleanId !== '') {
              shareUrl += `&id=${cleanId}`;
            } else if (cleanName !== '') {
              shareUrl += `&id=${encodeURIComponent(cleanName)}`;
            }
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
          const { publicId, name, isCollab, isPrivate, ownerId } = playlistData;
          
          let menuItems = '';
          
          const isOwner = currentUser && (currentUser.id == ownerId || currentUser.email === 'musiclibrary@mail.com');

          if (isOwner) {
            if (!isPrivate) {
              const collabText = isCollab ? 'Make Private' : 'Make Collaborative';
              const collabIcon = isCollab ? '<i class="bi bi-lock-fill"></i>' : '<i class="bi bi-globe"></i>';
              menuItems += `<li class="context-menu-item" data-action="toggle_collab" data-public-id="${publicId}">${collabIcon} ${collabText}</li>`;
              if (isCollab) {
                menuItems += `<li class="context-menu-item" data-action="manage_collab" data-public-id="${publicId}" data-name="${name}"><i class="bi bi-people-fill"></i> Manage Collaborators</li>`;
              }
            }
            menuItems += `<li class="context-menu-item" data-action="edit_playlist" data-public-id="${publicId}" data-name="${name}" data-is-private="${isPrivate}"><i class="bi bi-pencil-fill"></i> Edit Playlist</li>`;
            menuItems += `<li class="context-menu-item" data-action="export_playlist" data-public-id="${publicId}"><i class="bi bi-box-arrow-up"></i> Export Playlist</li>`;
            menuItems += `<li class="context-menu-item text-danger" data-action="delete_playlist" data-public-id="${publicId}" data-name="${name}"><i class="bi bi-trash-fill"></i> Delete Playlist</li>`;
          } else {
            // User is just a collaborator
            menuItems += `<li class="context-menu-item" data-action="export_playlist" data-public-id="${publicId}"><i class="bi bi-box-arrow-up"></i> Export Playlist</li>`;
            menuItems += `<li class="context-menu-item text-warning" data-action="leave_collab" data-public-id="${publicId}"><i class="bi bi-box-arrow-left"></i> Leave Collab</li>`;
          }
            
          menuItems += `
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
          const { id: songId, title, artist, album, genre, user_id: songUserId, is_favorite, is_offline_missing } = songData;
          
          let menuItems = '';
          if (!is_offline_missing) {
            menuItems += `
              <li class="context-menu-item" data-action="play_next" data-id="${songId}"><i class="bi bi-skip-end-fill"></i> Play Next</li>
              <li class="context-menu-item" data-action="add_to_queue" data-id="${songId}"><i class="bi bi-list-ul"></i> Add to Queue</li>
              <hr class="dropdown-divider bg-secondary mx-2 my-1">`;
          }
          
          if (currentUser) {
            const isLL = typeof listenLaterSet !== 'undefined' && listenLaterSet.has(songId);
            const llIcon = isLL ? '<i class="bi bi-bookmark-fill"></i>' : '<i class="bi bi-bookmark"></i>';
            const llText = isLL ? "Remove from Listen Later" : "Listen Later";
            menuItems += `<li class="context-menu-item" data-action="listen_later" data-id="${songId}">${llIcon} ${llText}</li>`;
            menuItems += `<li class="context-menu-item" data-action="view_comments" data-id="${songId}"><i class="bi bi-chat-dots"></i> View Comments & Likes</li>`;
          }
          
          menuItems += `
            <li class="context-menu-item" data-action="share_song" data-id="${songId}" data-name="${encodeURIComponent(title)}"><i class="bi bi-share-fill"></i> Share Song</li>
            <li class="context-menu-item" data-action="go_artist" data-name="${encodeURIComponent(artist)}" data-userid="${songUserId}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item" data-action="go_album" data-name="${encodeURIComponent(album)}" data-userid="${songUserId}" data-artistname="${encodeURIComponent(artist)}"><i class="bi bi-disc-fill"></i> Go to Album</li>
            <li class="context-menu-item" data-action="show_all_genres"><i class="bi bi-tags-fill"></i> View All Genres</li>
            <li class="context-menu-item" data-action="download_song" data-id="${songId}"><i class="bi bi-download"></i> Download Song</li>
            <li class="context-menu-item" data-action="download_cover" data-id="${songId}"><i class="bi bi-image"></i> Download Cover Art</li>
            <li class="context-menu-item" data-action="show_metadata" data-id="${songId}"><i class="bi bi-file-earmark-music"></i> View Metadata</li>
            <li class="context-menu-item" data-action="show_lyrics" data-id="${songId}"><i class="bi bi-music-note-list"></i> Show Lyrics</li>
            `;
          
          if (currentUser) {
            menuItems += `<li class="context-menu-item" data-action="song_audio_settings" data-id="${songId}" data-title="${encodeURIComponent(title || '')}"><i class="bi bi-sliders"></i> Audio Settings (This Song)</li>`;
            const favText = is_favorite ? "Remove from Favorites" : "Add to Favorites";
            const favIcon = is_favorite ? ICONS.heartFill : ICONS.heart;
            const offText = offlineSongsSet.has(songId) ? "Remove from Offline" : "Make Available Offline";
            const offIcon = offlineSongsSet.has(songId) ? '<i class="bi bi-cloud-check-fill text-success"></i>' : '<i class="bi bi-cloud-arrow-down"></i>';
            menuItems += `<hr class="dropdown-divider bg-secondary mx-2 my-1">`;
            menuItems += `<li class="context-menu-item" data-action="toggle_favorite" data-id="${songId}">${favIcon} ${favText}</li>`;
            menuItems += `<li class="context-menu-item" data-action="toggle_offline" data-id="${songId}">${offIcon} ${offText}</li>`;
            if (offlineSongsSet.has(songId)) {
              menuItems += `<li class="context-menu-item" data-action="save_to_device" data-id="${songId}" data-title="${encodeURIComponent(title)}"><i class="bi bi-device-hdd text-info"></i> Save File to Device</li>`;
              menuItems += `<li class="context-menu-item" data-action="recache_offline" data-id="${songId}"><i class="bi bi-arrow-repeat text-warning"></i> Re-download Cache</li>`;
            }
            menuItems += `<li class="context-menu-item" data-action="add_to_playlist" data-id="${songId}"><i class="bi bi-plus-lg"></i> Add to Playlist</li>`;
            if (currentView.type === 'playlist_songs') {
              menuItems += `<li class="context-menu-item text-danger" data-action="remove_from_playlist" data-id="${songId}"><i class="bi bi-x-circle-fill"></i> Remove from Playlist</li>`;
            }
            if (currentUser.id === songUserId || (currentUser.email && currentUser.email.toLowerCase() === 'musiclibrary@mail.com')) {
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
            is_offline_missing: songItem.classList.contains('offline-missing'),
            is_favorite: songItem.dataset.isFavorite === '1',
            title: songItem.dataset.songTitle,
            artist: songItem.dataset.songArtist,
            album: songItem.dataset.songAlbum,
            genre: songItem.dataset.songGenre,
            user_id: parseInt(songItem.dataset.songUserId)
          };
          globalSongCache[songData.id] = songData;
          
          buildAndShowSongContextMenu(buttonEl, songData);
        };
        
        let visAnimId;
        let visTime = 0;
        let cachedColor = { r: 255, g: 50, b: 50 }; // default theme red
        let lastColorSongId = null;
        let currentCoverImageObj = null;

        const getAverageColor = (imgEl) => {
          if (!imgEl || !imgEl.complete || imgEl.naturalWidth === 0) return { r: 255, g: 50, b: 50 };
          try {
            const tempCanvas = document.createElement('canvas');
            const tempCtx = tempCanvas.getContext('2d');
            tempCanvas.width = 10;
            tempCanvas.height = 10;
            tempCtx.drawImage(imgEl, 0, 0, 10, 10);
            const data = tempCtx.getImageData(0, 0, 10, 10).data;
            let r = 0, g = 0, b = 0, count = 0;
            for (let i = 0; i < data.length; i += 4) {
              const brightness = (data[i] + data[i+1] + data[i+2]) / 3;
              if (brightness > 15 && brightness < 240) { // Skip near-black and near-white
                r += data[i]; g += data[i+1]; b += data[i+2];
                count++;
              }
            }
            if (count === 0) {
              for (let i = 0; i < data.length; i += 4) { r += data[i]; g += data[i+1]; b += data[i+2]; }
              count = data.length / 4;
            }
            return { r: Math.round(r / count), g: Math.round(g / count), b: Math.round(b / count) };
          } catch (e) {
            return { r: 255, g: 50, b: 50 };
          }
        };

        const drawVisualizer = () => {
          visTime += 1;
          
          // Locate canvases inside both Main DOM and Document PiP windows
          let canvases = Array.from(document.querySelectorAll('.visualizer-canvas'));
          if (docPipWindow) {
            const pipCanvases = docPipWindow.document.querySelectorAll('.visualizer-canvas');
            canvases = canvases.concat(Array.from(pipCanvases));
          }
          
          let activeCanvas = null;
          canvases.forEach(c => { if (c.offsetParent) activeCanvas = c; });

          // Extract dominant color of current song cover art if it changed
          if (currentSong && currentSong.id !== lastColorSongId) {
            const activeImg = document.getElementById('player-art-desktop');
            if (activeImg && activeImg.complete) {
              cachedColor = getAverageColor(activeImg);
              lastColorSongId = currentSong.id;
            }
          }

          const activeBins = 24; // Lower bins stretching curves horizontally for extra gentle waves
          let rawData = [];

          if (isPlaying && analyser) {
            analyser.getByteFrequencyData(visualizerDataArray);
            for (let i = 0; i < activeBins; i++) {
              rawData.push(visualizerDataArray[i]);
            }
          } else {
            // Generate organic sinusoidal idle breathing movement during pause
            for (let i = 0; i < activeBins; i++) {
              const wave1 = Math.sin(i * 0.12 + visTime * 0.035) * 45;
              const wave2 = Math.cos(i * 0.07 - visTime * 0.02) * 20;
              rawData.push(Math.max(15, 65 + wave1 + wave2));
            }
          }

          // Symmetrical mapping: treble on outer edges, deep bass in the center (perfectly horizontal)
          const mirroredData = [];
          for (let i = activeBins - 1; i >= 0; i--) { mirroredData.push(rawData[i]); }
          for (let i = 0; i < activeBins; i++) { mirroredData.push(rawData[i]); }

          const totalPoints = mirroredData.length;
          
          // GENTLE SMOOTHING: Moving average filter to form smooth liquid peaks
          const smoothedData = [];
          const smoothWindow = 2; 
          for (let i = 0; i < mirroredData.length; i++) {
            let sum = 0, count = 0;
            for (let w = -smoothWindow; w <= smoothWindow; w++) {
              if (mirroredData[i + w] !== undefined) {
                sum += mirroredData[i + w];
                count++;
              }
            }
            smoothedData.push(sum / count);
          }

          const sliceWidths = canvases.map(c => c.offsetWidth / (totalPoints - 1));
          const { r, g, b } = cachedColor;

          // Process and Draw standard Page View & Document PiP canvases
          canvases.forEach((canvas, cIdx) => {
            if (!canvas.offsetParent) return; 
            const ctx = canvas.getContext('2d');
            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;
            
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // Darken bottom half slightly so translucent waves pop elegantly
            const baseGrad = ctx.createLinearGradient(0, canvas.height * 0.5, 0, canvas.height);
            baseGrad.addColorStop(0, 'transparent');
            baseGrad.addColorStop(1, 'rgba(0, 0, 0, 0.55)');
            ctx.fillStyle = baseGrad;
            ctx.fillRect(0, canvas.height * 0.5, canvas.width, canvas.height * 0.5);

            const sliceWidth = sliceWidths[cIdx];

            const drawWave = (baseAmplitude, colorGrad, baseOffset, layerIdx) => {
              ctx.beginPath();
              ctx.moveTo(-50, canvas.height);
              
              // DYNAMIC RANDOMIZED BREATHING: Modulate amplitude and phase shifts over time per-layer
              const waveBreathe = Math.sin(visTime * 0.012 * (layerIdx + 1) + layerIdx) * 0.15;
              const amplitude = baseAmplitude * (1.0 + waveBreathe);
              const xOffset = baseOffset + Math.cos(visTime * 0.008 * (layerIdx + 1)) * 35;

              let firstVal = smoothedData[0] || 0;
              let yStart = canvas.height - ((firstVal / 255) * canvas.height * amplitude);
              ctx.lineTo(-50, yStart);

              for(let i = 0; i < totalPoints - 1; i++) {
                let val = smoothedData[i] || 0;
                let nextVal = smoothedData[i + 1] || 0;

                let xc = xOffset + (sliceWidth * i);
                let yc = canvas.height - ((val / 255) * canvas.height * amplitude);
                
                let nextX = xOffset + (sliceWidth * (i + 1));
                let nextY = canvas.height - ((nextVal / 255) * canvas.height * amplitude);
                
                let midX = (xc + nextX) / 2;
                let midY = (yc + nextY) / 2;

                ctx.quadraticCurveTo(xc, yc, midX, midY);
              }
              
              ctx.lineTo(canvas.width + 50, canvas.height);
              ctx.closePath();
              ctx.fillStyle = colorGrad;
              ctx.fill();
            };

            const makeGrad = (opacity, rOff = 0, gOff = 0, bOff = 0) => {
              const gr = Math.max(0, Math.min(255, r + rOff));
              const gg = Math.max(0, Math.min(255, g + gOff));
              const gb = Math.max(0, Math.min(255, b + bOff));
              const gInst = ctx.createLinearGradient(0, canvas.height * 0.5, 0, canvas.height);
              gInst.addColorStop(0, `rgba(${gr}, ${gg}, ${gb}, ${opacity})`);
              gInst.addColorStop(1, `rgba(${gr}, ${gg}, ${gb}, 0.0)`);
              return gInst;
            };

            const waves = [
              { amp: 0.50, grad: makeGrad(0.12, -40, -40, -40), xOff: -40 },
              { amp: 0.46, grad: makeGrad(0.18, -20, -10, 20),  xOff: 30  }, 
              { amp: 0.42, grad: makeGrad(0.24, 20, -20, -10),  xOff: -20 },
              { amp: 0.38, grad: makeGrad(0.30, -10, 30, -20),  xOff: 40  },
              { amp: 0.34, grad: makeGrad(0.38, 30, 10, 30),    xOff: -10 },
              { amp: 0.30, grad: makeGrad(0.48, 10, -30, 40),   xOff: 20  },
              { amp: 0.25, grad: makeGrad(0.60, 50, 50, 50),    xOff: 0   }
            ];

            waves.forEach((w, idx) => {
              drawWave(w.amp, w.grad, w.xOff, idx);
            });
          });

          // Draw on standard Video PiP canvas if active
          if (document.pictureInPictureElement === pipVideo && currentCoverImageObj) {
            pipCtx.clearRect(0, 0, 500, 500);
            pipCtx.drawImage(currentCoverImageObj, 0, 0, 500, 500);

            // Darken bottom half
            const baseGrad = pipCtx.createLinearGradient(0, 250, 0, 500);
            baseGrad.addColorStop(0, 'transparent');
            baseGrad.addColorStop(1, 'rgba(0, 0, 0, 0.55)');
            pipCtx.fillStyle = baseGrad;
            pipCtx.fillRect(0, 250, 500, 250);

            const pipSliceWidth = 500 / (totalPoints - 1);

            const drawPipWave = (amplitude, colorGrad, xOffset) => {
              pipCtx.beginPath();
              pipCtx.moveTo(-50, 500);
              
              let firstVal = smoothedData[0] || 0;
              let yStart = 500 - ((firstVal / 255) * 500 * amplitude);
              pipCtx.lineTo(-50, yStart);

              for(let i = 0; i < totalPoints - 1; i++) {
                let val = smoothedData[i] || 0;
                let nextVal = smoothedData[i + 1] || 0;

                let xc = xOffset + (pipSliceWidth * i);
                let yc = 500 - ((val / 255) * 500 * amplitude);
                
                let nextX = xOffset + (pipSliceWidth * (i + 1));
                let nextY = 500 - ((nextVal / 255) * 500 * amplitude);
                
                let midX = (xc + nextX) / 2;
                let midY = (yc + nextY) / 2;

                pipCtx.quadraticCurveTo(xc, yc, midX, midY);
              }
              
              pipCtx.lineTo(550, 500);
              pipCtx.closePath();
              pipCtx.fillStyle = colorGrad;
              pipCtx.fill();
            };

            const makePipGrad = (opacity, rOff = 0, gOff = 0, bOff = 0) => {
              const gr = Math.max(0, Math.min(255, r + rOff));
              const gg = Math.max(0, Math.min(255, g + gOff));
              const gb = Math.max(0, Math.min(255, b + bOff));
              const gInst = pipCtx.createLinearGradient(0, 250, 0, 500);
              gInst.addColorStop(0, `rgba(${gr}, ${gg}, ${gb}, ${opacity})`);
              gInst.addColorStop(1, `rgba(${gr}, ${gg}, ${gb}, 0.0)`);
              return gInst;
            };

            const waveBreathe = (idx) => Math.sin(visTime * 0.012 * (idx + 1) + idx) * 0.15;
            const xSway = (idx) => Math.cos(visTime * 0.008 * (idx + 1)) * 35;

            drawPipWave(0.50 * (1.0 + waveBreathe(0)), makePipGrad(0.12, -40, -40, -40), -40 + xSway(0));
            drawPipWave(0.46 * (1.0 + waveBreathe(1)), makePipGrad(0.18, -20, -10, 20),  30 + xSway(1));
            drawPipWave(0.42 * (1.0 + waveBreathe(2)), makePipGrad(0.24, 20, -20, -10),  -20 + xSway(2));
            drawPipWave(0.38 * (1.0 + waveBreathe(3)), makePipGrad(0.30, -10, 30, -20),  40 + xSway(3));
            drawPipWave(0.34 * (1.0 + waveBreathe(4)), makePipGrad(0.38, 30, 10, 30),    -10 + xSway(4));
            drawPipWave(0.30 * (1.0 + waveBreathe(5)), makePipGrad(0.48, 10, -30, 40),   20 + xSway(5));
            drawPipWave(0.25 * (1.0 + waveBreathe(6)), makePipGrad(0.60, 50, 50, 50),    0 + xSway(6));

            // Overlay Metadata Text
            const textGrad = pipCtx.createLinearGradient(0, 380, 0, 500);
            textGrad.addColorStop(0, 'transparent');
            textGrad.addColorStop(1, 'rgba(0,0,0,0.85)');
            pipCtx.fillStyle = textGrad;
            pipCtx.fillRect(0, 380, 500, 120);

            pipCtx.fillStyle = '#ffffff';
            pipCtx.font = 'bold 28px sans-serif';
            pipCtx.shadowColor = "rgba(0,0,0,0.8)";
            pipCtx.shadowBlur = 4;
            const safeTitle = currentSong.title.length > 28 ? currentSong.title.substring(0, 25) + '...' : currentSong.title;
            pipCtx.fillText(safeTitle, 20, 440);
            
            pipCtx.fillStyle = '#cccccc';
            pipCtx.font = '22px sans-serif';
            const safeArtist = currentSong.artist.length > 35 ? currentSong.artist.substring(0, 32) + '...' : currentSong.artist;
            pipCtx.fillText(safeArtist, 20, 475);
          }
          
          visAnimId = requestAnimationFrame(drawVisualizer);
        };

        const togglePlayPause = () => {
          if (!currentSong) return;
          isPlaying = !isPlaying;
          isPlaying ? audio.play() : audio.pause();
          updatePlayPauseIcons();
          if (!visAnimId) drawVisualizer();
        };

        let isRadioLoading = false;
        const playNext = async () => {
          if (queue.length === 0) return;
          if (isRadioLoading) return;
          
          queueIndex++;
          if (queueIndex >= queue.length) {
            if (repeatMode === 'all') {
              queueIndex = 0;
            } else {
              isRadioLoading = true;
              const lastSongId = queue[queue.length - 1];
              showToast('Starting Autoplay Station...', 'info');
              const newTracks = await fetchData(`?action=get_radio_tracks&seed_id=${lastSongId}`);
              isRadioLoading = false;
              
              if (newTracks && newTracks.length > 0) {
                newTracks.forEach(song => {
                  globalSongCache[song.id] = song; // Cache full song objects!
                  queue.push(parseInt(song.id));
                  originalQueue.push(parseInt(song.id));
                });
                queueDirty = true;
                renderQueue(); // Dynamically updates the Up Next UI!
              } else {
                isPlaying = false; audio.pause();
                updatePlayPauseIcons();
                queueIndex = queue.length - 1;
                return;
              }
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
          localStorage.setItem('isShuffle', isShuffle);
          showToast(isShuffle ? 'Shuffle enabled' : 'Shuffle disabled', 'info');
        };

        let queueDebounceTimer = null;
        let queueRequestCounter = 0;
        const setQueueAndPlay = async (startId, view) => {
          queueRequestCounter++;
          const currentQueueId = queueRequestCounter;
          
          // DEBOUNCE: Prevent massive array generation and queue fetches from rapid clicks
          if (queueDebounceTimer) clearTimeout(queueDebounceTimer);
          await new Promise(resolve => {
            queueDebounceTimer = setTimeout(resolve, 200);
          });
          if (currentQueueId !== queueRequestCounter) return;

          const contextView = view || currentView;
          let fetchedQueue = [];
          
          if (contextView.type === 'get_offline_songs' && offlineViewSongsData && offlineViewSongsData.length > 0) {
            fetchedQueue = offlineViewSongsData.map(song => parseInt(song.id));
          } else if (contextView.type === 'get_recommendations' || contextView.type === 'search') {
            const allShelfSongs = [...document.querySelectorAll('.shelf-item[data-song-id], .song-item[data-song-id], .top-result-card[data-song-id]')];
            const allSongIds = allShelfSongs.map(item => parseInt(item.dataset.songId));
            fetchedQueue = [...new Set(allSongIds)];
          } else {
            let viewTypeForIds = contextView.type;
            if (contextView.type === 'user_profile') viewTypeForIds = 'get_profile_songs';
            const allIds = await fetchData('?action=get_view_ids', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ view_type: viewTypeForIds, param: contextView.param, sort: contextView.sort, filter_user_id: contextView.filter_user_id || '', artist_name: contextView.artist_name || '' })
            }, true);
            
            if (allIds && allIds.length > 0) {
                fetchedQueue = allIds.map(id => parseInt(id));
            }
          }
          
          if (!fetchedQueue || fetchedQueue.length === 0) {
            fetchedQueue = [startId];
          }
          
          originalQueue = fetchedQueue;
          queue = [...originalQueue];
          window.activeQueueContext = { type: contextView.type, param: contextView.param };
          
          if (isShuffle) { isShuffle = false; toggleShuffle(); }
          
          queueIndex = queue.findIndex(id => id === startId);
          if (queueIndex === -1) { 
            queue.unshift(startId);
            originalQueue = [...queue];
            queueIndex = 0;
          }
          playSongById(startId);
          renderQueue(true);
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
          if (link.getAttribute('data-bs-toggle') === 'modal' || ['logout-btn', 'clear-cache-btn', 'fullscreen-btn', 'install-pwa-btn', 'check-update-btn', 'nav-upload-btn'].includes(link.id)) return;
          link.addEventListener('click', e => {
            e.preventDefault();
            const navLink = e.currentTarget;
        
            const viewType = navLink.dataset.view;
            let sort = 'artist_asc';
            if (['get_favorites', 'playlist_songs', 'get_offline_songs', 'get_listen_later'].includes(viewType)) sort = 'manual_order';
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
            loadView({ type: 'search', param: query.trim(), sort: 'artist_asc', filter_user_id: '', f_date: '', f_dur: '', f_sort: 'relevance' });
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
                    <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" class="search-dropdown-img" style="width: 50px; height: 50px; border-radius: 50%;">
                    <div class="search-dropdown-text">
                      <div class="search-dropdown-title fw-bold" style="font-size: 1rem;">${escapeHTML(song.title)}</div>
                      <div class="search-dropdown-subtitle">Song • ${escapeHTML(song.artist)}</div>
                    </div>
                  </div>`;
            } else if (shelf.type === 'songs_list') {
              html += `<div class="search-dropdown-header">Songs</div>`;
              shelf.items.slice(0, 4).forEach(song => {
                html += `<div class="search-dropdown-item song-dropdown-item" data-id="${song.id}">
                    <img src="?action=get_image&id=${song.id}&v=${song.last_modified || 0}" class="search-dropdown-img">
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
                html += `<div class="search-dropdown-item artist-dropdown-item" data-artist="${encodeURIComponent(artist.name)}" data-userid="${artist.is_user ? artist.id : ''}">
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
                html += `<div class="search-dropdown-item album-dropdown-item" data-album="${encodeURIComponent(album.album)}" data-userid="${album.user_id || ''}" data-artistname="${encodeURIComponent(album.artist || '')}">
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
            if (currentView.type === 'search') loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' });
            return;
          }

          searchTimeout = setTimeout(async () => {
            const data = await fetchData(`?action=search&q=${encodeURIComponent(query.trim())}`);
            
            renderSearchDropdown(targetDropdown, data);
            
            currentView = { type: 'search', param: query.trim(), sort: 'artist_asc', filter_user_id: '', artist_name: '', f_date: '', f_dur: '', f_sort: 'relevance' };
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
              loadView({ type: 'album_songs', param: dropItem.dataset.album, sort: 'title_asc', filter_user_id: dropItem.dataset.userid, artist_name: dropItem.dataset.artistname });
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
          localStorage.setItem('repeatMode', repeatMode);
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
              if (typeof renderQueue === 'function') renderQueue(); // <--- Forces the queue to draw!
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
                const modalTitle = artistsModalEl.querySelector('.modal-title');
                if (modalTitle) modalTitle.textContent = 'Artists';
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
            const artistBtn = e.target.closest('.artist-modal-item');
            const albumBtn = e.target.closest('.album-modal-item');
            
            if (artistBtn) {
              const artistName = decodeURIComponent(artistBtn.dataset.artist);
              const userId = artistBtn.dataset.userid;
              closeOpenModals();
              setTimeout(() => {
                loadView({ type: 'artist_songs', param: artistName, sort: 'album_asc', filter_user_id: userId, artist_name: '' });
                hideMobileSidebar();
              }, 200);
            } else if (albumBtn) {
              const albumName = decodeURIComponent(albumBtn.dataset.album);
              const artistName = decodeURIComponent(albumBtn.dataset.artist);
              let validUserId = parseInt(albumBtn.dataset.userid, 10);
              let safeUserId = (isNaN(validUserId) || validUserId <= 0) ? '' : validUserId;
              closeOpenModals();
              setTimeout(() => {
                loadView({ type: 'album_songs', param: albumName, sort: 'title_asc', filter_user_id: safeUserId, artist_name: artistName });
                hideMobileSidebar();
              }, 200);
            }
          });
        }
        let activeCommentSongId = null;
        let currentCommentsPage = 1;

        window.openCommentsModal = async (songId, replyCommentId = null, replyUsername = null) => {
          activeCommentSongId = songId;
          const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('comments-modal'));
          await window.refreshComments(true);
          modal.show();

          if (replyCommentId && replyUsername) {
            setTimeout(() => {
              document.getElementById('comment-parent-id').value = replyCommentId;
              const input = document.getElementById('comment-input');
              input.placeholder = "Replying to comment...";
              const cleanedUsername = replyUsername.replace(/\s+/g, '');
              input.value = `@${cleanedUsername} `;
              input.focus();
            }, 500);
          }
        };

        window.refreshComments = async (reset = false) => {
          if (!activeCommentSongId) return;
          if (reset === true || typeof reset !== 'boolean') currentCommentsPage = 1;
          const sortVal = document.getElementById('comments-sort-select') ? document.getElementById('comments-sort-select').value : 'newest';

          const loadMoreBtn = document.getElementById('load-more-comments-btn');
          if (loadMoreBtn && !reset) loadMoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

          const data = await fetchData(`?action=get_song_comments&song_id=${activeCommentSongId}&sort=${sortVal}&page=${currentCommentsPage}`);
          document.getElementById('song-like-count').textContent = data.reactions.like || 0;
          document.getElementById('song-dislike-count').textContent = data.reactions.dislike || 0;

          const lBtn = document.getElementById('song-like-btn');
          const dBtn = document.getElementById('song-dislike-btn');
          if (lBtn) {
            lBtn.classList.toggle('active', data.my_reaction === 'like');
            lBtn.classList.toggle('text-info', data.my_reaction === 'like');
          }
          if (dBtn) {
            dBtn.classList.toggle('active', data.my_reaction === 'dislike');
            dBtn.classList.toggle('text-danger', data.my_reaction === 'dislike');
          }

          const buildTree = (comments, parent = null) => {
            const children = comments.filter(c => c.parent_id == parent);
            if (children.length === 0) return '';
            
            if (parent === null) {
              return children.map(c => `
                <div class="text-white p-2 border-start border-secondary mb-2" style="margin-left: 0;">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <div class="d-flex align-items-center gap-2 ${c.is_disabled ? '' : 'user-profile-link'}" data-userid="${c.u_id}" data-artist="${encodeURIComponent(c.artist)}" style="${c.is_disabled ? 'cursor: default;' : 'cursor: pointer;'}" ${c.is_disabled ? '' : 'title="View Profile"'}>
                      <img src="?action=get_profile_picture&id=${c.u_id}" style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
                      <strong class="hover-underline">${c.artist}</strong>
                    </div>
                    <small class="text-secondary">${timeAgo(c.created_at)}</small>
                  </div>
                  <div style="font-size: 0.9rem; white-space: pre-wrap;" class="mb-1">${parseUserText(c.content)}</div>
                  <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none comment-react-btn" data-id="${c.id}" data-reaction="like">
                      <i class="bi ${c.my_reaction === 'like' ? 'bi-hand-thumbs-up-fill text-info' : 'bi-hand-thumbs-up'}"></i> ${c.like_count || 0}
                    </button>
                    <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none comment-react-btn" data-id="${c.id}" data-reaction="dislike">
                      <i class="bi ${c.my_reaction === 'dislike' ? 'bi-hand-thumbs-down-fill text-danger' : 'bi-hand-thumbs-down'}"></i> ${c.dislike_count || 0}
                    </button>
                    <button class="btn btn-link btn-sm text-secondary p-0 reply-btn" data-id="${c.id}" data-username="${escapeHTML(c.artist)}">Reply</button>
                    ${(currentUser && (currentUser.id == c.u_id || currentUser.email === 'musiclibrary@mail.com')) ? `
                      <button class="btn btn-link btn-sm text-secondary p-0 edit-comment-btn" data-id="${c.id}" data-content="${escapeHTML(c.content)}"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-link btn-sm text-danger p-0 delete-comment-btn" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                    ` : ''}
                  </div>
                  <div class="mt-2">${buildTree(comments, c.id)}</div>
                </div>
              `).join('');
            } else {
              const repliesHtml = children.map(c => `
                <div class="text-white p-2 border-start border-secondary mb-2" style="margin-left: 20px;">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <div class="d-flex align-items-center gap-2 ${c.is_disabled ? '' : 'user-profile-link'}" data-userid="${c.u_id}" data-artist="${encodeURIComponent(c.artist)}" style="${c.is_disabled ? 'cursor: default;' : 'cursor: pointer;'}" ${c.is_disabled ? '' : 'title="View Profile"'}>
                      <img src="?action=get_profile_picture&id=${c.u_id}" style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
                      <strong class="hover-underline">${c.artist}</strong>
                    </div>
                    <small class="text-secondary">${timeAgo(c.created_at)}</small>
                  </div>
                  <div style="font-size: 0.9rem; white-space: pre-wrap;" class="mb-1">${parseUserText(c.content)}</div>
                  <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none comment-react-btn" data-id="${c.id}" data-reaction="like">
                      <i class="bi ${c.my_reaction === 'like' ? 'bi-hand-thumbs-up-fill text-info' : 'bi-hand-thumbs-up'}"></i> ${c.like_count || 0}
                    </button>
                    <button class="btn btn-link btn-sm text-secondary p-0 text-decoration-none comment-react-btn" data-id="${c.id}" data-reaction="dislike">
                      <i class="bi ${c.my_reaction === 'dislike' ? 'bi-hand-thumbs-down-fill text-danger' : 'bi-hand-thumbs-down'}"></i> ${c.dislike_count || 0}
                    </button>
                    <button class="btn btn-link btn-sm text-secondary p-0 reply-btn" data-id="${c.id}" data-username="${escapeHTML(c.artist)}">Reply</button>
                    ${(currentUser && (currentUser.id == c.u_id || currentUser.email === 'musiclibrary@mail.com')) ? `
                      <button class="btn btn-link btn-sm text-secondary p-0 edit-comment-btn" data-id="${c.id}" data-content="${escapeHTML(c.content)}"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-link btn-sm text-danger p-0 delete-comment-btn" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                    ` : ''}
                  </div>
                  <div class="mt-2">${buildTree(comments, c.id)}</div>
                </div>
              `).join('');

              return `
                <button class="btn btn-link btn-sm text-info p-0 text-decoration-none toggle-replies-btn mb-2" data-target="comment-reply-container-${parent}">
                  <i class="bi bi-chevron-down"></i> View ${children.length} replies
                </button>
                <div id="comment-reply-container-${parent}" class="d-none">
                  ${repliesHtml}
                </div>
              `;
            }
          };

          const commentsList = document.getElementById('comments-list');
          if (commentsList) {
            const newHtml = buildTree(data.comments) || (reset ? '<p class="text-secondary text-center mt-3">No comments yet.</p>' : '');
            if (reset) {
              commentsList.innerHTML = newHtml;
            } else {
              commentsList.insertAdjacentHTML('beforeend', newHtml);
            }

            const btnContainer = document.getElementById('load-more-comments-container');
            if (btnContainer) {
              const rootCount = data.comments.filter(c => c.parent_id == null).length;
              if (rootCount >= 25) {
                btnContainer.classList.remove('d-none');
                if (loadMoreBtn) loadMoreBtn.innerHTML = 'Load More Comments';
              } else {
                btnContainer.classList.add('d-none');
              }
            }
          }
        };

        // Attach global listener for comments modals once
        if (!window.commentsGlobalListenerAttached) {
          window.commentsGlobalListenerAttached = true;

          document.addEventListener('click', async (e) => {
            const mentionLink = e.target.closest('.mention-link');
            if (mentionLink) {
              e.stopPropagation();
              bootstrap.Modal.getInstance(document.getElementById('comments-modal')).hide();
              const artistName = mentionLink.dataset.artist;
              loadView({ type: 'artist_songs', param: artistName, sort: 'album_asc', filter_user_id: '', artist_name: '' });
              hideMobileSidebar();
              return;
            }
            const userLink = e.target.closest('.user-profile-link');
            if (userLink) {
              const userId = userLink.dataset.userid;
              const artistName = decodeURIComponent(userLink.dataset.artist);
              const commentsModal = bootstrap.Modal.getInstance(document.getElementById('comments-modal'));
              if (commentsModal) commentsModal.hide();
              loadView({ type: 'artist_songs', param: artistName, sort: 'album_asc', filter_user_id: userId, artist_name: '' });
              return;
            }
            const replyBtn = e.target.closest('.reply-btn');
            if (replyBtn) {
              document.getElementById('comment-parent-id').value = replyBtn.dataset.id;
              const input = document.getElementById('comment-input');
              input.placeholder = "Replying to comment...";
              const username = replyBtn.dataset.username;
              if (username) {
                const cleanedUsername = username.replace(/\s+/g, ''); // Removes spaces so @ works properly
                input.value = `@${cleanedUsername} `;
              }
              input.focus();
              return;
            }
            const reactBtn = e.target.closest('.comment-react-btn');
            if (reactBtn) {
              if (!currentUser) return showToast('Please login', 'error');
              await fetchData('?action=toggle_comment_reaction', { method: 'POST', body: JSON.stringify({ comment_id: reactBtn.dataset.id, reaction: reactBtn.dataset.reaction }) });
              window.refreshComments(true);
              return;
            }
            const editBtn = e.target.closest('.edit-comment-btn');
            if (editBtn) {
              document.getElementById('edit-comment-id').value = editBtn.dataset.id;
              document.getElementById('edit-comment-input').value = decodeHTML(editBtn.dataset.content);
              bootstrap.Modal.getOrCreateInstance(document.getElementById('edit-comment-modal')).show();
              return;
            }
            const deleteBtn = e.target.closest('.delete-comment-btn');
            if (deleteBtn) {
              if (confirm('Delete this comment?')) {
                await fetchData('?action=delete_song_comment', { method: 'POST', body: JSON.stringify({ comment_id: deleteBtn.dataset.id }) });
                window.refreshComments(true);
              }
              return;
            }
          });

          document.getElementById('load-more-comments-btn')?.addEventListener('click', () => {
            currentCommentsPage++;
            window.refreshComments(false);
          });
        }

        window.handleReaction = async (reaction) => {
          if (!currentUser) return showToast('Please login', 'error');
          await fetchData('?action=toggle_song_reaction', { method: 'POST', body: JSON.stringify({ song_id: activeCommentSongId, reaction: reaction }) });
          window.refreshComments(true);
        };

        const songLikeBtn = document.getElementById('song-like-btn');
        const songDislikeBtn = document.getElementById('song-dislike-btn');
        const commentForm = document.getElementById('comment-form');
        const editCommentForm = document.getElementById('edit-comment-form');
        const editPostForm = document.getElementById('edit-post-form');

        if (songLikeBtn) {
          songLikeBtn.addEventListener('click', () => window.handleReaction('like'));
        }
        if (songDislikeBtn) {
          songDislikeBtn.addEventListener('click', () => window.handleReaction('dislike'));
        }

        if (commentForm) {
          commentForm.addEventListener('submit', async e => {
            e.preventDefault();
            if (!currentUser) return showToast('Please login', 'error');
            const input = document.getElementById('comment-input');
            await fetchData('?action=add_song_comment', { method: 'POST', body: JSON.stringify({ song_id: activeCommentSongId, parent_id: document.getElementById('comment-parent-id').value || null, content: input.value }) });
            input.value = '';
            document.getElementById('comment-parent-id').value = '';
            input.placeholder = "Add a comment... (use @ to mention)";
            window.refreshComments(true);
          });
        }

        if (editCommentForm) {
          editCommentForm.addEventListener('submit', async e => {
            e.preventDefault();
            const id = document.getElementById('edit-comment-id').value;
            const content = document.getElementById('edit-comment-input').value;
            await fetchData('?action=edit_song_comment', { method: 'POST', body: JSON.stringify({ comment_id: id, content: content }) });
            bootstrap.Modal.getInstance(document.getElementById('edit-comment-modal')).hide();
            window.refreshComments(true);
          });
        }

        if (editPostForm) {
          editPostForm.addEventListener('submit', async e => {
            e.preventDefault();
            const id = document.getElementById('edit-post-id').value;
            const content = document.getElementById('edit-post-input').value;
            await fetchData('?action=edit_community_post', { method: 'POST', body: JSON.stringify({ id: id, content: content }) });
            bootstrap.Modal.getInstance(document.getElementById('edit-post-modal')).hide();
            loadView(currentView);
          });
        }

        const commentsSortSelect = document.getElementById('comments-sort-select');
        if (commentsSortSelect) {
          commentsSortSelect.addEventListener('change', () => window.refreshComments(true));
        }

        window.openNoteModal = (id = '', title = '', content = '') => {
          document.getElementById('note-id').value = id;
          document.getElementById('note-title').value = title;
          document.getElementById('note-content').value = content;
          bootstrap.Modal.getOrCreateInstance(document.getElementById('note-modal')).show();
        };

        window.deleteNote = async (id) => {
          if (!confirm('Delete this note?')) return;
          await fetchData('?action=delete_note', { method:'POST', body: JSON.stringify({id}) });
          loadView(currentView);
        };

        const noteForm = document.getElementById('note-form');
        if (noteForm) {
          noteForm.addEventListener('submit', async e => {
            e.preventDefault();
            const id = document.getElementById('note-id').value;
            const title = document.getElementById('note-title').value;
            const content = document.getElementById('note-content').value;
            await fetchData('?action=save_note', { method:'POST', body: JSON.stringify({id, title, content}) });
            bootstrap.Modal.getInstance(document.getElementById('note-modal')).hide();
            loadView(currentView);
          });
        }

        window.togglePostReaction = async (post_id, reaction) => {
          await fetchData('?action=toggle_post_reaction', { method:'POST', body: JSON.stringify({post_id, reaction}) });
          loadView(currentView);
        };
        contentArea.addEventListener('click', async e => {
          const target = e.target;

          const mentionLink = target.closest('.mention-link');
          if (mentionLink) {
            e.stopPropagation();
            const artistName = mentionLink.dataset.artist;
            loadView({ type: 'artist_songs', param: artistName, sort: 'album_asc', filter_user_id: '', artist_name: '' });
            hideMobileSidebar();
            return;
          }

          if (multiSelectMode) {
            const songItem = target.closest('.song-item');
            if (songItem) {
              e.preventDefault();
              e.stopPropagation();
              toggleSongSelection(songItem);
              return;
            }
          }
          
          const messageBtn = target.closest('.message-btn');
          if (messageBtn) {
            e.stopPropagation();
            window.openChat(messageBtn.dataset.userId, decodeURIComponent(messageBtn.dataset.artist));
            return;
          }

          const blockBtn = target.closest('.block-btn');
          if (blockBtn) {
             e.stopPropagation();
             const userId = blockBtn.dataset.userId;
             if (!confirm('Are you sure you want to block/unblock this user? Blocking prevents them from messaging or following you.')) return;
             fetchData('?action=toggle_block', {
               method: 'POST', headers: {'Content-Type': 'application/json'},
               body: JSON.stringify({ blocked_id: userId })
             }).then(res => {
               if (res && (res.status === 'blocked' || res.status === 'unblocked')) {
                 showToast(`User ${res.status}.`, 'success');
                 loadView(currentView); // Refresh profile header to reflect changes
               } else {
                 showToast(res?.message || 'Error toggling block', 'error');
               }
             });
             return;
          }

          const followBtn = target.closest('.follow-btn');
          if (followBtn) {
            e.stopPropagation();
            const userId = followBtn.dataset.userId;
            fetchData('?action=toggle_follow', {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ following_id: userId })
            }).then(async res => {
              if (res && res.status === 'followed') {
                followBtn.textContent = 'Unfollow';
                followBtn.classList.remove('btn-danger');
                followBtn.classList.add('btn-outline-light');
                
                // Fetch Recommended Artists and show the dismissible container
                const recs = await fetchData(`?action=get_recommended_artists&target_id=${userId}`);
                if (recs && recs.length > 0) {
                  const alertContainer = document.getElementById('recommendation-alert-container');
                  if (alertContainer) {
                    alertContainer.innerHTML = `
                      <div class="alert bg-dark border-secondary text-white alert-dismissible fade show mt-3 position-relative shadow-lg" role="alert" style="border-radius: 12px;">
                        <h6 class="alert-heading text-info fw-bold mb-3"><i class="bi bi-stars"></i> Because you followed this artist...</h6>
                        <div class="d-flex gap-3 overflow-auto pb-2" style="scrollbar-width: thin;">
                          ${recs.map(a => `
                            <div class="text-center user-profile-link" data-userid="${a.id}" data-artist="${encodeURIComponent(a.artist)}" style="width: 80px; flex-shrink: 0; cursor: pointer;">
                              <img src="?action=get_profile_picture&id=${a.id}" class="rounded-circle mb-2" style="width: 60px; height: 60px; object-fit: cover;">
                              <div class="small text-truncate w-100 hover-underline">${escapeHTML(a.artist)}</div>
                            </div>
                          `).join('')}
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                      </div>
                    `;
                  }
                }

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

          const connTrigger = target.closest('.connection-trigger');
          if (connTrigger) {
            e.preventDefault();
            e.stopPropagation();
            const connType = connTrigger.dataset.type;
            const uId = connTrigger.dataset.id;
            const modalEl = document.getElementById('connections-modal');
            if (modalEl) {
              document.getElementById('connections-modal-title').textContent = connType.charAt(0).toUpperCase() + connType.slice(1);
              const listEl = document.getElementById('connections-list');
              listEl.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-secondary"></div></div>';
              bootstrap.Modal.getOrCreateInstance(modalEl).show();
              
              fetchData(`?action=get_connections&id=${uId}&conn_type=${connType}`).then(data => {
                if (data && data.length > 0) {
                  listEl.innerHTML = data.map(u => `
                    <div class="list-group-item bg-transparent text-white border-secondary px-3 py-2 d-flex align-items-center gap-3 user-profile-link hover-bg-dark" data-userid="${u.id}" data-artist="${encodeURIComponent(u.artist)}" style="cursor: pointer;">
                      <img src="?action=get_profile_picture&id=${u.id}" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">
                      <div class="fw-bold hover-underline text-truncate flex-grow-1">${escapeHTML(u.artist)}</div>
                      <i class="bi bi-chevron-right text-secondary"></i>
                    </div>
                  `).join('');
                } else {
                  listEl.innerHTML = `<div class="p-4 text-center text-secondary">No ${connType} found.</div>`;
                }
              });
            }
            return;
          }
          const addMixBtn = target.closest('.add-mix-to-playlist-btn');
          if (addMixBtn) {
            e.stopPropagation();
            mixIdForPlaylist = addMixBtn.dataset.mixId;
            songIdForPlaylist = null;
            await reRenderPlaylistModal();
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
            const playlistData = { 
              publicId: playlistMoreBtn.dataset.publicId, 
              name: playlistMoreBtn.dataset.name,
              isCollab: parseInt(playlistMoreBtn.dataset.isCollab || 0),
              isPrivate: parseInt(playlistMoreBtn.dataset.isPrivate || 0),
              ownerId: playlistMoreBtn.dataset.ownerId
            };
            buildAndShowPlaylistContextMenu(playlistMoreBtn, playlistData);
            return;
          }
          const shareBtn = target.closest('.share-view-btn');
          if (shareBtn) {
            e.stopPropagation();
            const { shareType, shareId, shareName, artistId, artistName } = shareBtn.dataset;
            showShareModal(shareType, shareId, shareName, artistId, artistName);
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
          const exportNotesBtn = target.closest('#export-notes-btn');
          if (exportFavoritesBtn || exportNotesBtn) {
            return; // Allow the <a> tags to download the JSON files natively
          }
          const recacheAllBtn = target.closest('#recache-all-offline-btn');
          if (recacheAllBtn) {
             if (!confirm("Are you sure you want to re-download all offline songs? This might take a while and consume data.")) return;
             if (offlineViewSongsData && offlineViewSongsData.length > 0) {
                const processAll = async () => {
                   recacheAllBtn.disabled = true;
                   recacheAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
                   for (const song of offlineViewSongsData) {
                      await recacheOfflineSong(parseInt(song.id));
                   }
                   recacheAllBtn.disabled = false;
                   recacheAllBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-cache All';
                   showToast('Finished re-caching all offline songs!', 'success');
                };
                processAll();
             }
             return;
          }

          const importOfflineBtn = target.closest('#import-offline-btn');
          if (importOfflineBtn) {
            const importOfflineModalEl = document.getElementById('import-offline-modal');
            if (importOfflineModalEl) bootstrap.Modal.getOrCreateInstance(importOfflineModalEl).show();
            return;
          }
          const importFavoritesBtn = target.closest('#import-favorites-btn');
          if (importFavoritesBtn) {
            importFavoritesModal.show();
            return;
          }
          const importNotesBtn = target.closest('#import-notes-btn');
          if (importNotesBtn) {
            const importNotesModalEl = document.getElementById('import-notes-modal');
            if (importNotesModalEl) bootstrap.Modal.getOrCreateInstance(importNotesModalEl).show();
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
            const userId = ''; // Force empty to prevent breaking queries by looking up uploader IDs
            const artistsList = artistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');
            
            if (artistsList.length > 1) {
              if (artistsModalBody) {
                const modalTitle = artistsModalEl.querySelector('.modal-title');
                if (modalTitle) modalTitle.textContent = 'Artists';
                artistsModalBody.innerHTML = `
                  <div class="list-group list-group-flush rounded">
                    ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userId}">${a}</button>`).join('')}
                  </div>
                `;
                if (artistsModal) artistsModal.show();
              }
            } else {
              loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userId, artist_name: '' });
            }
            return;
          }
          const songAlbumEl = target.closest('.song-album, .shelf-item[data-album]');
          if (songAlbumEl) {
            e.stopPropagation();
            const songItem = target.closest('.song-item, .shelf-item');
            
            let rawUserId = songAlbumEl.getAttribute('data-userid') || (songItem ? (songItem.getAttribute('data-userid') || songItem.getAttribute('data-song-user-id')) : '') || '';
            let validUserId = parseInt(rawUserId, 10);
            let safeUserId = (isNaN(validUserId) || validUserId <= 0) ? '' : validUserId;
            
            let albumRaw = decodeURIComponent(songAlbumEl.getAttribute('data-album') || '');
            let songArtistRaw = songItem ? (songItem.getAttribute('data-song-artist') || songItem.querySelector('.item-subtitle')?.textContent || '') : '';
            const songArtistsList = songArtistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');

            if (songArtistsList.length > 1) {
              if (artistsModalBody) {
                const modalTitle = artistsModalEl.querySelector('.modal-title');
                if (modalTitle) modalTitle.textContent = 'Select Album Artist';
                artistsModalBody.innerHTML = `
                  <div class="list-group list-group-flush rounded">
                    ${songArtistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary album-modal-item py-3" data-album="${encodeURIComponent(albumRaw)}" data-artist="${encodeURIComponent(a)}" data-userid="${safeUserId}">${escapeHTML(albumRaw)} (${escapeHTML(a)})</button>`).join('')}
                  </div>
                `;
                if (artistsModal) artistsModal.show();
              }
            } else {
              loadView({ 
                type: 'album_songs', 
                param: albumRaw, 
                sort: 'title_asc', 
                filter_user_id: safeUserId,
                artist_name: songArtistRaw
              });
            }
            return;
          }
          const cardEl = target.closest('.card, .shelf-item[data-playlist], .shelf-item[data-mix], .shelf-item[data-artist]');
          if (cardEl && !target.closest('.playlist-more-btn')) {
            let viewType, param, sort;
            let filterUserId = cardEl.dataset.userid || '';
            let filterArtistName = '';
            
            // Protect against 'undefined' or 'null' strings passed in HTML attributes
            if (filterUserId === 'undefined' || filterUserId === 'null') filterUserId = '';

            if (cardEl.dataset.artist) {
              viewType = 'artist_songs';
              param = decodeURIComponent(cardEl.dataset.artist);
              sort = 'album_asc';
              filterUserId = ''; 
            } else if (cardEl.dataset.album) {
              viewType = 'album_songs';
              param = decodeURIComponent(cardEl.dataset.album);
              sort = 'title_asc';
              filterArtistName = cardEl.dataset.artistname ? decodeURIComponent(cardEl.dataset.artistname) : '';
            } else if (cardEl.dataset.genre) {
              viewType = 'genre_songs';
              param = decodeURIComponent(cardEl.dataset.genre);
              sort = 'artist_asc';
            } else if (cardEl.dataset.year) {
              viewType = 'year_songs';
              param = decodeURIComponent(cardEl.dataset.year);
              sort = 'artist_asc';
            } else if (cardEl.dataset.playlist) {
              viewType = 'playlist_songs';
              param = decodeURIComponent(cardEl.dataset.playlist);
              sort = 'manual_order';
            } else if (cardEl.dataset.mix) {
              viewType = 'mix_songs';
              param = decodeURIComponent(cardEl.dataset.mix);
              sort = 'manual_order';
            }
            
            if (viewType) {
              loadView({ type: viewType, param: param, sort: sort, filter_user_id: filterUserId, artist_name: filterArtistName });
              return;
            }
          }
          const viewNoteTrigger = target.closest('.view-note-trigger');
          if (viewNoteTrigger) {
            e.stopPropagation();
            document.getElementById('view-note-title').innerHTML = decodeHTML(viewNoteTrigger.dataset.title);
            document.getElementById('view-note-content').innerHTML = parseUserText(viewNoteTrigger.dataset.content);
             
            const rawDate = viewNoteTrigger.dataset.date;
            if (rawDate) {
              const dateObj = new Date(rawDate);
              const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
              document.getElementById('view-note-date').innerHTML = `<i class="bi bi-calendar3"></i> Last modified: ${dateObj.toLocaleDateString(undefined, options)}`;
            } else {
              document.getElementById('view-note-date').innerHTML = '';
            }
             
            bootstrap.Modal.getOrCreateInstance(document.getElementById('view-note-modal')).show();
            return;
          }
          const newNoteBtn = target.closest('.new-note-btn');
          if (newNoteBtn) {
            e.stopPropagation();
            openNoteModal();
            return;
          }
          const editNoteBtn = target.closest('.edit-note-btn');
          if (editNoteBtn) {
            e.stopPropagation();
            openNoteModal(editNoteBtn.dataset.id, decodeHTML(editNoteBtn.dataset.title), decodeHTML(editNoteBtn.dataset.content));
            return;
          }
          const deleteNoteBtn = target.closest('.delete-note-btn');
          if (deleteNoteBtn) {
            e.stopPropagation();
            deleteNote(deleteNoteBtn.dataset.id);
            return;
          }
          const communityReactBtn = target.closest('.community-react-btn');
          if (communityReactBtn) {
            e.stopPropagation();
            window.togglePostReaction(communityReactBtn.dataset.id, communityReactBtn.dataset.reaction);
            return;
          }
          const editPostBtn = target.closest('.edit-post-btn');
          if (editPostBtn) {
            e.stopPropagation();
            document.getElementById('edit-post-id').value = editPostBtn.dataset.id;
            document.getElementById('edit-post-input').value = decodeHTML(editPostBtn.dataset.content);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('edit-post-modal')).show();
            return;
          }
          const deletePostBtn = target.closest('.delete-post-btn');
          if (deletePostBtn) {
            e.stopPropagation();
            if(confirm('Delete this post?')) {
              fetchData('?action=delete_community_post', { method:'POST', body: JSON.stringify({id: deletePostBtn.dataset.id}) }).then(() => loadView(currentView));
            }
            return;
          }
          const communityReplyBtn = target.closest('.community-reply-btn');
          if (communityReplyBtn) {
            e.stopPropagation();
            document.getElementById('community-parent-id').value = communityReplyBtn.dataset.id;
            const input = document.getElementById('community-post-input');
            input.placeholder = "Replying to post...";
            const username = communityReplyBtn.dataset.username.replace(/\s+/g, '');
            input.value = `@${username} `;
            input.focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
          }
          const seeAllButton = target.closest('.shelf-header button');
          if (seeAllButton) {
            e.stopPropagation();
            const viewType = seeAllButton.dataset.view;
            const viewParam = seeAllButton.dataset.viewParam;
            let sort = 'artist_asc';
            if (['get_favorites', 'get_offline_songs', 'playlist_songs'].includes(viewType)) sort = 'manual_order';
            if (viewType === 'get_user_playlists') sort = 'modified_desc';
            if (viewType === 'get_history') sort = 'history_desc';
            if (viewType === 'artist_songs') sort = 'album_asc';

            loadView({ type: viewType, param: viewParam || '', sort: sort, filter_user_id: '' });
            return;
          }

          const songItem = target.closest('.song-item, .shelf-item[data-song-id], .top-result-card');
          if (songItem) {
            if (songItem.classList.contains('offline-missing')) {
              showToast('This song is not cached for offline listening. Please open the menu and Re-download it.', 'error');
              return;
            }
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
            case 'play_next':
              if (queueIndex !== -1) {
                queue.splice(queueIndex + 1, 0, parseInt(id));
                originalQueue.splice(queueIndex + 1, 0, parseInt(id));
              } else {
                setQueueAndPlay(parseInt(id));
              }
              queueDirty = true; // Tell the system the queue changed
              renderQueue();     // Redraw the "Up Next" tabs!
              showToast('Added to play next', 'success');
              break;
              
            case 'add_to_queue':
              queue.push(parseInt(id));
              originalQueue.push(parseInt(id));
              queueDirty = true; // Tell the system the queue changed
              renderQueue();     // Redraw the "Up Next" tabs!
              showToast('Added to end of queue', 'success');
              break;
            case 'share_song':
              showShareModal('song', id, name);
              break;
            case 'go_artist':
              closeOpenModals();
              const artistRaw = decodeURIComponent(name);
              const artistsList = artistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');
              if (artistsList.length > 1) {
                if (artistsModalBody) {
                  const modalTitle = artistsModalEl.querySelector('.modal-title');
                  if (modalTitle) modalTitle.textContent = 'Artists';
                  artistsModalBody.innerHTML = `
                    <div class="list-group list-group-flush rounded">
                      ${artistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary artist-modal-item py-3" data-artist="${encodeURIComponent(a)}" data-userid="${userid}">${a}</button>`).join('')}
                    </div>
                  `;
                  if (artistsModal) artistsModal.show();
                }
              } else {
                loadView({ type: 'artist_songs', param: artistRaw, sort: 'album_asc', filter_user_id: userid || '', artist_name: '' });
                hideMobileSidebar();
              }
              break;
            case 'go_album':
              closeOpenModals();
              const albumRaw = decodeURIComponent(name);
              const songArtistRaw = decodeURIComponent(item.dataset.artistname || '');
              const songArtistsList = songArtistRaw.split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i).filter(a => a && a.trim() !== '');
              
              let validMenuUserId = parseInt(userid, 10);
              let safeMenuUserId = (isNaN(validMenuUserId) || validMenuUserId <= 0) ? '' : validMenuUserId;

              if (songArtistsList.length > 1) {
                if (artistsModalBody) {
                  const modalTitle = artistsModalEl.querySelector('.modal-title');
                  if (modalTitle) modalTitle.textContent = 'Select Album Artist';
                  artistsModalBody.innerHTML = `
                    <div class="list-group list-group-flush rounded">
                      ${songArtistsList.map(a => `<button type="button" class="list-group-item list-group-item-action bg-transparent text-white border-secondary album-modal-item py-3" data-album="${encodeURIComponent(albumRaw)}" data-artist="${encodeURIComponent(a)}" data-userid="${safeMenuUserId}">${escapeHTML(albumRaw)} (${escapeHTML(a)})</button>`).join('')}
                    </div>
                  `;
                  if (artistsModal) artistsModal.show();
                }
              } else {
                loadView({ type: 'album_songs', param: albumRaw, sort: 'title_asc', filter_user_id: safeMenuUserId, artist_name: songArtistRaw });
                hideMobileSidebar();
              }
              break;
            case 'show_all_genres':
              showAllGenresModal();
              break;
            case 'toggle_favorite':
              toggleFavorite(parseInt(id));
              break;
            case 'listen_later':
              fetchData('?action=toggle_listen_later', { method: 'POST', body: JSON.stringify({id: parseInt(id)}) })
                .then(res => { 
                  if(res) {
                    if(res.status === 'added') listenLaterSet.add(parseInt(id));
                    else listenLaterSet.delete(parseInt(id));
                    showToast(res.status === 'added' ? 'Added to Listen Later' : 'Removed from Listen Later', 'success'); 
                  }
                });
              break;
            case 'view_comments':
              window.openCommentsModal(parseInt(id));
              break;
            case 'toggle_offline':
              const offRes = await fetchData('?action=toggle_offline', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(id) })
              });
              if (offRes) {
                if (offRes.status === 'added') {
                  offlineSongsSet.add(parseInt(id));
                  
                  let tc = document.querySelector('.toast-container');
                  if (!tc) {
                    tc = document.createElement('div');
                    tc.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                    tc.style.zIndex = "1100";
                    document.body.appendChild(tc);
                  }
                  
                  const progressToast = document.createElement('div');
                  progressToast.className = 'toast align-items-center text-white bg-info border-0 show';
                  progressToast.innerHTML = `<div class="d-flex"><div class="toast-body" id="offline-progress-${id}">Caching song (0%)...</div></div>`;
                  tc.appendChild(progressToast);

                  try {
                    const cache = await caches.open('php-music-offline');
                    const v = globalSongCache[id] ? (globalSongCache[id].last_modified || 0) : 0;
                    await cache.add(`?action=get_image&id=${id}&v=${v}`);
                    await cache.add(`?action=get_song_data&id=${id}`);

                    const response = await fetch(`?action=get_stream&id=${id}`);
                    const contentLength = response.headers.get('content-length');
                    
                    if (!contentLength) {
                      await cache.put(`?action=get_stream&id=${id}`, response.clone());
                    } else {
                      const total = parseInt(contentLength, 10);
                      let loaded = 0;
                      const reader = response.body.getReader();
                      const stream = new ReadableStream({
                        async start(controller) {
                          while (true) {
                            const { done, value } = await reader.read();
                            if (done) break;
                            loaded += value.length;
                            const pct = Math.round((loaded / total) * 100);
                            const pctText = document.getElementById(`offline-progress-${id}`);
                            if(pctText) pctText.innerText = `Caching song (${pct}%)...`;
                            controller.enqueue(value);
                          }
                          controller.close();
                        }
                      });
                      const newResponse = new Response(stream, {
                        headers: response.headers,
                        status: response.status,
                        statusText: response.statusText
                      });
                      await cache.put(`?action=get_stream&id=${id}`, newResponse);
                    }
                    
                    progressToast.classList.replace('bg-info', 'bg-success');
                    document.getElementById(`offline-progress-${id}`).innerText = 'Song is now available offline!';
                    setTimeout(() => progressToast.remove(), 3000);
                    
                  } catch(e) {
                    console.error(e);
                    progressToast.classList.replace('bg-info', 'bg-danger');
                    document.getElementById(`offline-progress-${id}`).innerText = 'Failed to cache completely.';
                    setTimeout(() => progressToast.remove(), 3000);
                    offlineSongsSet.delete(parseInt(id));
                    fetchData('?action=toggle_offline', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(id) }) });
                  }
                } else {
                  offlineSongsSet.delete(parseInt(id));
                  try {
                    const cache = await caches.open('php-music-offline');
                    const keys = await cache.keys();
                    for (let req of keys) {
                      const u = new URL(req.url);
                      if (u.searchParams.get('id') == id) await cache.delete(req);
                    }
                  } catch(e) {}
                  showToast('Removed from offline list.', 'success');
                  
                  if (currentView.type === 'get_offline_songs') {
                     const row = document.querySelector(`.song-item[data-song-id="${id}"]`);
                     if(row) {
                       row.style.opacity = '0';
                       setTimeout(() => row.remove(), 300);
                     }
                  }
                }
              }
              break;
            case 'recache_offline':
              await recacheOfflineSong(parseInt(id));
              break;
            case 'save_to_device':
              try {
                const res = await caches.match(`?action=get_stream&id=${id}`);
                if (res) {
                  const blob = await res.blob();
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = `${decodeURIComponent(title)}.mp3`;
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                  showToast('Downloaded from device cache successfully!', 'success');
                } else {
                  window.location.href = `?action=download_song&id=${id}`;
                }
              } catch (e) {
                window.location.href = `?action=download_song&id=${id}`;
              }
              break;
            case 'add_to_playlist':
              songIdForPlaylist = [parseInt(id)];
              mixIdForPlaylist = null;
              await reRenderPlaylistModal();
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
            case 'toggle_collab':
              const collabRes = await fetchData('?action=toggle_collaborative', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ public_id: publicId })
              });
              if (collabRes) {
                showToast(collabRes.message, collabRes.status);
                // Dynamically update the HTML button so the menu knows it changed!
                if (collabRes.status === 'success' && contextMenuItemEl) {
                  contextMenuItemEl.dataset.isCollab = collabRes.is_collaborative;
                }
              }
              break;
            case 'manage_collab':
              const collabModalEl = document.getElementById('collab-modal');
              if (collabModalEl) {
                const collabModal = new bootstrap.Modal(collabModalEl);
                const collabInput = document.getElementById('collab-input');
                const collabDropdown = document.getElementById('collab-search-dropdown');
                let selectedCollabUserId = null;
                
                const loadCollabs = async () => {
                  const res = await fetchData('?action=manage_collaborators', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ public_id: publicId, collab_action: 'list' })
                  });
                  const listEl = document.getElementById('collab-list');
                  if (res && res.status === 'success') {
                    if(res.collaborators.length === 0) listEl.innerHTML = '<p class="text-secondary small">No collaborators yet.</p>';
                    else listEl.innerHTML = res.collaborators.map(c => `
                      <div class="list-group-item bg-transparent text-white d-flex justify-content-between align-items-center border-secondary px-0">
                        <div class="d-flex align-items-center gap-3">
                          <img src="?action=get_profile_picture&id=${c.id}" class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover;">
                          <div>
                            <div>${escapeHTML(c.artist)}</div>
                            <div class="small text-secondary" style="font-size: 0.75rem;">ID: ${c.id}</div>
                          </div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger collab-remove-btn" data-id="${c.id}"><i class="bi bi-x-lg"></i></button>
                      </div>
                    `).join('');
                  }
                };

                let collabSearchTimeout;
                collabInput.oninput = (e) => {
                  clearTimeout(collabSearchTimeout);
                  selectedCollabUserId = null;
                  const q = e.target.value.trim();
                  if (q === '') {
                    collabDropdown.classList.add('d-none');
                    return;
                  }
                  collabSearchTimeout = setTimeout(async () => {
                    const res = await fetchData('?action=manage_collaborators', {
                      method: 'POST', headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({ public_id: publicId, collab_action: 'search', query: q })
                    });
                    if (res && res.status === 'success') {
                      if (res.users.length > 0) {
                        collabDropdown.innerHTML = res.users.map(u => `
                          <div class="search-dropdown-item collab-user-item" data-id="${u.id}" data-artist="${escapeHTML(u.artist)}">
                            <img src="?action=get_profile_picture&id=${u.id}" class="search-dropdown-img rounded-circle">
                            <div class="search-dropdown-text">
                              <div class="search-dropdown-title">${escapeHTML(u.artist)}</div>
                              <div class="search-dropdown-subtitle">ID: ${u.id}</div>
                            </div>
                          </div>
                        `).join('');
                        collabDropdown.classList.remove('d-none');
                      } else {
                        collabDropdown.innerHTML = '<div class="p-3 text-secondary text-center small">No users found</div>';
                        collabDropdown.classList.remove('d-none');
                      }
                    }
                  }, 300);
                };

                // Handle dropdown clicks natively on the element
                collabDropdown.onclick = (e) => {
                  const item = e.target.closest('.collab-user-item');
                  if (item) {
                    selectedCollabUserId = item.dataset.id;
                    collabInput.value = item.dataset.artist;
                    collabDropdown.classList.add('d-none');
                  }
                };

                // Close dropdown if clicked outside
                document.addEventListener('click', (e) => {
                  if (collabDropdown && !collabDropdown.contains(e.target) && e.target !== collabInput) {
                    collabDropdown.classList.add('d-none');
                  }
                });

                document.getElementById('collab-add-btn').onclick = async () => {
                  const target = collabInput.value.trim();
                  if(!target && !selectedCollabUserId) return;
                  
                  const reqBody = { public_id: publicId, collab_action: 'add' };
                  if (selectedCollabUserId) reqBody.target_id = selectedCollabUserId;
                  else reqBody.target = target;
                  
                  const res = await fetchData('?action=manage_collaborators', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(reqBody)
                  });
                  if (res) { 
                    showToast(res.message, res.status); 
                    if (res.status === 'success') {
                      loadCollabs(); 
                      collabInput.value = '';
                      selectedCollabUserId = null;
                    }
                  }
                };

                const expireSelect = document.getElementById('collab-expire-select');
                const customExpireInput = document.getElementById('collab-custom-expire');
                if (expireSelect && customExpireInput) {
                  expireSelect.onchange = (e) => {
                    if (e.target.value === 'custom') {
                      customExpireInput.classList.remove('d-none');
                    } else {
                      customExpireInput.classList.add('d-none');
                    }
                  };
                }

                const copyLinkBtn = document.getElementById('collab-copy-link-btn');
                if (copyLinkBtn) {
                  const newCopyBtn = copyLinkBtn.cloneNode(true);
                  copyLinkBtn.parentNode.replaceChild(newCopyBtn, copyLinkBtn);
                  
                  newCopyBtn.addEventListener('click', async () => {
                    let expireVal = expireSelect ? expireSelect.value : '1440';
                    if (expireVal === 'custom') {
                      expireVal = customExpireInput.value.trim();
                      if (!expireVal || isNaN(expireVal) || parseInt(expireVal) <= 0) {
                        return showToast('Please enter a valid number of minutes.', 'error');
                      }
                    } else if (expireVal === 'forever') {
                      expireVal = null;
                    }
                    
                    newCopyBtn.disabled = true;
                    newCopyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating...';

                    const res = await fetchData('?action=manage_collaborators', {
                      method: 'POST', headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({ public_id: publicId, collab_action: 'generate_link', expire: expireVal })
                    });

                    if (res && res.status === 'success') {
                      const inviteUrl = window.location.origin + window.location.pathname + '?collab_invite=' + res.token;
                      const onSuccess = () => {
                        newCopyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Link Copied!';
                        newCopyBtn.classList.replace('btn-outline-light', 'btn-success');
                        setTimeout(() => {
                          newCopyBtn.innerHTML = '<i class="bi bi-link-45deg"></i> Generate & Copy Link';
                          newCopyBtn.classList.replace('btn-success', 'btn-outline-light');
                        }, 2000);
                      };
                      const onError = () => showToast('Failed to copy link.', 'error');

                      if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(inviteUrl).then(onSuccess).catch(onError);
                      } else {
                        const textArea = document.createElement("textarea");
                        textArea.value = inviteUrl;
                        textArea.style.position = "fixed";
                        textArea.style.opacity = "0";
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        try {
                          if (document.execCommand('copy')) onSuccess();
                          else onError();
                        } catch (err) { onError(); }
                        document.body.removeChild(textArea);
                      }
                    } else {
                      showToast(res?.message || 'Error generating link', 'error');
                      newCopyBtn.innerHTML = '<i class="bi bi-link-45deg"></i> Generate & Copy Link';
                    }
                    newCopyBtn.disabled = false;
                  });
                }
                
                collabModalEl.onclick = async (evt) => {
                  const removeBtn = evt.target.closest('.collab-remove-btn');
                  if (removeBtn) {
                    if(!confirm('Remove this collaborator?')) return;
                    const res = await fetchData('?action=manage_collaborators', {
                      method: 'POST', headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({ public_id: publicId, collab_action: 'remove', collab_user_id: removeBtn.dataset.id })
                    });
                    if (res) { showToast(res.message, res.status); loadCollabs(); }
                  }
                };

                collabModal.show();
                loadCollabs();
              }
              break;
            case 'edit_playlist':
              document.getElementById('edit-playlist-id-input').value = publicId;
              document.getElementById('edit-playlist-name-input').value = name;
              document.getElementById('edit-playlist-is-private').checked = item.dataset.isPrivate === '1';
              if (editPlaylistModal) editPlaylistModal.show();
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
            case 'leave_collab':
              if (confirm(`Are you sure you want to leave this collaboration?`)) {
                const result = await fetchData('?action=leave_collab', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ public_id: publicId })
                });
                if (result && result.status === 'success') {
                  showToast(result.message, 'success');
                  if (currentView.type === 'get_collab_playlists') {
                     loadView(currentView);
                  } else {
                     loadView({ type: 'get_collab_playlists', param: '', sort: 'name_asc', filter_user_id: '' });
                  }
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
              
              if (typeof metadataCropper !== 'undefined' && metadataCropper) {
                metadataCropper.destroy();
                metadataCropper = null;
              }
              
              document.getElementById('edit-metadata-cover-preview').src = `?action=get_image&id=${id}&v=${Date.now()}`;
              document.getElementById('edit-metadata-cover').value = '';
              const metaSongDataToEdit = await fetchData(`?action=get_song_data&id=${id}`);
              document.getElementById('edit-metadata-lyrics').value = metaSongDataToEdit ? (metaSongDataToEdit.lyrics || '') : '';
              document.getElementById('edit-metadata-is-private').checked = metaSongDataToEdit ? (metaSongDataToEdit.is_private == 1) : false;
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
            case 'song_audio_settings':
              const sasModalEl = document.getElementById('song-audio-settings-modal');
              if (sasModalEl) {
                document.getElementById('sas-song-id').value = id;
                document.getElementById('sas-song-title').textContent = decodeURIComponent(title || '');
                
                const sasVolSlider = document.getElementById('sas-vol-slider');
                const sasVolVal = document.getElementById('sas-vol-val');
                const sasEqBands = document.querySelectorAll('.sas-eq-band');
                
                // Fetch fresh config from globalSongCache or currentSong
                let targetVol = globalVolumeMultiplier;
                let targetEQ = [0,0,0,0,0];
                const activeSongData = globalSongCache[id] || (currentSong && currentSong.id == id ? currentSong : null);

                if (activeSongData && activeSongData.volume_multiplier !== undefined && activeSongData.volume_multiplier !== null) {
                  targetVol = parseFloat(activeSongData.volume_multiplier);
                }
                if (activeSongData && activeSongData.eq_bands && Array.isArray(activeSongData.eq_bands)) {
                  targetEQ = activeSongData.eq_bands;
                }

                sasVolSlider.value = targetVol;
                sasVolVal.textContent = sasVolSlider.value + 'x';
                sasEqBands.forEach((band, i) => band.value = targetEQ[i] !== undefined ? targetEQ[i] : 0);
                document.getElementById('sas-eq-preset-select').value = 'Custom';
                
                bootstrap.Modal.getOrCreateInstance(sasModalEl).show();
              }
              break;
            case 'download_song':
              window.location.href = `?action=download_song&id=${id}`;
              break;
            case 'download_cover':
              window.location.href = `?action=download_cover&id=${id}`;
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
            const createBtn = e.target.closest('#quick-create-playlist-btn');
            if (createBtn) {
               const nameInput = document.getElementById('quick-create-playlist-input');
               const name = nameInput.value.trim();
               if (!name) return showToast('Playlist name cannot be empty', 'error');
               const data = await fetchData('?action=create_playlist', {
                  method: 'POST', headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({ name: name, is_private: 0 })
               });
               if (data && data.status === 'success') {
                  showToast('Playlist created', 'success');
                  await reRenderPlaylistModal();
               }
               return;
            }

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
              renderQueue(false); // Triggers infinite scroll for desktop Up Next
            }
          });
        }
        
        const mpQueuePane = document.getElementById('mp-queue-pane');
        if (mpQueuePane) {
          mpQueuePane.addEventListener('scroll', () => {
            if (mpQueuePane.scrollTop + mpQueuePane.clientHeight >= mpQueuePane.scrollHeight - 300) {
              renderQueue(false); // Triggers infinite scroll for mobile Up Next
            }
          });
        }

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault();
          deferredInstallPrompt = e;
          // Show the install button ONLY when the browser confirms the app isn't installed yet
          if (installPwaBtn) {
            installPwaBtn.classList.remove('d-none');
          }
        });

        // Hide the button permanently once the user successfully installs the app
        window.addEventListener('appinstalled', () => {
          deferredInstallPrompt = null;
          if (installPwaBtn) {
            installPwaBtn.classList.add('d-none');
          }
        });

        if (installPwaBtn) {
          installPwaBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!deferredInstallPrompt) {
              showToast('To install, use your browser menu (Add to Home Screen).', 'info');
              return;
            }
            deferredInstallPrompt.prompt();
            const { outcome } = await deferredInstallPrompt.userChoice;
            
            // Hide the button immediately if they accepted the installation prompt
            if (outcome === 'accepted') {
              deferredInstallPrompt = null;
              installPwaBtn.classList.add('d-none');
            }
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
                  width: 425,
                  height: 780
                });

                docPipWindow.addEventListener('resize', () => {
                  if (docPipWindow.innerWidth !== 425 || docPipWindow.innerHeight !== 780) {
                    docPipWindow.resizeTo(425, 780);
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
                
                  body.theme-light-bg { background-color: #ffffff !important; color: #000000 !important; }
                  body.theme-light-bg .text-secondary { color: rgba(0,0,0,0.6) !important; font-weight: 500; }
                  body.theme-light-bg .pip-tab-btn { color: rgba(0,0,0,0.6) !important; }
                  body.theme-light-bg .pip-tab-btn:hover { color: #000000 !important; }
                  body.theme-light-bg .pip-tab-btn.active { color: #000000 !important; border-bottom-color: #000000 !important; font-weight: 800; text-shadow: none; }
                  body.theme-light-bg .pip-lyric-line { color: rgba(0,0,0,0.6) !important; }
                  body.theme-light-bg .pip-lyric-line:hover { color: #000000 !important; }
                  body.theme-light-bg .pip-lyric-line.active { color: var(--ytm-accent) !important; font-weight: 800; text-shadow: none; }
                  body.theme-light-bg .title, body.theme-light-bg .artist { color: #000000 !important; }
                  body.theme-light-bg .time-stamps span { color: rgba(0,0,0,0.6) !important; }
                  body.theme-light-bg .player-btn { color: #333333 !important; }
                  body.theme-light-bg .player-btn:hover, body.theme-light-bg .player-btn.active { color: #000000 !important; }
                  body.theme-light-bg .play-btn { background-color: #000000 !important; color: #ffffff !important; }
                  body.theme-light-bg .progress-bar-bg { background-color: rgba(0, 0, 0, 0.2) !important; }
                  body.theme-light-bg .progress-bar-fg { background-color: #000000 !important; }
            
                  /* Force bootstrap icon tags inside control buttons to inherit their parent's inline font-sizes */
                  .player-modal-controls .player-btn .bi { font-size: inherit !important; }
                  .player-modal-controls .play-btn .bi { font-size: inherit !important; }
                `;
                docPipWindow.document.head.appendChild(extraStyle);
            
                docPipWindow.document.body.className = 'player-modal-content';
                docPipWindow.document.body.innerHTML = `
                  <div class="dynamic-blur-bg" id="pip-bg" style="position: absolute; z-index: -1;"></div>
                  <div style="display: flex; flex-direction: column; background: transparent; height: 100vh; position: relative; z-index: 1;">
                    <div class="pip-tabs">
                      <button class="pip-tab-btn active" id="pip-tab-player">Player</button>
                      <button class="pip-tab-btn" id="pip-tab-lyrics">Lyrics</button>
                    </div>
                    
                    <div class="pip-pane active mt-4" id="pip-pane-player" style="padding: 1rem 1.5rem 1.5rem 1.5rem; justify-content: space-evenly;">
                      <div style="width: 100%; max-width: min(440px, 48vh); margin: 0 auto; display: flex; flex-direction: column; height: 100%; justify-content: space-evenly;">
                        <div class="player-modal-art-wrapper" style="flex-grow: 1; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem; min-height: 0;">
                          <div class="position-relative shadow-lg" style="width: 100%; aspect-ratio: 1/1; border-radius: 12px; overflow: hidden;">
                            <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" id="pip-art" alt="Album Art" style="width: 100%; height: 100%; object-fit: cover;">
                            <canvas class="visualizer-canvas" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; pointer-events: none; z-index: 5;"></canvas>
                          </div>
                        </div>
                        <div class="player-modal-track-info mt-2" style="text-align: left; margin-bottom: 1rem;">
                          <h3 id="pip-title" class="title text-truncate" style="font-weight: 700; font-size: 1.5rem; margin-bottom: 0;">Song Title</h3>
                          <p id="pip-artist" class="artist text-truncate" style="color: var(--ytm-secondary-text); font-size: 1rem; margin-bottom: 0;">Artist Name</p>
                        </div>
                        <div class="player-modal-progress" style="width: 100%;">
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
                        <div class="player-modal-controls" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                          <button class="player-btn" id="pip-shuffle-btn" style="background: none; border: none; color: var(--ytm-secondary-text); font-size: 1.25rem; padding: 10px;"></button>
                          <button class="player-btn" id="pip-prev-btn" style="background: none; border: none; color: var(--ytm-primary-text); font-size: 1.25rem; padding: 10px;"></button>
                          <button class="player-btn play-btn" id="pip-play-pause-btn" style="background: var(--ytm-surface); border: none; color: var(--ytm-primary-text); font-size: 2rem; width: 70px; height: 70px; border-radius: 50%;"></button>
                          <button class="player-btn" id="pip-next-btn" style="background: none; border: none; color: var(--ytm-primary-text); font-size: 1.25rem;"></button>
                          <button class="player-btn" id="pip-repeat-btn" style="background: none; border: none; color: var(--ytm-secondary-text); font-size: 1.25rem; padding: 10px;"></button>
                        </div>
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
                      container.innerHTML = `<pre style="white-space: pre-wrap; font-family: 'Roboto', sans-serif; font-size: 1rem; padding: 1rem;" class="text-secondary">${escapeHTML(currentSong.lyrics)}</pre>`;
                    }
                  } else {
                    container.innerHTML = '<p class="text-secondary" style="margin-top: 2rem;">No lyrics available.</p>';
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
              await Promise.all(keys.map(key => {
                if (key !== 'php-music-offline') {
                  return caches.delete(key);
                }
              }));
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
        
        let apiDebounceTimer;
        const getApiBtn = document.getElementById('get-api-btn');
        if (getApiBtn) {
          getApiBtn.addEventListener('click', (e) => {
            e.preventDefault();
            hideMobileSidebar();
            
            // Debounce click to prevent prompt spam
            clearTimeout(apiDebounceTimer);
            apiDebounceTimer = setTimeout(() => {
              const pwd = prompt("API Access is locked.\n\nEnter Admin Password to generate endpoints for other sites:");
              if (pwd === 'admin') { // Automatically matches backend ADMIN_PASSWORD
                window.adminApiKey = pwd;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('api-modal')).show();
              } else if (pwd !== null) {
                showToast("Incorrect admin password.", "error");
              }
            }, 300);
          });
        }

        const navUploadBtn = document.getElementById('nav-upload-btn');
        if (navUploadBtn) {
          navUploadBtn.addEventListener('click', (e) => {
            e.preventDefault();
            hideMobileSidebar();
            if (currentUser && currentUser.verified === 'yes') {
              bootstrap.Modal.getOrCreateInstance(document.getElementById('upload-modal')).show();
            } else {
              const reqModalEl = document.getElementById('request-verification-modal');
              const reqModalBody = reqModalEl.querySelector('.modal-body');
              
              if (currentUser && currentUser.verified === 'pending') {
                reqModalBody.innerHTML = `
                  <i class="bi bi-hourglass-split text-info" style="font-size: 3.5rem; display: block; margin-bottom: 1rem;"></i>
                  <h5 class="text-white mb-3">Verification Pending</h5>
                  <p class="text-secondary mb-0">Your request is currently being reviewed by an administrator. Please check back later.</p>
                `;
              } else {
                reqModalBody.innerHTML = `
                  <i class="bi bi-cloud-upload text-secondary mb-3" style="font-size: 3rem; display: block;"></i>
                  <h5 class="text-white mb-3">Upload Permissions Required</h5>
                  <p class="text-secondary mb-4">Please notify the admin to verify your account so you can upload your own songs and share them with the community.</p>
                  <button class="btn btn-info w-100 fw-bold text-dark" id="send-verification-request-btn">Request Verification</button>
                `;
                
                const newBtn = document.getElementById('send-verification-request-btn');
                if (newBtn) {
                  newBtn.addEventListener('click', async () => {
                    newBtn.disabled = true;
                    newBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
                    const res = await fetchData('?action=request_verification', { method: 'POST' });
                    if (res) {
                      if (res.status === 'success') {
                        reqModalBody.innerHTML = `
                          <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem; display: block; margin-bottom: 1rem;"></i>
                          <h5 class="text-white mb-3">Request Sent!</h5>
                          <p class="text-secondary mb-0">Your request has been forwarded to the administrators. Please wait for approval.</p>
                        `;
                        await checkSession();
                      } else {
                        showToast(res.message, 'error');
                        newBtn.disabled = false;
                        newBtn.innerHTML = 'Request Verification';
                      }
                    } else {
                      newBtn.disabled = false;
                      newBtn.innerHTML = 'Request Verification';
                    }
                  });
                }
              }
              bootstrap.Modal.getOrCreateInstance(reqModalEl).show();
            }
          });
        }
        
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const restoreForm = document.getElementById('restore-form');
        const changeNameForm = document.getElementById('change-name-form');
        const changePwForm = document.getElementById('change-password-form');
        const profilePicForm = document.getElementById('profile-picture-form');
        const createPlaylistForm = document.getElementById('create-playlist-form');
        const editPlaylistForm = document.getElementById('edit-playlist-form');
        const importPlaylistForm = document.getElementById('import-playlist-form');
        const importNotesForm = document.getElementById('import-notes-form');
        if (importNotesForm) {
          importNotesForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fileInput = document.getElementById('import-notes-file');
            if (fileInput.files.length === 0) return;
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            reader.onload = async (event) => {
              try {
                const importData = JSON.parse(event.target.result);
                if (!importData.notes || !Array.isArray(importData.notes)) {
                  showToast('Invalid JSON format. Missing notes array.', 'error');
                  return;
                }
                
                const btn = importNotesForm.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
                
                const response = await fetch('?action=import_notes', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(importData)
                });
                
                const result = await response.json();
                if (result) {
                  showToast(result.message, result.status);
                  if (result.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('import-notes-modal')).hide();
                    importNotesForm.reset();
                    loadView(currentView); // Refresh the Notes view
                  }
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
              } catch (err) {
                showToast('Failed to parse JSON or import.', 'error');
                const btn = importNotesForm.querySelector('button[type="submit"]');
                btn.disabled = false;
                btn.textContent = 'Import Notes';
              }
            };
            reader.readAsText(file);
          });
        }
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
            
            // Refresh session & automatically stay on the current page logged in
            cachedExploreData = null;
            await checkSession();
            loadView(currentView);
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
              
              // Refresh session & automatically stay on the current page logged in
              cachedExploreData = null;
              await checkSession();
              loadView(currentView);
            }
          });
        }
        
        createPlaylistForm.addEventListener('submit', async e => {
          e.preventDefault();
          const name = document.getElementById('playlist-name-input').value;
          const is_private = document.getElementById('playlist-is-private').checked ? 1 : 0;
          const data = await fetchData('?action=create_playlist', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ name, is_private })
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
        
        if (editPlaylistForm) {
          editPlaylistForm.addEventListener('submit', async e => {
            e.preventDefault();
            const public_id = document.getElementById('edit-playlist-id-input').value;
            const name = document.getElementById('edit-playlist-name-input').value;
            const is_private = document.getElementById('edit-playlist-is-private').checked ? 1 : 0;
            const data = await fetchData('?action=edit_playlist', {
              method: 'POST', headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ public_id, name, is_private })
            });
            if (data && data.status === 'success') {
              if (editPlaylistModal) editPlaylistModal.hide();
              editPlaylistForm.reset();
              showToast(data.message, 'success');
              loadView(currentView);
            }
          });
        }

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

        const importOfflineForm = document.getElementById('import-offline-form');
        if (importOfflineForm) {
          importOfflineForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fileInput = document.getElementById('import-offline-file');
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
                
                const btn = importOfflineForm.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Scanning Library...';
                
                const response = await fetch('?action=import_offline', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(importData)
                });
                
                const result = await response.json();
                if (result) {
                  showToast(result.message, result.status);
                  if (result.status === 'success') {
                    const modalEl = document.getElementById('import-offline-modal');
                    if(modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    importOfflineForm.reset();
                    fetchData('?action=get_offline_ids').then(ids => {
                      if(ids) offlineSongsSet = new Set(ids.map(id => parseInt(id)));
                      currentView.sort = 'manual_order';
                      const sortSelectEl = document.getElementById('sort-select');
                      if (sortSelectEl) sortSelectEl.value = 'manual_order';
                      loadView(currentView);
                    });
                  }
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
              } catch (err) {
                showToast('Failed to parse JSON or import.', 'error');
                const btn = importOfflineForm.querySelector('button[type="submit"]');
                btn.disabled = false;
                btn.textContent = 'Import';
              }
            };
            reader.readAsText(file);
          });
        }
        
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

        let metadataCropper = null;

        if (editMetadataForm) {
          editMetadataForm.addEventListener('submit', async e => {
            e.preventDefault();
            
            const submitBtn = document.getElementById('edit-metadata-submit-btn');
            const progContainer = document.getElementById('metadata-progress-container');
            const progBar = document.getElementById('metadata-progress');
            
            submitBtn.disabled = true;
            progContainer.classList.remove('d-none');
            progBar.style.width = '0%';
            progBar.textContent = '0%';

            const formData = new FormData();
            formData.append('id', document.getElementById('edit-metadata-id').value);
            formData.append('title', document.getElementById('edit-metadata-title').value);
            formData.append('artist', document.getElementById('edit-metadata-artist').value);
            formData.append('album', document.getElementById('edit-metadata-album').value);
            formData.append('genre', document.getElementById('edit-metadata-genre').value);
            formData.append('lyrics', document.getElementById('edit-metadata-lyrics').value);
            formData.append('is_private', document.getElementById('edit-metadata-is-private').checked ? 1 : 0);

            const doMetadataUpload = () => {
              const xhr = new XMLHttpRequest();
              xhr.upload.onprogress = (evt) => {
                if (evt.lengthComputable) {
                  const pct = Math.round((evt.loaded / evt.total) * 100);
                  progBar.style.width = pct + '%';
                  progBar.textContent = pct + '%';
                }
              };
              xhr.open('POST', '?action=edit_metadata', true);
              xhr.onload = () => {
                submitBtn.disabled = false;
                progContainer.classList.add('d-none');
                if (xhr.status === 200) {
                  try {
                    const result = JSON.parse(xhr.responseText);
                    if (result && result.status === 'success') {
                      showToast(result.message, 'success');
                      if (metadataCropper) { metadataCropper.destroy(); metadataCropper = null; }
                      if (editMetadataModal) editMetadataModal.hide();
                      loadView(currentView);
                    } else {
                      showToast(result.message || 'Upload failed', 'error');
                    }
                  } catch(err) {
                    showToast('Invalid server response.', 'error');
                  }
                } else {
                  showToast('Upload error', 'error');
                }
              };
              xhr.onerror = () => {
                submitBtn.disabled = false;
                progContainer.classList.add('d-none');
                showToast('Network error during upload', 'error');
              };
              xhr.send(formData);
            };

            if (metadataCropper) {
              metadataCropper.getCroppedCanvas({ width: 600, height: 600 }).toBlob(blob => {
                formData.append('cover_image', blob, 'cover.jpg');
                doMetadataUpload();
              }, 'image/jpeg', 0.85);
            } else {
              const coverInput = document.getElementById('edit-metadata-cover');
              if (coverInput && coverInput.files.length > 0) {
                formData.append('cover_image', coverInput.files[0]);
              }
              doMetadataUpload();
            }
          });

          const coverInput = document.getElementById('edit-metadata-cover');
          if (coverInput) {
            coverInput.addEventListener('change', function(e) {
              if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                  const preview = document.getElementById('edit-metadata-cover-preview');
                  preview.src = e.target.result;
                  if (metadataCropper) metadataCropper.destroy();
                  metadataCropper = new Cropper(preview, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                    dragMode: 'move',
                    background: false
                  });
                };
                reader.readAsDataURL(this.files[0]);
              }
            });
          }
        }
        
        const sasVolSlider = document.getElementById('sas-vol-slider');
        const sasVolVal = document.getElementById('sas-vol-val');
        
        if (sasVolSlider) {
          sasVolSlider.addEventListener('input', e => {
            sasVolVal.textContent = e.target.value + 'x';
            const activeId = document.getElementById('sas-song-id').value;
            if (currentSong && currentSong.id == activeId && perSongGain) {
                perSongGain.gain.setTargetAtTime(parseFloat(e.target.value), audioCtx.currentTime, 0.1);
            }
          });
        }
        
        document.querySelectorAll('.sas-eq-band').forEach(slider => {
          slider.addEventListener('input', e => {
            document.getElementById('sas-eq-preset-select').value = 'Custom';
            const activeId = document.getElementById('sas-song-id').value;
            if (currentSong && currentSong.id == activeId && audioCtx) {
              const bandIndex = parseInt(e.target.dataset.band);
              eqBands[bandIndex].gain.setTargetAtTime(parseFloat(e.target.value), audioCtx.currentTime, 0.1);
            }
          });
        });

        const sasEqPresetSelect = document.getElementById('sas-eq-preset-select');
        if (sasEqPresetSelect) {
          sasEqPresetSelect.addEventListener('change', (e) => {
            if (e.target.value === 'Custom') return;
            const preset = EQ_PRESETS[e.target.value];
            if (preset) {
              const sasEqBands = document.querySelectorAll('.sas-eq-band');
              preset.forEach((val, i) => { 
                if (sasEqBands[i]) sasEqBands[i].value = val; 
              });
              const activeId = document.getElementById('sas-song-id').value;
              if (currentSong && currentSong.id == activeId && audioCtx) {
                preset.forEach((val, i) => {
                  eqBands[i].gain.setTargetAtTime(val, audioCtx.currentTime, 0.1);
                });
              }
            }
          });
        }

        const sasSaveBtn = document.getElementById('sas-save-btn');
        if (sasSaveBtn) {
          sasSaveBtn.addEventListener('click', async () => {
            const songId = document.getElementById('sas-song-id').value;
            const vol = parseFloat(document.getElementById('sas-vol-slider').value);
            const eqs = Array.from(document.querySelectorAll('.sas-eq-band')).map(s => parseFloat(s.value));
            
            const result = await fetchData('?action=save_song_settings', {
              method: 'POST', headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ song_id: songId, volume: vol, eq: eqs })
            });

            if (result && result.status === 'success') {
              showToast('Song audio settings saved to database', 'success');
              
              if (globalSongCache[songId]) {
                 globalSongCache[songId].volume_multiplier = vol;
                 globalSongCache[songId].eq_bands = eqs;
              }
              if (currentSong && currentSong.id == songId) {
                 currentSong.volume_multiplier = vol;
                 currentSong.eq_bands = eqs;
                 applyAudioSettings();
              }
              
              bootstrap.Modal.getOrCreateInstance(document.getElementById('song-audio-settings-modal')).hide();
            }
          });
        }

        const sasResetBtn = document.getElementById('sas-reset-btn');
        if (sasResetBtn) {
          sasResetBtn.addEventListener('click', async () => {
            const songId = document.getElementById('sas-song-id').value;
            
            const result = await fetchData('?action=reset_song_settings', {
              method: 'POST', headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({ song_id: songId })
            });

            if (result && result.status === 'success') {
              showToast('Song audio settings reset to global default', 'success');
              
              if (globalSongCache[songId]) {
                 globalSongCache[songId].volume_multiplier = null;
                 globalSongCache[songId].eq_bands = null;
              }
              if (currentSong && currentSong.id == songId) {
                 currentSong.volume_multiplier = null;
                 currentSong.eq_bands = null;
                 applyAudioSettings();
              }

              bootstrap.Modal.getOrCreateInstance(document.getElementById('song-audio-settings-modal')).hide();
            }
          });
        }

        const handleLogout = async (e) => {
          if (e) e.preventDefault(); // Stop browser from adding '#' to URL and breaking the view
          await fetchData('?action=logout');
          currentUser = null;
          cachedExploreData = null;
          updateUIForAuthState();
          
          const authRequiredViews = ['user_profile', 'get_user_stats', 'get_offline_songs', 'get_favorites', 'get_listen_later', 'get_notes', 'get_community', 'get_history', 'get_following', 'get_recommendations', 'get_user_playlists', 'get_collab_playlists'];
          
          if (authRequiredViews.includes(currentView.type)) {
            loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' });
          } else {
            loadView(currentView);
          }
        };
        
        document.getElementById('profile-dropdown-logout-desktop').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-logout-mobile').addEventListener('click', handleLogout);
        document.getElementById('profile-dropdown-stats-desktop').addEventListener('click', (e) => { e.preventDefault(); loadView({type: 'get_user_stats'}); });
        document.getElementById('profile-dropdown-stats-mobile').addEventListener('click', (e) => { e.preventDefault(); loadView({type: 'get_user_stats'}); });
        
        const sleepTimerBubble = document.getElementById('sleep-timer-bubble');
        const sleepTimerCountdown = document.getElementById('sleep-timer-countdown');
        const sleepTimerCancelBtn = document.getElementById('sleep-timer-cancel-btn');
        const sleepTimerWakeBtn = document.getElementById('sleep-timer-wake-btn');

        let noSleep = new NoSleep();

        const releaseWakeLock = () => {
          if (sleepTimerKeepAwake) {
            noSleep.disable();
            sleepTimerKeepAwake = false;
            if (sleepTimerWakeBtn) {
              sleepTimerWakeBtn.classList.remove('active');
              sleepTimerWakeBtn.innerHTML = '<i class="bi bi-display"></i>';
            }
          }
        };

        const toggleWakeLock = async (e) => {
          if (e) e.stopPropagation();
          
          if (sleepTimerKeepAwake) {
            releaseWakeLock();
            showToast('Screen wake lock disabled.', 'info');
          } else {
            try {
              noSleep.enable();
              sleepTimerKeepAwake = true;
              if (sleepTimerWakeBtn) {
                sleepTimerWakeBtn.classList.add('active');
                sleepTimerWakeBtn.innerHTML = '<i class="bi bi-display-fill"></i>';
              }
              showToast('Screen will stay awake.', 'success');
            } catch (err) {
              showToast("Failed to acquire screen wake lock.", "error");
            }
          }
        };

        if (sleepTimerWakeBtn) {
          sleepTimerWakeBtn.addEventListener('click', toggleWakeLock);
        }

        const cancelSleepTimer = () => {
          if (sleepTimerInterval) clearInterval(sleepTimerInterval);
          sleepTimerInterval = null;
          sleepTimerBubble.classList.add('d-none');
          releaseWakeLock();
          showToast('Sleep timer canceled.', 'info');
        };

        sleepTimerCancelBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          cancelSleepTimer();
        });

        const handleSleepTimer = (e) => {
          if (e) e.preventDefault();
          if (sleepTimerInterval) {
            if (confirm(`Sleep timer is active. Cancel it?`)) {
              cancelSleepTimer();
            }
            return;
          }
          const mins = prompt("Stop playing after how many minutes?", "30");
          if (mins && !isNaN(mins) && parseInt(mins) > 0) {
            releaseWakeLock();

            const ms = parseInt(mins) * 60000;
            sleepTimerEndTime = Date.now() + ms;
            
            const updateTimer = () => {
              const now = Date.now();
              const remaining = sleepTimerEndTime - now;
              if (remaining <= 0) {
                clearInterval(sleepTimerInterval);
                sleepTimerInterval = null;
                sleepTimerBubble.classList.add('d-none');
                releaseWakeLock();
                if (isPlaying) togglePlayPause();
                showToast('Sleep timer reached. Playback paused.', 'info');
                return;
              }
              const totalSeconds = Math.floor(remaining / 1000);
              const m = Math.floor(totalSeconds / 60);
              const s = (totalSeconds % 60).toString().padStart(2, '0');
              sleepTimerCountdown.textContent = `${m}:${s}`;
            };
            
            updateTimer();
            sleepTimerInterval = setInterval(updateTimer, 1000);
            sleepTimerBubble.classList.remove('d-none');
            showToast(`Sleep timer set for ${mins} minutes.`, 'success');
          }
        };

        // Sleep Timer Bubble Dragging Logic
        let isDraggingBubble = false;
        let bubbleOffsetX, bubbleOffsetY;

        const startDragBubble = (e) => {
          if (e.target.closest('.action-btn')) return;
          isDraggingBubble = true;
          const clientX = e.touches ? e.touches[0].clientX : e.clientX;
          const clientY = e.touches ? e.touches[0].clientY : e.clientY;
          const rect = sleepTimerBubble.getBoundingClientRect();
          bubbleOffsetX = clientX - rect.left;
          bubbleOffsetY = clientY - rect.top;
          sleepTimerBubble.style.cursor = 'grabbing';
        };

        const moveDragBubble = (e) => {
          if (!isDraggingBubble) return;
          e.preventDefault(); // Prevents screen scrolling while dragging
          const clientX = e.touches ? e.touches[0].clientX : e.clientX;
          const clientY = e.touches ? e.touches[0].clientY : e.clientY;
          
          let newLeft = clientX - bubbleOffsetX;
          let newTop = clientY - bubbleOffsetY;
          
          const maxX = window.innerWidth - sleepTimerBubble.offsetWidth;
          const maxY = window.innerHeight - sleepTimerBubble.offsetHeight;
          newLeft = Math.max(0, Math.min(newLeft, maxX));
          newTop = Math.max(0, Math.min(newTop, maxY));
          
          sleepTimerBubble.style.left = `${newLeft}px`;
          sleepTimerBubble.style.top = `${newTop}px`;
          sleepTimerBubble.style.right = 'auto'; // Disable initial right constraint
        };

        const stopDragBubble = () => {
          if (isDraggingBubble) {
            isDraggingBubble = false;
            sleepTimerBubble.style.cursor = 'grab';
          }
        };

        sleepTimerBubble.addEventListener('mousedown', startDragBubble);
        sleepTimerBubble.addEventListener('touchstart', startDragBubble, {passive: false});
        document.addEventListener('mousemove', moveDragBubble, {passive: false});
        document.addEventListener('touchmove', moveDragBubble, {passive: false});
        document.addEventListener('mouseup', stopDragBubble);
        document.addEventListener('touchend', stopDragBubble);

        document.getElementById('sleep-timer-btn-desktop').addEventListener('click', handleSleepTimer);
        document.getElementById('sleep-timer-btn-mobile').addEventListener('click', handleSleepTimer);

        const profileBgForm = document.getElementById('profile-bg-form');
        const bioForm = document.getElementById('bio-form');

        if (profileBgForm) {
          profileBgForm.addEventListener('submit', async e => {
            e.preventDefault();
            const bgInput = document.getElementById('profile-bg-input');
            if (bgInput.files.length === 0) return showToast('Select an image', 'error');
            const submitBtn = document.getElementById('profile-bg-submit-btn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';
            const formData = new FormData();
            formData.append('profile_background', bgInput.files[0]);
            
            try {
              const res = await fetch('?action=upload_profile_background', { method: 'POST', body: formData });
              const result = await res.json();
              showToast(result.message, result.status);
              if (result.status === 'success') {
                 bgInput.value = '';
                 await checkSession();
                 if (currentView.type === 'user_profile') loadView(currentView);
              }
            } catch (err) {
              showToast('Upload failed', 'error');
            }
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Background';
          });
        }

        if (bioForm) {
          bioForm.addEventListener('submit', async e => {
            e.preventDefault();
            const bio = document.getElementById('settings-bio').value;
            const res = await fetchData('?action=save_bio', { method: 'POST', body: JSON.stringify({ bio }) });
            if (res && res.status === 'success') {
              showToast(res.message, 'success');
              await checkSession();
              if (currentView.type === 'user_profile') loadView(currentView);
            }
          });
        }

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
        
        let profileCropper = null;
        const profilePicInput = document.getElementById('profile-picture-input');
        const profilePicPreview = document.getElementById('profile-picture-preview');
        
        if (profilePicInput) {
          profilePicInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
              const reader = new FileReader();
              reader.onload = e => {
                profilePicPreview.src = e.target.result;
                if (profileCropper) profileCropper.destroy();
                profileCropper = new Cropper(profilePicPreview, {
                  aspectRatio: 1,
                  viewMode: 1,
                  autoCropArea: 1,
                  dragMode: 'move',
                  background: false
                });
              };
              reader.readAsDataURL(this.files[0]);
            }
          });
        }

        profilePicForm.addEventListener('submit', async e => {
          e.preventDefault();
          if (profilePicInput.files.length === 0 && !profileCropper) {
            showToast('Please select an image file.', 'error');
            return;
          }

          const submitBtn = document.getElementById('profile-picture-submit-btn');
          const progContainer = document.getElementById('profile-pic-progress-container');
          const progBar = document.getElementById('profile-pic-progress');
          
          submitBtn.disabled = true;
          progContainer.classList.remove('d-none');
          progBar.style.width = '0%';
          progBar.textContent = '0%';

          const doUpload = (blob) => {
            const formData = new FormData();
            formData.append('profile_picture', blob, 'profile.jpg');

            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = (evt) => {
              if (evt.lengthComputable) {
                const pct = Math.round((evt.loaded / evt.total) * 100);
                progBar.style.width = pct + '%';
                progBar.textContent = pct + '%';
              }
            };
            xhr.open('POST', '?action=upload_profile_picture', true);
            xhr.onload = async () => {
              submitBtn.disabled = false;
              progContainer.classList.add('d-none');
              if (xhr.status === 200) {
                try {
                  const result = JSON.parse(xhr.responseText);
                  if (result.status === 'success') {
                    showToast(result.message, 'success');
                    if (profileCropper) { profileCropper.destroy(); profileCropper = null; }
                    await checkSession();
                    bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
                  } else {
                    showToast(result.message || 'Upload failed', 'error');
                  }
                } catch(err) {
                  showToast('Invalid server response.', 'error');
                }
              } else {
                showToast('Upload error', 'error');
              }
            };
            xhr.onerror = () => {
              submitBtn.disabled = false;
              progContainer.classList.add('d-none');
              showToast('Network error during upload', 'error');
            };
            xhr.send(formData);
          };

          if (profileCropper) {
            profileCropper.getCroppedCanvas({ width: 500, height: 500 }).toBlob(blob => {
              doUpload(blob);
            }, 'image/jpeg', 0.85);
          } else {
             doUpload(profilePicInput.files[0]);
          }
        });

        document.getElementById('toggle-normalization').addEventListener('change', (e) => {
          enableNormalization = e.target.checked;
          toggleAudioEnhancements();
        });

        document.getElementById('toggle-eq').addEventListener('change', (e) => {
          isEQEnabled = e.target.checked;
          document.getElementById('eq-sliders').classList.toggle('d-none', !isEQEnabled);
          toggleAudioEnhancements();
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
              
              const authRequiredViews = ['user_profile', 'get_user_stats', 'get_offline_songs', 'get_favorites', 'get_listen_later', 'get_notes', 'get_community', 'get_history', 'get_following', 'get_recommendations', 'get_user_playlists', 'get_collab_playlists'];
              if (authRequiredViews.includes(currentView.type)) {
                loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' });
              } else {
                loadView(currentView);
              }
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
              
              const authRequiredViews = ['user_profile', 'get_user_stats', 'get_offline_songs', 'get_favorites', 'get_listen_later', 'get_notes', 'get_community', 'get_history', 'get_following', 'get_recommendations', 'get_user_playlists', 'get_collab_playlists'];
              if (authRequiredViews.includes(currentView.type)) {
                loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' });
              } else {
                loadView(currentView);
              }
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
            const progressId = `upload-prog-${Date.now()}-${i}`;
            
            uploadProgressArea.insertAdjacentHTML('beforeend', `
              <div class="mb-3 p-3 rounded" style="background-color: var(--ytm-surface-2); border: 1px solid #404040;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <small class="text-white text-truncate fw-bold" style="max-width: 80%;">${escapeHTML(file.name)}</small>
                  <small class="text-secondary fw-bold" id="${progressId}-text">0%</small>
                </div>
                <div class="progress" style="height: 8px; background-color: #000;">
                  <div id="${progressId}" class="progress-bar bg-danger" role="progressbar" style="width: 0%; transition: width 0.1s linear;"></div>
                </div>
              </div>`);

            const formData = new FormData();
            formData.append('song', file);
            formData.append('genre', songGenreInput.value);
            formData.append('is_private', document.getElementById('song-is-private').checked ? 1 : 0);

            const xhr = new XMLHttpRequest();
            
            xhr.upload.onprogress = (e) => {
              if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                const progressBar = document.getElementById(progressId);
                const progressText = document.getElementById(`${progressId}-text`);
                if (progressBar) progressBar.style.width = `${percentComplete}%`;
                if (progressText) progressText.textContent = `${percentComplete}%`;
              }
            };
            
            xhr.open('POST', '?action=upload_song', true);
            
            await new Promise(resolve => {
              xhr.onload = () => {
                const progressBar = document.getElementById(progressId);
                const progressText = document.getElementById(`${progressId}-text`);
                if (xhr.status === 200) {
                  if (progressBar) progressBar.classList.replace('bg-danger', 'bg-success');
                  if (progressText) progressText.classList.replace('text-secondary', 'text-success');
                } else {
                  if (progressBar) progressBar.classList.replace('bg-danger', 'bg-warning');
                  if (progressText) {
                    progressText.classList.replace('text-secondary', 'text-warning');
                    progressText.textContent = 'Failed';
                  }
                  try {
                    showToast(`Upload failed for ${file.name}: ${JSON.parse(xhr.responseText).message}`, 'error');
                  } catch (e) {
                    showToast(`Upload failed for ${file.name}: Server error.`, 'error');
                  }
                }
                resolve();
              };
              xhr.onerror = () => {
                const progressBar = document.getElementById(progressId);
                const progressText = document.getElementById(`${progressId}-text`);
                if (progressBar) progressBar.classList.replace('bg-danger', 'bg-warning');
                if (progressText) {
                  progressText.classList.replace('text-secondary', 'text-warning');
                  progressText.textContent = 'Network Error';
                }
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
            const onSuccess = () => {
              copyShareUrlBtn.textContent = 'Copied!';
              copyShareUrlBtn.disabled = true;
              setTimeout(() => {
                copyShareUrlBtn.textContent = 'Copy';
                copyShareUrlBtn.disabled = false;
              }, 2000);
            };
            const onError = () => showToast('Failed to copy link.', 'error');

            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(shareUrlInput.value).then(onSuccess).catch(onError);
            } else {
              const textArea = document.createElement("textarea");
              textArea.value = shareUrlInput.value;
              textArea.style.position = "fixed";
              textArea.style.top = "0";
              textArea.style.left = "0";
              textArea.style.opacity = "0";
              document.body.appendChild(textArea);
              textArea.focus();
              textArea.select();
              try {
                const successful = document.execCommand('copy');
                if (successful) onSuccess();
                else onError();
              } catch (err) {
                onError();
              }
              document.body.removeChild(textArea);
            }
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
          return str.replace(/[&<>'"]/g, m => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[m] || m))
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
                <div class="text-secondary small d-flex align-items-center" style="color: #ffffff !important;">
                  <input class="form-check-input pd-song-checkbox me-2" type="checkbox" data-id="${song.id}" ${song.pdSelected !== false ? 'checked' : ''}>
                  <span class="d-none d-md-inline">${globalIdx + 1}</span>
                </div>
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
          document.querySelectorAll('.pd-song-checkbox').forEach(chk => {
            chk.addEventListener('change', (e) => {
              const s = pdAllSongs.find(song => song.id === parseInt(e.target.dataset.id));
              if (s) s.pdSelected = e.target.checked;
              updatePdSelectAllState();
            });
          });
        };

        const updatePdSelectAllState = () => {
          const selectAllChk = document.getElementById('pd-select-all');
          if (selectAllChk) {
            const allSelected = pdAllSongs.every(s => s.pdSelected !== false);
            selectAllChk.checked = allSelected;
          }
        };

        const selectAllChk = document.getElementById('pd-select-all');
        if (selectAllChk) {
          selectAllChk.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            pdAllSongs.forEach(s => s.pdSelected = isChecked);
            pdRenderSongRows();
            const pdModalBody = document.querySelector('#playlist-downloader-modal .modal-body');
            if (pdModalBody) pdModalBody.dispatchEvent(new Event('scroll'));
          });
        }

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
          
          const songsToDownload = pdAllSongs.filter(s => s.pdSelected !== false);
          pdLog(`🚀 Starting sequential download of ${songsToDownload.length} selected songs. Click "Stop Download" to cancel.`);
          
          for (let i = 0; i < songsToDownload.length; i++) {
            if (pdStopRequested) {
              pdLog(`⏹️ Download stopped by user after ${i}/${songsToDownload.length} songs.`);
              break;
            }
            await pdDownloadSong(songsToDownload[i], true, i, songsToDownload.length);
          }
          
          pdStartAutoBtn.removeEventListener('click', pdStopAutoDownload);
          pdStartAutoBtn.addEventListener('click', pdStartAutoDownload);
          pdStartAutoBtn.classList.replace('btn-warning', 'btn-danger');
          pdStartAutoBtn.innerHTML = '<i class="bi bi-play-fill" style="color: #ffffff !important;"></i> Download Selected Songs (Sequential)';
          pdStartAutoBtn.disabled = false;
          
          if (!pdStopRequested) pdLog(`✅ All ${songsToDownload.length} selected songs have been sent for download!`);
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
              pdAllSongs = data.map(s => ({...s, pdSelected: true}));
              pdCurrentPage = 1;
              
              let totalSizeBytes = pdAllSongs.reduce((sum, song) => sum + ((song.duration || 0) * (song.bitrate || 128000) / 8), 0);
              const formatBytes = (bytes) => {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
              };
              
              pdResultsCard.classList.remove('d-none');
              pdPlaylistTitle.innerHTML = `<i class="bi bi-music-note-beamed" style="color: #ffffff !important;"></i> Playlist loaded <span class="badge bg-secondary ms-2">${pdAllSongs.length} songs</span> <span class="badge bg-info ms-2">~${formatBytes(totalSizeBytes)}</span>`;
              pdLog(`✅ Successfully loaded ${pdAllSongs.length} songs (~${formatBytes(totalSizeBytes)}).`);
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
                    <div class="text-secondary small d-flex align-items-center" style="color: #ffffff !important;">
                      <input class="form-check-input pd-song-checkbox me-2" type="checkbox" data-id="${song.id}" ${song.pdSelected !== false ? 'checked' : ''}>
                      <span class="d-none d-md-inline">${globalIdx + 1}</span>
                    </div>
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
              document.querySelectorAll('.pd-song-checkbox').forEach(chk => {
                chk.addEventListener('change', (e) => {
                  const s = pdAllSongs.find(song => song.id === parseInt(e.target.dataset.id));
                  if (s) s.pdSelected = e.target.checked;
                  updatePdSelectAllState();
                });
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

        const updateNotifBadge = async () => {
          if (!currentUser) return;
          const data = await fetchData('?action=get_unread_notif_count');
          const badges = document.querySelectorAll('.notif-badge');
          if (data && data.count > 0) {
            badges.forEach(b => { b.textContent = data.count; b.classList.remove('d-none'); });
          } else {
            badges.forEach(b => b.classList.add('d-none'));
          }
          
          // Check Unread Messages (Inbox)
          const inboxData = await fetchData('?action=get_inbox', {}, true);
          let totalUnread = 0;
          if (inboxData && inboxData.length > 0) {
             inboxData.forEach(m => totalUnread += m.unread_count);
          }
          const inboxBadges = document.querySelectorAll('.inbox-badge');
          if (totalUnread > 0) {
            inboxBadges.forEach(b => { b.textContent = totalUnread; b.classList.remove('d-none'); });
          } else {
            inboxBadges.forEach(b => b.classList.add('d-none'));
          }
        };

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
            const scanAllBtn = document.getElementById('nav-scan-all');
            if (scanAllBtn) {
              if (currentUser.email && currentUser.email.toLowerCase() === 'musiclibrary@mail.com') {
                scanAllBtn.style.setProperty('display', 'flex', 'important');
              } else {
                scanAllBtn.style.setProperty('display', 'none', 'important');
              }
            }
          } else {
            document.body.classList.remove('user-verified');
          }
        }

        async function checkSession() {
          const data = await fetchData('?action=get_session');
          if (data && data.status === 'loggedin') {
            currentUser = data.user;
            if (currentUser.settings) {
              try {
                // Highly robust JSON extraction for settings
                const s = typeof currentUser.settings === 'string' ? JSON.parse(currentUser.settings) : currentUser.settings;
                
                enableNormalization = s.enableNormalization !== undefined ? s.enableNormalization : true;
                isEQEnabled = s.isEQEnabled !== undefined ? s.isEQEnabled : false;
                isSpatialEnabled = s.isSpatialEnabled !== undefined ? s.isSpatialEnabled : false;
                globalVolumeMultiplier = s.globalVolumeMultiplier !== undefined ? parseFloat(s.globalVolumeMultiplier) : 1.0;
                globalEQBands = Array.isArray(s.globalEQBands) ? s.globalEQBands.map(b => parseFloat(b)) : [0, 0, 0, 0, 0];
                crossfadeDuration = s.crossfadeDuration !== undefined ? parseFloat(s.crossfadeDuration) : 3.0;
                
                // Propagate to UI DOM
                const normEl = document.getElementById('toggle-normalization');
                if (normEl) normEl.checked = enableNormalization;
                
                const crossEl = document.getElementById('crossfade-slider');
                if (crossEl) {
                   crossEl.value = crossfadeDuration;
                   document.getElementById('crossfade-val').textContent = crossfadeDuration + 's';
                }
                
                const eqToggleEl = document.getElementById('toggle-eq');
                if (eqToggleEl) eqToggleEl.checked = isEQEnabled;
                
                const spatialToggleEl = document.getElementById('toggle-spatial');
                if (spatialToggleEl) spatialToggleEl.checked = isSpatialEnabled;
                
                const eqSlidersContainer = document.getElementById('eq-sliders');
                if (eqSlidersContainer) eqSlidersContainer.classList.toggle('d-none', !isEQEnabled);
                
                const volSliderEl = document.getElementById('global-vol-slider');
                if (volSliderEl) {
                   volSliderEl.value = globalVolumeMultiplier;
                   document.getElementById('global-vol-val').textContent = globalVolumeMultiplier + 'x';
                }
                
                const eqSliders = document.querySelectorAll('#eq-sliders .eq-band');
                globalEQBands.forEach((val, i) => { if (eqSliders[i]) eqSliders[i].value = val; });
                
                // Immediately apply audio processing if active
                if (audioCtx) {
                   applyAudioSettings();
                   toggleAudioEnhancements();
                }
              } catch(e) { 
                console.error("Error parsing settings JSON during checkSession:", e); 
              }
            }
            
            const newNameInput = document.getElementById('new-name');
            if (newNameInput) newNameInput.value = currentUser.artist;
            const bioInput = document.getElementById('settings-bio');
            if (bioInput) bioInput.value = currentUser.bio || '';
            if (uploadLimitText) uploadLimitText.textContent = data.upload_limit;
            if (uploadRemainingText) {
              uploadRemainingText.textContent = `Today's remaining uploads: ${currentUser.uploads_remaining}`;
            }
            const offIds = await fetchData('?action=get_offline_ids');
            if(offIds) offlineSongsSet = new Set(offIds.map(id => parseInt(id)));
            const llIds = await fetchData('?action=get_listen_later_ids');
            if(llIds) listenLaterSet = new Set(llIds.map(id => parseInt(id)));
            updateNotifBadge();
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
          
          // Fix: Prevent aggressive audio preloading from causing infinite network buffering spinners on an empty src
          audio.preload = 'none';
          
          audio.addEventListener('waiting', () => {
            if (isPlaying) updatePlayPauseIcons(true); 
          });

          audio.addEventListener('playing', () => {
            updatePlayPauseIcons(false);
            if (audioCtx) {
              if (audioCtx.state === 'suspended') audioCtx.resume();
              applyAudioSettings();
            }
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

          // Intercept Invite Link before normal rendering
          const urlParams = new URLSearchParams(window.location.search);
          const inviteToken = urlParams.get('collab_invite');
          if (inviteToken) {
            if (!currentUser) {
              showToast("Please log in to accept the collaboration invite.", "warning");
              const loginModal = new bootstrap.Modal(document.getElementById('login-modal'));
              loginModal.show();
            } else {
              const inviteData = await fetchData(`?action=get_invite_info&token=${encodeURIComponent(inviteToken)}`);
              if (inviteData && inviteData.status === 'success' && inviteData.details) {
                const playlistInfo = inviteData.details;
                if (playlistInfo.user_id == currentUser.id) {
                  showToast("Are you try to befriend yourself because you don't have a friend?", "warning");
                  window.history.replaceState({}, document.title, window.location.pathname);
                } else {
                  const inviteModalEl = document.getElementById('collab-invite-modal');
                  if (inviteModalEl) {
                    document.getElementById('invite-playlist-name').textContent = playlistInfo.name;
                    document.getElementById('invite-playlist-creator').textContent = playlistInfo.creator;
                    const inviteModal = new bootstrap.Modal(inviteModalEl);
                    
                    document.getElementById('invite-accept-btn').onclick = async () => {
                      const res = await fetchData('?action=manage_collaborators', {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ public_id: playlistInfo.public_id, collab_action: 'join', token: inviteToken })
                      });
                      if (res) {
                        showToast(res.message, res.status);
                        inviteModal.hide();
                        window.history.replaceState({}, document.title, window.location.pathname);
                        loadView({ type: 'playlist_songs', param: playlistInfo.public_id, sort: 'manual_order', filter_user_id: '' });
                      }
                    };
                    
                    document.getElementById('invite-reject-btn').onclick = () => {
                      window.history.replaceState({}, document.title, window.location.pathname);
                    };
                    
                    inviteModal.show();
                  }
                }
              } else {
                showToast("Invalid or expired invite link.", "error");
                window.history.replaceState({}, document.title, window.location.pathname);
              }
            }
          }

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
            loadView({ type: 'get_songs', param: '', sort: 'id_desc', filter_user_id: '', artist_name: '' }, false);
          }
        });

        // Forbid inspecting the page, bypassable by super admin
        document.addEventListener('contextmenu', e => {
          if (localStorage.getItem('dev_mode_token') === 'admin') return;
          if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
          }
        });

        document.addEventListener('keydown', async e => {
          // Anti-inspect
          if (e.key === 'F12' || 
          (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'C' || e.key === 'J')) || 
          (e.ctrlKey && e.key === 'U')) {
            if (localStorage.getItem('dev_mode_token') !== 'admin') {
              e.preventDefault(); // Block the browser's default inspect window opening
              const pwd = prompt("Developer tools locked. Enter admin password:");
              if (pwd === 'admin') {
                localStorage.setItem('dev_mode_token', 'admin');
                alert("Access granted. You can now press the shortcut again to open DevTools.");
              } else if (pwd !== null) {
                alert("Password incorrect. You want to try exploit the site?");
              }
              return false;
            }
          }
          // Stop if user is typing in an input field
          if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            if (e.code === 'Escape') {
              e.target.blur();
            }
            return;
          }

          // Global non-song shortcuts
          if (e.code === 'Escape') {
            e.preventDefault();
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(m => {
              const instance = bootstrap.Modal.getInstance(m);
              if (instance) instance.hide();
            });
            const ctxMenu = document.getElementById('context-menu');
            if (ctxMenu) ctxMenu.style.display = 'none';
            return;
          }

          if (e.shiftKey && e.code === 'KeyS') {
            e.preventDefault();
            const searchInput = window.innerWidth >= 768 ? document.getElementById('search-input-desktop') : document.getElementById('search-input-mobile');
            if (searchInput) searchInput.focus();
            return;
          }

          if (e.shiftKey && e.code === 'KeyC') {
            e.preventDefault();
            const clearBtn = document.getElementById('clear-history-btn');
            if (clearBtn && !clearBtn.closest('#history-controls').classList.contains('d-none')) {
              clearBtn.click();
            }
            return;
          }

          // Global Player Shortcuts (Only if a song is loaded)
          if (!currentSong) return;

          switch (e.code) {
            case 'Space':
              e.preventDefault(); 
              togglePlayPause();
              break;
            case 'ArrowRight':
              e.preventDefault();
              if (e.shiftKey) {
                if (isFinite(audio.duration)) audio.currentTime = Math.min(audio.duration, audio.currentTime + 10);
              } else {
                playNext();
              }
              break;
            case 'ArrowLeft':
              e.preventDefault();
              if (e.shiftKey) {
                if (isFinite(audio.duration)) audio.currentTime = Math.max(0, audio.currentTime - 10);
              } else {
                playPrev();
              }
              break;
            case 'ArrowUp':
              e.preventDefault();
              audio.volume = Math.min(1, audio.volume + 0.05);
              if(playerElements.volumeSlider) {
                playerElements.volumeSlider.value = audio.volume;
                playerElements.volumeSlider.dispatchEvent(new Event('input'));
              }
              break;
            case 'ArrowDown':
              e.preventDefault();
              audio.volume = Math.max(0, audio.volume - 0.05);
              if(playerElements.volumeSlider) {
                playerElements.volumeSlider.value = audio.volume;
                playerElements.volumeSlider.dispatchEvent(new Event('input'));
              }
              break;
            case 'KeyM':
              e.preventDefault();
              if (playerElements.volumeBtn) playerElements.volumeBtn.click();
              break;
            case 'KeyS':
              if (!e.shiftKey) {
                e.preventDefault();
                toggleShuffle();
              }
              break;
            case 'KeyR':
              e.preventDefault();
              repeatMode = (repeatMode === 'none') ? 'all' : (repeatMode === 'all') ? 'one' : 'none';
              localStorage.setItem('repeatMode', repeatMode);
              updateRepeatIcons();
              break;
            case 'KeyF':
              e.preventDefault();
              const fsBtn = document.getElementById('fullscreen-btn');
              if (fsBtn) fsBtn.click();
              break;
            case 'KeyP':
              e.preventDefault();
              const pipBtn = document.getElementById('pip-btn-desktop');
              if (pipBtn) pipBtn.click();
              break;
            case 'KeyL':
              e.preventDefault();
              const lyricsSongData = await fetchData('?action=get_song_data&id=' + currentSong.id);
              if (lyricsSongData) {
                const lyricsTitleEl = document.getElementById('lyrics-modal-title');
                const lyricsBodyEl = document.getElementById('lyrics-modal-body');
                if (lyricsTitleEl) lyricsTitleEl.textContent = lyricsSongData.title;
                if (lyricsBodyEl) {
                  const lrcData = parseLRC(lyricsSongData.lyrics);
                  if (lrcData.length > 0) {
                    currentLrcData = lrcData;
                    currentLrcSongId = parseInt(currentSong.id);
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
            case 'KeyC':
              if (!e.shiftKey) {
                e.preventDefault();
                if (currentUser) {
                  window.openCommentsModal(currentSong.id);
                } else {
                  showToast('Please login to view comments', 'error');
                }
              }
              break;
            case 'KeyH':
              e.preventDefault();
              if (currentUser) toggleFavorite(currentSong.id);
              break;
            case 'KeyB':
              e.preventDefault();
              if (currentUser) {
                const res = await fetchData('?action=toggle_listen_later', { method: 'POST', body: JSON.stringify({id: parseInt(currentSong.id)}) });
                if(res) {
                  if(res.status === 'added') listenLaterSet.add(parseInt(currentSong.id));
                  else listenLaterSet.delete(parseInt(currentSong.id));
                  showToast(res.status === 'added' ? 'Added to Listen Later' : 'Removed from Listen Later', 'success'); 
                }
              }
              break;
            case 'KeyO':
              e.preventDefault();
              if (currentUser) {
                 const offRes = await fetchData('?action=toggle_offline', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ id: parseInt(currentSong.id) })
                });
                if (offRes) {
                  if (offRes.status === 'added') {
                    showToast('Caching offline (check menu for progress)...', 'info');
                    offlineSongsSet.add(parseInt(currentSong.id));
                  } else {
                    showToast('Removed from offline list.', 'success');
                    offlineSongsSet.delete(parseInt(currentSong.id));
                  }
                }
              }
              break;
            case 'KeyD':
              e.preventDefault();
              window.location.href = '?action=download_song&id=' + currentSong.id;
              break;
            case 'KeyI':
              e.preventDefault();
              const metaSongData = await fetchData('?action=get_song_data&id=' + parseInt(currentSong.id));
              if (metaSongData) {
                const metaBody = document.getElementById('metadata-modal-body');
                if (metaBody) {
                  metaBody.innerHTML = `
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Title:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.title || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Artist:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.artist || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Album:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.album || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Genre:</strong> <span class="text-truncate" style="max-width: 70%;">${metaSongData.genre || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Year:</strong> <span>${metaSongData.year || 'N/A'}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Duration:</strong> <span>${formatTime(metaSongData.duration)}</span></li>
                      <li class="list-group-item bg-transparent border-secondary text-white d-flex justify-content-between"><strong>Bitrate:</strong> <span>${metaSongData.bitrate ? Math.round(metaSongData.bitrate / 1000) + ' kbps' : 'N/A'}</span></li>
                    </ul>`;
                  bootstrap.Modal.getOrCreateInstance(document.getElementById('metadata-modal')).show();
                }
              }
              break;
            case 'KeyE':
              e.preventDefault();
              if (currentUser) {
                const sasModalEl = document.getElementById('song-audio-settings-modal');
                if (sasModalEl) {
                  document.getElementById('sas-song-id').value = currentSong.id;
                  document.getElementById('sas-song-title').textContent = currentSong.title;
                  const targetVol = currentSong.volume_multiplier !== null ? parseFloat(currentSong.volume_multiplier) : globalVolumeMultiplier;
                  const targetEQ = currentSong.eq_bands || [0,0,0,0,0];
                  document.getElementById('sas-vol-slider').value = targetVol;
                  document.getElementById('sas-vol-val').textContent = targetVol + 'x';
                  const sasEqBands = document.querySelectorAll('.sas-eq-band');
                  sasEqBands.forEach((band, i) => band.value = targetEQ[i] !== undefined ? targetEQ[i] : 0);
                  document.getElementById('sas-eq-preset-select').value = 'Custom';
                  bootstrap.Modal.getOrCreateInstance(sasModalEl).show();
                }
              }
              break;
            case 'KeyU':
              e.preventDefault();
              showShareModal('song', currentSong.id, currentSong.title);
              break;
            case 'Digit0':
            case 'Numpad0':
              e.preventDefault();
              audio.currentTime = 0;
              break;
            case 'Digit1':
            case 'Numpad1':
            case 'Digit2':
            case 'Numpad2':
            case 'Digit3':
            case 'Numpad3':
            case 'Digit4':
            case 'Numpad4':
            case 'Digit5':
            case 'Numpad5':
            case 'Digit6':
            case 'Numpad6':
            case 'Digit7':
            case 'Numpad7':
            case 'Digit8':
            case 'Numpad8':
            case 'Digit9':
            case 'Numpad9':
              e.preventDefault();
              const num = parseInt(e.key);
              if (isFinite(audio.duration)) {
                audio.currentTime = audio.duration * (num / 10);
              }
              break;
          }
        });

        // --- ARTIST HOVER TOOLTIP LOGIC ---
        const artistTooltip = document.createElement('div');
        artistTooltip.id = 'artist-hover-tooltip';
        document.body.appendChild(artistTooltip);

        let artistHoverTimeout;
        let artistHideTimeout;
        const artistHoverCache = {};

        const showArtistTooltip = async (target, artistRaw, userId) => {
          if (window.innerWidth < 992) return;
          
          const artistName = decodeURIComponent(artistRaw).split(/\s*(?:;|\||\s\/\s|\s&\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*|,\s+(?!(?:the|a|an|jr|sr)\b)/i)[0].trim();
          const cacheKey = (userId ? userId + '_' : '') + artistName.toLowerCase();
          
          const rect = target.getBoundingClientRect();
          artistTooltip.style.display = 'block';
          
          let top = rect.bottom + window.scrollY + 10;
          let left = rect.left + window.scrollX;
          
          if (left + 320 > window.innerWidth) left = window.innerWidth - 340;
          if (top + 200 > window.scrollY + window.innerHeight) top = rect.top + window.scrollY - 210;
          
          artistTooltip.style.top = top + 'px';
          artistTooltip.style.left = left + 'px';

          let data = artistHoverCache[cacheKey];

          if (!data) {
             artistTooltip.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-secondary"></div></div>';
             artistTooltip.style.opacity = '1';
             const res = await fetchData(`?action=get_view_data&type=artist&name=${encodeURIComponent(artistName)}&filter_user_id=${userId || ''}`);
             if (res && res.details) {
                data = res.details;
                artistHoverCache[cacheKey] = data;
             } else {
                artistTooltip.innerHTML = '<div class="text-secondary small text-center p-3">Artist details not found</div>';
                return;
             }
          }

          let followBtn = '';
          if (data.is_user && currentUser && currentUser.id != data.user_id) {
             const btnClass = data.is_following ? 'btn-outline-light' : 'btn-danger';
             const btnText = data.is_following ? 'Unfollow' : 'Follow';
             followBtn = `<button class="btn btn-sm ${btnClass} w-100 mt-3 tooltip-follow-btn fw-bold" data-user-id="${data.user_id}">${btnText}</button>`;
          }

          artistTooltip.innerHTML = `
            <div class="d-flex align-items-center gap-3" style="cursor: pointer;">
              <img src="${data.image_url}" style="width: 70px; height: 70px; object-fit: cover; border-radius: 50%; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
              <div style="min-width: 0;">
                 <h6 class="text-white text-truncate mb-1 fw-bold artist-tt-name" style="font-size: 1.1rem;">${escapeHTML(data.name)}</h6>
                 <div class="text-secondary" style="font-size: 0.85rem;">${formatSongCount(data.song_count || 0)} tracks • ${formatTime(data.total_duration || 0)}</div>
                 ${data.followers_count !== undefined ? `<div class="text-info mt-1" style="font-size: 0.8rem;"><i class="bi bi-people-fill"></i> ${formatSongCount(data.followers_count)} followers</div>` : `<div class="text-secondary mt-1" style="font-size: 0.8rem;"><i class="bi bi-eye"></i> ${formatSongCount(data.play_count || 0)} plays</div>`}
              </div>
            </div>
            ${followBtn}
          `;
          artistTooltip.style.opacity = '1';
        };

        document.addEventListener('mouseover', e => {
          const artistEl = e.target.closest('.song-artist, .song-artist-name, .item-subtitle[data-artist], .mention-link, .user-profile-link');
          if (artistEl) {
            clearTimeout(artistHideTimeout);
            if (artistTooltip.contains(e.relatedTarget)) return; 
            
            let artistRaw = artistEl.dataset.artist;
            let userId = artistEl.dataset.userid || '';
            if (!artistRaw && artistEl.classList.contains('song-artist-name')) artistRaw = artistEl.textContent.trim();
            
            if (artistRaw && window.innerWidth >= 992) {
              artistHoverTimeout = setTimeout(() => {
                showArtistTooltip(artistEl, artistRaw, userId);
              }, 500);
            }
          } else if (e.target.closest('#artist-hover-tooltip')) {
            clearTimeout(artistHideTimeout);
          }
        });

        document.addEventListener('mouseout', e => {
          const artistEl = e.target.closest('.song-artist, .song-artist-name, .item-subtitle[data-artist], .mention-link, .user-profile-link');
          if (artistEl || e.target.closest('#artist-hover-tooltip')) {
            clearTimeout(artistHoverTimeout);
            artistHideTimeout = setTimeout(() => {
              artistTooltip.style.opacity = '0';
              setTimeout(() => { if(artistTooltip.style.opacity === '0') artistTooltip.style.display = 'none'; }, 200);
            }, 300);
          }
        });

        artistTooltip.addEventListener('click', async e => {
          const followBtn = e.target.closest('.tooltip-follow-btn');
          if (followBtn) {
            if (!currentUser) return showToast('Please log in', 'error');
            const userId = followBtn.dataset.userId;
            const res = await fetchData('?action=toggle_follow', {
              method: 'POST', body: JSON.stringify({ following_id: userId })
            });
            if (res && (res.status === 'followed' || res.status === 'unfollowed')) {
              const isFollowing = res.status === 'followed';
              followBtn.textContent = isFollowing ? 'Unfollow' : 'Follow';
              followBtn.className = `btn btn-sm w-100 mt-3 tooltip-follow-btn fw-bold ${isFollowing ? 'btn-outline-light' : 'btn-danger'}`;
                
              for (let key in artistHoverCache) {
                if (artistHoverCache[key].user_id == userId) {
                  artistHoverCache[key].is_following = isFollowing;
                  artistHoverCache[key].followers_count += isFollowing ? 1 : -1;
                  const followersText = artistTooltip.querySelector('.text-info');
                  if (followersText) {
                    followersText.innerHTML = `<i class="bi bi-people-fill"></i> ${formatSongCount(artistHoverCache[key].followers_count)} followers`;
                  }
                }
              }
              if (currentView.type === 'artist_songs' || currentView.type === 'get_following' || currentView.type === 'get_recommendations') {
                loadView(currentView);
              }
            }
          } else {
            const nameEl = artistTooltip.querySelector('.artist-tt-name');
            if (nameEl) {
              artistTooltip.style.opacity = '0';
              artistTooltip.style.display = 'none';
              loadView({ type: 'artist_songs', param: nameEl.textContent, sort: 'album_asc', filter_user_id: '', artist_name: '' });
            }
          }
        });

        // Calendar Modal Logic
        const calendarModalEl = document.getElementById('calendar-modal');
        let clockInterval;
        let calCurrentMonth, calCurrentYear;
        let calSelectedDate = null;

        const updateClock = () => {
          const now = new Date();
          document.getElementById('calendar-time-display').textContent = now.toLocaleTimeString();
          document.getElementById('calendar-date-display').textContent = now.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        };

        const renderCalendar = (month, year) => {
          const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
          document.getElementById('cal-month-year').textContent = `${monthNames[month]} ${year}`;
          
          const daysGrid = document.getElementById('cal-days-grid');
          daysGrid.innerHTML = '';
          
          const firstDay = new Date(year, month, 1).getDay();
          const daysInMonth = new Date(year, month + 1, 0).getDate();
          const today = new Date();
          
          for (let i = 0; i < firstDay; i++) {
            daysGrid.insertAdjacentHTML('beforeend', `<div></div>`);
          }
          
          for (let i = 1; i <= daysInMonth; i++) {
            const isToday = (i === today.getDate() && month === today.getMonth() && year === today.getFullYear());
            const isSelected = (calSelectedDate && i === calSelectedDate.getDate() && month === calSelectedDate.getMonth() && year === calSelectedDate.getFullYear());
            
            let classStr = 'text-white rounded';
            if (isSelected) {
              classStr = 'bg-danger text-white rounded fw-bold shadow-sm';
            } else if (isToday) {
              classStr = 'bg-secondary text-white rounded fw-bold';
            }
            
            const hoverStr = !isSelected ? 'onmouseover="this.style.backgroundColor=\'rgba(255,255,255,0.1)\'" onmouseout="this.style.backgroundColor=\'transparent\'"' : '';
            daysGrid.insertAdjacentHTML('beforeend', `<div class="p-2 cal-day-item ${classStr}" data-day="${i}" style="cursor: pointer; transition: background 0.2s;" ${hoverStr}>${i}</div>`);
          }
        };

        if (calendarModalEl) {
          calendarModalEl.addEventListener('show.bs.modal', () => {
            const now = new Date();
            calCurrentMonth = now.getMonth();
            calCurrentYear = now.getFullYear();
            if (!calSelectedDate) calSelectedDate = new Date();
            
            const jumpInput = document.getElementById('cal-jump-date');
            if (jumpInput) {
              const y = calSelectedDate.getFullYear();
              const m = String(calSelectedDate.getMonth() + 1).padStart(2, '0');
              const d = String(calSelectedDate.getDate()).padStart(2, '0');
              jumpInput.value = `${y}-${m}-${d}`;
            }

            updateClock();
            renderCalendar(calCurrentMonth, calCurrentYear);
            clockInterval = setInterval(updateClock, 1000);
          });
          
          calendarModalEl.addEventListener('hidden.bs.modal', () => {
            clearInterval(clockInterval);
          });
          
          document.getElementById('cal-prev-month').addEventListener('click', () => {
            calCurrentMonth--;
            if (calCurrentMonth < 0) { calCurrentMonth = 11; calCurrentYear--; }
            renderCalendar(calCurrentMonth, calCurrentYear);
          });
          
          document.getElementById('cal-next-month').addEventListener('click', () => {
            calCurrentMonth++;
            if (calCurrentMonth > 11) { calCurrentMonth = 0; calCurrentYear++; }
            renderCalendar(calCurrentMonth, calCurrentYear);
          });

          document.getElementById('cal-days-grid').addEventListener('click', (e) => {
            if (e.target.classList.contains('cal-day-item')) {
              const day = parseInt(e.target.dataset.day);
              calSelectedDate = new Date(calCurrentYear, calCurrentMonth, day);
              renderCalendar(calCurrentMonth, calCurrentYear);
              
              const jumpInput = document.getElementById('cal-jump-date');
              if (jumpInput) {
                const y = calSelectedDate.getFullYear();
                const m = String(calSelectedDate.getMonth() + 1).padStart(2, '0');
                const d = String(calSelectedDate.getDate()).padStart(2, '0');
                jumpInput.value = `${y}-${m}-${d}`;
              }
            }
          });

          const jumpInput = document.getElementById('cal-jump-date');
          if (jumpInput) {
            jumpInput.addEventListener('change', (e) => {
              if (e.target.value) {
                const parts = e.target.value.split('-');
                calCurrentYear = parseInt(parts[0]);
                calCurrentMonth = parseInt(parts[1]) - 1;
                const day = parseInt(parts[2]);
                calSelectedDate = new Date(calCurrentYear, calCurrentMonth, day);
                renderCalendar(calCurrentMonth, calCurrentYear);
              }
            });
          }

          document.getElementById('cal-today-btn').addEventListener('click', () => {
            const now = new Date();
            calCurrentMonth = now.getMonth();
            calCurrentYear = now.getFullYear();
            calSelectedDate = now;
            renderCalendar(calCurrentMonth, calCurrentYear);
            
            if (jumpInput) {
              const y = now.getFullYear();
              const m = String(now.getMonth() + 1).padStart(2, '0');
              const d = String(now.getDate()).padStart(2, '0');
              jumpInput.value = `${y}-${m}-${d}`;
            }
          });
        }

        init();
      });
    </script>
  </body>
</html>