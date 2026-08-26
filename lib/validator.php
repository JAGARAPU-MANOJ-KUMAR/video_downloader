<?php
class Validator {
    public static function sanitizeUrl($url) {
        $url = trim($url);
        if (empty($url)) return false;
        
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Prevent SSRF / Local IP requests
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) return false;
        
        $host = strtolower($parsed['host']);
        
        // Allowed platforms (Extensible)
        $allowed_domains = ['youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'tiktok.com', 'instagram.com', 'twitter.com', 'x.com', 'facebook.com', 'fb.watch'];
        $domain_allowed = false;
        foreach ($allowed_domains as $d) {
            if (str_ends_with($host, $d)) {
                $domain_allowed = true;
                break;
            }
        }
        
        if (!$domain_allowed) return false;

        return $url;
    }
}
