<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db       = getDB();
$cid      = getCompanyId();
$id       = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 社員ロール: 自分のレコードのみ編集可能
if (isEmployee()) {
    $myEmpId = getSessionEmployeeId();
    if (!$myEmpId || ($id && $id !== $myEmpId)) {
        redirect(BASE_PATH . '/public/index.php');
    }
    $id = $myEmpId;
} elseif (!isSuperAdmin() && !isCompanyAdmin()) {
    redirect(BASE_PATH . '/login.php');
}

$employee = $id ? getEmployee($id, $cid) : null;
if ($id && !$employee) {
    redirect(BASE_PATH . '/admin/employees.php');
}

$sfData   = $id ? getStrengthsFinder($id) : null;
$spiData  = $id ? getSpiResult($id)       : null;
$career   = $id ? getEmployeeCareer($id)  : [];
$teams    = getAllTeams($cid);
$sfDefs   = getStrengthsThemeDefinitions();
$spiDims  = getSpiDimensions();

// ユーザーアカウント情報取得（一般・管理者それぞれ取得）
$empUserAccount   = null; // 一般アカウント (role='employee')
$adminUserAccount = null; // 管理者アカウント (role='company_admin')
if ($id) {
    $uaStmt = $db->prepare('SELECT id, username, role, is_active, last_login_at FROM users WHERE employee_id = ?');
    $uaStmt->execute([$id]);
    foreach ($uaStmt->fetchAll() as $acc) {
        if ($acc['role'] === 'employee') $empUserAccount = $acc;
        elseif ($acc['role'] === 'company_admin') $adminUserAccount = $acc;
    }
}
// 管理者アカウントがない場合: 同一会社・同名の未リンクadminアカウントを自動検索してリンク
if (!$adminUserAccount && $id && ($employee['name'] ?? '')) {
    $empCidFb = $cid;
    if (!$empCidFb) {
        $s = $db->prepare('SELECT company_id FROM employees WHERE id = ?');
        $s->execute([$id]);
        $empCidFb = (int)$s->fetchColumn();
    }
    if ($empCidFb) {
        $fbStmt = $db->prepare(
            'SELECT id, username, role, is_active, last_login_at FROM users
             WHERE company_id = ? AND role = ? AND display_name = ?
             AND (employee_id IS NULL OR employee_id = ?)'
        );
        $fbStmt->execute([$empCidFb, 'company_admin', $employee['name'], $id]);
        $unlinkedAdmin = $fbStmt->fetch();
        if ($unlinkedAdmin) {
            $db->prepare('UPDATE users SET employee_id = ? WHERE id = ?')
               ->execute([$id, $unlinkedAdmin['id']]);
            $adminUserAccount = $unlinkedAdmin;
        }
    }
}

// 管理者アカウントがない場合、候補ユーザーIDを事前生成
$adminCandidateUsername = '';
if ($id && !$adminUserAccount) {
    $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
    do {
        $cand = '';
        for ($i = 0; $i < 10; $i++) $cand .= $chars[random_int(0, strlen($chars) - 1)];
        $ck = $db->prepare('SELECT id FROM users WHERE username = ?');
        $ck->execute([$cand]);
    } while ($ck->fetch());
    $adminCandidateUsername = $cand;
}

$pageTitle = $id ? ($employee['name'] ?? '') . ' 編集' : '新規社員登録';
$error     = '';
$csrf      = getCsrfToken();

// ============================================================
// 保存処理
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? 'save_basic';

    // --- 基本情報保存 ---
    if ($action === 'save_basic') {
        $skillsRaw = $_POST['skills'] ?? [];
        $fields = [
            'name'               => trim($_POST['name'] ?? ''),
            'phone'              => trim($_POST['phone'] ?? ''),
            'email'              => trim($_POST['email'] ?? ''),
            'hire_date'          => $_POST['hire_date'] ?: null,
            'employment_type'    => trim($_POST['employment_type'] ?? ''),
            'employment_subtype' => trim($_POST['employment_subtype'] ?? ''),
            'work_style'         => trim($_POST['work_style'] ?? ''),
            'retirement_date'    => $_POST['retirement_date'] ?: null,
            'skills_json'        => !empty($skillsRaw) ? json_encode(array_values($skillsRaw), JSON_UNESCAPED_UNICODE) : null,
        ];

        if (empty($fields['name'])) {
            $error = '氏名は必須です';
        } else {
            if ($id) {
                $setClauses = implode(',', array_map(fn($k) => "$k = ?", array_keys($fields)));
                $sql = "UPDATE employees SET $setClauses WHERE id = ?";
                $params = [...array_values($fields), $id];
                if ($cid) { $sql .= ' AND company_id = ?'; $params[] = $cid; }
                $db->prepare($sql)->execute($params);
            } else {
                // 新規作成時にcompany_idを設定（SAは選択した会社）
                $selectedCid = $cid;
                if (isSuperAdmin() && !empty($_POST['target_company_id'])) {
                    $selectedCid = (int)$_POST['target_company_id'];
                }
                $fields['company_id'] = $selectedCid ?: $cid;
                $cols   = implode(',', array_keys($fields));
                $places = implode(',', array_fill(0, count($fields), '?'));
                $stmt   = $db->prepare("INSERT INTO employees ($cols) VALUES ($places)");
                $stmt->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
            }

            // チーム設定
            $teamId = (int)($_POST['team_id'] ?? 0);
            $db->prepare('DELETE FROM team_members WHERE employee_id = ?')->execute([$id]);
            if ($teamId > 0) {
                $db->prepare('INSERT INTO team_members (team_id, employee_id) VALUES (?,?)')->execute([$teamId, $id]);
            }

            // ユーザーアカウント処理（管理者のみ）
            if (isCompanyAdmin() || isSuperAdmin()) {
                // 社員のcompany_idを取得（SAの場合$cidがNULLなのでDBから取得）
                $empCid = $cid;
                if (!$empCid) {
                    $empCidStmt = $db->prepare('SELECT company_id FROM employees WHERE id = ?');
                    $empCidStmt->execute([$id]);
                    $empCid = (int)$empCidStmt->fetchColumn();
                }

                if ($empCid) {
                    // --- 一般アカウント処理 (role='employee') ---
                    $empUsername = trim($_POST['emp_username'] ?? '');
                    $empPassword = $_POST['emp_password'] ?? '';
                    $empAccStmt = $db->prepare('SELECT id, username FROM users WHERE employee_id = ? AND role = ?');
                    $empAccStmt->execute([$id, 'employee']);
                    $empAccRow = $empAccStmt->fetch();
                    if ($empUsername) {
                        if ($empAccRow) {
                            if ($empUsername !== $empAccRow['username']) {
                                $dup = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
                                $dup->execute([$empUsername, $empAccRow['id']]);
                                if (!$dup->fetch()) {
                                    $db->prepare('UPDATE users SET username = ?, display_name = ? WHERE id = ?')
                                       ->execute([$empUsername, $fields['name'], $empAccRow['id']]);
                                }
                            } else {
                                $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                                   ->execute([$fields['name'], $empAccRow['id']]);
                            }
                            if ($empPassword) {
                                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                                   ->execute([password_hash($empPassword, PASSWORD_DEFAULT), $empAccRow['id']]);
                            }
                        } elseif ($empPassword) {
                            $dup = $db->prepare('SELECT id FROM users WHERE username = ?');
                            $dup->execute([$empUsername]);
                            if (!$dup->fetch()) {
                                $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, employee_id, is_active) VALUES (?,?,?,?,?,?,1)')
                                   ->execute([$empUsername, password_hash($empPassword, PASSWORD_DEFAULT), $fields['name'], 'employee', $empCid, $id]);
                            }
                        }
                    }

                    // --- 管理者アカウント処理 (role='company_admin') ---
                    $adminUsername = trim($_POST['admin_username'] ?? '');
                    $adminPassword = $_POST['admin_password'] ?? '';
                    $adminAccStmt = $db->prepare('SELECT id, username FROM users WHERE employee_id = ? AND role = ?');
                    $adminAccStmt->execute([$id, 'company_admin']);
                    $adminAccRow = $adminAccStmt->fetch();
                    if ($adminUsername) {
                        if ($adminAccRow) {
                            $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                               ->execute([$fields['name'], $adminAccRow['id']]);
                            if ($adminPassword) {
                                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                                   ->execute([password_hash($adminPassword, PASSWORD_DEFAULT), $adminAccRow['id']]);
                            }
                        } elseif ($adminPassword) {
                            $dup = $db->prepare('SELECT id FROM users WHERE username = ?');
                            $dup->execute([$adminUsername]);
                            if (!$dup->fetch()) {
                                $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, employee_id, is_active) VALUES (?,?,?,?,?,?,1)')
                                   ->execute([$adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT), $fields['name'], 'company_admin', $empCid, $id]);
                            }
                        }
                    }
                }
            }

            redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&saved=1');
        }
    }

    // --- 入社情報保存 ---
    if ($action === 'save_onboarding' && $id) {
        $onFields = [
            'gender', 'postal_code', 'address', 'address_kana', 'phone', 'my_number',
            'pension_number', 'insurance_number', 'has_insurance_card',
            'salary_type', 'monthly_salary', 'base_pay', 'commute_allowance',
            'allowance1_name', 'allowance1_amount', 'allowance2_name', 'allowance2_amount',
            'allowance3_name', 'allowance3_amount',
            'bank_name', 'bank_branch', 'bank_account_type', 'bank_account_number',
        ];
        $sets = [];
        $vals = [];
        foreach ($onFields as $f) {
            $sets[] = "$f = ?";
            $v = $_POST[$f] ?? null;
            $vals[] = ($v === '' || $v === null) ? null : $v;
        }
        $vals[] = $id;
        $cidCond = $cid ? ' AND company_id = ?' : '';
        if ($cid) $vals[] = $cid;
        $db->prepare('UPDATE employees SET ' . implode(', ', $sets) . ' WHERE id = ?' . $cidCond)->execute($vals);
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&tab=onboarding&saved=1');
    }

    // --- ストレングスファインダー保存 ---
    if ($action === 'save_sf') {
        $sfFields = [];
        foreach (array_keys($sfDefs) as $key) {
            $val = isset($_POST['sf_' . $key]) && $_POST['sf_' . $key] !== '' ? (int)$_POST['sf_' . $key] : null;
            $sfFields[$key] = $val;
        }
        $sfFields['top5_text'] = trim($_POST['top5_text'] ?? '');
        $sfFields['analysis']  = trim($_POST['sf_analysis'] ?? '');

        $existing = $db->prepare('SELECT id FROM strengths_finder WHERE employee_id = ?');
        $existing->execute([$id]);

        if ($existing->fetch()) {
            $setClauses = implode(',', array_map(fn($k) => "$k = ?", array_keys($sfFields)));
            $stmt = $db->prepare("UPDATE strengths_finder SET $setClauses WHERE employee_id = ?");
            $stmt->execute([...array_values($sfFields), $id]);
        } else {
            $sfFields['employee_id'] = $id;
            $cols   = implode(',', array_keys($sfFields));
            $places = implode(',', array_fill(0, count($sfFields), '?'));
            $db->prepare("INSERT INTO strengths_finder ($cols) VALUES ($places)")->execute(array_values($sfFields));
        }
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&saved=sf');
    }

    // --- SPI保存 ---
    if ($action === 'save_spi') {
        $spiFields = [];
        foreach ($spiDims as $category) {
            foreach (array_keys($category['items']) as $key) {
                $val = isset($_POST['spi_' . $key]) && $_POST['spi_' . $key] !== '' ? (int)$_POST['spi_' . $key] : null;
                $spiFields[$key] = $val;
            }
        }
        $spiFields['analysis'] = trim($_POST['spi_analysis'] ?? '');

        $existing = $db->prepare('SELECT id FROM spi_results WHERE employee_id = ?');
        $existing->execute([$id]);

        if ($existing->fetch()) {
            $setClauses = implode(',', array_map(fn($k) => "$k = ?", array_keys($spiFields)));
            $stmt = $db->prepare("UPDATE spi_results SET $setClauses WHERE employee_id = ?");
            $stmt->execute([...array_values($spiFields), $id]);
        } else {
            $spiFields['employee_id'] = $id;
            $cols   = implode(',', array_keys($spiFields));
            $places = implode(',', array_fill(0, count($spiFields), '?'));
            $db->prepare("INSERT INTO spi_results ($cols) VALUES ($places)")->execute(array_values($spiFields));
        }
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&saved=spi');
    }

    // --- キャリア追加 ---
    if ($action === 'add_career') {
        $stmt = $db->prepare(
            'INSERT INTO career_history (employee_id, start_year, start_month, end_year, end_month, is_current, is_internal, company, position, description, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $isCurrentC = !empty($_POST['career_is_current']) ? 1 : 0;
        $stmt->execute([
            $id,
            (int)$_POST['career_start_year'],
            (int)$_POST['career_start_month'],
            !$isCurrentC && $_POST['career_end_year'] ? (int)$_POST['career_end_year'] : null,
            !$isCurrentC && $_POST['career_end_month'] ? (int)$_POST['career_end_month'] : null,
            $isCurrentC,
            !empty($_POST['career_is_internal']) ? 1 : 0,
            trim($_POST['career_company'] ?? ''),
            trim($_POST['career_position'] ?? ''),
            trim($_POST['career_description'] ?? ''),
            0
        ]);
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&tab=career&saved=career');
    }

    // --- キャリア削除 ---
    if ($action === 'delete_career' && !empty($_POST['career_id'])) {
        $db->prepare('DELETE FROM career_history WHERE id = ? AND employee_id = ?')->execute([(int)$_POST['career_id'], $id]);
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&tab=career');
    }

    // --- 管理者アカウント 有効/無効切り替え ---
    if ($action === 'toggle_admin_account' && $id) {
        $adminUserId = (int)($_POST['admin_user_id'] ?? 0);
        $empCid = $cid;
        if (!$empCid) {
            $empCidStmt = $db->prepare('SELECT company_id FROM employees WHERE id = ?');
            $empCidStmt->execute([$id]);
            $empCid = (int)$empCidStmt->fetchColumn();
        }
        if ($adminUserId && $empCid) {
            $adminCheck = $db->prepare('SELECT id, is_active FROM users WHERE id = ? AND employee_id = ? AND role = ? AND company_id = ?');
            $adminCheck->execute([$adminUserId, $id, 'company_admin', $empCid]);
            $adminAcc = $adminCheck->fetch();
            if ($adminAcc) {
                $newActive = $adminAcc['is_active'] ? 0 : 1;
                $db->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')
                   ->execute([$newActive, $adminUserId]);
            }
        }
        redirect(BASE_PATH . '/admin/employee_form.php?id=' . $id . '&saved=1');
    }
}

// 現在のチームID
$currentTeamId = 0;
if ($id) {
    $t = $db->prepare('SELECT team_id FROM team_members WHERE employee_id = ? LIMIT 1');
    $t->execute([$id]);
    $currentTeamId = (int)($t->fetchColumn() ?: 0);
}

$activeTab = 'basic'; // 基本情報のみ（キャリア/入社情報/SF/SPIタブは廃止）

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item"><a href="employees.php">社員管理</a></li>
            <li class="breadcrumb-item active"><?= h($pageTitle) ?></li>
        </ol>
    </nav>

    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-person-fill me-2"></i><?= h($pageTitle) ?></h1>
            <?php if ($id): ?>
            <a href="<?= BASE_PATH ?>/public/employee.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                <i class="bi bi-eye me-1"></i>表示確認
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success alert-dismissible">保存しました！<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- ====== 基本情報 ====== -->
    <?php if ($activeTab === 'basic'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_basic">

                <?php
                $currentSkills = json_decode($employee['skills_json'] ?? '[]', true) ?: [];
                $empType = $employee['employment_type'] ?? '';
                $empSubtype = $employee['employment_subtype'] ?? '';
                ?>
                <div class="row g-3">
                    <?php if (isSuperAdmin() && !$id): ?>
                    <div class="col-12">
                        <label class="form-label fw-semibold">所属会社 <span class="text-danger">*</span></label>
                        <select name="target_company_id" class="form-select" required>
                            <option value="">会社を選択</option>
                            <?php
                            $allCompanies = $db->query('SELECT id, company_name FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll();
                            foreach ($allCompanies as $ac):
                            ?>
                            <option value="<?= $ac['id'] ?>" <?= ($cid && $cid == $ac['id']) ? 'selected' : '' ?>><?= h($ac['company_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- 氏名 -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">氏名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?= h($employee['name'] ?? '') ?>">
                    </div>

                    <!-- 電話番号 -->
                    <div class="col-md-6">
                        <label class="form-label">電話番号</label>
                        <input type="text" name="phone" class="form-control" value="<?= h($employee['phone'] ?? '') ?>">
                    </div>

                    <!-- メールアドレス -->
                    <div class="col-md-6">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" name="email" class="form-control" value="<?= h($employee['email'] ?? '') ?>">
                    </div>

                    <!-- 稼働開始日 -->
                    <div class="col-md-3">
                        <label class="form-label">稼働開始日</label>
                        <input type="date" name="hire_date" class="form-control" value="<?= h($employee['hire_date'] ?? '') ?>">
                    </div>

                    <!-- 退職日 -->
                    <div class="col-md-3">
                        <label class="form-label">退職日</label>
                        <input type="date" name="retirement_date" class="form-control" value="<?= h($employee['retirement_date'] ?? '') ?>">
                    </div>

                    <!-- 雇用形態 -->
                    <div class="col-md-3">
                        <label class="form-label">雇用形態</label>
                        <?php
                        // 旧データ互換: 自社+区分 を新選択肢へ読み替え
                        if ($empType === '自社') {
                            $empType = $empSubtype === '外注' ? '自社外注' : ($empSubtype === 'アルバイト' ? 'アルバイト' : '正社員');
                        }
                        ?>
                        <select name="employment_type" id="empType" class="form-select">
                            <option value="">未選択</option>
                            <option value="正社員" <?= $empType === '正社員' ? 'selected' : '' ?>>正社員</option>
                            <option value="自社外注" <?= $empType === '自社外注' ? 'selected' : '' ?>>自社外注</option>
                            <option value="個人外注" <?= $empType === '個人外注' ? 'selected' : '' ?>>個人外注</option>
                            <option value="アライアンス" <?= $empType === 'アライアンス' ? 'selected' : '' ?>>アライアンス</option>
                            <option value="アルバイト" <?= $empType === 'アルバイト' ? 'selected' : '' ?>>アルバイト</option>
                        </select>
                    </div>

                    <!-- 勤務形態 -->
                    <div class="col-md-3">
                        <label class="form-label">勤務形態</label>
                        <select name="work_style" class="form-select">
                            <option value="">未選択</option>
                            <option value="常勤" <?= ($employee['work_style'] ?? '') === '常勤' ? 'selected' : '' ?>>常勤</option>
                            <option value="イベント" <?= ($employee['work_style'] ?? '') === 'イベント' ? 'selected' : '' ?>>イベント</option>
                        </select>
                    </div>

                    <!-- スキル管理 -->
                    <div class="col-12">
                        <label class="form-label">スキル管理</label>
                        <div class="border rounded p-3">
                            <div class="mb-2">
                                <div class="fw-semibold small text-muted mb-1"><i class="bi bi-person-workspace me-1"></i>常勤</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php foreach (['ドコモ','au','SB','楽天','Air提案','クローザー','光AD'] as $sk): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="skills[]"
                                               id="sk_r_<?= h($sk) ?>" value="常勤:<?= h($sk) ?>"
                                               <?= in_array('常勤:' . $sk, $currentSkills) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="sk_r_<?= h($sk) ?>"><?= h($sk) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div>
                                <div class="fw-semibold small text-muted mb-1"><i class="bi bi-calendar-event me-1"></i>イベント</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php foreach (['キャッチャー','クローザー','ディレクター'] as $sk): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="skills[]"
                                               id="sk_e_<?= h($sk) ?>" value="イベント:<?= h($sk) ?>"
                                               <?= in_array('イベント:' . $sk, $currentSkills) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="sk_e_<?= h($sk) ?>"><?= h($sk) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isCompanyAdmin() || isSuperAdmin()): ?>
                <!-- ユーザーアカウント -->
                <hr class="my-4">
                <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-person-gear me-2"></i>ログインアカウント</h6>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted fw-semibold">権限:</small>
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="role_ui_select" id="roleGeneral" value="general"
                                   <?= !$adminUserAccount ? 'checked' : '' ?> onchange="updateRoleUI()">
                            <label class="btn btn-outline-secondary" for="roleGeneral"><i class="bi bi-person me-1"></i>一般社員</label>
                            <input type="radio" class="btn-check" name="role_ui_select" id="roleAdmin" value="admin"
                                   <?= $adminUserAccount ? 'checked' : '' ?> onchange="updateRoleUI()">
                            <label class="btn btn-outline-warning" for="roleAdmin"><i class="bi bi-shield-lock me-1"></i>会社管理者</label>
                        </div>
                    </div>
                </div>

                <!-- 一般アカウント（一般画面用） -->
                <div class="border rounded p-3 mb-3" style="border-color:#d1fae5 !important;background:#f0fdf4">
                    <div class="fw-semibold small mb-2" style="color:#065f46"><i class="bi bi-person me-1"></i>一般アカウント（一般画面ログイン用）</div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small">ユーザーID</label>
                            <input type="text" name="emp_username" class="form-control"
                                   pattern="[a-zA-Z0-9_\-]{3,50}" placeholder="半角英数字（3〜50文字）"
                                   value="<?= h($empUserAccount['username'] ?? '') ?>">
                            <?php if ($empUserAccount): ?>
                            <div class="form-text text-success"><i class="bi bi-check-circle me-1"></i>作成済み<?php if ($empUserAccount['last_login_at']): ?>（最終: <?= date('Y/m/d H:i', strtotime($empUserAccount['last_login_at'])) ?>）<?php endif; ?></div>
                            <?php else: ?>
                            <div class="form-text text-muted">IDとパスワードを入力すると自動作成されます</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">パスワード</label>
                            <div class="input-group">
                                <input type="password" name="emp_password" class="form-control" id="empPwField"
                                       placeholder="<?= $empUserAccount ? '変更時のみ入力' : '新規作成時は必須' ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="genPw('empPwField')"><i class="bi bi-shuffle"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 管理者アカウント（管理者画面用） -->
                <div id="adminAccountSection" style="display:<?= $adminUserAccount ? 'block' : 'none' ?>">
                <div class="border rounded p-3" style="border-color:#fde68a !important;background:#fffbeb">
                    <div class="fw-semibold small mb-2" style="color:#92400e"><i class="bi bi-shield-lock me-1"></i>管理者アカウント（管理者画面ログイン用）<span class="badge bg-warning text-dark ms-2" style="font-size:9px">ユーザーID自動生成</span></div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small">ユーザーID</label>
                            <input type="text" class="form-control bg-light" readonly
                                   value="<?= h($adminUserAccount['username'] ?? $adminCandidateUsername) ?>">
                            <input type="hidden" name="admin_username" value="<?= h($adminUserAccount['username'] ?? $adminCandidateUsername) ?>">
                            <?php if ($adminUserAccount): ?>
                            <?php if ($adminUserAccount['is_active']): ?>
                            <div class="form-text text-success"><i class="bi bi-check-circle me-1"></i>有効（管理者画面ログイン可）<?php if ($adminUserAccount['last_login_at']): ?>（最終: <?= date('Y/m/d H:i', strtotime($adminUserAccount['last_login_at'])) ?>）<?php endif; ?></div>
                            <?php else: ?>
                            <div class="form-text text-danger"><i class="bi bi-pause-circle me-1"></i>管理者権限停止中（管理者画面へのログイン不可）</div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="form-text text-muted"><i class="bi bi-info-circle me-1"></i>自動生成済み（変更不可）</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">パスワード<?= !$adminUserAccount ? ' <span class="text-muted" style="font-size:11px">（入力すると管理者アカウントを作成）</span>' : '' ?></label>
                            <div class="input-group">
                                <input type="password" name="admin_password" class="form-control" id="adminPwField"
                                       placeholder="<?= $adminUserAccount ? '変更時のみ入力' : 'パスワードを設定して保存' ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="genPw('adminPwField')"><i class="bi bi-shuffle"></i></button>
                            </div>
                        </div>
                        <?php if ($adminUserAccount): ?>
                        <div class="col-md-2 d-flex align-items-end">
                            <!-- フォームの入れ子は不可のため、外部フォーム(adminToggleForm)をform属性で参照 -->
                            <button type="submit" form="adminToggleForm" class="btn btn-sm w-100 <?= $adminUserAccount['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                <i class="bi bi-<?= $adminUserAccount['is_active'] ? 'x-circle' : 'check-circle' ?> me-1"></i>
                                <?= $adminUserAccount['is_active'] ? '権限取り消し' : '権限再付与' ?>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                </div><!-- /adminAccountSection -->
                <?php endif; ?>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>保存する
                    </button>
                    <a href="employees.php" class="btn btn-secondary ms-2">キャンセル</a>
                </div>
            </form>

            <?php if ((isCompanyAdmin() || isSuperAdmin()) && $adminUserAccount): ?>
            <!-- 管理者権限 有効/無効切り替え（メインフォームの外に配置） -->
            <form method="post" id="adminToggleForm" onsubmit="return confirm('<?= $adminUserAccount['is_active'] ? '管理者権限を取り消しますか？このユーザーは管理者画面にアクセスできなくなります。' : '管理者権限を再付与しますか？' ?>')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle_admin_account">
                <input type="hidden" name="admin_user_id" value="<?= $adminUserAccount['id'] ?>">
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php
$inlineJs = <<<'JS2'
function genPw(fieldId) {
    var c = 'abcdefghijkmnpqrstuvwxyz23456789', p = '';
    for (var i = 0; i < 10; i++) p += c[Math.floor(Math.random() * c.length)];
    document.getElementById(fieldId).value = p;
}
function updateRoleUI() {
    var isAdmin = document.getElementById('roleAdmin') && document.getElementById('roleAdmin').checked;
    var sec = document.getElementById('adminAccountSection');
    if (sec) sec.style.display = isAdmin ? 'block' : 'none';
}
JS2;
require_once __DIR__ . '/../includes/footer.php';
?>
