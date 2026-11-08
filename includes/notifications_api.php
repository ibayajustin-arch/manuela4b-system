<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/notifications.php'; // also loads dues_reminder.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin', 'homeowner', 'resident'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Automatic monthly dues reminders: checked after this response is sent,
// so the bell poll stays fast. Any logged-in poll can trigger the monthly run.
dues_reminder_schedule($conn);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'mark_read') {
    notification_mark_read($conn, (int) $_SESSION['user_id']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'poll') {
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $result = $isAdmin
        ? get_admin_notifications($conn, 25)
        : get_resident_notifications($conn, (int) $_SESSION['user_id'], 25);
    $items = array_map(static function ($item) {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'category_label' => (string) ($item['category_label'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'description' => html_entity_decode(strip_tags((string) ($item['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'time_ago' => (string) ($item['time_ago'] ?? ''),
            'link' => (string) ($item['link'] ?? '#'),
            'icon' => (string) ($item['icon'] ?? 'bi-bell'),
            'icon_bg' => (string) ($item['icon_bg'] ?? '#EDE2FF'),
            'icon_color' => (string) ($item['icon_color'] ?? '#5B21B6'),
            'is_unread' => !empty($item['is_unread']),
        ];
    }, $result['items'] ?? []);

    echo json_encode([
        'success' => true,
        'unread_count' => (int) ($result['unread_count'] ?? 0),
        'items' => $items,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);