<?php
class RateLimiter {
    private $dir;
    private $limit;
    private $window;

    public function __construct($config) {
        $this->dir = $config['PATHS']['cache'] . '/ratelimit';
        $this->limit = $config['RATE_LIMIT_REQUESTS'];
        $this->window = $config['RATE_LIMIT_WINDOW'];
        
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function check($ip) {
        $filename = $this->dir . '/' . md5($ip) . '.json';
        $now = time();
        $requests = [];

        if (file_exists($filename)) {
            $data = json_decode(file_get_contents($filename), true);
            if (is_array($data)) {
                // Filter out old requests
                foreach ($data as $timestamp) {
                    if ($now - $timestamp <= $this->window) {
                        $requests[] = $timestamp;
                    }
                }
            }
        }

        if (count($requests) >= $this->limit) {
            return false; // Rate limited
        }

        $requests[] = $now;
        file_put_contents($filename, json_encode($requests));
        return true;
    }
}
