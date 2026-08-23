# PHP Music

A modern self-hosted music player built in PHP, with a clean UI, SQLite backend, and full PWA (Progressive Web App) features. Scan your music collection, play songs in your browser, manage favorites/playlists, download entire playlists, upload and edit your own songs, view lyrics, write and publish Markdown blogs, edit images in the canvas studio, explore PHPMusic Drive with 4 dynamic layout modes, play rhythm game beatmaps, write code via an integrated PHPEditor IDE, and more—all in one lightweight app.

![1](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/1.png)
![2](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/2.png) 
![3](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/3.png)
![4](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/4.png) 
![5](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/5.png) 
![6](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/6.png) 
![7](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/7.png) 
![8](https://raw.githubusercontent.com/HirotakaDango/php-music-wiki/refs/heads/main/8.png) 

---

## Demo

* [Try demo 1 here on phpmusic.rf.gd](http://phpmusic.rf.gd)
* [Try demo 2 here on phpmusic--relinktrees.replit.app](https://phpmusic--relinktrees.replit.app)

---

## Complete Features Directory

### 1. Playback, Queue & Audio Engine

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **Advanced Audio Routing** | Dual-HTML5 node setup with Web Audio API. Routes audio via gain nodes to biquad filters and dynamic compressors. | Seamless gapless crossfading over an adjustable 3-second period. |
| **5-Band Graphic Equalizer** | Togglable equalizer directly accessible within the settings panel. | Independent frequency bands at 60Hz, 230Hz, 910Hz, 3.6kHz, and 14kHz. |
| **Volume Normalization** | Real-time Automatic Gain Control (AGC). | Normalizes varying track volumes using a Web Audio API dynamics compressor. |
| **Spatial Audio (HRTF)** | 3D surround simulation for headphone users. | Enabled via Web Audio PannerNode with HRTF panning model. |
| **Per-Song Audio Settings** | Override volume and EQ for individual tracks. | Stored per user in `user_song_settings` table; applied automatically on playback. |
| **Dynamic Queue Management** | YouTube Music-style "Up Next" queue with "Play Next" and "Add to Queue" actions. | Mobile and desktop player modals include an "Up Next" queue tab with chunked, on-demand infinite scroll. |
| **Media Session API Integration** | Background controls and metadata mirroring. | Emits lockscreen meta and handles system prev/next/seek keys globally on Android, iOS, Windows, and macOS. |
| **Infinite Autoplay (Station)** | Appends 15 recommended tracks based on the artist and genre of the last seed song. | Triggers automatically once the active queue is exhausted. |
| **Draggable Sleep Timer** | Schedule playback to auto-pause. | Features a draggable, floating countdown bubble that locks within screen boundaries and includes a NoSleep.js stay-awake fallback. |
| **Stay-Awake Guard** | Prevents screen dimming or timeout on mobile browsers while playing. | Uses `NoSleep.js` (under-the-hood silent HTML5 video looping) to lock screen state safely. |
| **Keyboard Shortcuts** | Full set of keyboard controls for playback, navigation, and actions. | Space (play/pause), arrow keys (seek/volume), numbers for jump, many more. |

### 2. Library, Curation & Social Ecosystem

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **Automatic Metadata Scans** | Recursively scans folders to sync physical files. | Indexes tags (Title, Artist, Album, Genre, Year, Cover Art) using getID3. |
| **Favorites with Custom Sorting** | Mark tracks as favorites with a single tap. | Pushes custom sorting arrays back to the server using SortableJS fluid drag-and-drop. |
| **Listen Later (Bookmark)** | Queue up tracks you intend to play at a later date. | Tracks bookmarks using the `listen_later` table. Displays an intuitive bookmark outline/fill toggle and supports manual drag-and-drop sorting via SortableJS. |
| **Curation Mixes ("For You")** | Generates personalized mixes, discover shelves, and artist auto-mixes. | Compiles metrics using history and play counts logged after 30 seconds of playback. |
| **Collaborative Playlists** | Invite users by username/email to co-edit. | Tracks contributions with an `added_by` column on the `playlist_songs` table and validates using a `playlist_collaborators` lookup. |
| **Social Following & Blocking** | Build your network and curate interactions. | Tracks relationships using `follows` and `blocks` tables. Blocking a user automatically severs follows and prevents messaging. |
| **Direct Messaging (Inbox)** | Real-time peer-to-peer chat system. | Operates on the `messages` table. Includes inbox user searching, image attachments, edit/delete controls, active status, and read/unread indicators. |
| **Direct Deep-Linking** | Share exact deep-links to tracks, playlists, artists, albums, and blog posts. | Emits direct sharing hooks to social platforms (Facebook, X, WhatsApp, Telegram) with direct query parameters. |
| **Playlists Portability** | Create, manage, import, export, and clone playlists. | Supports copying public playlists directly from other users, alongside JSON import/export handlers. |
| **Community Social Feed** | Micro-blogging space for sharing status updates, announcements, or thoughts. | Operates on the `community_posts` and `community_reactions` tables. Allows full CRUD capabilities for post owners, with likes/dislikes and multi-sorting (Newest, Most Liked, Following Users). |
| **Song & Blog Discussions** | Threaded comments and reaction metrics for tracks and blog posts. | Leverages dedicated comment tables (`song_comments`, `blog_comments`, reactions). Features nested reply trees, edit/delete controls, likes, dislikes, and `@` username tag highlighting. Comments are read-only for non-logged-in guests. |
| **Blogging & Markdown Platform** | Write, publish, or draft blogs with live Markdown preview, Find & Replace, and multi-format exports. | Uses `blogs` and `blog_categories` tables. Features auto-saving drafts, word/character counter, categories, status toggles (*Public* vs *Private*), multi-select bulk actions (download ZIP/delete), debounced search, and multi-format exports (PDF, HTML, MD, TXT, or ZIP). |
| **Blog Discussions** | Threaded comments and reaction metrics for blog posts. | Built on the `blog_comments` table. Nested reply trees, reactions (likes/dislikes) and user mentions. Comments are read-only for guests. |
| **Personal Notes** | Private, encrypted markdown notes with live preview, Find & Replace, Undo/Redo, and export/import. | Uses OPFS (Origin Private File System) for local caching and `personal_notes` table. Supports categories, starring, and real-time collaboration sync via SSE. |
| **Tasks** | Manage task lists with checkboxes, markdown support, and live preview. | Uses `tasks` table with JSON items. Supports categories, starring, and export/import. |
| **PHPShares – Artwork & Manga Gallery** | A dedicated art sharing platform for illustrations, manga, and comics. | Upload images, tag with metadata (characters, parodies, groups, series). Supports series collections, NSFW flagging, favorites, comments with threaded replies, and a manga reader with page-by-page navigation. Artwork views are tracked and displayed. |
| **Upload Collaborators Search** | Choose multiple collaborators using a visual name/email search panel before uploading. | Integrates the exact same professional search dropdown and pill-based list as the edit collaborators modal directly inside the upload form. |
| **Rhythm Game Engine** | Interactive game utilizing parsed tracks directly from your database. | Uses Web Audio API for fast decodes. Automatically builds note beatmaps via root-mean-square energy checks. Features lane speed scaling (up to 20x), pause states, and global standing leaderboards. |
| **Advanced Image Editor (ImagEditor)** | Multi-layered image composition workspace with brush, text, shapes, filters, and layer management. | Built on Fabric.js and the HTML5 Canvas API. Supports undo/redo, zoom/pan, layer ordering, opacity, blending, and export to PNG, JPEG, WEBP, SVG, or project JSON. |

### 3. Personal Privacy Controls

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **Personal Private Playlists** | Hides chosen playlists completely from other users. | Filtered strictly via SQL checking. Private state disables collaboration options and purges all previous collaborators. |
| **Personal Private Songs** | Restricts uploaded songs strictly to the owner. | Private songs are stripped from all public index views, search, and other users' public playlists. |
| **Personal Private Blogs** | Restricts draft blog posts strictly to the author. | Draft/private blogs are visible only in the author's editor and management view. |
| **Super Admin Global Override** | Grants master bypass privileges to the default `Music Library` user account. | Logging into the `musiclibrary@mail.com` account unlocks access to view, stream, and play all private assets system-wide. |

### 4. Cache, Offline & Download Management

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **True Offline Caching** | Caches audio files, metadata, and covers directly to browser storage. | Service Worker intercepts stream requests (`?action=get_stream`) to serve range-slice data (`206 Partial Content`) offline. |
| **Cache Integrity Verification** | Incomplete caches automatically dim in the UI. | Offline Music tab validates local storage, blocks invalid playback, and shows warning indicators with a re-download cache trigger. |
| **Local File Export** | Export raw audio files directly out of browser storage. | A context menu option dynamically appears for fully cached tracks, saving mobile data. |
| **Offline Drag-and-Drop** | Reorder offline music manually. | Stores customized sort order arrays with SortableJS, supporting dedicated offline JSON import/export backups. |
| **Playlist Downloader Tool** | Sequential batch downloader for whole playlists. | Fetches entire playlists or single songs by ID, saving them directly to device storage with real-time log outputs. |

### 5. Administrative Suite & PHPMusic Drive

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **PHPMusic Drive (PHPFiles Core)** | Enterprise-grade cloud drive with 4 adaptive layout modes, OPFS caching, and zero external DB dependency. | Integrated at `?access=admin&page=drive`. Supports 1:1 Square Grid, Column Masonry (Waterfall), Justified Row-Based Masonry (Dynamic Aspect-Ratio Widths with Row-Height Slider Scaling from 125px to 380px), and Compact List View. |
| **In-App Canvas Image Studio** | Professional raster image editing suite directly within PHPMusic Drive. | Interactive 8-point draggable & resizable Crop Engine (*Freeform, 1:1, 16:9, 4:3, 9:16*), 90° CW/CCW rotations, flip horizontal/vertical, filter tuning (Brightness, Contrast, Saturation, Grayscale, Sepia, Invert), freeform draggable text overlays & header banners, full Undo/Redo stack (`Ctrl+Z` / `Ctrl+Y`), and dual save modes (*Overwrite with auto-snapshot backup* or *Save as Duplicate*). |
| **Integrated PHPEditor (IDE)** | Full-fledged server file IDE directly in the browser. | Built on Ace Editor. Features syntax highlighting, multi-tab support, file tree explorer, auto-saving, file history/rollback, diff viewer, and an interactive terminal console. |
| **PHPAudio – Audio Editor** | Edit audio files directly in your browser: trim, amplify, adjust volume, and update metadata. | Uses Web Audio API for waveform rendering and trimming; getID3 for tag extraction and physical writing. |
| **Interactive Calendar** | Built-in date planner and time referencing tool. | Accessible via the sidebar. Features a live clock, dynamic month/year navigation, and date picker. |
| **1:1 Image Cropper** | Crop profile pictures and song covers. | Integrated 1:1 aspect-ratio cropping canvas with panning/zoom, converting uploads to WebP/JPEG format. |
| **Account Soft-Delete** | Soft-deletes user credentials while keeping upload logs, notes, tasks, and blogs intact. | Anonymizes account details and generates a physical recovery backup key. |
| **Long-Lasting Persistent Sessions** | Keep sessions logged-in persistently. | Persists sessions safely for up to 1 year using custom garbage collection and 1-year admin cookies. |
| **ID3 Metadata & Lyrics Editor** | Overwrite metadata and LRC lyrics directly. | Modifies DB records and writes tags physically back into files using getID3 write functions. Automatically mirrors artwork in `covers/songs` and `covers/albums`. |
| **Upload Quotas** | Multi-file uploads with quota tracking. | Restricts uploads to verified users with a daily limit of 10 songs/day (resetting at midnight). |
| **PHPDBManager** | Web-based SQLite database manager for administrators. | View, edit, insert, delete, export/import tables, run custom SQL queries, and perform maintenance (VACUUM, integrity check, WAL checkpoint). |
| **API Key Management** | Generate and manage custom API keys for external integrations. | Supports 1,000 requests per month per key, with verification states, expiration timestamps, and ban controls. |
| **Storage Stats Analytics** | Visual disk usage breakdown, audio file metrics, non-audio asset footprints, and per-user storage consumption charts. | Real-time Chart.js doughnut and bar charts analyzing disk quotas and server storage footprints. |

### 6. Developer & Power-User Tools

| Feature | Description | Technical Implementation |
| :--- | :--- | :--- |
| **Open API Endpoints** | RESTful JSON API for querying library, streaming, and mutations. | Complete documentation available via the "API Documentation" sidebar link. |
| **API Playground** | Visual interface to test API endpoints, inspect raw JSON responses, and execute queries. | Integrated iframe client that can be shared via URL hashes. |
| **HDMarkDown & Code Workspace** | Split-view editor with live syntax highlighting and Mermaid diagram rendering. | CodeMirror 5 integration supporting Markdown, HTML, PHP, JS, Python, SQL, C/C++, XML, and CSS. |
| **Full Library Scan** | One-click scan to import all audio files from the server disk. | Recursively traverses directories, extracts tags with getID3, and populates the database. |
| **Forced Rescan** | Re-analyze metadata (artists, songs, covers) without waiting for file changes. | Fixes corrupted tags or updates artist mappings. |
| **Rhythm Chart Rescan** | Regenerate beatmaps for all songs (or per difficulty) with custom density. | Creates deterministic note charts based on song length and difficulty, stored in the `rhythm_charts` table. |
| **Database VACUUM** | Optimize and reclaim space from the SQLite database. | Executes `VACUUM` command with automatic retry on lock conflicts. |
| **Export/Import User Data** | Full account backup and restore of followings, notes, tasks, blogs, rhythm favorites, and playlists. | JSON format with schema versioning. |

---

## Requirements

| Prerequisite | Specification | Note / Verification |
| :--- | :--- | :--- |
| **PHP Environment** | PHP 7.4+ (PHP 8.x recommended) | Requires `pdo_sqlite`, `gd`, `fileinfo`, and `mbstring` extensions activated. |
| **Tag Parser** | [getID3 Library](https://github.com/JamesHeinrich/getID3) | Auto-downloaded on first run or manually extracted into `getid3/` in the project root. |
| **Storage** | Write Permissions | The web server must have write permissions for database creation, cache files, and user uploads. |

---

## How to Activate SQLite in XAMPP/LAMPP

If you are using **XAMPP** or **LAMPP** and encounter issues with SQLite, follow these instructions to enable it:

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

---

## Installation

1. **Clone the repository:**
    ```bash
    git clone https://github.com/HirotakaDango/PHP-Music.git
    cd PHP-Music
    ```

2. **Download getID3 (Optional if auto-download is enabled):**
    * [Download latest getID3](https://github.com/JamesHeinrich/getID3/releases)
    * Extract as a `getid3` folder inside the project root:
      ```text
      PHP-Music/
        index.php
        getid3/
          getid3.php
          ...
      ```

3. **Place music files:**
    * Put your music files in the root folder or any subfolder (except `uploads/`).
    * The player recursively scans for supported audio files.

4. **Set permissions:**
    * Ensure the project directory is writable so PHP can initialize `music.db` and cache directories.

5. **Run the server:**
    * Built-in server (for testing):
      ```bash
      php -S localhost:8000
      ```
    * Or configure Apache/Nginx as a standard PHP web application.

6. **Open in browser:**
    * Go to `http://localhost:8000`
    * The first registered user automatically becomes the **Super Administrator**.

---

## Usage Guide

### 1. General & Account Settings
* **Account Portability**: Change your email or reset credentials safely using the "Delete Account but Keep Data" button in Settings. You will receive a backup key to input on the "Restore Account" modal.
* **Navigation Sidebar**: The navigation hierarchy places dynamic directories like *Listen Later*, *Community*, *Personal Notes*, and *My Blogs* directly beneath the **Following** tab for quick transition.
* **Listen Later Bookmarking**: Click the three vertical dots `...` on any song and tap `Listen Later` to bookmark it. In your *Listen Later* library, you can drag and drop tracks to configure a customized listening queue.
* **Personal Notes Notebook**: Organize draft lyrics, artist logs, or notes in the *Personal Notes* tab. Notes are sandboxed privately to your account and can be sorted by *Newest*, *Oldest*, or *Recently Modified*.
* **Direct Messaging & Blocking**: Click the Message button on a user's profile to open a chat, or use the Inbox search to find users. You can send images, edit/delete messages, and view read receipts/active status.
* **Calendar & Clock**: Open the Calendar from the sidebar to check the current time, jump to specific dates via the date-picker, and reference days seamlessly while managing your music metadata.

### 2. Blogging Platform & Markdown Editor
* Access *My Blogs* from the sidebar to write articles and announcements.
* **Markdown Support:** Full GFM support (headings, lists, code blocks, tables, images, video embeds). Click the Markdown icon to toggle live split-preview mode.
* **Find & Replace:** Search and replace text across your draft with real-time match counters.
* **Auto-Save & Drafts:** First drafts automatically save as you type (`status = private`).
* **Multi-Format Export:** Export individual blogs to PDF (via `html2pdf.js`), HTML, Markdown (`.md`), or Plain Text (`.txt`).
* **Multi-Select & Bulk Actions:** Long-press or right-click blog cards to enter multi-select mode. Bulk-delete or download selected blogs as a `.zip` archive.

### 3. Rhythm Game Engine
* **Accessing the Game:** Access the **Rhythm Game** directly from the sidebar. The UI launches straight into the game hub with zero startup screens (Songs, Favorites, Ranks, Settings).
* **Beatmap Loading:** Tap **PLAY** on any track card in the list to open the launch setup dialog displaying the song's top 25 high scores (with green **FC** Full Combo badges on perfect runs). Select your difficulty (Easy, Medium, Hard, Expert, Master) and click Play.
* **Customization & Note Speed:** Under Settings, configure custom keyboard lane bindings (default: `D`, `F`, `J`, `K`), calibrate audio latency offset values, and tweak the Note Speed multiplier (up to `20x` tick speed).
* **Pause & Abort System:** Click the in-game **Pause** button to halt playback immediately with options to **Resume**, **Retry**, or **Quit to Menu**.

### 4. Advanced Image Editor (ImagEditor)
* **Workspace Setup:** Click **Image Editor** in the sidebar to load the multi-layered canvas workspace.
* **Layer Composition:** Drag, drop, or upload images directly to create **Image Layers**. Click **Text** to append editable text layers, or **Shape** to render vector rectangles or ellipses.
* **Properties Inspector:** Adjust opacity, corner-radius, layer order (bring forward/send back), orientations, or apply filters.
* **Brush & Drawing Tools:** Multiple brush engines (dip pen, felt tip, airbrush, calligraphy, neon, spray, eraser) with symmetry modes (vertical, horizontal, quad).
* **Exporting:** Download composite artwork as `.png`, `.jpg`, `.webp`, `.svg`, or save the project as a JSON file.

### 5. PHPAudio – Audio Editing Tool
* **Access:** Click **PHPAudio** from the sidebar to open the audio editor workspace.
* **Open Audio:** Drag and drop or browse an audio file (MP3, FLAC, WAV, M4A, OGG). The editor displays an interactive waveform and extracts ID3 metadata.
* **Trim & Amplify:** Use the sliders to set start/end trim points and adjust the gain multiplier.
* **Save to Library:** Overwrite existing library tracks or save as new files with updated ID3 tags and album covers.

### 6. PHPShares – Artwork & Manga Gallery
* **Access:** Explore illustrations, manga, and comics uploaded by the community via the **PHPShares** menu.
* **Browsing:** Filter by **All**, **Illustrations**, **Manga**, or **My Favorites**. Sort by newest, oldest, most viewed, or most favorited.
* **Manga Reader:** Flip through pages with keyboard arrows (←/→) or on-screen click targets, or jump to any chapter/episode using the episode selector modal.
* **Series Management:** Group related artworks into series collections with aggregated tag indexes and episode lists.

### 7. PHPMusic Drive & Built-In Image Studio (Admin Feature)

Access the administrative drive by navigating to `?access=admin&page=drive`.

* **4 Flexible Layout Modes:**
  * **Square Grid:** Uniform 1:1 square tiles with customizable column counts (1 to 8).
  * **Column Masonry:** Waterfall vertical flow adjusting naturally to item heights.
  * **Justified Row Masonry:** Dynamic-width photo tiles that preserve original aspect ratios at a fixed row height. Use the **Cols/Density slider** to scale the row height from compact (125px) to large (380px).
  * **List View:** Compact file table with metadata, timestamps, and permissions.
* **In-App Canvas Image Studio:**
  * Click **Edit Image** in any photo's context menu or Lightbox viewer to open the canvas studio.
  * **8-Point Crop:** Freeform or ratio-locked cropping (`1:1`, `16:9`, `4:3`, `9:16`) with a clean non-destructive overlay.
  * **Transformations:** Rotate Left/Right (90°) and Flip Horizontal/Vertical.
  * **Filter Tuning:** Sliders for Brightness, Contrast, and Saturation, plus instant Grayscale, Sepia, and Invert toggles.
  * **Freeform Draggable Text & Header Banners:** Type text, pick colors, adjust font sizes, and drag text freely to any canvas coordinate, or apply top/bottom header banners.
  * **Undo & Redo:** Full history navigation with `Ctrl + Z` and `Ctrl + Y`.
  * **Dual Save Engine:** Choose **Save (Overwrite)** with automated version snapshots, or **Save as Copy** (`filename_edited_(1).jpg`).
* **HDMarkDown & Code Workspace:** Edit code and markdown with live split-preview, CodeMirror syntax highlighting, and interactive Mermaid diagrams.
* **Office & Document Suite:** View PDF, Word (`.docx`), and Excel (`.xlsx`, `.xls`, `.csv`) files directly in-browser.
* **Archive Tools:** Inspect ZIP/TAR/RAR files without extracting, or create compressed `.zip` and `.tar.gz` packages on-the-fly.

### 8. Administrative Dashboard & Tools

Access the administrative dashboard at `?access=admin`. Sessions are persistent for up to 1 year.

| Admin Module | Functionality |
| :--- | :--- |
| **User Listing** | View registered users in a paginated table, searchable by ID, Email, or Artist name with role toggles. |
| **Verification & Moderation** | Approve or revoke upload rights, ban/unban accounts, and soft/permanent delete profiles. |
| **PHPMusic Drive** | Full-featured file workspace at `?access=admin&page=drive` with 4 layout modes, image studio, and document viewers. |
| **PHPEditor (IDE)** | Web IDE at `?access=admin&page=ide` featuring Ace Editor, multi-tab editing, terminal console, and version diffing. |
| **PHPDBManager** | SQLite database manager at `?access=admin&page=dbmanager` with table inspection, SQL query runner, and CSV/SQL export/import. |
| **Song & Artwork Management** | Bulk-edit metadata, transfer asset ownership, and execute batch moderation actions. |
| **Activity Audit Logs** | Comprehensive chronological logs of administrative actions. |
| **Profile Reports & Appeals** | Resolve community infraction reports and review ban appeals. |
| **API Keys Management** | Issue and throttle developer API keys (1,000 requests/month). |
| **Storage Stats Analytics** | Real-time disk allocation charts, asset breakdown, and per-user storage rankings. |

---

## Keyboard Shortcuts

| Shortcut | Action | Scope |
| :--- | :--- | :--- |
| `?` / `F1` | Open Keyboard Shortcuts Cheat Sheet | Global |
| `/` or `Ctrl + F` | Focus Search Bar | Library & File Manager |
| `Ctrl + F` | Find & Replace Card | Text / Code Editor |
| `Ctrl + A` | Select All Items | File Manager |
| `Ctrl + Shift + N` | Create New Folder | File Manager |
| `Ctrl + Shift + F` | Create New Text File | File Manager |
| `Ctrl + C` / `Ctrl + X` | Copy / Cut Selected Items | File Manager |
| `Ctrl + V` | Paste Items | File Manager |
| `F2` | Rename Selected Item | File Manager |
| `Delete` | Move Selected Items to Trash | File Manager |
| `Ctrl + S` | Save Active Document / Code | Code & Text Editor |
| `Ctrl + B` / `Ctrl + I` | Bold / Italic Syntax | Text Editor |
| `Ctrl + Z` / `Ctrl + Y` | Undo / Redo Changes | Code & Image Editor |
| `←` / `→` | Previous / Next Track or Media | Player, Lightbox, Manga Reader |
| `Space` | Play / Pause Audio or Media | Global Player & Lightbox |
| `Esc` | Close Active Modal, Lightbox, or Manga Viewer | Global |

---

## Troubleshooting

| Issue | Potential Cause | Solution |
| :--- | :--- | :--- |
| **Scan errors or empty library** | Missing getID3, wrong directory permissions, or missing `pdo_sqlite` driver. | Ensure `getid3/` folder is present, check directory write permissions, and uncomment sqlite extensions in `php.ini`. |
| **Upload errors / File too large** | Large audio/video files exceeding PHP limits, or unverified account. | Verify account has upload rights in Admin Panel. Increase `upload_max_filesize` and `post_max_size` in `php.ini`. |
| **Metadata or lyrics not saving** | Strict file permission constraints. | Grant write permissions to audio files so PHP can write ID3 tags back into physical files. |
| **Lyrics not syncing** | Timestamp formatting issues in LRC file. | Ensure timestamps are followed by a space (e.g., `[00:15.30] Lyric text`). |
| **Image Editor canvas not rendering** | High-resolution image memory constraints. | Increase `memory_limit` in `php.ini` (e.g., `512M`). |
| **PHPMusic Drive details modal behind viewer** | Browser cache holding outdated CSS z-index rules. | Hard-refresh the browser (`Ctrl + F5` or `Cmd + Shift + R`) to load updated modal layering. |
| **Chat messages not sending** | SSE background pooling blocked by active session write-lock. | Handled automatically via `session_write_close()`; verify server event stream connection in browser DevTools. |

---

## License

This project is open-source software provided under the [MIT License](LICENSE).