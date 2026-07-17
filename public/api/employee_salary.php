<?php
/**
 * 正社員給与管理 API
 * GET  ?action=data&employee_id=&year=&month=   → 給与・前月比較・支給履歴・年間推移
 * GET  ?action=trend&employee_id=&year=         → 年間推移のみ
 * GET  ?action=slip&employee_id=&year=&month=   → 給与明細画像（バイナリ）
 * POST multipart: action=save + 各金額フィールド + slip(任意)
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

$PAY_FIELDS = ['base_pay','position_allowance','overtime_allowance','commute_allowance','other_allowance'];
$DED_FIELDS = ['health_insurance','pension','employment_insurance','income_tax','resident_tax','other_deduction'];

function esTotals(?array $row, array $PAY_FIELDS, array $DED_FIELDS): ?array {
    if (!$row) return null;
    $pay = 0; $ded = 0;
    foreach ($PAY_FIELDS as $f) $pay += (int)($row[$f] ?? 0);
    foreach ($DED_FIELDS as $f) $ded += (int)($row[$f] ?? 0);
    return ['total_payment' => $pay, 'total_deduction' => $ded, 'net_payment' => $pay - $ded];
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
    $tSt = $db->prepare('SELECT pay_month, base_pay,position_allowance,overtime_allowance,commute_allowance,other_allowance,health_insurance,pension,employment_insurance,income_tax,resident_tax,other_deduction FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=?');
    $tSt->execute([$cid, $empId, $trendYear]);
    $trend = array_fill(1, 12, null);
    foreach ($tSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = esTotals($r, $PAY_FIELDS, $DED_FIELDS);
        $trend[(int)$r['pay_month']] = $t['net_payment'];
    }
    if ($action === 'trend') {
        echo json_encode(['trend_year' => $trendYear, 'trend' => array_values($trend)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 当月データ
    $cSt = $db->prepare('SELECT *, (slip_image IS NOT NULL) AS has_slip FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $cSt->execute([$cid, $empId, $year, $month]);
    $cur = $cSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($cur) { unset($cur['slip_image']); }

    // 前月データ（比較用）
    $pv = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $pv->modify('-1 month');
    $pSt = $db->prepare('SELECT * FROM employee_salaries WHERE company_id=? AND employee_id=? AND pay_year=? AND pay_month=?');
    $pSt->execute([$cid, $empId, (int)$pv->format('Y'), (int)$pv->format('n')]);
    $prev = $pSt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 支給履歴（直近6件）
    $hSt = $db->prepare('SELECT pay_year, pay_month, base_pay,position_allowance,overtime_allowance,commute_allowance,other_allowance,health_insurance,pension,employment_insurance,income_tax,resident_tax,other_deduction, (slip_image IS NOT NULL) AS has_slip FROM employee_salaries WHERE company_id=? AND employee_id=? ORDER BY pay_year DESC, pay_month DESC LIMIT 6');
    $hSt->execute([$cid, $empId]);
    $history = [];
    foreach ($hSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = esTotals($r, $PAY_FIELDS, $DED_FIELDS);
        $history[] = [
            'pay_year' => (int)$r['pay_year'], 'pay_month' => (int)$r['pay_month'],
            'total_payment' => $t['total_payment'], 'net_payment' => $t['net_payment'],
            'has_slip' => (bool)$r['has_slip'],
        ];
    }

    echo json_encode([
        'employee' => [
            'id' => (int)$emp['id'],
            'name' => $emp['name'],
            'employee_number' => $emp['employee_number'] ?? '',
            'department' => $emp['department'] ?? '',
            'employment_type' => $emp['employment_type'] ?: '正社員',
            'hire_date' => $emp['hire_date'] ?? '',
        ],
        'salary'       => $cur,
        'totals'       => esTotals($cur, $PAY_FIELDS, $DED_FIELDS),
        'prev_totals'  => esTotals($prev, $PAY_FIELDS, $DED_FIELDS),
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
金額は整数（円、カンマなし）。明細に存在しない項目は0にしてください。

{
  "base_pay": 基本給,
  "position_allowance": 役職手当,
  "overtime_allowance": 残業手当（時間外手当含む）,
  "commute_allowance": 通勤手当,
  "other_allowance": その他手当の合計,
  "health_insurance": 健康保険,
  "pension": 厚生年金,
  "employment_insurance": 雇用保険,
  "income_tax": 所得税,
  "resident_tax": 住民税,
  "other_deduction": その他控除の合計,
  "employee_name": "氏名（読み取れた場合。なければ空文字）",
  "pay_year": 支給年（西暦、読み取れなければ0）,
  "pay_month": 支給月（読み取れなければ0）,
  "uncertain_fields": ["読み取りに自信がない項目のキー名"]
}
PROMPT;

    $payload = json_encode([
        'model' => 'claude-sonnet-5',
        'max_tokens' => 1024,
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
    // コードフェンスが付いていた場合は除去
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        error_log('[employee_salary ocr] parse failed: ' . substr($text, 0, 300));
        echo json_encode(['error' => 'AI読み取り結果の解析に失敗しました。手入力してください。']); exit;
    }

    $fields = [];
    foreach (array_merge($PAY_FIELDS, $DED_FIELDS) as $f) {
        $fields[$f] = max(0, (int)($parsed[$f] ?? 0));
    }
    echo json_encode([
        'success' => true,
        'fields'  => $fields,
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

    $vals = [];
    foreach (array_merge($PAY_FIELDS, $DED_FIELDS) as $f) {
        $vals[$f] = max(0, (int)str_replace(',', '', $_POST[$f] ?? '0'));
    }

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

    $cols = array_keys($vals);
    if ($exId) {
        $sets = implode(',', array_map(fn($c) => "$c=?", $cols));
        $params = array_values($vals);
        if ($slipData !== null) { $sets .= ', slip_image=?, slip_mime=?'; $params[] = $slipData; $params[] = $slipMime; }
        $params[] = $exId;
        $db->prepare("UPDATE employee_salaries SET $sets WHERE id=?")->execute($params);
    } else {
        $cols2 = array_merge(['company_id','employee_id','pay_year','pay_month'], $cols);
        $params = array_merge([$cid, $empId, $year, $month], array_values($vals));
        if ($slipData !== null) { $cols2[] = 'slip_image'; $cols2[] = 'slip_mime'; $params[] = $slipData; $params[] = $slipMime; }
        $ph = implode(',', array_fill(0, count($cols2), '?'));
        $db->prepare('INSERT INTO employee_salaries (' . implode(',', $cols2) . ") VALUES ($ph)")->execute($params);
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Bad request']);
