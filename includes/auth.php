<?php
// ============================================================
// 認証・認可ヘルパー関数（マルチテナント対応）
// ============================================================

/**
 * 現在ログイン中のユーザー情報を取得
 * 権限変更の即時反映のためDBからロールを再確認する
 */
function getCurrentUser(): ?array {
    startSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $dbRole = $stmt->fetchColumn();
    if ($dbRole === false) {
        session_destroy();
        return null;
    }
    $_SESSION['user_role'] = $dbRole;
    return [
        'id'          => $_SESSION['user_id'],
        'role'        => $dbRole,
        'company_id'  => $_SESSION['company_id'] ?? null,
        'employee_id' => $_SESSION['employee_id'] ?? null,
        'display_name'=> $_SESSION['display_name'] ?? '',
    ];
}

/**
 * ログイン必須（全ロール対象）。未ログインならログインページへ
 */
function requireAnyLogin(): void {
    if (!getCurrentUser()) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
}

/**
 * 指定ロールのいずれかを要求。不一致なら403またはリダイレクト
 */
function requireRole(string ...$roles): void {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
        echo '<h1>403 アクセス権限がありません</h1>';
        echo '<p>この機能を利用する権限がありません。</p>';
        echo '<a href="' . BASE_PATH . '/public/index.php">ダッシュボードへ戻る</a>';
        echo '</body></html>';
        exit;
    }
}

/**
 * セッションからcompany_idを取得。super_adminの場合はNULL（全社閲覧）
 */
function getCompanyId(): ?int {
    startSession();
    return $_SESSION['company_id'] ?? null;
}

/**
 * ロール判定ヘルパー
 */
function isSuperAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'super_admin';
}

function isCompanyAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'company_admin';
}

function isEmployee(): bool {
    return ($_SESSION['user_role'] ?? '') === 'employee';
}

/**
 * 管理者かどうか（super_admin または company_admin）
 */
function isAdmin(): bool {
    $role = $_SESSION['user_role'] ?? '';
    return $role === 'super_admin' || $role === 'company_admin';
}

/**
 * ログイン中のユーザーが社員一覧で「営業担当」にチェックされているか
 * 判定は employees.sales_rep_flag（在籍中の正社員・自社外注のみ）を見る。
 * ※第2段階として用意した判定。まだどの画面からも呼ばれていない
 */
function isSalesRep(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = false;
    $empId = getSessionEmployeeId();
    if (!$empId) return $cache;
    try {
        $sql = "SELECT sales_rep_flag FROM employees
                WHERE id = ? AND is_active = 1
                  AND employment_type IN ('正社員', '自社外注')";
        $params = [$empId];
        $cid = getCompanyId();
        if ($cid) { $sql .= ' AND company_id = ?'; $params[] = $cid; }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $cache = ((int)$stmt->fetchColumn() === 1);
    } catch (PDOException $e) {
        // 列が未追加の環境では営業担当なしとして扱う（既存の動作を変えないため）
        error_log('[isSalesRep] ' . $e->getMessage());
    }
    return $cache;
}

/**
 * 営業マン用画面を閲覧できるか（管理者は従来どおり全画面を閲覧可）
 * ※第2段階として用意した判定。まだどの画面からも呼ばれていない
 */
function canViewSalesPages(): bool {
    return isAdmin() || isSalesRep();
}

/**
 * 営業マン用画面での絞り込み条件
 * 管理者・営業担当は全件（null）、それ以外は従来どおり自分の名前で絞り込む。
 * ※シフト・日報・交通費など他の画面は従来どおり getEmployeeNameFilter() を使うこと。
 *   共通側を変えると、それらの画面でも営業担当が全員分を見られてしまうため。
 */
function getSalesPageNameFilter(): ?string {
    if (canViewSalesPages()) return null;
    return getEmployeeNameFilter();
}

/**
 * 営業マン用画面の閲覧を要求する（管理者 または 営業担当のみ）
 * これらの画面は従来 requireAnyLogin() だけで守られており、
 * メニューに出していないだけで誰でもURLから開けたため、入口で明示的に確認する。
 */
function requireSalesPageView(): void {
    if (canViewSalesPages()) return;
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h1>403 アクセス権限がありません</h1>';
    echo '<p>この画面は営業担当の方のみ閲覧できます。</p>';
    echo '<a href="' . BASE_PATH . '/public/index.php">ダッシュボードへ戻る</a>';
    echo '</body></html>';
    exit;
}

/**
 * データを変更する操作は管理者のみに許可する
 * 画面のボタンを隠すだけでは、URLやAPIを直接呼ばれた場合に防げないため、
 * 保存・削除を行う処理そのものの入口で必ず確認する。
 * ※日報提出・交通費申請など、一般社員が正当に行う操作には使わないこと
 * @param bool $json JSONを返すAPIならtrue（画面用HTMLではなくJSONで返す）
 */
function requireAdminWrite(bool $json = false): void {
    if (isAdmin()) return;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'この操作を行う権限がありません'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'この操作を行う権限がありません';
    }
    exit;
}

/**
 * 現在のユーザーのemployee_idを取得
 */
function getSessionEmployeeId(): ?int {
    return $_SESSION['employee_id'] ?? null;
}

/**
 * ログイン中の社員名を取得（employeesテーブルから）
 * 管理者の場合はnullを返す（全データ閲覧可）
 */
function getEmployeeNameFilter(): ?string {
    if (isAdmin()) {
        return null;
    }
    $empId = getSessionEmployeeId();
    if (!$empId) {
        return $_SESSION['display_name'] ?? null;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM employees WHERE id = ?");
    $stmt->execute([$empId]);
    $name = $stmt->fetchColumn();
    return $name ?: ($_SESSION['display_name'] ?? null);
}

/**
 * 一般画面用: ログインユーザー自身のemployee_nameを取得（ロール問わず）
 * 会社管理者が一般画面を利用する場合にも自分のデータを参照できるようにする
 */
function getMyEmployeeName(): ?string {
    $empId = getSessionEmployeeId();
    if ($empId) {
        $db = getDB();
        $stmt = $db->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->execute([$empId]);
        $name = $stmt->fetchColumn();
        if ($name) return $name;
    }
    return $_SESSION['display_name'] ?? null;
}

// ============================================================
// マルチテナント データアクセス検証
// ============================================================

/**
 * 社員が自社に属しているか検証。属していなければ403で停止。
 * SA（super_admin）は全社アクセス可。
 */
function verifyEmployeeAccess(int $employeeId): array {
    $cid = getCompanyId();
    $db  = getDB();
    $sql = 'SELECT id, name, company_id FROM employees WHERE id = ?';
    $params = [$employeeId];
    if ($cid) {
        $sql .= ' AND company_id = ?';
        $params[] = $cid;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $emp = $stmt->fetch();
    if (!$emp) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
        echo '<h1>403 アクセス権限がありません</h1>';
        echo '<p>この社員データにアクセスする権限がありません。</p>';
        echo '<a href="' . BASE_PATH . '/public/index.php">ダッシュボードへ戻る</a>';
        echo '</body></html>';
        exit;
    }
    return $emp;
}

/**
 * company_idスコープ付きSQLのWHERE句を生成するヘルパー
 * 使い方: [$where, $params] = companyScope('e.company_id');
 *         $sql .= $where;  $params = array_merge($params, $scopeParams);
 */
function companyScope(string $column = 'company_id'): array {
    $cid = getCompanyId();
    if ($cid) {
        return [" AND {$column} = ?", [$cid]];
    }
    return ['', []];
}

/**
 * 会社IDが必須のページで、会社IDがない（SA直アクセス）場合にブロック
 */
function requireCompanyId(): int {
    $cid = getCompanyId();
    if (!$cid) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>会社未選択</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
        echo '<h1>会社が選択されていません</h1>';
        echo '<p>この機能は会社管理者としてログインしてください。</p>';
        echo '<a href="' . BASE_PATH . '/admin/companies.php">会社管理へ</a>';
        echo '</body></html>';
        exit;
    }
    return $cid;
}
