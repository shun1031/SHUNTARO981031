<?php
// ============================================================
// 認証・認可ヘルパー関数（マルチテナント対応）
// ============================================================

/**
 * 現在ログイン中のユーザー情報を取得
 */
function getCurrentUser(): ?array {
    startSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'          => $_SESSION['user_id'],
        'role'        => $_SESSION['user_role'] ?? '',
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
