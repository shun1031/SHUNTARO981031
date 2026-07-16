<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h1>403 アクセス権限がありません</h1>';
    echo '<p>この機能は管理者のみ利用できます。</p>';
    echo '<a href="' . BASE_PATH . '/public/index.php">ダッシュボードへ戻る</a>';
    echo '</body></html>';
    exit;
}
$cid = getCompanyId();

$pageTitle = '社員一覧';

$db = getDB();

// チームフィルター
$teamFilter = isset($_GET['team']) ? (int)$_GET['team'] : 0;
$teams = getAllTeams($cid);

// 社員一覧取得
$sql = 'SELECT e.*,
               t.name AS team_name, t.id AS team_id,
               sf.achiever, sf.strategic, sf.learner, sf.maximizer, sf.relator,
               sf.futuristic, sf.analytical, sf.top5_text,
               sp.leadership, sp.teamwork, sp.problem_solving
        FROM employees e
        LEFT JOIN team_members tm ON e.id = tm.employee_id
        LEFT JOIN teams t ON tm.team_id = t.id
        LEFT JOIN strengths_finder sf ON e.id = sf.employee_id
        LEFT JOIN spi_results sp ON e.id = sp.employee_id
        WHERE e.is_active = 1';
$params = [];
if ($cid) {
    $sql .= ' AND e.company_id = ?';
    $params[] = $cid;
}
if ($teamFilter > 0) {
    $sql .= ' AND tm.team_id = ?';
    $params[] = $teamFilter;
}
$sql .= ' ORDER BY e.employee_number, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$sfThemes = getStrengthsThemeDefinitions();

// 社員区分の判定（雇用形態の選択肢と対応。表示フィルタ用）
function empCategory(array $e): string {
    $t = $e['employment_type'] ?? '';
    $s = $e['employment_subtype'] ?? '';
    switch ($t) {
        case '正社員':     return 'seishain';
        case '自社外注':   return 'inhouse_out';
        case '個人外注':   return 'personal_out';
        case 'アライアンス': return 'alliance';
        case 'アルバイト': return 'part_time';
    }
    // 旧データ互換（自社+区分）
    if ($t === '自社') {
        if ($s === '外注')       return 'inhouse_out';
        if ($s === 'アルバイト') return 'part_time';
        return 'seishain';
    }
    return 'seishain';
}

// アライアンス会社一覧（マスタ + アライアンス社員の所属会社を統合）
$allianceCompanies = [];
try {
    $aStmt = $db->prepare('SELECT alliance_name FROM sales_alliances WHERE company_id = ? AND is_active = 1 ORDER BY sort_order, alliance_name');
    $aStmt->execute([$cid]);
    $allianceCompanies = $aStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (PDOException $e) {}
foreach ($employees as $empRow) {
    $aff = trim($empRow['affiliation_company'] ?? '');
    if ($aff !== '' && empCategory($empRow) === 'alliance' && !in_array($aff, $allianceCompanies, true)) {
        $allianceCompanies[] = $aff;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.emp-cat-tabs { display:flex; flex-wrap:nowrap; overflow-x:auto; margin:0; padding:0; list-style:none; }
.emp-cat-tabs li { flex:1 1 0; min-width:110px; }
.emp-cat-tabs button {
    width:100%; background:none; border:none; border-bottom:3px solid transparent;
    padding:14px 12px; font-weight:600; font-size:.9rem; color:#6b7280;
    white-space:nowrap; display:flex; align-items:center; justify-content:center; gap:7px;
    transition:color .15s ease, border-color .15s ease; cursor:pointer;
}
.emp-cat-tabs button:hover { color:#374151; }
.emp-cat-tabs button.active { color:#2563eb; border-bottom-color:#2563eb; }
@media (max-width: 576px) {
    .emp-cat-tabs button { font-size:.78rem; padding:11px 8px; gap:4px; }
    .emp-cat-tabs li { min-width:90px; }
}
</style>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-lines-fill me-2"></i>社員一覧</h1>
                <p><?= count($employees) ?>名の社員情報</p>
            </div>
        </div>
    </div>

    <!-- 社員区分タブ -->
    <div class="card mb-3">
        <div class="card-body p-0">
            <ul class="emp-cat-tabs" id="empCatTabs">
                <li><button type="button" class="active" data-cat="seishain"><i class="bi bi-person-fill"></i>正社員</button></li>
                <li><button type="button" data-cat="inhouse_out"><i class="bi bi-building-gear"></i>自社外注</button></li>
                <li><button type="button" data-cat="personal_out"><i class="bi bi-person-badge"></i>個人外注</button></li>
                <li><button type="button" data-cat="alliance"><i class="bi bi-people"></i>アライアンス</button></li>
                <li><button type="button" data-cat="part_time"><i class="bi bi-clock-history"></i>アルバイト</button></li>
            </ul>
        </div>
    </div>

    <!-- フィルター・検索 -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div id="allianceFilterLabel" class="fw-semibold small mb-2" style="display:none">アライアンス会社で絞り込み</div>
            <div class="row g-2 align-items-center">
                <div class="col-md-3" id="allianceFilterWrap" style="display:none">
                    <select id="allianceCompanyFilter" class="form-select">
                        <option value="">すべての会社</option>
                        <?php foreach ($allianceCompanies as $ac): ?>
                        <option value="<?= h($ac) ?>"><?= h($ac) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="employeeSearch" class="form-control" placeholder="名前・職種・部署で検索...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 社員グリッド -->
    <div class="row g-3">
        <?php foreach ($employees as $emp): ?>
        <?php
            $initial  = mb_substr($emp['name'], 0, 1);
            $top5     = [];
            if (!empty($emp['top5_text'])) {
                $top5 = explode('・', $emp['top5_text']);
            }
            $searchText = strtolower($emp['name'] . ' ' . ($emp['name_kana'] ?? '') . ' ' . ($emp['job_title'] ?? '') . ' ' . ($emp['department'] ?? '') . ' ' . ($emp['team_name'] ?? ''));
        ?>
        <div class="col-sm-6 col-xl-4 emp-card-col" data-category="<?= h(empCategory($emp)) ?>" data-alliance="<?= h(trim($emp['affiliation_company'] ?? '')) ?>">
            <a href="employee.php?id=<?= $emp['id'] ?>" class="employee-card p-4 h-100"
               data-search="<?= h($searchText) ?>" data-bs-class="employee-filter-item">
                <div class="employee-filter-item" data-search="<?= h($searchText) ?>">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="avatar flex-shrink-0">
                            <?php if ($emp['photo_path'] && file_exists(UPLOAD_DIR . $emp['photo_path'])): ?>
                                <img src="<?= h(UPLOAD_URL . $emp['photo_path']) ?>" alt="<?= h($emp['name']) ?>">
                            <?php else: ?>
                                <?= h($initial) ?>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-bold fs-6 text-dark"><?= h($emp['name']) ?></div>
                            <?php if ($emp['name_kana']): ?>
                            <div class="text-muted" style="font-size:11px"><?= h($emp['name_kana']) ?></div>
                            <?php endif; ?>
                            <div class="text-muted small mt-1">
                                <?php if ($emp['job_title']): ?>
                                <i class="bi bi-briefcase me-1"></i><?= h($emp['job_title']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($emp['team_name']): ?>
                            <span class="badge bg-light text-dark mt-1" style="font-size:11px">
                                <i class="bi bi-diagram-3 me-1"></i><?= h($emp['team_name']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($emp['hire_date']): ?>
                    <div class="text-muted small mb-2">
                        <i class="bi bi-calendar3 me-1"></i>入社: <?= formatDate($emp['hire_date'], 'Y年n月') ?>
                        <span class="ms-2 text-info"><?= getYearsOfService($emp['hire_date']) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($top5)): ?>
                    <div class="mt-2">
                        <div class="info-label mb-1">トップ資質</div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach (array_slice($top5, 0, 3) as $i => $theme): ?>
                            <span class="badge" style="font-size:11px;background:#eff3ff;color:#3d5a9e">
                                <?= h(trim($theme)) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3 d-flex gap-2 text-muted" style="font-size:11px">
                        <?php if ($emp['achiever'] !== null): ?>
                        <span><i class="bi bi-lightning-fill text-warning"></i> SF</span>
                        <?php endif; ?>
                        <?php if ($emp['leadership'] !== null): ?>
                        <span><i class="bi bi-activity text-success"></i> SPI</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>

        <?php if (empty($employees)): ?>
        <div class="col-12">
            <div class="card text-center p-5">
                <i class="bi bi-person-x fs-1 text-muted mb-3"></i>
                <p class="text-muted">社員データがありません。<br>管理画面から登録してください。</p>
                <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_PATH ?>/admin/employee_form.php" class="btn btn-primary mt-2">社員を追加する</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// 社員区分タブ + アライアンス会社 + 検索 の複合フィルター（リロードなし）
(function() {
    var currentCat = 'seishain';

    function applyFilter() {
        var q = (document.getElementById('employeeSearch')?.value || '').toLowerCase();
        var compSel = document.getElementById('allianceCompanyFilter');
        var comp = compSel ? compSel.value : '';
        document.querySelectorAll('.emp-card-col').forEach(function(col) {
            var catOk  = (col.dataset.category || 'seishain') === currentCat;
            var compOk = currentCat !== 'alliance' || comp === '' || col.dataset.alliance === comp;
            var item = col.querySelector('.employee-filter-item');
            var text = item ? (item.dataset.search || '') : '';
            var qOk = q === '' || text.includes(q);
            col.style.display = (catOk && compOk && qOk) ? '' : 'none';
        });
    }

    // タブ切り替え
    document.querySelectorAll('#empCatTabs button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#empCatTabs button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            currentCat = this.dataset.cat;
            var isAlliance = currentCat === 'alliance';
            var wrap  = document.getElementById('allianceFilterWrap');
            var label = document.getElementById('allianceFilterLabel');
            if (wrap)  wrap.style.display  = isAlliance ? '' : 'none';
            if (label) label.style.display = isAlliance ? '' : 'none';
            if (!isAlliance) {
                var sel = document.getElementById('allianceCompanyFilter');
                if (sel) sel.value = '';
            }
            applyFilter();
        });
    });

    // アライアンス会社切り替え
    document.getElementById('allianceCompanyFilter')?.addEventListener('change', applyFilter);

    // 検索（既存の動作を維持）
    document.getElementById('employeeSearch')?.addEventListener('input', applyFilter);

    // 初期表示: 正社員タブ
    applyFilter();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
