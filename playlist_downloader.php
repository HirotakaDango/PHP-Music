<?php
error_reporting(E_ALL & ~E_DEPRECATED);
session_start();

define('MUSIC_DIR', __DIR__);
define('DB_FILE', MUSIC_DIR . '/music.db');

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

function resolve_file_path($stored_path) {
  if (file_exists($stored_path)) {
    return $stored_path;
  }
  $relative_path = ltrim($stored_path, '/\\');
  $candidate = MUSIC_DIR . '/' . $relative_path;
  if (file_exists($candidate)) {
    return $candidate;
  }
  return null;
}

function sanitize_filename($name) {
  $name = preg_replace('/[\/\\:*?"<>|#%&{}]/', '_', $name);
  $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
  $name = trim($name, '. ');
  if (empty($name)) {
    $name = 'song';
  }
  return $name;
}

// API: get song info
if (isset($_GET['get_song']) && isset($_GET['id'])) {
  $db = get_db();
  $stmt = $db->prepare("SELECT id, title, artist FROM music WHERE id = ?");
  $stmt->execute([(int)$_GET['id']]);
  $song = $stmt->fetch();
  if ($song) {
    header('Content-Type: application/json');
    echo json_encode(['title' => $song['title'], 'artist' => $song['artist']]);
  } else {
    http_response_code(404);
    echo json_encode(['error' => 'Song not found']);
  }
  exit;
}

// Download endpoint
if (isset($_GET['serve']) && isset($_GET['file'])) {
  $db = get_db();
  $file_param = $_GET['file'];
  $title_param = $_GET['title'] ?? null;

  $stmt = $db->prepare("SELECT id, file, title, artist FROM music WHERE file = ? OR id = ?");
  $stmt->execute([$file_param, (int)$file_param]);
  $song = $stmt->fetch();

  if (!$song) {
    http_response_code(404);
    die("Song not found in database.");
  }

  $full_path = resolve_file_path($song['file']);
  if (!$full_path || !file_exists($full_path)) {
    http_response_code(404);
    die("File not found on server.");
  }

  if ($title_param !== null) {
    $download_name = sanitize_filename($title_param) . '.mp3';
  } else {
    $base = $song['title'];
    if (!empty($song['artist'])) {
      $base .= ' - ' . $song['artist'];
    }
    $download_name = sanitize_filename($base) . '.mp3';
  }

  $encoded_name = rawurlencode($download_name);

  header('Content-Description: File Transfer');
  header('Content-Type: audio/mpeg');
  header('Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name);
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($full_path));

  ob_clean();
  flush();
  readfile($full_path);
  exit;
}

$playlist_public_id = isset($_GET['id']) ? trim($_GET['id']) : '';
$db = get_db();
$playlist = null;
$songs = [];

if (!empty($playlist_public_id)) {
  $stmt = $db->prepare("SELECT id, name FROM playlists WHERE public_id = ?");
  $stmt->execute([$playlist_public_id]);
  $playlist = $stmt->fetch();
  if ($playlist) {
    $numeric_playlist_id = $playlist['id'];
    $stmt = $db->prepare("
      SELECT m.id, m.title, m.artist, m.album, m.duration
      FROM playlist_songs ps
      JOIN music m ON ps.song_id = m.id
      WHERE ps.playlist_id = ?
      ORDER BY ps.sort_order ASC, ps.added_at ASC
    ");
    $stmt->execute([$numeric_playlist_id]);
    $songs = $stmt->fetchAll();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playlist Downloader - PHP Music</title>
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
      * {
        color: var(--ytm-primary-text) !important;
      }
      body {
        background-color: var(--ytm-bg);
        font-family: 'Roboto', sans-serif;
        padding: 2rem;
      }
      .container {
        max-width: 1000px;
        margin: 0 auto;
      }
      .card {
        background-color: var(--ytm-surface);
        border: none;
        border-radius: 12px;
        margin-bottom: 1.5rem;
      }
      .card-header {
        background-color: var(--ytm-surface-2);
        border-bottom: none;
        font-weight: 700;
        font-size: 1.25rem;
        padding: 1rem 1.5rem;
      }
      .btn-danger {
        background-color: var(--ytm-accent);
        border: none;
      }
      .btn-danger:hover {
        background-color: #cc0000;
      }
      .song-list {
        background-color: var(--ytm-surface);
        border-radius: 12px;
        overflow: hidden;
      }
      .song-item {
        display: grid;
        grid-template-columns: 50px 2fr 2fr 100px 60px;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      .song-item:last-child {
        border-bottom: none;
      }
      .log-area {
        background-color: var(--ytm-surface-2);
        border-radius: 8px;
        padding: 1rem;
        font-family: 'Courier New', monospace;
        font-size: 0.85rem;
        height: 300px;
        overflow-y: auto;
      }
      .log-line {
        border-bottom: 1px solid #404040;
        padding: 0.25rem 0;
      }
      .form-control {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
      }
      .form-control:focus {
        background-color: var(--ytm-surface-2);
        border-color: #666;
        box-shadow: none;
      }
      .form-control::placeholder {
        color: #888888 !important;
      }
      .badge {
        background-color: var(--ytm-surface-2);
      }
      .btn-outline-secondary {
        border-color: #404040;
      }
      .btn-outline-secondary:hover {
        background-color: var(--ytm-surface-2);
        border-color: #666;
      }
      .btn-outline-light {
        border-color: var(--ytm-primary-text) !important;
      }
      .btn-outline-light:hover {
        background-color: var(--ytm-surface-2) !important;
      }
      .text-truncate {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .text-secondary {
        color: var(--ytm-secondary-text) !important;
      }
      .pagination .page-link {
        background-color: var(--ytm-surface-2);
        border-color: #404040;
      }
      .pagination .page-item.active .page-link {
        background-color: var(--ytm-accent);
        border-color: var(--ytm-accent);
      }
      .pagination .page-item.disabled .page-link {
        background-color: var(--ytm-surface);
        opacity: 0.5;
      }
      hr.text-secondary {
        border-color: #404040;
      }
      a {
        text-decoration: none;
      }
      a:hover {
        color: var(--ytm-accent) !important;
      }
      @media (max-width: 768px) {
        body { padding: 1rem; }
        .song-item { grid-template-columns: 40px 1fr 30px; gap: 0.5rem; }
        .song-item .artist-col, .song-item .duration-col { display: none; }
        .mobile-artist { display: block; font-size: 0.75rem; grid-column: 2; }
        .song-item .title-col { grid-column: 2; }
        .song-item .download-col { grid-column: 3; }
      }
      .mobile-artist { display: none; }
    </style>
  </head>
  <body>
    <div class="container">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3"><i class="bi bi-download"></i> Playlist Downloader</h1>
        <div>
          <a href="./" class="btn btn-outline-light me-2"><i class="bi bi-arrow-left"></i> Back to Player</a>
          <?php if ($playlist): ?>
            <a href="./" class="btn btn-outline-light"><i class="bi bi-house-door"></i> Main Menu</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$playlist): ?>
        <div class="card">
          <div class="card-header">Select a Playlist</div>
          <div class="card-body">
            <form method="GET" class="mb-4">
              <div class="mb-3">
                <label for="playlist_id" class="form-label">Playlist ID</label>
                <input type="text" class="form-control" id="playlist_id" name="id" placeholder="Enter Playlist Public ID">
              </div>
              <button type="submit" class="btn btn-danger">Load Playlist</button>
            </form>
            <hr class="text-secondary">
            <div class="mb-3">
              <label for="song_id_input" class="form-label">Download a single song by ID</label>
              <div class="input-group">
                <input type="number" class="form-control" id="song_id_input" placeholder="Song ID">
                <button class="btn btn-outline-light" id="download_song_btn">Download</button>
              </div>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="card">
          <div class="card-header">
            <i class="bi bi-music-note-beamed"></i> <?= htmlspecialchars($playlist['name']) ?>
            <span class="badge ms-2"><?= count($songs) ?> songs</span>
          </div>
          <div class="card-body">
            <button class="btn btn-danger w-100 mb-3" id="startAutoBtn">
              <i class="bi bi-play-fill"></i> Download All Songs (Sequential)
            </button>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span><i class="bi bi-terminal"></i> Download Log</span>
                <button class="btn btn-sm btn-outline-secondary" id="clearLogBtn">Clear</button>
              </div>
              <div class="log-area" id="logArea"></div>
            </div>

            <div id="songsSection">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Song List</strong>
                <div class="d-none d-md-block text-secondary small">Manual: click <i class="bi bi-download"></i> per song</div>
              </div>
              <div class="song-list" id="songListContainer">
                <div class="song-item text-secondary small d-none d-md-grid">
                  <div>#</div><div>Title</div><div>Artist</div><div>Duration</div><div></div>
                </div>
                <div id="songRows"></div>
              </div>
              <nav id="paginationControls" class="mt-3 d-none">
                <ul class="pagination justify-content-center mb-0" id="paginationUl"></ul>
              </nav>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <script>
      <?php if ($playlist && !empty($songs)): ?>
      const allSongs = <?= json_encode($songs) ?>;
      let currentPage = 1;
      const itemsPerPage = 50;
      let isDownloading = false;
      let stopRequested = false;

      function truncate(str, len = 50) { return str.length > len ? str.substring(0, len) + '…' : str; }

      function log(message, isError = false) {
        const logArea = document.getElementById('logArea');
        const time = new Date().toLocaleTimeString();
        const div = document.createElement('div');
        div.className = 'log-line';
        div.style.color = isError ? '#ff8888' : '#aaaaaa';
        div.innerHTML = `[${time}] ${message}`;
        logArea.appendChild(div);
        div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }

      async function downloadSong(song, isAuto = false, index = null, total = null) {
        const safeTitle = (song.title + ' - ' + (song.artist || 'Unknown')).replace(/[^a-zA-Z0-9\s\.\-\(\)\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]/g, '_');
        const url = `?serve=1&file=${encodeURIComponent(song.id)}&title=${encodeURIComponent(safeTitle)}`;
        if (isAuto) {
          log(`Downloading ${index+1}/${total}: ${song.title} - ${song.artist || 'Unknown'}`);
        } else {
          log(`Manual download: ${song.title} - ${song.artist || 'Unknown'}`);
        }
        const a = document.createElement('a');
        a.href = url;
        a.download = safeTitle + '.mp3';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        await new Promise(r => setTimeout(r, 1200));
      }

      function stopAutoDownload() {
        if (!isDownloading) return;
        stopRequested = true;
        log('⏹️ Stopping download process...', false);
        const startBtn = document.getElementById('startAutoBtn');
        startBtn.disabled = true;
        startBtn.innerHTML = '<i class="bi bi-stop-fill"></i> Stopping...';
      }

      async function startAutoDownload() {
        if (isDownloading) return;
        isDownloading = true;
        stopRequested = false;
        const startBtn = document.getElementById('startAutoBtn');
        startBtn.removeEventListener('click', startAutoDownload);
        startBtn.addEventListener('click', stopAutoDownload);
        startBtn.classList.remove('btn-danger');
        startBtn.classList.add('btn-warning');
        startBtn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop Download';
        startBtn.disabled = false;
        log('🚀 Starting sequential download of ALL songs. Click "Stop Download" to cancel.');
        for (let i = 0; i < allSongs.length; i++) {
          if (stopRequested) {
            log(`⏹️ Download stopped by user after ${i}/${allSongs.length} songs.`);
            break;
          }
          await downloadSong(allSongs[i], true, i, allSongs.length);
        }
        startBtn.removeEventListener('click', stopAutoDownload);
        startBtn.addEventListener('click', startAutoDownload);
        startBtn.classList.remove('btn-warning');
        startBtn.classList.add('btn-danger');
        startBtn.innerHTML = '<i class="bi bi-play-fill"></i> Download All Songs (Sequential)';
        startBtn.disabled = false;
        if (!stopRequested) {
          log(`✅ All ${allSongs.length} songs have been sent for download!`);
        }
        isDownloading = false;
        stopRequested = false;
      }

      function renderSongRows() {
        const start = (currentPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        const pageSongs = allSongs.slice(start, end);
        const rowsHtml = pageSongs.map((song, idx) => {
          const globalIdx = start + idx;
          return `
            <div class="song-item" data-song-id="${song.id}">
              <div>${globalIdx + 1}</div>
              <div class="title-col text-truncate" title="${escapeHtml(song.title)}">${escapeHtml(truncate(song.title, 60))}</div>
              <div class="artist-col text-truncate d-none d-md-block" title="${escapeHtml(song.artist)}">${escapeHtml(song.artist ? truncate(song.artist, 40) : 'Unknown')}</div>
              <div class="duration-col">${formatDuration(song.duration)}</div>
              <div class="download-col"><button class="btn btn-sm btn-outline-light manual-download" data-id="${song.id}" data-title="${escapeHtml(song.title)}" data-artist="${escapeHtml(song.artist)}"><i class="bi bi-download"></i></button></div>
              <div class="mobile-artist d-md-none">${escapeHtml(song.artist ? truncate(song.artist, 40) : 'Unknown')}</div>
            </div>
          `;
        }).join('');
        document.getElementById('songRows').innerHTML = rowsHtml;
        attachManualListeners();
        updatePagination();
      }

      function attachManualListeners() {
        document.querySelectorAll('.manual-download').forEach(btn => {
          btn.removeEventListener('click', manualHandler);
          btn.addEventListener('click', manualHandler);
        });
      }

      async function manualHandler(e) {
        e.preventDefault();
        const btn = e.currentTarget;
        const songId = parseInt(btn.dataset.id);
        const song = allSongs.find(s => s.id === songId);
        if (song) {
          await downloadSong(song, false);
        }
      }

      function formatDuration(sec) {
        if (!sec) return '0:00';
        const minutes = Math.floor(sec / 60);
        const seconds = Math.floor(sec % 60).toString().padStart(2, '0');
        return `${minutes}:${seconds}`;
      }

      function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
          if (m === '&') return '&amp;';
          if (m === '<') return '&lt;';
          if (m === '>') return '&gt;';
          return m;
        }).replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, function(c) {
          return c;
        });
      }

      function updatePagination() {
        const totalPages = Math.ceil(allSongs.length / itemsPerPage);
        const controls = document.getElementById('paginationControls');
        if (totalPages <= 1) {
          controls.classList.add('d-none');
          return;
        }
        controls.classList.remove('d-none');
        let pagesHtml = '';
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);
        if (startPage > 1) pagesHtml += `<li class="page-item"><a class="page-link" data-page="1">1</a></li><li class="page-item disabled"><span class="page-link">...</span></li>`;
        for (let i = startPage; i <= endPage; i++) {
          pagesHtml += `<li class="page-item ${i === currentPage ? 'active' : ''}"><a class="page-link" data-page="${i}">${i}</a></li>`;
        }
        if (endPage < totalPages) pagesHtml += `<li class="page-item disabled"><span class="page-link">...</span></li><li class="page-item"><a class="page-link" data-page="${totalPages}">${totalPages}</a></li>`;
        document.getElementById('paginationUl').innerHTML = pagesHtml;
        document.querySelectorAll('.page-link[data-page]').forEach(link => {
          link.addEventListener('click', (e) => {
            e.preventDefault();
            const page = parseInt(link.dataset.page);
            if (!isNaN(page) && page !== currentPage) {
              currentPage = page;
              renderSongRows();
              document.getElementById('songListContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });
        });
      }

      document.getElementById('startAutoBtn').addEventListener('click', startAutoDownload);
      document.getElementById('clearLogBtn').addEventListener('click', () => {
        document.getElementById('logArea').innerHTML = '';
        log('Log cleared.');
      });
      window.addEventListener('load', () => {
        window.scrollTo(0, 0);
        renderSongRows();
        log(`Ready. ${allSongs.length} songs loaded.`);
      });
      <?php else: ?>
      document.getElementById('download_song_btn')?.addEventListener('click', async () => {
        const songId = parseInt(document.getElementById('song_id_input').value);
        if (isNaN(songId) || songId <= 0) {
          alert('Enter a valid song ID');
          return;
        }
        try {
          const response = await fetch(`?get_song=1&id=${songId}`);
          if (!response.ok) {
            alert('Song not found or invalid ID');
            return;
          }
          const song = await response.json();
          const safeTitle = (song.title + ' - ' + (song.artist || 'Unknown')).replace(/[^a-zA-Z0-9\s\.\-\(\)\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]/g, '_');
          const url = `?serve=1&file=${encodeURIComponent(songId)}&title=${encodeURIComponent(safeTitle)}`;
          const a = document.createElement('a');
          a.href = url;
          a.download = safeTitle + '.mp3';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
        } catch (err) {
          alert('Error fetching song info');
          console.error(err);
        }
      });
      <?php endif; ?>
    </script>
  </body>
</html>