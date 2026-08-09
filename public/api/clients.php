<?php
/**
 * 取引先一覧 API
 *  GET  ?q=&page=&per=      … 一覧（検索・ページング）
 *  POST action=create       … 追加
 *  POST action=update       … 編集
 *  POST action=delete       … 削除（is_active=0。案件で使用中の取引先も安全に非表示化）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
if (!isAdmin()) { echo json_encode(['error' => 'Forbidden']); exit; }

$db = getDB();

/** 1件を一覧表示用に整形 */
function clientRowOut(array $r): array {
    $link = clientContractLink($r);
    return [
        'id'                 => (int)$r['id'],
        'client_name'        => (string)($r['client_name'] ?? ''),
        'display_name'       => (string)($r['display_name'] ?? ''),
        'contact_person'     => (string)($r['contact_person'] ?? ''),
        'email'              => (string)($r['email'] ?? ''),
        'phone'              => (string)($r['phone'] ?? ''),
        'contract_file_id'   => (string)($r['contract_file_id'] ?? ''),
        'contract_file_name' => (string)($r['contract_file_name'] ?? ''),
        'contract_url'       => (string)($r['contract_url'] ?? ''),
        'has_contract'       => $link['has'],
        'contract_link'      => $link['url'],
    ];
}

$action = $_POST['action'] ?? '';

// ───────── 一覧取得 ─────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q    = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if ($per < 1 || $per > 100) $per = 20;

    $where  = 'company_id = ? AND is_active = 1';
    $params = [$cid];
    if ($q !== '') {
        // 会社名・表記名・担当者名のいずれかに部分一致
        $where .= ' AND (client_name LIKE ? OR display_name LIKE ? OR contact_person LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    try {
        $cStmt = $db->prepare("SELECT COUNT(*) FROM sales_clients WHERE $where");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $per;
        $lStmt = $db->prepare("SELECT * FROM sales_clients WHERE $where ORDER BY sort_order, client_name LIMIT $per OFFSET $offset");
        $lStmt->execute($params);
        $rows = array_map('clientRowOut', $lStmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo json_encode(['error' => 'データの取得に失敗しました']); exit;
    }

    echo json_encode([
        'ok'         => true,
        'clients'    => $rows,
        'total'      => $total,
        'page'       => $page,
        'per'        => $per,
        'total_page' => max(1, (int)ceil($total / $per)),
        'from'       => $total > 0 ? ($page - 1) * $per + 1 : 0,
        'to'         => min($total, $page * $per),
        'drive'      => googleDriveConfigStatus(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ───────── 更新系（CSRF必須）─────────
if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => '不正なリクエストです']); exit; }

$name     = trim($_POST['client_name'] ?? '');
$display  = trim($_POST['display_name'] ?? '');
$person   = trim($_POST['contact_person'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$contract = trim($_POST['contract_input'] ?? '');   // GoogleドライブのURLまたはファイルID
$fileName = trim($_POST['contract_file_name'] ?? '');
$id       = (int)($_POST['id'] ?? 0);

// 契約書: URLならファイルIDを抽出。抽出できない場合もURLとして保持（紐付けは維持される）
$fileId    = googleDriveExtractFileId($contract);
$rawUrl    = ($fileId === null && $contract !== '') ? $contract : '';

if ($action === 'create' || $action === 'update') {
    if ($name === '')    { echo json_encode(['error' => '会社名を入力してください']); exit; }
    if ($display === '') { echo json_encode(['error' => '表記名を入力してください']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'メールアドレスの形式が正しくありません']); exit;
    }
}

try {
    if ($action === 'create') {
        $ins = $db->prepare("INSERT INTO sales_clients
            (company_id, client_name, display_name, contact_person, email, phone,
             contract_file_id, contract_file_name, contract_url, is_active, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,1,0)");
        $ins->execute([$cid, $name, $display, $person, $email, $phone, $fileId, $fileName, $rawUrl]);
        $newId = (int)$db->lastInsertId();
        $g = $db->prepare('SELECT * FROM sales_clients WHERE id = ? AND company_id = ?');
        $g->execute([$newId, $cid]);
        $row = $g->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'client' => $row ? clientRowOut($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
        $up = $db->prepare("UPDATE sales_clients
            SET client_name=?, display_name=?, contact_person=?, email=?, phone=?,
                contract_file_id=?, contract_file_name=?, contract_url=?, updated_at=NOW()
            WHERE id=? AND company_id=?");
        $up->execute([$name, $display, $person, $email, $phone, $fileId, $fileName, $rawUrl, $id, $cid]);
        $g = $db->prepare('SELECT * FROM sales_clients WHERE id = ? AND company_id = ?');
        $g->execute([$id, $cid]);
        $row = $g->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'client' => $row ? clientRowOut($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
        // 案件から参照されている可能性があるため物理削除はしない
        $db->prepare('UPDATE sales_clients SET is_active = 0, updated_at = NOW() WHERE id = ? AND company_id = ?')
           ->execute([$id, $cid]);
        echo json_encode(['ok' => true, 'deleted_id' => $id]);
        exit;
    }
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        echo json_encode(['error' => 'その会社名はすでに登録されています']); exit;
    }
    echo json_encode(['error' => '保存に失敗しました']); exit;
}

echo json_encode(['error' => '不明な操作です']);
