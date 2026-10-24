# VidLoader - High-Speed Universal Video & Audio Downloader

A lightweight, modern web application for downloading videos, audio tracks, and subtitles in pristine quality from YouTube and 1000+ supported sites.

![VidLoader Interface](assets/screenshot.png) <!-- optional preview -->

---

## ✨ Features

- **True 4K / 2K / 1080p HD Downloads**: Downloads separate video and audio streams and automatically merges them using `ffmpeg` without quality loss.
- **5x Parallel Multi-Threaded Engine**: Utilizes multi-threaded concurrent fragment fetching (`-N 5`) for ultra-fast downloads.
- **In-Page Download Progress Modal**: Live progress bar, real-time seconds elapsed timer (`⏱️`), dynamic status steps, and automatic completion notification.
- **Multi-Track Audio Extraction**: Supports videos with multiple language audio tracks (English, Hindi, Dubbed, Original) with individual language tagging and download.
- **Subtitles & Closed Captions**: Download subtitles and auto-generated captions in `.srt` or `.vtt` formats.
- **Responsive Dark Mode UI**: Built with modern CSS glassmorphism, fluid animations, mobile-friendly layouts, and zero heavy frameworks.
- **File-Based Caching & Rate Limiting**: Built-in caching for metadata and rate-limiting to protect against abuse.

---

## 🛠️ Requirements & Setup

### 1. Requirements
- **PHP 8.0+** with `curl`, `json`, and `mbstring` extensions enabled.
- **Web Server**: Apache / Nginx / XAMPP / PHP Built-in server.
- **Binaries**:
  - `yt-dlp.exe` (included in repository)
  - `ffmpeg.exe` and `ffprobe.exe` (Required for HD merging — see download instructions below)

### 2. FFmpeg Setup (Required for 1080p+ Video & Merging)
Because `ffmpeg.exe` and `ffprobe.exe` exceed GitHub's 100 MB upload limit, download the official Windows build:
1. Download FFmpeg Essentials build from [gyan.dev](https://www.gyan.dev/ffmpeg/builds/) or [ffmpeg.org](https://ffmpeg.org/download.html).
2. Extract and place `ffmpeg.exe` and `ffprobe.exe` directly in the project root directory.

### 3. Local Development (PHP Built-in Server)
You can start the project instantly using PHP's built-in server:
```bash
php -S localhost:8000
```
Then open `http://localhost:8000` in your web browser.

---

## 📁 Project Structure

```text
├── assets/
│   ├── script.js        # Frontend interactions, async API calls & modal management
│   └── style.css        # Responsive styling, animations & theme variables
├── lib/
│   ├── cache.php        # File-based caching system
│   ├── extractor.php    # yt-dlp wrapper & format resolution
│   ├── rate_limit.php   # IP-based rate limiter
│   ├── response.php     # Standardized JSON response helpers
│   └── validator.php    # URL and parameter validation
├── api.php              # REST endpoint for video metadata analysis
├── download.php         # Streaming handler with 5x parallel fragment acceleration
├── config.php           # App settings, paths, limits, and timeouts
├── index.html           # Main user interface
└── yt-dlp.exe           # CLI video extractor
```

---

## 🔒 Security & Best Practices

- Includes `.htaccess` protection preventing direct web access to `tmp/`, `cache/`, and `logs/`.
- Safe command execution avoiding shell injection vulnerabilities.
- For personal use only. Always respect content copyright and platform terms of service.

---

## 📄 License
MIT License. Powered by [yt-dlp](https://github.com/yt-dlp/yt-dlp) and [FFmpeg](https://ffmpeg.org).
