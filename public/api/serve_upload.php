<?php
/**
 * アップロードファイル配信（認証付き）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAnyLogin();

$file = $_GET['file'] ?? '';
if (!$file) {
    http_response_code(400);
    exit;
}

// パストラバーサル防止
$file = basename(dirname($file)) . '/' . basename($file);
if (str_contains($file, '..') || str_starts_with($file, '/')) {
    http_response_code(400);
    exit;
}

$fullPath = __DIR__ . '/../../uploads/' . $file;
if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($fullPath);
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
