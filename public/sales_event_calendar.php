<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
// 営業マン用画面: 管理者または営業担当のみ閲覧可（URL直打ちでも弾く）
requireSalesPageView();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'イベントカレンダー';
$extraCss  = ['sales.css'];
$extraJs   = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$db = getDB();
$csrf = getCsrfToken();

// ─────────────────────────────────────────────
// POST: 予定案件 CRUD
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $act = $_POST['action'] ?? '';

    if ($act === 'create_plan' && isAdmin()) {
        $db->prepare("INSERT INTO event_plans (company_id, client_name, store_name, work_date, required_count, note)
                      VALUES (?,?,?,?,?,?)")
           ->execute([
               $cid,
               trim($_POST['plan_client'] ?? ''),
               trim($_POST['plan_store']  ?? ''),
               $_POST['plan_date']  ?? date('Y-m-d'),
               max(1, (int)($_POST['plan_count'] ?? 1)),
               trim($_POST['plan_note'] ?? ''),
           ]);
    }

    if ($act === 'delete_plan' && isAdmin()) {
        $db->prepare("DELETE FROM event_plans WHERE id=? AND company_id=? AND status='pending'")
           ->execute([(int)$_POST['plan_id'], $cid]);
    }

    redirect(BASE_PATH . '/public/sales_event_calendar.php?year='.$year.'&month='.$month);
}

// ─────────────────────────────────────────────
// データ取得
// ─────────────────────────────────────────────
$clientFilter = (int)($_GET['client_id'] ?? 0);
$empFilter    = getSalesPageNameFilter();

// 確定イベントカレンダー（詳細フィールド付き独自クエリ）
$_startDate = sprintf('%04d-%02d-01', $year, $month);
$_endDate   = date('Y-m-t', strtotime($_startDate));
$_cSql = "SELECT sc.id, COALESCE(NULLIF(TRIM(cl.display_name),''), cl.client_name) AS client_name, sc.store_name, sc.worker_name, sc.worker_type,
                 COALESCE(NULLIF(TRIM(al.display_name),''), al.alliance_name) AS alliance_name, sc.start_date, sc.end_date
          FROM sales_cases sc
          LEFT JOIN sales_clients cl ON sc.client_id = cl.id
          LEFT JOIN sales_alliances al ON sc.alliance_id = al.id
          WHERE sc.company_id=? AND sc.case_type='event' AND sc.status='confirmed'
            AND sc.start_date <= ? AND sc.end_date >= ?";
$_cParams = [$cid, $_endDate, $_startDate];
if ($clientFilter) { $_cSql .= " AND sc.client_id=?"; $_cParams[] = $clientFilter; }
if ($empFilter)    { $_cSql .= " AND sc.worker_name=?"; $_cParams[] = $empFilter; }
$_cSql .= " ORDER BY sc.start_date, client_name";
$_cStmt = $db->prepare($_cSql);
$_cStmt->execute($_cParams);

$confirmedByDay = [];
foreach ($_cStmt->fetchAll() as $_c) {
    $_sd = max($_startDate, $_c['start_date']);
    $_ed = min($_endDate,   $_c['end_date']);
    $_cur = $_sd;
    while ($_cur <= $_ed) {
        $confirmedByDay[(int)date('j', strtotime($_cur))][] = $_c;
        $_cur = date('Y-m-d', strtotime($_cur . ' +1 day'));
    }
}

// 後方互換: $calendar は他の箇所で使わないが念のため
$calendar = $confirmedByDay;

// 予定案件（event_plans から当月 pending のみ）
$planStmt = $db->prepare("SELECT * FROM event_plans
    WHERE company_id=? AND status='pending'
      AND YEAR(work_date)=? AND MONTH(work_date)=?
    ORDER BY work_date, id");
$planStmt->execute([$cid, $year, $month]);
$plans = $planStmt->fetchAll();

// 未確定のイベント案件（案件人員一覧などから登録され、まだ稼働者が決まっていないもの）。
// 未確定は event_plans ではなく sales_cases に入るため、ここで別途読み込んで
// 「予定・未確定カレンダー」に一緒に並べる。
//
// 人員をアサインすると、稼働者入りの確定案件が別に1件作られて左のカレンダーに出る。
// こちらの枠には「まだ足りない人数」だけを出したいので、アサイン済みの人数を数えて
// 必要人数から引く。全員そろった枠は右カレンダーから消える
// （予定案件が pending → confirmed で消えるのとまったく同じ見え方）
$_dSql = "SELECT sc.id, sc.store_name, sc.start_date, sc.end_date, sc.recruitment_count,
                 sc.note, sc.case_year, sc.case_month,
                 COALESCE(NULLIF(TRIM(cl.display_name),''), cl.client_name) AS client_name,
                 (SELECT COUNT(*) FROM case_staff_candidates csc
                   WHERE csc.company_id = sc.company_id
                     AND csc.assigned_case_id = sc.id
                     AND csc.assign_status = 'アサイン済') AS assigned_count
          FROM sales_cases sc
          LEFT JOIN sales_clients cl ON sc.client_id = cl.id
          WHERE sc.company_id=? AND sc.case_type='event' AND sc.status='draft'
            AND sc.start_date IS NOT NULL
            AND sc.start_date <= ? AND COALESCE(sc.end_date, sc.start_date) >= ?";
$_dParams = [$cid, $_endDate, $_startDate];
if ($clientFilter) { $_dSql .= " AND sc.client_id=?"; $_dParams[] = $clientFilter; }
$_dSql .= " ORDER BY sc.start_date, client_name";
$_dStmt = $db->prepare($_dSql);
$_dStmt->execute($_dParams);

// 予定案件と同じ形に揃えて扱う（is_case で見分ける）
$draftCases = [];
foreach ($_dStmt->fetchAll() as $_d) {
    $_need     = $_d['recruitment_count'] !== null ? (int)$_d['recruitment_count'] : null;
    $_assigned = (int)$_d['assigned_count'];
    // 必要人数が未入力の枠は「1名必要」とみなす（予定案件の扱いと合わせる）。
    // アサインが必要人数に達した枠は、もう募集していないので右カレンダーには出さない
    if ($_assigned >= ($_need ?? 1)) continue;
    $draftCases[] = [
        'id'             => (int)$_d['id'],
        'client_name'    => (string)($_d['client_name'] ?? ''),
        'store_name'     => (string)($_d['store_name'] ?? ''),
        'work_date'      => (string)$_d['start_date'],
        'end_date'       => (string)($_d['end_date'] ?: $_d['start_date']),
        // 表示・集計に使うのは「残り何名必要か」
        'required_count' => $_need !== null ? max(0, $_need - $_assigned) : null,
        'total_count'    => $_need,       // もともとの必要人数（ツールチップ用）
        'assigned_count' => $_assigned,   // すでにアサインした人数
        'note'           => (string)($_d['note'] ?? ''),
        'is_case'        => true,   // 案件人員一覧などから登録された未確定案件
    ];
}

// 月次集計
$planByDay = [];
foreach ($plans as $p) {
    $d = (int)date('j', strtotime($p['work_date']));
    $p['is_case'] = false;
    $planByDay[$d][] = $p;
}
// 未確定案件は期間があるので、開始日から終了日までの全日に出す
foreach ($draftCases as $c) {
    $_sd = max($_startDate, $c['work_date']);
    $_ed = min($_endDate,   $c['end_date']);
    for ($_cur = $_sd; $_cur <= $_ed; $_cur = date('Y-m-d', strtotime($_cur . ' +1 day'))) {
        $planByDay[(int)date('j', strtotime($_cur))][] = $c;
    }
}
ksort($planByDay);

// 下の「予定案件一覧」にも未確定案件を混ぜる（開始日の早い順）
$plansAndDrafts = array_merge(
    array_map(function ($p) { $p['is_case'] = false; return $p; }, $plans),
    $draftCases
);
usort($plansAndDrafts, fn($a, $b) => strcmp((string)$a['work_date'], (string)$b['work_date']));

$clients = getSalesClients($cid);
$colors = ['#3b82f6','#ef4444','#10b981','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#14b8a6'];
$clientColors = [];
$ci = 0;
foreach ($clients as $cl) {
    $clientColors[$cl['client_name']] = $colors[$ci % count($colors)];
    $ci++;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDow    = (int)date('w', mktime(0,0,0,$month,1,$year));
$today       = date('j');
$thisYear    = (int)date('Y');
$thisMon     = (int)date('n');
$isCurrentMonth = ($year === $thisYear && $month === $thisMon);

// 月集計
$confirmedTotal = 0;
foreach ($calendar as $evs) { $confirmedTotal += count($evs); }
$planTotal      = count($plansAndDrafts);
$planCountSum   = 0;
foreach ($plansAndDrafts as $_p) { $planCountSum += (int)($_p['required_count'] ?? 0); }

$prevM = $month-1; $prevY = $year;
if ($prevM<1) { $prevM=12; $prevY--; }
$nextM = $month+1; $nextY = $year;
if ($nextM>12) { $nextM=1; $nextY++; }

require_once __DIR__ . '/../includes/header.php';

// カレンダーグリッド描画関数
function renderCalendarGrid(array $byDay, int $firstDow, int $daysInMonth, bool $isCurrentMonth, int $today, string $mode, array $clientColors): void {
    $dow = ['日','月','火','水','木','金','土'];
    echo '<table class="table table-bordered mb-0" style="table-layout:fixed;font-size:.6rem">';
    echo '<thead class="table-light"><tr>';
    foreach ($dow as $i => $d) {
        $cls = $i===0 ? 'text-danger' : ($i===6 ? 'text-primary' : '');
        echo '<th class="text-center '.$cls.'" style="width:14.28%;padding:.2rem">'.$d.'</th>';
    }
    echo '</tr></thead><tbody>';

    $day = 1;
    for ($week=0; $week<6; $week++) {
        if ($day > $daysInMonth) break;
        echo '<tr>';
        for ($d=0; $d<7; $d++) {
            $cellDay = ($week===0 && $d<$firstDow) ? null : ($day > $daysInMonth ? null : $day);
            if ($cellDay === null) {
                echo '<td class="bg-light" style="height:80px;vertical-align:top;padding:.15rem"></td>';
            } else {
                $isToday = $isCurrentMonth && $cellDay===$today;
                $borderClass = $isToday ? 'border border-primary border-2' : '';
                $dowCls = ($d===0) ? 'text-danger' : ($d===6 ? 'text-primary' : '');
                echo '<td class="'.$borderClass.'" style="height:80px;vertical-align:top;padding:.15rem;overflow:hidden">';
                echo '<div class="'.$dowCls.'" style="font-weight:600;font-size:.65rem;margin-bottom:2px">'.$cellDay.'</div>';

                $events = $byDay[$cellDay] ?? [];
                foreach ($events as $ev) {
                    if ($mode === 'confirmed') {
                        $client   = $ev['client_name']  ?? '';
                        $store    = $ev['store_name']   ?? '';
                        $worker   = $ev['worker_name']  ?? '';
                        $wtype    = $ev['worker_type']  ?? '';
                        $alliance = $ev['alliance_name']?? '';
                        $color    = $clientColors[$client] ?? '#6b7280';
                        $bg       = 'rgba('.implode(',',array_map('intval',sscanf($color,'#%02x%02x%02x'))).', 0.18)';
                        // tooltip text
                        $tip  = 'クライアント: '.$client;
                        if ($store)  $tip .= "\n稼働店舗: ".$store;
                        if ($worker) $tip .= "\nスタッフ: ".$worker;
                        if ($wtype==='アライアンス' && $alliance) $tip .= "\n外注先: ".$alliance;
                        echo '<div title="'.htmlspecialchars($tip, ENT_QUOTES).'" style="cursor:default;border-left:2px solid '.$color.';background:'.$bg.';padding:1px 3px;margin-bottom:2px;border-radius:2px;line-height:1.3;overflow:hidden">';
                        echo '<div style="color:'.$color.';font-weight:700">'.h(mb_substr($client,0,6,'UTF-8')).'</div>';
                        if ($store)  echo '<div style="color:#374151">'.h(mb_substr($store,0,8,'UTF-8')).'</div>';
                        if ($worker) echo '<div style="color:#6b7280">'.h(mb_substr($worker,0,8,'UTF-8')).'</div>';
                        echo '</div>';
                    } else {
                        // 予定案件（event_plans）と、未確定の案件（sales_cases）が並ぶ。
                        // 未確定案件は「案件」の印を付けて、どちらから登録したものか分かるようにする
                        $client = $ev['client_name']   ?? '';
                        $store  = $ev['store_name']    ?? '';
                        $isCase = !empty($ev['is_case']);
                        $count  = $ev['required_count'] !== null ? (int)$ev['required_count'] : null;
                        // 予定案件は必要人数が必ず入る。未確定案件は未入力のこともある
                        if (!$isCase && $count === null) $count = 1;
                        $color  = $isCase ? '#2563eb' : '#f59e0b';
                        $bg     = $isCase ? 'rgba(37,99,235,.10)' : 'rgba(245,158,11,.12)';

                        // 未確定案件はアサイン済みを引いた「残り人数」を出す
                        $assigned = (int)($ev['assigned_count'] ?? 0);
                        $total    = $ev['total_count'] ?? null;

                        $tip = ($isCase ? '未確定の案件' : '予定案件') . "\nクライアント: " . $client;
                        if ($store) $tip .= "\n稼働店舗: ".$store;
                        if ($isCase && $assigned > 0 && $total !== null) {
                            $tip .= "\n必要人数: " . (int)$total . '名（アサイン済 ' . $assigned . '名）';
                            $tip .= "\n残り: " . (int)$count . '名';
                        } else {
                            $tip .= "\n必要人数: " . ($count !== null ? $count.'名' : '未設定');
                        }

                        echo '<div title="'.htmlspecialchars($tip, ENT_QUOTES).'" style="cursor:default;border-left:2px solid '.$color.';background:'.$bg.';padding:1px 3px;margin-bottom:2px;border-radius:2px;line-height:1.3;overflow:hidden">';
                        echo '<div style="color:'.$color.';font-weight:700">';
                        if ($isCase) echo '<span class="ec-case-dot">案</span>';
                        echo h(mb_substr($client,0,6,'UTF-8')).'</div>';
                        if ($store) echo '<div style="color:#374151">'.h(mb_substr($store,0,8,'UTF-8')).'</div>';
                        if ($count !== null) {
                            $label = ($isCase && $assigned > 0) ? '残り'.$count.'名' : $count.'名必要';
                            echo '<div style="color:#ef4444;font-weight:600">'.$label.'</div>';
                        }
                        echo '</div>';
                    }
                }
                echo '</td>';
                $day++;
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-calendar-range me-2"></i>イベントシフトカレンダー</h1>
                <p class="text-muted small mb-0">確定案件と予定（未確定含む）を比較し、リソースの過不足を把握できます。</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <select onchange="location.href='?year='+this.value+'&month=<?= $month ?>'" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y=date('Y')+1; $y>=2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month='+this.value" class="form-select form-select-sm" style="width:80px">
                    <?php for ($m=1; $m<=12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month==$m?'selected':'' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
                <?php if (isAdmin()): ?>
                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#planModal">
                    <i class="bi bi-plus"></i> 予定案件追加
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2画面カレンダー -->
    <div class="row g-2 mb-4">
        <!-- 左: 確定イベントカレンダー -->
        <div class="col-xl-6 col-12">
            <div class="card h-100" style="border:2px solid #3b82f6">
                <div class="card-header" style="background:#eff6ff;border-bottom:2px solid #3b82f6">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold" style="color:#1d4ed8"><i class="bi bi-calendar-check me-1"></i>確定イベントカレンダー</span>
                        <div style="font-size:.7rem;color:#6b7280">
                            <span class="me-2"><span style="display:inline-block;width:10px;height:10px;background:#3b82f6;border-radius:2px"></span> 確定案件</span>
                        </div>
                    </div>
                    <div style="font-size:.68rem;color:#6b7280;margin-top:2px">確定済みのイベント案件を表示（稼働者まで確定）</div>
                </div>
                <div class="card-body p-1">
                    <?php renderCalendarGrid($confirmedByDay, $firstDow, $daysInMonth, $isCurrentMonth, (int)$today, 'confirmed', $clientColors); ?>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center" style="background:#eff6ff;font-size:.78rem">
                    <span><i class="bi bi-people me-1"></i>確定稼働数（延べ人数）</span>
                    <span class="fw-bold" style="color:#1d4ed8">今月合計 <?= $confirmedTotal ?> 人</span>
                </div>
            </div>
        </div>

        <!-- 右: 予定・未確定カレンダー -->
        <div class="col-xl-6 col-12">
            <div class="card h-100" style="border:2px solid #10b981">
                <div class="card-header" style="background:#f0fdf4;border-bottom:2px solid #10b981">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold" style="color:#065f46"><i class="bi bi-calendar-plus me-1"></i>予定・未確定カレンダー</span>
                        <div style="font-size:.7rem;color:#6b7280">
                            <span class="me-2"><span style="display:inline-block;width:10px;height:10px;background:#f59e0b;border-radius:2px"></span> 予定案件</span>
                            <span><span class="ec-case-dot">案</span> 未確定の案件</span>
                        </div>
                    </div>
                    <div style="font-size:.68rem;color:#6b7280;margin-top:2px">
                        未確定・予定の案件を表示（稼働者は未確定）。
                        <span style="color:#2563eb">「案」</span>は案件人員一覧などから登録された案件です
                    </div>
                </div>
                <div class="card-body p-1">
                    <?php renderCalendarGrid($planByDay, $firstDow, $daysInMonth, $isCurrentMonth, (int)$today, 'pending', $clientColors); ?>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center" style="background:#f0fdf4;font-size:.78rem">
                    <span><i class="bi bi-calendar3 me-1"></i>予定稼働数（延べ人数）</span>
                    <span class="fw-bold" style="color:#065f46">今月合計 <?= $planTotal ?>件 / <?= $planCountSum ?>名予定</span>
                </div>
            </div>
        </div>
    </div>

    <!-- リソース状況サマリー + 予定案件一覧 -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header fw-bold" style="font-size:.85rem"><i class="bi bi-bar-chart me-1" style="color:#3b82f6"></i>リソース状況サマリー</div>
                <div class="card-body">
                    <div class="row g-2 text-center">
                        <div class="col-6 col-md-3">
                            <div class="p-2 rounded" style="background:#eff6ff">
                                <div style="font-size:.7rem;color:#6b7280">確定稼働数</div>
                                <div class="fw-bold" style="color:#1d4ed8;font-size:1.4rem"><?= $confirmedTotal ?>人</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2 rounded" style="background:#f0fdf4">
                                <div style="font-size:.7rem;color:#6b7280">予定稼働数</div>
                                <div class="fw-bold" style="color:#065f46;font-size:1.4rem"><?= $planCountSum ?>人</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2 rounded" style="background:#fff7ed">
                                <div style="font-size:.7rem;color:#6b7280">未確定件数</div>
                                <div class="fw-bold" style="color:#92400e;font-size:1.4rem"><?= $planTotal ?>件</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2 rounded" style="background:#fef2f2">
                                <div style="font-size:.7rem;color:#6b7280">差分</div>
                                <div class="fw-bold" style="color:#991b1b;font-size:1.4rem"><?= max(0, $planCountSum - $confirmedTotal) ?>人</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 予定案件一覧 -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header fw-bold" style="font-size:.85rem"><i class="bi bi-list-ul me-1" style="color:#f59e0b"></i>予定案件一覧（未確定）</div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:200px;overflow-y:auto">
                        <table class="table table-sm mb-0" style="font-size:.72rem">
                            <thead class="table-light sticky-top">
                                <tr><th>稼働日</th><th>クライアント</th><th>店舗</th><th class="text-center">必要人数</th><?= isAdmin() ? '<th></th>' : '' ?></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($plansAndDrafts)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">予定案件がありません</td></tr>
                                <?php else: ?>
                                <?php foreach ($plansAndDrafts as $p): $_isCase = !empty($p['is_case']); ?>
                                <tr>
                                    <td>
                                        <?= date('m/d(D)', strtotime($p['work_date'])) ?>
                                        <?php if ($_isCase && !empty($p['end_date']) && $p['end_date'] !== $p['work_date']): ?>
                                        <span class="text-muted">〜<?= date('m/d', strtotime($p['end_date'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= h($p['client_name']) ?>
                                        <?php if ($_isCase): ?>
                                        <span class="ec-case-badge">案件</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($p['store_name'] ?? '') ?></td>
                                    <td class="text-center fw-bold" style="color:#d97706">
                                        <?= $p['required_count'] !== null ? (int)$p['required_count'] . '名' : '-' ?>
                                        <?php if ($_isCase && !empty($p['assigned_count'])): ?>
                                        <div class="text-muted fw-normal" style="font-size:.62rem">
                                            全<?= (int)$p['total_count'] ?>名中<?= (int)$p['assigned_count'] ?>名アサイン済
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <?php if ($_isCase): ?>
                                        <?php /* 未確定案件はイベント案件の画面で確定させる。ここでは削除しない */ ?>
                                        <a class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:.6rem"
                                           href="<?= BASE_PATH ?>/public/sales_events.php?year=<?= (int)$p['case_year'] ?>&month=<?= (int)$p['case_month'] ?>"
                                           title="イベント案件の画面で開く"><i class="bi bi-box-arrow-up-right"></i></a>
                                        <?php else: ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.6rem"><i class="bi bi-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- クライアント凡例 -->
    <div class="card mb-3">
        <div class="card-body py-2 d-flex flex-wrap gap-3">
            <?php foreach ($clientColors as $name => $color): ?>
            <span style="font-size:.72rem;display:flex;align-items:center;gap:4px">
                <span style="width:12px;height:12px;border-radius:2px;background:<?= $color ?>"></span>
                <?= h($name) ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (isAdmin()): ?>
<!-- 予定案件追加モーダル -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_plan">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-1"></i>予定案件追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">クライアント <span class="text-danger">*</span></label>
                            <input type="text" name="plan_client" class="form-control" list="planClientList" required placeholder="クライアント名を入力・選択">
                            <datalist id="planClientList">
                                <?php foreach ($clients as $cl): ?>
                                <option value="<?= h($cl['client_name']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">店舗名</label>
                            <input type="text" name="plan_store" class="form-control" placeholder="店舗名（任意）">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">稼働日 <span class="text-danger">*</span></label>
                            <input type="date" name="plan_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">必要人数 <span class="text-danger">*</span></label>
                            <input type="number" name="plan_count" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">メモ</label>
                            <textarea name="plan_note" class="form-control" rows="2" placeholder="任意のメモ"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-save me-1"></i>保存</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<style>
/* 未確定の案件（sales_cases）につける印。予定案件（event_plans）と見分けるため */
.ec-case-dot {
    display: inline-block; margin-right: 3px; padding: 0 3px;
    background: #2563eb; color: #fff; border-radius: 3px;
    font-size: .58rem; font-weight: 700; line-height: 1.5; vertical-align: 1px;
}
.ec-case-badge {
    display: inline-block; margin-left: 4px; padding: 0 5px;
    background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;
    border-radius: .7rem; font-size: .62rem; font-weight: 600;
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
