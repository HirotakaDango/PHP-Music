# Contributing to PHP-Music

Thank you for your interest in contributing to **PHP-Music**! Contributions from the community help keep this project maintained and improving.

## Getting Started

1. **Fork** this repository.
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/PHP-Music.git
   cd PHP-Music
   ```
3. Set up a local PHP development server:
   ```bash
   php -S localhost:8000
   ```
4. Open `http://localhost:8000` in your browser.

## Requirements

- **PHP 7.4+** or **PHP 8.x**
- Enabled PHP extensions:
  - `pdo_sqlite` (for database management)
  - `gd` or `imagick` (for album artwork & canvas image studio)
  - `mbstring` (for character encoding)
  - `fileinfo` (for MIME-type detection)
- `getID3` library (bundled in `/getid3`)

## How You Can Contribute

We welcome contributions in several areas:

- **Audio Engine & Playback**: Support for additional audio formats (FLAC, Opus, AAC), gapless playback, playlist queue enhancements.
- **Metadata & ID3 Tags**: Enhancing tag parsing accuracy, lyric synchronization (.lrc format parsing), batch metadata tagging.
- **Admin & Tools**: Improvements to PHP-Music Drive, SQLite DB Manager, activity audit logs, and developer API endpoints.
- **UI & UX**: Responsive layout refinements for mobile/tablet, theme improvements, accessibility (a11y) fixes, keyboard navigation.
- **Performance & Security**: Optimizing SQLite query indexing, memory handling during large audio scans, input sanitization and upload security checks.
- **Documentation**: Clarifying setup steps, configuration guides, and troubleshooting instructions.

## Development Guidelines

1. **Code Style**: Keep code clean, readable, and properly indented. Follow standard PHP formatting practices (PSR-12 where applicable).
2. **Backward Compatibility**: Ensure core features continue to run without mandatory external framework dependencies.
3. **Security**: Validate and sanitize all user input before interacting with SQLite or the file system. Never trust user-uploaded filenames directly.
4. **Testing**: Test changes across different audio formats, browser window sizes, and admin modules before submitting.

## Pull Request Process

1. Create a descriptive feature branch:
   ```bash
   git checkout -b feat/your-feature-name
   # or
   git checkout -b fix/issue-description
   ```
2. Commit your changes with clear, concise messages:
   - `feat: add playlist shuffle and repeat modes`
   - `fix: handle missing ID3 album art gracefully`
   - `docs: update php.ini memory configuration tips`
3. Push to your fork and open a Pull Request against the `main` branch of `HirotakaDango/PHP-Music`.
4. Describe your changes and mention any testing done.

---

Thank you for contributing to PHP-Music! 🎵
