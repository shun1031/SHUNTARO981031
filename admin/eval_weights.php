<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$error = '';
$success = '';

// === POST処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $deptKey   = trim($_POST['department_key'] ?? '');
        $deptLabel = trim($_POST['department_label'] ?? '');
        $wp        = (int)($_POST['weight_performance'] ?? 0);
        $wa        = (int)($_POST['weight_action'] ?? 0);
        $wc        = (int)($_POST['weight_competency'] ?? 0);

        if (!$deptKey || !$deptLabel) {
            $error = '部署キーと部署名は必須です';
        } elseif (($wp + $wa + $wc) !== 100) {
            $error = 'ウェイトの合計は100にしてください（現在: ' . ($wp + $wa + $wc) . '）';
        } else {
            $existing = $db->prepare('SELECT id FROM eval_axis_weights WHERE company_id = ? AND department_key = ?');
            $existing->execute([$cid, $deptKey]);
            if ($existing->fetch()) {
                $error = 'この部署キーは既に登録されています';
            } else {
                $db->prepare(
                    'INSERT INTO eval_axis_weights (company_id, department_key, department_label, weight_performance, weight_action, weight_competency)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$cid, $deptKey, $deptLabel, $wp, $wa, $wc]);
                $success = 'ウェイト設定を追加しました';
            }
        }
    }

    if ($action === 'update') {
        $id        = (int)($_POST['weight_id'] ?? 0);
        $deptLabel = trim($_POST['department_label'] ?? '');
        $wp        = (int)($_POST['weight_performance'] ?? 0);
        $wa        = (int)($_POST['weight_action'] ?? 0);
        $wc        = (int)($_POST['weight_competency'] ?? 0);

        if (($wp + $wa + $wc) !== 100) {
            $error = 'ウェイトの合計は100にしてください（現在: ' . ($wp + $wa + $wc) . '）';
        } elseif ($id && $deptLabel) {
            $db->prepare(
                'UPDATE eval_axis_weights SET department_label = ?, weight_performance = ?, weight_action = ?, weight_competency = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([$deptLabel, $wp, $wa, $wc, $id, $cid]);
            $success = '更新しました';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['weight_id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM eval_axis_weights WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
            $success = '削除しました';
        }
    }

    if ($action === 'seed_defaults') {
        $defaults = [
            ['sales',        '営業部',     60, 30, 10],
            ['inside_sales', 'IS部',       40, 40, 20],
            ['admin',        '管理部',     10, 40, 50],
        ];
        $inserted = 0;
        foreach ($defaults as $d) {
            $dup = $db->prepare('SELECT id FROM eval_axis_weights WHERE company_id = ? AND department_key = ?');
            $dup->execute([$cid, $d[0]]);
            if (!$dup->fetch()) {
                $db->prepare(
                    'INSERT INTO eval_axis_weights (company_id, department_key, department_label, weight_performance, weight_action, weight_competency)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$cid, $d[0], $d[1], $d[2], $d[3], $d[4]]);
                $inserted++;
            }
        }
        $success = "デフォルト設定を{$inserted}件追加しました";
    }
}

// === データ取得 ===
$weights = getAxisWeights($cid);

$pageTitle = '評価ウェイト設定';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item active">評価ウェイト設定</li>
        </ol>
    </nav>

    <div class="page-header">
        <h1><i class="bi bi-sliders me-2"></i>評価ウェイト設定</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ウェイト一覧 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>部署別ウェイト一覧</span>
                    <?php if (empty($weights)): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="seed_defaults">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-magic me-1"></i>デフォルト設定を追加
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>部署キー</th>
                                <th>部署名</th>
                                <th class="text-center">業績%</th>
                                <th class="text-center">行動%</th>
                                <th class="text-center">コンピテンシー%</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($weights as $w): ?>
                            <tr>
                                <td><code><?= h($w['department_key']) ?></code></td>
                                <td><?= h($w['department_label']) ?></td>
                                <td class="text-center"><?= h($w['weight_performance']) ?>%</td>
                                <td class="text-center"><?= h($w['weight_action']) ?>%</td>
                                <td class="text-center"><?= h($w['weight_competency']) ?>%</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $w['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- 編集モーダル -->
                            <div class="modal fade" id="editModal<?= $w['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="weight_id" value="<?= $w['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">ウェイト編集: <?= h($w['department_label']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">部署キー</label>
                                                    <input type="text" class="form-control" value="<?= h($w['department_key']) ?>" disabled>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">部署名 <span class="text-danger">*</span></label>
                                                    <input type="text" name="department_label" class="form-control" value="<?= h($w['department_label']) ?>" required>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-4">
                                                        <label class="form-label">業績% <span class="text-danger">*</span></label>
                                                        <input type="number" name="weight_performance" class="form-control" value="<?= h($w['weight_performance']) ?>" min="0" max="100" required>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">行動% <span class="text-danger">*</span></label>
                                                        <input type="number" name="weight_action" class="form-control" value="<?= h($w['weight_action']) ?>" min="0" max="100" required>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">コンピテンシー% <span class="text-danger">*</span></label>
                                                        <input type="number" name="weight_competency" class="form-control" value="<?= h($w['weight_competency']) ?>" min="0" max="100" required>
                                                    </div>
                                                </div>
                                                <div class="form-text mt-2">合計が100になるように入力してください</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" name="action" value="delete" class="btn btn-danger me-auto" onclick="return confirm('このウェイト設定を削除しますか？')">
                                                    <i class="bi bi-trash me-1"></i>削除
                                                </button>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                                <button type="submit" class="btn btn-primary">保存</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($weights)): ?>
                <div class="card-body text-center text-muted">ウェイト設定がまだ登録されていません</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 新規追加 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>新規ウェイト追加</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">部署キー <span class="text-danger">*</span></label>
                            <input type="text" name="department_key" class="form-control" required
                                   pattern="[a-zA-Z0-9_]+" title="英数字・アンダースコアのみ"
                                   placeholder="例: sales">
                            <div class="form-text">英数字・アンダースコアのみ</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">部署名 <span class="text-danger">*</span></label>
                            <input type="text" name="department_label" class="form-control" required placeholder="例: 営業部">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-4">
                                <label class="form-label fw-semibold">業績%</label>
                                <input type="number" name="weight_performance" class="form-control" value="40" min="0" max="100" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">行動%</label>
                                <input type="number" name="weight_action" class="form-control" value="40" min="0" max="100" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">コンピテンシー%</label>
                                <input type="number" name="weight_competency" class="form-control" value="20" min="0" max="100" required>
                            </div>
                        </div>
                        <div class="form-text mb-3">合計が100になるように入力してください</div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-sliders me-1"></i>追加する
                        </button>
                    </form>
                </div>
            </div>

            <?php if (!empty($weights)): ?>
            <div class="card mt-3">
                <div class="card-header">デフォルト設定</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="seed_defaults">
                        <p class="text-muted small">営業(60/30/10)、IS(40/40/20)、管理(10/40/50)のデフォルト設定を追加します。既存のキーはスキップされます。</p>
                        <button type="submit" class="btn btn-outline-success w-100">
                            <i class="bi bi-magic me-1"></i>デフォルト設定を追加
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
