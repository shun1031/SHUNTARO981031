<?php
/**
 * 給与体系管理（ラダー別給与テーブル）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$db   = getDB();
$csrf = getCsrfToken();

$cid = getCompanyId();
if (!$cid && isSuperAdmin()) {
    $companies = $db->query('SELECT id, company_name AS name FROM companies WHERE is_active = 1 ORDER BY company_name')->fetchAll();
    if (!empty($_GET['company_id'])) $cid = (int)$_GET['company_id'];
    elseif (count($companies) === 1) $cid = $companies[0]['id'];
}
if (!$cid) {
    $pageTitle = '給与体系';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-4"><div class="alert alert-info">会社を選択してください。</div><div class="list-group">';
    foreach ($companies ?? [] as $c) { echo '<a href="?company_id=' . $c['id'] . '" class="list-group-item list-group-item-action">' . h($c['name']) . '</a>'; }
    echo '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$error = '';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $dept = trim($_POST['department_key'] ?? '');
        $ladder = trim($_POST['ladder_name'] ?? '');
        $grade = trim($_POST['grade_name'] ?? '');
        $level = (int)$_POST['grade_level'];
        $threshold = (int)$_POST['sales_threshold'];
        $salary = (int)$_POST['salary'];
        $color = trim($_POST['grade_color'] ?? '#C0C0C0');
        $sort = (int)$_POST['sort_order'];

        if ($dept && $ladder && $grade && $salary > 0) {
            $stmt = $db->prepare('INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary), grade_color=VALUES(grade_color), sort_order=VALUES(sort_order)');
            $stmt->execute([$cid, $dept, $ladder, $grade, $level, $threshold, $salary, $color, $sort]);
            $_SESSION['flash'] = '給与ランクを追加しました';
        } else {
            $error = '必須項目を入力してください';
        }
        if (!$error) { header('Location: salary_table.php' . ($cid && isSuperAdmin() ? '?company_id=' . $cid : '')); exit; }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM salary_ladder WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
        $_SESSION['flash'] = '削除しました';
        header('Location: salary_table.php' . ($cid && isSuperAdmin() ? '?company_id=' . $cid : '')); exit;
    }

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $threshold = (int)$_POST['sales_threshold'];
        $salary = (int)$_POST['salary'];
        $color = trim($_POST['grade_color'] ?? '#C0C0C0');
        $sort = (int)$_POST['sort_order'];
        $db->prepare('UPDATE salary_ladder SET sales_threshold=?, salary=?, grade_color=?, sort_order=? WHERE id=? AND company_id=?')
           ->execute([$threshold, $salary, $color, $sort, $id, $cid]);
        $_SESSION['flash'] = '更新しました';
        header('Location: salary_table.php' . ($cid && isSuperAdmin() ? '?company_id=' . $cid : '')); exit;
    }
}

// データ取得
$ladders = $db->prepare('SELECT * FROM salary_ladder WHERE company_id = ? ORDER BY department_key, ladder_name, sort_order');
$ladders->execute([$cid]);
$allRows = $ladders->fetchAll();

// 事業部×ラダーでグループ化
$grouped = [];
foreach ($allRows as $r) {
    $key = $r['department_key'] . '|' . $r['ladder_name'];
    $grouped[$key][] = $r;
}

// 会社名取得
$companyName = $db->prepare('SELECT company_name FROM companies WHERE id = ?');
$companyName->execute([$cid]);
$companyName = $companyName->fetchColumn();

$pageTitle = '給与体系';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">管理</a></li>
        <li class="breadcrumb-item active">給与体系</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-table me-2"></i>給与体系 — <?= h($companyName) ?></h4>
</div>

<?php if (empty($grouped)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>給与体系が未登録です。右側のフォームから追加してください。
</div>
<?php endif; ?>

<div class="row">
    <!-- 給与テーブル表示 -->
    <div class="col-lg-8 mb-4">
        <?php foreach ($grouped as $key => $rows):
            [$dept, $ladder] = explode('|', $key);
        ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <strong><?= h($dept) ?></strong>
                    <span class="badge bg-secondary ms-2"><?= h($ladder) ?></span>
                </span>
                <span class="badge bg-info"><?= count($rows) ?>段階</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>等級</th>
                            <th class="text-end">月売上</th>
                            <th class="text-end">月給</th>
                            <th class="text-center" style="width:100px">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $gradeStyle = 'background:' . h($r['grade_color']) . ';color:#fff;font-weight:bold;padding:2px 10px;border-radius:12px;font-size:0.8rem';
                        ?>
                        <tr>
                            <td>
                                <span style="<?= $gradeStyle ?>"><?= h($r['grade_name']) ?></span>
                                <?php if ($r['grade_level'] > 1): ?>
                                <small class="text-muted ms-1">Lv<?= $r['grade_level'] ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold">
                                <?php if ($r['sales_threshold'] > 0): ?>
                                ¥<?= number_format($r['sales_threshold']) ?>
                                <?php else: ?>
                                <span class="text-muted">〜</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <span class="fw-bold text-success">¥<?= number_format($r['salary']) ?></span>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $r['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>

                        <!-- 編集モーダル -->
                        <div class="modal fade" id="editModal<?= $r['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-sm">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <div class="modal-header"><h6 class="modal-title"><?= h($r['grade_name']) ?> Lv<?= $r['grade_level'] ?> 編集</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">月売上閾値（円）</label>
                                                <input type="number" name="sales_threshold" class="form-control" value="<?= $r['sales_threshold'] ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">月給（円）</label>
                                                <input type="number" name="salary" class="form-control" value="<?= $r['salary'] ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">等級カラー</label>
                                                <input type="color" name="grade_color" class="form-control form-control-color" value="<?= h($r['grade_color']) ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">並び順</label>
                                                <input type="number" name="sort_order" class="form-control" value="<?= $r['sort_order'] ?>">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                                            <button type="submit" class="btn btn-primary btn-sm">更新</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 新規追加フォーム -->
    <div class="col-lg-4">
        <div class="card sticky-top" style="top:80px">
            <div class="card-header bg-success text-white"><i class="bi bi-plus-circle me-2"></i>新規ランク追加</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">事業部 <span class="text-danger">*</span></label>
                        <input type="text" name="department_key" class="form-control" required placeholder="例: LiberTeen" list="deptList">
                        <datalist id="deptList">
                            <?php foreach (array_unique(array_column($allRows, 'department_key')) as $d): ?>
                            <option value="<?= h($d) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">ラダー名 <span class="text-danger">*</span></label>
                        <input type="text" name="ladder_name" class="form-control" required placeholder="例: ショップラダー" list="ladderList">
                        <datalist id="ladderList">
                            <?php foreach (array_unique(array_column($allRows, 'ladder_name')) as $l): ?>
                            <option value="<?= h($l) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">等級名 <span class="text-danger">*</span></label>
                            <select name="grade_name" class="form-select" required>
                                <option value="ゴールド">ゴールド</option>
                                <option value="シルバー" selected>シルバー</option>
                                <option value="ブロンズ">ブロンズ</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">段階</label>
                            <input type="number" name="grade_level" class="form-control" value="1" min="1" max="10">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">月売上閾値（円）</label>
                        <input type="number" name="sales_threshold" class="form-control" value="0" min="0" step="10000">
                        <div class="form-text">最低ランクは0で可</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">月給（円） <span class="text-danger">*</span></label>
                        <input type="number" name="salary" class="form-control" required min="0" step="10000" placeholder="200000">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">等級カラー</label>
                            <input type="color" name="grade_color" class="form-control form-control-color" value="#C0C0C0">
                        </div>
                        <div class="col-6">
                            <label class="form-label">並び順</label>
                            <input type="number" name="sort_order" class="form-control" value="0">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-circle me-1"></i>追加</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
