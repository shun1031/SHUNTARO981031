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
// 閲覧は管理者または営業担当（追加・編集・削除は下のPOST判定で管理者のみに制限）
if (!canViewSalesPages()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$db = getDB();

/** 1件を一覧表示用に整形 */
function clientRowOut(array $r, array $alsoAlliance = []): array {
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
        // 外注先にも同じ会社が登録されていれば「外注先にもあり」バッジを出す
        'also_alliance'      => isset($alsoAlliance[(int)$r['id']]),
    ];
}

$action = $_POST['action'] ?? '';

// 取引先の追加・編集・削除は管理者のみ（一覧の取得は従来どおり閲覧可）
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireAdminWrite(true); }

// ───────── 一覧取得 ─────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q    = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if ($per < 1 || $per > 100) $per = 20;

    // 表示する年度（9月始まり）。戦略会議のパートナー数と同じ数え方に揃える
    $fyList = tradeFyOptions($cid);
    $fy     = (int)($_GET['fy'] ?? 0);
    if (!in_array($fy, $fyList, true)) $fy = $fyList[0] ?? tradeCurrentFy();
    $repNames = getSalesRepCandidates($cid);

    // show=deleted で削除済み（is_active=0）の取引先を表示し、復元できるようにする
    $show   = ($_GET['show'] ?? '') === 'deleted' ? 'deleted' : 'active';
    $where  = 'company_id = ? AND is_active = ' . ($show === 'deleted' ? '0' : '1');
    $params = [$cid];
    // 登録中の一覧は「その年度に取引がある取引先」だけに絞る（削除済みは絞らない）
    if ($show === 'active') {
        [$hasCaseSql, $hasCaseParams] = tradeClientHasCaseSql($fy, $repNames);
        $where .= ' AND ' . $hasCaseSql;
        $params = array_merge($params, $hasCaseParams);
    }
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
        $pageRows = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        // このページに出す取引先のうち、その年度に取引がある外注先にも登録があるもの（バッジ用）
        $alsoAlliance = [];
        $ids = array_map(fn($r) => (int)$r['id'], $pageRows);
        if ($ids && $repNames) {
            [$alSql, $alParams] = tradeAllianceHasCaseSql($fy, $repNames);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $aStmt = $db->prepare("SELECT DISTINCT client_id FROM sales_alliances
                                   WHERE company_id = ? AND is_active = 1 AND client_id IN ($ph)
                                     AND {$alSql}");
            $aStmt->execute(array_merge([$cid], $ids, $alParams));
            foreach ($aStmt->fetchAll(PDO::FETCH_COLUMN) as $v) $alsoAlliance[(int)$v] = true;
        }
        $rows = array_map(fn($r) => clientRowOut($r, $alsoAlliance), $pageRows);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'データの取得に失敗しました']); exit;
    }

    // 上部に出す「合計◯社（重複を除く）」。戦略会議のパートナー数と同じ数え方
    $summary = tradeCompanySummary($db, $cid, $fy, $repNames);

    // 復元できる（削除済みの）取引先の件数
    try {
        $dStmt = $db->prepare('SELECT COUNT(*) FROM sales_clients WHERE company_id = ? AND is_active = 0');
        $dStmt->execute([$cid]);
        $deletedCount = (int)$dStmt->fetchColumn();
    } catch (PDOException $e) { $deletedCount = 0; }

    echo json_encode([
        'ok'         => true,
        'show'       => $show,
        'deleted_count' => $deletedCount,
        'fy'         => $fy,
        'fy_label'   => tradeFyLabel($fy),
        'fy_options' => array_map(fn($v) => ['fy' => $v, 'label' => tradeFyLabel($v)], $fyList),
        'summary'    => $summary,
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
    // 表記名の重複を禁止（別会社に同じ表記名が付くと案件が誤って紐づくため）
    // ※空欄は対象外。編集時は自分自身を除外する
    try {
        $dupSql = 'SELECT client_name FROM sales_clients
                   WHERE company_id = ? AND TRIM(display_name) = ? AND TRIM(display_name) <> \'\'';
        $dupPar = [$cid, $display];
        if ($action === 'update' && $id) { $dupSql .= ' AND id <> ?'; $dupPar[] = $id; }
        $dupSql .= ' LIMIT 1';
        $dupStmt = $db->prepare($dupSql);
        $dupStmt->execute($dupPar);
        $dupName = $dupStmt->fetchColumn();
        if ($dupName !== false) {
            echo json_encode([
                'error' => "表記名「{$display}」はすでに「{$dupName}」で使われています。\n別の表記名を入力してください。",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (PDOException $e) { /* 判定できない場合は保存を妨げない */ }
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

        // 同じ会社が外注先にも登録されている場合は、外注先の名前も同じに揃える。
        // 取引先一覧だけ直せば案件一覧・アライアンス別売上などの表示もすべて追従する
        try {
            $db->prepare('UPDATE sales_alliances SET alliance_name = ?, display_name = ?, updated_at = NOW()
                          WHERE company_id = ? AND client_id = ?')
               ->execute([$name, $display, $cid, $id]);
        } catch (PDOException $e) {
            // 外注先名が他と重複する等で失敗しても、取引先の保存自体は成功させる
            error_log('[clients sync alliance] ' . $e->getMessage());
        }

        $g = $db->prepare('SELECT * FROM sales_clients WHERE id = ? AND company_id = ?');
        $g->execute([$id, $cid]);
        $row = $g->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'client' => $row ? clientRowOut($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
        // 案件で使用中の取引先は削除しない
        // （案件画面の取引先プルダウンから消えてしまうため）
        $u = $db->prepare("SELECT COUNT(*) FROM sales_cases
                           WHERE company_id = ? AND client_id = ? AND status <> 'cancelled'");
        $u->execute([$cid, $id]);
        $used = (int)$u->fetchColumn();
        if ($used > 0) {
            echo json_encode([
                'error' => "この取引先は案件で使用中のため削除できません（該当案件 {$used} 件）。\n案件側で取引先を変更してから削除してください。",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 案件から参照されている可能性があるため物理削除はしない
        $db->prepare('UPDATE sales_clients SET is_active = 0, updated_at = NOW() WHERE id = ? AND company_id = ?')
           ->execute([$id, $cid]);
        echo json_encode(['ok' => true, 'deleted_id' => $id]);
        exit;
    }

    // 削除済みの取引先を元に戻す（id指定。id=0 で削除済みを一括復元）
    if ($action === 'restore') {
        if ($id) {
            $db->prepare('UPDATE sales_clients SET is_active = 1, updated_at = NOW() WHERE id = ? AND company_id = ?')
               ->execute([$id, $cid]);
            $restored = 1;
        } else {
            $st = $db->prepare('UPDATE sales_clients SET is_active = 1, updated_at = NOW() WHERE company_id = ? AND is_active = 0');
            $st->execute([$cid]);
            $restored = $st->rowCount();
        }
        echo json_encode(['ok' => true, 'restored' => $restored]);
        exit;
    }
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        echo json_encode(['error' => 'その会社名はすでに登録されています']); exit;
    }
    echo json_encode(['error' => '保存に失敗しました']); exit;
}

echo json_encode(['error' => '不明な操作です']);
