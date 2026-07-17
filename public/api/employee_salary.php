<?php
/**
 * 正社員給与管理 API
 * GET  ?action=data&employee_id=&year=&month=   → 給与・前月比較・支給履歴・年間推移
 * GET  ?action=trend&employee_id=&trend_year=   → 年間推移のみ
 * GET  ?action=slip&employee_id=&year=&month=   → 給与明細画像（バイナリ）
 * POST action=save   : detail(JSON) + slip(任意)
 * POST action=ocr    : slip → AI読み取り（Claude API）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Unauthorized']); exit; }
$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['super_admin', 'company_admin'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '権限がありません']); exit;
}

$db = getDB();

// ─── 項目定義 ───
// 勤怠（小数可）
$ATT_FIELDS = ['work_days','absence_days','paid_overtime_hours','midnight_overtime_hours','holiday_work_hours','holiday_midnight_hours','late_early_hours','working_days'];
// 支給（円・整数）※work_deductionは支給から差し引く
$PAY_FIELDS = ['base_pay','position_allowance','skill_allowance','fixed_overtime_allowance','duty_allowance','communication_fee','management_incentive','sales_incentive','housing_allowance','overtime_allowance','work_deduction','commute_nontax','commute_tax'];
// 控除（円・整数）※taxable_amountは情報表示のみで控除合計に含めない
$DED_FIELDS = ['health_insurance','pension_insurance','pension_fund','employment_insurance','taxable_amount','income_tax','resident_tax','year_end_adjustment'];
// 集計（円・整数）
$SUM_FIELDS = ['prev_carryover','current_adjustment','bank_account1','bank_account2','bank_account3','cash_payment'];

// 正社員かどうか（社員一覧の正社員タブと同一判定）
function esIsSeishain(array $e): bool {
    $t = $e['employment_type'] ?? '';
    $s = $e['employment_subtype'] ?? '';
    if (in_array($t, ['自社外注', '個人外注', 'アライアンス', 'アルバイト'], true)) return false;
    if ($t === '自社' && in_array($s, ['外注', 'アルバイト'], true)) return false;
    return true;
}

function esFetchEmployee(PDO $db, int $cid, int $empId): ?array {
    $st = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ? AND is_active = 1');
    $st->execute([$empId, $cid]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e || !esIsSeishain($e)) return null;
    return $e;
}

// detail JSON → 合計値（総支給額/社会保険合計/控除合計/差引支給額）
function esTotals(?array $detail, array $PAY_FIELDS): ?array {
    if (!$detail) return null;
    $pay = 0;
    foreach ($PAY_FIELDS as $f) {
        if ($f === 'work_deduction') continue;
        $pay += (int)($detail[$f] ?? 0);
    }
    $pay -= (int)($detail['work_deduction'] ?? 0);
    $social = (int)($detail['health_insurance'] ?? 0) + (int)($detail['pension_insurance'] ?? 0)
            + (int)($detail['pension_fund'] ?? 0) + (int)($detail['employment_insurance'] ?? 0);
    $ded = $social + (int)($detail['income_tax'] ?? 0) + (int)($detail['resident_tax'] ?? 0)
         + (int)($detail['year_end_adjustment'] ?? 0);
    return [
        'total_payment'          => $pay,
        'social_insurance_total' => $social,
        'total_deduction'        => $ded,
        'net_payment'            => $pay - $ded,
    ];
}

function esDecodeDetail(?string $json): ?array {
    if ($json === null || $json === '') return null;
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
}

$action = $_REQUEST['action'] ?? '';

// ─── 明細画像 ───
if ($action === 'slip' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $empId = (int)($_GET['employee_id'] ?? 0);
    $year  = (int)($_GET['year']  ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    $st = $db->prepare('SELECT slip_image, slip_mime FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $st->execute([$cid, $empId, $year, $month]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['slip_image'] === null) { http_response_code(404); exit; }
    header('Content-Type: ' . ($row['slip_mime'] ?: 'application/octet-stream'));
    header('Cache-Control: no-store');
    echo $row['slip_image'];
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ─── 給与データ取得 ───
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === 'data' || $action === 'trend')) {
    $empId = (int)($_GET['employee_id'] ?? 0);
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $emp = esFetchEmployee($db, $cid, $empId);
    if (!$emp) { echo json_encode(['error' => '対象社員が見つかりません（正社員のみ対象）']); exit; }

    // 年間推移（1〜12月の差引支給額）
    $trendYear = (int)($_GET['trend_year'] ?? $year);
    $tSt = $db->prepare('SELECT pay_month, detail FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=?');
    $tSt->execute([$cid, $empId, $trendYear]);
    $trend = array_fill(1, 12, null);
    foreach ($tSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = esTotals(esDecodeDetail($r['detail']), $PAY_FIELDS);
        if ($t) $trend[(int)$r['pay_month']] = $t['net_payment'];
    }
    if ($action === 'trend') {
        echo json_encode(['trend_year' => $trendYear, 'trend' => array_values($trend)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 当月データ
    $cSt = $db->prepare('SELECT detail, (slip_image IS NOT NULL) AS has_slip FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $cSt->execute([$cid, $empId, $year, $month]);
    $curRow = $cSt->fetch(PDO::FETCH_ASSOC) ?: null;
    $curDetail = $curRow ? esDecodeDetail($curRow['detail']) : null;

    // 前月データ（比較用）
    $pv = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $pv->modify('-1 month');
    $pSt = $db->prepare('SELECT detail FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $pSt->execute([$cid, $empId, (int)$pv->format('Y'), (int)$pv->format('n')]);
    $prevRow = $pSt->fetch(PDO::FETCH_ASSOC) ?: null;
    $prevDetail = $prevRow ? esDecodeDetail($prevRow['detail']) : null;

    // 支給履歴（直近6件）
    $hSt = $db->prepare('SELECT pay_year, pay_month, detail, (slip_image IS NOT NULL) AS has_slip FROM employee_salaries WHERE company_id=? AND employee_id=? ORDER BY pay_year DESC, pay_month DESC LIMIT 6');
    $hSt->execute([$cid, $empId]);
    $history = [];
    foreach ($hSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = esTotals(esDecodeDetail($r['detail']), $PAY_FIELDS);
        $history[] = [
            'pay_year' => (int)$r['pay_year'], 'pay_month' => (int)$r['pay_month'],
            'total_payment' => $t['total_payment'] ?? 0, 'net_payment' => $t['net_payment'] ?? 0,
            'has_slip' => (bool)$r['has_slip'],
        ];
    }

    // 会社名
    $coSt = $db->prepare('SELECT company_name FROM companies WHERE id = ?');
    $coSt->execute([$cid]);
    $companyName = (string)($coSt->fetchColumn() ?: '');

    echo json_encode([
        'employee' => [
            'id' => (int)$emp['id'],
            'name' => $emp['name'],
            'department' => $emp['department'] ?? '',
            'company_name' => $companyName,
        ],
        'detail'       => $curDetail,
        'has_slip'     => $curRow ? (bool)$curRow['has_slip'] : false,
        'totals'       => esTotals($curDetail, $PAY_FIELDS),
        'prev_totals'  => esTotals($prevDetail, $PAY_FIELDS),
        'history'      => $history,
        'trend_year'   => $trendYear,
        'trend'        => array_values($trend),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── AI読み取り（給与明細画像 → 各項目を自動抽出） ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'ocr') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    if ($apiKey === '') {
        echo json_encode(['error' => 'AI読み取りが未設定です。環境変数 ANTHROPIC_API_KEY を設定してください。手入力は可能です。']);
        exit;
    }
    if (empty($_FILES['slip']['tmp_name']) || !is_uploaded_file($_FILES['slip']['tmp_name'])) {
        echo json_encode(['error' => 'ファイルがありません']); exit;
    }
    $mime = mime_content_type($_FILES['slip']['tmp_name']) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        echo json_encode(['error' => '画像はJPG/PNG/PDFのみ対応です']); exit;
    }
    if ($_FILES['slip']['size'] > 8 * 1024 * 1024) { echo json_encode(['error' => 'ファイルサイズは8MB以内にしてください']); exit; }

    $b64 = base64_encode(file_get_contents($_FILES['slip']['tmp_name']));
    if ($mime === 'application/pdf') {
        $fileBlock = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
    } else {
        $fileBlock = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
    }

    $prompt = <<<'PROMPT'
これは日本の給与明細書です。以下の項目を読み取り、JSONのみを出力してください（説明文・コードフェンス不要）。
金額は整数（円、カンマなし）。勤怠は小数可。明細に存在しない項目は0にしてください。

【勤怠】
"work_days": 出勤日数,
"absence_days": 欠勤日数,
"paid_overtime_hours": 有給残業時間（明細の「普通残業時間」）,
"midnight_overtime_hours": 深夜残業時間,
"holiday_work_hours": 休日出勤時間,
"holiday_midnight_hours": 休日深夜時間,
"late_early_hours": 遅刻早退時間,
"working_days": 勤務日数（明細の「勤務時間」）,

【支給】
"base_pay": 基本給,
"position_allowance": 役職手当,
"skill_allowance": 能力手当,
"fixed_overtime_allowance": 固定残業手当,
"duty_allowance": 職務手当,
"communication_fee": 通信費,
"management_incentive": 管理インセンティブ,
"sales_incentive": 営業インセンティブ,
"housing_allowance": 家賃手当,
"overtime_allowance": 時間外手当,
"work_deduction": 勤怠控除,
"commute_nontax": 通勤手当（非）,
"commute_tax": 通勤手当（課）,

【控除】
"health_insurance": 健康保険,
"pension_insurance": 厚生年金保険,
"pension_fund": 厚生年金基金,
"employment_insurance": 雇用保険,
"taxable_amount": 課税対象額,
"income_tax": 所得税,
"resident_tax": 住民税,
"year_end_adjustment": 年末調整,

【集計】
"prev_carryover": 前回端額（前回繰越額）,
"current_adjustment": 今回端額（今回調整額）,
"bank_account1": 振込口座1,
"bank_account2": 振込口座2,
"bank_account3": 振込口座3,
"cash_payment": 現金支給額,

【その他】
"comment": "コメント欄の文章（なければ空文字）",
"employee_name": "氏名（読み取れた場合。なければ空文字）",
"pay_year": 支給対象年（西暦、読み取れなければ0）,
"pay_month": 支給対象月（読み取れなければ0）,
"uncertain_fields": ["読み取りに自信がない項目のキー名"]

上記すべてのキーを含む1つのJSONオブジェクトとして出力してください。
PROMPT;

    $payload = json_encode([
        'model' => 'claude-sonnet-5',
        'max_tokens' => 2048,
        'messages' => [[
            'role' => 'user',
            'content' => [$fileBlock, ['type' => 'text', 'text' => $prompt]],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resBody === false) {
        error_log('[employee_salary ocr] curl error: ' . $curlErr);
        echo json_encode(['error' => 'AI読み取りの通信に失敗しました']); exit;
    }
    $res = json_decode($resBody, true);
    if ($httpCode !== 200 || empty($res['content'][0]['text'])) {
        error_log('[employee_salary ocr] API error http=' . $httpCode . ' body=' . substr($resBody, 0, 300));
        echo json_encode(['error' => 'AI読み取りに失敗しました（' . ($res['error']['message'] ?? 'APIエラー') . '）']); exit;
    }

    $text = trim($res['content'][0]['text']);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        error_log('[employee_salary ocr] parse failed: ' . substr($text, 0, 300));
        echo json_encode(['error' => 'AI読み取り結果の解析に失敗しました。手入力してください。']); exit;
    }

    $fields = [];
    foreach ($ATT_FIELDS as $f) $fields[$f] = round((float)($parsed[$f] ?? 0), 2);
    foreach (array_merge($PAY_FIELDS, $DED_FIELDS, $SUM_FIELDS) as $f) $fields[$f] = max(0, (int)($parsed[$f] ?? 0));
    echo json_encode([
        'success' => true,
        'fields'  => $fields,
        'comment' => (string)($parsed['comment'] ?? ''),
        'employee_name' => (string)($parsed['employee_name'] ?? ''),
        'pay_year'  => (int)($parsed['pay_year'] ?? 0),
        'pay_month' => (int)($parsed['pay_month'] ?? 0),
        'uncertain_fields' => array_values(array_filter((array)($parsed['uncertain_fields'] ?? []), 'is_string')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── 保存 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }
    $empId = (int)($_POST['employee_id'] ?? 0);
    $year  = (int)($_POST['year']  ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if (!$empId || $year < 2000 || $month < 1 || $month > 12) { echo json_encode(['error' => '入力値が不正です']); exit; }
    $emp = esFetchEmployee($db, $cid, $empId);
    if (!$emp) { echo json_encode(['error' => '対象社員が見つかりません（正社員のみ対象）']); exit; }

    $raw = json_decode($_POST['detail'] ?? '{}', true);
    if (!is_array($raw)) { echo json_encode(['error' => '明細データが不正です']); exit; }
    $detail = [];
    foreach ($ATT_FIELDS as $f) $detail[$f] = round((float)str_replace(',', '', (string)($raw[$f] ?? 0)), 2);
    foreach (array_merge($PAY_FIELDS, $DED_FIELDS, $SUM_FIELDS) as $f) $detail[$f] = max(0, (int)str_replace(',', '', (string)($raw[$f] ?? 0)));
    $detail['comment'] = mb_substr(trim((string)($raw['comment'] ?? '')), 0, 1000);
    $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE);

    // 明細画像（任意）
    $slipData = null; $slipMime = null;
    if (!empty($_FILES['slip']['tmp_name']) && is_uploaded_file($_FILES['slip']['tmp_name'])) {
        $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
        $mime = mime_content_type($_FILES['slip']['tmp_name']) ?: '';
        if (!in_array($mime, $allowed, true)) { echo json_encode(['error' => '画像はJPG/PNG/PDFのみ対応です']); exit; }
        if ($_FILES['slip']['size'] > 8 * 1024 * 1024) { echo json_encode(['error' => 'ファイルサイズは8MB以内にしてください']); exit; }
        $slipData = file_get_contents($_FILES['slip']['tmp_name']);
        $slipMime = $mime;
    }

    $ex = $db->prepare('SELECT id FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $ex->execute([$cid, $empId, $year, $month]);
    $exId = $ex->fetchColumn();

    if ($exId) {
        $sets = 'detail=?';
        $params = [$detailJson];
        if ($slipData !== null) { $sets .= ', slip_image=?, slip_mime=?'; $params[] = $slipData; $params[] = $slipMime; }
        $params[] = $exId;
        $db->prepare("UPDATE employee_salaries SET $sets WHERE id=?")->execute($params);
    } else {
        $cols = ['company_id','employee_id','pay_year','pay_month','detail'];
        $params = [$cid, $empId, $year, $month, $detailJson];
        if ($slipData !== null) { $cols[] = 'slip_image'; $cols[] = 'slip_mime'; $params[] = $slipData; $params[] = $slipMime; }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare('INSERT INTO employee_salaries (' . implode(',', $cols) . ") VALUES ($ph)")->execute($params);
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Bad request']);
