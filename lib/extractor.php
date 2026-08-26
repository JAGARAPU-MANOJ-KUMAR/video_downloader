<?php
interface ExtractorInterface {
    public function extract($url);
}

class YtDlpExtractor implements ExtractorInterface {
    private $timeout;

    public function __construct($config) {
        $this->timeout = $config['REQUEST_TIMEOUT'];
    }

    public function extract($url) {
        if (!function_exists('exec')) {
            throw new Exception("exec() is not available on this server.", 500);
        }

        $exePath = realpath(__DIR__ . '/../yt-dlp.exe');
        $cmd = '"' . $exePath . '" --dump-json --no-warnings --no-playlist ' . escapeshellarg($url) . ' 2>&1';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $cmd = "timeout " . escapeshellarg($this->timeout) . " " . $cmd;
        }

        $output = [];
        $return_var = 0;
        exec($cmd, $output, $return_var);

        if ($return_var !== 0 || empty($output)) {
            $errorMsg = implode("\n", $output);
            throw new Exception("Extraction failed (Code: $return_var). Details: $errorMsg");
        }

        $json = implode("", $output);
        $data = json_decode($json, true);

        if (!$data) {
            throw new Exception("Failed to parse extractor output.");
        }

        return $this->normalize($data);
    }

    private function normalize($raw) {
        $formats = [];
        foreach ($raw['formats'] ?? [] as $f) {
            if (isset($f['url']) && strpos($f['url'], 'http') === 0) {
                // Filter out storyboards and mhtml
                $vcodec = $f['vcodec'] ?? 'none';
                $acodec = $f['acodec'] ?? 'none';
                $ext = $f['ext'] ?? 'unknown';
                $note = $f['format_note'] ?? '';

                if ($vcodec === 'none' && $acodec === 'none') continue;
                if ($ext === 'mhtml' || $ext === 'html') continue;
                if (strpos(strtolower($note), 'storyboard') !== false) continue;

                $formats[] = [
                    'format_id' => $f['format_id'] ?? 'unknown',
                    'ext' => $ext,
                    'resolution' => $f['resolution'] ?? (isset($f['width']) ? $f['width'].'x'.$f['height'] : 'audio only'),
                    'height' => $f['height'] ?? null,
                    'vcodec' => $vcodec,
                    'acodec' => $acodec,
                    'filesize' => $f['filesize'] ?? null,
                    'url' => $f['url']
                ];
            }
        }

        return [
            'platform' => $raw['extractor'] ?? 'unknown',
            'type' => 'video',
            'id' => $raw['id'] ?? '',
            'title' => $raw['title'] ?? 'Unknown Title',
            'thumbnail' => $raw['thumbnail'] ?? '',
            'duration' => $raw['duration'] ?? 0,
            'formats' => $formats
        ];
    }
}

class ExternalApiExtractor implements ExtractorInterface {
    private $apiKey;
    private $apiHost;
    private $timeout;

    public function __construct($config) {
        $this->apiKey = $config['EXTERNAL_API_KEY'];
        $this->apiHost = $config['EXTERNAL_API_HOST'];
        $this->timeout = $config['REQUEST_TIMEOUT'];
    }

    public function extract($url) {
        // MOCK Response to demonstrate architecture without a real paid API key
        // In a real scenario, use cURL to hit the third-party API.
        
        return [
            'platform' => 'MockAPI (Change EXTRACTION_MODE to YTDLP for local test)',
            'type' => 'video',
            'id' => 'mock_123',
            'title' => 'Example Extracted Video (API Mode)',
            'thumbnail' => 'https://via.placeholder.com/640x360.png?text=Thumbnail',
            'duration' => 120,
            'formats' => [
                [
                    'format_id' => '1',
                    'ext' => 'mp4',
                    'resolution' => '1920x1080',
                    'vcodec' => 'h264',
                    'acodec' => 'aac',
                    'filesize' => 15000000,
                    'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4'
                ],
                [
                    'format_id' => '2',
                    'ext' => 'm4a',
                    'resolution' => 'audio only',
                    'vcodec' => 'none',
                    'acodec' => 'aac',
                    'filesize' => 3000000,
                    'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4' // mock audio
                ]
            ]
        ];
    }
}
