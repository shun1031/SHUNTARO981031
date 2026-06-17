<?php
/**
 * 推定年収シミュレーター
 * EVP BOOKデータを元にリアルタイム計算
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

$incentives = $db->prepare('SELECT * FROM evp_sales_incentives WHERE company_id = ? ORDER BY grade_key, sort_order');
$incentives->execute([$cid]); $incentives = $incentives->fetchAll();

$bonusRules = $db->prepare('SELECT * FROM evp_bonus_rules WHERE company_id = ? ORDER BY position_group, sort_order');
$bonusRules->execute([$cid]); $bonusRules = $bonusRules->fetchAll();

$settingsRaw = $db->prepare('SELECT setting_key, setting_value FROM evp_base_settings WHERE company_id = ?');
$settingsRaw->execute([$cid]);
$settings = [];
foreach ($settingsRaw->fetchAll() as $s) { $settings[$s['setting_key']] = $s['setting_value']; }

$baseSalary = (int)($settings['base_salary'] ?? 230000);
$avgGrossProfit = (int)($settings['avg_gross_profit_per_deal'] ?? 1100000);
$carAnnual = (int)($settings['company_car_annual'] ?? 360000);

// JSON化
$gradesJson = json_encode($grades, JSON_UNESCAPED_UNICODE);
$positionsJson = json_encode($positions, JSON_UNESCAPED_UNICODE);
$incentivesJson = json_encode($incentives, JSON_UNESCAPED_UNICODE);
$bonusRulesJson = json_encode($bonusRules, JSON_UNESCAPED_UNICODE);

$pageTitle = '推定年収シミュレーター';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($grades)): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>この会社のEVP情報が未登録です。</div>
<?php else: ?>

<div class="text-center mb-4">
    <h4 class="fw-bold"><i class="bi bi-calculator me-2"></i>推定年収シミュレーター</h4>
    <p class="text-muted small">1実行あたり平均粗利 ¥<?= number_format($avgGrossProfit) ?> ベース</p>
</div>

<div class="row">
    <!-- 入力フォーム -->
    <div class="col-lg-5 mb-4">
        <div class="card sticky-top" style="top:80px">
            <div class="card-header bg-success text-white"><i class="bi bi-sliders me-2"></i>条件設定</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">等級</label>
                    <select id="gradeSelect" class="form-select" onchange="recalc()">
                        <?php foreach ($grades as $g): ?>
                        <option value="<?= h($g['grade_key']) ?>" data-pay="<?= $g['grade_pay'] ?>"><?= h($g['grade_name']) ?>（等級給 ¥<?= number_format($g['grade_pay']) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">役職</label>
                    <select id="positionSelect" class="form-select" onchange="recalc()">
                        <?php foreach ($positions as $p): ?>
                        <option value="<?= h($p['position_key']) ?>"
                                data-pay="<?= $p['position_pay'] ?>"
                                data-inc-rate="<?= $p['incentive_rate'] ?>"
                                data-inc-type="<?= h($p['incentive_type'] ?? '') ?>"
                                data-housing-s="<?= $p['housing_allowance_single'] ?>"
                                data-housing-m="<?= $p['housing_allowance_married'] ?>"
                                data-car="<?= $p['company_car'] ?>">
                            <?= h($p['position_name']) ?>（役職給 ¥<?= number_format($p['position_pay']) ?>）
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">月間実行数</label>
                    <input type="number" id="dealsInput" class="form-control" value="1.5" min="0" max="10" step="0.5" onchange="recalc()" oninput="recalc()">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">1実行あたり粗利（万円）</label>
                    <input type="number" id="grossProfitInput" class="form-control" value="<?= $avgGrossProfit / 10000 ?>" min="0" max="500" step="10" onchange="recalc()" oninput="recalc()">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">婚姻状況</label>
                    <select id="marriageSelect" class="form-select" onchange="recalc()">
                        <option value="single">独身</option>
                        <option value="married">既婚</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">評価ランク（賞与用）</label>
                    <select id="evalRankSelect" class="form-select" onchange="recalc()">
                        <option value="A">ランクA（80〜84点）</option>
                        <option value="S">ランクS（90点以上）</option>
                        <option value="A+">ランクA+（85〜89点）</option>
                        <option value="B">ランクB（70〜79点）</option>
                        <option value="C">ランクC（〜69点）</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- 結果表示 -->
    <div class="col-lg-7">
        <!-- 推定年収ヘッダー -->
        <div class="card mb-4" style="border-color:#047857;border-width:2px">
            <div class="card-body text-center py-4">
                <div class="text-muted small mb-1">推定年収</div>
                <div class="display-4 fw-bold" style="color:#047857" id="totalAnnual">---</div>
                <div class="text-muted small mt-1">※モデルケースによる試算です</div>
            </div>
        </div>

        <!-- 月収内訳 -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-wallet2 me-2"></i>月収内訳</div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table mb-0">
                    <tbody>
                        <tr><td class="ps-4">基本給</td><td class="text-end pe-4 fw-bold" id="rowBase">¥<?= number_format($baseSalary) ?></td></tr>
                        <tr><td class="ps-4">等級給</td><td class="text-end pe-4 fw-bold" id="rowGrade">---</td></tr>
                        <tr><td class="ps-4">役職給</td><td class="text-end pe-4 fw-bold" id="rowPosition">---</td></tr>
                        <tr style="background:#f0fdf4"><td class="ps-4 fw-bold">月給合計</td><td class="text-end pe-4 fw-bold" style="color:#047857" id="rowMonthly">---</td></tr>
                        <tr><td class="ps-4">営業インセンティブ</td><td class="text-end pe-4 fw-bold" style="color:#d4a843" id="rowSalesInc">---</td></tr>
                        <tr><td class="ps-4">役職インセンティブ</td><td class="text-end pe-4 fw-bold" style="color:#d4a843" id="rowPosInc">---</td></tr>
                        <tr style="background:#fffbeb"><td class="ps-4 fw-bold">月収合計</td><td class="text-end pe-4 fw-bold" style="color:#92400e" id="rowMonthTotal">---</td></tr>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- 年収内訳 -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>年収内訳</div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table mb-0">
                    <tbody>
                        <tr><td class="ps-4">月収 × 12ヶ月</td><td class="text-end pe-4 fw-bold" id="rowAnnualBase">---</td></tr>
                        <tr><td class="ps-4">賞与（年2回）</td><td class="text-end pe-4 fw-bold" style="color:#d4a843" id="rowBonus">---</td></tr>
                        <tr><td class="ps-4">家賃補助（年間）</td><td class="text-end pe-4 fw-bold" id="rowHousing">---</td></tr>
                        <tr><td class="ps-4">社用車（年間）</td><td class="text-end pe-4 fw-bold" id="rowCar">---</td></tr>
                        <tr style="background:#f0fdf4;font-size:1.1em"><td class="ps-4 fw-bold">推定年収合計</td><td class="text-end pe-4 fw-bold" style="color:#047857" id="rowTotal">---</td></tr>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- チャート -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>年収構成</div>
            <div class="card-body">
                <canvas id="incomeChart" style="max-height:300px"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="text-center mb-4">
    <a href="evp_book.php" class="btn btn-outline-success me-2"><i class="bi bi-journal-text me-1"></i>EVP BOOKを見る</a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i>ダッシュボード</a>
</div>

<script>
const BASE_SALARY = <?= $baseSalary ?>;
const CAR_ANNUAL = <?= $carAnnual ?>;
const grades = <?= $gradesJson ?>;
const positions = <?= $positionsJson ?>;
const incentives = <?= $incentivesJson ?>;
const bonusRules = <?= $bonusRulesJson ?>;

let chart = null;

function fmt(n) { return '¥' + Math.round(n).toLocaleString(); }
function fmtMan(n) { return Math.round(n / 10000).toLocaleString() + '万円'; }

function calcSalesIncentive(gradeKey, deals, grossProfit) {
    const rules = incentives.filter(i => i.grade_key === gradeKey);
    if (!rules.length) return 0;

    // Check if gross_profit_rate type
    const rateRule = rules.find(r => r.incentive_type === 'gross_profit_rate');
    if (rateRule) {
        return grossProfit * deals * (parseFloat(rateRule.rate) / 100);
    }

    // Deal-based
    let total = 0;
    const dealCount = Math.floor(deals);
    if (dealCount <= 0) return 0;

    for (const r of rules) {
        const min = parseInt(r.min_deals);
        const max = r.max_deals ? parseInt(r.max_deals) : 999;
        if (dealCount >= min && dealCount <= max) {
            if (r.incentive_type === 'fixed') {
                total = parseInt(r.amount);
            } else if (r.incentive_type === 'per_deal') {
                total = parseInt(r.amount) * dealCount;
            }
            break;
        }
    }
    return total;
}

function getBonusRate(posKey, evalRank) {
    const posGroup = (posKey === 'director' || posKey === 'manager') ? 'manager_director' : 'staff';
    const rule = bonusRules.find(r => r.position_group === posGroup && r.eval_rank === evalRank);
    return rule ? parseFloat(rule.bonus_rate) : 1.0;
}

function recalc() {
    const gradeEl = document.getElementById('gradeSelect');
    const posEl = document.getElementById('positionSelect');
    const deals = parseFloat(document.getElementById('dealsInput').value) || 0;
    const grossProfitMan = parseFloat(document.getElementById('grossProfitInput').value) || 0;
    const grossProfit = grossProfitMan * 10000;
    const marriage = document.getElementById('marriageSelect').value;
    const evalRank = document.getElementById('evalRankSelect').value;

    const gradeKey = gradeEl.value;
    const gradePay = parseInt(gradeEl.selectedOptions[0].dataset.pay) || 0;
    const posKey = posEl.value;
    const opt = posEl.selectedOptions[0];
    const posPay = parseInt(opt.dataset.pay) || 0;
    const posIncRate = parseFloat(opt.dataset.incRate) || 0;
    const housingS = parseInt(opt.dataset.housingS) || 0;
    const housingM = parseInt(opt.dataset.housingM) || 0;
    const hasCar = parseInt(opt.dataset.car) || 0;

    // Monthly
    const monthly = BASE_SALARY + gradePay + posPay;
    const salesInc = calcSalesIncentive(gradeKey, deals, grossProfit);
    const monthlySales = grossProfit * deals;
    const posInc = posIncRate > 0 ? monthlySales * (posIncRate / 100) : 0;
    const monthTotal = monthly + salesInc + posInc;

    // Annual
    const annualBase = monthTotal * 12;
    const bonusRate = getBonusRate(posKey, evalRank);
    const annualSales = monthlySales * 12;
    const bonus = annualSales * (bonusRate / 100) * 2; // 年2回
    const housing = (marriage === 'married' ? housingM : housingS) * 12;
    const car = hasCar ? CAR_ANNUAL : 0;
    const totalAnnual = annualBase + bonus + housing + car;

    // Update DOM
    document.getElementById('rowBase').textContent = fmt(BASE_SALARY);
    document.getElementById('rowGrade').textContent = fmt(gradePay);
    document.getElementById('rowPosition').textContent = fmt(posPay);
    document.getElementById('rowMonthly').textContent = fmt(monthly);
    document.getElementById('rowSalesInc').textContent = fmt(salesInc);
    document.getElementById('rowPosInc').textContent = fmt(posInc);
    document.getElementById('rowMonthTotal').textContent = fmt(monthTotal);
    document.getElementById('rowAnnualBase').textContent = fmtMan(monthTotal * 12);
    document.getElementById('rowBonus').textContent = fmtMan(bonus);
    document.getElementById('rowHousing').textContent = housing > 0 ? fmtMan(housing) : '—';
    document.getElementById('rowCar').textContent = car > 0 ? fmtMan(car) : '—';
    document.getElementById('rowTotal').textContent = fmtMan(totalAnnual);
    document.getElementById('totalAnnual').textContent = fmtMan(totalAnnual);

    // Chart
    updateChart(monthTotal * 12, bonus, housing, car);
}

function updateChart(base, bonus, housing, car) {
    const ctx = document.getElementById('incomeChart');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['月収×12', '賞与', '家賃補助', '社用車'],
            datasets: [{
                data: [Math.round(base), Math.round(bonus), Math.round(housing), Math.round(car)],
                backgroundColor: ['#047857', '#d4a843', '#6ab0a0', '#9ca3af']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 12 } } },
                tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + Math.round(ctx.raw / 10000).toLocaleString() + '万円'; } } }
            }
        }
    });
}

// 初期計算
document.addEventListener('DOMContentLoaded', recalc);
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
