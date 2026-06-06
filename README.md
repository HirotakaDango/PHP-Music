# PHP Music

A simple, fast, and modern self-hosted music player built in PHP, with a clean UI, SQLite backend, and full PWA (Progressive Web App) features. Scan your music collection, play songs in your browser, manage favorites/playlists, download entire playlists, upload and edit your own songs, view lyrics, and more—all in one lightweight app.

![1](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/1.png)
![2](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/2.png) 
![3](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/3.png)
![4](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/4.png) 
[![Star History Chart](https://api.star-history.com/svg?repos=HirotakaDango/PHP-Music&type=Date)](https://www.star-history.com/#HirotakaDango/PHP-Music&Date)

---

## Demo

[Try demo 1 here on phpmusic.rf.gd](http://phpmusic.rf.gd)
[Try demo 2 here on https://phpmusic--relinktrees.replit.app](https://phpmusic--relinktrees.replit.app)

---

## Features

- 🎵 **Scan Local Music**: Recursively scans your directory for `mp3`, `m4a`, `flac`, `ogg`, and `wav` files (excluding uploads).
- 🏷️ **Automatic Metadata**: Uses [getID3](https://github.com/JamesHeinrich/getID3) to extract artist, album, year, genre, and cover images.
- 📚 **Library Management**: Browse by songs, artists, albums, genres, or favorites. Instant search included.
- ❤️ **Favorites**: Mark/unmark songs as favorites. Drag to reorder in the "Favorites" view. Import/export your favorites as JSON.
- 🔊 **Player**: Play, pause, next/prev, repeat, shuffle, seek, volume control, and cover art display. In-browser playback via HTML5 `<audio>`. Media Session API support for background media controls.
- 🖼️ **Album Art & Lyrics**: Displays embedded images as `.webp` (SVG fallback if missing) and allows viewing or adding song lyrics.
- 📱 **Responsive UI**: Mobile-optimized, fast, and touch-friendly with infinite scrolling.
- ⚡ **PWA Support**: Install as an app on your phone or desktop. Works offline (caches static assets & some API). Manifest & service worker included.
- 🚀 **No Database Setup**: Uses SQLite, auto-initialized on first run.
- 👤 **User Accounts & Profiles**: Register/login, upload a custom profile picture, and view your personal statistics.
- 🛡️ **Account Recovery**: Soft-delete your account while preserving data to generate a backup key, allowing you to restore your profile securely at a later time.
- 👥 **Social Features**: Follow other artists or users and find them easily in your "Following" tab.
- ☁️ **Upload Music**: Upload new songs (multi-file, genre auto-detected or custom). Each user can upload up to 10 songs per day (daily limit resets at midnight).
- ✏️ **Edit Metadata**: Edit a song's Title, Artist, Album, Genre, Lyrics, and Cover Image directly from the context menu (for your own uploads or as admin).
- ⬇️ **Downloader**: Built-in sequential downloader for batch-downloading entire playlists directly to your hard drive, or grabbing single tracks by ID.
- 🗑️ **Delete & Download**: Delete or download your own uploaded songs directly from the UI/context menu.
- 🔐 **Session Security**: All write actions require login. Uploads require account verification by an admin.
- 🛠️ **Settings & Cache**: Change password, manage your profile picture, toggle full-screen, or instantly clear the PWA app cache directly from the sidebar.
- 🏢 **Admin Panel**: Admin can verify/un-verify users, ban/unban malicious accounts, view user stats (last upload, daily count), and wipe user data.
- 🎶 **Playlists**: Create, manage, drag-to-reorder, import, export, and even copy other users' custom playlists.
- 🔗 **Shareable Views**: Share direct links to songs, albums, artists, and playlists across social media platforms (Facebook, Twitter, WhatsApp, Telegram).
- 🆘 **Full Library Scan**: If the library needs updating, a full scan can be performed to rebuild the database and verify sync with local files.
- 📈 **Recommendations & Auto-Mixes**: The "For You" tab generates personalized shelves based on your play history (logged after 30s), followed artists, top genres, and endless auto-generated mix playlists.

---

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `gd`, and `mbstring` extensions enabled.
- [getID3 library](https://github.com/JamesHeinrich/getID3) (extract to a `getid3` folder inside the project).
- A folder full of music files!

---

## How to Activate SQLite in XAMPP/LAMPP

If you are using **XAMPP** or **LAMPP** and encounter issues with SQLite:

### For XAMPP (Windows/macOS)

1. Open your `php.ini` file (usually found in `xampp/php/php.ini`).
2. Ensure these lines are **not** commented (remove the leading semicolon `;` if present):

    ```ini
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save and restart Apache using the XAMPP control panel.

### For LAMPP (Linux)

1. Open `/opt/lampp/etc/php.ini`.
2. Ensure:

    ```ini
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save and restart Apache:

    ```bash
    sudo /opt/lampp/lampp restart
    ```

### Verify SQLite is enabled

- Create a `phpinfo.php` file:

    ```php
    <?php phpinfo(); ?>
    ```
- Open in your browser and search for "sqlite" or "PDO drivers". You should see `sqlite3` and `pdo_sqlite` enabled.

---

## Installation

1. **Clone the repo:**

    ```bash
    git clone https://github.com/HirotakaDango/PHP-Music.git
    cd PHP-Music
    ```

2. **Download getID3:**

    - [Download latest getID3](https://github.com/JamesHeinrich/getID3/releases)
    - Extract as a `getid3` folder inside the project root:
      ```text
      PHP-Music/
        index.php
        getid3/
          getid3.php
          ...
      ```

3. **Place music files:**

    - Put your music files in the root folder or any subfolder (except `uploads/`).
    - The player recursively scans for supported audio files.

4. **Set permissions (if needed):**

    - PHP must be able to write to `music.db` in the project directory.
    - For uploads, ensure the `uploads/` folder (auto-created) is writable by PHP.

5. **Run with your favorite PHP server:**

    - Built-in server (for testing):
      ```bash
      php -S localhost:8000
      ```
    - Or use with Apache/Nginx as a standard PHP site.

6. **Open in browser:**

    - Go to [http://localhost:8000](http://localhost:8000)
    - Register a user account to unlock uploading and library scanning.
    - **IMPORTANT:** After registering, an admin must verify your account before you can upload music (see below).
    - Click "Scan All" to index your music folder.

---

## Usage

- **Register/Login**: Create a user account for full features (upload, scan, delete, edit, favorites, playlists, following).
- **Profile Picture**: Set or change your profile picture from the settings modal (accepts PNG, JPG, GIF).
- **Account Verification**: After registering, your account must be verified by an admin before you can upload music. Unverified users can still scan, browse, and play music.
- **Account Backup/Restore**: If you need to change your email or reset credentials safely, use "Delete Account but Keep Data" in Settings to receive a backup key. You can restore this key via the "Restore Account" modal.
- **Upload Limit**: Each user can upload up to 10 songs per day (resets at midnight).
- **Scan Library**: Click "Scan All" in the sidebar to index or refresh your library (synchronizes disk files with database).
- **Browse**: Use the sidebar to view all songs, favorites, albums, artists, genres, or your own uploads.
- **Playlists**: Create, edit, drag-to-reorder, import, export, and copy custom playlists. Add/remove songs easily.
- **Playlist Downloader**: Open the "Downloader" tool from the sidebar, enter a Playlist ID, and sequentially batch-download every track in that playlist to your device.
- **Following**: Follow your favorite artists and users to easily access their tracks.
- **Search**: Use the search bar (desktop/mobile) to instantly find songs, albums, or artists.
- **Play Music**: Click a song to play, or use the player controls at the bottom.
- **Favorites**: Click the heart icon to add/remove from favorites. Drag to reorder in "Favorites" view. Export or import your favorites at any time.
- **Edit Metadata & Lyrics**: Right-click (or tap "..." on mobile) a song and choose "Edit Info" (your own uploads or as admin) to change Title, Artist, Album, Genre, Lyrics, and Cover Art. You can also view lyrics via "Show Lyrics" or check file details via "View Metadata".
- **Upload Music**: Click "Upload Song". You can upload multiple files at once. **Upload limit:** 10 songs per user per day (resets at midnight).
- **Delete/Download**: Use the context menu on your uploads to delete or download the actual file.
- **Share**: Click the "Share" button on albums, artists, playlists, or songs to get a direct, shareable link for social platforms.
- **PWA & Cache Management**: Click "Install App" (sidebar) if your browser supports PWAs. Works offline for playback and browsing of cached assets. Use "Clear Cache" if you need to force a hard reset of the UI or Service Worker.
- **Recommendations**: Use the "For You" page for personalized recommendations (Recently Played, More from your top artists, Your Genre Mix, Discover New Songs, Auto-Mixes, etc.).
- **User Statistics**: Access your total uploads, favorites, playlists, and play counts from the "Statistics" option in the profile dropdown.

---

### Admin Panel

- Go to `?access=admin` (e.g., `http://localhost:8000/?access=admin`)
- Default Admin Password: `admin`
- **User Management**: View all users (paginated to 20 users per page), search by ID/Email/Artist, and filter by status.
- **Verification**: Admin can verify/un-verify user accounts.
- **Banning**: Suspend malicious users easily. Banned users are completely locked out of the app.
- **Data Deletion**: Admins can permanently wipe a user and all of their uploaded files.
- The default "Music Library" user operates as an admin context for files scanned directly from the disk.

---

## How does it work?

- **index.php** is both the backend API (`?action=...`) and the single-page frontend application.
- User authentication is session-based (server-side PHP sessions) with secure password hashing.
- User uploads are separated—each user can only manage and edit their own uploads.
- Scanning uses getID3 for metadata and album art extraction, storing everything in `music.db` (SQLite).
- Album art and profile pictures are extracted, resized, and converted to `.webp` on the fly to save space and bandwidth.
- Playback runs via JavaScript and HTML5 `<audio>`, utilizing the Media Session API.
- PWA support includes a generated web manifest and a dynamic service worker (`?pwa=manifest`, `?pwa=sw`) to handle offline caching. IndexedDB is used for version tracking.
- Uploads are safely stored in `/uploads/{artist_slug}/` directories.
- Complete metadata modification is supported via the `edit_metadata` action which updates the database, and writes ID3 tags back into the file using getID3's writetags function.
- Playlists and favorites support fluid drag-and-drop ordering powered by SortableJS, pushing positional arrays back to the server.
- Play histories and view counts are continuously logged locally (after 30 seconds of playback) to generate personalized "For You" shelves and track statistics.
- A `follows` table tracks user-to-artist and user-to-user relationships.

---

## Customization

- **Colors**: Edit CSS variables (`--ytm-bg`, `--ytm-accent`, etc.) in the `<style>` block inside `index.php`.
- **Audio formats**: Adjust the regex (`/\.(mp3|m4a|flac|ogg|wav)$/i`) in `perform_full_scan()` within `index.php` to add or restrict formats.
- **Remote sources**: Would require backend refactoring.
- **Public/Internet use**: Built with public sharing in mind, but it is advised to use SSL/TLS.

---

## Security

- **Warning:** Intended for personal use on your own server or LAN, though robust enough for small community instances.
- Each user is securely sandboxed to their own uploads, favorites, playlists, and profile.
- Users must be explicitly verified by an admin before they are allowed to upload music.
- File types, image processing (only accepts standard images and converts to WebP/JPEG), and tag decoding use sanitized structures to mitigate basic injection attacks.
- The Admin panel is strictly protected by a securely hashed password. Banned accounts are checked upon every login attempt.

---

## Troubleshooting

- **Scan errors**: Ensure the `getid3/` directory exists and is accessible. Make sure PHP has read/write permissions for the local files and the project root (to create `music.db`).
- **Upload errors**: Make sure the `uploads/` directory is writable and PHP settings allow sufficiently large uploads (`upload_max_filesize`, `post_max_size`). Ensure your account has been verified by the admin.
- **Missing album art**: Some files may lack embedded images; a default SVG icon will show as a fallback.
- **Playback issues**: Browser support for lossless formats like FLAC or WAV may vary across devices.
- **Metadata not saving**: Ensure the file permissions allow PHP to overwrite the file when utilizing the ID3 tag writer.
- **Profile picture issues**: Only PNG, JPG, and GIF files are supported (they are auto-converted to webp on upload).

---

Enjoy your self-hosted PHP music player!  
Contributions and feedback are always welcome.
