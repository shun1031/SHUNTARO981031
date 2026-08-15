<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { http_response_code(403); exit; }

// インセンティブ率は社員一覧（employees.incentive_rate）で管理する。
// 定義は includes/sales/reports.php の resolveIncentiveRate() に一本化した。
function getIncRateP(string $name): float {
    return resolveIncentiveRate($name, getIncentiveRateMap(getCompanyId() ?? 0));
}

$payYear  = (int)($_GET['pay_year']  ?? date('Y'));
$payMonth = (int)($_GET['pay_month'] ?? date('n'));

$workDt = new DateTime("{$payYear}-{$payMonth}-01");
$workDt->modify('-1 month');
$workYear  = (int)$workDt->format('Y');
$workMonth = (int)$workDt->format('n');
$incDt = clone $workDt;
$incDt->modify('-1 month');
$incYear  = (int)$incDt->format('Y');
$incMonth = (int)$incDt->format('n');

$db    = getDB();
// 対象者は社員名簿の雇用形態で判定する（給与管理の一覧と同じ条件に揃える）
$where = ["sc.company_id=?","sc.case_type='regular'","emp_w.employment_type='自社外注'",
          "sc.case_year=?","sc.case_month=?","sc.status!='cancelled'"];
$params= [$cid, $workYear, $workMonth];

if (!empty($_GET['client_id'])) { $where[] = 'sc.client_id=?'; $params[] = (int)$_GET['client_id']; }
if (!empty($_GET['store_name'])) { $where[] = 'sc.store_name LIKE ?'; $params[] = '%'.$_GET['store_name'].'%'; }
if (!empty($_GET['worker_name'])){ $where[] = 'sc.worker_name LIKE ?'; $params[] = '%'.$_GET['worker_name'].'%'; }

$stmt = $db->prepare("SELECT sc.id,sc.worker_name,sc.revenue,sc.gross_profit,
    COALESCE(NULLIF(TRIM(cl.display_name),''), cl.client_name) AS client_name, sc.store_name, sc.carrier, sc.sales_rep
    FROM sales_cases sc LEFT JOIN sales_clients cl ON sc.client_id=cl.id
    INNER JOIN employees emp_w ON emp_w.id = sc.worker_employee_id AND emp_w.company_id = sc.company_id
    WHERE ".implode(' AND ',$where)." ORDER BY sc.worker_name,sc.id");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// インセンティブ集計
// 第3段階: 担当者は社員IDがあればその社員、無ければ従来どおり名前で特定する
$empNameById = [];
try {
    $enStmt = $db->prepare('SELECT id, name FROM employees WHERE company_id = ?');
    $enStmt->execute([$cid]);
    foreach ($enStmt->fetchAll() as $_e) { $empNameById[(int)$_e['id']] = $_e['name']; }
} catch (PDOException $e) { error_log('[salary_print] ' . $e->getMessage()); }
$nameOfP = function ($id, string $fallback) use ($empNameById): string {
    return (!empty($id) && isset($empNameById[(int)$id])) ? $empNameById[(int)$id] : $fallback;
};
// 紹介者として扱える値か（空欄と「該当者なし」は人ではない。他の画面と同じ判定）
$isPersonP = fn(string $v): bool => $v !== '' && $v !== '該当者なし';

$incStmt = $db->prepare("SELECT sales_rep,manager,recruiter,sales_rep_id,manager_id,recruiter_id,COALESCE(SUM(gross_profit),0) as profit
    FROM sales_cases WHERE company_id=? AND case_year=? AND case_month=? AND status!='cancelled' AND sales_rep!=''
    GROUP BY sales_rep,manager,recruiter,sales_rep_id,manager_id,recruiter_id");
$incStmt->execute([$cid,$incYear,$incMonth]);
$incMap = [];
foreach ($incStmt->fetchAll() as $r) {
    $p = (int)$r['profit']; $rp = (int)floor($p/2); $rfp = $p - $rp;
    $repName = $nameOfP($r['sales_rep_id'] ?? null, $r['sales_rep']);
    $incMap[$repName] = ($incMap[$repName] ?? 0) + $rp;
    $mg  = trim($nameOfP($r['manager_id']   ?? null, (string)($r['manager']   ?? '')));
    $rc  = trim($nameOfP($r['recruiter_id'] ?? null, (string)($r['recruiter'] ?? '')));
    $ref = $isPersonP($mg) ? $mg : ($isPersonP($rc) ? $rc : '直営業');
    $incMap[$ref] = ($incMap[$ref] ?? 0) + $rfp;
}

$staffMap = [];
foreach ($rows as $r) {
    $n = $r['worker_name'];
    if (!isset($staffMap[$n])) $staffMap[$n] = ['worker_name'=>$n,'case_count'=>0,'regular_salary'=>0];
    $staffMap[$n]['case_count']++;
    $staffMap[$n]['regular_salary'] += (int)round($r['revenue']*0.7);
}
// 常勤案件売上(7割)の手入力上書き（給与一覧と同じ値を印刷するため）
$ovrMapP = [];
try {
    $_op = getDB()->prepare("SELECT worker_name, amount FROM salary_regular_overrides
        WHERE company_id = ? AND pay_year = ? AND pay_month = ?");
    $_op->execute([$cid, $payYear, $payMonth]);
    foreach ($_op->fetchAll() as $_r) { $ovrMapP[$_r['worker_name']] = (int)$_r['amount']; }
} catch (PDOException $e) { /* テーブル未作成時は無視 */ }

$staffList = [];
foreach ($staffMap as $n => $s) {
    if (array_key_exists($n, $ovrMapP)) { $s['regular_salary'] = $ovrMapP[$n]; }
    $rate = getIncRateP($n);
    $sp   = $incMap[$n] ?? 0;
    $inc  = ($rate>0 && $sp>0) ? (int)round($sp*$rate) : 0;
    $staffList[] = array_merge($s, ['incentive'=>$inc,'total'=>$s['regular_salary']+$inc]);
}
usort($staffList, fn($a,$b) => strcmp($a['worker_name'],$b['worker_name']));

$regTotal = array_sum(array_column($staffList,'regular_salary'));
$incTotal = array_sum(array_column($staffList,'incentive'));
$grandTotal= array_sum(array_column($staffList,'total'));

function yp($n){return '¥'.number_format((int)$n);}
?><!DOCTYPE html>
<html lang="ja"><head>
<meta charset="UTF-8">
<title>給与一覧 <?= $payYear ?>年<?= $payMonth ?>月末</title>
<style>
body{font-family:'Meiryo',sans-serif;font-size:11pt;margin:20px}
h2{font-size:14pt;margin-bottom:4px}
.sub{font-size:9pt;color:#666;margin-bottom:14px}
table{width:100%;border-collapse:collapse}
th{background:#f1f5f9;font-size:9pt;padding:8px;border:1px solid #ccc;text-align:center}
td{font-size:10pt;padding:7px 8px;border:1px solid #ccc}
.right{text-align:right} .center{text-align:center}
.blue{color:#1d4ed8;font-weight:700} .orange{color:#d97706;font-weight:600}
tfoot td{font-weight:700;background:#f0fdf4}
.note{font-size:8pt;color:#999;margin-top:10px}
@media print{button{display:none}}
</style>
</head><body>
<button onclick="window.print()" style="margin-bottom:12px;padding:6px 14px;cursor:pointer">🖨️ 印刷／PDF保存</button>
<h2>給与一覧 — <?= $payYear ?>年<?= $payMonth ?>月末 支払い予定</h2>
<div class="sub">常勤売上：<?= $workYear ?>年<?= $workMonth ?>月稼働分（×70%）　／　インセンティブ：<?= $incYear ?>年<?= $incMonth ?>月分</div>
<table>
<thead><tr>
<th>スタッフ名</th><th>案件数</th>
<th>常勤案件売上（7割）</th>
<th>インセンティブ費用</th>
<th>総支給額</th>
</tr></thead>
<tbody>
<?php foreach ($staffList as $s): ?>
<tr>
<td class="fw-bold"><?= h($s['worker_name']) ?></td>
<td class="center"><?= $s['case_count'] ?>件</td>
<td class="right"><?= yp($s['regular_salary']) ?></td>
<td class="right orange"><?= yp($s['incentive']) ?></td>
<td class="right blue"><?= yp($s['total']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
<td colspan="2" class="right">合計</td>
<td class="right"><?= yp($regTotal) ?></td>
<td class="right orange"><?= yp($incTotal) ?></td>
<td class="right blue"><?= yp($grandTotal) ?></td>
</tr></tfoot>
</table>
<div class="note">※ 対象：社員一覧の雇用形態が「自社外注」のスタッフの常勤案件のみ</div>
</body></html>
