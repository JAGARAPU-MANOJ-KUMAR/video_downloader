<?php
/**
 * download.php — Server-side download proxy
 *
 * 1. Handles Video, Audio, and Subtitle downloads
 * 2. Runs yt-dlp safely into an isolated temp dir
 * 3. Supports MP3/M4A audio conversion via ffmpeg
 * 4. Fixes Windows escapeshellarg %(ext)s bug
 * 5. Streams the file to browser with correct filename & headers
 * 6. Cleans up temp directory on shutdown
 */

set_time_limit(0);
ignore_user_abort(false);
ini_set('memory_limit', '512M');

$config  = require __DIR__ . '/config.php';
require __DIR__ . '/lib/validator.php';

header('Access-Control-Allow-Origin: *');

// ── Inputs ─────────────────────────────────────────────────────────
$url      = $_GET['url']       ?? '';
$type     = $_GET['type']      ?? 'media'; // 'media', 'audio', or 'subtitle'
$formatId = $_GET['format_id'] ?? 'bestvideo+bestaudio/best';
$title    = $_GET['title']     ?? 'video';
$ext      = $_GET['ext']       ?? 'mp4';
$subLang  = $_GET['sub_lang']  ?? '';
$token    = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['token'] ?? '');

// Sanitize filename title (strip chars illegal on Windows/Linux)
$safeTitle = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '', $title);
$safeTitle = trim(preg_replace('/\s+/', ' ', $safeTitle));
$safeTitle = substr($safeTitle ?: 'video', 0, 100);

$safeExt = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext)) ?: 'mp4';

// ── Validate URL ───────────────────────────────────────────────────
$validUrl = Validator::sanitizeUrl($url);
if (!$validUrl) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Error: Invalid or unsupported URL.');
}

// ── Locate executables ────────────────────────────────────────────
$baseDir    = __DIR__;
$exePath    = findExe($baseDir, 'yt-dlp');
$ffmpegPath = findExe($baseDir, 'ffmpeg');
$cookieFile = $baseDir . DIRECTORY_SEPARATOR . 'cookies.txt';

if (!$exePath) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Error: yt-dlp.exe not found in project root.');
}

// ── Create unique temp directory ──────────────────────────────────
$tmpBase = $baseDir . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmpBase)) {
    mkdir($tmpBase, 0755, true);
}
$sessionId = 'dl_' . uniqid('', true) . '_' . getmypid();
$tmpDir    = $tmpBase . DIRECTORY_SEPARATOR . $sessionId;
if (!mkdir($tmpDir, 0755)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Error: Cannot create temp directory.');
}

register_shutdown_function('cleanupDir', $tmpDir);

$logDir = $config['PATHS']['logs'] ?? $baseDir . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$stderrFile = $tmpDir . DIRECTORY_SEPARATOR . 'stderr.txt';
$isWin      = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// ── Mode A: Subtitle Download ──────────────────────────────────────
$isSubtitle = ($type === 'subtitle') || !empty($subLang);

if ($isSubtitle) {
    $cleanLang = preg_replace('/[^a-zA-Z0-9\-_]/', '', $subLang) ?: 'en';
    $subExt    = in_array($safeExt, ['vtt', 'srt']) ? $safeExt : 'srt';

    $logLine = date('Y-m-d H:i:s') . " | DL_SUB | lang={$cleanLang} | ext={$subExt} | url={$validUrl}\n";
    @file_put_contents($logDir . '/download.log', $logLine, FILE_APPEND | LOCK_EX);

    // Note: Do NOT use escapeshellarg on %(ext)s on Windows
    $outputTemplate = $tmpDir . DIRECTORY_SEPARATOR . 'sub.%(ext)s';

    $cmd  = '"' . $exePath . '"';
    $cmd .= ' --no-warnings';
    $cmd .= ' --no-playlist';
    $cmd .= ' --no-check-formats';
    $cmd .= ' --skip-download';
    $cmd .= ' --js-runtimes node';
    $cmd .= ' --write-sub';
    $cmd .= ' --write-auto-sub';
    $cmd .= ' --sub-lang ' . escapeshellarg($cleanLang);
    $cmd .= ' --convert-subs ' . escapeshellarg($subExt);
    $cmd .= ' -o "' . $outputTemplate . '"';
    if (file_exists($cookieFile) && is_readable($cookieFile)) {
        $cmd .= ' --cookies ' . escapeshellarg($cookieFile);
    }
    if ($ffmpegPath) {
        $cmd .= ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpegPath));
    }
    $cmd .= ' ' . escapeshellarg($validUrl);

    runCmd($cmd, $stderrFile, $isWin);

    // Locate the subtitle file
    $subFiles = glob($tmpDir . '/*.' . $subExt);
    if (empty($subFiles)) {
        // Fallback to any srt or vtt
        $subFiles = glob($tmpDir . '/*.{srt,vtt}', GLOB_BRACE) ?: [];
    }

    if (empty($subFiles) || !filesize($subFiles[0])) {
        http_response_code(404);
        header('Content-Type: text/plain');
        exit('Error: Subtitle could not be downloaded for this video.');
    }

    $downloadedFile = $subFiles[0];
    $actualExt      = strtolower(pathinfo($downloadedFile, PATHINFO_EXTENSION)) ?: $subExt;
    $mimeType       = ($actualExt === 'vtt') ? 'text/vtt; charset=utf-8' : 'application/x-subrip; charset=utf-8';
    $finalName      = $safeTitle . '_' . $cleanLang . '.' . $actualExt;

    streamFile($downloadedFile, $finalName, $mimeType, $token);
    exit;
}

// ── Mode B: Video / Audio Download ─────────────────────────────────

// Validate format ID
if (!preg_match('/^[\w.\+\-\/\[\] ,]+$/', $formatId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Error: Invalid format_id parameter.');
}

$isAudio  = ($type === 'audio') || in_array($safeExt, ['mp3', 'm4a', 'opus', 'ogg', 'aac', 'wav', 'flac']);
$isMerged = strpos($formatId, '+') !== false;

$logLine = date('Y-m-d H:i:s') . " | DL | type=" . ($isAudio ? 'audio' : 'video') . " | fmt={$formatId} | url={$validUrl} | file={$safeTitle}.{$safeExt}\n";
@file_put_contents($logDir . '/download.log', $logLine, FILE_APPEND | LOCK_EX);

// Note: Do NOT use escapeshellarg on %(ext)s on Windows because it strips % to space
$outputTemplate = $tmpDir . DIRECTORY_SEPARATOR . 'output.%(ext)s';

$cmd  = '"' . $exePath . '"';
$cmd .= ' --no-warnings';
$cmd .= ' --no-playlist';
$cmd .= ' --no-part';
$cmd .= ' --no-check-formats';
$cmd .= ' -N 8'; // 8x parallel fragment downloads for maximum throughput
$cmd .= ' --buffer-size 64K';
$cmd .= ' --js-runtimes node';
$cmd .= ' --retries 5';
$cmd .= ' --fragment-retries 5';
$cmd .= ' -f ' . escapeshellarg($formatId);
$cmd .= ' -o "' . $outputTemplate . '"';

if (file_exists($cookieFile) && is_readable($cookieFile)) {
    $cmd .= ' --cookies ' . escapeshellarg($cookieFile);
}
if (!empty($config['PROXY'])) {
    $cmd .= ' --proxy ' . escapeshellarg($config['PROXY']);
}

if ($isAudio && $ffmpegPath) {
    // If MP3 requested, convert to high quality MP3
    if ($safeExt === 'mp3') {
        $cmd .= ' -x --audio-format mp3 --audio-quality 0';
    } elseif ($safeExt === 'm4a') {
        $cmd .= ' -x --audio-format m4a';
    } elseif ($safeExt === 'wav') {
        $cmd .= ' -x --audio-format wav';
    }
    $cmd .= ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpegPath));
} elseif (!$isAudio && $ffmpegPath) {
    $cmd .= ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpegPath));
    if ($isMerged) {
        $cmd .= ' --merge-output-format mp4';
    }
}

$cmd .= ' ' . escapeshellarg($validUrl);

runCmd($cmd, $stderrFile, $isWin);

$downloadedFile = findDownloadedFile($tmpDir, $isAudio);

if (!$downloadedFile || !filesize($downloadedFile)) {
    $stderrContent = file_exists($stderrFile) ? file_get_contents($stderrFile) : 'no stderr captured';
    @file_put_contents($logDir . '/error.log',
        date('Y-m-d H:i:s') . " | DL_FAIL | fmt={$formatId} | url={$validUrl} | stderr=" . substr($stderrContent, 0, 1000) . "\n",
        FILE_APPEND | LOCK_EX
    );

    http_response_code(500);
    header('Content-Type: text/plain');
    $reason = (!$ffmpegPath && $isMerged)
        ? 'ffmpeg.exe is missing — required for merging video+audio.'
        : 'Download failed. Check logs/error.log for details.';
    exit('Error: ' . $reason);
}

$actualExt = strtolower(pathinfo($downloadedFile, PATHINFO_EXTENSION));

// Extra safety: sanitize extension if it contains illegal characters or literal 'ext'
if (empty($actualExt) || strpos($actualExt, 'ext') !== false || strlen($actualExt) > 5) {
    $actualExt = $isAudio ? ($safeExt ?: 'm4a') : ($safeExt ?: 'mp4');
}

$mimeType  = getMime($actualExt);
$finalName = $safeTitle . '.' . $actualExt;

streamFile($downloadedFile, $finalName, $mimeType, $token);

// ── Helpers ────────────────────────────────────────────────────────

function runCmd(string $cmd, string $stderrFile, bool $isWin): void {
    $fullCmd = $cmd . ' 2>' . escapeshellarg($stderrFile);
    if ($isWin) {
        $proc = popen($fullCmd, 'r');
        if ($proc) {
            while (!feof($proc)) { fread($proc, 8192); }
            pclose($proc);
        }
    } else {
        passthru($fullCmd);
    }
}

function streamFile(string $filePath, string $filename, string $mimeType, string $token = ''): void {
    $fileSize = filesize($filePath);
    while (ob_get_level() > 0) { ob_end_clean(); }

    if (!empty($token)) {
        header('Set-Cookie: vidloader_dl_' . $token . '=done; Path=/; SameSite=Lax; Max-Age=120');
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\n", "\r"], '', $filename) . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Accept-Ranges: none');
    header('X-Accel-Buffering: no');

    $fp = fopen($filePath, 'rb');
    if ($fp) {
        while (!feof($fp) && !connection_aborted()) {
            echo fread($fp, 1048576); // 1MB chunks
            flush();
        }
        fclose($fp);
    }
}

function findExe(string $baseDir, string $name): ?string {
    $paths = [
        $baseDir . '/' . $name . '.exe',
        $baseDir . '/' . $name,
        $baseDir . '/bin/' . $name . '.exe',
        $baseDir . '/bin/' . $name,
    ];
    foreach ($paths as $p) {
        if (file_exists($p) && is_file($p)) return realpath($p);
    }
    $isWin  = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $result = trim(shell_exec($isWin ? "where {$name} 2>NUL" : "which {$name} 2>/dev/null") ?? '');
    $first  = strtok($result, "\n\r");
    if ($first && file_exists($first)) return $first;
    return null;
}

function findDownloadedFile(string $dir, bool $isAudio = false): ?string {
    if (!is_dir($dir)) return null;
    $files = glob($dir . '/*');
    if (!$files) return null;

    $videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'ts'];
    $audioExts = ['mp3', 'm4a', 'opus', 'ogg', 'aac', 'wav', 'flac'];

    $videos = $audios = $others = [];
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        if (basename($f) === 'stderr.txt') continue;

        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($e, $audioExts))      $audios[] = $f;
        elseif (in_array($e, $videoExts))  $videos[] = $f;
        else                               $others[] = $f;
    }

    $sorter = fn($a, $b) => filesize($b) - filesize($a);

    if ($isAudio) {
        if ($audios) { usort($audios, $sorter); return $audios[0]; }
        if ($videos) { usort($videos, $sorter); return $videos[0]; }
    } else {
        if ($videos) { usort($videos, $sorter); return $videos[0]; }
        if ($audios) { usort($audios, $sorter); return $audios[0]; }
    }

    if ($others) {
        usort($others, $sorter);
        return $others[0];
    }
    return null;
}

function getMime(string $ext): string {
    return match ($ext) {
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'flv'  => 'video/x-flv',
        'ts'   => 'video/mp2t',
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'opus' => 'audio/opus',
        'ogg'  => 'audio/ogg',
        'aac'  => 'audio/aac',
        'wav'  => 'audio/wav',
        'flac' => 'audio/flac',
        'srt'  => 'application/x-subrip; charset=utf-8',
        'vtt'  => 'text/vtt; charset=utf-8',
        default => 'application/octet-stream',
    };
}

function cleanupDir(string $dir): void {
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*') ?: [];
    foreach ($files as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}
