<?php
interface ExtractorInterface {
    public function extract($url);
}

class YtDlpExtractor implements ExtractorInterface {
    private $timeout;
    private $exePath;
    private $ffmpegPath;

    public function __construct($config) {
        $this->timeout = $config['REQUEST_TIMEOUT'];

        $baseDir = realpath(__DIR__ . '/..');

        // Locate yt-dlp
        foreach ([$baseDir . '/yt-dlp.exe', $baseDir . '/yt-dlp'] as $p) {
            if (file_exists($p)) { $this->exePath = $p; break; }
        }

        // Locate ffmpeg (in project root, bin/, or system PATH)
        foreach ([
            $baseDir . '/ffmpeg.exe', $baseDir . '/ffmpeg',
            $baseDir . '/bin/ffmpeg.exe', $baseDir . '/bin/ffmpeg',
        ] as $p) {
            if (file_exists($p)) { $this->ffmpegPath = $p; break; }
        }

        // Fallback: system PATH
        if (!$this->ffmpegPath) {
            $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $found = trim(shell_exec($isWin ? 'where ffmpeg 2>NUL' : 'which ffmpeg 2>/dev/null') ?? '');
            if ($found && file_exists(strtok($found, PHP_EOL))) {
                $this->ffmpegPath = strtok($found, PHP_EOL);
            }
        }
    }

    public function extract($url) {
        if (!function_exists('exec')) {
            throw new Exception("exec() is not available on this server.");
        }
        if (!$this->exePath || !file_exists($this->exePath)) {
            throw new Exception("yt-dlp.exe not found in project root.");
        }

        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        // Check for optional cookies.txt in project root for authenticated/bot-challenged videos
        $cookieFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cookies.txt';

        $cmd = '"' . $this->exePath . '"'
             . ' --dump-single-json'
             . ' --no-warnings'
             . ' --no-playlist'
             . ' --js-runtimes node';

        if (file_exists($cookieFile) && is_readable($cookieFile)) {
            $cmd .= ' --cookies ' . escapeshellarg($cookieFile);
        }

        if (!empty($this->config['PROXY'])) {
            $cmd .= ' --proxy ' . escapeshellarg($this->config['PROXY']);
        }

        $cmd .= ' ' . escapeshellarg($url) . ' 2>&1';

        // Windows: use proc_open for proper timeout
        if ($isWin) {
            $data = $this->execWithTimeout($cmd, $this->timeout);
        } else {
            $cmd = "timeout " . (int)$this->timeout . " " . $cmd;
            $data = $this->execWithTimeout($cmd, $this->timeout + 5);
        }

        return $this->normalize($data);
    }

    private function execWithTimeout(string $cmd, int $timeout): array {
        $output = [];
        $retVar = 0;
        exec($cmd, $output, $retVar);
        $raw = implode("\n", $output);

        // Find the JSON start
        $start = strpos($raw, '{');
        if ($start === false) {
            $errorMsg = trim($raw);

            // Friendly human-readable error messages for common yt-dlp failure cases
            if (stripos($errorMsg, "not a bot") !== false || stripos($errorMsg, "cookies-from-browser") !== false) {
                throw new Exception("YouTube requested bot verification for this video. You can place a 'cookies.txt' file in the project root to authenticate, or try another video.");
            }
            if (stripos($errorMsg, "Private video") !== false) {
                throw new Exception("This video is private and cannot be downloaded.");
            }
            if (stripos($errorMsg, "Video unavailable") !== false) {
                throw new Exception("This video is unavailable or has been removed.");
            }
            if (stripos($errorMsg, "Sign in to confirm your age") !== false) {
                throw new Exception("This video is age-restricted. Place a cookies.txt file in the project folder to download age-restricted videos.");
            }
            if (stripos($errorMsg, "copyright") !== false) {
                throw new Exception("This video is unavailable due to copyright restrictions.");
            }

            // Extract the cleanest error line
            $lines = explode("\n", $raw);
            $clean = '';
            foreach ($lines as $l) {
                $l = trim($l);
                if (str_starts_with($l, 'ERROR:')) {
                    $clean = preg_replace('/^ERROR:\s*(\[[^\]]+\]\s*)?/', '', $l);
                    break;
                }
            }

            throw new Exception($clean ?: ("yt-dlp extraction failed (Exit code {$retVar})."));
        }

        $json = substr($raw, $start);
        $data = json_decode($json, true);
        if (!$data || json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse video metadata: " . json_last_error_msg());
        }

        return $data;
    }

    private function normalize(array $raw): array {
        $formats   = [];
        $seen      = [];

        foreach ($raw['formats'] ?? [] as $f) {
            $id = $f['format_id'] ?? null;
            if (!$id || isset($seen[$id])) continue;
            $seen[$id] = true;

            // Must have a URL
            if (empty($f['url']) || strpos($f['url'], 'http') !== 0) continue;

            $vcodec = $f['vcodec'] ?? 'none';
            $acodec = $f['acodec'] ?? 'none';
            $ext    = $f['ext']    ?? 'unknown';
            $proto  = $f['protocol'] ?? 'https';
            $note   = strtolower($f['format_note'] ?? '');

            // Skip storyboards and image formats
            if ($ext === 'mhtml' || $ext === 'html') continue;
            if (strpos($note, 'storyboard') !== false) continue;
            if ($vcodec === 'none' && $acodec === 'none') continue;

            // Skip m3u8/HLS for YouTube (prefer HTTPS DASH streams — they're stable)
            // Keep m3u8 for sites that ONLY have m3u8 (Dailymotion, etc.)
            $isYouTube = isset($raw['extractor']) &&
                         stripos($raw['extractor'], 'youtube') !== false;
            if ($isYouTube && in_array($proto, ['m3u8', 'm3u8_native'])) continue;

            $w   = $f['width']  ?? null;
            $h   = $f['height'] ?? null;
            $res = ($w && $h) ? "{$w}x{$h}" : ($h ? "x{$h}" : 'audio only');

            $formats[] = [
                'format_id'        => $id,
                'ext'              => $ext,
                'protocol'         => $proto,
                'resolution'       => $res,
                'width'            => $w,
                'height'           => $h,
                'fps'              => isset($f['fps']) && $f['fps'] > 0 ? round($f['fps']) : null,
                'vcodec'           => $vcodec,
                'acodec'           => $acodec,
                'tbr'              => isset($f['tbr']) ? round($f['tbr']) : null,
                'abr'              => isset($f['abr']) ? round($f['abr']) : null,
                'vbr'              => isset($f['vbr']) ? round($f['vbr']) : null,
                'filesize'         => $f['filesize'] ?? $f['filesize_approx'] ?? null,
                'audio_lang'       => $f['language'] ?? null,
                'audio_track_name' => $f['audio_track']['name'] ?? null,
                'dynamic_range'    => $f['dynamic_range'] ?? null,
                'format_note'      => $f['format_note'] ?? '',
                'url'              => $f['url'],
            ];
        }

        // Sort formats: by height desc, then tbr desc
        usort($formats, function($a, $b) {
            $dh = ($b['height'] ?? 0) - ($a['height'] ?? 0);
            return $dh !== 0 ? $dh : (($b['tbr'] ?? 0) - ($a['tbr'] ?? 0));
        });

        // Extract Subtitles
        $subtitles = [];
        $seenLangs = [];

        // 1. Manual subtitles (human uploaded - high quality)
        foreach ($raw['subtitles'] ?? [] as $langCode => $subs) {
            if (empty($subs)) continue;
            $name = $subs[0]['name'] ?? $langCode;
            $seenLangs[$langCode] = true;
            $subtitles[] = [
                'lang'    => $langCode,
                'name'    => $name,
                'is_auto' => false,
            ];
        }

        // 2. Automatic captions
        foreach ($raw['automatic_captions'] ?? [] as $langCode => $subs) {
            if (empty($subs) || isset($seenLangs[$langCode])) continue;
            $name = $subs[0]['name'] ?? $langCode;
            $seenLangs[$langCode] = true;
            $subtitles[] = [
                'lang'    => $langCode,
                'name'    => $name,
                'is_auto' => true,
            ];
        }

        return [
            'platform'      => $raw['extractor']     ?? 'unknown',
            'extractor_key' => $raw['extractor_key'] ?? '',
            'type'          => 'video',
            'id'            => $raw['id']            ?? '',
            'title'         => $raw['title']         ?? 'Unknown Title',
            'thumbnail'     => $raw['thumbnail']     ?? '',
            'duration'      => $raw['duration']      ?? 0,
            'uploader'      => $raw['uploader']      ?? '',
            'view_count'    => $raw['view_count']    ?? null,
            'formats'       => $formats,
            'subtitles'     => $subtitles,
            'ffmpeg_available' => !empty($this->ffmpegPath),
        ];
    }

    public function hasFfmpeg(): bool {
        return !empty($this->ffmpegPath);
    }
}

class ExternalApiExtractor implements ExtractorInterface {
    public function __construct($config) {}

    public function extract($url) {
        return [
            'platform'         => 'MockAPI (Set EXTRACTION_MODE=YTDLP in config.php)',
            'extractor_key'    => 'MockAPI',
            'type'             => 'video',
            'id'               => 'mock_123',
            'title'            => 'Example Video (API Mode Active)',
            'thumbnail'        => 'https://via.placeholder.com/640x360.png?text=API+Mode',
            'duration'         => 120,
            'uploader'         => '',
            'view_count'       => null,
            'ffmpeg_available' => false,
            'subtitles'        => [],
            'formats'          => [
                [
                    'format_id'        => '137',
                    'ext'              => 'mp4',
                    'protocol'         => 'https',
                    'resolution'       => '1920x1080',
                    'width'            => 1920,
                    'height'           => 1080,
                    'fps'              => 30,
                    'vcodec'           => 'avc1',
                    'acodec'           => 'none',
                    'tbr'              => 3038,
                    'abr'              => null,
                    'vbr'              => 3038,
                    'filesize'         => 80000000,
                    'audio_lang'       => null,
                    'audio_track_name' => null,
                    'dynamic_range'    => 'SDR',
                    'format_note'      => '1080p',
                    'url'              => '',
                ],
                [
                    'format_id'        => '140',
                    'ext'              => 'm4a',
                    'protocol'         => 'https',
                    'resolution'       => 'audio only',
                    'width'            => null,
                    'height'           => null,
                    'fps'              => null,
                    'vcodec'           => 'none',
                    'acodec'           => 'mp4a.40.2',
                    'tbr'              => 130,
                    'abr'              => 130,
                    'vbr'              => null,
                    'filesize'         => 3400000,
                    'audio_lang'       => 'en',
                    'audio_track_name' => 'English',
                    'dynamic_range'    => null,
                    'format_note'      => '130k',
                    'url'              => '',
                ],
            ],
        ];
    }
}
