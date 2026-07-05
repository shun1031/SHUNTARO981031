<?php
/**
 * エビデンス配信エンドポイント
 * 署名付きURL（HMAC）で認証 → セッション不要でタブ開きでも正常動作
 */
require_once __DIR__ . '/../../config/config.php';

$id    = (int)($_GET['id']    ?? 0);
$field = (int)($_GET['field'] ?? 0);
$cid   = (int)($_GET['c']     ?? 0);
$token = $_GET['t'] ?? '';

$colMap = [1 => 'evidence_data_1', 2 => 'evidence_data_2', 3 => 'evidence_data_3'];
$urlMap = [1 => 'evidence_url_1',  2 => 'evidence_url_2',  3 => 'evidence_url_3'];

if ($id <= 0 || $cid <= 0 || !isset($colMap[$field])) {
    http_response_code(400);
    exit;
}

// HMACトークン検証（今日 or 昨日のキー。日跨ぎでもリンク切れしない）
$keyBase = 'ev_' . SESSION_NAME;
$expected     = substr(hash_hmac('sha256', "{$id}:{$field}:{$cid}", $keyBase . date('Ymd')), 0, 24);
$expectedPrev = substr(hash_hmac('sha256', "{$id}:{$field}:{$cid}", $keyBase . date('Ymd', strtotime('-1 day'))), 0, 24);

if (!hash_equals($expected, $token) && !hash_equals($expectedPrev, $token)) {
    http_response_code(403);
    exit;
}

$col    = $colMap[$field];
$urlCol = $urlMap[$field];

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $db  = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->prepare("SELECT `{$col}`, `{$urlCol}` FROM sales_transport_costs WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit;
}

if (!$row) {
    http_response_code(404);
    exit('Not Found');
}

$mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];
$urlVal  = $row[$urlCol] ?? '';
$ext     = strtolower(pathinfo($urlVal, PATHINFO_EXTENSION));
$mime    = $mimeMap[$ext] ?? 'application/octet-stream';

// DBのバイナリを優先配信
$blobData = $row[$col];
if (!empty($blobData)) {
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    echo $blobData;
    exit;
}

// フォールバック: ファイルシステム
if ($urlVal) {
    $filePath = __DIR__ . '/../../uploads/' . $urlVal;
    if (file_exists($filePath)) {
        $detectedMime = mime_content_type($filePath) ?: $mime;
        header('Content-Type: ' . $detectedMime);
        header('Cache-Control: private, max-age=86400');
        readfile($filePath);
        exit;
    }
}

http_response_code(404);
exit('エビデンスが見つかりません');
