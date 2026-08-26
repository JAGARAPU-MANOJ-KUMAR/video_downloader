<?php
class Cache {
    private $dir;
    private $ttl;
    private $enabled;

    public function __construct($config) {
        $this->dir = $config['PATHS']['cache'] . '/meta';
        $this->ttl = $config['CACHE_TTL'];
        $this->enabled = $config['CACHE_ENABLED'];

        if ($this->enabled && !is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function get($key) {
        if (!$this->enabled) return null;
        
        $filename = $this->dir . '/' . md5($key) . '.json';
        if (file_exists($filename)) {
            $mtime = filemtime($filename);
            if (time() - $mtime <= $this->ttl) {
                return json_decode(file_get_contents($filename), true);
            }
            @unlink($filename); // expired
        }
        return null;
    }

    public function set($key, $data) {
        if (!$this->enabled) return;
        $filename = $this->dir . '/' . md5($key) . '.json';
        file_put_contents($filename, json_encode($data));
    }
}
