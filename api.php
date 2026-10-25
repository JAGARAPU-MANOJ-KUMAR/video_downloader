<?php
// API Entry Point
$config = require 'config.php';

require 'lib/response.php';
require 'lib/validator.php';
require 'lib/rate_limit.php';
require 'lib/cache.php';
require 'lib/extractor.php';

// Allow OPTIONS for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('METHOD_NOT_ALLOWED', 'Only POST method is allowed.', 405);
}

$jsonBody = file_get_contents('php://input');
$request  = json_decode($jsonBody, true);

if (!$request || !isset($request['action'])) {
    Response::error('BAD_REQUEST', 'Invalid JSON payload or missing action.', 400);
}

// Rate Limiting
if ($config['RATE_LIMIT_ENABLED']) {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limiter = new RateLimiter($config);
    if (!$limiter->check($ip)) {
        Response::error('RATE_LIMITED', 'Too many requests. Please try again later.', 429);
    }
}

if ($request['action'] === 'analyze') {
    $url      = $request['url'] ?? '';
    $validUrl = Validator::sanitizeUrl($url);

    if (!$validUrl) {
        Response::error('INVALID_URL', 'The provided URL is invalid or unsupported.');
    }

    $cache    = new Cache($config);
    $cacheKey = 'extract_v4_' . md5($validUrl);

    $cachedData = $cache->get($cacheKey);
    if ($cachedData) {
        Response::json(true, $cachedData);
    }

    try {
        if ($config['EXTRACTION_MODE'] === 'YTDLP') {
            $extractor = new YtDlpExtractor($config);
        } else {
            $extractor = new ExternalApiExtractor($config);
        }

        $data = $extractor->extract($validUrl);
        $data = buildDownloadOptions($data, $validUrl);

        $cache->set($cacheKey, $data);
        Response::json(true, $data);

    } catch (Exception $e) {
        if (!is_dir($config['PATHS']['logs'])) {
            @mkdir($config['PATHS']['logs'], 0755, true);
        }
        $logMsg = date('Y-m-d H:i:s') . " | ANALYZE | URL=$validUrl | ERROR=" . $e->getMessage() . PHP_EOL;
        @file_put_contents($config['PATHS']['logs'] . '/error.log', $logMsg, FILE_APPEND | LOCK_EX);

        Response::error('EXTRACTION_FAILED', 'Unable to retrieve video information: ' . $e->getMessage(), 500);
    }
} else {
    Response::error('UNKNOWN_ACTION', 'The requested action is not recognized.');
}

// ══════════════════════════════════════════════════════════════════════
// Build Smart Download Options
// ══════════════════════════════════════════════════════════════════════
/**
 * Builds `video_options` and `audio_options` from raw yt-dlp formats.
 *
 * YouTube ONLY delivers DASH streams (video-only + audio-only separately).
 * We pair each video-only stream with the best compatible audio stream.
 *
 * The `download_format_id` field is what gets sent to yt-dlp -f.
 * Examples:
 *   - "137+140"   → video 1080p + audio 130k (ffmpeg required for merge)
 *   - "22"        → combined 720p (rare, no ffmpeg needed)
 *   - "140"       → audio only 130k
 */
function buildDownloadOptions(array $data, string $originalUrl): array {
    $formats       = $data['formats'];
    $ffmpegAvail   = $data['ffmpeg_available'] ?? false;

    $combined  = []; // video + audio in one stream
    $videoOnly = []; // video stream, no audio (DASH)
    $audioOnly = []; // audio stream only (DASH)

    foreach ($formats as $f) {
        $hasV = ($f['vcodec'] !== 'none');
        $hasA = ($f['acodec'] !== 'none');
        if ($hasV && $hasA)      $combined[]  = $f;
        elseif ($hasV && !$hasA) $videoOnly[] = $f;
        elseif (!$hasV && $hasA) $audioOnly[] = $f;
    }

    // ── Step 1: Build audio pool grouped by language ─────────────────
    // For each language, find best audio streams (by codec priority then bitrate)
    $audioByLang = [];
    foreach ($audioOnly as $a) {
        $lang = $a['audio_lang'] ?? 'default';
        if (!isset($audioByLang[$lang])) {
            $audioByLang[$lang] = [];
        }
        $audioByLang[$lang][] = $a;
    }

    // Sort each language group by bitrate desc
    foreach ($audioByLang as &$group) {
        usort($group, fn($a, $b) => ($b['abr'] ?? 0) - ($a['abr'] ?? 0));
    }
    unset($group);

    // Pick best audio fallback (prefer 'original', 'en', then first)
    $bestAudio = null;
    if (isset($audioByLang['original'])) {
        $bestAudio = $audioByLang['original'][0];
    } elseif (isset($audioByLang['en'])) {
        $bestAudio = $audioByLang['en'][0];
    } elseif (!empty($audioByLang)) {
        $first = reset($audioByLang);
        $bestAudio = $first[0];
    }

    // ── Step 2: Build video download options ─────────────────────────
    $videoOptions  = [];
    $seenHeights   = []; // track which heights we've already added

    // First: add combined streams (rare on modern YouTube but exist on other sites)
    foreach ($combined as $f) {
        $h   = $f['height'] ?? 0;
        $key = $h . '_combined';
        if (!isset($seenHeights[$key]) || ($f['tbr'] ?? 0) > ($seenHeights[$key]['tbr'] ?? 0)) {
            $f['download_format_id'] = $f['format_id'];
            $f['needs_ffmpeg']       = false;
            $f['audio_info']         = 'Combined (has audio)';
            $seenHeights[$key] = $f;
        }
    }

    // Second: add DASH video-only streams paired with audio
    foreach ($videoOnly as $f) {
        $h   = $f['height'] ?? 0;
        $ext = $f['ext']    ?? 'mp4';

        // Skip this height if we already have a combined stream for it
        // (unless the DASH quality is significantly higher)
        if (isset($seenHeights[$h . '_combined'])) {
            continue; // combined always preferred for same height
        }

        $key = $h . '_dash';
        if (isset($seenHeights[$key])) {
            // Already have this height — keep highest bitrate
            if (($f['tbr'] ?? 0) <= ($seenHeights[$key]['tbr'] ?? 0)) continue;
        }

        // Pick best matching audio for this video
        // Prefer m4a for mp4/m4v videos, webm/opus for webm
        $audioTrack = null;
        if ($bestAudio) {
            // For webm video, prefer opus audio if available
            if (in_array($ext, ['webm']) && isset($audioByLang['default'])) {
                foreach ($audioByLang['default'] as $a) {
                    if (in_array($a['ext'], ['webm']) || strpos($a['acodec'], 'opus') !== false) {
                        $audioTrack = $a;
                        break;
                    }
                }
            }
            // Fallback to best audio
            if (!$audioTrack) $audioTrack = $bestAudio;
        }

        $clone = $f;

        if ($audioTrack && $ffmpegAvail) {
            $clone['download_format_id'] = $f['format_id'] . '+' . $audioTrack['format_id'];
            $clone['needs_ffmpeg']       = true;
            $clone['audio_info']         = ($audioTrack['audio_track_name'] ?? $audioTrack['audio_lang'] ?? 'Default') . ' ' . ($audioTrack['abr'] ?? '') . 'kbps';
            // For the final ext, merged DASH video + audio → mp4
            $clone['ext'] = 'mp4';
        } elseif ($audioTrack && !$ffmpegAvail) {
            // No ffmpeg: we can still pass "video+audio" but yt-dlp will download both separately
            // Better to just offer video-only and warn
            $clone['download_format_id'] = $f['format_id'] . '+' . $audioTrack['format_id'];
            $clone['needs_ffmpeg']       = true;
            $clone['audio_info']         = 'Requires ffmpeg (not installed)';
            $clone['ext'] = 'mp4';
        } else {
            // No audio available at all — serve video only
            $clone['download_format_id'] = $f['format_id'];
            $clone['needs_ffmpeg']       = false;
            $clone['audio_info']         = 'No audio';
        }

        $seenHeights[$key] = $clone;
    }

    // Collect and sort by height desc
    $videoOptions = array_values($seenHeights);
    usort($videoOptions, function($a, $b) {
        return ($b['height'] ?? 0) - ($a['height'] ?? 0);
    });

    // ── Step 3: Build audio download options ─────────────────────────
    $audioOptions  = [];
    $seenAudioKeys = [];

    foreach ($audioOnly as $a) {
        $lang  = $a['audio_lang']  ?? 'default';
        $abr   = $a['abr']         ?? $a['tbr'] ?? 0;
        $codec = $a['acodec']      ?? 'unknown';
        $ext   = $a['ext']         ?? 'm4a';

        // Deduplicate: same language + similar bitrate + same codec
        $bitrBucket = round($abr / 10) * 10; // bucket to nearest 10kbps
        $key = $lang . '_' . $codec . '_' . $bitrBucket;

        if (isset($seenAudioKeys[$key])) continue;
        $seenAudioKeys[$key] = true;

        $a['download_format_id'] = $a['format_id'];
        $a['needs_ffmpeg']       = false;
        $audioOptions[] = $a;
    }

    // Sort by bitrate desc
    usort($audioOptions, fn($a, $b) => ($b['abr'] ?? 0) - ($a['abr'] ?? 0));

    $data['video_options']   = $videoOptions;
    $data['audio_options']   = $audioOptions;
    $data['source_url']      = $originalUrl;
    $data['ffmpeg_available'] = $ffmpegAvail;

    return $data;
}
