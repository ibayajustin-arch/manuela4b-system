<?php
// config/oauth.php  (do NOT commit this file to GitHub)
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $httpHost)[0];
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true) || (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']));
$baseUrl = $isLocal ? 'http://localhost/MANUELA' : 'https://manuela4b.infy.click';

return [
    'base_url' => defined('BASE_URL') ? BASE_URL : $baseUrl,

    'google' => [
        'client_id'     => '136744831873-ltgudkieosatki9bcnh2lhaj9epg1c9c.apps.googleusercontent.com',
        'client_secret' => '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',
    ],
];














