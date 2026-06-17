<?php
/**
 * 1on1・面談メモ管理画面
 * - 面談記録の作成・閲覧
 * - 構造化された面談テンプレート（ポジティブFB / 課題 / キャリア）
 * - スマホから1行メモ入力対応
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();
$csrf = getCsrfToken();
$myEmpId = $_SESSION['employee_id'] ?? 0;
$user = getCurrentUser();
$isAdmin = in_array($user['role'] ?? '', ['super_admin','company_admin']);

/**
 * 権限チェック: 一般社員は自分の面談メモのみ閲覧/作成可能
 * 管理者は全員の面談メモを扱える
 */
function canAccessInterviewTarget(int $targetEmpId, int $myEmpId, bool $isAdmin): bool {
    if ($isAdmin) return true;
    return $targetEmpId === $myEmpId;
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $empId = (int)$_POST['employee_id'];
        if (!canAccessInterviewTarget($empId, $myEmpId, $isAdmin)) {
            http_response_code(403);
            die('他のユーザーの面談メモは作成できません');
        }
        $stmt = $db->prepare(
            'INSERT INTO interview_notes (company_id, employee_id, interviewer_id, note_type, interview_date, positives, challenges, challenge_causes, action_plan, career_aspiration, manager_memo, mood, period_id, is_private)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $periodStmt = $db->prepare("SELECT id FROM eval_periods WHERE company_id = ? AND status NOT IN ('draft','closed') ORDER BY start_date DESC LIMIT 1");
        $periodStmt->execute([$cid]);
        $curPeriod = $periodStmt->fetch();

        // interviewer_id: 社員IDがなければuser_idで代替
        $interviewerId = $myEmpId ?: ($_SESSION['user_id'] ?? 0);
        $stmt->execute([
            $cid, $empId, $interviewerId,
            $_POST['note_type'] ?? 'one_on_one',
            $_POST['interview_date'] ?? date('Y-m-d'),
            trim($_POST['positives'] ?? ''),
            trim($_POST['challenges'] ?? ''),
            trim($_POST['challenge_causes'] ?? ''),
            trim($_POST['action_plan'] ?? ''),
            trim($_POST['career_aspiration'] ?? ''),
            trim($_POST['manager_memo'] ?? ''),
            (int)($_POST['mood'] ?? 3) ?: null,
            $curPeriod ? $curPeriod['id'] : null,
            isset($_POST['is_private']) ? 1 : 0,
        ]);

        // 研修割当
        if (!empty($_POST['training_ids'])) {
            foreach ($_POST['training_ids'] as $tid) {
                $tid = (int)$tid;
                if ($tid) {
                    assignTraining($cid, $empId, $tid, $myEmpId, '面談での課題に基づく割当', (int)$db->lastInsertId(), null);
                }
            }
        }

        $_SESSION['flash'] = '面談メモを保存しました';
        header('Location: interview_notes.php?employee_id=' . $empId);
        exit;
    } elseif ($action === 'delete' && $isAdmin) {
        $noteId = (int)$_POST['note_id'];
        $db->prepare('DELETE FROM interview_notes WHERE id = ? AND company_id = ?')->execute([$noteId, $cid]);
        $_SESSION['flash'] = '削除しました';
        header('Location: interview_notes.php');
        exit;
    }
}

// 対象社員（一般社員は自分固定）
$targetEmpId = $isAdmin ? (int)($_GET['employee_id'] ?? 0) : $myEmpId;
$employees = $isAdmin ? getAllEmployees(true, $cid) : [];
$trainings = getTrainingCatalog($cid);

$notes = [];
$targetEmp = null;
if ($targetEmpId) {
    if (!canAccessInterviewTarget($targetEmpId, $myEmpId, $isAdmin)) {
        http_response_code(403);
        die('他のユーザーの面談メモにはアクセスできません');
    }
    $eStmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?');
    $eStmt->execute([$targetEmpId, $cid]);
    $targetEmp = $eStmt->fetch();
    if ($targetEmp) {
        $notes = getInterviewNotes($targetEmpId, $cid);
    }
}

// 詳細表示
$viewNote = null;
if (!empty($_GET['note_id'])) {
    $viewNote = getInterviewNote((int)$_GET['note_id'], $cid);
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$pageTitle = '面談メモ';
$extraCss = ['eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_dashboard.php">評価</a></li>
        <li class="breadcrumb-item active">面談メモ</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<h4 class="mb-4"><i class="bi bi-chat-left-text me-2"></i>面談メモ（1on1 / フィードバック）</h4>

<div class="row">
    <!-- 左: 社員選択 + 履歴 -->
    <div class="col-lg-4 mb-4">
        <div class="card mb-3">
            <div class="card-header">社員選択</div>
            <div class="card-body">
                <select class="form-select" onchange="if(this.value) location.href='?employee_id='+this.value">
                    <option value="">社員を選択...</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $e['id']==$targetEmpId?'selected':'' ?>>
                        <?= h($e['name']) ?> (<?= h($e['department'] ?? '') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($targetEmp && $notes): ?>
        <div class="card">
            <div class="card-header">過去の面談メモ（<?= count($notes) ?>件）</div>
            <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
                <?php foreach ($notes as $n):
                    $ml = getMoodLabel((int)$n['mood']);
                    $active = ($viewNote && $viewNote['id'] == $n['id']) ? 'active' : '';
                ?>
                <a href="?employee_id=<?= $targetEmpId ?>&note_id=<?= $n['id'] ?>" class="list-group-item list-group-item-action <?= $active ?>">
                    <div class="d-flex justify-content-between">
                        <strong class="small"><?= h($n['interview_date']) ?></strong>
                        <span class="badge bg-secondary"><?= getNoteTypeLabel($n['note_type']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted">by <?= h($n['interviewer_name']) ?></small>
                        <i class="bi <?= $ml['icon'] ?>" style="color:<?= $ml['color'] ?>"></i>
                    </div>
                    <?php if ($n['positives']): ?>
                    <div class="small text-truncate mt-1"><?= h(mb_substr($n['positives'], 0, 40)) ?>...</div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- 右: 新規作成 or 詳細表示 -->
    <div class="col-lg-8">
        <?php if ($viewNote): ?>
        <!-- 面談詳細表示 -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-file-text me-2"></i><?= h($viewNote['interview_date']) ?> - <?= getNoteTypeLabel($viewNote['note_type']) ?></span>
                <span>面談者: <?= h($viewNote['interviewer_name']) ?></span>
            </div>
            <div class="card-body">
                <?php $ml = getMoodLabel((int)$viewNote['mood']); ?>
                <div class="mb-3"><i class="bi <?= $ml['icon'] ?> me-1" style="color:<?= $ml['color'] ?>"></i><strong><?= $ml['label'] ?></strong></div>

                <?php if ($viewNote['positives']): ?>
                <div class="mb-3 p-3 bg-success bg-opacity-10 rounded">
                    <h6 class="text-success"><i class="bi bi-check-circle me-1"></i>今期のできたこと（ポジティブFB）</h6>
                    <div><?= nl2br(h($viewNote['positives'])) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($viewNote['challenges']): ?>
                <div class="mb-3 p-3 bg-warning bg-opacity-10 rounded">
                    <h6 class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>課題と改善点</h6>
                    <div><?= nl2br(h($viewNote['challenges'])) ?></div>
                    <?php if ($viewNote['challenge_causes']): ?>
                    <div class="mt-2 small text-muted"><strong>原因:</strong> <?= nl2br(h($viewNote['challenge_causes'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($viewNote['action_plan']): ?>
                <div class="mb-3 p-3 bg-info bg-opacity-10 rounded">
                    <h6 class="text-info"><i class="bi bi-arrow-right-circle me-1"></i>次期アクションプラン</h6>
                    <div><?= nl2br(h($viewNote['action_plan'])) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($viewNote['career_aspiration']): ?>
                <div class="mb-3 p-3 bg-primary bg-opacity-10 rounded">
                    <h6 class="text-primary"><i class="bi bi-rocket me-1"></i>キャリア・意欲</h6>
                    <div><?= nl2br(h($viewNote['career_aspiration'])) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($viewNote['manager_memo'] && ($isAdmin || $viewNote['interviewer_id'] == $myEmpId)): ?>
                <div class="mb-3 p-3 bg-danger bg-opacity-10 rounded">
                    <h6 class="text-danger"><i class="bi bi-lock me-1"></i>管理者メモ（非公開）</h6>
                    <div><?= nl2br(h($viewNote['manager_memo'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <a href="?employee_id=<?= $targetEmpId ?>" class="btn btn-outline-primary"><i class="bi bi-plus me-1"></i>新しい面談メモを作成</a>

        <?php elseif ($targetEmp): ?>
        <!-- 新規面談メモ作成フォーム -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-plus-circle me-2"></i><?= h($targetEmp['name']) ?> の面談メモ作成
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="employee_id" value="<?= $targetEmpId ?>">

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">面談種別</label>
                            <select name="note_type" class="form-select">
                                <option value="one_on_one">1on1</option>
                                <option value="mid_term">中間面談</option>
                                <option value="end_term">期末面談</option>
                                <option value="career">キャリア面談</option>
                                <option value="other">その他</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">面談日</label>
                            <input type="date" name="interview_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">面談時の印象</label>
                            <div class="d-flex gap-1 mt-1">
                                <?php for ($m = 1; $m <= 5; $m++): $ml = getMoodLabel($m); ?>
                                <div>
                                    <input type="radio" name="mood" id="mood<?= $m ?>" value="<?= $m ?>" class="btn-check" <?= $m==3?'checked':'' ?>>
                                    <label class="btn btn-outline-secondary btn-sm" for="mood<?= $m ?>" title="<?= $ml['label'] ?>">
                                        <i class="bi <?= $ml['icon'] ?>"></i>
                                    </label>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-success"><i class="bi bi-check-circle me-1"></i>1. 今期の「できたこと」（ポジティブFB）</label>
                        <textarea name="positives" class="form-control" rows="3" placeholder="目標達成に向けた具体的な行動で評価できる点、数値に表れない周囲への貢献やスキル向上..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-warning"><i class="bi bi-exclamation-triangle me-1"></i>2. 「課題」と「原因」（建設的FB）</label>
                        <textarea name="challenges" class="form-control" rows="3" placeholder="目標未達や行動不足の具体的な事実..."></textarea>
                        <textarea name="challenge_causes" class="form-control mt-2" rows="2" placeholder="原因の深掘り（スキル不足、時間不足、環境など）..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-info"><i class="bi bi-arrow-right-circle me-1"></i>3. 次期アクションプラン</label>
                        <textarea name="action_plan" class="form-control" rows="2" placeholder="次期、課題をどう克服するか（研修、誰に教わるか等）..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-primary"><i class="bi bi-rocket me-1"></i>4. キャリア・意欲の確認</label>
                        <textarea name="career_aspiration" class="form-control" rows="2" placeholder="本人が今後挑戦したい業務、身につけたいスキル..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-danger"><i class="bi bi-lock me-1"></i>管理者メモ（本人に非公開）</label>
                        <textarea name="manager_memo" class="form-control" rows="2" placeholder="来期の昇給・昇進に向けた課題..."></textarea>
                        <div class="form-check mt-1">
                            <input type="checkbox" name="is_private" class="form-check-input" id="isPrivate" value="1" checked>
                            <label class="form-check-label small" for="isPrivate">管理者メモを本人に非公開にする</label>
                        </div>
                    </div>

                    <!-- 研修割当 -->
                    <?php if ($trainings): ?>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-mortarboard me-1"></i>研修割当（この場で割り当て可能）</label>
                        <div class="row">
                            <?php foreach ($trainings as $t): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="training_ids[]" value="<?= $t['id'] ?>" class="form-check-input" id="tr<?= $t['id'] ?>">
                                    <label class="form-check-label small" for="tr<?= $t['id'] ?>">
                                        <?= h($t['name']) ?>
                                        <?php if ($t['duration_hours']): ?><span class="badge bg-info"><?= $t['duration_hours'] ?>h</span><?php endif; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>面談メモを保存</button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-arrow-left-circle fs-1 d-block mb-3"></i>
            左の社員選択から面談相手を選んでください
        </div></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
