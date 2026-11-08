<?php
// Run before uploading: php tools/check_before_upload.php [site folder]
// Catches a resident page in admin/ (or the reverse) and required files that are missing.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = rtrim($argv[1] ?? dirname(__DIR__), '/\\');
$problems = [];

// A correct guard sends away anyone who is NOT admin; a resident guard sends away admins.
foreach (['admin' => '/role\'\]\s*(\?\?\s*\'\'\)\s*)?===\s*\'admin\'/', 'resident' => '/role\'\]\s*(\?\?\s*\'\'\)\s*)?!==\s*\'admin\'/'] as $folder => $wrongGuard) {
    foreach (glob("$root/$folder/*.php") ?: [] as $file) {
        foreach (file($file) as $number => $line) {
            if (strpos($line, 'header(') === false && strpos($line, 'if (') !== false && strpos($line, 'user_id') !== false && preg_match($wrongGuard, $line)) {
                $problems[] = "$folder/" . basename($file) . ':' . ($number + 1) . " has the wrong role check: " . trim($line);
            }
        }
    }
}

// Every required file and every local script or stylesheet must exist, with the exact letter case.
$exists = static function (string $path): bool {
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '..') array_pop($parts);
        elseif ($part !== '.') $parts[] = $part;
    }
    $current = array_shift($parts);
    foreach ($parts as $part) {
        $names = is_dir($current . '/') ? scandir($current . '/') : false;
        if ($names === false || !in_array($part, $names, true)) return false;
        $current .= '/' . $part;
    }
    return true;
};

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if ($file->getExtension() !== 'php' || !preg_match('#^(admin|resident|includes|[^/]+\.php$)#', $relative)) continue;
    $source = file_get_contents($path);

    preg_match_all('#require(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*)?[\'"]([^\'"]+\.php)[\'"]#', $source, $requires);
    foreach ($requires[1] as $target) {
        $base = strpos($target, '/') === 0 ? dirname($path) : dirname($path) . '/';
        if (!$exists($base . $target)) $problems[] = "$relative requires a missing file: $target";
    }

    preg_match_all('#Assets/(?:css|js)/[\w.-]+\.(?:css|js)#', $source, $assets);
    foreach (array_unique($assets[0]) as $asset) {
        if (!$exists("$root/$asset")) $problems[] = "$relative loads a missing file: $asset";
    }
}

$problems = array_values(array_unique($problems));
if ($problems) {
    echo count($problems) . " problem(s). Do not upload yet:\n - " . implode("\n - ", $problems) . "\n";
    exit(1);
}
echo "OK: role checks are correct and no required file is missing.\n";
