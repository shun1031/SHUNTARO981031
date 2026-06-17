<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$cid  = getCompanyId();
$db   = getDB();
$csrf = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $db->prepare('INSERT INTO training_rules (company_id, training_id, trigger_axis, trigger_item_keyword, trigger_condition, threshold_value, priority) VALUES (?,?,?,?,?,?,?)')
           ->execute([$cid, (int)$_POST['training_id'], $_POST['trigger_axis'], trim($_POST['trigger_item_keyword']??'') ?: null, $_POST['trigger_condition'] ?? 'below_threshold', (float)$_POST['threshold_value'], (int)$_POST['priority']]);
        $_SESSION['flash'] = 'ルールを追加しました';
    } elseif ($action === 'update') {
        $db->prepare('UPDATE training_rules SET training_id=?, trigger_axis=?, trigger_item_keyword=?, trigger_condition=?, threshold_value=?, priority=?, is_active=? WHERE id=? AND company_id=?')
           ->execute([(int)$_POST['training_id'], $_POST['trigger_axis'], trim($_POST['trigger_item_keyword']??'') ?: null, $_POST['trigger_condition'], (float)$_POST['threshold_value'], (int)$_POST['priority'], isset($_POST['is_active'])?1:0, (int)$_POST['id'], $cid]);
        $_SESSION['flash'] = '更新しました';
    } elseif ($action === 'delete') {
        $db->prepare('DELETE FROM training_rules WHERE id=? AND company_id=?')->execute([(int)$_POST['id'], $cid]);
        $_SESSION['flash'] = '削除しました';
    }
    header('Location: training_rules.php');
    exit;
}

$rules = getTrainingRules($cid);
$catalog = getTrainingCatalog($cid);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$axisLabels = ['performance'=>'業績','action'=>'行動','competency'=>'コンピテンシー'];
$condLabels = ['below_threshold'=>'閾値以下','low_achievement'=>'達成率低'];

$pageTitle = '研修推奨ルール';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価管理</a></li>
        <li class="breadcrumb-item"><a href="training_catalog.php">研修カタログ</a></li>
        <li class="breadcrumb-item active">推奨ルール</li>
    </ol>
</nav>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="alert alert-info small mb-3">
    <i class="bi bi-info-circle me-1"></i>評価スコアが閾値以下の場合に、対応する研修を自動推奨するルールを設定します。フィードバック完了時に自動判定されます。
</div>

<div class="row">
    <div class="col-lg-8">
        <h4 class="mb-3"><i class="bi bi-gear me-2"></i>推奨ルール</h4>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>研修</th><th>軸</th><th>キーワード</th><th>条件</th><th>閾値</th><th>優先度</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rules as $r): ?>
                        <tr>
                            <td><strong><?= h($r['training_name']) ?></strong></td>
                            <td><span class="badge bg-primary"><?= h($axisLabels[$r['trigger_axis']]??$r['trigger_axis']) ?></span></td>
                            <td><?= h($r['trigger_item_keyword'] ?? '-') ?></td>
                            <td><?= h($condLabels[$r['trigger_condition']]??$r['trigger_condition']) ?></td>
                            <td><?= h($r['threshold_value']) ?></td>
                            <td><?= h($r['priority']) ?></td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('削除？')">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rules): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">ルールが未設定です</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>新規ルール</div>
            <div class="card-body">
                <?php if (!$catalog): ?>
                <div class="alert alert-warning">先に<a href="training_catalog.php">研修カタログ</a>に研修を登録してください。</div>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2"><label class="form-label">研修 *</label><select name="training_id" class="form-select" required>
                        <?php foreach ($catalog as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="mb-2"><label class="form-label">評価軸</label><select name="trigger_axis" class="form-select">
                        <?php foreach ($axisLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="mb-2"><label class="form-label">キーワード</label><input name="trigger_item_keyword" class="form-control" placeholder="項目名に含まれる文字"></div>
                    <div class="mb-2"><label class="form-label">条件</label><select name="trigger_condition" class="form-select">
                        <?php foreach ($condLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="mb-2"><label class="form-label">閾値</label><input name="threshold_value" type="number" step="0.1" class="form-control" value="60"></div>
                    <div class="mb-3"><label class="form-label">優先度</label><input name="priority" type="number" class="form-control" value="0"></div>
                    <button type="submit" class="btn btn-primary w-100">追加</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
