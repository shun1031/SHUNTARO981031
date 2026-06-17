<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = 'チーム管理';
$db        = getDB();
$cid       = getCompanyId();
$csrf      = getCsrfToken();

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) die('不正なリクエストです');

    $action = $_POST['action'] ?? '';

    if ($action === 'save_team') {
        $fields = [
            'name'             => trim($_POST['name'] ?? ''),
            'description'      => trim($_POST['description'] ?? ''),
            'manager_id'       => $_POST['manager_id'] ?: null,
            'sub_manager_id'   => $_POST['sub_manager_id'] ?: null,
            'team_strengths'   => trim($_POST['team_strengths'] ?? ''),
            'team_challenges'  => trim($_POST['team_challenges'] ?? ''),
            'management_points'=> trim($_POST['management_points'] ?? ''),
            'ideal_recruit'    => trim($_POST['ideal_recruit'] ?? ''),
            'strengths_analysis'=> trim($_POST['strengths_analysis'] ?? ''),
            'sort_order'       => (int)($_POST['sort_order'] ?? 0),
        ];

        $tid = (int)($_POST['team_id'] ?? 0);
        if ($tid) {
            $setClauses = implode(',', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $sql = "UPDATE teams SET $setClauses WHERE id = ?";
            $params = [...array_values($fields), $tid];
            if ($cid) { $sql .= ' AND company_id = ?'; $params[] = $cid; }
            $db->prepare($sql)->execute($params);
        } else {
            $fields['company_id'] = $cid;
            $cols   = implode(',', array_keys($fields));
            $places = implode(',', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO teams ($cols) VALUES ($places)")->execute(array_values($fields));
            $tid = (int)$db->lastInsertId();
        }

        // メンバー設定
        $memberIds = array_filter(array_map('intval', $_POST['member_ids'] ?? []));
        $db->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$tid]);
        foreach ($memberIds as $mid) {
            $db->prepare('INSERT IGNORE INTO team_members (team_id, employee_id) VALUES (?,?)')->execute([$tid, $mid]);
        }

        redirect(BASE_PATH . '/admin/teams.php?saved=1');
    }

    if ($action === 'delete_team' && !empty($_POST['team_id'])) {
        $delSql = 'DELETE FROM teams WHERE id = ?';
        $delParams = [(int)$_POST['team_id']];
        if ($cid) { $delSql .= ' AND company_id = ?'; $delParams[] = $cid; }
        $db->prepare($delSql)->execute($delParams);
        redirect(BASE_PATH . '/admin/teams.php?deleted=1');
    }
}

$teams    = getAllTeams($cid);
$allEmps  = getAllEmployees(true, $cid);
$editTeam = null;
$teamMembers = [];
if (!empty($_GET['edit'])) {
    $editTeam = getTeam((int)$_GET['edit'], $cid);
    $teamMembers = array_column(getTeamMembers((int)$_GET['edit']), 'id');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-diagram-3 me-2"></i>チーム管理</h1>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success alert-dismissible">保存しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible">チームを削除しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- チーム一覧 -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span>チーム一覧</span>
                    <a href="?edit=new" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus me-1"></i>新規チーム
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($teams as $team): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div>
                            <div class="fw-semibold"><?= h($team['name']) ?></div>
                            <div class="text-muted small">
                                <?= $team['member_count'] ?>名
                                <?php if ($team['manager_name']): ?>
                                / <?= h($team['manager_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-1">
                            <a href="?edit=<?= $team['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_team">
                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('チームを削除しますか？メンバーの所属情報も削除されます。')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 編集フォーム -->
        <?php if (isset($_GET['edit'])): ?>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pencil me-2"></i>
                    <?= $editTeam ? h($editTeam['name']) . ' を編集' : '新規チーム作成' ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="save_team">
                        <input type="hidden" name="team_id" value="<?= $editTeam ? $editTeam['id'] : 0 ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">チーム名 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= h($editTeam['name'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">説明</label>
                            <textarea name="description" class="form-control" rows="2"><?= h($editTeam['description'] ?? '') ?></textarea>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">マネージャー</label>
                                <select name="manager_id" class="form-select form-select-sm">
                                    <option value="">なし</option>
                                    <?php foreach ($allEmps as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($editTeam['manager_id'] ?? null) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= h($emp['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">サブマネージャー</label>
                                <select name="sub_manager_id" class="form-select form-select-sm">
                                    <option value="">なし</option>
                                    <?php foreach ($allEmps as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($editTeam['sub_manager_id'] ?? null) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= h($emp['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- メンバー選択 -->
                        <div class="mb-3">
                            <label class="form-label">メンバー</label>
                            <div style="max-height:200px;overflow-y:auto;border:1px solid #dee2e6;border-radius:8px;padding:8px">
                                <?php foreach ($allEmps as $emp): ?>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="member_ids[]"
                                           id="mem_<?= $emp['id'] ?>" value="<?= $emp['id'] ?>"
                                           <?= in_array($emp['id'], $teamMembers) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="mem_<?= $emp['id'] ?>"><?= h($emp['name']) ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">チームの強み</label>
                            <textarea name="team_strengths" id="fld_strengths" class="form-control" rows="3"><?= h($editTeam['team_strengths'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">チームの課題</label>
                            <textarea name="team_challenges" id="fld_challenges" class="form-control" rows="3"><?= h($editTeam['team_challenges'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">マネジメントの要点</label>
                            <textarea name="management_points" id="fld_management" class="form-control" rows="3"><?= h($editTeam['management_points'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">このチームに合う人</label>
                            <textarea name="ideal_recruit" id="fld_recruit" class="form-control" rows="3"><?= h($editTeam['ideal_recruit'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">SF総合分析</label>
                            <textarea name="strengths_analysis" id="fld_analysis" class="form-control" rows="4"><?= h($editTeam['strengths_analysis'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">表示順</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm" style="width:80px"
                                   value="<?= h($editTeam['sort_order'] ?? 0) ?>">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>保存する
                            </button>
                            <a href="teams.php" class="btn btn-secondary">キャンセル</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
