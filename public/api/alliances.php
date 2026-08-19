<?php
/**
 * 外注先一覧 API（取引先一覧の「外注先」タブ用）
 *  GET  ?q=&page=&per=&show=  … 一覧（検索・ページング）
 *  POST action=create        … 追加
 *  POST action=update        … 編集
 *  POST action=delete        … 削除（is_active=0。案件で使用中の外注先も安全に非表示化）
 *  POST action=restore       … 削除済みを元に戻す
 *
 * 取引先API（clients.php）と同じ作りにしてある。違いは契約書が無いことと、
 * 「種別」と「同じ会社の取引先」があること。
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

const AL_TYPES = ['アライアンス', '個人外注'];

/**
 * 「登録中」の一覧に出す条件: 常勤・イベントの案件が1件でもある外注先だけ。
 * キャンセル済みの案件は数えない。期間の制限は無し。
 * ※削除済みの一覧はこの絞り込みをかけない（復元したいものを探せなくなるため）
 */
const AL_HAS_CASE = "EXISTS (SELECT 1 FROM sales_cases sc
                             WHERE sc.company_id  = sales_alliances.company_id
                               AND sc.alliance_id = sales_alliances.id
                               AND sc.status <> 'cancelled')";

/** 1件を一覧表示用に整形 */
function allianceRowOut(array $r, array $clientById, array $alsoClient = []): array {
    $cid = (int)($r['client_id'] ?? 0);
    $linked = $clientById[$cid] ?? null;
    return [
        'id'             => (int)$r['id'],
        'alliance_name'  => (string)($r['alliance_name'] ?? ''),
        'display_name'   => (string)($r['display_name'] ?? ''),
        'alliance_type'  => (string)($r['alliance_type'] ?? 'アライアンス'),
        'contact_person' => (string)($r['contact_person'] ?? ''),
        'email'          => (string)($r['email'] ?? ''),
        'phone'          => (string)($r['phone'] ?? ''),
        'client_id'      => $r['client_id'] !== null ? (int)$r['client_id'] : null,
        'client_label'   => $linked ? clientLabel($linked) : '',
        // 紐づけ先の取引先が取引先タブにも出ていれば「取引先にもあり」バッジを出す
        'also_client'    => isset($alsoClient[$cid]),
    ];
}

$action = $_POST['action'] ?? '';

// 追加・編集・削除は管理者のみ（一覧の取得は従来どおり閲覧可）
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireAdminWrite(true); }

// 紐づけ先の取引先（表示と選択肢に使う）
$clStmt = $db->prepare('SELECT id, client_name, display_name, is_active FROM sales_clients WHERE company_id = ?');
$clStmt->execute([$cid]);
$clientById = [];
foreach ($clStmt->fetchAll(PDO::FETCH_ASSOC) as $c) { $clientById[(int)$c['id']] = $c; }

// ───────── 一覧取得 ─────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q    = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if ($per < 1 || $per > 100) $per = 20;

    $show   = ($_GET['show'] ?? '') === 'deleted' ? 'deleted' : 'active';
    $where  = 'company_id = ? AND is_active = ' . ($show === 'deleted' ? '0' : '1');
    // 登録中の一覧は「案件がある外注先」だけに絞る（削除済みは絞らない）
    if ($show === 'active') $where .= ' AND ' . AL_HAS_CASE;
    $params = [$cid];
    if ($q !== '') {
        // 正式名称・表記名・担当者名のいずれかに部分一致
        $where .= ' AND (alliance_name LIKE ? OR display_name LIKE ? OR contact_person LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    try {
        $cStmt = $db->prepare("SELECT COUNT(*) FROM sales_alliances WHERE $where");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $per;
        $lStmt = $db->prepare("SELECT * FROM sales_alliances WHERE $where ORDER BY sort_order, alliance_name LIMIT $per OFFSET $offset");
        $lStmt->execute($params);
        $pageRows = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        // このページの紐づけ先のうち、取引先タブにも出ている取引先（バッジ用）
        $alsoClient = [];
        $linkIds = [];
        foreach ($pageRows as $r) { if (!empty($r['client_id'])) $linkIds[(int)$r['client_id']] = true; }
        if ($linkIds) {
            $ids = array_keys($linkIds);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $cSt = $db->prepare("SELECT id FROM sales_clients cl
                                 WHERE cl.company_id = ? AND cl.is_active = 1 AND cl.id IN ($ph)
                                   AND EXISTS (SELECT 1 FROM sales_cases sc
                                               WHERE sc.company_id = cl.company_id
                                                 AND sc.client_id  = cl.id
                                                 AND sc.status <> 'cancelled')");
            $cSt->execute(array_merge([$cid], $ids));
            foreach ($cSt->fetchAll(PDO::FETCH_COLUMN) as $v) $alsoClient[(int)$v] = true;
        }
        $rows = array_map(fn($r) => allianceRowOut($r, $clientById, $alsoClient), $pageRows);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'データの取得に失敗しました']); exit;
    }

    try {
        $dStmt = $db->prepare('SELECT COUNT(*) FROM sales_alliances WHERE company_id = ? AND is_active = 0');
        $dStmt->execute([$cid]);
        $deletedCount = (int)$dStmt->fetchColumn();
    } catch (PDOException $e) { $deletedCount = 0; }

    // 「同じ会社の取引先」の選択肢（登録中の取引先のみ）
    $clientOptions = [];
    foreach ($clientById as $c) {
        if ((int)$c['is_active'] !== 1) continue;
        $clientOptions[] = ['id' => (int)$c['id'], 'label' => clientLabel($c)];
    }
    usort($clientOptions, fn($a, $b) => strcmp($a['label'], $b['label']));

    echo json_encode([
        'ok'            => true,
        'show'          => $show,
        'deleted_count' => $deletedCount,
        'alliances'     => $rows,
        'client_options'=> $clientOptions,
        'types'         => AL_TYPES,
        'total'         => $total,
        'page'          => $page,
        'per'           => $per,
        'total_page'    => max(1, (int)ceil($total / $per)),
        'from'          => $total > 0 ? ($page - 1) * $per + 1 : 0,
        'to'            => min($total, $page * $per),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ───────── 更新系（CSRF必須）─────────
if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => '不正なリクエストです']); exit; }

$name     = trim($_POST['alliance_name'] ?? '');
$display  = trim($_POST['display_name'] ?? '');
$type     = trim($_POST['alliance_type'] ?? 'アライアンス');
$person   = trim($_POST['contact_person'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$clientId = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
$id       = (int)($_POST['id'] ?? 0);

if ($clientId !== null && !isset($clientById[$clientId])) { $clientId = null; }
if (!in_array($type, AL_TYPES, true)) $type = 'アライアンス';

if ($action === 'create' || $action === 'update') {
    if ($name === '')    { echo json_encode(['error' => '正式名称を入力してください']); exit; }
    if ($display === '') { echo json_encode(['error' => '表記名を入力してください']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'メールアドレスの形式が正しくありません']); exit;
    }
    // 表記名の重複を禁止（別会社に同じ表記名が付くと案件が誤って紐づくため）
    try {
        $dupSql = 'SELECT alliance_name FROM sales_alliances
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

    // 同じ取引先に2つの外注先を紐づけると、戦略会議の会社数の名寄せが曖昧になる
    if ($clientId !== null) {
        try {
            $dupSql = 'SELECT alliance_name FROM sales_alliances WHERE company_id = ? AND client_id = ?';
            $dupPar = [$cid, $clientId];
            if ($action === 'update' && $id) { $dupSql .= ' AND id <> ?'; $dupPar[] = $id; }
            $dupSql .= ' LIMIT 1';
            $dupStmt = $db->prepare($dupSql);
            $dupStmt->execute($dupPar);
            $dupName = $dupStmt->fetchColumn();
            if ($dupName !== false) {
                echo json_encode([
                    'error' => "この取引先はすでに外注先「{$dupName}」と紐づいています。\n紐づけられるのは1件までです。",
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (PDOException $e) { /* 判定できない場合は保存を妨げない */ }
    }
}

try {
    if ($action === 'create') {
        $ins = $db->prepare("INSERT INTO sales_alliances
            (company_id, alliance_name, display_name, alliance_type, contact_person, email, phone,
             client_id, is_active, sort_order)
            VALUES (?,?,?,?,?,?,?,?,1,0)");
        $ins->execute([$cid, $name, $display, $type, $person, $email, $phone, $clientId]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
        $up = $db->prepare("UPDATE sales_alliances
            SET alliance_name=?, display_name=?, alliance_type=?, contact_person=?, email=?, phone=?,
                client_id=?, updated_at=NOW()
            WHERE id=? AND company_id=?");
        $up->execute([$name, $display, $type, $person, $email, $phone, $clientId, $id, $cid]);
        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
        // 案件で使用中の外注先は削除しない（案件画面の外注先プルダウンから消えてしまうため）
        $u = $db->prepare("SELECT COUNT(*) FROM sales_cases
                           WHERE company_id = ? AND alliance_id = ? AND status <> 'cancelled'");
        $u->execute([$cid, $id]);
        $used = (int)$u->fetchColumn();
        if ($used > 0) {
            echo json_encode([
                'error' => "この外注先は案件で使用中のため削除できません（該当案件 {$used} 件）。\n案件側で外注先を変更してから削除してください。",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $db->prepare('UPDATE sales_alliances SET is_active = 0, updated_at = NOW() WHERE id = ? AND company_id = ?')
           ->execute([$id, $cid]);
        echo json_encode(['ok' => true, 'deleted_id' => $id]);
        exit;
    }

    if ($action === 'restore') {
        if ($id) {
            $db->prepare('UPDATE sales_alliances SET is_active = 1, updated_at = NOW() WHERE id = ? AND company_id = ?')
               ->execute([$id, $cid]);
            $restored = 1;
        } else {
            $st = $db->prepare('UPDATE sales_alliances SET is_active = 1, updated_at = NOW() WHERE company_id = ? AND is_active = 0');
            $st->execute([$cid]);
            $restored = $st->rowCount();
        }
        echo json_encode(['ok' => true, 'restored' => $restored]);
        exit;
    }
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        echo json_encode(['error' => 'その正式名称はすでに登録されています']); exit;
    }
    echo json_encode(['error' => '保存に失敗しました']); exit;
}

echo json_encode(['error' => '不明な操作です']);
