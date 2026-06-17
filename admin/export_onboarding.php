<?php
/**
 * 入社・扶養者異動連絡票 PDF出力（印刷用HTML）
 * ブラウザの印刷機能（Ctrl+P）でPDF化
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$db  = getDB();
$cid = getCompanyId();

$empId = (int)($_GET['id'] ?? 0);
if (!$empId) { echo '社員IDが指定されていません'; exit; }

// 社員データ取得
$sql = 'SELECT e.*, c.company_name FROM employees e LEFT JOIN companies c ON e.company_id = c.id WHERE e.id = ?';
if ($cid) $sql .= ' AND e.company_id = ' . (int)$cid;
$stmt = $db->prepare($sql);
$stmt->execute([$empId]);
$emp = $stmt->fetch();
if (!$emp) { echo '社員が見つかりません'; exit; }

// 扶養家族
$deps = $db->prepare('SELECT * FROM employee_dependents WHERE employee_id = ? ORDER BY sort_order');
$deps->execute([$empId]);
$dependents = $deps->fetchAll();

// 配偶者
$spouse = null;
$children = [];
foreach ($dependents as $d) {
    if ($d['relationship'] === 'spouse') $spouse = $d;
    else $children[] = $d;
}

// 職歴
$history = $db->prepare('SELECT * FROM employee_work_history WHERE employee_id = ? ORDER BY sort_order');
$history->execute([$empId]);
$workHistory = $history->fetchAll();

// 和暦変換
function toWareki(?string $date): string {
    if (!$date) return '';
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    $d = (int)date('j', strtotime($date));
    if ($y >= 2019) return '令和' . ($y - 2018) . '年' . $m . '月' . $d . '日';
    if ($y >= 1989) return '平成' . ($y - 1988) . '年' . $m . '月' . $d . '日';
    return '昭和' . ($y - 1925) . '年' . $m . '月' . $d . '日';
}

function toWarekiShort(?string $date): array {
    if (!$date) return ['era'=>'', 'y'=>'', 'm'=>'', 'd'=>''];
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    $d = (int)date('j', strtotime($date));
    if ($y >= 2019) return ['era'=>'令和', 'y'=>$y-2018, 'm'=>$m, 'd'=>$d];
    if ($y >= 1989) return ['era'=>'平成', 'y'=>$y-1988, 'm'=>$m, 'd'=>$d];
    return ['era'=>'昭和', 'y'=>$y-1925, 'm'=>$m, 'd'=>$d];
}

$birth = toWarekiShort($emp['birth_date']);
$hire = toWarekiShort($emp['hire_date']);
$gender = ($emp['gender'] ?? '') === 'male' ? '男' : (($emp['gender'] ?? '') === 'female' ? '女' : '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>入社・扶養者異動連絡票 - <?= h($emp['name']) ?></title>
<style>
@page { size: A4 portrait; margin: 10mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Yu Gothic', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; font-size: 11px; color: #000; background: #fff; }
.page { width: 190mm; margin: 0 auto; padding: 5mm 0; }
h1 { font-size: 18px; text-align: center; margin-bottom: 5px; letter-spacing: 8px; }
h1 small { font-size: 11px; font-weight: normal; letter-spacing: 0; }
.company-name { font-size: 12px; margin-bottom: 8px; }

table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
td, th { border: 1px solid #000; padding: 3px 5px; vertical-align: middle; }
th { background: #e8e8e8; font-weight: bold; text-align: center; }
.no-border { border: none; }
.dark-header { background: #333; color: #fff; }
.label { background: #f0f0f0; font-weight: bold; width: 60px; text-align: center; font-size: 10px; }
.val { min-height: 18px; }
.small { font-size: 9px; }
.center { text-align: center; }
.right { text-align: right; }
.underline { border-bottom: 1px solid #000; display: inline-block; min-width: 80px; text-align: center; }

.print-btn { position: fixed; top: 10px; right: 10px; z-index: 999; padding: 10px 20px; background: #047857; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
.print-btn:hover { background: #065f46; }
@media print { .print-btn { display: none; } }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨 印刷 / PDF出力</button>

<div class="page">

<h1>入社 ・ 扶養者異動連絡票 <small>（○をつけてください）</small></h1>
<div class="company-name">会社名 <?= h($emp['company_name'] ?? '') ?></div>

<!-- 基本情報 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" style="width:40px">フリガナ</td>
        <td colspan="3" class="val"><?= h($emp['name_kana'] ?? '') ?></td>
        <td class="label" style="width:40px">性別</td>
        <td class="center val" style="width:80px"><?= $gender === '男' ? '<strong>●男</strong>　女' : ($gender === '女' ? '男　<strong>●女</strong>' : '男　女') ?></td>
    </tr>
    <tr>
        <td class="label" rowspan="2">＊氏名</td>
        <td colspan="3" rowspan="1" style="font-size:16px;font-weight:bold;height:30px"><?= h($emp['name']) ?></td>
        <td class="center small" style="width:70px"><?= h($birth['era']) ?></td>
        <td class="center val"><?= $birth['y'] ? $birth['y'] . '年' . $birth['m'] . '月' . $birth['d'] . '日生' : '' ?></td>
    </tr>
    <tr>
        <td colspan="3" class="small">個人番号</td>
        <td colspan="2" class="val"><?= h($emp['my_number'] ?? '') ?></td>
    </tr>
</table></div>

<!-- 住所 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" style="width:40px">フリガナ</td>
        <td colspan="3" class="val small"><?= h($emp['address_kana'] ?? '') ?></td>
    </tr>
    <tr>
        <td class="label" rowspan="1">現住所</td>
        <td style="width:20px">〒</td>
        <td class="val"><?= h($emp['postal_code'] ?? '') ?></td>
        <td class="val">TEL <?= h($emp['phone'] ?? '') ?></td>
    </tr>
    <tr>
        <td class="no-border"></td>
        <td colspan="3" class="val"><?= h($emp['address'] ?? '') ?></td>
    </tr>
</table></div>

<!-- 入社日 -->
<div class="table-responsive"><table>
    <tr>
        <td class="no-border" style="width:60px">（入社日）</td>
        <td class="no-border center"><?= $hire['era'] ? $hire['era'] . ' ' . $hire['y'] . '年 ' . $hire['m'] . '月 ' . $hire['d'] . '日' : '令和　　年　　月　　日' ?></td>
        <td class="no-border" style="width:80px">（職種・部門）</td>
        <td class="no-border val"><?= h($emp['department'] ?? '') ?> <?= h($emp['job_title'] ?? '') ?></td>
    </tr>
</table></div>

<!-- 扶養家族 -->
<div class="table-responsive"><table>
    <tr>
        <th class="dark-header" rowspan="2" style="width:30px">＊<br>扶<br>養<br>家<br>族</th>
        <th style="width:20px"></th>
        <th>フリガナ<br>氏　名</th>
        <th style="width:50px">性別<br>続柄</th>
        <th style="width:80px">生年月日</th>
        <th style="width:60px">年間収入</th>
        <th>職業・学年<br>【マイナンバー】</th>
    </tr>
    <!-- 配偶者 -->
    <tr>
        <td class="center small">配偶者<br>(夫・妻)</td>
        <td class="val"><?= $spouse ? h($spouse['name_kana'] ?? '') . '<br><strong>' . h($spouse['name']) . '</strong>' : '' ?></td>
        <td class="center val"><?= $spouse ? ($spouse['gender']==='male'?'男':'女') : '男・女' ?></td>
        <td class="center val"><?= $spouse && $spouse['birth_date'] ? toWareki($spouse['birth_date']) : '年　月　日' ?></td>
        <td class="right val"><?= $spouse && $spouse['annual_income'] ? number_format($spouse['annual_income']) . '万円' : '' ?></td>
        <td class="val small"><?= $spouse ? h($spouse['occupation'] ?? '') . ($spouse['my_number'] ? '<br>【' . h($spouse['my_number']) . '】' : '') : '【基礎年金番号】<br>【マイナンバー】' ?></td>
    </tr>
    <?php for ($i = 0; $i < 3; $i++): $c = $children[$i] ?? null; ?>
    <tr>
        <td class="center small"><?= $c ? h($c['relationship_label'] ?? '') : '' ?></td>
        <td class="val"><?= $c ? h($c['name_kana'] ?? '') . '<br><strong>' . h($c['name']) . '</strong>' : '' ?></td>
        <td class="center val"><?= $c ? ($c['gender']==='male'?'男':'女') : '男・女' ?></td>
        <td class="center val"><?= $c && $c['birth_date'] ? toWareki($c['birth_date']) : '年　月　日' ?></td>
        <td class="right val"><?= $c && $c['annual_income'] ? number_format($c['annual_income']) . '万円' : '' ?></td>
        <td class="val small"><?= $c ? h($c['occupation'] ?? '') . ($c['my_number'] ? '<br>【' . h($c['my_number']) . '】' : '') : '【マイナンバー】' ?></td>
    </tr>
    <?php endfor; ?>
</table></div>

<!-- 社会保険 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" style="width:70px">社会保険の加入</td>
        <td class="val">厚生年金 ・ 健康保険 ・ 雇用保険　　○をつけてください</td>
    </tr>
    <tr>
        <td class="label">年金</td>
        <td class="val">基礎年金番号: <?= h($emp['pension_number'] ?? '') ?></td>
    </tr>
    <tr>
        <td class="label">雇用保険</td>
        <td class="val">（被保険者証）<?= $emp['has_insurance_card'] ? '●有り' : '有り・無し' ?>　　被保険者番号: <?= h($emp['insurance_number'] ?? '') ?></td>
    </tr>
</table></div>

<!-- 職歴 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" rowspan="<?= max(count($workHistory), 3) + 1 ?>" style="width:40px">職歴</td>
        <th>会社名</th>
        <th colspan="2">勤務年月</th>
    </tr>
    <?php for ($i = 0; $i < max(count($workHistory), 3); $i++): $wh = $workHistory[$i] ?? null; ?>
    <tr>
        <td class="val"><?= $wh ? h($wh['company_name']) : '' ?></td>
        <td class="center val" colspan="2"><?= $wh ? ($wh['start_year'] ? $wh['start_year'].'年'.$wh['start_month'].'月' : '') . '　〜　' . ($wh['end_year'] ? $wh['end_year'].'年'.$wh['end_month'].'月' : '') : '年　月　〜　年　月' ?></td>
    </tr>
    <?php endfor; ?>
</table></div>

<!-- 給与 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" rowspan="6" style="width:40px">給与</td>
        <td colspan="3">1ケ月のおよその給与総額　　<?= $emp['monthly_salary'] ? number_format($emp['monthly_salary']) : '0' ?>　円</td>
    </tr>
    <tr>
        <td colspan="3"><?= $emp['salary_type']==='monthly'?'●月給':'月給' ?> ・ <?= $emp['salary_type']==='daily'?'●日給':'日給' ?> ・ <?= $emp['salary_type']==='hourly'?'●時間給':'時間給' ?>　　基本給　<?= $emp['base_pay'] ? number_format($emp['base_pay']) : '' ?>円</td>
    </tr>
    <tr><td colspan="3"><?= h($emp['allowance1_name'] ?? '手当') ?>　<?= $emp['allowance1_amount'] ? number_format($emp['allowance1_amount']) : '' ?>円</td></tr>
    <tr><td colspan="3"><?= h($emp['allowance2_name'] ?? '手当') ?>　<?= $emp['allowance2_amount'] ? number_format($emp['allowance2_amount']) : '' ?>円</td></tr>
    <tr><td colspan="3"><?= h($emp['allowance3_name'] ?? '手当') ?>　<?= $emp['allowance3_amount'] ? number_format($emp['allowance3_amount']) : '' ?>円</td></tr>
    <tr><td colspan="3">通勤手当　<?= $emp['commute_allowance'] ? number_format($emp['commute_allowance']) : '' ?>円</td></tr>
</table></div>

<!-- 振込先 -->
<div class="table-responsive"><table>
    <tr>
        <td class="label" style="width:40px">給与<br>振込先</td>
        <td class="val" style="width:45%"><?= h($emp['bank_name'] ?? '') ?>　銀行 / 信用金庫<br><?= h($emp['bank_branch'] ?? '') ?>　支店</td>
        <td class="val">口座番号<br><?= ($emp['bank_account_type'] ?? '') === 'current' ? '当座' : '普通' ?>　No. <?= h($emp['bank_account_number'] ?? '') ?></td>
    </tr>
</table></div>

<div style="margin-top:8px;font-size:10px">
    【備　考】<br>
    ● 【扶養者異動の場合は＊のみ】<br>
    ● 扶養者が別居の場合は住所を記入願います。
</div>

</div>
</body>
</html>
