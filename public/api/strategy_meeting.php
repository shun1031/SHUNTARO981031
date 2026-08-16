<?php
/**
 * 戦略会議 API
 *
 * この画面専用のデータ取得API。既存の案件データ（sales_cases）を読むだけで、
 * 既存テーブルへの書き込みは一切行わない。
 * 書き込みは戦略会議専用の strategy_meeting_memos（企業メモ）のみ。
 *
 * GET  ?action=reps      &fy=&division=            営業マンカード一覧
 * GET  ?action=companies &fy=&division=&rep=       担当企業一覧
 * GET  ?action=trend     &division=&client_id=     企業の年推移（期別）＋メモ
 * POST  action=save_memo  client_id, memo, csrf    企業メモの保存
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
// 戦略会議は管理者専用（支出管理・社員一覧と同じ扱い）
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }

$db = getDB();

// ----------------------------------------------------------------
// 共通ヘルパー
// ----------------------------------------------------------------

/**
 * 光AD列がまだ無い環境（マイグレーション未実行）でも動くようにする。
 * 列が無ければ「全件が光ADでない」として扱い、常勤/イベントの2区分で動作する。
 */
function smHasHikariAd(PDO $db): bool {
    static $has = null;
    if ($has !== null) return $has;
    $has = false;
    try {
        $has = (bool)$db->query("SHOW COLUMNS FROM sales_cases LIKE 'hikari_ad_flag'")->fetch();
    } catch (PDOException $e) {
        error_log('[strategy_meeting] ' . $e->getMessage());
    }
    return $has;
}

/** 区分（光AD / 常勤 / イベント）を求めるSQL式 */
function smDivisionExpr(PDO $db): string {
    $ad = smHasHikariAd($db) ? "WHEN sc.hikari_ad_flag = 1 THEN '光AD' " : '';
    return "CASE {$ad}WHEN sc.case_type = 'regular' THEN '常勤' ELSE 'イベント' END";
}

/** 区分での絞り込み条件（未指定・不明な値なら絞らない） */
function smDivisionCond(PDO $db, string $division): string {
    $adOff = smHasHikariAd($db) ? 'sc.hikari_ad_flag = 0 AND ' : '';
    if ($division === '光AD')     return smHasHikariAd($db) ? ' AND sc.hikari_ad_flag = 1' : ' AND 1 = 0';
    if ($division === '常勤')     return " AND {$adOff}sc.case_type = 'regular'";
    if ($division === 'イベント') return " AND {$adOff}sc.case_type = 'event'";
    return '';
}

/** 年度（9月始まり）の月範囲条件。パラメータは [$fy-1, $fy] を渡す */
const SM_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";

/** 年度の表示ラベル（例: 25.9-26.8） */
function smFyLabel(int $fy): string {
    return substr((string)($fy - 1), 2) . '.9-' . substr((string)$fy, 2) . '.8';
}

/** 年月から年度を求める（9〜12月は翌年度） */
function smFyOf(int $year, int $month): int {
    return $month >= 9 ? $year + 1 : $year;
}

// 担当者名を「社員IDがあればその社員、無ければ案件に入っている名前」で求める。
// ※既存の売上集計（総合ダッシュボード・担当者別売上）と完全に同じ判定にするため
const SM_REP_NAME  = "COALESCE(er.name, sc.sales_rep)";
const SM_REP_JOIN  = "LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id";
const SM_MGR_NAME  = "COALESCE(em.name, sc.manager)";
const SM_MGR_JOIN  = "LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id";

// ----------------------------------------------------------------
// POST: 企業メモの保存（戦略会議専用テーブルのみ）
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 保存は管理者のみ（他画面と同じ守り方に揃える）
    requireAdminWrite(true);
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }
    if (($_POST['action'] ?? '') !== 'save_memo') { echo json_encode(['error' => 'Unknown action']); exit; }

    $clientId = (int)($_POST['client_id'] ?? 0);
    $memo     = trim($_POST['memo'] ?? '');
    if (!$clientId) { echo json_encode(['error' => 'client_id required']); exit; }

    try {
        $stmt = $db->prepare("INSERT INTO strategy_meeting_memos (company_id, client_id, memo)
                              VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE memo = VALUES(memo), updated_at = NOW()");
        $stmt->execute([$cid, $clientId, $memo]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('[strategy_meeting save_memo] ' . $e->getMessage());
        echo json_encode(['error' => 'メモの保存に失敗しました']);
    }
    exit;
}

// ----------------------------------------------------------------
// GET 共通パラメータ
// ----------------------------------------------------------------
$action   = $_GET['action'] ?? '';
$division = (string)($_GET['division'] ?? '');
if (!in_array($division, ['光AD', '常勤', 'イベント'], true)) $division = '';

$today   = new DateTimeImmutable('today');
$curYear = (int)$today->format('Y');
$curMon  = (int)$today->format('n');

$fy = (int)($_GET['fy'] ?? 0);
if ($fy < 2000 || $fy > 2100) $fy = smFyOf($curYear, $curMon);

$divCond = smDivisionCond($db, $division);

try {

// ----------------------------------------------------------------
// action=reps : 営業マンカード一覧
// ----------------------------------------------------------------
if ($action === 'reps') {
    // 表示対象は「社員一覧で営業担当にチェックが入っている在籍中の正社員・自社外注」。
    // 既存関数をそのまま使うので、チェックの変更は次の表示から自動で反映される
    $repNames = getSalesRepCandidates($cid);

    // 営業マンカードは区分で絞り込まない（区分の切替は担当企業一覧にだけ効かせる）。
    // カードの売上金額は常に全区分の合計で、既存の売上集計と同じ数字になる
    $divCond = '';

    // --- 売上金額（今年度累計）: 既存の50/50分割と完全に同じロジック ---
    // 営業担当に50%、紹介元（管理者→採用者→直営業）に50%
    $revSql = "
        SELECT name, SUM(rev) AS revenue FROM (
            SELECT " . SM_REP_NAME . " AS name, FLOOR(sc.revenue / 2) AS rev
            FROM sales_cases sc " . SM_REP_JOIN . "
            WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.sales_rep != ''
              AND " . SM_FY_WHERE . " {$divCond}
            UNION ALL
            SELECT CASE WHEN em.name IS NOT NULL THEN em.name
                        WHEN COALESCE(sc.manager, '')   NOT IN ('', '該当者なし') THEN sc.manager
                        WHEN erc.name IS NOT NULL THEN erc.name
                        WHEN COALESCE(sc.recruiter, '') NOT IN ('', '該当者なし') THEN sc.recruiter
                        ELSE '直営業' END AS name,
                   sc.revenue - FLOOR(sc.revenue / 2) AS rev
            FROM sales_cases sc
            " . SM_MGR_JOIN . "
            LEFT JOIN employees erc ON erc.id = sc.recruiter_id AND erc.company_id = sc.company_id
            WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.sales_rep != ''
              AND " . SM_FY_WHERE . " {$divCond}
        ) t
        WHERE t.name NOT IN ('直営業', '', '該当者なし')
        GROUP BY t.name";
    $stmt = $db->prepare($revSql);
    $stmt->execute([$cid, $fy - 1, $fy, $cid, $fy - 1, $fy]);
    $revMap = [];
    foreach ($stmt->fetchAll() as $r) { $revMap[$r['name']] = (int)$r['revenue']; }

    // --- クライアント数（今年度・重複なし）: その営業マンが「営業担当」の案件の企業数 ---
    $clientSql = "
        SELECT " . SM_REP_NAME . " AS name, COUNT(DISTINCT sc.client_id) AS cnt
        FROM sales_cases sc " . SM_REP_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.client_id IS NOT NULL
          AND " . SM_FY_WHERE . " {$divCond}
        GROUP BY " . SM_REP_NAME;
    $stmt = $db->prepare($clientSql);
    $stmt->execute([$cid, $fy - 1, $fy]);
    $clientMap = [];
    foreach ($stmt->fetchAll() as $r) { $clientMap[$r['name']] = (int)$r['cnt']; }

    // --- アライアンス数（今年度・重複なし） ---
    // 判定基準は「管理者」。スタッフ区分がアライアンスの案件に出てくる外注先の社数
    $allianceSql = "
        SELECT " . SM_MGR_NAME . " AS name, COUNT(DISTINCT sc.alliance_id) AS cnt
        FROM sales_cases sc " . SM_MGR_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND sc.worker_type = 'アライアンス' AND sc.alliance_id IS NOT NULL
          AND " . SM_FY_WHERE . " {$divCond}
        GROUP BY " . SM_MGR_NAME;
    $stmt = $db->prepare($allianceSql);
    $stmt->execute([$cid, $fy - 1, $fy]);
    $allianceMap = [];
    foreach ($stmt->fetchAll() as $r) {
        $n = trim((string)$r['name']);
        if ($n === '' || $n === '該当者なし') continue;
        $allianceMap[$n] = (int)$r['cnt'];
    }

    $reps = [];
    foreach ($repNames as $name) {
        $reps[] = [
            'name'           => $name,
            'client_count'   => $clientMap[$name]   ?? 0,
            'alliance_count' => $allianceMap[$name] ?? 0,
            'revenue'        => $revMap[$name]      ?? 0,
        ];
    }
    // 売上の多い順（画像と同じく実績順に並べる）
    usort($reps, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    echo json_encode([
        'reps'     => $reps,
        'fy'       => $fy,
        'fy_label' => smFyLabel($fy),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=companies : ある営業マンの担当企業一覧
// ----------------------------------------------------------------
if ($action === 'companies') {
    $rep = trim($_GET['rep'] ?? '');
    if ($rep === '') { echo json_encode(['error' => 'rep required']); exit; }

    $divExpr = smDivisionExpr($db);
    // 企業は「取引先マスタの表記名」で表示する。
    // 取引先一覧で表記名を変えれば、ここもJOINで引くので次の表示から反映される
    $sql = "
        SELECT cl.id AS client_id,
               COALESCE(NULLIF(TRIM(cl.display_name), ''), cl.client_name) AS client_name,
               {$divExpr} AS division,
               COUNT(*) AS frame_count,
               COALESCE(SUM(CASE WHEN sc.case_year = ? AND sc.case_month = ? THEN sc.revenue ELSE 0 END), 0) AS month_revenue,
               COALESCE(SUM(sc.revenue), 0) AS fy_revenue
        FROM sales_cases sc
        JOIN sales_clients cl ON sc.client_id = cl.id
        " . SM_REP_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND " . SM_FY_WHERE . "
          AND " . SM_REP_NAME . " = ?
          {$divCond}
        GROUP BY cl.id, cl.display_name, cl.client_name, {$divExpr}
        ORDER BY month_revenue DESC, fy_revenue DESC, client_name";
    $stmt = $db->prepare($sql);
    $stmt->execute([$curYear, $curMon, $cid, $fy - 1, $fy, $rep]);

    $companies = [];
    foreach ($stmt->fetchAll() as $r) {
        $companies[] = [
            'client_id'     => (int)$r['client_id'],
            // 光ADの行は会社名の末尾に（光AD）を付ける
            'label'         => $r['division'] === '光AD' ? $r['client_name'] . '（光AD）' : $r['client_name'],
            'division'      => $r['division'],
            'frame_count'   => (int)$r['frame_count'],
            // 単位: イベントは「コマ」、それ以外は「枠」（既存ダッシュボードと同じ呼び分け）
            'frame_unit'    => $r['division'] === 'イベント' ? 'コマ' : '枠',
            'month_revenue' => (int)$r['month_revenue'],
            'fy_revenue'    => (int)$r['fy_revenue'],
        ];
    }

    echo json_encode([
        'rep'         => $rep,
        'companies'   => $companies,
        'month_label' => $curYear . '年' . $curMon . '月',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=trend : 企業の年推移（期別）
// ----------------------------------------------------------------
if ($action === 'trend') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    if (!$clientId) { echo json_encode(['error' => 'client_id required']); exit; }

    $nameStmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(display_name), ''), client_name) AS n
                              FROM sales_clients WHERE id = ? AND company_id = ?");
    $nameStmt->execute([$clientId, $cid]);
    $clientName = (string)($nameStmt->fetchColumn() ?: '');
    if ($clientName === '') { echo json_encode(['error' => '取引先が見つかりません']); exit; }

    // 年推移は「その営業マンが営業担当の案件」だけを見る（営業マンの詳細として見るため）。
    // 1社を2人で担当することは基本的に無いので、実際は会社全体の推移とほぼ同じになる
    $rep     = trim($_GET['rep'] ?? '');
    $repJoin = $rep !== '' ? SM_REP_JOIN : '';
    $repCond = $rep !== '' ? ' AND ' . SM_REP_NAME . ' = ?' : '';

    $sql = "
        SELECT sc.case_year, sc.case_month,
               COALESCE(SUM(sc.revenue), 0) AS revenue,
               COUNT(*) AS frame_count
        FROM sales_cases sc {$repJoin}
        WHERE sc.company_id = ? AND sc.client_id = ? AND sc.status = 'confirmed'
          {$divCond}{$repCond}
        GROUP BY sc.case_year, sc.case_month";
    $stmt = $db->prepare($sql);
    $stmt->execute($rep !== '' ? [$cid, $clientId, $rep] : [$cid, $clientId]);

    // 月別の実績を年度（9月〜翌8月）ごとにまとめる
    $byFy = [];
    foreach ($stmt->fetchAll() as $r) {
        $f = smFyOf((int)$r['case_year'], (int)$r['case_month']);
        if (!isset($byFy[$f])) $byFy[$f] = ['revenue' => 0, 'frame_count' => 0];
        $byFy[$f]['revenue']     += (int)$r['revenue'];
        $byFy[$f]['frame_count'] += (int)$r['frame_count'];
    }
    ksort($byFy);

    // データがある最初の期から最後の期まで、間の空白期も0で埋めて連続表示する
    $periods = [];
    if ($byFy) {
        $keys  = array_keys($byFy);
        $first = (int)$keys[0];
        $last  = (int)end($keys);
        for ($f = $first; $f <= $last; $f++) {
            $periods[] = [
                'fy'          => $f,
                'label'       => smFyLabel($f),
                'revenue'     => $byFy[$f]['revenue']     ?? 0,
                'frame_count' => $byFy[$f]['frame_count'] ?? 0,
            ];
        }
    }

    // 区分は指定されたものを表示。未指定なら実際に登録されている区分を並べる
    $divLabel = $division;
    if ($divLabel === '') {
        $dvStmt = $db->prepare("SELECT DISTINCT " . smDivisionExpr($db) . " AS d
                                FROM sales_cases sc {$repJoin}
                                WHERE sc.company_id = ? AND sc.client_id = ? AND sc.status = 'confirmed'
                                  {$repCond}");
        $dvStmt->execute($rep !== '' ? [$cid, $clientId, $rep] : [$cid, $clientId]);
        $divLabel = implode(' / ', $dvStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // メモ（企業ごとに1件）
    $memo = '';
    try {
        $mStmt = $db->prepare('SELECT memo FROM strategy_meeting_memos WHERE company_id = ? AND client_id = ?');
        $mStmt->execute([$cid, $clientId]);
        $memo = (string)($mStmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        // テーブル未作成の環境ではメモなしとして扱う（画面は表示できる）
        error_log('[strategy_meeting memo] ' . $e->getMessage());
    }

    echo json_encode([
        'client_id'    => $clientId,
        'client_name'  => $division === '光AD' ? $clientName . '（光AD）' : $clientName,
        'division'     => $divLabel,
        'frame_unit'   => $division === 'イベント' ? 'コマ' : '枠',
        'periods'      => $periods,
        'memo'         => $memo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('[strategy_meeting] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'データの取得に失敗しました']);
}
