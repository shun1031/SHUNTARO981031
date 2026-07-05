<?php
/**
 * 交通費提出API（ファイルアップロード対応）
 * multipart/form-data で受信
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// 認証チェック
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '認証が必要です']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF検証（multipart/form-dataなので$_POSTから取得）
$csrfOk = !empty($_POST['csrf']) && verifyCsrfToken($_POST['csrf']);
$headerCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfHeaderOk = $headerCsrf && verifyCsrfToken($headerCsrf);
$originOk = verifyApiOrigin();

if (!$csrfOk && !$csrfHeaderOk && !$originOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF検証に失敗しました']);
    exit;
}

$cid = getCompanyId();
if (!$cid) {
    echo json_encode(['ok' => false, 'error' => '会社情報が取得できません']);
    exit;
}

// 権限チェック: 一般社員は自分の名前のみ
$empFilter = getEmployeeNameFilter();
$employeeName = trim($_POST['employee_name'] ?? '');
if (!$employeeName) {
    echo json_encode(['ok' => false, 'error' => '氏名を入力してください']);
    exit;
}
if ($empFilter !== null && $empFilter !== $employeeName) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '他のユーザーのデータは登録できません']);
    exit;
}

$targetYear = (int)($_POST['target_year'] ?? 0);
$targetMonth = (int)($_POST['target_month'] ?? 0);
if ($targetYear < 2024 || $targetYear > 2030 || $targetMonth < 1 || $targetMonth > 12) {
    echo json_encode(['ok' => false, 'error' => '対象年月が不正です']);
    exit;
}

// ファイルアップロード処理
$uploadDir = __DIR__ . '/../../uploads/transport/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$maxFileSize = 10 * 1024 * 1024; // 10MB

/**
 * @return array{path:string,data:string}|null
 */
function handleUpload(string $fieldName, string $uploadDir, int $cid, string $empName, int $year, int $month): ?array {
    global $allowedTypes, $maxFileSize;

    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'ファイルサイズが大きすぎます',
            UPLOAD_ERR_FORM_SIZE => 'ファイルサイズが大きすぎます',
            UPLOAD_ERR_PARTIAL => 'ファイルが一部しかアップロードされませんでした',
            UPLOAD_ERR_NO_TMP_DIR => 'サーバーエラー',
            UPLOAD_ERR_CANT_WRITE => 'サーバーエラー',
        ];
        throw new RuntimeException($errors[$file['error']] ?? 'アップロードエラー');
    }

    if ($file['size'] > $maxFileSize) {
        throw new RuntimeException('ファイルサイズは10MB以下にしてください');
    }

    // MIMEタイプ検証（finfo使用）
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedTypes, true)) {
        throw new RuntimeException('許可されていないファイル形式です（JPEG/PNG/GIF/WebP/PDFのみ）');
    }

    // 安全なファイル名生成
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        default => 'bin',
    };
    $safeEmpName = preg_replace('/[^a-zA-Z0-9\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', '_', $empName);
    $filename = sprintf('%d_%d%02d_%s_%s.%s', $cid, $year, $month, $safeEmpName, bin2hex(random_bytes(8)), $ext);

    $destPath = $uploadDir . $filename;
    // tmp_nameからバイナリ読み取り（move前に行う）
    $fileData = file_get_contents($file['tmp_name']);
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('ファイルの保存に失敗しました');
    }

    return ['path' => 'transport/' . $filename, 'data' => $fileData];
}

try {
    $evidence1 = handleUpload('evidence_1', $uploadDir, $cid, $employeeName, $targetYear, $targetMonth);
    $evidence2 = handleUpload('evidence_2', $uploadDir, $cid, $employeeName, $targetYear, $targetMonth);
    $evidence3 = handleUpload('evidence_3', $uploadDir, $cid, $employeeName, $targetYear, $targetMonth);

    $evidenceUrl1 = $evidence1['path'] ?? null;
    $evidenceUrl2 = $evidence2['path'] ?? null;
    $evidenceUrl3 = $evidence3['path'] ?? null;

    // エビデンス①は必須
    if (!$evidenceUrl1) {
        echo json_encode(['ok' => false, 'error' => '交通費エビデンス①は必須です']);
        exit;
    }

    $workDays1 = (int)($_POST['work_days_1'] ?? 0);
    $workDays2 = (int)($_POST['work_days_2'] ?? 0);
    $workDays3 = (int)($_POST['work_days_3'] ?? 0);
    $distanceKm1 = !empty($_POST['distance_km_1']) ? (float)$_POST['distance_km_1'] : null;
    $distanceKm2 = !empty($_POST['distance_km_2']) ? (float)$_POST['distance_km_2'] : null;
    $distanceKm3 = !empty($_POST['distance_km_3']) ? (float)$_POST['distance_km_3'] : null;
    $cost1 = (int)($_POST['cost_1'] ?? 0);
    $cost2 = (int)($_POST['cost_2'] ?? 0);
    $cost3 = (int)($_POST['cost_3'] ?? 0);
    $highwayCost = (int)($_POST['highway_cost'] ?? 0);

    if ($workDays1 < 1) {
        echo json_encode(['ok' => false, 'error' => '①の稼働日数を入力してください']);
        exit;
    }

    $totalAmount = $cost1 + $cost2 + $cost3 + $highwayCost;

    $data = [
        'employee_name' => $employeeName,
        'target_year' => $targetYear,
        'target_month' => $targetMonth,
        'total_amount' => $totalAmount,
        'evidence_url_1' => $evidenceUrl1,
        'distance_km_1' => $distanceKm1,
        'work_days_1' => $workDays1,
        'cost_1' => $cost1,
        'evidence_url_2' => $evidenceUrl2,
        'distance_km_2' => $distanceKm2,
        'work_days_2' => $workDays2 ?: null,
        'cost_2' => $cost2 ?: null,
        'evidence_url_3' => $evidenceUrl3,
        'distance_km_3' => $distanceKm3,
        'work_days_3' => $workDays3 ?: null,
        'cost_3' => $cost3 ?: null,
        'highway_cost' => $highwayCost,
    ];

    // 既存データチェック（同一社員・同一月があればupdate）
    $existing = getTransportCosts($cid, $targetYear, $targetMonth, $employeeName);
    if (!empty($existing)) {
        $data['id'] = $existing[0]['id'];
        // 既存のエビデンスを保持（新しくアップロードされなかった場合）
        if (!$evidenceUrl2 && !empty($existing[0]['evidence_url_2'])) {
            $data['evidence_url_2'] = $existing[0]['evidence_url_2'];
        }
        if (!$evidenceUrl3 && !empty($existing[0]['evidence_url_3'])) {
            $data['evidence_url_3'] = $existing[0]['evidence_url_3'];
        }
    }

    $id = saveTransportCost($cid, $data);

    // submitted_atを更新
    $db = getDB();
    $stmt = $db->prepare("UPDATE sales_transport_costs SET submitted_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $cid]);

    // エビデンスバイナリをDBに保存（コンテナ再起動後も画像を参照可能にするため）
    $blobCols = [];
    $blobVals = [];
    foreach ([1 => $evidence1, 2 => $evidence2, 3 => $evidence3] as $i => $ev) {
        if ($ev !== null && isset($ev['data'])) {
            $blobCols[] = "evidence_data_{$i} = ?";
            $blobVals[] = $ev['data'];
        }
    }
    if ($blobCols) {
        $blobVals[] = $id;
        $blobVals[] = $cid;
        $db->prepare("UPDATE sales_transport_costs SET " . implode(', ', $blobCols) . " WHERE id = ? AND company_id = ?")
           ->execute($blobVals);
    }

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'message' => '交通費を提出しました',
        'total_amount' => $totalAmount,
    ]);

} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Transport submit error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'データの保存に失敗しました']);
}
