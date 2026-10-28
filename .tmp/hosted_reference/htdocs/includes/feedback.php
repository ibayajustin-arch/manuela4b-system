<?php
function ensure_resident_feedback_table(mysqli $conn) {
    $sql = "CREATE TABLE IF NOT EXISTS resident_feedback (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        category ENUM('suggestion', 'concern') NOT NULL,
        subject VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        archived_at DATETIME NULL DEFAULT NULL,
        status ENUM('new', 'in_review', 'resolved') NOT NULL DEFAULT 'new',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_resident_feedback_user_created (user_id, created_at),
        KEY idx_resident_feedback_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to initialize resident feedback storage.');
    }

    $columns = [
        'admin_reply' => "ALTER TABLE resident_feedback ADD COLUMN admin_reply TEXT NULL AFTER message",
        'admin_replied_at' => "ALTER TABLE resident_feedback ADD COLUMN admin_replied_at TIMESTAMP NULL DEFAULT NULL AFTER admin_reply",
        'resident_name' => "ALTER TABLE resident_feedback ADD COLUMN resident_name VARCHAR(100) NULL DEFAULT NULL AFTER user_id",
    ];
    foreach ($columns as $column => $alterSql) {
        $result = $conn->query("SHOW COLUMNS FROM resident_feedback LIKE '{$column}'");
        if (!$result || !$result->fetch_assoc()) {
            if (!$conn->query($alterSql)) {
                throw new RuntimeException('Unable to update resident feedback storage.');
            }
        }
    }
}
