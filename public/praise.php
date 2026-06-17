<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();
$csrf = getCsrfToken();
$myEmpId = $_SESSION['employee_id'] ?? 0;

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $empId = (int)$_POST['employee_id'];
    $memo  = trim($_POST['memo'] ?? '');
    $cat   = $_POST['category'] ?? 'other';
    $date  = $_POST['praised_date'] ?? date('Y-m-d');
    // author_id: 社員IDがなければuser_idで代替
    $authorId = $myEmpId ?: ($_SESSION['user_id'] ?? 0);
    if ($empId && $memo && $authorId) {
        $stmt = $db->prepare('INSERT INTO praise_points (company_id, employee_id, author_id, memo, category, praised_date) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$cid, $empId, $authorId, mb_substr($memo, 0, 500), $cat, $date]);
        $_SESSION['flash'] = '褒めポイントを記録しました！';
    } elseif (!$authorId) {
        $_SESSION['flash_error'] = 'ログインユーザー情報の取得に失敗しました';
    }
    header('Location: praise.php');
    exit;
}

$employees = getAllEmployees(true, $cid);
$categories = getPraiseCategories();
$recent = getRecentPraise($cid, 30);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$pageTitle = '褒めポイント';
$extraCss = ['eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><i class="bi bi-star me-2"></i>褒めポイント</h4>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php $flashError = $_SESSION['flash_error'] ?? ''; unset($_SESSION['flash_error']); ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row">
    <!-- 入力フォーム -->
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header bg-warning text-dark"><i class="bi bi-plus-circle me-2"></i>新しい褒めポイント</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label">社員 <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">選択してください</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['department'] ?? '') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">カテゴリ</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($categories as $key => $cat): ?>
                            <div>
                                <input type="radio" name="category" id="cat_<?= $key ?>" value="<?= $key ?>" class="btn-check" <?= $key === 'other' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-secondary btn-sm" for="cat_<?= $key ?>">
                                    <i class="bi <?= $cat['icon'] ?> me-1"></i><?= $cat['label'] ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">メモ <span class="text-danger">*</span></label>
                        <textarea name="memo" class="form-control" rows="3" maxlength="500" required placeholder="良かった行動を具体的に記入..." id="praiseMemo"></textarea>
                        <div class="form-text text-end"><span id="memoCount">0</span>/500</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">日付</label>
                        <input type="date" name="praised_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <button type="submit" class="btn btn-warning w-100"><i class="bi bi-star me-1"></i>記録する</button>
                </form>
            </div>
        </div>
    </div>

    <!-- タイムライン -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">最近の褒めポイント</div>
            <div class="card-body praise-timeline">
                <?php if (!$recent): ?>
                <p class="text-muted text-center py-4">まだ褒めポイントがありません</p>
                <?php endif; ?>
                <?php foreach ($recent as $p):
                    $cat = $categories[$p['category']] ?? $categories['other'];
                ?>
                <div class="praise-card" style="border-left-color:<?= $cat['color'] ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge" style="background:<?= $cat['color'] ?>">
                                <i class="bi <?= $cat['icon'] ?> me-1"></i><?= $cat['label'] ?>
                            </span>
                            <strong class="ms-2"><?= h($p['employee_name']) ?></strong>
                        </div>
                        <small class="text-muted"><?= h($p['praised_date']) ?></small>
                    </div>
                    <div class="mt-2"><?= nl2br(h($p['memo'])) ?></div>
                    <div class="text-muted small mt-1">by <?= h($p['author_name']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('praiseMemo')?.addEventListener('input', function() {
    document.getElementById('memoCount').textContent = this.value.length;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
