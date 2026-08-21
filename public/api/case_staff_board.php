<?php
/**
 * 案件人員一覧 API
 *
 *  GET  ?side=cases&rep=&status=&q=   … 案件一覧（sales_cases をそのまま読む）
 *  GET  ?side=staff&rep=&q=           … アサイン検討中の人員一覧
 *  POST action=save_staff             … 人員の追加・編集
 *  POST action=delete_staff           … 人員の削除
 *  POST action=assign                 … 人員を案件にアサイン（確定案件を1件つくる）
 *  POST action=unassign               … アサインを取り消して候補に戻す
 *
 * 案件は新しいテーブルを持たず、既存の sales_cases を読むだけ。
 * 案件の追加・編集は既存の public/api/save_case.php をそのまま使うので、
 * この画面から登録した案件は常勤案件・イベント案件・売上集計に自動で反映される。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
// 閲覧は管理者または営業担当（他の売上画面と同じ）。追加・編集は下のPOST判定で管理者のみ
if (!canViewSalesPages()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$db = getDB();

/** 人員のスキル感の選択肢 */
const CSB_SKILL_TYPES = ['キャッチャー', 'クローザー'];
/** 人員のアサイン状況 */
const CSB_ASSIGN_STATUSES = ['検討中', 'アサイン済', '見送り'];

// 追加・編集・削除は管理者のみ
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireAdminWrite(true); }

// ============================================================
// 案件一覧
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['side'] ?? '') === 'cases') {
    $rep    = trim($_GET['rep'] ?? '');
    $status = ($_GET['status'] ?? '') === 'confirmed' ? 'confirmed' : 'draft';
    $q      = trim($_GET['q'] ?? '');

    $where  = ["sc.company_id = ?", "sc.status = ?"];
    $params = [$cid, $status];
    if ($rep !== '') {
        // 担当者は社員IDがあればその社員、無ければ案件に入っている名前で判定する
        // （既存の売上集計とまったく同じ考え方）
        $where[]  = "COALESCE(er.name, sc.sales_rep) = ?";
        $params[] = $rep;
    }
    if ($q !== '') {
        $where[] = "(sc.store_name LIKE ? OR sc.trade_name LIKE ? OR cl.client_name LIKE ? OR cl.display_name LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    try {
        $sql = "SELECT sc.id, sc.case_type, sc.case_year, sc.case_month, sc.carrier, sc.trade_name,
                       sc.store_name, sc.start_date, sc.end_date, sc.worker_name, sc.worker_type,
                       sc.recruitment_count, sc.status,
                       COALESCE(er.name, sc.sales_rep) AS rep_name,
                       " . clientLabelSql('cl') . " AS client_name,
                       " . allianceLabelSql('al') . " AS alliance_name,
                       (SELECT COUNT(*) FROM case_staff_candidates csc
                         WHERE csc.company_id = sc.company_id
                           AND csc.assigned_case_id = sc.id
                           AND csc.assign_status = 'アサイン済') AS assigned_count
                FROM sales_cases sc
                LEFT JOIN sales_clients cl   ON sc.client_id = cl.id
                LEFT JOIN sales_alliances al ON sc.alliance_id = al.id
                LEFT JOIN employees er       ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sc.start_date DESC, sc.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[case_staff_board cases] ' . $e->getMessage());
        echo json_encode(['error' => 'データの取得に失敗しました']); exit;
    }

    $out = [];
    foreach ($rows as $r) {
        $need     = $r['recruitment_count'] !== null ? (int)$r['recruitment_count'] : null;
        $assigned = (int)$r['assigned_count'];
        // 稼働者が既に入っている案件は、その1名分もアサイン済みとして数える
        if (trim((string)$r['worker_name']) !== '') $assigned++;
        $out[] = [
            'id'          => (int)$r['id'],
            'case_type'   => $r['case_type'] === 'regular' ? '常勤' : 'イベント',
            'client_name' => (string)($r['client_name'] ?? ''),
            'carrier'     => (string)($r['carrier'] ?? ''),
            'trade_name'  => (string)($r['trade_name'] ?? ''),
            'store_name'  => (string)($r['store_name'] ?? ''),
            'start_date'  => (string)($r['start_date'] ?? ''),
            'end_date'    => (string)($r['end_date'] ?? ''),
            'rep_name'    => (string)($r['rep_name'] ?? ''),
            'worker_name' => (string)($r['worker_name'] ?? ''),
            'alliance_name' => (string)($r['alliance_name'] ?? ''),
            'need_count'  => $need,
            'assigned'    => $assigned,
            'remaining'   => $need !== null ? max(0, $need - $assigned) : null,
        ];
    }
    echo json_encode(['ok' => true, 'status' => $status, 'cases' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 人員一覧（アサイン検討中）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['side'] ?? '') === 'staff') {
    $rep = trim($_GET['rep'] ?? '');
    $q   = trim($_GET['q'] ?? '');
    // 既定は「検討中」だけ。アサイン済みも見たいときは show=assigned
    $show = ($_GET['show'] ?? '') === 'assigned' ? 'アサイン済' : '検討中';

    $where  = ['csc.company_id = ?', 'csc.is_active = 1', 'csc.assign_status = ?'];
    $params = [$cid, $show];
    if ($rep !== '') { $where[] = 'csc.rep_name = ?'; $params[] = $rep; }
    if ($q !== '') {
        $where[] = '(csc.staff_name LIKE ? OR csc.affiliation LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like;
    }

    try {
        $sql = "SELECT csc.*, " . allianceLabelSql('al') . " AS alliance_label,
                       " . clientLabelSql('cl') . " AS assigned_client,
                       sc.store_name AS assigned_store
                FROM case_staff_candidates csc
                LEFT JOIN sales_alliances al ON csc.alliance_id = al.id
                LEFT JOIN sales_cases sc     ON csc.created_case_id = sc.id
                LEFT JOIN sales_clients cl   ON sc.client_id = cl.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY csc.available_from IS NULL, csc.available_from, csc.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[case_staff_board staff] ' . $e->getMessage());
        echo json_encode(['error' => 'データの取得に失敗しました']); exit;
    }

    $out = [];
    foreach ($rows as $r) {
        $aff = trim((string)($r['alliance_label'] ?? '')) !== ''
            ? (string)$r['alliance_label'] : (string)($r['affiliation'] ?? '');
        $out[] = [
            'id'             => (int)$r['id'],
            'staff_name'     => (string)$r['staff_name'],
            'employee_id'    => $r['employee_id'] !== null ? (int)$r['employee_id'] : null,
            'rep_name'       => (string)$r['rep_name'],
            'affiliation'    => $aff,
            'alliance_id'    => $r['alliance_id'] !== null ? (int)$r['alliance_id'] : null,
            'skill_type'     => (string)($r['skill_type'] ?? ''),
            'carrier'        => (string)($r['carrier'] ?? ''),
            'desired_price'  => $r['desired_price'] !== null ? (int)$r['desired_price'] : null,
            'available_from' => (string)($r['available_from'] ?? ''),
            'note'           => (string)($r['note'] ?? ''),
            'assign_status'  => (string)$r['assign_status'],
            'assigned_client'=> (string)($r['assigned_client'] ?? ''),
            'assigned_store' => (string)($r['assigned_store'] ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'show' => $show, 'staff' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Unknown side']); exit; }

// ============================================================
// 更新系（CSRF必須）
// ============================================================
if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => '不正なリクエストです']); exit; }

$action = $_POST['action'] ?? '';

// ---- 人員の追加・編集 ----
if ($action === 'save_staff') {
    $id        = (int)($_POST['id'] ?? 0);
    $name      = trim($_POST['staff_name'] ?? '');
    $repName   = trim($_POST['rep_name'] ?? '');
    $empId     = ($_POST['employee_id'] ?? '') !== '' ? (int)$_POST['employee_id'] : null;
    $allianceId= ($_POST['alliance_id'] ?? '') !== '' ? (int)$_POST['alliance_id'] : null;
    $affil     = trim($_POST['affiliation'] ?? '');
    $skill     = trim($_POST['skill_type'] ?? '');
    $carrier   = trim($_POST['carrier'] ?? '');
    $price     = ($_POST['desired_price'] ?? '') !== '' ? max(0, (int)$_POST['desired_price']) : null;
    $from      = trim($_POST['available_from'] ?? '');
    $note      = trim($_POST['note'] ?? '');

    if ($name === '')    { echo json_encode(['error' => '氏名を入力してください']); exit; }
    if ($repName === '') { echo json_encode(['error' => '担当営業を選んでください']); exit; }
    if ($skill !== '' && !in_array($skill, CSB_SKILL_TYPES, true)) $skill = '';
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';

    // 同じ人を二重に登録しない（検討中のうちは氏名で重複を止める）
    try {
        $dup = $db->prepare("SELECT staff_name FROM case_staff_candidates
                             WHERE company_id = ? AND is_active = 1 AND assign_status = '検討中'
                               AND TRIM(staff_name) = ? AND id <> ? LIMIT 1");
        $dup->execute([$cid, $name, $id]);
        if ($dup->fetchColumn() !== false) {
            echo json_encode(['error' => "「{$name}」はすでに検討中の人員として登録されています。"], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (PDOException $e) { /* 判定できない場合は保存を妨げない */ }

    $repEmpId = resolveEmployeeIdByName($cid, $repName);
    // 社員一覧に同じ名前の人が1人だけ居れば自動で紐づける。
    // これで同じ人が社員一覧と候補リストで別人として扱われるのを防ぐ
    if ($empId === null) $empId = resolveEmployeeIdByName($cid, $name);

    try {
        if ($id) {
            $db->prepare("UPDATE case_staff_candidates
                          SET staff_name=?, employee_id=?, rep_name=?, rep_employee_id=?, affiliation=?,
                              alliance_id=?, skill_type=?, carrier=?, desired_price=?, available_from=?,
                              note=?, updated_at=NOW()
                          WHERE id=? AND company_id=?")
               ->execute([$name, $empId, $repName, $repEmpId, ($affil ?: null), $allianceId,
                          ($skill ?: null), ($carrier ?: null), $price, ($from ?: null),
                          ($note ?: null), $id, $cid]);
            echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        } else {
            $db->prepare("INSERT INTO case_staff_candidates
                          (company_id, staff_name, employee_id, rep_name, rep_employee_id, affiliation,
                           alliance_id, skill_type, carrier, desired_price, available_from, note)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$cid, $name, $empId, $repName, $repEmpId, ($affil ?: null), $allianceId,
                          ($skill ?: null), ($carrier ?: null), $price, ($from ?: null), ($note ?: null)]);
            echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()], JSON_UNESCAPED_UNICODE);
        }
    } catch (PDOException $e) {
        error_log('[case_staff_board save_staff] ' . $e->getMessage());
        echo json_encode(['error' => '保存に失敗しました']);
    }
    exit;
}

// ---- 人員の削除 ----
if ($action === 'delete_staff') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => '対象が見つかりません']); exit; }
    try {
        // 案件データは触らない。候補リストから外すだけ
        $db->prepare('UPDATE case_staff_candidates SET is_active = 0, updated_at = NOW()
                      WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[case_staff_board delete_staff] ' . $e->getMessage());
        echo json_encode(['error' => '削除に失敗しました']);
    }
    exit;
}

// ---- 案件にアサイン ----
// 選んだ案件（枠）の内容をコピーして、稼働者を入れた確定案件を1件つくる。
// 元の案件はそのまま残るので、必要人数の分だけ何人でもアサインできる。
if ($action === 'assign') {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $caseId  = (int)($_POST['case_id'] ?? 0);
    if (!$staffId || !$caseId) { echo json_encode(['error' => '人員と案件を選んでください']); exit; }

    $priceIn  = max(0, (int)($_POST['unit_price_in']  ?? 0));
    $priceOut = max(0, (int)($_POST['unit_price_out'] ?? 0));
    $days     = max(0, (int)($_POST['days_worked']    ?? 0));

    try {
        $s = $db->prepare("SELECT * FROM case_staff_candidates
                           WHERE id = ? AND company_id = ? AND is_active = 1");
        $s->execute([$staffId, $cid]);
        $staff = $s->fetch(PDO::FETCH_ASSOC);
        if (!$staff) { echo json_encode(['error' => '人員が見つかりません']); exit; }
        if ($staff['assign_status'] === 'アサイン済') {
            echo json_encode(['error' => 'この人員はすでにアサイン済みです']); exit;
        }

        $c = $db->prepare("SELECT * FROM sales_cases WHERE id = ? AND company_id = ?");
        $c->execute([$caseId, $cid]);
        $case = $c->fetch(PDO::FETCH_ASSOC);
        if (!$case) { echo json_encode(['error' => '案件が見つかりません']); exit; }

        // 案件の作成は既存の createSalesCase() をそのまま使う。
        // 金額の計算・年月の決定・担当者IDの解決まで既存ロジックに任せるので、
        // 常勤案件・イベント案件の画面から登録したものとまったく同じ形になる
        $newCase = [
            'case_type'      => $case['case_type'],
            'client_id'      => $case['client_id'],
            'start_date'     => $case['start_date'],
            'end_date'       => $case['end_date'],
            // 担当者は案件（枠）の値をそのまま引き継ぐ。
            // 人員側の担当営業をここに入れると担当者別売上の配分（営業50%+紹介元50%）が
            // 変わってしまうため、金額に関わる担当者は一切触らず備考に残すだけにする
            'sales_rep'      => (string)($case['sales_rep'] ?? ''),
            'manager'        => (string)($case['manager'] ?? ''),
            'recruiter'      => (string)($case['recruiter'] ?? ''),
            'worker_type'    => $case['worker_type'] ?: '正社員',
            'worker_name'    => $staff['staff_name'],
            // 外注先は案件の値を優先。案件が未設定のときだけ人員側の外注先を使う
            'alliance_id'    => $case['alliance_id'] ?: ($staff['alliance_id'] ?: null),
            'carrier'        => (string)($case['carrier'] ?? ''),
            'trade_name'     => (string)($case['trade_name'] ?? ''),
            'area_id'        => $case['area_id'],
            'store_name'     => (string)($case['store_name'] ?? ''),
            'unit_price_in'  => $priceIn,
            'unit_price_out' => $priceOut,
            'days_worked'    => $days,
            'status'         => 'confirmed',
            'note'           => '案件人員一覧からアサイン（人員担当: ' . $staff['rep_name'] . '）',
            'case_division'  => $case['case_division'] ?: null,
            'sales_rep_id'   => resolveEmployeeIdByName($cid, (string)($case['sales_rep'] ?? '')),
            'manager_id'     => resolveEmployeeIdByName($cid, (string)($case['manager'] ?? '')),
            'recruiter_id'   => resolveEmployeeIdByName($cid, (string)($case['recruiter'] ?? '')),
            'worker_employee_id' => $staff['employee_id'] ?: resolveEmployeeIdByName($cid, $staff['staff_name']),
        ];
        if ($case['case_type'] === 'regular') {
            $newCase['gross_profit_direct'] = $priceIn - $priceOut;
        }

        $db->beginTransaction();
        $newId = createSalesCase($cid, $newCase);
        $db->prepare("UPDATE case_staff_candidates
                      SET assign_status='アサイン済', assigned_case_id=?, created_case_id=?, updated_at=NOW()
                      WHERE id=? AND company_id=?")
           ->execute([$caseId, $newId, $staffId, $cid]);
        $db->commit();

        echo json_encode(['ok' => true, 'case_id' => $newId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[case_staff_board assign] ' . $e->getMessage());
        echo json_encode(['error' => 'アサインに失敗しました: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---- アサインの取り消し（候補に戻す） ----
// アサインで作られた確定案件も一緒に削除する。手で編集した案件を消さないよう、
// この画面が作った案件（created_case_id）だけを対象にする
if ($action === 'unassign') {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    if (!$staffId) { echo json_encode(['error' => '対象が見つかりません']); exit; }
    try {
        $s = $db->prepare("SELECT created_case_id FROM case_staff_candidates WHERE id = ? AND company_id = ?");
        $s->execute([$staffId, $cid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => '対象が見つかりません']); exit; }

        $db->beginTransaction();
        if (!empty($row['created_case_id'])) {
            deleteSalesCase((int)$row['created_case_id'], $cid);
        }
        $db->prepare("UPDATE case_staff_candidates
                      SET assign_status='検討中', assigned_case_id=NULL, created_case_id=NULL, updated_at=NOW()
                      WHERE id=? AND company_id=?")->execute([$staffId, $cid]);
        $db->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[case_staff_board unassign] ' . $e->getMessage());
        echo json_encode(['error' => '取り消しに失敗しました']);
    }
    exit;
}

echo json_encode(['error' => '不明な操作です']);
