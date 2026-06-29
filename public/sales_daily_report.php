<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '日報管理';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// If no year/month specified in URL, find latest month with data
if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $latestRow = getDB()->prepare("SELECT YEAR(work_date) AS y, MONTH(work_date) AS m FROM sales_daily_reports WHERE company_id = ? ORDER BY work_date DESC LIMIT 1");
    $latestRow->execute([$cid]);
    $latest = $latestRow->fetch();
    if ($latest) {
        $year = (int)$latest['y'];
        $month = (int)$latest['m'];
    }
}

$empFilter = getEmployeeNameFilter();
$filterEmp = $empFilter ?? ($_GET['employee'] ?? '');

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    // 一般社員は自分のデータのみ操作可能。管理者は全データ操作可。
    $myName = getEmployeeNameFilter();

    if ($action === 'create' || $action === 'update') {
        $postEmpName = trim($_POST['employee_name'] ?? '');
        if ($myName !== null && $myName !== $postEmpName) {
            http_response_code(403);
            die('他のユーザーのデータは登録できません');
        }
        // 更新時は既存レコードの所有者も検証
        if ($action === 'update' && !empty($_POST['id'])) {
            $existing = getDB()->prepare('SELECT employee_name FROM sales_daily_reports WHERE id = ? AND company_id = ?');
            $existing->execute([(int)$_POST['id'], $cid]);
            $owner = $existing->fetchColumn();
            if ($myName !== null && $owner !== false && $owner !== $myName) {
                http_response_code(403);
                die('他のユーザーのデータは編集できません');
            }
        }
        saveDailyReport($cid, $_POST);
        redirect(BASE_PATH . '/public/sales_daily_report.php?year='.$year.'&month='.$month.'&msg=saved');
    }
    if ($action === 'delete') {
        $delId = (int)$_POST['id'];
        if ($myName !== null) {
            $existing = getDB()->prepare('SELECT employee_name FROM sales_daily_reports WHERE id = ? AND company_id = ?');
            $existing->execute([$delId, $cid]);
            $owner = $existing->fetchColumn();
            if ($owner !== false && $owner !== $myName) {
                http_response_code(403);
                die('他のユーザーのデータは削除できません');
            }
        }
        deleteDailyReport($delId, $cid);
        redirect(BASE_PATH . '/public/sales_daily_report.php?year='.$year.'&month='.$month.'&msg=deleted');
    }
}

$reports   = getDailyReports($cid, $year, $month, $filterEmp ?: null);
$employees = getReportEmployees($cid);
// 社員選択（Ajax 初期値: ログインユーザー or 全員）
$selectedEmp = $empFilter ?? ($filterEmp ?: '');
$drApiBase   = BASE_PATH . '/public/api/daily_report_kpi.php';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-journal-check me-2"></i>日報管理</h1>
                <p id="drSubtitle"><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center gap-1">
                    <button onclick="drChangeMonth(-1)" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</button>
                    <span id="drMonthLabel" class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                    <button onclick="drChangeMonth(1)" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</button>
                </div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="bi bi-plus"></i> 日報入力</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_GET['msg'] === 'saved' ? '保存しました' : '削除しました' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ① 社員選択 + KPI + 一覧 (Ajax) -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <?php if (isAdmin()): ?>
        <div class="d-flex align-items-center gap-2">
            <label class="fw-semibold small mb-0" style="white-space:nowrap">社員を選択</label>
            <select id="drEmpSelect" class="form-select form-select-sm" style="width:200px">
                <?php foreach ($employees as $e): ?>
                <option value="<?= h($e) ?>" <?= $selectedEmp === $e ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endforeach; ?>
                <?php if (!$selectedEmp): ?><option value="" selected>全員</option><?php endif; ?>
            </select>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <button id="drWeekBtn" class="btn btn-outline-secondary btn-sm" onclick="drToggleWeek()">
            <i class="bi bi-calendar-week me-1"></i>直近1週間
        </button>
    </div>

    <!-- ② KPIカード -->
    <div class="row g-3 mb-4" id="drKpiRow">
        <?php
        $kpiDefs = [
            ['key'=>'catch_count',        'label'=>'キャッチ数', 'icon'=>'bi-megaphone',    'color'=>'#2563eb'],
            ['key'=>'event_seated',       'label'=>'着座数',     'icon'=>'bi-person-check', 'color'=>'#059669'],
            ['key'=>'event_proposals',    'label'=>'提案数',     'icon'=>'bi-clipboard-check','color'=>'#d97706'],
            ['key'=>'event_negotiations', 'label'=>'昇段数',     'icon'=>'bi-graph-up-arrow','color'=>'#7c3aed'],
            ['key'=>'event_contracts',    'label'=>'成約数',     'icon'=>'bi-handshake',    'color'=>'#dc2626'],
        ];
        foreach ($kpiDefs as $kd): ?>
        <div class="col-6 col-md">
            <div class="card h-100 kpi-card" data-key="<?= $kd['key'] ?>" style="border-top:3px solid <?= $kd['color'] ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi <?= $kd['icon'] ?> fs-5" style="color:<?= $kd['color'] ?>"></i>
                        <span class="fw-bold small" style="color:<?= $kd['color'] ?>"><?= $kd['label'] ?></span>
                    </div>
                    <div class="kpi-month-label text-muted" style="font-size:.68rem">月合計</div>
                    <div class="kpi-month-val fw-bold" style="font-size:1.8rem;color:<?= $kd['color'] ?>">- <small style="font-size:.8rem">件</small></div>
                    <div class="kpi-prev text-muted" style="font-size:.7rem">前月比 読込中...</div>
                    <div class="kpi-week" style="display:none;margin-top:4px;padding-top:4px;border-top:1px dashed #e5e7eb;font-size:.72rem;color:#6b7280">
                        直近1週間: <span class="kpi-week-val fw-bold">-</span> 件
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ③ 日報一覧 -->
    <div class="card mb-3">
        <div class="card-header fw-bold"><i class="bi bi-list-ul me-1"></i>日報一覧
            <span class="ms-2 text-muted fw-normal" style="font-size:.78rem">個人獲得内訳 / 全体獲得内訳</span>
        </div>
        <div class="card-body p-0" id="drListWrap">
            <div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat"></i> 読込中...</div>
        </div>
    </div>

    <!-- 旧月間サマリー（非表示） -->
    <?php if (false && !empty([])): ?>
    <div class="card mb-3" style="display:none">
        <div class="card-header"><strong><i class="bi bi-bar-chart me-1"></i>月間サマリー</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.7rem">
                    <thead class="table-light">
                        <tr>
                            <th>社員名</th><th class="text-center">日数</th>
                            <th class="text-center">声掛</th><th class="text-center">相談</th><th class="text-center">着席</th>
                            <th class="text-center" style="background:#e0f2fe">SB MNP</th><th class="text-center" style="background:#e0f2fe">新規</th><th class="text-center" style="background:#e0f2fe">機変</th><th class="text-center" style="background:#e0f2fe">UP</th>
                            <th class="text-center" style="background:#fef3c7">YM MNP</th><th class="text-center" style="background:#fef3c7">新規</th><th class="text-center" style="background:#fef3c7">機変</th><th class="text-center" style="background:#fef3c7">DG</th>
                            <th class="text-center" style="background:#fce7f3">au MNP</th><th class="text-center" style="background:#fce7f3">新規</th><th class="text-center" style="background:#fce7f3">機変</th><th class="text-center" style="background:#fce7f3">UP</th>
                            <th class="text-center" style="background:#ede9fe">UQ MNP</th><th class="text-center" style="background:#ede9fe">新規</th><th class="text-center" style="background:#ede9fe">機変</th><th class="text-center" style="background:#ede9fe">DG</th>
                            <th class="text-center">光</th><th class="text-center">Air</th><th class="text-center">でんわ</th><th class="text-center">PayPay</th><th class="text-center">でんき</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $s): ?>
                        <tr>
                            <td class="fw-medium"><?= h($s['employee_name']) ?></td>
                            <td class="text-center"><?= $s['work_days'] ?></td>
                            <td class="text-center"><?= $s['total_contacts'] ?></td>
                            <td class="text-center"><?= $s['total_consultations'] ?></td>
                            <td class="text-center"><?= $s['total_seated'] ?></td>
                            <td class="text-center"><?= $s['sb_mnp'] ?></td><td class="text-center"><?= $s['sb_new'] ?></td><td class="text-center"><?= $s['sb_change'] ?></td><td class="text-center"><?= $s['sb_upgrade'] ?></td>
                            <td class="text-center"><?= $s['ym_mnp'] ?></td><td class="text-center"><?= $s['ym_new'] ?></td><td class="text-center"><?= $s['ym_change'] ?></td><td class="text-center"><?= $s['ym_downgrade'] ?></td>
                            <td class="text-center"><?= $s['au_mnp'] ?></td><td class="text-center"><?= $s['au_new'] ?></td><td class="text-center"><?= $s['au_change'] ?></td><td class="text-center"><?= $s['au_upgrade'] ?></td>
                            <td class="text-center"><?= $s['uq_mnp'] ?></td><td class="text-center"><?= $s['uq_new'] ?></td><td class="text-center"><?= $s['uq_change'] ?></td><td class="text-center"><?= $s['uq_downgrade'] ?></td>
                            <td class="text-center"><?= $s['sb_hikari'] ?></td><td class="text-center"><?= $s['sb_air'] ?></td><td class="text-center"><?= $s['ouchi_denwa'] ?></td><td class="text-center"><?= $s['paypay_card'] ?></td><td class="text-center"><?= $s['ouchi_denki'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- 日報入力モーダル -->
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="reportForm">
                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-check me-1"></i>日報入力</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- 基本情報 -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">社員名</label>
                            <?php if ($empFilter): ?>
                            <input type="text" name="employee_name" class="form-control form-control-sm" value="<?= h($empFilter) ?>" readonly>
                            <?php else: ?>
                            <input type="text" name="employee_name" class="form-control form-control-sm" required list="empList">
                            <datalist id="empList">
                                <?php foreach ($employees as $e): ?>
                                <option value="<?= h($e) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <?php endif; ?>
                            <div class="form-text" style="font-size:.7rem;color:#6b7280">フルネームで入力してください</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">稼働日</label>
                            <input type="date" name="work_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">稼働店舗</label>
                            <input type="text" name="location" id="drLocation" class="form-control form-control-sm" placeholder="店舗名を入力" required>
                            <div class="form-text" style="font-size:.7rem;color:#6b7280">正式名称で入力してください</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">キャリア</label>
                            <select name="carrier" id="drCarrier" class="form-select form-select-sm">
                                <option value="">選択</option>
                                <option value="SB,YM">SB / Y!mobile</option>
                                <option value="au,UQ">au / UQ</option>
                                <option value="ドコモ">ドコモ</option>
                                <option value="楽天">楽天</option>
                                <option value="コミュファ">コミュファ光</option>
                                <option value="CATV">CATV</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">業務形態</label>
                            <select name="work_type" id="drWorkType" class="form-select form-select-sm" required>
                                <option value="">選択してください</option>
                                <option value="常勤">常勤</option>
                                <option value="イベント">イベント</option>
                            </select>
                        </div>
                    </div>

                    <!-- 統一フォーム（業務形態選択後に表示） -->
                    <div id="drEventForm" style="display:none">
                        <div class="border rounded p-2" style="background:#fffbeb">
                            <div class="fw-bold mb-2" style="color:#b45309;font-size:.85rem"><i class="bi bi-lightning me-1"></i>日報</div>
                            <!-- 数値フィールド（コンパクト） -->
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">キャッチ数</div><input type="number" name="catch_count" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">着席数</div><input type="number" name="event_seated" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">提案数</div><input type="number" name="event_proposals" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">商談数</div><input type="number" name="event_negotiations" id="evtNegotiations" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">成約数</div><input type="number" name="event_contracts" id="evtContracts" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                            </div>
                            <!-- 全体獲得内訳 -->
                            <div class="border rounded p-2 mb-2 bg-white">
                                <div style="font-size:.78rem;font-weight:600;margin-bottom:3px">全体獲得内訳 <span class="text-muted fw-normal" style="font-size:.68rem">（0件は空欄）</span></div>
                                <div id="evtAcqFields"><p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p></div>
                                <input type="hidden" name="event_acquisition_detail" id="evtAcqJson">
                            </div>
                            <!-- 個人獲得内訳 -->
                            <div class="border rounded p-2 bg-white">
                                <div style="font-size:.78rem;font-weight:600;margin-bottom:3px">個人獲得内訳 <span class="text-muted fw-normal" style="font-size:.68rem">（0件は空欄）</span></div>
                                <div id="perAcqFields"><p class="text-muted small text-center mb-0 py-1">全体獲得内訳を入力すると活性化されます</p></div>
                                <input type="hidden" name="personal_acquisition_detail" id="perAcqJson">
                            </div>
                            <!-- 削除された項目用 hidden（保存エラー防止） -->
                            <input type="hidden" name="fixed_check_detail" id="fixChkJson" value="">
                            <input type="hidden" name="fixed_acquisition_detail" id="fixAcqJson" value="">
                            <input type="hidden" name="event_reflection" value="">
                        </div>
                    </div>

                    <!-- ショップフォーム用 hidden（保存エラー防止） -->
                    <input type="hidden" name="shop_acquisition_detail" value="">
                    <input type="hidden" name="shop_fixed_check_detail" value="">

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="drSubmitBtn" disabled>保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var CARRIER_ITEMS = {
        'SB,YM':   ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','Air新規','Airキヘン','SBでんき','PayPayカード'],
        'au,UQ':   ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','auでんき','au PAYカード'],
        'ドコモ':  ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','ドコモでんき','dカード'],
        '楽天':    ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','楽天でんき','楽天カード'],
        'コミュファ':['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G'],
        'CATV':    ['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','電力系→1G','電力系→10G','その他光→1G','その他光→10G']
    };

    function makeItemCol(id, label, disabled) {
        var col = document.createElement('div');
        col.className = 'col-auto';
        var lbl = document.createElement('div');
        lbl.style.cssText = 'font-size:.65rem;font-weight:600;margin-bottom:2px;white-space:nowrap;text-align:center';
        lbl.title = label;
        lbl.textContent = label;
        var inp = document.createElement('input');
        inp.type = 'number'; inp.id = id;
        inp.className = 'form-control form-control-sm text-center';
        inp.min = '0'; inp.step = '1'; inp.placeholder = '-';
        inp.style.cssText = 'width:55px';
        if (disabled) { inp.disabled = true; inp.style.background = '#f3f4f6'; }
        col.appendChild(lbl); col.appendChild(inp);
        return col;
    }

    // ---- イベント: 全体獲得内訳 ----
    function buildEvtAcq(carrier) {
        var wrap = document.getElementById('evtAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>'; buildPerAcq(carrier); return; }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('evtacq_' + i, label, false)); });
        wrap.appendChild(row);
        buildPerAcq(carrier);
    }

    // ---- イベント: 個人獲得内訳 ----
    function buildPerAcq(carrier) {
        var wrap = document.getElementById('perAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">全体獲得内訳を入力すると活性化されます</p>'; return; }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) {
            var ev = document.getElementById('evtacq_' + i);
            row.appendChild(makeItemCol('peracq_' + i, label, !(ev && ev.value.trim())));
        });
        wrap.appendChild(row);
    }

    document.getElementById('evtAcqFields').addEventListener('input', function(e) {
        if (!e.target.id || !e.target.id.startsWith('evtacq_')) return;
        var idx = e.target.id.slice(7);
        var per = document.getElementById('peracq_' + idx);
        if (!per) return;
        if (e.target.value.trim() === '') { per.disabled = true; per.value = ''; per.style.background = '#f3f4f6'; }
        else { per.disabled = false; per.style.background = ''; }
    });

    // ---- ショップ: 全体獲得内訳 ----
    function buildShopAcq(carrier) {
        var wrap = document.getElementById('shopAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>'; buildShopPerAcq(carrier); return; }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('shopacq_' + i, label, false)); });
        wrap.appendChild(row);
        buildShopPerAcq(carrier);
    }

    // ---- ショップ: 個人獲得内訳 ----
    function buildShopPerAcq(carrier) {
        var wrap = document.getElementById('shopPerAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>'; return; }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('shopperacq_' + i, label, false)); });
        wrap.appendChild(row);
    }

    document.getElementById('drCarrier').addEventListener('change', function() {
        if (document.getElementById('drEventForm').style.display !== 'none') buildEvtAcq(this.value);
    });

    function updateDrForm() {
        var wt = document.getElementById('drWorkType').value;
        var show = !!wt;
        document.getElementById('drEventForm').style.display = show ? 'block' : 'none';
        document.getElementById('drSubmitBtn').disabled = !wt;
        var c = document.getElementById('drCarrier').value;
        if (show && c) buildEvtAcq(c);
    }
    document.getElementById('drLocation').addEventListener('input', updateDrForm);
    document.getElementById('drWorkType').addEventListener('change', updateDrForm);

    document.getElementById('reportForm').addEventListener('submit', function(e) {
        var evForm = document.getElementById('drEventForm');
        var carrier = document.getElementById('drCarrier').value;
        var items = CARRIER_ITEMS[carrier] || [];

        function nonZero(v) { return v !== '' && v !== null && parseFloat(v) !== 0; }

        if (evForm.style.display !== 'none') {
            var evtAcq = {}, perAcq = {};
            items.forEach(function(label, i) {
                var ev = document.getElementById('evtacq_' + i); if (ev && nonZero(ev.value)) evtAcq[label] = ev.value;
                var per = document.getElementById('peracq_' + i); if (per && !per.disabled && nonZero(per.value)) perAcq[label] = per.value;
            });
            document.getElementById('evtAcqJson').value = JSON.stringify(evtAcq);
            document.getElementById('perAcqJson').value = JSON.stringify(perAcq);
        }
    });

    document.getElementById('reportModal').addEventListener('show.bs.modal', function() {
        document.getElementById('reportForm').reset();
        document.getElementById('drLocation').value = '';
        document.getElementById('drWorkType').value = '';
        document.getElementById('drCarrier').value  = '';
        document.getElementById('evtAcqFields').innerHTML     = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>';
        document.getElementById('perAcqFields').innerHTML = '<p class="text-muted small text-center mb-0 py-1">全体獲得内訳を入力すると活性化されます</p>';
        updateDrForm();
    });
})();
</script>

<script>
(function(){
    var drYear = <?= $year ?>, drMonth = <?= $month ?>;
    var drEmp  = <?= json_encode($selectedEmp) ?>;
    var drWeekMode = false;
    var drApiBase  = <?= json_encode($drApiBase) ?>;
    var drCsrf     = <?= json_encode(getCsrfToken()) ?>;
    var KPI_KEYS   = ['catch_count','event_seated','event_proposals','event_negotiations','event_contracts'];
    var KPI_LABELS = ['キャッチ数','着座数','提案数','昇段数','成約数'];

    function drLoad() {
        var url = drApiBase + '?employee=' + encodeURIComponent(drEmp) + '&year=' + drYear + '&month=' + drMonth;
        document.getElementById('drListWrap').innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat"></i> 読込中...</div>';
        fetch(url).then(function(r){return r.json();}).then(function(d){
            drRenderKpi(d);
            drRenderList(d);
            document.getElementById('drMonthLabel').textContent = drYear + '年' + drMonth + '月';
            document.getElementById('drSubtitle').textContent   = drYear + '年' + drMonth + '月';
        }).catch(function(e){ console.error(e); });
    }

    function drRenderKpi(d) {
        var month = d.kpi_month, prev = d.kpi_prev, week = d.kpi_week;
        KPI_KEYS.forEach(function(k, i) {
            var card = document.querySelector('.kpi-card[data-key="' + k + '"]');
            if (!card) return;
            var mVal = parseInt(month[k]||0);
            var pVal = parseInt(prev[k]||0);
            var wVal = parseInt(week[k]||0);
            var diff = mVal - pVal;
            var pct  = pVal > 0 ? ((diff/pVal)*100).toFixed(1) : '-';
            card.querySelector('.kpi-month-val').innerHTML = mVal + ' <small style="font-size:.8rem">件</small>';
            var prevEl = card.querySelector('.kpi-prev');
            var sign = diff >= 0 ? '+' : '';
            var color = diff >= 0 ? '#059669' : '#ef4444';
            prevEl.innerHTML = '前月比 <span style="color:'+color+'">'+sign+diff+'件 ('+sign+pct+'%)</span>';
            var wkEl = card.querySelector('.kpi-week-val');
            if (wkEl) wkEl.textContent = wVal;
            card.querySelector('.kpi-week').style.display = drWeekMode ? 'block' : 'none';
        });
    }

    function drRenderList(d) {
        var reports = d.reports || [];
        var wrap = document.getElementById('drListWrap');
        if (!reports.length) {
            wrap.innerHTML = '<div class="text-center text-muted py-4">日報データがありません</div>';
            return;
        }
        // キャリア別にグループ化してテーブル作成
        var html = '';
        var byCarrier = {};
        reports.forEach(function(r){ (byCarrier[r.carrier||''] = byCarrier[r.carrier||''] || []).push(r); });

        Object.keys(byCarrier).forEach(function(carrier) {
            var rows = byCarrier[carrier];
            var items = (d.carrier_items||{})[carrier] || [];
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0" style="font-size:.72rem">';
            html += '<thead class="table-light"><tr>';
            html += '<th>日付</th><th>社員名</th><th>稼働店舗</th><th>キャリア</th>';
            html += '<th class="text-center">キャッチ</th><th class="text-center">着席</th><th class="text-center">提案</th><th class="text-center">成約</th>';
            items.forEach(function(lbl) {
                html += '<th class="text-center" style="max-width:60px;font-size:.68rem">' + lbl + '</th>';
            });
            html += '<th>操作</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                html += '<tr>';
                html += '<td>' + r.work_date + '</td>';
                html += '<td>' + esc(r.employee) + '</td>';
                html += '<td>' + esc(r.location) + '</td>';
                html += '<td>' + esc(r.carrier) + '</td>';
                html += '<td class="text-center">' + (r.catch||0) + '</td>';
                html += '<td class="text-center">' + (r.seated||0) + '</td>';
                html += '<td class="text-center">' + (r.proposals||0) + '</td>';
                html += '<td class="text-center">' + (r.contracts||0) + '</td>';
                items.forEach(function(lbl) {
                    var a = (r.acq||{})[lbl];
                    var per = a ? (a.person||0) : 0;
                    var tot = a ? (a.total||0) : 0;
                    html += '<td class="text-center">' + per + ' / ' + tot + '</td>';
                });
                html += '<td><form method="post" style="display:inline" onsubmit="return confirm(\'削除しますか？\')">';
                html += '<input type="hidden" name="csrf" value="' + drCsrf + '">';
                html += '<input type="hidden" name="action" value="delete">';
                html += '<input type="hidden" name="id" value="' + r.id + '">';
                html += '<button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.6rem"><i class="bi bi-trash"></i></button>';
                html += '</form></td></tr>';
            });
            html += '</tbody></table></div>';
        });
        wrap.innerHTML = html;
    }

    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    window.drChangeMonth = function(delta) {
        drMonth += delta;
        if (drMonth < 1) { drMonth = 12; drYear--; }
        if (drMonth > 12) { drMonth = 1; drYear++; }
        drLoad();
    };

    window.drToggleWeek = function() {
        drWeekMode = !drWeekMode;
        var btn = document.getElementById('drWeekBtn');
        btn.classList.toggle('btn-outline-secondary', !drWeekMode);
        btn.classList.toggle('btn-secondary', drWeekMode);
        document.querySelectorAll('.kpi-week').forEach(function(el){
            el.style.display = drWeekMode ? 'block' : 'none';
        });
    };

    var empSel = document.getElementById('drEmpSelect');
    if (empSel) {
        empSel.addEventListener('change', function() {
            drEmp = this.value;
            drLoad();
        });
    }

    // 初回ロード
    drLoad();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
