# Universal Video Downloader - Fast & Free

A lightweight, high-performance, mobile-friendly video downloader optimized for free PHP hosting providers (like InfinityFree, 000webhost, etc.). 

## Features

- **No Server Overload**: Extracts direct download links so users download straight from the source (e.g., YouTube's CDN), consuming zero server bandwidth.
- **No Database**: Uses lightweight file-based caching and rate-limiting.
- **Frontend**: Pure Vanilla HTML/CSS/JS. No Node.js, Webpack, or React required.
- **Modern UI**: Dark-mode, glassmorphism interface that is highly responsive.
- **Two Extraction Modes**: 
  - `API Mode` (Default): For strict free hosting that disables `shell_exec()`.
  - `YTDLP Mode`: For VPS or flexible hosts where `yt-dlp` can be installed.

## Deployment Instructions

1. **Upload Files**: Upload the entire contents of this directory to your `public_html` or `htdocs` folder using cPanel File Manager or FTP.
2. **Permissions**: Ensure the PHP user has write permissions (`chmod 755` or `777` depending on the host) for the following directories so they can be created automatically:
   - `cache/meta/`
   - `cache/ratelimit/`
   - `logs/`
3. **Configuration**: Open `config.php` and configure:
   - `EXTRACTION_MODE`: Use `'API'` if your free host blocks `exec()`. If you have a VPS with `yt-dlp` installed, use `'YTDLP'`.
   - If using API mode, register for a free tier API on RapidAPI (e.g., a YouTube downloader API) and input your `EXTERNAL_API_KEY`.
   - You can adjust rate limits, cache TTL, and more.
4. **Test**:
   - Go to your website URL.
   - Paste a supported link (e.g., a YouTube video).
   - Verify that the title, thumbnail, and download options appear.

## Hosting Compatibility Checklist

To run this perfectly, your host needs:
- **PHP 7.4+** or **PHP 8.x**
- **cURL Extension** (usually enabled)
- **JSON Extension** (usually enabled)
- Write permissions in the app directory for the cache and logs.

### A note on `exec()` / `shell_exec()`
Most free hosting providers block these functions for security. If this is the case, `YTDLP` mode will not work and you *must* use `API` mode with an external extraction service. The script handles this gracefully and will return 500 errors if you try to use YTDLP mode on a host that blocks it.
