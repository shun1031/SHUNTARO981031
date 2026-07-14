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

    <!-- ② KPIサマリーカード（全体/個人/達成率） -->
    <div class="row g-2 mb-3" id="drKpiRow">
        <?php
        $kpiDefs = [
            ['key'=>'catch_count',     'label'=>'キャッチ数', 'icon'=>'bi-megaphone',      'color'=>'#2563eb'],
            ['key'=>'event_seated',    'label'=>'着座数',     'icon'=>'bi-person-check',   'color'=>'#059669'],
            ['key'=>'event_proposals', 'label'=>'提案数',     'icon'=>'bi-clipboard-check','color'=>'#d97706'],
            ['key'=>'event_contracts', 'label'=>'成約数',     'icon'=>'bi-handshake',      'color'=>'#dc2626'],
            ['key'=>'goal',            'label'=>'目標',       'icon'=>'bi-bullseye',       'color'=>'#7c3aed'],
        ];
        foreach ($kpiDefs as $kd): ?>
        <div class="col-6 col-md">
            <div class="card h-100 kpi-card shadow-sm" data-key="<?= $kd['key'] ?>" style="border-top:3px solid <?= $kd['color'] ?>;border-radius:.75rem">
                <div class="card-body p-2">
                    <div class="d-flex align-items-center gap-1 mb-2">
                        <i class="bi <?= $kd['icon'] ?>" style="color:<?= $kd['color'] ?>;font-size:1rem"></i>
                        <span class="fw-semibold" style="color:<?= $kd['color'] ?>;font-size:.78rem"><?= $kd['label'] ?></span>
                    </div>
                    <div class="d-flex gap-1 mb-1">
                        <div class="flex-fill text-center" style="background:#f9fafb;border-radius:.4rem;padding:3px 2px">
                            <div style="font-size:.58rem;color:#9ca3af;line-height:1.4">全体</div>
                            <div class="kpi-total-val fw-bold" style="font-size:1.2rem;color:<?= $kd['color'] ?>;line-height:1.1">-</div>
                            <div style="font-size:.55rem;color:#9ca3af">件</div>
                        </div>
                        <div class="flex-fill text-center" style="background:#f9fafb;border-radius:.4rem;padding:3px 2px">
                            <div style="font-size:.58rem;color:#9ca3af;line-height:1.4">個人</div>
                            <div class="kpi-personal-val fw-bold" style="font-size:1.2rem;color:<?= $kd['color'] ?>;line-height:1.1">-</div>
                            <div style="font-size:.55rem;color:#9ca3af">件</div>
                        </div>
                    </div>
                    <div class="kpi-rate-val text-center" style="font-size:.68rem;color:#6b7280">達成率 <span class="fw-bold">-%</span></div>
                    <div class="kpi-week" style="display:none;margin-top:4px;padding-top:4px;border-top:1px dashed #e5e7eb;font-size:.65rem;color:#6b7280">
                        1週間: <span class="kpi-week-val fw-bold">-</span>件
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ③ キャリア選択タブ + アイテム別KPIカード -->
    <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
        <span class="text-muted small fw-semibold" style="white-space:nowrap">キャリア:</span>
        <?php
        $carrierTabDefs = [
            ['key'=>'SB,YM',     'label'=>'SB / Y!mobile', 'color'=>'#2563eb'],
            ['key'=>'au,UQ',     'label'=>'au / UQ',        'color'=>'#d97706'],
            ['key'=>'ドコモ',    'label'=>'ドコモ',         'color'=>'#dc2626'],
            ['key'=>'楽天',      'label'=>'楽天',            'color'=>'#be123c'],
            ['key'=>'コミュファ','label'=>'コミュファ',     'color'=>'#059669'],
            ['key'=>'CATV',      'label'=>'CATV',            'color'=>'#0891b2'],
        ];
        foreach ($carrierTabDefs as $i => $ct): ?>
        <button type="button" class="btn btn-sm dr-carrier-tab<?= $i === 0 ? ' dr-carrier-tab-active' : '' ?>"
                data-carrier="<?= h($ct['key']) ?>" data-color="<?= $ct['color'] ?>"
                style="border:1.5px solid <?= $ct['color'] ?>;color:<?= $i === 0 ? '#fff' : $ct['color'] ?>;background:<?= $i === 0 ? $ct['color'] : 'transparent' ?>;border-radius:20px;font-size:.75rem;padding:2px 10px;transition:all .15s">
            <?= h($ct['label']) ?>
        </button>
        <?php endforeach; ?>
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

    <!-- ⑤ 日報一覧 -->
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
                            <label class="form-label fw-bold">店舗種別 <span class="text-danger">*</span></label>
                            <select name="work_type" id="drWorkType" class="form-select form-select-sm" required>
                                <option value="">選択してください</option>
                                <option value="ショップ">ショップ</option>
                                <option value="ショップ以外">ショップ以外</option>
                            </select>
                        </div>
                    </div>

                    <!-- 統一フォーム（業務形態選択後に表示） -->
                    <div id="drEventForm" style="display:none">
                        <div class="border rounded p-2" style="background:#fffbeb">
                            <div class="fw-bold mb-2" style="color:#b45309;font-size:.85rem"><i class="bi bi-lightning me-1"></i>日報</div>
                            <!-- 数値フィールド（コンパクト） -->
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <div class="text-center"><div id="drLabelCatch" style="font-size:.68rem;font-weight:600;margin-bottom:2px">キャッチ数</div><input type="number" name="catch_count" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
                                <div class="text-center"><div id="drLabelSeated" style="font-size:.68rem;font-weight:600;margin-bottom:2px">着座数</div><input type="number" name="event_seated" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:55px"></div>
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
                            <!-- 目標設定 -->
                            <div class="border rounded p-2 mt-2" style="background:#f0fdf4">
                                <div class="fw-bold mb-2" style="color:#166534;font-size:.82rem"><i class="bi bi-bullseye me-1"></i>目標設定（任意）</div>
                                <div class="d-flex gap-3 align-items-center flex-wrap">
                                    <div class="d-flex gap-3">
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="radio" name="goal_type" id="goalTypeCount" value="件数">
                                            <label class="form-check-label" for="goalTypeCount" style="font-size:.82rem">件数</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="radio" name="goal_type" id="goalTypePts" value="ポイント">
                                            <label class="form-check-label" for="goalTypePts" style="font-size:.82rem">ポイント</label>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <label style="font-size:.82rem;white-space:nowrap;color:#166534;font-weight:600">目標値</label>
                                        <input type="number" name="goal_value" id="drGoalValue" class="form-control form-control-sm text-center" min="0" placeholder="-" style="width:70px">
                                    </div>
                                    <div id="drAchievementPreview" style="font-size:.78rem;color:#166534;display:none">
                                        達成率目安: <strong id="drAchievementRate">-%</strong>
                                    </div>
                                </div>
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

    function updateFieldLabels(wt) {
        var isShop = (wt === 'ショップ');
        document.getElementById('drLabelCatch').textContent  = isShop ? '来店組数' : 'キャッチ数';
        document.getElementById('drLabelSeated').textContent = isShop ? '接客組数' : '着座数';
    }

    function updateDrForm() {
        var wt = document.getElementById('drWorkType').value;
        var show = !!wt;
        document.getElementById('drEventForm').style.display = show ? 'block' : 'none';
        document.getElementById('drSubmitBtn').disabled = !wt;
        updateFieldLabels(wt);
        var c = document.getElementById('drCarrier').value;
        if (show && c) buildEvtAcq(c);
    }
    document.getElementById('drLocation').addEventListener('input', updateDrForm);
    document.getElementById('drWorkType').addEventListener('change', updateDrForm);

    document.getElementById('reportForm').addEventListener('submit', function(e) {
        // 稼働店舗バリデーション
        var loc = document.getElementById('drLocation').value.trim();
        var locError = document.getElementById('drLocationError');
        if (!loc) {
            e.preventDefault();
            locError.style.display = 'block';
            document.getElementById('drLocation').focus();
            return;
        }
        locError.style.display = 'none';
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
        document.getElementById('drLocationError').style.display = 'none';
        document.getElementById('evtAcqFields').innerHTML     = '<p class="text-muted small text-center mb-0 py-1">キャリアを選択すると入力欄が表示されます</p>';
        document.getElementById('perAcqFields').innerHTML = '<p class="text-muted small text-center mb-0 py-1">全体獲得内訳を入力すると活性化されます</p>';
        document.getElementById('drAchievementPreview').style.display = 'none';
        updateDrForm();
    });

    // 目標達成率プレビュー（成約数 ÷ 目標値）
    function updateAchievementPreview() {
        var goalType = document.querySelector('input[name="goal_type"]:checked');
        var goalVal  = parseInt(document.getElementById('drGoalValue').value || '0');
        var contracts = parseInt(document.getElementById('evtContracts') ? document.getElementById('evtContracts').value || '0' : '0');
        var previewEl = document.getElementById('drAchievementPreview');
        var rateEl    = document.getElementById('drAchievementRate');
        if (goalType && goalType.value === '件数' && goalVal > 0 && contracts >= 0) {
            var rate = Math.round(contracts / goalVal * 100);
            rateEl.textContent = rate + '%';
            previewEl.style.display = 'inline';
        } else {
            previewEl.style.display = 'none';
        }
    }
    document.getElementById('drGoalValue').addEventListener('input', updateAchievementPreview);
    document.querySelectorAll('input[name="goal_type"]').forEach(function(r){
        r.addEventListener('change', updateAchievementPreview);
    });
    document.getElementById('evtContracts').addEventListener('input', updateAchievementPreview);
})();
</script>

<script>
(function(){
    var drYear = <?= $year ?>, drMonth = <?= $month ?>;
    var drEmp  = <?= json_encode($selectedEmp) ?>;
    var drWeekMode = false;
    var drApiBase  = <?= json_encode($drApiBase) ?>;
    var drCsrf     = <?= json_encode(getCsrfToken()) ?>;
    var drTrendChart = null;
    var drLastData   = null;
    var drSelectedCarrier = 'SB,YM';

    var CARRIER_COLORS = {
        'SB,YM':'#2563eb','au,UQ':'#d97706','ドコモ':'#dc2626',
        '楽天':'#be123c','コミュファ':'#059669','CATV':'#0891b2'
    };

    function drLoad() {
        var url = drApiBase + '?employee=' + encodeURIComponent(drEmp) + '&year=' + drYear + '&month=' + drMonth;
        document.getElementById('drListWrap').innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat"></i> 読込中...</div>';
        fetch(url).then(function(r){return r.json();}).then(function(d){
            drLastData = d;
            drRenderKpi(d);
            drRenderItemKpi(d, drSelectedCarrier);
            drRenderChart(d);
            drRenderList(d);
            document.getElementById('drMonthLabel').textContent = drYear + '年' + drMonth + '月';
            document.getElementById('drSubtitle').textContent   = drYear + '年' + drMonth + '月';
        }).catch(function(e){ console.error(e); });
    }

    function drRenderKpi(d) {
        var kpiAll   = d.kpi_all   || {};
        var kpiMonth = d.kpi_month || {};
        var week     = d.kpi_week  || {};
        var goalTotal    = d.goal_total    || 0;
        var goalPersonal = d.goal_personal || 0;

        var SUMMARY = [
            {key:'catch_count',     color:'#2563eb'},
            {key:'event_seated',    color:'#059669'},
            {key:'event_proposals', color:'#d97706'},
            {key:'event_contracts', color:'#dc2626'},
            {key:'goal',            color:'#7c3aed'},
        ];
        SUMMARY.forEach(function(def) {
            var card = document.querySelector('.kpi-card[data-key="' + def.key + '"]');
            if (!card) return;
            var total, personal;
            if (def.key === 'goal') {
                total    = goalTotal;
                personal = goalPersonal;
            } else {
                total    = parseInt(kpiAll[def.key]   || 0);
                personal = parseInt(kpiMonth[def.key] || 0);
            }
            var rate = (total > 0) ? (personal / total * 100).toFixed(1) : '-';
            var wVal = def.key === 'goal' ? '-' : parseInt(week[def.key] || 0);

            card.querySelector('.kpi-total-val').textContent    = total;
            card.querySelector('.kpi-personal-val').textContent = personal;
            var rateColor = (rate === '-') ? '#6b7280'
                : (parseFloat(rate) >= 100 ? '#059669' : (parseFloat(rate) >= 50 ? def.color : '#ef4444'));
            card.querySelector('.kpi-rate-val').innerHTML =
                '達成率 <span style="font-weight:700;color:' + rateColor + '">' + rate + (rate !== '-' ? '%' : '') + '</span>';
            var wkEl = card.querySelector('.kpi-week-val');
            if (wkEl) wkEl.textContent = wVal;
            card.querySelector('.kpi-week').style.display = drWeekMode ? 'block' : 'none';
        });
    }

    function drRenderItemKpi(d, carrier) {
        var itemKpi = (d.carrier_item_kpi || {})[carrier] || {};
        var items   = (d.carrier_items    || {})[carrier] || [];
        var color   = CARRIER_COLORS[carrier] || '#6b7280';
        var wrap    = document.getElementById('drItemKpiRow');
        if (!wrap) return;
        if (!items.length) {
            wrap.innerHTML = '<div class="col-12 text-muted small text-center py-2">データがありません</div>';
            return;
        }
        var html = '';
        items.forEach(function(label) {
            var kpi     = itemKpi[label] || {total:0, personal:0};
            var total   = kpi.total   || 0;
            var personal = kpi.personal || 0;
            var rate    = (total > 0) ? (personal / total * 100).toFixed(1) : '-';
            var rateColor = (rate === '-') ? '#9ca3af'
                : (parseFloat(rate) >= 100 ? '#059669' : (parseFloat(rate) >= 50 ? color : '#ef4444'));
            html += '<div class="col-6 col-md-4 col-lg-2">';
            html += '<div class="card h-100 shadow-sm" style="border-radius:.75rem;border-top:3px solid ' + color + '">';
            html += '<div class="card-body p-2">';
            html += '<div class="fw-semibold mb-1" style="font-size:.72rem;color:' + color + '">' + esc(label) + '</div>';
            html += '<div class="d-flex gap-1 mb-1">';
            html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px">';
            html += '<div style="font-size:.56rem;color:#9ca3af">全体</div>';
            html += '<div style="font-size:1rem;font-weight:700;color:' + color + ';line-height:1.1">' + total + '</div>';
            html += '<div style="font-size:.52rem;color:#9ca3af">件</div>';
            html += '</div>';
            html += '<div class="flex-fill text-center" style="background:#f9fafb;border-radius:.3rem;padding:2px">';
            html += '<div style="font-size:.56rem;color:#9ca3af">個人</div>';
            html += '<div style="font-size:1rem;font-weight:700;color:' + color + ';line-height:1.1">' + personal + '</div>';
            html += '<div style="font-size:.52rem;color:#9ca3af">件</div>';
            html += '</div>';
            html += '</div>';
            html += '<div class="text-center" style="font-size:.65rem;color:#6b7280">達成率 <span style="font-weight:700;color:' + rateColor + '">' + rate + (rate!=='-'?'%':'') + '</span></div>';
            html += '</div></div></div>';
        });
        wrap.innerHTML = html;
    }

    function drRenderChart(d) {
        var trend = d.annual_trend || [];
        var labels = trend.map(function(t){ return t.month + '月'; });
        var personalData = trend.map(function(t){ return t.personal; });
        var rateData     = trend.map(function(t){ return t.achievement_rate; });
        var hasRate = rateData.some(function(v){ return v !== null; });

        var ctx = document.getElementById('drTrendChart');
        if (!ctx) return;
        if (drTrendChart) { drTrendChart.destroy(); drTrendChart = null; }

        var datasets = [
            {
                label: '個人獲得数（件）',
                data: personalData,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,.1)',
                fill: true,
                tension: .35,
                yAxisID: 'y',
                pointBackgroundColor: '#2563eb',
                pointRadius: 4,
            }
        ];
        if (hasRate) {
            datasets.push({
                label: '目標達成率（%）',
                data: rateData,
                borderColor: '#d97706',
                backgroundColor: 'transparent',
                fill: false,
                tension: .35,
                yAxisID: 'y2',
                borderDash: [4,3],
                pointBackgroundColor: '#d97706',
                pointRadius: 4,
            });
        }

        drTrendChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 14 } },
                },
                scales: {
                    x: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { font: { size: 10 } } },
                    y: {
                        type: 'linear', position: 'left',
                        title: { display: true, text: '件数', font: { size: 10 }, color: '#2563eb' },
                        ticks: { font: { size: 10 }, precision: 0 },
                        grid: { color: 'rgba(0,0,0,.05)' },
                        beginAtZero: true,
                    },
                    y2: {
                        type: 'linear', position: 'right', display: hasRate,
                        title: { display: true, text: '達成率(%)', font: { size: 10 }, color: '#d97706' },
                        ticks: { font: { size: 10 }, callback: function(v){ return v + '%'; } },
                        grid: { drawOnChartArea: false },
                        beginAtZero: true,
                        max: 200,
                    },
                },
            },
        });
    }

    function drRenderList(d) {
        var reports = d.reports || [];
        var wrap = document.getElementById('drListWrap');
        if (!reports.length) {
            wrap.innerHTML = '<div class="text-center text-muted py-4">日報データがありません</div>';
            return;
        }
        var html = '';
        var byCarrier = {};
        reports.forEach(function(r){ (byCarrier[r.carrier||''] = byCarrier[r.carrier||''] || []).push(r); });

        Object.keys(byCarrier).forEach(function(carrier) {
            var rows = byCarrier[carrier];
            var items = (d.carrier_items||{})[carrier] || [];
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0" style="font-size:.72rem">';
            html += '<thead class="table-light"><tr>';
            html += '<th>日付</th><th>社員名</th><th>稼働店舗</th><th>キャリア</th>';
            html += '<th class="text-center">キャッチ</th><th class="text-center">着席</th><th class="text-center">提案</th>';
            html += '<th class="text-center">成約<br><span style="font-size:.6rem;color:#6b7280">達成率</span></th>';
            items.forEach(function(lbl) {
                html += '<th class="text-center" style="max-width:60px;font-size:.68rem">' + esc(lbl) + '<br><span style="font-size:.55rem;color:#9ca3af">個/全</span></th>';
            });
            html += '<th>操作</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                html += '<tr>';
                html += '<td style="white-space:nowrap">' + r.work_date + '</td>';
                html += '<td>' + esc(r.employee) + '</td>';
                html += '<td>' + esc(r.location) + '</td>';
                html += '<td>' + esc(r.carrier) + '</td>';
                html += '<td class="text-center">' + (r.catch||0) + '</td>';
                html += '<td class="text-center">' + (r.seated||0) + '</td>';
                html += '<td class="text-center">' + (r.proposals||0) + '</td>';
                var rateHtml = '';
                if (r.achievement_rate !== null && r.achievement_rate !== undefined) {
                    var rc = r.achievement_rate >= 100 ? '#059669' : (r.achievement_rate >= 70 ? '#d97706' : '#dc2626');
                    rateHtml = '<div style="font-size:.62rem;color:'+rc+';font-weight:600">'+r.achievement_rate+'%</div>';
                }
                html += '<td class="text-center"><div>' + (r.contracts||0) + '</div>' + rateHtml + '</td>';
                items.forEach(function(lbl) {
                    var a = (r.acq||{})[lbl];
                    var per = a ? (a.person||0) : 0;
                    var tot = a ? (a.total||0) : 0;
                    html += '<td class="text-center"><div style="font-size:.7rem">' + per + '</div><div style="font-size:.6rem;color:#9ca3af">' + tot + '</div></td>';
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

    // キャリアタブ切替
    document.querySelectorAll('.dr-carrier-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            drSelectedCarrier = this.dataset.carrier;
            var color = this.dataset.color;
            // タブの見た目更新
            document.querySelectorAll('.dr-carrier-tab').forEach(function(b) {
                var c = b.dataset.color;
                b.style.background = 'transparent';
                b.style.color = c;
                b.classList.remove('dr-carrier-tab-active');
            });
            this.style.background = color;
            this.style.color = '#fff';
            this.classList.add('dr-carrier-tab-active');
            if (drLastData) drRenderItemKpi(drLastData, drSelectedCarrier);
        });
    });

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
