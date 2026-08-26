<?php
// Configuration Settings
return [
    'EXTRACTION_MODE' => 'YTDLP', // Set to 'YTDLP' if shell_exec is enabled and yt-dlp is installed, or 'API' for external RapidAPI
    'EXTERNAL_API_KEY' => 'YOUR_RAPIDAPI_KEY', // Only used if EXTRACTION_MODE is 'API'
    'EXTERNAL_API_HOST' => 'youtube-video-download-info.p.rapidapi.com', // Example API Host
    'CACHE_ENABLED' => true,
    'CACHE_TTL' => 3600, // Cache results for 1 hour
    'RATE_LIMIT_ENABLED' => true,
    'RATE_LIMIT_REQUESTS' => 15, // Max requests
    'RATE_LIMIT_WINDOW' => 60, // Per 60 seconds
    'REQUEST_TIMEOUT' => 30, // seconds
    'MAX_PLAYLIST_SIZE' => 50,
    'PATHS' => [
        'cache' => __DIR__ . '/cache',
        'logs' => __DIR__ . '/logs'
    ]
];
