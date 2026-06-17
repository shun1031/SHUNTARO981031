<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$cid  = getCompanyId();
$db   = getDB();
$csrf = getCsrfToken();

$categories = ['sales'=>'営業スキル','process'=>'業務プロセス','competency'=>'コンピテンシー','general'=>'全般'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $db->prepare('INSERT INTO training_catalog (company_id, name, description, category, duration_hours, url) VALUES (?,?,?,?,?,?)')
           ->execute([$cid, trim($_POST['name']), trim($_POST['description'] ?? ''), $_POST['category'] ?? 'general', (float)$_POST['duration_hours'] ?: null, trim($_POST['url'] ?? '') ?: null]);
        $_SESSION['flash'] = '研修を追加しました';
    } elseif ($action === 'update') {
        $db->prepare('UPDATE training_catalog SET name=?, description=?, category=?, duration_hours=?, url=?, is_active=? WHERE id=? AND company_id=?')
           ->execute([trim($_POST['name']), trim($_POST['description'] ?? ''), $_POST['category'] ?? 'general', (float)$_POST['duration_hours'] ?: null, trim($_POST['url'] ?? '') ?: null, isset($_POST['is_active'])?1:0, (int)$_POST['id'], $cid]);
        $_SESSION['flash'] = '更新しました';
    } elseif ($action === 'delete') {
        $db->prepare('DELETE FROM training_catalog WHERE id=? AND company_id=?')->execute([(int)$_POST['id'], $cid]);
        $_SESSION['flash'] = '削除しました';
    }
    header('Location: training_catalog.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM training_catalog WHERE company_id = ? ORDER BY category, name');
$stmt->execute([$cid]);
$trainings = $stmt->fetchAll();
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$pageTitle = '研修カタログ';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価管理</a></li>
        <li class="breadcrumb-item active">研修カタログ</li>
    </ol>
</nav>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <h4 class="mb-3"><i class="bi bi-mortarboard me-2"></i>研修カタログ</h4>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>研修名</th><th>カテゴリ</th><th>時間</th><th>状態</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($trainings as $t):
                            $catLabel = $categories[$t['category']] ?? $t['category'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= h($t['name']) ?></strong>
                                <?php if ($t['url']): ?><a href="<?= h($t['url']) ?>" target="_blank" class="ms-1"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
                                <?php if ($t['description']): ?><div class="text-muted small"><?= h(mb_substr($t['description'],0,60)) ?></div><?php endif; ?>
                            </td>
                            <td><span class="badge bg-info"><?= h($catLabel) ?></span></td>
                            <td><?= $t['duration_hours'] ? h($t['duration_hours']).'h' : '-' ?></td>
                            <td><?= $t['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal"
                                    data-id="<?= $t['id'] ?>" data-name="<?= h($t['name']) ?>" data-description="<?= h($t['description']??'') ?>"
                                    data-category="<?= h($t['category']) ?>" data-duration="<?= h($t['duration_hours']??'') ?>"
                                    data-url="<?= h($t['url']??'') ?>" data-active="<?= $t['is_active'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$trainings): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">研修が登録されていません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>新規研修</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2"><label class="form-label">研修名 *</label><input name="name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">カテゴリ</label><select name="category" class="form-select">
                        <?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="mb-2"><label class="form-label">説明</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <div class="mb-2"><label class="form-label">時間(h)</label><input name="duration_hours" type="number" step="0.5" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">URL</label><input name="url" type="url" class="form-control"></div>
                    <button type="submit" class="btn btn-primary w-100">追加</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="eId">
        <div class="modal-header"><h5 class="modal-title">研修編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label">研修名</label><input name="name" id="eName" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">カテゴリ</label><select name="category" id="eCat" class="form-select">
                <?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
            <div class="mb-2"><label class="form-label">説明</label><textarea name="description" id="eDesc" class="form-control" rows="2"></textarea></div>
            <div class="mb-2"><label class="form-label">時間</label><input name="duration_hours" id="eDur" type="number" step="0.5" class="form-control"></div>
            <div class="mb-2"><label class="form-label">URL</label><input name="url" id="eUrl" type="url" class="form-control"></div>
            <div class="form-check"><input type="checkbox" name="is_active" id="eActive" class="form-check-input" value="1"><label class="form-check-label" for="eActive">有効</label></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">保存</button></div>
    </form>
</div></div></div>

<script>
document.getElementById('editModal')?.addEventListener('show.bs.modal', function(e) {
    var b = e.relatedTarget;
    document.getElementById('eId').value = b.dataset.id;
    document.getElementById('eName').value = b.dataset.name;
    document.getElementById('eDesc').value = b.dataset.description;
    document.getElementById('eCat').value = b.dataset.category;
    document.getElementById('eDur').value = b.dataset.duration;
    document.getElementById('eUrl').value = b.dataset.url;
    document.getElementById('eActive').checked = b.dataset.active === '1';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
