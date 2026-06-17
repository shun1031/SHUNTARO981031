<?php
/**
 * フィードバック面談画面（強化版）
 * - 評価結果の確認
 * - 面談テンプレート（ポジティブFB / 課題 / キャリア）
 * - 過去の面談メモ参照（サイドパネル）
 * - 研修リクエストボタン（その場で割り当て）
 * - 昇給シミュレーター（リアルタイムプレビュー）
 * - 研修自動推奨表示
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

$sheetId = (int)($_GET['sheet_id'] ?? 0);
if (!$sheetId) { header('Location: eval_dashboard.php'); exit; }
$sheet = getEvalSheet($sheetId, $cid);
if (!$sheet) { header('Location: eval_dashboard.php'); exit; }

$canAccess = $isAdmin || $sheet['employee_id'] == $myEmpId || $sheet['evaluator_id'] == $myEmpId;
if (!$canAccess) { header('Location: eval_dashboard.php'); exit; }
$canEdit = $isAdmin || $sheet['evaluator_id'] == $myEmpId;
$isEvaluator = $sheet['evaluator_id'] == $myEmpId || $isAdmin;

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'save_feedback') {
        $db->prepare('UPDATE eval_sheets SET feedback_comment = ?, next_goals = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
           ->execute([trim($_POST['feedback_comment'] ?? ''), trim($_POST['next_goals'] ?? ''), $sheetId, $cid]);
        $_SESSION['flash'] = '保存しました';
    } elseif ($action === 'complete_feedback') {
        $db->prepare("UPDATE eval_sheets SET feedback_comment = ?, next_goals = ?, status = 'feedback_done', feedback_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ?")
           ->execute([trim($_POST['feedback_comment'] ?? ''), trim($_POST['next_goals'] ?? ''), $sheetId, $cid]);
        generateTrainingRecommendations($sheetId);
        $_SESSION['flash'] = 'フィードバックを完了しました';
    } elseif ($action === 'assign_training') {
        $trainingId = (int)$_POST['training_id'];
        $reason = trim($_POST['assign_reason'] ?? '面談でのフィードバックに基づく割当');
        if ($trainingId) {
            assignTraining($cid, $sheet['employee_id'], $trainingId, $myEmpId, $reason, null, $sheetId);
            $_SESSION['flash'] = '研修を割り当てました';
        }
    }
    header("Location: eval_feedback.php?sheet_id={$sheetId}");
    exit;
}

$weights = getAxisWeight($cid, $sheet['department_key']) ?: ['weight_performance'=>40,'weight_action'=>40,'weight_competency'=>20];
$autoTrainings = getTrainingRecommendations($sheetId);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$scoreTotal = (float)($sheet['final_score_total'] ?? $sheet['primary_score_total'] ?? 0);
$grade = $sheet['final_grade'] ?: getEvalGrade($scoreTotal);
$gradeClass = getGradeBadgeClass($grade);
$statusLabel = getEvalStatusLabel($sheet['status']);

// 過去の面談メモ（評価者向け）
$pastNotes = $isEvaluator ? getInterviewNotes($sheet['employee_id'], $cid, 10) : [];

// 給与シミュレーション
$salaryPreview = $isEvaluator ? simulateSalary($cid, $sheet['employee_id'], $grade) : null;

// 研修カタログ（割当用）
$catalog = $isEvaluator ? getTrainingCatalog($cid) : [];

// 既に割当済みの研修
$assignedTrainings = getTrainingAssignments($sheet['employee_id'], $cid);

$pageTitle = 'フィードバック - ' . h($sheet['employee_name']);
$extraCss = ['eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_dashboard.php">評価</a></li>
        <li class="breadcrumb-item active"><?= h($sheet['employee_name']) ?> フィードバック</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4><i class="bi bi-chat-left-dots me-2"></i><?= h($sheet['employee_name']) ?> - <?= h($sheet['period_name']) ?></h4>
        <span class="badge <?= $statusLabel['class'] ?>"><?= $statusLabel['label'] ?></span>
    </div>
    <div class="col-md-4 text-end">
        <span class="grade-badge <?= $gradeClass ?> text-white px-3 py-2 rounded"><?= h($grade) ?></span>
    </div>
</div>

<div class="row">
    <!-- メインコンテンツ -->
    <div class="col-lg-8">

        <!-- スコア概要 -->
        <div class="row mb-4">
            <?php
            $axes = [
                ['label'=>'業績','key'=>'performance','color'=>'#3498db','w'=>$weights['weight_performance']],
                ['label'=>'行動','key'=>'action','color'=>'#27ae60','w'=>$weights['weight_action']],
                ['label'=>'コンピテンシー','key'=>'competency','color'=>'#9b59b6','w'=>$weights['weight_competency']],
            ];
            foreach ($axes as $ax):
                $final = $sheet["final_score_{$ax['key']}"] ?? $sheet["primary_score_{$ax['key']}"] ?? '-';
            ?>
            <div class="col-4">
                <div class="card h-100" style="border-left:4px solid <?= $ax['color'] ?>">
                    <div class="card-body text-center py-2">
                        <div class="text-muted" style="font-size:0.7rem"><?= $ax['label'] ?>（<?= $ax['w'] ?>%）</div>
                        <div class="fs-3 fw-bold" style="color:<?= $ax['color'] ?>"><?= is_numeric($final) ? number_format((float)$final,1) : $final ?></div>
                        <div class="text-muted" style="font-size:0.65rem">
                            自己:<?= number_format((float)($sheet["self_score_{$ax['key']}"] ?? 0),1) ?> /
                            1次:<?= number_format((float)($sheet["primary_score_{$ax['key']}"] ?? 0),1) ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card mb-4 text-center">
            <div class="card-body py-2">
                <span class="text-muted">総合</span>
                <span class="fs-3 fw-bold ms-2"><?= number_format($scoreTotal,1) ?></span>
            </div>
        </div>

        <!-- 昇給シミュレーター（管理者向け） -->
        <?php if ($isEvaluator && $salaryPreview && $salaryPreview['current_salary']): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark"><i class="bi bi-calculator me-2"></i>昇給シミュレーター（リアルタイムプレビュー）</div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="text-muted small">現在</div>
                        <div class="fw-bold"><?= h($salaryPreview['current_grade_rank']) ?>-<?= $salaryPreview['current_step'] ?></div>
                        <div class="fs-5"><?= number_format($salaryPreview['current_salary']) ?>円</div>
                    </div>
                    <div class="col-md-1 d-flex align-items-center justify-content-center">
                        <i class="bi bi-arrow-right fs-4 text-muted"></i>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small"><?= h($grade) ?>評価後</div>
                        <div class="fw-bold"><?= h($salaryPreview['new_grade_rank']) ?>-<?= $salaryPreview['new_step'] ?></div>
                        <div class="fs-5"><?= $salaryPreview['new_salary'] ? number_format($salaryPreview['new_salary']) . '円' : '-' ?></div>
                    </div>
                    <div class="col-md-3">
                        <?php if ($salaryPreview['salary_diff'] !== null): ?>
                        <div class="text-muted small">月額増減</div>
                        <div class="fs-5 fw-bold <?= $salaryPreview['salary_diff'] > 0 ? 'text-danger' : ($salaryPreview['salary_diff'] < 0 ? 'text-success' : '') ?>">
                            <?= $salaryPreview['salary_diff'] >= 0 ? '+' : '' ?><?= number_format($salaryPreview['salary_diff']) ?>円
                        </div>
                        <?php endif; ?>
                        <?php if ($salaryPreview['bonus_amount']): ?>
                        <div class="text-muted small mt-1">賞与見込</div>
                        <div class="fw-bold"><?= number_format($salaryPreview['bonus_amount']) ?>円</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-muted small mt-2 text-center">
                    <?= h($salaryPreview['rule_description']) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- フィードバック面談テンプレート -->
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-chat-left-dots me-2"></i>フィードバックコメント</div>
                <div class="card-body">
                    <?php if ($canEdit && $sheet['status'] !== 'feedback_done'): ?>
                    <div class="small text-muted mb-2">
                        <strong>面談ガイド:</strong>
                        ①できたことを具体的に承認 → ②課題と原因を一緒に深掘り → ③次期のアクションを合意
                    </div>
                    <textarea name="feedback_comment" class="form-control" rows="6" placeholder="【できたこと】&#10;・○○の目標を120%達成した点は素晴らしい&#10;&#10;【課題】&#10;・△△の部分は改善の余地がある&#10;&#10;【次期に向けて】&#10;・□□の研修を受けて、スキルアップを図りましょう"><?= h($sheet['feedback_comment'] ?? '') ?></textarea>
                    <?php else: ?>
                    <div class="eval-readonly"><?= nl2br(h($sheet['feedback_comment'] ?? '未記入')) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-flag me-2"></i>次期目標</div>
                <div class="card-body">
                    <?php if ($canEdit && $sheet['status'] !== 'feedback_done'): ?>
                    <textarea name="next_goals" class="form-control" rows="4" placeholder="次期の具体的な目標（数値目標 + 行動目標 + 成長目標）"><?= h($sheet['next_goals'] ?? '') ?></textarea>
                    <?php else: ?>
                    <div class="eval-readonly"><?= nl2br(h($sheet['next_goals'] ?? '未記入')) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit && $sheet['status'] !== 'feedback_done'): ?>
            <div class="d-flex gap-2 mb-4">
                <button type="submit" name="action" value="save_feedback" class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>一時保存</button>
                <button type="submit" name="action" value="complete_feedback" class="btn btn-success eval-submit-btn"><i class="bi bi-check-circle me-1"></i>フィードバック完了</button>
                <a href="interview_notes.php?employee_id=<?= $sheet['employee_id'] ?>" class="btn btn-outline-secondary ms-auto"><i class="bi bi-chat-left-text me-1"></i>面談メモ作成</a>
            </div>
            <?php endif; ?>
        </form>

        <!-- 研修割当ボタン（その場で研修を割り当て） -->
        <?php if ($isEvaluator && $catalog): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-mortarboard me-2"></i>研修リクエスト（この場で割り当て）</div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="assign_training">
                    <div class="col-md-5">
                        <label class="form-label small">研修を選択</label>
                        <select name="training_id" class="form-select form-select-sm" required>
                            <option value="">選択...</option>
                            <?php foreach ($catalog as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> (<?= $c['duration_hours'] ?? '-' ?>h)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">割当理由</label>
                        <input name="assign_reason" class="form-control form-control-sm" placeholder="課題に基づく割当理由...">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-warning w-100"><i class="bi bi-plus me-1"></i>割当</button>
                    </div>
                </form>

                <?php if ($assignedTrainings): ?>
                <div class="mt-3">
                    <div class="small text-muted mb-1">割当済み研修:</div>
                    <?php foreach ($assignedTrainings as $at): ?>
                    <span class="badge bg-info me-1 mb-1"><?= h($at['training_name']) ?> (<?= h($at['status']) ?>)</span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 自動推奨研修 -->
        <?php if ($autoTrainings): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-robot me-2"></i>AI自動推奨研修</div>
            <div class="card-body">
                <?php foreach ($autoTrainings as $t): ?>
                <div class="d-flex align-items-start mb-2 p-2 bg-light rounded">
                    <div class="flex-grow-1">
                        <strong><?= h($t['training_name']) ?></strong>
                        <?php if ($t['duration_hours']): ?><span class="badge bg-info ms-1"><?= h($t['duration_hours']) ?>h</span><?php endif; ?>
                        <div class="text-muted small"><?= h($t['reason']) ?></div>
                    </div>
                    <?php if ($t['url']): ?><a href="<?= h($t['url']) ?>" class="btn btn-sm btn-outline-primary ms-2" target="_blank">詳細</a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- サイドパネル: 過去の面談メモ -->
    <?php if ($isEvaluator && $pastNotes): ?>
    <div class="col-lg-4">
        <div class="card sticky-top" style="top:70px">
            <div class="card-header bg-dark text-white">
                <i class="bi bi-clock-history me-2"></i>過去の面談メモ
                <span class="badge bg-light text-dark ms-1"><?= count($pastNotes) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:70vh;overflow-y:auto">
                <?php foreach ($pastNotes as $n):
                    $ml = getMoodLabel((int)$n['mood']);
                ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <strong class="small"><?= h($n['interview_date']) ?></strong>
                        <span class="badge bg-secondary" style="font-size:0.65rem"><?= getNoteTypeLabel($n['note_type']) ?></span>
                    </div>
                    <div class="small mt-1">
                        <i class="bi <?= $ml['icon'] ?> me-1" style="color:<?= $ml['color'] ?>"></i>
                        <span class="text-muted">by <?= h($n['interviewer_name']) ?></span>
                    </div>
                    <?php if ($n['positives']): ?>
                    <div class="small mt-1 text-success"><i class="bi bi-check me-1"></i><?= h(mb_substr($n['positives'], 0, 80)) ?><?= mb_strlen($n['positives']) > 80 ? '...' : '' ?></div>
                    <?php endif; ?>
                    <?php if ($n['challenges']): ?>
                    <div class="small mt-1 text-warning"><i class="bi bi-exclamation me-1"></i><?= h(mb_substr($n['challenges'], 0, 80)) ?><?= mb_strlen($n['challenges']) > 80 ? '...' : '' ?></div>
                    <?php endif; ?>
                    <?php if ($n['manager_memo']): ?>
                    <div class="small mt-1 text-danger"><i class="bi bi-lock me-1"></i><?= h(mb_substr($n['manager_memo'], 0, 60)) ?>...</div>
                    <?php endif; ?>
                    <a href="interview_notes.php?employee_id=<?= $sheet['employee_id'] ?>&note_id=<?= $n['id'] ?>" class="small">詳細 →</a>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer text-center">
                <a href="interview_notes.php?employee_id=<?= $sheet['employee_id'] ?>" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-plus me-1"></i>新しい面談メモ
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
