<?php
/**
 * エビデンス配信エンドポイント
 * DBに保存したバイナリを優先して返し、なければファイルシステムにフォールバック
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$id    = (int)($_GET['id']    ?? 0);
$field = (int)($_GET['field'] ?? 0);

$colMap = [1 => 'evidence_data_1', 2 => 'evidence_data_2', 3 => 'evidence_data_3'];
$urlMap = [1 => 'evidence_url_1',  2 => 'evidence_url_2',  3 => 'evidence_url_3'];

if ($id <= 0 || !isset($colMap[$field])) {
    http_response_code(400);
    exit;
}

$cid = getCompanyId();
if (!$cid) {
    http_response_code(403);
    exit;
}

$col    = $colMap[$field];
$urlCol = $urlMap[$field];

$stmt = getDB()->prepare("SELECT `{$col}`, `{$urlCol}` FROM sales_transport_costs WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $cid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Not Found');
}

$mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];
$urlVal  = $row[$urlCol] ?? '';
$ext     = strtolower(pathinfo($urlVal, PATHINFO_EXTENSION));
$mime    = $mimeMap[$ext] ?? 'application/octet-stream';

// DBに保存されたバイナリを優先配信
$blobData = $row[$col];
if (!empty($blobData)) {
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    echo $blobData;
    exit;
}

// フォールバック: ファイルシステムから配信
if ($urlVal) {
    $filePath = __DIR__ . '/../../uploads/' . $urlVal;
    if (file_exists($filePath)) {
        $detectedMime = mime_content_type($filePath) ?: $mime;
        header('Content-Type: ' . $detectedMime);
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }
}

http_response_code(404);
exit('エビデンスが見つかりません');
