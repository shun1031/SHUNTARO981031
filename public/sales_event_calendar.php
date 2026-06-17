<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'イベントカレンダー';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
if (isset($_GET['month'])) {
    $month = (int)$_GET['month'];
} else {
    $month = (int)date('n');
    $db = getDB();
    $latestStmt = $db->prepare("SELECT MAX(case_month) FROM sales_cases WHERE company_id = ? AND case_year = ? AND case_type = 'event' AND status = 'confirmed'");
    $latestStmt->execute([$cid, $year]);
    $latestMonth = $latestStmt->fetchColumn();
    if ($latestMonth && (int)$latestMonth < $month) {
        $month = (int)$latestMonth;
    }
}
$clientFilter = (int)($_GET['client_id'] ?? 0);

$empFilter = getEmployeeNameFilter();
$calendar = getEventCalendar($cid, $year, $month, $clientFilter ?: null, $empFilter);
$clients = getSalesClients($cid);

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDow = date('w', mktime(0, 0, 0, $month, 1, $year));

$colors = ['#3b82f6','#ef4444','#10b981','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#14b8a6'];
$clientColors = [];
$ci = 0;
foreach ($clients as $cl) {
    $clientColors[$cl['client_name']] = $colors[$ci % count($colors)];
    $ci++;
}

$totalEvents = 0;
foreach ($calendar as $day => $events) { $totalEvents += count($events); }

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-calendar-range me-2"></i>イベントカレンダー</h1>
                <p><?= $year ?>年<?= $month ?>月　イベント配置: <strong><?= $totalEvents ?>件</strong></p>
            </div>
            <div class="d-flex gap-2">
                <select onchange="location.href='?year='+this.value+'&month=<?= $month ?>&client_id=<?= $clientFilter ?>'" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month='+this.value+'&client_id=<?= $clientFilter ?>'" class="form-select form-select-sm" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month=<?= $month ?>&client_id='+this.value" class="form-select form-select-sm" style="width:160px">
                    <option value="">全クライアント</option>
                    <?php foreach ($clients as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= $clientFilter == $cl['id'] ? 'selected' : '' ?>><?= h($cl['client_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-2">
            <div class="table-responsive"><table class="table table-bordered mb-0" style="table-layout:fixed">
                <thead>
                    <tr>
                        <th class="text-center text-danger" style="width:14.28%">日</th>
                        <th class="text-center" style="width:14.28%">月</th>
                        <th class="text-center" style="width:14.28%">火</th>
                        <th class="text-center" style="width:14.28%">水</th>
                        <th class="text-center" style="width:14.28%">木</th>
                        <th class="text-center" style="width:14.28%">金</th>
                        <th class="text-center text-primary" style="width:14.28%">土</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $day = 1;
                    $today = date('Y-m-d');
                    for ($week = 0; $week < 6; $week++):
                        if ($day > $daysInMonth) break;
                    ?>
                    <tr>
                        <?php for ($dow = 0; $dow < 7; $dow++):
                            if (($week === 0 && $dow < $firstDow) || $day > $daysInMonth):
                        ?>
                            <td class="bg-light" style="min-height:100px;height:100px"></td>
                        <?php else:
                            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $isToday = ($dateStr === $today);
                            $events = $calendar[$day] ?? [];
                            $isWeekend = ($dow === 0 || $dow === 6);
                        ?>
                            <td class="<?= $isWeekend ? 'bg-light' : '' ?> <?= $isToday ? 'border-primary border-2' : '' ?>" style="min-height:100px;height:100px;vertical-align:top;padding:4px;overflow:hidden">
                                <div class="fw-bold small <?= $dow === 0 ? 'text-danger' : ($dow === 6 ? 'text-primary' : '') ?>"><?= $day ?></div>
                                <?php foreach ($events as $ev):
                                    $color = $clientColors[$ev['client_name']] ?? '#6b7280';
                                    $workerList = $ev['workers'] ?: '未配置';
                                ?>
                                <div class="mb-1" style="font-size:.6rem;line-height:1.2;background:<?= $color ?>22;border-left:2px solid <?= $color ?>;padding:1px 3px;border-radius:2px;cursor:pointer"
                                     title="<?= h($ev['case_name']) ?>&#10;スタッフ: <?= h($workerList) ?>&#10;売上: <?= number_format($ev['revenue']) ?>円">
                                    <span style="color:<?= $color ?>;font-weight:600"><?= h(mb_substr($ev['client_name'], 0, 6)) ?></span>
                                    <?php if ($ev['workers']): ?>
                                    <br><span class="text-muted"><?= h(mb_substr($ev['workers'], 0, 15)) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </td>
                        <?php $day++; endif; endfor; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- 凡例 -->
    <?php if (!empty($clientColors)): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>クライアント凡例</strong></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ($clientColors as $name => $color): ?>
                <span style="font-size:.75rem"><span style="display:inline-block;width:12px;height:12px;background:<?= $color ?>;border-radius:2px;margin-right:4px"></span><?= h($name) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
