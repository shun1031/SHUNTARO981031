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
        try {
            saveDailyReport($cid, $_POST);
        } catch (Throwable $e) {
            $errMsg = $e->getMessage();
            error_log('[saveDailyReport] ' . $errMsg . ' | data=' . json_encode(array_intersect_key($_POST, array_flip(['employee_name','work_date','work_type','carrier']))));
            $_SESSION['_dr_save_error'] = $errMsg;
            redirect(BASE_PATH . '/public/sales_daily_report.php?year='.$year.'&month='.$month.'&err=save_failed');
        }
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
$drApiBase     = BASE_PATH . '/public/api/daily_report_kpi.php';
$drSaveApiBase = BASE_PATH . '/public/api/save_daily_report.php';
$drBudgetApiBase = BASE_PATH . '/public/api/store_budget.php';

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
    <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>日報の保存に失敗しました。</strong>
        <?php if (!empty($_SESSION['_dr_save_error'])): ?>
        <br><small class="font-monospace"><?= h($_SESSION['_dr_save_error']) ?></small>
        <?php unset($_SESSION['_dr_save_error']); ?>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ① 検索 + KPI + 一覧 (Ajax) -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <?php if (isAdmin()): ?>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="btn-group btn-group-sm" role="group" aria-label="検索種別">
                <button type="button" id="drTabEmp"     class="btn btn-primary"           onclick="drSetFilterTab('employee')">スタッフ名</button>
                <button type="button" id="drTabBiz"     class="btn btn-outline-secondary" onclick="drSetFilterTab('work_type')">業務形態</button>
                <button type="button" id="drTabCarrier" class="btn btn-outline-secondary" onclick="drSetFilterTab('carrier')">キャリア</button>
            </div>
            <select id="drEmpSelect" class="form-select form-select-sm" style="width:200px">
                <option value="" <?= !$selectedEmp ? 'selected' : '' ?>>全員</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= h($e) ?>" <?= $selectedEmp === $e ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="drBizSelect" class="form-select form-select-sm" style="width:140px;display:none">
                <option value="">全て</option>
                <option value="光AD">光AD</option>
                <option value="業務委託">業務委託</option>
            </select>
            <select id="drCarrierFilterSelect" class="form-select form-select-sm" style="width:180px;display:none">
                <option value="">全て</option>
                <option value="SB,YM">SB / Y!mobile</option>
                <option value="au,UQ">au / UQ</option>
                <option value="ドコモ">ドコモ</option>
                <option value="楽天">楽天</option>
                <option value="コミュファ">コミュファ光</option>
                <option value="CATV">CATV</option>
            </select>
            <div id="drGroupSummary" style="display:none;font-size:.82rem;padding:4px 10px;background:#f0fdf4;border-radius:.4rem;border:1px solid #bbf7d0">
                <span class="text-muted">平均達成率:</span>
                <strong id="drGroupAvgRate" style="color:#059669">-%</strong>
            </div>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <button id="drWeekBtn" class="btn btn-outline-secondary btn-sm" onclick="drToggleWeek()">
            <i class="bi bi-calendar-week me-1"></i>直近1週間
        </button>
    </div>

    <!-- ② KPIサマリーカード（個人実績のみ） -->
    <div class="row g-2 mb-3" id="drKpiRow">
        <?php
        $kpiDefs = [
            ['key'=>'catch_count',        'label'=>'キャッチ数', 'shop_label'=>'来店組数', 'icon'=>'bi-megaphone',       'color'=>'#2563eb'],
            ['key'=>'event_seated',       'label'=>'着座数',     'shop_label'=>'接客組数', 'icon'=>'bi-person-check',    'color'=>'#059669'],
            ['key'=>'event_proposals',    'label'=>'提案数',     'shop_label'=>'提案数',   'icon'=>'bi-clipboard-check', 'color'=>'#d97706'],
            ['key'=>'event_negotiations', 'label'=>'商談数',     'shop_label'=>'商談数',   'icon'=>'bi-chat-square-dots','color'=>'#7c3aed'],
            ['key'=>'event_contracts',    'label'=>'成約数',     'shop_label'=>'成約数',   'icon'=>'bi-handshake',       'color'=>'#dc2626'],
            ['key'=>'goal',               'label'=>'目標',       'shop_label'=>'目標',     'icon'=>'bi-bullseye',        'color'=>'#0891b2'],
        ];
        foreach ($kpiDefs as $kd): ?>
        <div class="col-6 col-md-2">
            <div class="card h-100 kpi-card shadow-sm" data-key="<?= $kd['key'] ?>" data-shop-label="<?= $kd['shop_label'] ?>" style="border-top:3px solid <?= $kd['color'] ?>;border-radius:.75rem">
                <div class="card-body p-2 text-center">
                    <div class="d-flex align-items-center gap-1 mb-1 justify-content-center">
                        <i class="bi <?= $kd['icon'] ?>" style="color:<?= $kd['color'] ?>;font-size:.9rem"></i>
                        <span class="kpi-label fw-semibold" style="color:<?= $kd['color'] ?>;font-size:.72rem"><?= $kd['label'] ?></span>
                    </div>
                    <div class="kpi-personal-val fw-bold" style="font-size:1.7rem;color:<?= $kd['color'] ?>;line-height:1.1">-</div>
                    <div style="font-size:.58rem;color:#9ca3af;margin-bottom:2px">件</div>
                    <div class="kpi-rate-val" style="font-size:.65rem;color:#6b7280;display:none">達成率 <span class="fw-bold">-%</span></div>
                    <div class="kpi-week" style="display:none;margin-top:4px;padding-top:4px;border-top:1px dashed #e5e7eb;font-size:.65rem;color:#6b7280">
                        1週間: <span class="kpi-week-val fw-bold">-</span>件
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ③ キャリア別アイテムKPIカード（自動検出） -->
    <div class="d-flex align-items-center gap-2 mb-2" id="drItemKpiHeader" style="display:none">
        <span class="text-muted small fw-semibold">商材別実績</span>
        <span id="drDetectedCarrierBadge" class="badge" style="font-size:.72rem"></span>
    </div>
    <div class="row g-2 mb-3" id="drItemKpiRow">
        <div class="col-12 text-center text-muted small py-2"><i class="bi bi-arrow-repeat"></i> 読込中...</div>
    </div>

    <!-- ④ 年間推移グラフ -->
    <div class="card mb-3 shadow-sm" style="border-radius:.75rem">
        <div class="card-header fw-semibold" style="font-size:.85rem;border-radius:.75rem .75rem 0 0">
            <i class="bi bi-graph-up me-1"></i>年間推移 <span class="text-muted fw-normal" style="font-size:.75rem"><?= $year ?>年 月別</span>
        </div>
        <div class="card-body p-3" style="height:220px">
            <canvas id="drTrendChart"></canvas>
        </div>
    </div>

    <!-- ⑤ 目標達成率ランキング（自社外注） -->
    <div class="card mb-3 shadow-sm" style="border-radius:.75rem" id="drRankingCard">
        <div class="card-header d-flex justify-content-between align-items-center" style="font-size:.85rem;border-radius:.75rem .75rem 0 0;padding:.5rem .75rem">
            <span><i class="bi bi-trophy me-1 text-warning"></i><strong>目標達成率ランキング</strong> <span class="text-muted fw-normal ms-1" style="font-size:.75rem">自社外注</span></span>
            <button id="drRankingToggle" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="drToggleRanking()" style="font-size:.72rem;display:none">△ 全て表示</button>
        </div>
        <div class="card-body p-0" id="drRankingWrap">
            <div class="text-center py-3 text-muted small"><i class="bi bi-arrow-repeat"></i> 読込中...</div>
        </div>
    </div>

    <!-- ⑥ 日報一覧 -->
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
                            <label class="form-label fw-bold">稼働店舗 <span class="text-danger">*</span></label>
                            <input type="text" name="location" id="drLocation" class="form-control form-control-sm" placeholder="店舗名を入力">
                            <div class="form-text" style="font-size:.7rem;color:#6b7280">正式名称で入力してください</div>
                            <p id="drLocationError" class="text-danger mb-0" style="font-size:.75rem;display:none">稼働店舗を入力してください</p>
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
                            <label class="form-label fw-bold">業務形態 <span class="text-danger">*</span></label>
                            <select name="work_type" id="drWorkType" class="form-select form-select-sm" required>
                                <option value="">選択してください</option>
                                <option value="光AD">光AD</option>
                                <option value="業務委託">業務委託</option>
                            </select>
                        </div>
                    </div>

                    <!-- 業務形態選択後に表示 -->
                    <div id="drEventForm" style="display:none">
                        <div class="border rounded p-2" style="background:#fffbeb">
                            <div class="fw-bold mb-2" style="color:#b45309;font-size:.85rem"><i class="bi bi-lightning me-1"></i>日報</div>
                            <!-- 数値フィールド -->
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <div class="text-center"><div id="drLabelCatch" style="font-size:.68rem;font-weight:600;margin-bottom:2px">キャッチ数</div><input type="number" name="catch_count" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div id="drLabelSeated" style="font-size:.68rem;font-weight:600;margin-bottom:2px">着座数</div><input type="number" name="event_seated" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">提案数</div><input type="number" name="event_proposals" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">商談数</div><input type="number" name="event_negotiations" id="evtNegotiations" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div style="font-size:.68rem;font-weight:600;margin-bottom:2px">成約数</div><input type="number" name="event_contracts" id="evtContracts" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                            </div>
                            <!-- 店舗予算（光ADのみ） -->
                            <div id="drBudgetSection" class="border rounded p-2 mb-2" style="background:#eff6ff;display:none">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="fw-bold" style="color:#1d4ed8;font-size:.82rem"><i class="bi bi-bullseye me-1"></i>店舗予算 <span class="badge bg-danger ms-1" style="font-size:.6rem">月初1回</span></div>
                                    <div id="drBudgetExistsBadge" class="badge bg-success" style="display:none;font-size:.65rem">入力済み（上書き可）</div>
                                </div>
                                <div id="drBudgetFields" class="row g-1"></div>
                                <input type="hidden" name="budget_detail" id="drBudgetJson" value="">
                            </div>
                            <!-- 個人実績 -->
                            <div class="border rounded p-2 bg-white">
                                <div style="font-size:.78rem;font-weight:600;margin-bottom:3px">個人実績 <span class="text-muted fw-normal" style="font-size:.68rem">（0件は空欄）</span></div>
                                <div id="drPersonalFields"><p class="text-muted small text-center mb-0 py-1">業務形態を選択すると入力欄が表示されます</p></div>
                                <input type="hidden" name="personal_acquisition_detail" id="perAcqJson">
                            </div>
                            <!-- 後方互換 hidden -->
                            <input type="hidden" name="event_acquisition_detail" id="evtAcqJson" value="">
                            <input type="hidden" name="fixed_check_detail" value="">
                            <input type="hidden" name="fixed_acquisition_detail" value="">
                            <input type="hidden" name="event_reflection" value="">
                        </div>
                    </div>
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

<!-- 商材別年間推移モーダル -->
<div class="modal fade" id="itemTrendModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-graph-up me-1"></i><span id="itemTrendModalTitle">年間推移</span></h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="height:260px;position:relative">
                <canvas id="itemTrendChart"></canvas>
                <div id="itemTrendNoData" class="d-none text-center text-muted" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <i class="bi bi-bar-chart-line" style="font-size:2.2rem;opacity:.3;margin-bottom:10px"></i>
                    <div id="itemTrendNoDataMsg" style="font-size:.85rem"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var BIZ_CONFIG = {
        '光AD': {
            requireBudget: true,
            catchLabel: '来店組数',
            seatedLabel: '接客組数',
            budgetItems: ['固定合計','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード'],
            personalItems: ['固定合計','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード'],
            primaryKpi: '固定合計'
        },
        '業務委託': {
            requireBudget: false,
            catchLabel: 'キャッチ数',
            seatedLabel: '着座数',
            budgetItems: [],
            personalItems: ['MNP','アップ','ダウン','機変','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード','セレクション単価'],
            primaryKpi: null
        }
    };
    var BIZ_ALIASES = {'ショップ': '光AD', 'ショップ以外': '業務委託'};
    var drCurrentBiz = null;
    var drBudgetApiBase = <?= json_encode($drBudgetApiBase) ?>;
    var drSaveApiBase   = <?= json_encode($drSaveApiBase) ?>;
    var drFormCsrf      = <?= json_encode(getCsrfToken()) ?>;

    function makeItemCol(id, label) {
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
        col.appendChild(lbl); col.appendChild(inp);
        return col;
    }

    function buildPersonalFields(items) {
        var wrap = document.getElementById('drPersonalFields');
        if (!items || !items.length) {
            wrap.innerHTML = '<p class="text-muted small text-center mb-0 py-1">業務形態を選択すると入力欄が表示されます</p>';
            return;
        }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('perinp_' + i, label)); });
        wrap.innerHTML = ''; wrap.appendChild(row);
    }

    function buildBudgetFields(items) {
        var wrap = document.getElementById('drBudgetFields');
        if (!items || !items.length) { wrap.innerHTML = ''; return; }
        var row = document.createElement('div'); row.className = 'row g-1';
        items.forEach(function(label, i) { row.appendChild(makeItemCol('budinp_' + i, label)); });
        wrap.innerHTML = ''; wrap.appendChild(row);
    }

    function checkBudgetExists(emp, year, month) {
        if (!emp || !drCurrentBiz || !drCurrentBiz.requireBudget) return;
        fetch(drBudgetApiBase + '?employee=' + encodeURIComponent(emp) + '&year=' + year + '&month=' + month)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var badge = document.getElementById('drBudgetExistsBadge');
                if (res.exists && res.budget && badge) {
                    badge.style.display = '';
                    if (drCurrentBiz && drCurrentBiz.budgetItems) {
                        drCurrentBiz.budgetItems.forEach(function(label, i) {
                            var inp = document.getElementById('budinp_' + i);
                            if (inp) inp.value = (res.budget[label] != null ? res.budget[label] : '');
                        });
                    }
                } else if (badge) {
                    badge.style.display = 'none';
                }
            })
            .catch(function() {});
    }

    function updateBizForm() {
        var wt = document.getElementById('drWorkType').value;
        var biz = BIZ_CONFIG[BIZ_ALIASES[wt] || wt] || null;
        drCurrentBiz = biz;
        var show = !!biz;

        document.getElementById('drEventForm').style.display = show ? 'block' : 'none';
        document.getElementById('drSubmitBtn').disabled = !show;

        if (!biz) return;

        document.getElementById('drLabelCatch').textContent  = biz.catchLabel;
        document.getElementById('drLabelSeated').textContent = biz.seatedLabel;

        var budgetSection = document.getElementById('drBudgetSection');
        if (biz.requireBudget) {
            budgetSection.style.display = '';
            buildBudgetFields(biz.budgetItems);
            // 社員と稼働日から予算チェック
            var empInp   = document.querySelector('[name="employee_name"]');
            var dateInp  = document.querySelector('[name="work_date"]');
            var emp  = empInp  ? empInp.value.trim()  : '';
            var wd   = dateInp ? dateInp.value         : '';
            if (emp && wd) {
                var yr = parseInt(wd.slice(0,4)), mo = parseInt(wd.slice(5,7));
                checkBudgetExists(emp, yr, mo);
            }
        } else {
            budgetSection.style.display = 'none';
        }
        buildPersonalFields(biz.personalItems);
    }

    document.getElementById('drWorkType').addEventListener('change', updateBizForm);

    document.getElementById('reportModal').addEventListener('show.bs.modal', function() {
        document.getElementById('reportForm').reset();
        document.getElementById('drLocation').value = '';
        document.getElementById('drWorkType').value = '';
        document.getElementById('drCarrier').value  = '';
        document.getElementById('drLocationError').style.display = 'none';
        document.getElementById('drPersonalFields').innerHTML = '<p class="text-muted small text-center mb-0 py-1">業務形態を選択すると入力欄が表示されます</p>';
        document.getElementById('drBudgetFields').innerHTML = '';
        document.getElementById('drBudgetSection').style.display = 'none';
        document.getElementById('drBudgetExistsBadge').style.display = 'none';
        drCurrentBiz = null;
        document.getElementById('drEventForm').style.display = 'none';
        document.getElementById('drSubmitBtn').disabled = true;
    });

    document.getElementById('reportForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var loc = document.getElementById('drLocation').value.trim();
        var locError = document.getElementById('drLocationError');
        if (!loc) { locError.style.display = 'block'; document.getElementById('drLocation').focus(); return; }
        locError.style.display = 'none';

        var biz = drCurrentBiz;
        // 個人実績 JSON 収集
        var perAcq = {};
        if (biz && biz.personalItems) {
            biz.personalItems.forEach(function(label, i) {
                var inp = document.getElementById('perinp_' + i);
                if (inp && inp.value !== '' && parseFloat(inp.value) !== 0) perAcq[label] = inp.value;
            });
        }
        document.getElementById('perAcqJson').value = JSON.stringify(perAcq);

        // 店舗予算 JSON 収集（光ADのみ）
        var budgetJson = '';
        if (biz && biz.requireBudget && biz.budgetItems && biz.budgetItems.length) {
            var bData = {}, hasVal = false;
            biz.budgetItems.forEach(function(label, i) {
                var inp = document.getElementById('budinp_' + i);
                var v = inp ? inp.value : '';
                bData[label] = v !== '' ? v : '0';
                if (v !== '' && parseFloat(v) > 0) hasVal = true;
            });
            if (hasVal) budgetJson = JSON.stringify(bData);
        }
        document.getElementById('drBudgetJson').value = budgetJson;
        document.getElementById('evtAcqJson').value = '{}';

        var btn = document.getElementById('drSubmitBtn');
        btn.disabled = true; btn.textContent = '保存中...';

        var fd = new FormData(this);
        fetch(drSaveApiBase, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('reportModal')).hide();
                    drShowFormAlert('success', '日報を保存しました');
                    if (typeof drLoad === 'function') drLoad();
                } else {
                    drShowFormAlert('danger', '保存失敗: ' + (res.error || '不明なエラー'));
                    btn.disabled = false; btn.textContent = '保存';
                }
            })
            .catch(function() {
                drShowFormAlert('danger', '通信エラーが発生しました');
                btn.disabled = false; btn.textContent = '保存';
            });
    });

    window.drShowFormAlert = function(type, msg) {
        var container = document.querySelector('.container-fluid');
        var old = document.getElementById('drFlashAlert');
        if (old) old.remove();
        var div = document.createElement('div');
        div.id = 'drFlashAlert';
        div.className = 'alert alert-' + type + ' alert-dismissible fade show';
        div.setAttribute('role', 'alert');
        div.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        var firstChild = container.children[0];
        container.insertBefore(div, firstChild ? firstChild.nextSibling : null);
        setTimeout(function() { if (div.parentNode) div.remove(); }, 4500);
    };
})();
</script>

<script>
(function(){
    var drYear  = <?= $year ?>, drMonth = <?= $month ?>;
    var drFilterType  = 'employee';
    var drFilterValue = <?= json_encode($selectedEmp) ?>;
    var drWeekMode    = false;
    var drApiBase     = <?= json_encode($drApiBase) ?>;
    var drSaveApiBase = <?= json_encode($drSaveApiBase) ?>;
    var drCsrf        = <?= json_encode(getCsrfToken()) ?>;
    var drTrendChart  = null;
    var drItemChart   = null;
    var drLastData    = null;
    var drLastCarrier = null;
    var drLastItemLabel = null;
    var drLastItemTrend = null;
    var drRankingExpanded = false;

    var CARRIER_COLORS = {
        'SB,YM':'#2563eb','au,UQ':'#d97706','ドコモ':'#dc2626',
        '楽天':'#be123c','コミュファ':'#059669','CATV':'#0891b2'
    };
    var CARRIER_LABELS = {
        'SB,YM':'SB / Y!mobile','au,UQ':'au / UQ','ドコモ':'ドコモ',
        '楽天':'楽天','コミュファ':'コミュファ','CATV':'CATV'
    };
    var BIZ_ALIASES = {'ショップ':'光AD','ショップ以外':'業務委託'};

    function normBiz(wt) { return BIZ_ALIASES[wt] || wt; }
    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function hexToRgb(hex) {
        var r=parseInt((hex||'#000000').slice(1,3),16);
        var g=parseInt((hex||'#000000').slice(3,5),16);
        var b=parseInt((hex||'#000000').slice(5,7),16);
        return r+','+g+','+b;
    }

    // ─── 検索タブ切替 ─────────────────────────────────────────────────
    window.drSetFilterTab = function(tab) {
        drFilterType = tab;
        var tabs = { employee:'drTabEmp', work_type:'drTabBiz', carrier:'drTabCarrier' };
        Object.keys(tabs).forEach(function(t) {
            var btn = document.getElementById(tabs[t]);
            if (btn) { btn.className = (t === tab) ? 'btn btn-primary' : 'btn btn-outline-secondary'; }
        });
        var empSel  = document.getElementById('drEmpSelect');
        var bizSel  = document.getElementById('drBizSelect');
        var carrSel = document.getElementById('drCarrierFilterSelect');
        if (empSel)  empSel.style.display  = (tab === 'employee')   ? '' : 'none';
        if (bizSel)  bizSel.style.display  = (tab === 'work_type')  ? '' : 'none';
        if (carrSel) carrSel.style.display = (tab === 'carrier')    ? '' : 'none';
        if (tab === 'employee')   drFilterValue = (empSel  ? empSel.value  : '');
        else if (tab === 'work_type') drFilterValue = (bizSel  ? bizSel.value  : '');
        else                      drFilterValue = (carrSel ? carrSel.value : '');
        drLoad();
    };

    // ─── API 呼び出し ─────────────────────────────────────────────────
    window.drLoad = function() {
        var url = drApiBase + '?filter_type=' + encodeURIComponent(drFilterType)
            + '&filter_value=' + encodeURIComponent(drFilterValue)
            + '&year=' + drYear + '&month=' + drMonth;
        document.getElementById('drListWrap').innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat"></i> 読込中...</div>';
        document.getElementById('drItemKpiRow').innerHTML = '<div class="col-12 text-center text-muted small py-2"><i class="bi bi-arrow-repeat"></i> 読込中...</div>';
        fetch(url).then(function(r){ return r.json(); }).then(function(d){
            drLastData = d;
            drRenderKpi(d);
            drRenderChart(d);
            drRenderRanking(d);
            drRenderList(d);
            drRenderGroupSummary(d);
            document.getElementById('drMonthLabel').textContent = drYear + '年' + drMonth + '月';
            document.getElementById('drSubtitle').textContent   = drYear + '年' + drMonth + '月';
        }).catch(function(e){ console.error(e); });
    };

    // ─── KPIカード ────────────────────────────────────────────────────
    function drRenderKpi(d) {
        var kpiMonth = d.kpi_month || {};
        var week     = d.kpi_week  || {};

        // 業務形態ラベル自動切替
        var autoBiz  = d.auto_biz_type || null;
        var bizConf  = autoBiz ? (d.biz_config || {})[autoBiz] : null;
        var isShopBiz = (autoBiz === '光AD');
        var SUMMARY = [
            {key:'catch_count',        color:'#2563eb'},
            {key:'event_seated',       color:'#059669'},
            {key:'event_proposals',    color:'#d97706'},
            {key:'event_negotiations', color:'#7c3aed'},
            {key:'event_contracts',    color:'#dc2626'},
            {key:'goal',               color:'#0891b2'},
        ];
        SUMMARY.forEach(function(def) {
            var card = document.querySelector('.kpi-card[data-key="' + def.key + '"]');
            if (!card) return;
            var labelEl = card.querySelector('.kpi-label');
            if (labelEl && bizConf) {
                if (def.key === 'catch_count') labelEl.textContent = bizConf.catch_label || (isShopBiz ? '来店組数' : 'キャッチ数');
                else if (def.key === 'event_seated') labelEl.textContent = bizConf.seated_label || (isShopBiz ? '接客組数' : '着座数');
                else if (labelEl.dataset && def.key !== 'goal') { /* keep default */ }
            } else if (labelEl && card.dataset.shopLabel && isShopBiz) {
                labelEl.textContent = card.dataset.shopLabel;
            }
            var val  = parseInt(kpiMonth[def.key] || 0);
            var wVal = parseInt(week[def.key]     || 0);
            if (def.key === 'goal') { val = 0; wVal = 0; }
            card.querySelector('.kpi-personal-val').textContent = val;
            var rateEl = card.querySelector('.kpi-rate-val');
            if (rateEl) rateEl.style.display = 'none';
            var wkEl = card.querySelector('.kpi-week-val');
            if (wkEl) wkEl.textContent = wVal;
            card.querySelector('.kpi-week').style.display = drWeekMode ? 'block' : 'none';
        });

        // 商材別KPI表示
        var autoBizType = d.auto_biz_type || null;
        if (!autoBizType) {
            // 後方互換: キャリアベース
            drLastCarrier = d.auto_carrier || null;
            if (!drLastCarrier) {
                document.getElementById('drItemKpiRow').innerHTML = '<div class="col-12 text-muted small text-center py-2">日報データがありません</div>';
                document.getElementById('drItemKpiHeader').style.display = 'none';
            } else {
                var badge = document.getElementById('drDetectedCarrierBadge');
                if (badge) {
                    badge.textContent = CARRIER_LABELS[drLastCarrier] || drLastCarrier;
                    badge.style.background = CARRIER_COLORS[drLastCarrier] || '#6b7280';
                    badge.style.color = '#fff';
                }
                document.getElementById('drItemKpiHeader').style.display = 'flex';
                drRenderCarrierItemKpi(d, drLastCarrier);
            }
        } else {
            drLastCarrier = d.auto_carrier || null;
            var badge = document.getElementById('drDetectedCarrierBadge');
            if (badge) {
                badge.textContent = autoBizType;
                badge.style.background = autoBizType === '光AD' ? '#2563eb' : '#7c3aed';
                badge.style.color = '#fff';
            }
            document.getElementById('drItemKpiHeader').style.display = 'flex';
            drRenderBizItemKpi(d);
        }
    }

    // ─── 業務形態別アイテムKPI（新） ───────────────────────────────────
    function drRenderBizItemKpi(d) {
        var autoBizType = d.auto_biz_type;
        var bizConf = (d.biz_config || {})[autoBizType] || null;
        var bizKpi  = d.biz_item_kpi || {};
        var color   = autoBizType === '光AD' ? '#2563eb' : '#7c3aed';
        var wrap    = document.getElementById('drItemKpiRow');
        if (!wrap || !bizConf) { if (wrap) wrap.innerHTML = '<div class="col-12 text-muted small text-center py-2">データがありません</div>'; return; }
        var items = bizConf.personal_items || [];
        if (!items.length) { wrap.innerHTML = '<div class="col-12 text-muted small text-center py-2">データがありません</div>'; return; }
        var primaryKpi = bizConf.primary_kpi || null;
        var html = '';
        items.forEach(function(label) {
            var kpi      = bizKpi[label] || { actual: 0, budget: null };
            var actual   = kpi.actual || 0;
            var isPrimary = label === primaryKpi;
            var budget   = isPrimary && (kpi.budget !== null && kpi.budget !== undefined) ? kpi.budget : null;
            var hasBudget = budget !== null;
            var rate = '-', rateColor = '#9ca3af';
            if (isPrimary && hasBudget && budget > 0) {
                rate = (actual / budget * 100).toFixed(1);
                rateColor = parseFloat(rate) >= 100 ? '#059669' : (parseFloat(rate) >= 70 ? color : '#ef4444');
            }
            html += '<div class="col-6 col-md-4 col-lg-2">';
            html += '<div class="card h-100 shadow-sm" style="border-radius:.75rem;border-top:3px solid ' + color + '">';
            html += '<div class="card-body p-2">';
            html += '<div class="fw-semibold mb-1" style="font-size:.72rem;color:' + color + '">' + esc(label) + '</div>';
            html += '<div class="d-flex gap-1 mb-1">';
            if (isPrimary) {
                html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px">';
                html += '<div style="font-size:.56rem;color:#9ca3af">予算</div>';
                html += '<div style="font-size:1rem;font-weight:700;color:#6b7280;line-height:1.1">' + (hasBudget ? budget : '-') + '</div>';
                html += '<div style="font-size:.52rem;color:#9ca3af">件</div></div>';
            }
            html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px">';
            html += '<div style="font-size:.56rem;color:#9ca3af">実績</div>';
            html += '<div style="font-size:1rem;font-weight:700;color:' + color + ';line-height:1.1">' + actual + '</div>';
            html += '<div style="font-size:.52rem;color:#9ca3af">件</div></div></div>';
            if (isPrimary) {
                html += '<div class="text-center" style="font-size:.63rem;color:#6b7280">達成率 <span style="font-weight:700;color:' + rateColor + '">' + rate + (rate !== '-' ? '%' : '') + '</span></div>';
            }
            html += '</div></div></div>';
        });
        wrap.innerHTML = html;
    }

    // ─── キャリア別アイテムKPI（後方互換） ────────────────────────────
    function drRenderCarrierItemKpi(d, carrier) {
        var itemKpi = (d.carrier_item_kpi || {})[carrier] || {};
        var items   = (d.carrier_items    || {})[carrier] || [];
        var color   = CARRIER_COLORS[carrier] || '#6b7280';
        var wrap    = document.getElementById('drItemKpiRow');
        if (!wrap) return;
        if (!items.length) { wrap.innerHTML = '<div class="col-12 text-muted small text-center py-2">データがありません</div>'; return; }
        var html = '';
        items.forEach(function(label) {
            var kpi = itemKpi[label] || {total:0, personal:0};
            var total = kpi.total || 0, personal = kpi.personal || 0;
            var rate = (total > 0) ? (personal / total * 100).toFixed(1) : '-';
            var rateColor = rate === '-' ? '#9ca3af' : (parseFloat(rate) >= 100 ? '#059669' : (parseFloat(rate) >= 50 ? color : '#ef4444'));
            html += '<div class="col-6 col-md-4 col-lg-2" data-item-label="' + esc(label) + '" style="cursor:pointer">';
            html += '<div class="card h-100 shadow-sm" style="border-radius:.75rem;border-top:3px solid ' + color + '">';
            html += '<div class="card-body p-2">';
            html += '<div class="fw-semibold mb-1" style="font-size:.72rem;color:' + color + '">' + esc(label) + '</div>';
            html += '<div class="d-flex gap-1 mb-1">';
            html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px"><div style="font-size:.56rem;color:#9ca3af">全体</div><div style="font-size:1rem;font-weight:700;color:' + color + ';line-height:1.1">' + total + '</div><div style="font-size:.52rem;color:#9ca3af">件</div></div>';
            html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px"><div style="font-size:.56rem;color:#9ca3af">個人</div><div style="font-size:1rem;font-weight:700;color:' + color + ';line-height:1.1">' + personal + '</div><div style="font-size:.52rem;color:#9ca3af">件</div></div></div>';
            html += '<div class="text-center" style="font-size:.63rem;color:#6b7280">達成率 <span style="font-weight:700;color:' + rateColor + '">' + rate + (rate !== '-' ? '%' : '') + '</span></div>';
            html += '<div style="font-size:.55rem;color:#aaa;text-align:center;margin-top:2px"><i class="bi bi-graph-up"></i> タップで推移</div>';
            html += '</div></div></div>';
        });
        wrap.innerHTML = html;
    }

    // ─── アイテムKPIクリック → 年間推移モーダル ───────────────────────
    document.getElementById('drItemKpiRow').addEventListener('click', function(e) {
        var col = e.target.closest('[data-item-label]');
        if (!col || !drLastData) return;
        var label = col.dataset.itemLabel;
        var trend = (drLastData.item_annual_trend || {})[label] || [];
        drLastItemLabel = label;
        drLastItemTrend = trend;
        document.getElementById('itemTrendModalTitle').textContent = label + '  個人獲得 年間推移 (' + drYear + '年)';
        new bootstrap.Modal(document.getElementById('itemTrendModal')).show();
    });

    document.getElementById('itemTrendModal').addEventListener('shown.bs.modal', function() {
        if (drItemChart) { drItemChart.destroy(); drItemChart = null; }
        var ctx      = document.getElementById('itemTrendChart');
        var noData   = document.getElementById('itemTrendNoData');
        var noDataMsg= document.getElementById('itemTrendNoDataMsg');
        if (!ctx) return;
        var trend = drLastItemTrend || [];
        var hasPositive = trend.some(function(t){ return t.value > 0; });
        function showNoData(msg) {
            ctx.style.display = 'none';
            if (noData)    { noData.classList.remove('d-none'); noData.style.display = 'flex'; }
            if (noDataMsg) noDataMsg.textContent = msg;
        }
        function showChart() {
            ctx.style.display = '';
            if (noData) { noData.classList.add('d-none'); noData.style.display = 'none'; }
        }
        if (!trend.length) { showNoData('社員を選択するとデータが表示されます'); return; }
        if (!hasPositive)  { showNoData(drYear + '年の個人獲得実績はありません'); return; }
        showChart();
        var color  = CARRIER_COLORS[drLastCarrier] || '#2563eb';
        var labels = trend.map(function(t){ return t.month + '月'; });
        var values = trend.map(function(t){ return t.value; });
        drItemChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: [{ label: drLastItemLabel + ' 個人獲得数（件）', data: values,
                borderColor: color, backgroundColor: 'rgba(' + hexToRgb(color) + ',.1)',
                fill: true, tension: .35, pointBackgroundColor: color, pointRadius: 4 }] },
            options: { responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false},
                plugins:{legend:{position:'top',labels:{font:{size:11},boxWidth:14}}},
                scales:{ x:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10}}},
                    y:{beginAtZero:true,ticks:{font:{size:10},precision:0},grid:{color:'rgba(0,0,0,.05)'},title:{display:true,text:'件数',font:{size:10}}} } }
        });
    });
    document.getElementById('itemTrendModal').addEventListener('hidden.bs.modal', function() {
        if (drItemChart) { drItemChart.destroy(); drItemChart = null; }
    });

    // ─── 年間推移グラフ ───────────────────────────────────────────────
    function drRenderChart(d) {
        var trend = d.annual_trend || [];
        var labels = trend.map(function(t){ return t.month + '月'; });
        var personalData = trend.map(function(t){ return t.personal; });
        // 予算達成率優先、旧フィールドにフォールバック
        var rateData = trend.map(function(t){
            return t.budget_achievement_rate !== undefined && t.budget_achievement_rate !== null
                ? t.budget_achievement_rate : t.achievement_rate;
        });
        var hasRate = rateData.some(function(v){ return v !== null; });
        var ctx = document.getElementById('drTrendChart');
        if (!ctx) return;
        if (drTrendChart) { drTrendChart.destroy(); drTrendChart = null; }
        var datasets = [{
            label:'個人獲得数（件）', data: personalData,
            borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.1)',
            fill:true, tension:.35, yAxisID:'y', pointBackgroundColor:'#2563eb', pointRadius:4,
        }];
        if (hasRate) {
            datasets.push({
                label:'予算達成率（%）', data: rateData,
                borderColor:'#d97706', backgroundColor:'transparent',
                fill:false, tension:.35, yAxisID:'y2', borderDash:[4,3],
                pointBackgroundColor:'#d97706', pointRadius:4,
            });
        }
        drTrendChart = new Chart(ctx, {
            type:'line', data:{ labels:labels, datasets:datasets },
            options:{
                responsive:true, maintainAspectRatio:false,
                interaction:{mode:'index',intersect:false},
                plugins:{legend:{position:'top',labels:{font:{size:11},boxWidth:14}}},
                scales:{
                    x:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10}}},
                    y:{type:'linear',position:'left',title:{display:true,text:'件数',font:{size:10},color:'#2563eb'},
                       ticks:{font:{size:10},precision:0},grid:{color:'rgba(0,0,0,.05)'},beginAtZero:true},
                    y2:{type:'linear',position:'right',display:hasRate,
                        title:{display:true,text:'達成率(%)',font:{size:10},color:'#d97706'},
                        ticks:{font:{size:10},callback:function(v){return v+'%';}},
                        grid:{drawOnChartArea:false},beginAtZero:true,max:200},
                },
            },
        });
    }

    // ─── グループ平均達成率表示 ────────────────────────────────────────
    function drRenderGroupSummary(d) {
        var summaryEl = document.getElementById('drGroupSummary');
        var rateEl    = document.getElementById('drGroupAvgRate');
        if (!summaryEl || !rateEl) return;
        if (drFilterType !== 'employee' && d.group_avg_ach_rate !== null && d.group_avg_ach_rate !== undefined) {
            var rate = d.group_avg_ach_rate;
            var color = rate >= 100 ? '#059669' : (rate >= 70 ? '#d97706' : '#ef4444');
            rateEl.textContent = rate + '%';
            rateEl.style.color = color;
            summaryEl.style.display = '';
        } else {
            summaryEl.style.display = 'none';
        }
    }

    // ─── ランキング ───────────────────────────────────────────────────
    function drRenderRanking(d) {
        var ranking = d.ranking || [];
        var wrap    = document.getElementById('drRankingWrap');
        var toggle  = document.getElementById('drRankingToggle');
        if (!wrap) return;
        if (!ranking.length) {
            wrap.innerHTML = '<div class="text-center py-3 text-muted small">データなし<br><span style="font-size:.7rem">（自社外注社員かつ目標設定済み日報が必要です）</span></div>';
            if (toggle) toggle.style.display = 'none';
            return;
        }
        var BADGES = ['🥇','🥈','🥉'];
        var html = '';
        ranking.forEach(function(r, i) {
            var badge   = i < 3 ? BADGES[i] : ('#' + r.rank);
            var isEmoji = i < 3;
            var rateStr = r.achievement_rate !== null ? r.achievement_rate + '%' : '-';
            var rateColor = r.achievement_rate === null ? '#6b7280'
                : (r.achievement_rate >= 100 ? '#059669' : (r.achievement_rate >= 70 ? '#d97706' : '#ef4444'));
            var prevStr = r.prev_rank ? '前月' + r.prev_rank + '位' : '初登場';
            var extra = i >= 3 ? ' dr-ranking-extra' : '';
            var estyle = i >= 3 ? ' style="display:none"' : '';
            html += '<div class="d-flex align-items-center p-2 border-bottom' + extra + '"' + estyle + '>';
            html += '<div class="text-center" style="min-width:36px;font-size:' + (isEmoji ? '1.3' : '.8') + 'rem">' + badge + '</div>';
            html += '<div class="flex-fill ms-2"><div class="fw-semibold" style="font-size:.82rem;line-height:1.3">' + esc(r.location) + '</div>';
            html += '<div class="text-muted" style="font-size:.75rem">' + esc(r.employee_name) + '</div></div>';
            html += '<div class="text-center" style="min-width:68px"><div class="fw-bold" style="font-size:1rem;color:' + rateColor + '">' + rateStr + '</div>';
            html += '<div style="font-size:.58rem;color:#9ca3af">達成率</div></div>';
            html += '<div class="text-center ms-2" style="min-width:54px"><div style="font-size:.65rem;color:#6b7280">' + esc(prevStr) + '</div></div></div>';
        });
        wrap.innerHTML = html;
        drRankingExpanded = false;
        if (toggle) { toggle.style.display = ranking.length > 3 ? '' : 'none'; toggle.textContent = '△ 全て表示'; }
    }
    window.drToggleRanking = function() {
        drRankingExpanded = !drRankingExpanded;
        document.querySelectorAll('.dr-ranking-extra').forEach(function(el){ el.style.display = drRankingExpanded ? 'flex' : 'none'; });
        var btn = document.getElementById('drRankingToggle');
        if (btn) btn.textContent = drRankingExpanded ? '▽ TOP3に戻す' : '△ 全て表示';
    };

    // ─── 日報一覧 ─────────────────────────────────────────────────────
    function drRenderList(d) {
        var reports = d.reports || [];
        var wrap = document.getElementById('drListWrap');
        if (!reports.length) { wrap.innerHTML = '<div class="text-center text-muted py-4">日報データがありません</div>'; return; }
        var html = '';
        var byBiz = {};
        reports.forEach(function(r) {
            var key = normBiz(r.work_type || '') || '不明';
            (byBiz[key] = byBiz[key] || []).push(r);
        });
        Object.keys(byBiz).forEach(function(biz) {
            var rows  = byBiz[biz];
            var bizConf = (d.biz_config || {})[biz] || null;
            var items = bizConf ? (bizConf.personal_items || []) : [];
            // 後方互換: biz_items が空ならキャリア items
            if (!items.length && rows.length) items = rows[0].biz_items || [];
            if (!items.length && rows.length) {
                var carrier = rows[0].carrier || '';
                items = (d.carrier_items || {})[carrier] || [];
            }
            var isShopBiz = (biz === '光AD');
            var color = isShopBiz ? '#2563eb' : '#7c3aed';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0" style="font-size:.72rem">';
            html += '<thead class="table-light"><tr>';
            html += '<th>日付</th><th>社員名</th><th>稼働店舗</th>';
            html += '<th><span class="badge" style="background:' + color + ';font-size:.65rem">' + esc(biz) + '</span></th>';
            html += '<th class="text-center">' + (isShopBiz ? '来店' : 'キャッチ') + '</th>';
            html += '<th class="text-center">' + (isShopBiz ? '接客' : '着座') + '</th>';
            html += '<th class="text-center">提案</th>';
            html += '<th class="text-center">成約</th>';
            items.forEach(function(lbl) {
                html += '<th class="text-center" style="max-width:60px;font-size:.68rem">' + esc(lbl) + '</th>';
            });
            html += '<th>操作</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                html += '<tr>';
                html += '<td style="white-space:nowrap">' + r.work_date + '</td>';
                html += '<td>' + esc(r.employee) + '</td>';
                html += '<td>' + esc(r.location) + '</td>';
                html += '<td>' + esc(r.carrier || '') + '</td>';
                html += '<td class="text-center">' + (r.catch || 0) + '</td>';
                html += '<td class="text-center">' + (r.seated || 0) + '</td>';
                html += '<td class="text-center">' + (r.proposals || 0) + '</td>';
                html += '<td class="text-center">' + (r.contracts || 0) + '</td>';
                items.forEach(function(lbl) {
                    var a = (r.acq || {})[lbl];
                    html += '<td class="text-center" style="font-size:.7rem">' + (a ? a.person : 0) + '</td>';
                });
                html += '<td><button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.6rem" onclick="drDeleteReport(' + r.id + ')"><i class="bi bi-trash"></i></button></td></tr>';
            });
            html += '</tbody></table></div>';
        });
        wrap.innerHTML = html;
    }

    window.drDeleteReport = function(id) {
        if (!confirm('削除しますか？')) return;
        var fd = new FormData();
        fd.append('csrf', drCsrf);
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch(drSaveApiBase, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.success) { drShowFormAlert('success', '削除しました'); drLoad(); }
                else drShowFormAlert('danger', '削除失敗: ' + (res.error || ''));
            })
            .catch(function(){ drShowFormAlert('danger', '通信エラー'); });
    };

    window.drChangeMonth = function(delta) {
        drMonth += delta;
        if (drMonth < 1)  { drMonth = 12; drYear--; }
        if (drMonth > 12) { drMonth = 1;  drYear++; }
        drLoad();
    };

    window.drToggleWeek = function() {
        drWeekMode = !drWeekMode;
        var btn = document.getElementById('drWeekBtn');
        btn.classList.toggle('btn-outline-secondary', !drWeekMode);
        btn.classList.toggle('btn-secondary',         drWeekMode);
        document.querySelectorAll('.kpi-week').forEach(function(el){ el.style.display = drWeekMode ? 'block' : 'none'; });
    };

    // ─── セレクトボックス変更ハンドラ ─────────────────────────────────
    var empSel  = document.getElementById('drEmpSelect');
    var bizSel  = document.getElementById('drBizSelect');
    var carrSel = document.getElementById('drCarrierFilterSelect');
    if (empSel)  empSel.addEventListener('change',  function(){ drFilterValue = this.value; drLoad(); });
    if (bizSel)  bizSel.addEventListener('change',  function(){ drFilterValue = this.value; drLoad(); });
    if (carrSel) carrSel.addEventListener('change', function(){ drFilterValue = this.value; drLoad(); });

    drLoad();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
