<?php
function ensure_archive_columns(mysqli $conn, string $table): void {
    $definitions = [
        'homeowners' => [
            'archived_at' => 'DATETIME NULL DEFAULT NULL',
            'archived_status' => 'VARCHAR(50) NULL DEFAULT NULL',
        ],
        'announcements' => [
            'archived_at' => 'DATETIME NULL DEFAULT NULL',
        ],
        'payments' => [
            'archived_at' => 'DATETIME NULL DEFAULT NULL',
        ],
        'resident_feedback' => [
            'archived_at' => 'DATETIME NULL DEFAULT NULL',
            'resident_name' => 'VARCHAR(100) NULL DEFAULT NULL',
        ],
        'reservations' => [
            'archived_at' => 'DATETIME NULL DEFAULT NULL',
            'archived_resident_name' => 'VARCHAR(100) NULL DEFAULT NULL',
        ],
    ];

    if (!isset($definitions[$table])) {
        throw new InvalidArgumentException('Unsupported archive table.');
    }

    foreach ($definitions[$table] as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if (!$result) {
            throw new RuntimeException("Unable to inspect archive storage for {$table}.");
        }

        $exists = $result->num_rows > 0;
        $result->free();
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException("Unable to initialize archive storage for {$table}.");
        }
    }
}
