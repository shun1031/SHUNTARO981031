<?php
/**
 * EVP BOOK 閲覧ページ
 * 会社の給与・評価・稼働ルール一覧
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();

if (!$cid) { header('Location: index.php'); exit; }

// データ取得
$grades = $db->prepare('SELECT * FROM evp_grades WHERE company_id = ? ORDER BY sort_order');
$grades->execute([$cid]); $grades = $grades->fetchAll();

$positions = $db->prepare('SELECT * FROM evp_positions WHERE company_id = ? ORDER BY sort_order');
$positions->execute([$cid]); $positions = $positions->fetchAll();

$incentives = $db->prepare('SELECT * FROM evp_sales_incentives WHERE company_id = ? ORDER BY sort_order');
$incentives->execute([$cid]); $incentives = $incentives->fetchAll();

$bonusRules = $db->prepare('SELECT * FROM evp_bonus_rules WHERE company_id = ? ORDER BY position_group, sort_order');
$bonusRules->execute([$cid]); $bonusRules = $bonusRules->fetchAll();

$settingsRaw = $db->prepare('SELECT setting_key, setting_value, description FROM evp_base_settings WHERE company_id = ?');
$settingsRaw->execute([$cid]); $settingsRaw = $settingsRaw->fetchAll();
$settings = [];
foreach ($settingsRaw as $s) { $settings[$s['setting_key']] = $s; }

// インセンティブをグレード別にグループ化
$incByGrade = [];
foreach ($incentives as $i) { $incByGrade[$i['grade_key']][] = $i; }

// 賞与ルールをグループ別に
$bonusByGroup = [];
foreach ($bonusRules as $b) { $bonusByGroup[$b['position_group']][] = $b; }

$baseSalary = (int)($settings['base_salary']['setting_value'] ?? 230000);

$pageTitle = 'EVP BOOK';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($grades) && empty($positions)): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>この会社のEVP情報は未登録です。</div>
<?php else: ?>

<div class="text-center mb-5">
    <h3 class="fw-bold">EVP BOOK</h3>
    <p class="text-muted">給与・評価・稼働ルール一覧</p>
</div>

<!-- Section 1: 給与体系の全体構造 -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-layers me-2"></i>給与体系の全体構造</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-lg-7">
                <div class="d-flex flex-column gap-2">
                    <div class="p-3 rounded-3 border" style="background:#f0fdf4">
                        <div class="d-flex justify-content-between"><strong>基本給</strong><span class="fw-bold">¥<?= number_format($baseSalary) ?></span></div>
                    </div>
                    <div class="text-center text-muted">+</div>
                    <div class="p-3 rounded-3 border" style="background:#f0fdf4">
                        <div class="d-flex justify-content-between"><strong>等級給</strong><span class="text-muted">支給（等級により変動）</span></div>
                    </div>
                    <div class="text-center text-muted">+</div>
                    <div class="p-3 rounded-3 border" style="background:#f0fdf4">
                        <div class="d-flex justify-content-between"><strong>役職給</strong><span class="text-muted">支給（役職により変動）</span></div>
                    </div>
                    <div class="text-center text-muted">+</div>
                    <div class="p-3 rounded-3 border" style="background:#fffbeb">
                        <div class="d-flex justify-content-between"><strong>インセンティブ</strong><span class="text-muted">営業 or 役職</span></div>
                    </div>
                    <div class="text-center text-muted">+</div>
                    <div class="p-3 rounded-3 border" style="background:#fffbeb">
                        <div class="d-flex justify-content-between"><strong>賞与</strong><span class="text-muted">年2回</span></div>
                    </div>
                    <div class="text-center text-muted">+</div>
                    <div class="p-3 rounded-3 border">
                        <div class="d-flex justify-content-between"><strong>交通費</strong><span class="text-muted">ガソリン代＋高速代</span></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mt-4 mt-lg-0">
                <div class="card" style="border-color:#d4a843">
                    <div class="card-header text-center" style="background:#fef3c7;color:#92400e;font-weight:bold">福利厚生詳細</div>
                    <div class="card-body">
                        <?php foreach ($positions as $p): ?>
                            <?php if ($p['housing_allowance_single'] > 0 || $p['company_car']): ?>
                            <div class="mb-2">
                                <strong><?= h($p['position_name']) ?>:</strong>
                                <?php if ($p['housing_allowance_single'] > 0): ?>
                                <div class="small">家賃補助 — 独身: ¥<?= number_format($p['housing_allowance_single']) ?> / 既婚: ¥<?= number_format($p['housing_allowance_married']) ?></div>
                                <?php endif; ?>
                                <?php if ($p['company_car']): ?>
                                <div class="small">社用車支給</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section 2: 等級・役職の構造 -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-diagram-3 me-2"></i>等級・役職の構造</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th class="text-center text-white py-3" style="background:#6ab0a0;border-radius:8px 0 0 0">等級（Grade）</th><th class="text-center text-white py-3" style="background:#6ab0a0;border-radius:0 8px 0 0">役職（Position）</th></tr></thead>
                <tbody>
                    <?php for ($i = 0; $i < max(count($grades), count($positions)); $i++): ?>
                    <tr>
                        <td class="text-center py-3"><?= h($grades[$i]['grade_name'] ?? '') ?></td>
                        <td class="text-center py-3"><?= h($positions[$i]['position_name'] ?? '') ?></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-light border-start border-4" style="border-color:#d4a843!important">
            等級と役職は「別の概念」として管理されます。役職は「任命制」となり、等級条件を満たした上で任命されます。
        </div>
    </div>
</div>

<!-- Section 3: 等級 昇格・降格条件 -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-arrow-up-circle me-2"></i>等級 | 昇格・降格条件</h5>
    </div>
    <div class="card-body">
        <?php foreach ($grades as $g): ?>
        <?php if ($g['promotion_condition'] || $g['demotion_condition']): ?>
        <div class="p-3 mb-3 rounded-3" style="background:#f0fdf4;border-left:4px solid #6ab0a0">
            <strong class="d-block mb-2" style="color:#047857"><?= h($g['grade_name']) ?></strong>
            <?php if ($g['promotion_condition']): ?>
            <div class="mb-1"><span class="badge" style="background:#d4a843">昇格</span> <span class="ms-2"><?= h($g['promotion_condition']) ?></span></div>
            <?php endif; ?>
            <?php if ($g['demotion_condition']): ?>
            <div><span class="badge bg-secondary">降格</span> <span class="ms-2"><?= h($g['demotion_condition']) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Section 4: 役職 昇格・降格条件 -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-person-up me-2"></i>役職 | 昇格・降格条件</h5>
    </div>
    <div class="card-body">
        <?php foreach ($positions as $p): ?>
        <?php if ($p['promotion_condition'] || $p['demotion_condition']): ?>
        <div class="mb-4">
            <h6 style="color:#d4a843"><?= h($p['position_name']) ?></h6>
            <?php if ($p['promotion_condition']): ?>
            <div class="mb-1"><span class="badge" style="background:#6ab0a0">昇格</span> <span class="ms-2"><?= h($p['promotion_condition']) ?></span></div>
            <?php endif; ?>
            <?php if ($p['demotion_condition']): ?>
            <div><span class="badge bg-secondary">降格</span> <span class="ms-2"><?= h($p['demotion_condition']) ?></span></div>
            <?php endif; ?>
            <hr>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Section 5: 等級給・役職給 -->
<div class="row mb-5">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header text-center text-white" style="background:#6ab0a0"><strong>等級給</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table mb-0">
                    <?php foreach ($grades as $g): ?>
                    <tr><td class="ps-4"><?= h($g['grade_name']) ?></td><td class="text-end pe-4 fw-bold"><?= $g['grade_pay'] > 0 ? '¥' . number_format($g['grade_pay']) : 'なし' ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header text-center text-white" style="background:#d4a843"><strong>役職給</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table mb-0">
                    <?php foreach ($positions as $p): ?>
                    <?php if ($p['position_pay'] > 0): ?>
                    <tr><td class="ps-4"><?= h($p['position_name']) ?></td><td class="text-end pe-4 fw-bold">¥<?= number_format($p['position_pay']) ?></td></tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </table></div>
            </div>
        </div>
    </div>
</div>

<!-- Section 6: 営業インセンティブ -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-cash-stack me-2"></i>営業インセンティブ</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($grades as $g):
                $gInc = $incByGrade[$g['grade_key']] ?? [];
                if (empty($gInc)) continue;
            ?>
            <div class="col-md-6 mb-3">
                <div class="card h-100" style="border-color:#6ab0a0">
                    <div class="card-header" style="background:#f0fdf4;color:#047857;font-weight:600"><?= h($g['grade_name']) ?></div>
                    <div class="card-body p-0">
                        <div class="table-responsive"><table class="table table-sm mb-0">
                            <?php foreach ($gInc as $inc): ?>
                            <tr>
                                <td class="ps-3"><?= h($inc['description']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Section 7: 役職インセンティブ -->
<?php $posWithInc = array_filter($positions, fn($p) => $p['incentive_rate'] > 0); ?>
<?php if (!empty($posWithInc)): ?>
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-trophy me-2"></i>役職インセンティブ</h5>
    </div>
    <div class="card-body">
        <div class="row justify-content-center">
            <?php foreach ($posWithInc as $p): ?>
            <div class="col-md-4 mb-3">
                <div class="card text-center h-100" style="border-color:#6ab0a0">
                    <div class="card-header" style="background:#f0fdf4"><strong><?= h($p['position_name']) ?></strong></div>
                    <div class="card-body">
                        <div class="display-4 fw-bold" style="color:#d4a843"><?= number_format($p['incentive_rate'], 0) ?>%</div>
                        <div class="text-muted mt-2"><?= h($p['incentive_base']) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Section 8: 賞与変動ルール -->
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-gift me-2"></i>賞与変動ルール（人事評価連動）</h5>
    </div>
    <div class="card-body">
        <div class="small mb-3 d-flex flex-wrap gap-3 text-muted">
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i>賞与は人事評価結果により支給率が変動する</span>
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i>評価点数は100点満点</span>
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i>賞与支給は年2回（6月・12月）</span>
        </div>
        <div class="row">
            <?php foreach ($bonusByGroup as $group => $rules): ?>
            <div class="col-md-6 mb-3">
                <h6 class="fw-bold"><?= $group === 'manager_director' ? '部長・課長 | 賞与支給率' : '主任・一般 | 賞与支給率' ?></h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead><tr style="background:#6ab0a0;color:#fff"><th>評価ランク</th><th>評価点数</th><th style="color:#fef3c7">賞与支給率</th></tr></thead>
                        <tbody>
                            <?php foreach ($rules as $r): ?>
                            <tr>
                                <td class="text-center fw-semibold">ランク<?= h($r['eval_rank']) ?></td>
                                <td class="text-center"><?= $r['min_score'] > 0 ? $r['min_score'] . '〜' . $r['max_score'] . '点' : '〜' . $r['max_score'] . '点' ?></td>
                                <td class="text-center fw-bold" style="color:#d4a843"><?= number_format($r['bonus_rate'], 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Section 9: 稼働スケジュール -->
<?php if (!empty($settings['work_days']) || !empty($settings['day_off'])): ?>
<div class="card mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4">
        <h5 class="fw-bold" style="color:#047857"><i class="bi bi-calendar3 me-2"></i>稼働スケジュール</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card h-100" style="border-color:#6ab0a0">
                    <div class="card-body">
                        <h6 style="color:#d4a843"><i class="bi bi-cup-hot me-2"></i>休日・会議</h6>
                        <hr>
                        <div class="mb-2"><strong>休日:</strong> <?= h($settings['day_off']['setting_value'] ?? '') ?></div>
                        <div><strong>会議（<?= h($settings['meeting_day']['setting_value'] ?? '') ?>）:</strong><br>11:00〜 リバース（キャリア）出社<br>14:00〜 自社会議</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card h-100" style="border-color:#6ab0a0">
                    <div class="card-body">
                        <h6 style="color:#d4a843"><i class="bi bi-briefcase me-2"></i>営業稼働</h6>
                        <hr>
                        <div class="mb-2"><strong>営業稼働日:</strong> <?= h($settings['work_days']['setting_value'] ?? '') ?></div>
                        <div><strong>稼働時間:</strong> <?= h($settings['work_hours']['setting_value'] ?? '') ?>（新規訪問）<br><small class="text-muted">※再訪は19:00以降も可</small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- リンク -->
<div class="text-center mb-4">
    <a href="income_simulator.php" class="btn btn-success me-2"><i class="bi bi-calculator me-1"></i>年収シミュレーター</a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i>ダッシュボード</a>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
