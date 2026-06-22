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

$reports = getDailyReports($cid, $year, $month, $filterEmp ?: null);
$summary = getDailyReportSummary($cid, $year, $month, $empFilter);
$employees = getReportEmployees($cid);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-journal-check me-2"></i>日報管理</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <?php
            $prevM = $month - 1; $prevY = $year;
            if ($prevM < 1) { $prevM = 12; $prevY--; }
            $nextM = $month + 1; $nextY = $year;
            if ($nextM > 12) { $nextM = 1; $nextY++; }
            ?>
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center gap-1">
                    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                    <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
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

    <!-- 月間サマリー -->
    <?php if (!empty($summary)): ?>
    <div class="card mb-3">
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

    <!-- 日報一覧 -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-list-ul me-1"></i>日報一覧</strong>
            <?php if (isAdmin()): ?>
            <select onchange="location.href='?year=<?= $year ?>&month=<?= $month ?>&employee='+this.value" class="form-select form-select-sm" style="width:150px">
                <option value="">全員</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= h($e) ?>" <?= $filterEmp === $e ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.75rem">
                    <thead class="table-light">
                        <tr><th>日付</th><th>社員名</th><th>場所</th><th>キャリア</th><th class="text-center">声掛</th><th class="text-center">相談</th><th class="text-center">着席</th><th class="text-center">SB系</th><th class="text-center">YM系</th><th class="text-center">au系</th><th class="text-center">UQ系</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r):
                            $sbTotal = $r['sb_mnp'] + $r['sb_new'] + $r['sb_change'] + $r['sb_upgrade'];
                            $ymTotal = $r['ym_mnp'] + $r['ym_new'] + $r['ym_change'] + $r['ym_downgrade'];
                            $auTotal = $r['au_mnp'] + $r['au_new'] + $r['au_change'] + $r['au_upgrade'];
                            $uqTotal = $r['uq_mnp'] + $r['uq_new'] + $r['uq_change'] + $r['uq_downgrade'];
                        ?>
                        <tr>
                            <td><?= $r['work_date'] ?></td>
                            <td><?= h($r['employee_name']) ?></td>
                            <td><?= h($r['location'] ?? '') ?></td>
                            <td><?= h($r['carrier'] ?? '') ?></td>
                            <td class="text-center"><?= $r['contacts'] ?></td>
                            <td class="text-center"><?= $r['consultations'] ?></td>
                            <td class="text-center"><?= $r['seated'] ?></td>
                            <td class="text-center"><?= $sbTotal ?></td>
                            <td class="text-center"><?= $ymTotal ?></td>
                            <td class="text-center"><?= $auTotal ?></td>
                            <td class="text-center"><?= $uqTotal ?></td>
                            <td>
                                <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.65rem"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="12" class="text-center text-muted py-4">日報データがありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
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
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">稼働日</label>
                            <input type="date" name="work_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">場所</label>
                            <select name="location" id="drLocation" class="form-select form-select-sm" required>
                                <option value="">選択してください</option>
                                <option value="量販店">量販店</option>
                                <option value="ショップ">ショップ</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">キャリア</label>
                            <select name="carrier" id="drCarrier" class="form-select form-select-sm">
                                <option value="">選択</option>
                                <option value="SB,YM">SB / Y!mobile</option>
                                <option value="au,UQ">au / UQ</option>
                                <option value="ドコモ">ドコモ</option>
                                <option value="楽天">楽天</option>
                                <option value="コミュファ">コミュファ</option>
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

                    <!-- フォーム切り替えエリア -->
                    <div id="drFormGuide" class="alert alert-light text-muted text-center py-3" style="font-size:.85rem">
                        場所と業務形態を選択すると日報入力欄が表示されます
                    </div>

                    <!-- イベントフォーム (量販店 OR 業務形態=イベント) -->
                    <div id="drEventForm" style="display:none">
                        <div class="border rounded p-3" style="background:#fffbeb">
                            <div class="fw-bold mb-3" style="color:#b45309"><i class="bi bi-lightning me-1"></i>イベント日報</div>
                            <!-- 数値フィールド (コンパクト) -->
                            <div class="d-flex gap-3 flex-wrap mb-4">
                                <div class="text-center"><div class="form-label small fw-bold mb-1">キャッチ数</div><input type="number" name="catch_count" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">着席数</div><input type="number" name="event_seated" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">提案数</div><input type="number" name="event_proposals" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">商談数</div><input type="number" name="event_negotiations" id="evtNegotiations" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">成約数</div><input type="number" name="event_contracts" id="evtContracts" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                            </div>
                            <!-- イベント獲得内訳 -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">イベント獲得内訳 <span class="text-muted fw-normal" style="font-size:.72rem">（0件は空欄）</span></div>
                                <div id="evtAcqFields"><p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p></div>
                                <input type="hidden" name="event_acquisition_detail" id="evtAcqJson">
                            </div>
                            <!-- 個人獲得内訳 -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">個人獲得内訳 <span class="text-muted fw-normal" style="font-size:.72rem">（0件は空欄 ／ 合計が成約数と一致する必要があります）</span></div>
                                <div id="perAcqFields"><p class="text-muted small text-center mb-0 py-1">イベント獲得内訳を入力すると活性化されます</p></div>
                                <input type="hidden" name="personal_acquisition_detail" id="perAcqJson">
                            </div>
                            <!-- 固定出現内訳 -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">固定出現内訳 <span class="text-muted fw-normal" style="font-size:.72rem">（0件は空欄 ／ 合計が商談数と一致する必要があります）</span></div>
                                <div id="fixChkFields" class="row g-2"></div>
                                <input type="hidden" name="fixed_check_detail" id="fixChkJson">
                            </div>
                            <!-- 固定獲得内訳 -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">固定獲得内訳 <span class="text-muted fw-normal" style="font-size:.72rem">（0件は空欄）</span></div>
                                <div id="fixAcqFields" class="row g-2"></div>
                                <input type="hidden" name="fixed_acquisition_detail" id="fixAcqJson">
                            </div>
                            <!-- 振り返り -->
                            <div>
                                <label class="form-label small fw-bold">振り返り</label>
                                <textarea name="event_reflection" class="form-control form-control-sm" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ショップ常勤フォーム (ショップ AND 常勤) -->
                    <div id="drShopForm" style="display:none">
                        <div class="border rounded p-3" style="background:#f0fdf4">
                            <div class="fw-bold mb-3" style="color:#15803d"><i class="bi bi-shop me-1"></i>ショップ常勤日報</div>
                            <!-- 数値フィールド (コンパクト) -->
                            <div class="d-flex gap-3 flex-wrap mb-4">
                                <div class="text-center"><div class="form-label small fw-bold mb-1">来店数</div><input type="number" name="shop_visits" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">提案数</div><input type="number" name="shop_proposals" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">商談数</div><input type="number" name="shop_negotiations" id="shopNegotiations" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                                <div class="text-center"><div class="form-label small fw-bold mb-1">成約数</div><input type="number" name="shop_contracts" id="shopContracts" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px"></div>
                            </div>
                            <!-- 獲得内訳 (キャリア連動) -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">獲得内訳</div>
                                <div id="shopAcqFields"><p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p></div>
                                <input type="hidden" name="shop_acquisition_detail" id="shopAcqJson">
                            </div>
                            <!-- 固定出現内訳 -->
                            <div class="border rounded p-2 mb-3 bg-white">
                                <div class="fw-bold small mb-2">固定出現内訳 <span class="text-muted fw-normal" style="font-size:.72rem">（0件は空欄 ／ 合計が商談数と一致する必要があります）</span></div>
                                <div id="shopFixChkFields" class="row g-2"></div>
                                <input type="hidden" name="shop_fixed_check_detail" id="shopFixChkJson">
                            </div>
                            <!-- 店舗コメント -->
                            <div>
                                <label class="form-label small fw-bold">店舗コメント</label>
                                <textarea name="shop_comment" class="form-control form-control-sm" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

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
        'SB,YM':    ['MNP','アップ','ダウン','機変','転用','事変','SB光１G新規','SB光1G→10G','SB光10G新規','SB光＋１/2.5G','SB光＋10G','Air新規','Air機変','Airレンタル','SB電気','paypayカード'],
        'au,UQ':    ['MNP','アップ','ダウン','機変','転用','事変','BL光１G新規','BL光1G→10G','BL光10G新規','コミュファ１G','コミュファ10G','ホームルーター新規','ホームルータ機変','auでんき','aupayカード'],
        'ドコモ':   ['MNP','アップ','ダウン','機変','転用','事変','D光１G新規','D光1G→10G','D光10G新規','ホームルーター新規','ホームルータ機変','ｄでんき','ｄカード'],
        '楽天':     ['MNP','アップ','ダウン','機変','転用','事変','Ｒ光１G新規','Ｒ光1G→10G','Ｒ光10G新規','ホームルーター新規','ホームルータ機変','楽天でんき','楽天カード'],
        'コミュファ':['MNP','アップ','ダウン','機変','転用','事変','BL光１G新規','BL光1G→10G','BL光10G新規','コミュファ１G','コミュファ10G','ホームルーター新規','ホームルータ機変','auでんき','aupayカード']
    };
    var FIXED_ITEMS = ['SB光１G','SB光10','SB光＋１/2.5G','SB光＋10G','SBAir６','SBAir5以前','ドコモ光１G','ドコモ光１０G','ドコモホームルーター','BL光１G','BL１０G','auホームルーター','楽天光１G','楽天光１０G','楽天ホームルーター','コミュファ１G','コミュファ１０G','ケーブルプラス','ケーブルライン','その他コラボ光','その他他社光'];

    function makeItemCol(id, label, disabled) {
        var col = document.createElement('div');
        col.className = 'col-6 col-md-4 col-lg-3';
        var lbl = document.createElement('div');
        lbl.style.cssText = 'font-size:.72rem;font-weight:600;margin-bottom:2px';
        lbl.textContent = label;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.id = id;
        inp.className = 'form-control form-control-sm text-center';
        inp.placeholder = '';
        if (disabled) { inp.disabled = true; inp.style.background = '#f3f4f6'; }
        col.appendChild(lbl); col.appendChild(inp);
        return col;
    }

    function buildEvtAcq(carrier) {
        var wrap = document.getElementById('evtAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>'; buildPerAcq(carrier); return; }
        var row = document.createElement('div'); row.className = 'row g-2';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('evtacq_' + i, label, false)); });
        wrap.appendChild(row);
        buildPerAcq(carrier);
    }

    function buildPerAcq(carrier) {
        var wrap = document.getElementById('perAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">イベント獲得内訳を入力すると活性化されます</p>'; return; }
        var row = document.createElement('div'); row.className = 'row g-2';
        items.forEach(function(label, i) {
            var ev = document.getElementById('evtacq_' + i);
            row.appendChild(makeItemCol('peracq_' + i, label, !(ev && ev.value.trim())));
        });
        wrap.appendChild(row);
    }

    function buildFixed() {
        var chkWrap = document.getElementById('fixChkFields');
        var acqWrap = document.getElementById('fixAcqFields');
        if (chkWrap.children.length > 0) return;
        FIXED_ITEMS.forEach(function(label, i) {
            chkWrap.appendChild(makeItemCol('fixchk_' + i, label, false));
            acqWrap.appendChild(makeItemCol('fixacq_' + i, label, true));
        });
    }

    document.getElementById('evtAcqFields').addEventListener('input', function(e) {
        if (!e.target.id || !e.target.id.startsWith('evtacq_')) return;
        var idx = e.target.id.slice(7);
        var per = document.getElementById('peracq_' + idx);
        if (!per) return;
        if (e.target.value.trim() === '') { per.disabled = true; per.value = ''; per.style.background = '#f3f4f6'; }
        else { per.disabled = false; per.style.background = ''; }
    });

    document.getElementById('fixChkFields').addEventListener('input', function(e) {
        if (!e.target.id || !e.target.id.startsWith('fixchk_')) return;
        var idx = e.target.id.slice(7);
        var acq = document.getElementById('fixacq_' + idx);
        if (!acq) return;
        if (e.target.value.trim() === '') { acq.disabled = true; acq.value = ''; acq.style.background = '#f3f4f6'; }
        else { acq.disabled = false; acq.style.background = ''; }
    });

    // ---- ショップ獲得内訳 構築 ----
    function buildShopAcq(carrier) {
        var wrap = document.getElementById('shopAcqFields');
        wrap.innerHTML = '';
        var items = CARRIER_ITEMS[carrier];
        if (!items) { wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>'; return; }
        var row = document.createElement('div'); row.className = 'row g-2';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('shopacq_' + i, label, false)); });
        wrap.appendChild(row);
    }

    // ---- ショップ固定出現内訳 構築 ----
    function buildShopFixed() {
        var wrap = document.getElementById('shopFixChkFields');
        if (wrap.children.length > 0) return;
        FIXED_ITEMS.forEach(function(label, i) { wrap.appendChild(makeItemCol('shopfixchk_' + i, label, false)); });
    }

    document.getElementById('drCarrier').addEventListener('change', function() {
        if (document.getElementById('drEventForm').style.display !== 'none') buildEvtAcq(this.value);
        if (document.getElementById('drShopForm').style.display  !== 'none') buildShopAcq(this.value);
    });

    function updateDrForm() {
        var loc = document.getElementById('drLocation').value;
        var wt  = document.getElementById('drWorkType').value;
        var showEv = (wt === 'イベント') || (loc === '量販店' && wt === '常勤');
        var showSh = (loc === 'ショップ' && wt === '常勤');
        document.getElementById('drFormGuide').style.display = (showEv || showSh) ? 'none' : 'block';
        document.getElementById('drEventForm').style.display = showEv ? 'block' : 'none';
        document.getElementById('drShopForm').style.display  = showSh ? 'block' : 'none';
        document.getElementById('drSubmitBtn').disabled      = !(loc && wt);
        var c = document.getElementById('drCarrier').value;
        if (showEv) { buildFixed(); if (c) buildEvtAcq(c); }
        if (showSh) { buildShopFixed(); if (c) buildShopAcq(c); }
    }
    document.getElementById('drLocation').addEventListener('change', updateDrForm);
    document.getElementById('drWorkType').addEventListener('change', updateDrForm);

    document.getElementById('reportForm').addEventListener('submit', function(e) {
        var evForm = document.getElementById('drEventForm');
        var shForm = document.getElementById('drShopForm');
        var carrier = document.getElementById('drCarrier').value;
        var items = CARRIER_ITEMS[carrier] || [];

        if (evForm.style.display !== 'none') {
            var evtAcq = {}, perAcq = {}, fixChk = {}, fixAcq = {};
            items.forEach(function(label, i) {
                var ev = document.getElementById('evtacq_' + i); if (ev && ev.value.trim()) evtAcq[label] = ev.value.trim();
                var per = document.getElementById('peracq_' + i); if (per && !per.disabled && per.value.trim()) perAcq[label] = per.value.trim();
            });
            FIXED_ITEMS.forEach(function(label, i) {
                var chk = document.getElementById('fixchk_' + i); if (chk && chk.value.trim()) fixChk[label] = chk.value.trim();
                var acq = document.getElementById('fixacq_' + i); if (acq && !acq.disabled && acq.value.trim()) fixAcq[label] = acq.value.trim();
            });
            var contracts = parseFloat(document.getElementById('evtContracts').value) || 0;
            if (contracts > 0) {
                var perSum = Object.values(perAcq).reduce(function(s,v){return s+(parseFloat(v)||0);},0);
                if (Math.round(perSum) !== Math.round(contracts)) { e.preventDefault(); alert('個人獲得内訳の合計（'+perSum+'件）が成約数（'+contracts+'件）と一致しません。'); return; }
            }
            var evtNeg = parseFloat(document.getElementById('evtNegotiations').value) || 0;
            if (evtNeg > 0) {
                var fixSum = Object.values(fixChk).reduce(function(s,v){return s+(parseFloat(v)||0);},0);
                if (Math.round(fixSum) !== Math.round(evtNeg)) { e.preventDefault(); alert('固定出現内訳の合計（'+fixSum+'件）が商談数（'+evtNeg+'件）と一致しません。'); return; }
            }
            document.getElementById('evtAcqJson').value = JSON.stringify(evtAcq);
            document.getElementById('perAcqJson').value = JSON.stringify(perAcq);
            document.getElementById('fixChkJson').value = JSON.stringify(fixChk);
            document.getElementById('fixAcqJson').value = JSON.stringify(fixAcq);

        } else if (shForm.style.display !== 'none') {
            var shopAcq = {}, shopFixChk = {};
            items.forEach(function(label, i) {
                var inp = document.getElementById('shopacq_' + i); if (inp && inp.value.trim()) shopAcq[label] = inp.value.trim();
            });
            FIXED_ITEMS.forEach(function(label, i) {
                var chk = document.getElementById('shopfixchk_' + i); if (chk && chk.value.trim()) shopFixChk[label] = chk.value.trim();
            });
            var shNeg = parseFloat(document.getElementById('shopNegotiations').value) || 0;
            if (shNeg > 0) {
                var shFixSum = Object.values(shopFixChk).reduce(function(s,v){return s+(parseFloat(v)||0);},0);
                if (Math.round(shFixSum) !== Math.round(shNeg)) { e.preventDefault(); alert('固定出現内訳の合計（'+shFixSum+'件）が商談数（'+shNeg+'件）と一致しません。'); return; }
            }
            document.getElementById('shopAcqJson').value = JSON.stringify(shopAcq);
            document.getElementById('shopFixChkJson').value = JSON.stringify(shopFixChk);
        }
    });

    document.getElementById('reportModal').addEventListener('show.bs.modal', function() {
        document.getElementById('reportForm').reset();
        document.getElementById('drLocation').value = '';
        document.getElementById('drWorkType').value = '';
        document.getElementById('drCarrier').value  = '';
        document.getElementById('evtAcqFields').innerHTML  = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>';
        document.getElementById('perAcqFields').innerHTML  = '<p class="text-muted small text-center mb-0 py-1">イベント獲得内訳を入力すると活性化されます</p>';
        document.getElementById('fixChkFields').innerHTML  = '';
        document.getElementById('fixAcqFields').innerHTML  = '';
        document.getElementById('shopAcqFields').innerHTML = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>';
        document.getElementById('shopFixChkFields').innerHTML = '';
        updateDrForm();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
