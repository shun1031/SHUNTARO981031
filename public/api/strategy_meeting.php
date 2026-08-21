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

// hikari_ad_flag / case_division は起動時マイグレーションで必ず追加される。
// マイグレーションはWebサーバーの起動前に実行されるため、画面が開ける時点で列は存在する。
// 以前は列の有無を確認してから使っていたが、確認の失敗を「列が無い」と誤って解釈し、
// 光ADが常勤に混ざったまま静かに表示される不具合を招いたため、確認をやめて直接使う。
// 万一列が無い場合はSQLエラーになり、画面に「データの取得に失敗しました」と出る。

/** 区分（光AD / 常勤 / イベント）を求めるSQL式 */
function smDivisionExpr(): string {
    return "CASE WHEN sc.hikari_ad_flag = 1 THEN '光AD'
                 WHEN sc.case_type = 'regular' THEN '常勤'
                 ELSE 'イベント' END";
}

/** 区分での絞り込み条件（未指定・不明な値なら絞らない） */
function smDivisionCond(string $division): string {
    if ($division === '光AD')     return ' AND sc.hikari_ad_flag = 1';
    if ($division === '常勤')     return " AND sc.hikari_ad_flag = 0 AND sc.case_type = 'regular'";
    if ($division === 'イベント') return " AND sc.hikari_ad_flag = 0 AND sc.case_type = 'event'";
    return '';
}

/**
 * 枠数の数え方。既存の総合ダッシュボード「月別枠数」と同じく
 * 区分が1次・2次以降の案件だけを数える
 */
function smFrameCountExpr(): string {
    return "SUM(CASE WHEN sc.case_division IN ('1次', '2次以降') THEN 1 ELSE 0 END)";
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

/**
 * 集計期間の絞り込み条件を組み立てる（年度合計 / 月合計）
 * どちらも「?」は2個なので、渡す値だけが変わる。集計そのものは変えない。
 * @return array{0:string,1:array,2:string} [条件SQL, パラメータ, 表示ラベル]
 */
function smPeriod(string $period, int $year, int $month): array {
    if ($period === 'fy') {
        $fy = smFyOf($year, $month);
        return [SM_FY_WHERE, [$fy - 1, $fy], '年度（' . smFyLabel($fy) . '）'];
    }
    return ['(sc.case_year = ? AND sc.case_month = ?)', [$year, $month], $year . '年' . $month . '月'];
}

/**
 * 集計期間の「終わりの年月」YYYYMM。
 * パートナー数・パートナー候補数は「その時点でどの状態か」で数えるので、
 * 期間の終端を決めて、そこまでに起きたステータス変更だけを見る。
 *   月別 … その年月       / 年度 … その年度の8月
 */
function smPeriodEndYm(string $period, int $year, int $month): int {
    if ($period === 'fy') return smFyOf($year, $month) * 100 + 8;
    return $year * 100 + $month;
}

/** 年度の始まり（9月）の YYYYMM */
function smFyStartYm(int $year, int $month): int {
    return (smFyOf($year, $month) - 1) * 100 + 9;
}

/** 商談報告で選べる区分（候補一覧の表示用。案件が無い会社は案件から区分を作れないため） */
const SM_DIVISIONS = ['光AD', '常勤', 'イベント'];

// 担当者名を「社員IDがあればその社員、無ければ案件に入っている名前」で求める。
// ※既存の売上集計（総合ダッシュボード・担当者別売上）と完全に同じ判定にするため
const SM_REP_NAME  = "COALESCE(er.name, sc.sales_rep)";
const SM_REP_JOIN  = "LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id";
const SM_MGR_NAME  = "COALESCE(em.name, sc.manager)";
const SM_MGR_JOIN  = "LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id";

/**
 * 会社名の表記ゆれを吸収して突き合わせ用のキーにする。
 * 会社数の集計は取引先IDで行うようになったため、この関数はもう集計には使わない。
 * 商談報告の client_name_key（過去データとの互換のために残している列）を作るためだけに使う。
 */
function smNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    // a = 全角英数字→半角 / K = 半角カナ→全角カナ / V = 濁点付き半角カナを1文字にまとめる
    // ※画面に出す会社名は入力どおりのまま。ここで作るのは突き合わせ用の値だけ
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'aKV');
    return mb_strtolower($n, 'UTF-8');
}

/**
 * 会社数を数えるときの「会社の識別キー」。
 *
 * 以前は会社名の文字列で突き合わせていたため、取引先一覧で表記名を変えると
 * 商談報告と一致しなくなり、同じ会社が2社に数えられてしまっていた。
 * そこで取引先マスタのIDで数える方式に変更した。IDは名前を変えても変わらないので、
 * 取引先一覧をいくら編集しても会社数は崩れない。
 */
function smClientKey(int $clientId): string {
    return 'C' . $clientId;
}

/**
 * 外注先の識別キー。
 * 外注先マスタの「同じ会社の取引先」が指定されていれば、その取引先と同じキーを返す
 * （＝取引先にも外注先にも登録されている会社を1社として数える）。
 * 指定が無い外注先は、それ単独の会社として数える。
 */
function smAllianceKey(int $allianceId, $linkedClientId): string {
    $linked = (int)$linkedClientId;
    return $linked > 0 ? smClientKey($linked) : 'A' . $allianceId;
}

/**
 * 外注先ID → 紐づけ先の取引先ID の対応表。
 * client_id 列が無い環境でも動くように、失敗しても空配列を返す
 */
function smAllianceClientMap(PDO $db, int $companyId): array {
    try {
        $stmt = $db->prepare('SELECT id, client_id FROM sales_alliances WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id']] = $r['client_id'] !== null ? (int)$r['client_id'] : null;
        }
        return $map;
    } catch (PDOException $e) {
        error_log('[strategy_meeting alliance map] ' . $e->getMessage());
        return [];
    }
}

/**
 * 商談報告を営業担当者ごとに仕分けする。
 *
 * パートナー数・パートナー候補数は「期間の終端の時点でどの状態か」で数える。
 * ステータスが変わった年月（candidate_ym / active_ym / excluded_ym）を見るので、
 * あとから過去の月の数字が変わることはない。
 *
 * 会社の識別は取引先ID（smClientKey）。案件側と同じ鍵なので、
 * 同じ会社を商談報告と案件の両方から数えても二重にならない。
 *
 * @return array<string, array{partners:array,candidates:array,month_new:int,total_new:int}>
 */
function smNegotiationsByRep(PDO $db, int $companyId, int $endYm, int $targetYm, int $fyStartYm): array {
    // 担当者は strategy_meeting_negotiation_reps から読む。
    // 1社を2人で担当している場合は2行返り、両方のカードにその会社が出る。
    // 会社としては1社のままなので、グラフ・表・上部の社数は変わらない
    $stmt = $db->prepare("SELECT r.rep_name, n.client_id, n.division, n.note,
                                 n.first_report_ym, n.candidate_ym, n.active_ym, n.excluded_ym,
                                 COALESCE(" . clientLabelSql('cl') . ", n.client_name) AS client_name
                          FROM strategy_meeting_negotiation_reps r
                          JOIN strategy_meeting_negotiations n
                            ON n.id = r.negotiation_id AND n.company_id = r.company_id
                          LEFT JOIN sales_clients cl ON cl.id = n.client_id AND cl.company_id = n.company_id
                          WHERE r.company_id = ?");
    $stmt->execute([$companyId]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $rep = trim((string)$r['rep_name']);
        // 営業担当者が入っていない行は、誰のカードにも出しようがないので飛ばす
        if ($rep === '') continue;
        if (!isset($out[$rep])) {
            $out[$rep] = ['partners' => [], 'candidates' => [], 'month_new' => 0, 'total_new' => 0];
        }

        // 新規商談数は「初回登録の年月」で数える。累計の起点は年度の始まり（9月）
        $first = (int)$r['first_report_ym'];
        if ($first === $targetYm)                            $out[$rep]['month_new']++;
        if ($first >= $fyStartYm && $first <= $targetYm)      $out[$rep]['total_new']++;

        // 取引先が選ばれていない古い行は会社の鍵が作れないため、社数には数えない
        if ($r['client_id'] === null) continue;

        $cand     = $r['candidate_ym'] !== null ? (int)$r['candidate_ym'] : null;
        $active   = $r['active_ym']    !== null ? (int)$r['active_ym']    : null;
        $excluded = $r['excluded_ym']  !== null ? (int)$r['excluded_ym']  : null;

        // その時点で会社数から外れていれば、パートナーにも候補にも数えない
        if ($excluded !== null && $excluded <= $endYm) continue;

        $key = smClientKey((int)$r['client_id']);
        $row = [
            'client_id'   => (int)$r['client_id'],
            'client_name' => (string)$r['client_name'],
            'division'    => (string)$r['division'],
            'note'        => (string)($r['note'] ?? ''),
        ];
        if ($active !== null && $active <= $endYm) {
            $out[$rep]['partners'][$key] = $row;
        } elseif ($cand !== null && $cand <= $endYm) {
            $out[$rep]['candidates'][$key] = $row;
        }
    }
    return $out;
}

/**
 * 期間内に案件がある会社を、営業担当者ごとの「会社の鍵」の集合にする。
 * 取引先は営業担当基準、外注先（アライアンス）は管理者基準。
 * この使い分けは既存の全社集計（action=summary）と同じ。
 *
 * @return array<string, array<string, true>> 営業担当者名 => [会社の鍵 => true]
 */
function smCaseKeysByRep(PDO $db, int $companyId, string $perWhere, array $perParams, array $allyMap): array {
    $out = [];
    $add = function (string $name, string $key) use (&$out) {
        $n = trim($name);
        if ($n === '' || $n === '該当者なし') return;
        $out[$n][$key] = true;
    };

    // 取引先（営業担当基準）
    $stmt = $db->prepare("SELECT DISTINCT " . SM_REP_NAME . " AS name, sc.client_id
        FROM sales_cases sc " . SM_REP_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.client_id IS NOT NULL
          AND {$perWhere}");
    $stmt->execute(array_merge([$companyId], $perParams));
    foreach ($stmt->fetchAll() as $r) {
        $add((string)$r['name'], smClientKey((int)$r['client_id']));
    }

    // 外注先（管理者基準）
    $stmt = $db->prepare("SELECT DISTINCT " . SM_MGR_NAME . " AS name, sc.alliance_id
        FROM sales_cases sc " . SM_MGR_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND sc.worker_type = 'アライアンス' AND sc.alliance_id IS NOT NULL
          AND {$perWhere}");
    $stmt->execute(array_merge([$companyId], $perParams));
    foreach ($stmt->fetchAll() as $r) {
        $aid = (int)$r['alliance_id'];
        $add((string)$r['name'], smAllianceKey($aid, $allyMap[$aid] ?? null));
    }
    return $out;
}

/**
 * 営業マン1人分の4つの数字を作る。
 * 営業マンカードと担当企業一覧の両方から呼ぶので、両者の数字が食い違わない。
 *
 * パートナー数     … 期間内に案件がある会社 ＋ 商談報告で「取引開始」の会社（重複なし）
 * パートナー候補数 … 商談報告で「取引候補」の会社。すでにパートナーの会社は数えない
 * 当月新規商談数   … 対象年月に初回登録された商談報告の件数
 * 累計新規商談数   … 年度の始まり（9月）から対象年月までの初回登録件数
 */
function smRepCounts(array $caseKeys, ?array $neg): array {
    $neg = $neg ?? ['partners' => [], 'candidates' => [], 'month_new' => 0, 'total_new' => 0];

    $partners = $caseKeys;
    foreach ($neg['partners'] as $k => $_) { $partners[$k] = true; }

    $candidates = [];
    foreach ($neg['candidates'] as $k => $_) {
        if (!isset($partners[$k])) $candidates[$k] = true;
    }

    return [
        'partner_count'   => count($partners),
        'candidate_count' => count($candidates),
        'month_neg_count' => (int)$neg['month_new'],
        'total_neg_count' => (int)$neg['total_new'],
    ];
}

/**
 * 商談報告の担当者を入れ替える（いったん全部消してから入れ直す）。
 * 商談報告そのもの（会社・ステータス・年月）には触れないので、
 * パートナー数・パートナー候補数は変わらない。
 */
function smSaveNegotiationReps(PDO $db, int $companyId, int $negotiationId, array $repNames): void {
    $db->prepare('DELETE FROM strategy_meeting_negotiation_reps WHERE negotiation_id = ? AND company_id = ?')
       ->execute([$negotiationId, $companyId]);
    if (!$repNames) return;
    $ins = $db->prepare('INSERT INTO strategy_meeting_negotiation_reps
        (company_id, negotiation_id, rep_name, rep_employee_id) VALUES (?,?,?,?)');
    foreach ($repNames as $rn) {
        $ins->execute([$companyId, $negotiationId, $rn, resolveEmployeeIdByName($companyId, $rn)]);
    }
}

/** 目標企業数を取得（未設定なら既定の100社） */
function smTargetCount(PDO $db, int $companyId): int {
    try {
        $stmt = $db->prepare('SELECT target_client_count FROM strategy_meeting_settings WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $v = $stmt->fetchColumn();
        if ($v !== false) return (int)$v;
    } catch (PDOException $e) {
        error_log('[strategy_meeting target] ' . $e->getMessage());
    }
    return 100;
}

/** 商談報告で選べるステータス */
const SM_STATUSES = ['取引開始', '取引候補', '温度感低め', '合わない', '倒産', 'その他'];

/** 会社数に含めるステータス（取引候補以上） */
const SM_COUNTED_STATUSES = ['取引開始', '取引候補'];

/** 年月を YYYYMM の整数にする。不正なら null */
function smYm(?int $year, ?int $month): ?int {
    if (!$year || !$month || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) return null;
    return $year * 100 + $month;
}

// ----------------------------------------------------------------
// POST: 企業メモ・目標企業数・商談報告の保存（戦略会議専用テーブルのみ）
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 保存は管理者のみ（他画面と同じ守り方に揃える）
    requireAdminWrite(true);
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }
    $postAction = $_POST['action'] ?? '';

    // --- 目標企業数の保存 ---
    if ($postAction === 'save_target') {
        $target = (int)($_POST['target'] ?? 0);
        if ($target < 1 || $target > 100000) { echo json_encode(['error' => '1〜100000の数値を入力してください']); exit; }
        try {
            $stmt = $db->prepare("INSERT INTO strategy_meeting_settings (company_id, target_client_count)
                                  VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE target_client_count = VALUES(target_client_count), updated_at = NOW()");
            $stmt->execute([$cid, $target]);
            echo json_encode(['success' => true, 'target' => $target]);
        } catch (PDOException $e) {
            error_log('[strategy_meeting save_target] ' . $e->getMessage());
            echo json_encode(['error' => '目標企業数の保存に失敗しました']);
        }
        exit;
    }

    // --- 商談報告の保存（新規登録 / 既存の書き換え） ---
    // 1社につき1件。既存の内容を書き換えられるのは、一覧の編集ボタンから開いたとき（id あり）だけ。
    // 「＋商談報告」からの新規登録（id なし）で既に登録済みの会社を入れた場合は、
    // 気づかないうちに担当者や備考が上書きされるのを防ぐため、保存せずにエラーを返す。
    // ステータスが変わったときは「いつ変わったか」の年月を記録し、過去の月の集計が
    // あとから変わらないようにする
    if ($postAction === 'save_negotiation') {
        $id         = (int)($_POST['id'] ?? 0);
        $clientId   = (int)($_POST['client_id'] ?? 0);
        // 担当者は複数選べる。社員一覧で営業担当にチェックがある人だけを受け付ける
        $repInput = $_POST['rep_names'] ?? ($_POST['rep_name'] ?? []);
        if (!is_array($repInput)) $repInput = [$repInput];
        $repAllowed = array_flip(getSalesRepCandidates($cid));
        $repNames = [];
        foreach ($repInput as $rn) {
            $rn = trim((string)$rn);
            if ($rn !== '' && isset($repAllowed[$rn]) && !in_array($rn, $repNames, true)) $repNames[] = $rn;
        }
        // 一覧・互換用に先頭の担当者を rep_name 列にも残す（集計はもう読まない）
        $repName    = $repNames[0] ?? '';
        $status     = trim($_POST['status'] ?? '');
        $statusOther= trim($_POST['status_other'] ?? '');
        $note       = trim($_POST['note'] ?? '');
        // 区分（光AD/常勤/イベント）。候補一覧に出すためだけの項目なので未選択でもよい
        $negDivision = trim($_POST['division'] ?? '');
        if (!in_array($negDivision, SM_DIVISIONS, true)) $negDivision = '';
        // 変更が起きた年月。まとめて入力するときのために過去の月も指定できる
        $ym = smYm((int)($_POST['ym_year'] ?? 0), (int)($_POST['ym_month'] ?? 0));

        // 会社は取引先一覧から選ぶ方式。手入力を受け付けないので表記ゆれが起きない
        if (!$clientId)                              { echo json_encode(['error' => '会社を取引先一覧から選んでください']); exit; }
        if (!$repNames)                              { echo json_encode(['error' => '営業担当者を1人以上選んでください']); exit; }
        if (!in_array($status, SM_STATUSES, true))   { echo json_encode(['error' => 'ステータスを選択してください']); exit; }
        if ($status === 'その他' && $statusOther === '') { echo json_encode(['error' => '「その他」の内容を入力してください']); exit; }
        if ($ym === null)                            { echo json_encode(['error' => '対象年月を選択してください']); exit; }

        // 選ばれた取引先が自社のものか確認し、会社名は取引先マスタの表記名から取る
        $clRow = getSalesClient($clientId, $cid);
        if (!$clRow) { echo json_encode(['error' => '選ばれた取引先が見つかりません']); exit; }
        $clientName = clientLabel($clRow);
        $key = smNameKey($clientName);

        $counted = in_array($status, SM_COUNTED_STATUSES, true);

        try {
            // 編集対象は「一覧の編集ボタンから開いたレコード」だけ。
            // 新規登録（id なし）では既存を拾わないので、勝手な上書きが起こらない
            $cur = null;
            if ($id) {
                $st = $db->prepare('SELECT * FROM strategy_meeting_negotiations WHERE id = ? AND company_id = ?');
                $st->execute([$id, $cid]);
                $cur = $st->fetch() ?: null;
                if (!$cur) { echo json_encode(['error' => '対象の商談報告が見つかりません']); exit; }
            }

            // 同じ取引先が既に登録されていないか確認する（IDで判定するので表記ゆれの影響を受けない）。
            // 新規登録なら1件でもあればエラー。編集なら自分以外にあればエラー
            $dup = $db->prepare('SELECT client_name FROM strategy_meeting_negotiations
                                 WHERE company_id = ? AND client_id = ? AND id <> ?');
            $dup->execute([$cid, $clientId, $cur ? (int)$cur['id'] : 0]);
            $dupName = $dup->fetchColumn();
            if ($dupName !== false) {
                echo json_encode([
                    'error' => '「' . $dupName . '」はすでに登録されています。'
                             . '内容を変更する場合は一覧の編集ボタンから行ってください。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!$cur) {
                // --- 新規登録 ---
                $ins = $db->prepare("INSERT INTO strategy_meeting_negotiations
                    (company_id, client_id, client_name, client_name_key, rep_name, rep_employee_id,
                     status, status_other, division, note, first_report_ym, candidate_ym, active_ym, excluded_ym)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $cid, $clientId, $clientName, $key, $repName,
                    resolveEmployeeIdByName($cid, $repName),
                    $status, ($status === 'その他' ? $statusOther : null), $negDivision, ($note !== '' ? $note : null),
                    $ym,
                    $counted ? $ym : null,
                    $status === '取引開始' ? $ym : null,
                    $counted ? null : $ym,
                ]);
                $newId = (int)$db->lastInsertId();
                smSaveNegotiationReps($db, $cid, $newId, $repNames);
                echo json_encode(['success' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // --- 既存の書き換え ---
            // 候補化・取引開始の年月は「最初になった月」を残す（後から遡れるよう小さい方を採用）
            $candidateYm = $cur['candidate_ym'] !== null ? (int)$cur['candidate_ym'] : null;
            $activeYm    = $cur['active_ym']    !== null ? (int)$cur['active_ym']    : null;
            $excludedYm  = $cur['excluded_ym']  !== null ? (int)$cur['excluded_ym']  : null;

            if ($counted) {
                $candidateYm = $candidateYm === null ? $ym : min($candidateYm, $ym);
                if ($status === '取引開始') {
                    $activeYm = $activeYm === null ? $ym : min($activeYm, $ym);
                }
                $excludedYm = null;              // 会社数に復帰する
            } else {
                $excludedYm = $ym;               // この月から会社数・取引有会社数から外れる
            }

            $upd = $db->prepare("UPDATE strategy_meeting_negotiations
                SET client_id = ?, client_name = ?, client_name_key = ?, rep_name = ?, rep_employee_id = ?,
                    status = ?, status_other = ?, division = ?, note = ?,
                    candidate_ym = ?, active_ym = ?, excluded_ym = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?");
            $upd->execute([
                $clientId, $clientName, $key, $repName, resolveEmployeeIdByName($cid, $repName),
                $status, ($status === 'その他' ? $statusOther : null), $negDivision, ($note !== '' ? $note : null),
                $candidateYm, $activeYm, $excludedYm,
                (int)$cur['id'], $cid,
            ]);
            smSaveNegotiationReps($db, $cid, (int)$cur['id'], $repNames);
            echo json_encode(['success' => true, 'id' => (int)$cur['id']], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            error_log('[strategy_meeting save_negotiation] ' . $e->getMessage());
            echo json_encode(['error' => '商談報告の保存に失敗しました']);
        }
        exit;
    }

    // --- 月別目標（会社数目標 / 取引有会社数目標）の保存 ---
    if ($postAction === 'save_monthly_target') {
        $tYear  = (int)($_POST['t_year']  ?? 0);
        $tMonth = (int)($_POST['t_month'] ?? 0);
        $field  = $_POST['field'] ?? '';
        $value  = max(0, (int)($_POST['value'] ?? 0));
        if (smYm($tYear, $tMonth) === null) { echo json_encode(['error' => '年月が不正です']); exit; }
        if (!in_array($field, ['company', 'active'], true)) { echo json_encode(['error' => 'Unknown field']); exit; }

        $col = $field === 'company' ? 'target_company_count' : 'target_active_count';
        try {
            // 片方だけ更新しても、もう片方の値が消えないようにする
            $stmt = $db->prepare("INSERT INTO strategy_meeting_monthly_targets (company_id, year, month, {$col})
                                  VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE {$col} = VALUES({$col}), updated_at = NOW()");
            $stmt->execute([$cid, $tYear, $tMonth, $value]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('[strategy_meeting save_monthly_target] ' . $e->getMessage());
            echo json_encode(['error' => '目標の保存に失敗しました']);
        }
        exit;
    }

    // --- 商談報告の削除 ---
    if ($postAction === 'delete_negotiation') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'id required']); exit; }
        try {
            // 担当者の行も一緒に消す（残しても読まれないが、ゴミを残さない）
            $db->prepare('DELETE FROM strategy_meeting_negotiation_reps WHERE negotiation_id = ? AND company_id = ?')->execute([$id, $cid]);
            $db->prepare('DELETE FROM strategy_meeting_negotiations WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('[strategy_meeting delete_negotiation] ' . $e->getMessage());
            echo json_encode(['error' => '商談報告の削除に失敗しました']);
        }
        exit;
    }

    // --- 企業メモの保存 ---
    if ($postAction !== 'save_memo') { echo json_encode(['error' => 'Unknown action']); exit; }

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

// 集計期間: month=月合計 / fy=年度合計。既定は月合計
$period = ($_GET['period'] ?? '') === 'fy' ? 'fy' : 'month';

// 対象年月。未指定なら前月（当月は月末まで案件が登録されないため）
$today  = new DateTimeImmutable('today');
$pYear  = (int)($_GET['year']  ?? 0);
$pMonth = (int)($_GET['month'] ?? 0);
if ($pYear < 2000 || $pYear > 2100 || $pMonth < 1 || $pMonth > 12) {
    $prev   = $today->modify('first day of last month');
    $pYear  = (int)$prev->format('Y');
    $pMonth = (int)$prev->format('n');
}

[$perWhere, $perParams, $perLabel] = smPeriod($period, $pYear, $pMonth);

$divCond = smDivisionCond($division);

try {

// ----------------------------------------------------------------
// action=trend_companies : 会社数の年間推移（9月〜翌8月）
// ----------------------------------------------------------------
// 商談報告と「既に案件がある会社」を取引先IDで突き合わせ、同じ会社を二重に数えない。
// 外注先は「同じ会社の取引先」が指定されていれば、その取引先と同じ会社として扱う。
// IDで突き合わせるので、取引先一覧で会社名・表記名を変えても集計は崩れない。
// 候補化・取引開始・除外の年月を記録してあるので、月ごとの実績を正確に出せる。
if ($action === 'trend_companies') {
    $fy       = smFyOf($pYear, $pMonth);
    $fyMonths = [];
    for ($m = 9; $m <= 12; $m++) $fyMonths[] = ($fy - 1) * 100 + $m;
    for ($m = 1; $m <= 8;  $m++) $fyMonths[] = $fy * 100 + $m;

    // --- 商談報告 ---
    // 取引先が選ばれていない古い行（client_id が空）は、従来どおり会社名キーで数える
    $negs = [];
    $stmt = $db->prepare('SELECT client_id, client_name_key, first_report_ym, candidate_ym, active_ym, excluded_ym
                          FROM strategy_meeting_negotiations WHERE company_id = ?');
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll() as $r) {
        $k = $r['client_id'] !== null ? smClientKey((int)$r['client_id']) : 'N' . $r['client_name_key'];
        $negs[$k] = [
            'first'    => (int)$r['first_report_ym'],
            'cand'     => $r['candidate_ym'] !== null ? (int)$r['candidate_ym'] : null,
            'active'   => $r['active_ym']    !== null ? (int)$r['active_ym']    : null,
            'excluded' => $r['excluded_ym']  !== null ? (int)$r['excluded_ym']  : null,
        ];
    }

    // --- 既に案件がある会社（初回の案件年月から「取引開始」として扱う） ---
    // 案件があるということは実際に取引しているので、除外はかけない。
    // 集計条件は画面上部の「〇〇社 / 目標社数」と完全に揃える:
    //   取引先は営業担当基準、外注先は管理者基準、対象は今年度の案件のみ
    $repNames  = getSalesRepCandidates($cid);
    $allyMap   = smAllianceClientMap($db, $cid);
    $caseFirst = [];
    if ($repNames) {
        $repPh    = implode(',', array_fill(0, count($repNames), '?'));
        $fyParams = [$fy - 1, $fy];

        // 取引先（クライアント）
        $stmt = $db->prepare("SELECT cl.id AS client_id,
                                     MIN(sc.case_year * 100 + sc.case_month) AS first_ym
                              FROM sales_cases sc
                              JOIN sales_clients cl ON sc.client_id = cl.id
                              " . SM_REP_JOIN . "
                              WHERE sc.company_id = ? AND sc.status = 'confirmed'
                                AND " . SM_FY_WHERE . "
                                AND " . SM_REP_NAME . " IN ({$repPh})
                              GROUP BY cl.id");
        $stmt->execute(array_merge([$cid], $fyParams, $repNames));
        foreach ($stmt->fetchAll() as $r) {
            $k  = smClientKey((int)$r['client_id']);
            $ym = (int)$r['first_ym'];
            if (!isset($caseFirst[$k]) || $ym < $caseFirst[$k]) $caseFirst[$k] = $ym;
        }

        // 外注先（アライアンス）。商談報告の対象外なので常に「取引開始」として扱う
        $stmt = $db->prepare("SELECT al.id AS alliance_id,
                                     MIN(sc.case_year * 100 + sc.case_month) AS first_ym
                              FROM sales_cases sc
                              JOIN sales_alliances al ON sc.alliance_id = al.id
                              " . SM_MGR_JOIN . "
                              WHERE sc.company_id = ? AND sc.status = 'confirmed'
                                AND sc.worker_type = 'アライアンス'
                                AND " . SM_FY_WHERE . "
                                AND " . SM_MGR_NAME . " IN ({$repPh})
                              GROUP BY al.id");
        $stmt->execute(array_merge([$cid], $fyParams, $repNames));
        foreach ($stmt->fetchAll() as $r) {
            $aid = (int)$r['alliance_id'];
            $k   = smAllianceKey($aid, $allyMap[$aid] ?? null);
            $ym  = (int)$r['first_ym'];
            if (!isset($caseFirst[$k]) || $ym < $caseFirst[$k]) $caseFirst[$k] = $ym;
        }
    }

    // --- 会社ごとに統合（同じ会社名は1社にまとめる） ---
    $companies = $negs;
    foreach ($caseFirst as $k => $ym) {
        if (!isset($companies[$k])) {
            $companies[$k] = ['first' => null, 'cand' => $ym, 'active' => $ym, 'excluded' => null];
            continue;
        }
        // 商談報告にもある会社は早い方の月を採用。案件がある＝取引中なので除外は解除する
        $c = $companies[$k];
        $c['cand']     = $c['cand']   === null ? $ym : min($c['cand'],   $ym);
        $c['active']   = $c['active'] === null ? $ym : min($c['active'], $ym);
        $c['excluded'] = null;
        $companies[$k] = $c;
    }

    // --- 月別目標 ---
    $targetMap = [];
    try {
        $stmt = $db->prepare('SELECT year, month, target_company_count, target_active_count
                              FROM strategy_meeting_monthly_targets WHERE company_id = ? AND year IN (?, ?)');
        $stmt->execute([$cid, $fy - 1, $fy]);
        foreach ($stmt->fetchAll() as $r) {
            $targetMap[(int)$r['year'] * 100 + (int)$r['month']] = [
                (int)$r['target_company_count'], (int)$r['target_active_count'],
            ];
        }
    } catch (PDOException $e) {
        error_log('[strategy_meeting targets] ' . $e->getMessage());
    }

    // --- 月ごとに数える ---
    $months = [];
    foreach ($fyMonths as $ym) {
        $newNeg = 0; $converted = 0; $companyCount = 0; $activeCount = 0;

        // 折れ線（累計）: 商談報告と既存案件を統合した全社が対象
        foreach ($companies as $c) {
            $alive = ($c['excluded'] === null || $c['excluded'] > $ym);
            if ($c['cand']   !== null && $c['cand']   <= $ym && $alive) $companyCount++;
            if ($c['active'] !== null && $c['active'] <= $ym && $alive) $activeCount++;
        }
        // 棒グラフ: 商談報告のみが対象（既存案件は商談報告ではないため数えない）
        foreach ($negs as $n) {
            if ($n['first'] === $ym)                              $newNeg++;     // 青棒: 新規商談数
            if ($n['cand'] !== null && $n['cand'] === $ym)        $converted++;  // 赤棒: 取引・候補になった数
        }
        $t = $targetMap[$ym] ?? [0, 0];
        $months[] = [
            'ym'             => $ym,
            'year'           => (int)floor($ym / 100),
            'month'          => $ym % 100,
            'new_negotiations' => $newNeg,
            'converted'      => $converted,
            'company_count'  => $companyCount,
            'active_count'   => $activeCount,
            'target_company' => $t[0],
            'target_active'  => $t[1],
        ];
    }

    echo json_encode([
        'fy'       => $fy,
        'fy_label' => smFyLabel($fy),
        'months'   => $months,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=negotiations : 商談報告の一覧（登録が新しい順）
// ----------------------------------------------------------------
if ($action === 'negotiations') {
    // 会社名は取引先マスタの表記名を優先して出す。
    // こうしておくと、取引先一覧で表記名を編集した内容がこの一覧にもそのまま反映される
    // （取引先が選ばれていない古い行だけ、保存されている会社名を使う）
    $stmt = $db->prepare("SELECT n.id, n.client_id,
                                 COALESCE(" . clientLabelSql('cl') . ", n.client_name) AS client_name,
                                 n.rep_name, n.status, n.status_other, n.division, n.note,
                                 n.first_report_ym, n.candidate_ym, n.active_ym, n.excluded_ym
                          FROM strategy_meeting_negotiations n
                          LEFT JOIN sales_clients cl ON cl.id = n.client_id AND cl.company_id = n.company_id
                          WHERE n.company_id = ?
                          ORDER BY COALESCE(n.excluded_ym, 999999) DESC, n.first_report_ym DESC, n.id DESC");
    $stmt->execute([$cid]);

    // 担当者（1社に何人でも）をまとめて取る
    $rStmt = $db->prepare('SELECT negotiation_id, rep_name FROM strategy_meeting_negotiation_reps
                           WHERE company_id = ? ORDER BY id');
    $rStmt->execute([$cid]);
    $repsByNeg = [];
    foreach ($rStmt->fetchAll() as $r) { $repsByNeg[(int)$r['negotiation_id']][] = (string)$r['rep_name']; }

    $ymLabel = function ($v) {
        if (!$v) return '';
        return (int)floor($v / 100) . '年' . ((int)$v % 100) . '月';
    };

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        // 担当者は新しい表を優先。まだ移行されていない行だけ rep_name を使う
        $reps = $repsByNeg[(int)$r['id']] ?? [];
        if (!$reps && trim((string)$r['rep_name']) !== '') $reps = [trim((string)$r['rep_name'])];
        $rows[] = [
            'id'            => (int)$r['id'],
            'client_id'     => $r['client_id'] !== null ? (int)$r['client_id'] : null,
            'client_name'   => $r['client_name'],
            'reps'          => $reps,
            'rep_name'      => implode('、', $reps),
            'status'        => $r['status'],
            'status_other'  => $r['status_other'],
            'division'      => (string)($r['division'] ?? ''),
            'note'          => $r['note'],
            'first_label'   => $ymLabel($r['first_report_ym']),
            'first_year'    => (int)floor((int)$r['first_report_ym'] / 100),
            'first_month'   => (int)$r['first_report_ym'] % 100,
            'excluded'      => $r['excluded_ym'] !== null,
            'excluded_label'=> $ymLabel($r['excluded_ym']),
        ];
    }

    echo json_encode([
        'negotiations' => $rows,
        'statuses'     => SM_STATUSES,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=summary : 取引企業数の合計（〇〇社 / 目標社数）
// ----------------------------------------------------------------
// 営業マンカードの数字を足し合わせたものではない。
// 同じ企業を複数の営業マンが担当していても1社として数え、
// さらに外注先マスタで「同じ会社の取引先」が指定されていれば、それも1社にまとめる。
// 突き合わせは取引先IDで行うので、会社名・表記名を変えても数字は変わらない。
// 期間は月別/年度の切替に連動させず、今年度（9月〜8月）で固定する。
//
// ※数えるのは「案件がある会社」だけ。商談報告だけで案件がまだ無い会社
//   （パートナー候補）は意図的に含めない。そのため年間推移グラフの
//   「パートナー数＋パートナー候補数」より少なくなるが、これで正しい（ユーザー確認済み）。
//   合わせようとして商談報告を足さないこと。
if ($action === 'summary') {
    $repNames = getSalesRepCandidates($cid);
    $target   = smTargetCount($db, $cid);

    if (!$repNames) {
        echo json_encode(['count' => 0, 'target' => $target, 'fy_label' => smFyLabel(smFyOf($pYear, $pMonth))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fy       = smFyOf($pYear, $pMonth);
    $fyParams = [$fy - 1, $fy];
    $repPh    = implode(',', array_fill(0, count($repNames), '?'));
    $allyMap  = smAllianceClientMap($db, $cid);
    $keys     = [];

    // 取引先: 営業担当がその人たちの案件に出てくる取引先
    $clientSql = "
        SELECT DISTINCT cl.id AS client_id
        FROM sales_cases sc
        JOIN sales_clients cl ON sc.client_id = cl.id
        " . SM_REP_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND " . SM_FY_WHERE . "
          AND " . SM_REP_NAME . " IN ({$repPh})";
    $stmt = $db->prepare($clientSql);
    $stmt->execute(array_merge([$cid], $fyParams, $repNames));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $clientId) {
        $keys[smClientKey((int)$clientId)] = true;
    }

    // 外注先: 管理者がその人たちの、スタッフ区分アライアンスの案件に出てくる外注先
    $allianceSql = "
        SELECT DISTINCT al.id AS alliance_id
        FROM sales_cases sc
        JOIN sales_alliances al ON sc.alliance_id = al.id
        " . SM_MGR_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND sc.worker_type = 'アライアンス'
          AND " . SM_FY_WHERE . "
          AND " . SM_MGR_NAME . " IN ({$repPh})";
    $stmt = $db->prepare($allianceSql);
    $stmt->execute(array_merge([$cid], $fyParams, $repNames));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $allianceId) {
        $aid = (int)$allianceId;
        $keys[smAllianceKey($aid, $allyMap[$aid] ?? null)] = true;
    }

    echo json_encode([
        'count'    => count($keys),
        'target'   => $target,
        'fy_label' => smFyLabel($fy),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=reps : 営業マンカード一覧
// ----------------------------------------------------------------
if ($action === 'reps') {
    // 表示対象は「社員一覧で営業担当にチェックが入っている在籍中の正社員・自社外注」。
    // 既存関数をそのまま使うので、チェックの変更は次の表示から自動で反映される
    $repNames = getSalesRepCandidates($cid);
    $allyMap  = smAllianceClientMap($db, $cid);

    // 営業マンカードは区分で絞り込まない（区分の切替は担当企業一覧にだけ効かせる）
    $endYm     = smPeriodEndYm($period, $pYear, $pMonth);
    $targetYm  = $pYear * 100 + $pMonth;
    $fyStartYm = smFyStartYm($pYear, $pMonth);

    $caseKeys = smCaseKeysByRep($db, $cid, $perWhere, $perParams, $allyMap);
    $negByRep = smNegotiationsByRep($db, $cid, $endYm, $targetYm, $fyStartYm);

    $reps = [];
    foreach ($repNames as $name) {
        $counts = smRepCounts($caseKeys[$name] ?? [], $negByRep[$name] ?? null);
        $reps[] = array_merge(['name' => $name], $counts);
    }

    // 並び順は「パートナー数＋パートナー候補数」の多い順。
    // 同数のときはパートナー数が多い方を先に出す
    usort($reps, function ($a, $b) {
        $sa = $a['partner_count'] + $a['candidate_count'];
        $sb = $b['partner_count'] + $b['candidate_count'];
        if ($sa !== $sb) return $sb <=> $sa;
        return $b['partner_count'] <=> $a['partner_count'];
    });

    echo json_encode([
        'reps'         => $reps,
        'period'       => $period,
        'period_label' => $perLabel,
        'month_label'  => $pYear . '年' . $pMonth . '月',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------
// action=companies : ある営業マンの担当企業一覧
// ----------------------------------------------------------------
if ($action === 'companies') {
    $rep = trim($_GET['rep'] ?? '');
    if ($rep === '') { echo json_encode(['error' => 'rep required']); exit; }

    $divExpr   = smDivisionExpr();
    $frameExpr = smFrameCountExpr();
    $allyMap   = smAllianceClientMap($db, $cid);

    $endYm     = smPeriodEndYm($period, $pYear, $pMonth);
    $targetYm  = $pYear * 100 + $pMonth;
    $fyStartYm = smFyStartYm($pYear, $pMonth);

    // --- 4つの集計カード ---
    // 区分の絞り込みは効かせない（営業マンカードと同じ数字にするため）
    $caseKeys = smCaseKeysByRep($db, $cid, $perWhere, $perParams, $allyMap);
    $negByRep = smNegotiationsByRep($db, $cid, $endYm, $targetYm, $fyStartYm);
    $neg      = $negByRep[$rep] ?? ['partners' => [], 'candidates' => [], 'month_new' => 0, 'total_new' => 0];
    $kpi      = smRepCounts($caseKeys[$rep] ?? [], $neg);

    // --- 担当パートナー ---
    // 会社は「取引先マスタの表記名」で表示する。取引先一覧で表記名を変えれば次の表示から反映される。
    // 枠数は既存の総合ダッシュボード「月別枠数」に合わせ、区分が1次・2次以降の案件だけを数える。
    // 取引金額はその企業との実際の取引額を出したいので、区分では絞らず全件を合計する
    $partners = [];   // 表示する行（同じ会社でも区分が違えば別の行になる）
    $shownKeys = [];  // 何社ぶん出したかを数えるための鍵

    // (1) 取引先（営業担当基準）
    $sql = "
        SELECT cl.id AS client_id,
               " . clientLabelSql('cl') . " AS client_name,
               {$divExpr} AS division,
               {$frameExpr} AS frame_count,
               COALESCE(SUM(sc.revenue), 0) AS revenue
        FROM sales_cases sc
        JOIN sales_clients cl ON sc.client_id = cl.id
        " . SM_REP_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND {$perWhere}
          AND " . SM_REP_NAME . " = ?
          {$divCond}
        GROUP BY cl.id, cl.display_name, cl.client_name, {$divExpr}
        ORDER BY revenue DESC, client_name";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$cid], $perParams, [$rep]));
    foreach ($stmt->fetchAll() as $r) {
        $key = smClientKey((int)$r['client_id']);
        $shownKeys[$key] = true;
        $partners[] = [
            'key'         => $key,
            'client_id'   => (int)$r['client_id'],
            // 光ADの行は会社名の末尾に（光AD）を付ける
            'label'       => $r['division'] === '光AD' ? $r['client_name'] . '（光AD）' : $r['client_name'],
            'kind'        => 'パートナー',
            'division'    => $r['division'],
            'frame_count' => (int)$r['frame_count'],
            // 単位: イベントは「コマ」、それ以外は「枠」（既存ダッシュボードと同じ呼び分け）
            'frame_unit'  => $r['division'] === 'イベント' ? 'コマ' : '枠',
            'revenue'     => (int)$r['revenue'],
        ];
    }

    // (2) 外注先（管理者基準）。取引先として既に出ている会社は重ねて出さない
    $sql = "
        SELECT al.id AS alliance_id,
               " . allianceLabelSql('al') . " AS alliance_name,
               {$divExpr} AS division,
               {$frameExpr} AS frame_count,
               COALESCE(SUM(sc.revenue), 0) AS revenue
        FROM sales_cases sc
        JOIN sales_alliances al ON sc.alliance_id = al.id
        " . SM_MGR_JOIN . "
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND sc.worker_type = 'アライアンス'
          AND {$perWhere}
          AND " . SM_MGR_NAME . " = ?
          {$divCond}
        GROUP BY al.id, al.display_name, al.alliance_name, {$divExpr}
        ORDER BY revenue DESC, alliance_name";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$cid], $perParams, [$rep]));
    foreach ($stmt->fetchAll() as $r) {
        $aid    = (int)$r['alliance_id'];
        $linked = $allyMap[$aid] ?? null;
        $key    = smAllianceKey($aid, $linked);
        if (isset($shownKeys[$key])) continue;   // 同じ会社の取引先が既に出ている
        $shownKeys[$key] = true;
        $partners[] = [
            'key'         => $key,
            // 年推移は取引先を見る画面なので、取引先が紐づいている外注先だけ開ける
            'client_id'   => $linked ? (int)$linked : null,
            'label'       => ($r['division'] === '光AD' ? $r['alliance_name'] . '（光AD）' : $r['alliance_name']) . '（外注先）',
            'kind'        => 'パートナー',
            'division'    => $r['division'],
            'frame_count' => (int)$r['frame_count'],
            'frame_unit'  => $r['division'] === 'イベント' ? 'コマ' : '枠',
            'revenue'     => (int)$r['revenue'],
        ];
    }

    // (3) 商談報告で「取引開始」だが、この期間に案件がまだ無い会社。
    //     案件が無いので区分は商談報告に入力されたものを使い、枠数・取引金額は「-」にする
    foreach ($neg['partners'] as $key => $n) {
        if (isset($shownKeys[$key])) continue;
        if ($division !== '' && $n['division'] !== $division) continue;  // 区分の絞り込み
        $shownKeys[$key] = true;
        $partners[] = [
            'key'         => $key,
            'client_id'   => $n['client_id'],
            'label'       => $n['client_name'],
            'kind'        => 'パートナー',
            'division'    => $n['division'],
            'frame_count' => null,
            'frame_unit'  => '',
            'revenue'     => null,
        ];
    }

    // --- 担当パートナー候補 ---
    // 案件がまだ無い会社なので、区分の絞り込みは効かせない（⑥の方針）
    $candidates = [];
    foreach ($neg['candidates'] as $key => $n) {
        if (isset($shownKeys[$key])) continue;   // すでにパートナーの会社は候補に出さない
        $candidates[] = [
            'client_id' => $n['client_id'],
            'label'     => $n['client_name'],
            'kind'      => '候補',
            'division'  => $n['division'],
            'note'      => $n['note'],
        ];
    }
    usort($candidates, fn($a, $b) => strcmp($a['label'], $b['label']));

    echo json_encode([
        'rep'             => $rep,
        'kpi'             => $kpi,
        'partners'        => $partners,
        'partner_total'   => count($shownKeys),
        'candidates'      => $candidates,
        'candidate_total' => count($candidates),
        'period'          => $period,
        'period_label'    => $perLabel,
        'month_label'     => $pYear . '年' . $pMonth . '月',
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

    // 表示の単位。fy=期別（今までどおり） / month=月別で1年度分（9月〜8月）
    $scale = ($_GET['scale'] ?? '') === 'month' ? 'month' : 'fy';

    // 枠数の数え方は担当企業一覧と揃える（カードの枠数とグラフの枠数が食い違わないように）
    $frameExpr = smFrameCountExpr();
    $sql = "
        SELECT sc.case_year, sc.case_month,
               COALESCE(SUM(sc.revenue), 0) AS revenue,
               {$frameExpr} AS frame_count
        FROM sales_cases sc {$repJoin}
        WHERE sc.company_id = ? AND sc.client_id = ? AND sc.status = 'confirmed'
          {$divCond}{$repCond}
        GROUP BY sc.case_year, sc.case_month";
    $stmt = $db->prepare($sql);
    $stmt->execute($rep !== '' ? [$cid, $clientId, $rep] : [$cid, $clientId]);
    $rows = $stmt->fetchAll();

    $periods    = [];
    $rangeLabel = '期：9月〜8月';

    if ($scale === 'month') {
        // 月別: 対象年月が属する年度の9月〜翌8月を、実績が無い月も0で並べる
        $fy   = smFyOf($pYear, $pMonth);
        $byYm = [];
        foreach ($rows as $r) {
            $byYm[(int)$r['case_year'] * 100 + (int)$r['case_month']] =
                ['revenue' => (int)$r['revenue'], 'frame_count' => (int)$r['frame_count']];
        }
        for ($i = 0; $i < 12; $i++) {
            $m  = $i < 4 ? 9 + $i : $i - 3;              // 9,10,11,12,1,2,...,8
            $y  = $i < 4 ? $fy - 1 : $fy;
            $ym = $y * 100 + $m;
            $periods[] = [
                'fy'          => $fy,
                'label'       => $m . '月',
                'revenue'     => $byYm[$ym]['revenue']     ?? 0,
                'frame_count' => $byYm[$ym]['frame_count'] ?? 0,
            ];
        }
        $rangeLabel = '年度（' . smFyLabel($fy) . '）の月別';
    } else {
        // 期別: 月別の実績を年度（9月〜翌8月）ごとにまとめる
        $byFy = [];
        foreach ($rows as $r) {
            $f = smFyOf((int)$r['case_year'], (int)$r['case_month']);
            if (!isset($byFy[$f])) $byFy[$f] = ['revenue' => 0, 'frame_count' => 0];
            $byFy[$f]['revenue']     += (int)$r['revenue'];
            $byFy[$f]['frame_count'] += (int)$r['frame_count'];
        }
        ksort($byFy);

        // データがある最初の期から最後の期まで、間の空白期も0で埋めて連続表示する
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
    }

    // 区分は指定されたものを表示。未指定なら実際に登録されている区分を並べる
    $divLabel = $division;
    if ($divLabel === '') {
        $dvStmt = $db->prepare("SELECT DISTINCT " . smDivisionExpr() . " AS d
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
        'scale'        => $scale,
        'range_label'  => $rangeLabel,
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
