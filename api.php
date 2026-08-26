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

// Get JSON body
$jsonBody = file_get_contents('php://input');
$request = json_decode($jsonBody, true);

if (!$request || !isset($request['action'])) {
    Response::error('BAD_REQUEST', 'Invalid JSON payload or missing action.', 400);
}

// Rate Limiting
if ($config['RATE_LIMIT_ENABLED']) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limiter = new RateLimiter($config);
    if (!$limiter->check($ip)) {
        Response::error('RATE_LIMITED', 'Too many requests. Please try again later.', 429);
    }
}

if ($request['action'] === 'analyze') {
    $url = $request['url'] ?? '';
    $validUrl = Validator::sanitizeUrl($url);
    
    if (!$validUrl) {
        Response::error('INVALID_URL', 'The provided URL is invalid or unsupported.');
    }

    $cache = new Cache($config);
    $cacheKey = 'extract_' . md5($validUrl);
    
    // Check Cache
    $cachedData = $cache->get($cacheKey);
    if ($cachedData) {
        Response::json(true, $cachedData);
    }

    try {
        // Instantiate Extractor based on config
        $extractor = null;
        if ($config['EXTRACTION_MODE'] === 'YTDLP') {
            $extractor = new YtDlpExtractor($config);
        } else {
            $extractor = new ExternalApiExtractor($config);
        }

        $data = $extractor->extract($validUrl);
        
        // Save to cache
        $cache->set($cacheKey, $data);
        
        Response::json(true, $data);
        
    } catch (Exception $e) {
        // Log the error securely
        if (!is_dir($config['PATHS']['logs'])) {
            @mkdir($config['PATHS']['logs'], 0755, true);
        }
        $logMsg = date('Y-m-d H:i:s') . " - URL: $validUrl - ERROR: " . $e->getMessage() . PHP_EOL;
        @file_put_contents($config['PATHS']['logs'] . '/error.log', $logMsg, FILE_APPEND);
        
        Response::error('EXTRACTION_FAILED', 'Unable to retrieve this video information. ' . $e->getMessage(), 500);
    }

} else {
    Response::error('UNKNOWN_ACTION', 'The requested action is not recognized.');
}
