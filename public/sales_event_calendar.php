<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
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
$empFilter    = getEmployeeNameFilter();

// 確定イベントカレンダー（詳細フィールド付き独自クエリ）
$_startDate = sprintf('%04d-%02d-01', $year, $month);
$_endDate   = date('Y-m-t', strtotime($_startDate));
$_cSql = "SELECT sc.id, cl.client_name, sc.store_name, sc.worker_name, sc.worker_type,
                 al.alliance_name, sc.start_date, sc.end_date
          FROM sales_cases sc
          LEFT JOIN sales_clients cl ON sc.client_id = cl.id
          LEFT JOIN sales_alliances al ON sc.alliance_id = al.id
          WHERE sc.company_id=? AND sc.case_type='event' AND sc.status='confirmed'
            AND sc.start_date <= ? AND sc.end_date >= ?";
$_cParams = [$cid, $_endDate, $_startDate];
if ($clientFilter) { $_cSql .= " AND sc.client_id=?"; $_cParams[] = $clientFilter; }
if ($empFilter)    { $_cSql .= " AND sc.worker_name=?"; $_cParams[] = $empFilter; }
$_cSql .= " ORDER BY sc.start_date, cl.client_name";
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

// 月次集計
$planByDay = [];
foreach ($plans as $p) {
    $d = (int)date('j', strtotime($p['work_date']));
    $planByDay[$d][] = $p;
}

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
$planTotal      = count($plans);
$planCountSum   = array_sum(array_column($plans, 'required_count'));

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
                        $client = $ev['client_name']   ?? '';
                        $store  = $ev['store_name']    ?? '';
                        $count  = (int)($ev['required_count'] ?? 1);
                        $color  = '#f59e0b';
                        $tip    = 'クライアント: '.$client;
                        if ($store) $tip .= "\n稼働店舗: ".$store;
                        $tip .= "\n必要人数: ".$count.'名';
                        echo '<div title="'.htmlspecialchars($tip, ENT_QUOTES).'" style="cursor:default;border-left:2px solid '.$color.';background:rgba(245,158,11,.12);padding:1px 3px;margin-bottom:2px;border-radius:2px;line-height:1.3;overflow:hidden">';
                        echo '<div style="color:'.$color.';font-weight:700">'.h(mb_substr($client,0,6,'UTF-8')).'</div>';
                        if ($store) echo '<div style="color:#374151">'.h(mb_substr($store,0,8,'UTF-8')).'</div>';
                        echo '<div style="color:#ef4444;font-weight:600">'.$count.'名必要</div>';
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
                            <span class="me-2"><span style="display:inline-block;width:10px;height:10px;background:#10b981;border-radius:2px"></span> 確定済</span>
                            <span class="me-2"><span style="display:inline-block;width:10px;height:10px;background:#f59e0b;border-radius:2px"></span> 未確定</span>
                            <span><span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:2px"></span> 不足</span>
                        </div>
                    </div>
                    <div style="font-size:.68rem;color:#6b7280;margin-top:2px">未確定・予定の案件を表示（稼働者は未確定）</div>
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
                                <?php if (empty($plans)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">予定案件がありません</td></tr>
                                <?php else: ?>
                                <?php foreach ($plans as $p): ?>
                                <tr>
                                    <td><?= date('m/d(D)', strtotime($p['work_date'])) ?></td>
                                    <td><?= h($p['client_name']) ?></td>
                                    <td><?= h($p['store_name'] ?? '') ?></td>
                                    <td class="text-center fw-bold" style="color:#d97706"><?= (int)$p['required_count'] ?>名</td>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.6rem"><i class="bi bi-trash"></i></button>
                                        </form>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
