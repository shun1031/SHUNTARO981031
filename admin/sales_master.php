<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = '売上マスタ管理';
$extraCss = ['sales.css'];
$db  = getDB();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/sales_dashboard.php'); }

$tab = $_GET['tab'] ?? 'clients';
$validTabs = ['clients', 'alliances', 'brands', 'areas', 'workers'];
if (!in_array($tab, $validTabs)) $tab = 'clients';

$msg = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['id'] ?? 0);

    switch ($tab) {
        case 'clients':
            if ($action === 'create') {
                createSalesClient($cid, $_POST);
                $msg = '取引先を追加しました';
            } elseif ($action === 'update' && $targetId) {
                updateSalesClient($targetId, $cid, $_POST);
                $msg = '取引先を更新しました';
            } elseif ($action === 'toggle' && $targetId) {
                $current = getSalesClient($targetId, $cid);
                if ($current) toggleSalesClient($targetId, $cid, !$current['is_active']);
                $msg = 'ステータスを変更しました';
            }
            break;
        case 'alliances':
            if ($action === 'create') {
                createSalesAlliance($cid, $_POST);
                $msg = '外注先を追加しました';
            } elseif ($action === 'update' && $targetId) {
                updateSalesAlliance($targetId, $cid, $_POST);
                $msg = '外注先を更新しました';
            } elseif ($action === 'toggle' && $targetId) {
                $current = getSalesAlliance($targetId, $cid);
                if ($current) toggleSalesAlliance($targetId, $cid, !$current['is_active']);
                $msg = 'ステータスを変更しました';
            }
            break;
        case 'brands':
            if ($action === 'create') {
                createSalesStoreBrand($cid, $_POST);
                $msg = '屋号を追加しました';
            } elseif ($action === 'update' && $targetId) {
                updateSalesStoreBrand($targetId, $cid, $_POST);
                $msg = '屋号を更新しました';
            } elseif ($action === 'toggle' && $targetId) {
                $current = getSalesStoreBrand($targetId, $cid);
                if ($current) toggleSalesStoreBrand($targetId, $cid, !$current['is_active']);
                $msg = 'ステータスを変更しました';
            }
            break;
        case 'areas':
            if ($action === 'create') {
                createSalesArea($cid, $_POST);
                $msg = 'エリアを追加しました';
            } elseif ($action === 'update' && $targetId) {
                updateSalesArea($targetId, $cid, $_POST);
                $msg = 'エリアを更新しました';
            } elseif ($action === 'toggle' && $targetId) {
                $current = getSalesArea($targetId, $cid);
                if ($current) toggleSalesArea($targetId, $cid, !$current['is_active']);
                $msg = 'ステータスを変更しました';
            }
            break;
        case 'workers':
            if ($action === 'create') {
                createSalesWorker($cid, $_POST);
                $msg = 'スタッフを追加しました';
            } elseif ($action === 'update' && $targetId) {
                updateSalesWorker($targetId, $cid, $_POST);
                $msg = 'スタッフを更新しました';
            } elseif ($action === 'toggle' && $targetId) {
                $current = getSalesWorker($targetId, $cid);
                if ($current) toggleSalesWorker($targetId, $cid, !$current['is_active']);
                $msg = 'ステータスを変更しました';
            }
            break;
    }
    if ($msg) {
        redirect(BASE_PATH . '/admin/sales_master.php?tab=' . $tab . '&msg=' . urlencode($msg));
    }
}

if (!empty($_GET['msg'])) $msg = $_GET['msg'];

// データ取得
$showInactive = !empty($_GET['show_inactive']);
$clients   = getSalesClients($cid, !$showInactive);
$alliances = getSalesAlliances($cid, !$showInactive);
$brands    = getSalesStoreBrands($cid, !$showInactive);
$areas     = getSalesAreas($cid, !$showInactive);
$workers   = getSalesWorkers($cid, null, !$showInactive);

$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-database me-2"></i>売上マスタ管理</h1>
                <p>取引先・外注先・屋号・エリア・スタッフの管理</p>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i><?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- タブ -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'clients' ? 'active' : '' ?>" href="?tab=clients">
                <i class="bi bi-building me-1"></i>取引先 <span class="badge bg-secondary ms-1"><?= count($clients) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'alliances' ? 'active' : '' ?>" href="?tab=alliances">
                <i class="bi bi-people me-1"></i>外注先 <span class="badge bg-secondary ms-1"><?= count($alliances) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'brands' ? 'active' : '' ?>" href="?tab=brands">
                <i class="bi bi-shop me-1"></i>屋号 <span class="badge bg-secondary ms-1"><?= count($brands) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'areas' ? 'active' : '' ?>" href="?tab=areas">
                <i class="bi bi-geo-alt me-1"></i>エリア <span class="badge bg-secondary ms-1"><?= count($areas) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'workers' ? 'active' : '' ?>" href="?tab=workers">
                <i class="bi bi-person-badge me-1"></i>スタッフ <span class="badge bg-secondary ms-1"><?= count($workers) ?></span>
            </a>
        </li>
    </ul>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="showInactive" <?= $showInactive ? 'checked' : '' ?>
                onchange="location.href='?tab=<?= $tab ?>' + (this.checked ? '&show_inactive=1' : '')">
            <label class="form-check-label small" for="showInactive">非アクティブも表示</label>
        </div>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-lg me-1"></i>新規追加
        </button>
    </div>

    <!-- 取引先タブ -->
    <?php if ($tab === 'clients'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>取引先名</th>
                        <th>コード</th>
                        <th>担当者</th>
                        <th>電話</th>
                        <th>順序</th>
                        <th class="text-center">状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $item): ?>
                    <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                        <td class="fw-medium"><?= h($item['client_name']) ?></td>
                        <td><span class="text-muted small"><?= h($item['client_code'] ?? '-') ?></span></td>
                        <td><?= h($item['contact_person'] ?? '') ?></td>
                        <td><?= h($item['phone'] ?? '') ?></td>
                        <td><?= $item['sort_order'] ?></td>
                        <td class="text-center">
                            <?php if ($item['is_active']): ?>
                            <span class="badge bg-success">有効</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">無効</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editClient(<?= json_encode($item) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('状態を変更しますか？')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>">
                                    <i class="bi bi-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 取引先 追加モーダル -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">取引先追加</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">取引先名 <span class="text-danger">*</span></label><input type="text" name="client_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">コード</label><input type="text" name="client_code" class="form-control"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">担当者</label><input type="text" name="contact_person" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">電話</label><input type="text" name="phone" class="form-control"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">備考</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" class="form-control" value="0"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">追加</button></div>
            </form>
        </div>
    </div>
    <!-- 取引先 編集モーダル -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">取引先編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">取引先名 <span class="text-danger">*</span></label><input type="text" name="client_name" id="edit_client_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">コード</label><input type="text" name="client_code" id="edit_client_code" class="form-control"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">担当者</label><input type="text" name="contact_person" id="edit_contact_person" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">電話</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">備考</label><textarea name="note" id="edit_note" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" id="edit_sort_order" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">更新</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- 外注先タブ -->
    <?php if ($tab === 'alliances'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>外注先名</th><th>種別</th><th>担当者</th><th>電話</th><th>順序</th><th class="text-center">状態</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($alliances as $item): ?>
                    <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                        <td class="fw-medium"><?= h($item['alliance_name']) ?></td>
                        <td><span class="tag"><?= h($item['alliance_type']) ?></span></td>
                        <td><?= h($item['contact_person'] ?? '') ?></td>
                        <td><?= h($item['phone'] ?? '') ?></td>
                        <td><?= $item['sort_order'] ?></td>
                        <td class="text-center"><?= $item['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editAlliance(<?= json_encode($item) ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('状態を変更しますか？')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>"><i class="bi bi-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">外注先追加</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">外注先名 <span class="text-danger">*</span></label><input type="text" name="alliance_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">種別</label><select name="alliance_type" class="form-select"><option value="アライアンス">アライアンス</option><option value="個人外注">個人外注</option></select></div>
                    <div class="row"><div class="col-md-6 mb-3"><label class="form-label">担当者</label><input type="text" name="contact_person" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label">電話</label><input type="text" name="phone" class="form-control"></div></div>
                    <div class="mb-3"><label class="form-label">備考</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" class="form-control" value="0"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">追加</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">外注先編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">外注先名 <span class="text-danger">*</span></label><input type="text" name="alliance_name" id="edit_alliance_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">種別</label><select name="alliance_type" id="edit_alliance_type" class="form-select"><option value="アライアンス">アライアンス</option><option value="個人外注">個人外注</option></select></div>
                    <div class="row"><div class="col-md-6 mb-3"><label class="form-label">担当者</label><input type="text" name="contact_person" id="edit_contact_person" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label">電話</label><input type="text" name="phone" id="edit_phone" class="form-control"></div></div>
                    <div class="mb-3"><label class="form-label">備考</label><textarea name="note" id="edit_note" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" id="edit_sort_order" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">更新</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- 屋号タブ -->
    <?php if ($tab === 'brands'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>屋号名</th><th>コード</th><th>順序</th><th class="text-center">状態</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($brands as $item): ?>
                    <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                        <td class="fw-medium"><?= h($item['brand_name']) ?></td>
                        <td><span class="tag"><?= h($item['brand_code'] ?? '-') ?></span></td>
                        <td><?= $item['sort_order'] ?></td>
                        <td class="text-center"><?= $item['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editBrand(<?= json_encode($item) ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('状態を変更しますか？')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>"><i class="bi bi-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">屋号追加</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">屋号名 <span class="text-danger">*</span></label><input type="text" name="brand_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">コード</label><input type="text" name="brand_code" class="form-control" maxlength="10" placeholder="例: SB, YM, ED"></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" class="form-control" value="0"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">追加</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">屋号編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">屋号名 <span class="text-danger">*</span></label><input type="text" name="brand_name" id="edit_brand_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">コード</label><input type="text" name="brand_code" id="edit_brand_code" class="form-control" maxlength="10"></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" id="edit_sort_order" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">更新</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- エリアタブ -->
    <?php if ($tab === 'areas'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>エリア名</th><th>地域</th><th>順序</th><th class="text-center">状態</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($areas as $item): ?>
                    <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                        <td class="fw-medium"><?= h($item['area_name']) ?></td>
                        <td><?= h($item['region'] ?? '') ?></td>
                        <td><?= $item['sort_order'] ?></td>
                        <td class="text-center"><?= $item['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editArea(<?= json_encode($item) ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('状態を変更しますか？')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>"><i class="bi bi-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">エリア追加</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">エリア名 <span class="text-danger">*</span></label><input type="text" name="area_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">地域</label><input type="text" name="region" class="form-control" placeholder="例: 名古屋, 三河, 岐阜"></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" class="form-control" value="0"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">追加</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">エリア編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">エリア名 <span class="text-danger">*</span></label><input type="text" name="area_name" id="edit_area_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">地域</label><input type="text" name="region" id="edit_region" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">表示順</label><input type="number" name="sort_order" id="edit_sort_order" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">更新</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- スタッフタブ -->
    <?php if ($tab === 'workers'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>スタッフ名</th><th>区分</th><th>外注先</th><th class="text-center">状態</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($workers as $item): ?>
                    <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                        <td class="fw-medium"><?= h($item['worker_name']) ?></td>
                        <td>
                            <?php
                            $wtColors = ['正社員'=>'primary','自社外注'=>'info','アライアンス'=>'success','個人外注'=>'warning','アルバイト'=>'secondary'];
                            $wtColor = $wtColors[$item['worker_type']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $wtColor ?>"><?= h($item['worker_type']) ?></span>
                        </td>
                        <td><?= h($item['alliance_name'] ?? '-') ?></td>
                        <td class="text-center"><?= $item['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editWorker(<?= json_encode($item) ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('状態を変更しますか？')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>"><i class="bi bi-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">スタッフ追加</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">スタッフ名 <span class="text-danger">*</span></label><input type="text" name="worker_name" class="form-control" required></div>
                    <div class="mb-3">
                        <label class="form-label">区分</label>
                        <select name="worker_type" class="form-select">
                            <option value="正社員">正社員</option><option value="自社外注">自社外注</option><option value="アライアンス">アライアンス</option><option value="個人外注">個人外注</option><option value="アルバイト">アルバイト</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">外注先</label>
                        <select name="alliance_id" class="form-select">
                            <option value="">-- なし --</option>
                            <?php foreach (getSalesAlliances($cid) as $al): ?>
                            <option value="<?= $al['id'] ?>"><?= h($al['alliance_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">社員紐付けID</label><input type="number" name="employee_id" class="form-control" placeholder="任意"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">追加</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5 class="modal-title">スタッフ編集</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">スタッフ名 <span class="text-danger">*</span></label><input type="text" name="worker_name" id="edit_worker_name" class="form-control" required></div>
                    <div class="mb-3">
                        <label class="form-label">区分</label>
                        <select name="worker_type" id="edit_worker_type" class="form-select">
                            <option value="正社員">正社員</option><option value="自社外注">自社外注</option><option value="アライアンス">アライアンス</option><option value="個人外注">個人外注</option><option value="アルバイト">アルバイト</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">外注先</label>
                        <select name="alliance_id" id="edit_alliance_id" class="form-select">
                            <option value="">-- なし --</option>
                            <?php foreach (getSalesAlliances($cid) as $al): ?>
                            <option value="<?= $al['id'] ?>"><?= h($al['alliance_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">社員紐付けID</label><input type="number" name="employee_id" id="edit_employee_id" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button><button type="submit" class="btn btn-primary">更新</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$inlineJs = <<<'JS'
function editClient(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_client_name').value = d.client_name;
    document.getElementById('edit_client_code').value = d.client_code || '';
    document.getElementById('edit_contact_person').value = d.contact_person || '';
    document.getElementById('edit_phone').value = d.phone || '';
    document.getElementById('edit_note').value = d.note || '';
    document.getElementById('edit_sort_order').value = d.sort_order;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function editAlliance(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_alliance_name').value = d.alliance_name;
    document.getElementById('edit_alliance_type').value = d.alliance_type;
    document.getElementById('edit_contact_person').value = d.contact_person || '';
    document.getElementById('edit_phone').value = d.phone || '';
    document.getElementById('edit_note').value = d.note || '';
    document.getElementById('edit_sort_order').value = d.sort_order;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function editBrand(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_brand_name').value = d.brand_name;
    document.getElementById('edit_brand_code').value = d.brand_code || '';
    document.getElementById('edit_sort_order').value = d.sort_order;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function editArea(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_area_name').value = d.area_name;
    document.getElementById('edit_region').value = d.region || '';
    document.getElementById('edit_sort_order').value = d.sort_order;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function editWorker(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_worker_name').value = d.worker_name;
    document.getElementById('edit_worker_type').value = d.worker_type;
    document.getElementById('edit_alliance_id').value = d.alliance_id || '';
    document.getElementById('edit_employee_id').value = d.employee_id || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
