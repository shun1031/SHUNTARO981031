<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '交通費管理';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// If no year/month specified in URL, find latest month with data
if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $latestRow = getDB()->prepare("SELECT target_year, target_month FROM sales_transport_costs WHERE company_id = ? ORDER BY target_year DESC, target_month DESC LIMIT 1");
    $latestRow->execute([$cid]);
    $latest = $latestRow->fetch();
    if ($latest) {
        $year = (int)$latest['target_year'];
        $month = (int)$latest['target_month'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    // 一般社員は自分のデータのみ操作可能。管理者は全データ操作可。
    $myName = getEmployeeNameFilter();

    if ($action === 'save') {
        $postEmpName = trim($_POST['employee_name'] ?? '');
        if ($myName !== null && $myName !== $postEmpName) {
            http_response_code(403);
            die('他のユーザーのデータは登録できません');
        }
        // 更新時は既存レコードの所有者も検証
        if (!empty($_POST['id'])) {
            $existing = getDB()->prepare('SELECT employee_name FROM sales_transport_costs WHERE id = ? AND company_id = ?');
            $existing->execute([(int)$_POST['id'], $cid]);
            $owner = $existing->fetchColumn();
            if ($myName !== null && $owner !== false && $owner !== $myName) {
                http_response_code(403);
                die('他のユーザーのデータは編集できません');
            }
        }
        $_POST['target_year'] = $year;
        $_POST['target_month'] = $month;
        $_POST['total_amount'] = (int)($_POST['cost_1'] ?? 0) + (int)($_POST['cost_2'] ?? 0) + (int)($_POST['cost_3'] ?? 0) + (int)($_POST['highway_cost'] ?? 0);
        saveTransportCost($cid, $_POST);
        redirect(BASE_PATH . '/public/sales_transport.php?year='.$year.'&month='.$month.'&msg=saved');
    }
    if ($action === 'delete') {
        $delId = (int)$_POST['id'];
        if ($myName !== null) {
            $existing = getDB()->prepare('SELECT employee_name FROM sales_transport_costs WHERE id = ? AND company_id = ?');
            $existing->execute([$delId, $cid]);
            $owner = $existing->fetchColumn();
            if ($owner !== false && $owner !== $myName) {
                http_response_code(403);
                die('他のユーザーのデータは削除できません');
            }
        }
        deleteTransportCost($delId, $cid);
        redirect(BASE_PATH . '/public/sales_transport.php?year='.$year.'&month='.$month.'&msg=deleted');
    }
}

$empFilter = getEmployeeNameFilter();
$salesReps = getSalesReps($cid, $year);
$costs = getTransportCosts($cid, $year, $month, $empFilter);
$totalAll = array_sum(array_column($costs, 'total_amount'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-car-front me-2"></i>交通費管理</h1>
                <p><?= $year ?>年<?= $month ?>月　合計: <strong style="color:#059669"><?= number_format($totalAll) ?>円</strong></p>
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
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#costModal" onclick="clearModal()"><i class="bi bi-plus"></i> 交通費追加</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_GET['msg'] === 'saved' ? '保存しました' : '削除しました' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- 交通費提出フォーム -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-car-front me-1" style="color:#f59e0b"></i>交通費提出フォーム</span>
            <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#transportForm">
                <i class="bi bi-chevron-down"></i> 開く
            </button>
        </div>
        <div class="collapse" id="transportForm">
            <div class="card-body">
                <form id="transportSubmitForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h(getCsrfToken()) ?>">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">氏名 <span class="text-danger">*</span></label>
                            <?php if ($empFilter): ?>
                            <input type="text" class="form-control" name="employee_name" value="<?= h($empFilter) ?>" readonly>
                            <?php else: ?>
                            <select name="employee_name" class="form-select" required>
                                <option value="">選択してください</option>
                                <?php foreach ($salesReps as $rep): ?>
                                <option value="<?= h($rep) ?>"><?= h($rep) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">対象年月 <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <select name="target_year" class="form-select">
                                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                                    <?php endfor; ?>
                                </select>
                                <select name="target_month" class="form-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <h6 class="mb-3"><i class="bi bi-1-circle me-1"></i>交通費① <span class="text-danger">* 必須</span></h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">エビデンス（画像/PDF） <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="evidence_1" accept="image/*,.pdf" required>
                                <div class="form-text">JPEG/PNG/GIF/WebP/PDF（10MB以下）</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">片道距離(km)</label>
                                <input type="number" class="form-control tc-distance" name="distance_km_1" step="0.1" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">稼働日数 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control tc-days" name="work_days_1" min="1" max="31" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">金額（円）<span class="text-danger">*</span></label>
                                <input type="number" class="form-control tc-cost" name="cost_1" min="0" required>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <h6 class="mb-3"><i class="bi bi-2-circle me-1"></i>交通費②（任意）</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">エビデンス（画像/PDF）</label>
                                <input type="file" class="form-control" name="evidence_2" accept="image/*,.pdf">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">片道距離(km)</label>
                                <input type="number" class="form-control tc-distance" name="distance_km_2" step="0.1" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">稼働日数</label>
                                <input type="number" class="form-control tc-days" name="work_days_2" min="0" max="31">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">金額（円）</label>
                                <input type="number" class="form-control tc-cost" name="cost_2" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <h6 class="mb-3"><i class="bi bi-3-circle me-1"></i>交通費③（任意）</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">エビデンス（画像/PDF）</label>
                                <input type="file" class="form-control" name="evidence_3" accept="image/*,.pdf">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">片道距離(km)</label>
                                <input type="number" class="form-control tc-distance" name="distance_km_3" step="0.1" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">稼働日数</label>
                                <input type="number" class="form-control tc-days" name="work_days_3" min="0" max="31">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">金額（円）</label>
                                <input type="number" class="form-control tc-cost" name="cost_3" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">高速代（円）</label>
                            <input type="number" class="form-control tc-cost" name="highway_cost" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">合計金額</label>
                            <div class="input-group">
                                <input type="text" class="form-control fw-bold" id="tcTotalDisplay" readonly value="0">
                                <span class="input-group-text">円</span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning" id="transportSubmitBtn">
                            <i class="bi bi-send me-1"></i>交通費を提出
                        </button>
                        <div id="transportSubmitMsg" class="align-self-center"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.75rem">
                    <thead class="table-light">
                        <tr>
                            <th>社員名</th>
                            <th class="text-end">合計金額</th>
                            <th>区間①</th><th class="text-end">距離</th><th class="text-end">日数</th><th class="text-end">金額①</th>
                            <th>区間②</th><th class="text-end">距離</th><th class="text-end">日数</th><th class="text-end">金額②</th>
                            <th>区間③</th><th class="text-end">距離</th><th class="text-end">日数</th><th class="text-end">金額③</th>
                            <th class="text-end">高速代</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costs as $c): ?>
                        <tr>
                            <td class="fw-medium"><?= h($c['employee_name']) ?></td>
                            <td class="text-end fw-bold" style="color:#059669"><?= number_format($c['total_amount']) ?></td>
                            <td class="small"><?= h($c['evidence_url_1'] ?? '') ?></td>
                            <td class="text-end"><?= $c['distance_km_1'] ? $c['distance_km_1'].'km' : '-' ?></td>
                            <td class="text-end"><?= $c['work_days_1'] ?? '-' ?></td>
                            <td class="text-end"><?= $c['cost_1'] ? number_format($c['cost_1']) : '-' ?></td>
                            <td class="small"><?= h($c['evidence_url_2'] ?? '') ?></td>
                            <td class="text-end"><?= $c['distance_km_2'] ? $c['distance_km_2'].'km' : '-' ?></td>
                            <td class="text-end"><?= $c['work_days_2'] ?? '-' ?></td>
                            <td class="text-end"><?= $c['cost_2'] ? number_format($c['cost_2']) : '-' ?></td>
                            <td class="small"><?= h($c['evidence_url_3'] ?? '') ?></td>
                            <td class="text-end"><?= $c['distance_km_3'] ? $c['distance_km_3'].'km' : '-' ?></td>
                            <td class="text-end"><?= $c['work_days_3'] ?? '-' ?></td>
                            <td class="text-end"><?= $c['cost_3'] ? number_format($c['cost_3']) : '-' ?></td>
                            <td class="text-end"><?= $c['highway_cost'] ? number_format($c['highway_cost']) : '-' ?></td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:.65rem" onclick='editCost(<?= json_encode($c) ?>)'><i class="bi bi-pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.65rem"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($costs)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">交通費データがありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($costs)): ?>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td>合計</td>
                            <td class="text-end" style="color:#059669"><?= number_format($totalAll) ?></td>
                            <td colspan="14"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 交通費モーダル -->
<div class="modal fade" id="costModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="costId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-car-front me-1"></i>交通費入力</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">社員名</label>
                        <input type="text" name="employee_name" id="costEmp" class="form-control form-control-sm" required>
                    </div>
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                    <fieldset class="border rounded p-2 mb-3">
                        <legend class="small fw-bold w-auto px-2">区間<?= $i ?></legend>
                        <div class="row g-2">
                            <div class="col-md-3"><label class="form-label small">エビデンス</label><input type="text" name="evidence_url_<?= $i ?>" id="costEv<?= $i ?>" class="form-control form-control-sm" placeholder="経路名"></div>
                            <div class="col-md-3"><label class="form-label small">距離(km)</label><input type="number" name="distance_km_<?= $i ?>" id="costDist<?= $i ?>" class="form-control form-control-sm" step="0.1" min="0"></div>
                            <div class="col-md-3"><label class="form-label small">日数</label><input type="number" name="work_days_<?= $i ?>" id="costDays<?= $i ?>" class="form-control form-control-sm" min="0"></div>
                            <div class="col-md-3"><label class="form-label small">金額</label><input type="number" name="cost_<?= $i ?>" id="costAmt<?= $i ?>" class="form-control form-control-sm" min="0" onchange="calcTotal()"></div>
                        </div>
                    </fieldset>
                    <?php endfor; ?>
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label">高速代</label><input type="number" name="highway_cost" id="costHighway" class="form-control form-control-sm" min="0" value="0" onchange="calcTotal()"></div>
                        <div class="col-md-4"><label class="form-label">合計金額</label><input type="text" id="costTotal" class="form-control form-control-sm" readonly></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary btn-sm">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 交通費提出フォーム
document.querySelectorAll('.tc-cost').forEach(el => {
    el.addEventListener('input', function() {
        let total = 0;
        document.querySelectorAll('.tc-cost').forEach(c => { total += parseInt(c.value) || 0; });
        document.getElementById('tcTotalDisplay').value = total.toLocaleString();
    });
});

document.getElementById('transportSubmitForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('transportSubmitBtn');
    const msg = document.getElementById('transportSubmitMsg');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>送信中...';
    msg.innerHTML = '';

    const formData = new FormData(this);

    try {
        const res = await fetch((window.BMS_BASE_PATH || '') + '/public/api/sales_transport_submit.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok) {
            msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '（合計: ' + data.total_amount.toLocaleString() + '円）</span>';
            this.reset();
            document.getElementById('tcTotalDisplay').value = '0';
            const nameField = this.querySelector('[name="employee_name"]');
            if (nameField && nameField.hasAttribute('readonly')) nameField.value = nameField.defaultValue;
            // テーブルを更新するためリロード
            setTimeout(() => location.reload(), 1500);
        } else {
            msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + (data.error || '送信に失敗しました') + '</span>';
        }
    } catch (err) {
        msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>通信エラーが発生しました</span>';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send me-1"></i>交通費を提出';
});

function calcTotal() {
    const c1 = parseInt(document.getElementById('costAmt1').value) || 0;
    const c2 = parseInt(document.getElementById('costAmt2').value) || 0;
    const c3 = parseInt(document.getElementById('costAmt3').value) || 0;
    const hw = parseInt(document.getElementById('costHighway').value) || 0;
    document.getElementById('costTotal').value = (c1+c2+c3+hw).toLocaleString() + '円';
}
function clearModal() {
    document.getElementById('costId').value = '';
    document.getElementById('costEmp').value = '';
    for (let i = 1; i <= 3; i++) {
        document.getElementById('costEv'+i).value = '';
        document.getElementById('costDist'+i).value = '';
        document.getElementById('costDays'+i).value = '';
        document.getElementById('costAmt'+i).value = '';
    }
    document.getElementById('costHighway').value = '0';
    document.getElementById('costTotal').value = '';
}
function editCost(c) {
    document.getElementById('costId').value = c.id;
    document.getElementById('costEmp').value = c.employee_name;
    for (let i = 1; i <= 3; i++) {
        document.getElementById('costEv'+i).value = c['evidence_url_'+i] || '';
        document.getElementById('costDist'+i).value = c['distance_km_'+i] || '';
        document.getElementById('costDays'+i).value = c['work_days_'+i] || '';
        document.getElementById('costAmt'+i).value = c['cost_'+i] || '';
    }
    document.getElementById('costHighway').value = c.highway_cost || 0;
    calcTotal();
    new bootstrap.Modal(document.getElementById('costModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
