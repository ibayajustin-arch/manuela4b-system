<?php
/**
 * includes/incident_helpers.php
 * Helpers for incident reports on resident/feedback.php
 */

if (!defined('INCIDENT_MAX_PHOTOS')) define('INCIDENT_MAX_PHOTOS', 5);
if (!defined('INCIDENT_MAX_BYTES'))  define('INCIDENT_MAX_BYTES', 5 * 1024 * 1024); // 5 MB

function incident_categories(): array
{
    return [
        'security'  => 'Security / Trespassing',
        'theft'     => 'Theft / Burglary',
        'vandalism' => 'Vandalism / Property damage',
        'noise'     => 'Noise disturbance',
        'accident'  => 'Accident / Injury',
        'fire'      => 'Fire / Electrical hazard',
        'flooding'  => 'Flooding / Water leak',
        'animals'   => 'Stray or loose animals',
        'parking'   => 'Parking / Traffic violation',
        'other'     => 'Other',
    ];
}

function incident_upload_dir(): string
{
    // includes/ is one level below the site root -> <root>/uploads/incidents
    return dirname(__DIR__) . '/uploads/incidents';
}

function incident_run(mysqli $conn, string $sql): void
{
    if (!$conn->query($sql)) {
        throw new RuntimeException($conn->error . ' | SQL: ' . $sql);
    }
}

function incident_get_column(mysqli $conn, string $table, string $column): ?array
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    if (!$res) throw new RuntimeException($conn->error);
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: null;
}

/**
 * Adds the incident columns, the photos table, and the upload folder if missing.
 * Safe to call on every page load.
 */
function ensure_incident_support(mysqli $conn): void
{
    // 1. Make sure the category column accepts 'incident'
    $col = incident_get_column($conn, 'resident_feedback', 'category');
    if ($col && stripos($col['Type'], 'enum(') === 0 && stripos($col['Type'], "'incident'") === false) {
        $newType = substr($col['Type'], 0, -1) . ",'incident')";
        $null = $col['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] !== null
            ? " DEFAULT '" . $conn->real_escape_string($col['Default']) . "'"
            : '';
        incident_run($conn, "ALTER TABLE `resident_feedback` MODIFY `category` {$newType} {$null}{$default}");
    }

    // 2. Incident-only columns
    $columns = [
        'reference_no'      => "VARCHAR(20) NULL DEFAULT NULL",
        'incident_category' => "VARCHAR(50) NULL DEFAULT NULL",
        'incident_date'     => "DATETIME NULL DEFAULT NULL",
        'incident_location' => "VARCHAR(150) NULL DEFAULT NULL",
        'urgency'           => "ENUM('low','medium','high') NULL DEFAULT NULL",
    ];
    foreach ($columns as $name => $definition) {
        if (!incident_get_column($conn, 'resident_feedback', $name)) {
            incident_run($conn, "ALTER TABLE `resident_feedback` ADD COLUMN `{$name}` {$definition}");
        }
    }

    // 3. Photos table
    incident_run($conn, "CREATE TABLE IF NOT EXISTS `resident_feedback_photos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `feedback_id` INT NOT NULL,
        `file_name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_feedback_id` (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Upload folder (+ block scripts / directory listing inside it)
    $dir = incident_upload_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($htaccess)) {
        @file_put_contents($htaccess,
            "Options -Indexes\n" .
            "<FilesMatch \"\\.(php\\d?|phtml|phar)$\">\n" .
            "    Order allow,deny\n" .
            "    Deny from all\n" .
            "</FilesMatch>\n"
        );
    }
}

function incident_detect_mime(string $path)
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime) return $mime;
    }
    $info = @getimagesize($path);
    return $info['mime'] ?? false;
}

/**
 * Validates $_FILES['photos'] (multiple input).
 * Returns ['error' => string|null, 'files' => [['tmp' => ..., 'ext' => ...], ...]]
 */
function validate_incident_photos($files): array
{
    $result = ['error' => null, 'files' => []];
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        return $result; // no photos attached: that's fine, photos are optional
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $picked = [];

    foreach (array_keys($files['name']) as $i) {
        $err = (int) $files['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $result['error'] = 'One of the photos is too large (max 5 MB each).';
            return $result;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $result['error'] = 'One of the photos failed to upload. Please try again.';
            return $result;
        }
        if ((int) $files['size'][$i] > INCIDENT_MAX_BYTES) {
            $result['error'] = 'One of the photos is too large (max 5 MB each).';
            return $result;
        }

        $tmp = $files['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            $result['error'] = 'Invalid photo upload.';
            return $result;
        }

        $mime = incident_detect_mime($tmp);
        if (!isset($allowed[$mime])) {
            $result['error'] = 'Only JPG, PNG, or WEBP images are allowed.';
            return $result;
        }

        $picked[] = ['tmp' => $tmp, 'ext' => $allowed[$mime]];
    }

    if (count($picked) > INCIDENT_MAX_PHOTOS) {
        $result['error'] = 'You can attach up to ' . INCIDENT_MAX_PHOTOS . ' photos only.';
        return $result;
    }

    $result['files'] = $picked;
    return $result;
}

/**
 * Moves validated photos into the upload folder and records them.
 * Returns the stored file names. Cleans up its own files if something fails.
 */
function save_incident_photos(mysqli $conn, int $feedbackId, array $files): array
{
    $dir = incident_upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Incident upload folder is missing and could not be created.');
    }

    $stmt = $conn->prepare('INSERT INTO resident_feedback_photos (feedback_id, file_name) VALUES (?, ?)');
    if (!$stmt) throw new RuntimeException($conn->error);

    $stored = [];
    try {
        foreach ($files as $file) {
            $name = 'inc' . $feedbackId . '_' . bin2hex(random_bytes(8)) . '.' . $file['ext'];
            if (!move_uploaded_file($file['tmp'], $dir . '/' . $name)) {
                throw new RuntimeException('Could not save an incident photo.');
            }
            $stored[] = $name;

            $stmt->bind_param('is', $feedbackId, $name);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
    } catch (Throwable $e) {
        foreach ($stored as $n) {
            @unlink($dir . '/' . $n);
        }
        throw $e;
    } finally {
        $stmt->close();
    }

    return $stored;
}

/**
 * Returns [feedback_id => [['feedback_id' => .., 'file_name' => ..], ...]]
 */
function get_feedback_photos(mysqli $conn, array $feedbackIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $feedbackIds), function ($id) {
        return $id > 0;
    })));
    if (!$ids) return [];

    $in = implode(',', $ids); // already cast to int, safe to inline
    $res = $conn->query("SELECT feedback_id, file_name FROM resident_feedback_photos WHERE feedback_id IN ({$in}) ORDER BY id");
    if (!$res) throw new RuntimeException($conn->error);

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int) $row['feedback_id']][] = $row;
    }
    $res->free();
    return $out;
}