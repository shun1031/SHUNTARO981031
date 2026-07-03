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
}

// 現在のチームID
$currentTeamId = 0;
if ($id) {
    $t = $db->prepare('SELECT team_id FROM team_members WHERE employee_id = ? LIMIT 1');
    $t->execute([$id]);
    $currentTeamId = (int)($t->fetchColumn() ?: 0);
}

$activeTab = $_GET['tab'] ?? 'basic';

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

    <!-- タブ -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'basic' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=basic"><i class="bi bi-person me-1"></i>基本情報</a></li>
        <?php if ($id): ?>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'career' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=career"><i class="bi bi-briefcase me-1"></i>キャリア</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'onboarding' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=onboarding"><i class="bi bi-file-earmark-text me-1"></i>入社情報</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'sf' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=sf"><i class="bi bi-lightning me-1"></i>SF</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'spi' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=spi"><i class="bi bi-activity me-1"></i>SPI</a></li>
        <?php endif; ?>
    </ul>

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
                        <select name="employment_type" id="empType" class="form-select" onchange="toggleSubtype()">
                            <option value="">未選択</option>
                            <option value="自社" <?= $empType === '自社' ? 'selected' : '' ?>>自社</option>
                            <option value="アライアンス" <?= $empType === 'アライアンス' ? 'selected' : '' ?>>アライアンス</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="subtypeGroup" style="display:<?= $empType === '自社' ? 'block' : 'none' ?>">
                        <label class="form-label">区分</label>
                        <select name="employment_subtype" id="empSubtype" class="form-select">
                            <option value="">未選択</option>
                            <option value="社員" <?= $empSubtype === '社員' ? 'selected' : '' ?>>社員</option>
                            <option value="外注" <?= $empSubtype === '外注' ? 'selected' : '' ?>>外注</option>
                            <option value="アルバイト" <?= $empSubtype === 'アルバイト' ? 'selected' : '' ?>>アルバイト</option>
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
                <h6 class="fw-bold mb-3"><i class="bi bi-person-gear me-2"></i>ログインアカウント</h6>

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
                <div class="border rounded p-3" style="border-color:#fde68a !important;background:#fffbeb">
                    <div class="fw-semibold small mb-2" style="color:#92400e"><i class="bi bi-shield-lock me-1"></i>管理者アカウント（管理者画面ログイン用）<span class="badge bg-warning text-dark ms-2" style="font-size:9px">ユーザーID自動生成</span></div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small">ユーザーID</label>
                            <input type="text" class="form-control bg-light" readonly
                                   value="<?= h($adminUserAccount['username'] ?? $adminCandidateUsername) ?>">
                            <input type="hidden" name="admin_username" value="<?= h($adminUserAccount['username'] ?? $adminCandidateUsername) ?>">
                            <?php if ($adminUserAccount): ?>
                            <div class="form-text text-success"><i class="bi bi-check-circle me-1"></i>作成済み<?php if ($adminUserAccount['last_login_at']): ?>（最終: <?= date('Y/m/d H:i', strtotime($adminUserAccount['last_login_at'])) ?>）<?php endif; ?></div>
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
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>保存する
                    </button>
                    <a href="employees.php" class="btn btn-secondary ms-2">キャンセル</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ====== キャリア ====== -->
    <?php elseif ($activeTab === 'career'): ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header">登録済みキャリア</div>
                <div class="card-body p-0">
                    <?php if (empty($career)): ?>
                    <p class="text-muted p-3">まだキャリアが登録されていません</p>
                    <?php endif; ?>
                    <?php foreach ($career as $c): ?>
                    <div class="d-flex justify-content-between align-items-start p-3 border-bottom">
                        <div>
                            <div class="fw-semibold"><?= h($c['position']) ?></div>
                            <?php if ($c['is_internal']): ?>
                            <span class="badge bg-primary-subtle text-primary" style="font-size:11px">社内</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size:11px"><?= h($c['company'] ?: '前職') ?></span>
                            <?php endif; ?>
                            <div class="text-muted small mt-1">
                                <?= $c['start_year'] ?>年<?= $c['start_month'] ?>月 〜
                                <?= $c['is_current'] ? '現在' : ($c['end_year'] ? $c['end_year'] . '年' . $c['end_month'] . '月' : '') ?>
                            </div>
                            <?php if ($c['description']): ?>
                            <p class="text-muted small mt-1 mb-0"><?= nl2br(h($c['description'])) ?></p>
                            <?php endif; ?>
                        </div>
                        <form method="POST" class="ms-2">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="delete_career">
                            <input type="hidden" name="career_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('削除しますか？')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>キャリア追加</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_career">

                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">開始年</label>
                                <input type="number" name="career_start_year" class="form-control form-control-sm" value="<?= date('Y') ?>" min="1970" max="2099">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">月</label>
                                <input type="number" name="career_start_month" class="form-control form-control-sm" value="<?= date('n') ?>" min="1" max="12">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">終了年</label>
                                <input type="number" name="career_end_year" class="form-control form-control-sm" min="1970" max="2099">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">月</label>
                                <input type="number" name="career_end_month" class="form-control form-control-sm" min="1" max="12">
                            </div>
                        </div>
                        <div class="mb-2">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="career_is_current" id="isCurrentC">
                                <label class="form-check-label small" for="isCurrentC">現在も継続中</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="career_is_internal" id="isInternalC">
                                <label class="form-check-label small" for="isInternalC">社内キャリア</label>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">会社名（社外の場合）</label>
                            <input type="text" name="career_company" class="form-control form-control-sm">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">役職・担当</label>
                            <input type="text" name="career_position" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">業務内容</label>
                            <textarea name="career_description" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">追加する</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== 入社情報 ====== -->
    <?php elseif ($activeTab === 'onboarding'): ?>
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2"></i>入社・扶養者連絡票用データ</h6>
                <a href="export_onboarding.php?id=<?= $id ?>" target="_blank" class="btn btn-success btn-sm">
                    <i class="bi bi-printer me-1"></i>連絡票を出力
                </a>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_onboarding">

                <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-person-vcard me-2"></i>個人情報</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">性別</label>
                        <select name="gender" class="form-select">
                            <option value="">未選択</option>
                            <option value="male" <?= ($emp['gender']??'')==='male'?'selected':'' ?>>男</option>
                            <option value="female" <?= ($emp['gender']??'')==='female'?'selected':'' ?>>女</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">郵便番号</label>
                        <input type="text" name="postal_code" class="form-control" value="<?= h($emp['postal_code'] ?? '') ?>" placeholder="000-0000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">電話番号</label>
                        <input type="text" name="phone" class="form-control" value="<?= h($emp['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">個人番号（マイナンバー）</label>
                        <input type="password" name="my_number" class="form-control" value="<?= h($emp['my_number'] ?? '') ?>" maxlength="12">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">住所フリガナ</label>
                        <input type="text" name="address_kana" class="form-control" value="<?= h($emp['address_kana'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">現住所</label>
                        <input type="text" name="address" class="form-control" value="<?= h($emp['address'] ?? '') ?>">
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-shield-check me-2"></i>社会保険</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">基礎年金番号</label>
                        <input type="text" name="pension_number" class="form-control" value="<?= h($emp['pension_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">被保険者番号</label>
                        <input type="text" name="insurance_number" class="form-control" value="<?= h($emp['insurance_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">被保険者証</label>
                        <select name="has_insurance_card" class="form-select">
                            <option value="0" <?= !($emp['has_insurance_card']??0)?'selected':'' ?>>無し</option>
                            <option value="1" <?= ($emp['has_insurance_card']??0)?'selected':'' ?>>有り</option>
                        </select>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-cash-coin me-2"></i>給与情報</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">給与形態</label>
                        <select name="salary_type" class="form-select">
                            <option value="monthly" <?= ($emp['salary_type']??'')==='monthly'?'selected':'' ?>>月給</option>
                            <option value="daily" <?= ($emp['salary_type']??'')==='daily'?'selected':'' ?>>日給</option>
                            <option value="hourly" <?= ($emp['salary_type']??'')==='hourly'?'selected':'' ?>>時間給</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">給与総額（月額）</label>
                        <input type="number" name="monthly_salary" class="form-control" value="<?= $emp['monthly_salary'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">基本給</label>
                        <input type="number" name="base_pay" class="form-control" value="<?= $emp['base_pay'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">通勤手当</label>
                        <input type="number" name="commute_allowance" class="form-control" value="<?= $emp['commute_allowance'] ?? '' ?>">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="row g-2">
                            <div class="col-7"><input type="text" name="allowance1_name" class="form-control" value="<?= h($emp['allowance1_name'] ?? '') ?>" placeholder="手当1名"></div>
                            <div class="col-5"><input type="number" name="allowance1_amount" class="form-control" value="<?= $emp['allowance1_amount'] ?? '' ?>" placeholder="金額"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2">
                            <div class="col-7"><input type="text" name="allowance2_name" class="form-control" value="<?= h($emp['allowance2_name'] ?? '') ?>" placeholder="手当2名"></div>
                            <div class="col-5"><input type="number" name="allowance2_amount" class="form-control" value="<?= $emp['allowance2_amount'] ?? '' ?>" placeholder="金額"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2">
                            <div class="col-7"><input type="text" name="allowance3_name" class="form-control" value="<?= h($emp['allowance3_name'] ?? '') ?>" placeholder="手当3名"></div>
                            <div class="col-5"><input type="number" name="allowance3_amount" class="form-control" value="<?= $emp['allowance3_amount'] ?? '' ?>" placeholder="金額"></div>
                        </div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-bank me-2"></i>給与振込先</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">銀行名</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= h($emp['bank_name'] ?? '') ?>" placeholder="○○銀行">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">支店名</label>
                        <input type="text" name="bank_branch" class="form-control" value="<?= h($emp['bank_branch'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">口座種別</label>
                        <select name="bank_account_type" class="form-select">
                            <option value="ordinary" <?= ($emp['bank_account_type']??'')==='ordinary'?'selected':'' ?>>普通</option>
                            <option value="current" <?= ($emp['bank_account_type']??'')==='current'?'selected':'' ?>>当座</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">口座番号</label>
                        <input type="text" name="bank_account_number" class="form-control" value="<?= h($emp['bank_account_number'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>入社情報を保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====== ストレングスファインダー ====== -->
    <?php elseif ($activeTab === 'sf'): ?>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-lightning me-2"></i>ストレングスファインダー登録
            <small class="text-muted ms-2">各資質のランク（1位=最強）を入力してください</small>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_sf">

                <div class="row g-2">
                    <?php foreach ($sfDefs as $key => $def): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <label class="form-label small" data-bs-toggle="tooltip" title="<?= h($def['desc']) ?>">
                            <?= h($def['ja']) ?>
                            <span class="badge" style="font-size:9px;background:#eee;color:#555"><?= h($def['domain']) ?></span>
                        </label>
                        <input type="number" name="sf_<?= $key ?>" class="form-control form-control-sm"
                               min="1" max="34"
                               value="<?= h($sfData[$key] ?? '') ?>"
                               placeholder="ランク">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <label class="form-label">トップ5資質（表示用テキスト）</label>
                        <input type="text" name="top5_text" class="form-control"
                               value="<?= h($sfData['top5_text'] ?? '') ?>"
                               placeholder="例: 達成欲・戦略性・学習欲・最上志向・親密性">
                    </div>
                    <div class="col-12">
                        <label class="form-label">SF分析コメント（AI生成テキストを貼り付け可）</label>
                        <textarea name="sf_analysis" class="form-control" rows="5"><?= h($sfData['analysis'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save me-1"></i>SF情報を保存
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ====== SPI ====== -->
    <?php elseif ($activeTab === 'spi'): ?>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-activity me-2"></i>SPI登録
            <small class="text-muted ms-2">各項目を1〜10で入力してください</small>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_spi">

                <?php foreach ($spiDims as $category): ?>
                <div class="section-title"><?= h($category['label']) ?></div>
                <div class="row g-2 mb-3">
                    <?php foreach ($category['items'] as $key => $label): ?>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small"><?= h($label) ?></label>
                        <input type="number" name="spi_<?= $key ?>" class="form-control form-control-sm"
                               min="1" max="10"
                               value="<?= h($spiData[$key] ?? '') ?>"
                               placeholder="1-10">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <div class="mb-3">
                    <label class="form-label">SPI分析コメント</label>
                    <textarea name="spi_analysis" class="form-control" rows="5"><?= h($spiData['analysis'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save me-1"></i>SPI情報を保存
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$inlineJs = <<<'JS2'
function toggleSubtype() {
    var type = document.getElementById('empType').value;
    var grp  = document.getElementById('subtypeGroup');
    if (grp) grp.style.display = type === '自社' ? 'block' : 'none';
    if (type !== '自社') {
        var sub = document.getElementById('empSubtype');
        if (sub) sub.value = '';
    }
}
function genPw(fieldId) {
    var c = 'abcdefghijkmnpqrstuvwxyz23456789', p = '';
    for (var i = 0; i < 10; i++) p += c[Math.floor(Math.random() * c.length)];
    document.getElementById(fieldId).value = p;
}
JS2;
require_once __DIR__ . '/../includes/footer.php';
?>
