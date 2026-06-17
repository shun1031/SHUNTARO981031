<?php
// ============================================================
// 共通ヘルパー関数
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/eval_functions.php';
require_once __DIR__ . '/assessment_functions.php';
require_once __DIR__ . '/sales_functions.php';

// ----------------------------------------------------------------
// セッション
// ----------------------------------------------------------------
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // セッションタイムアウト（最終アクティビティから2時間）
        if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > SESSION_LIFETIME) {
            $_SESSION = [];
            session_destroy();
            // 新しいセッションを再作成（古いCookieは無効化）
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
            session_regenerate_id(true);
        }
        $_SESSION['_last_activity'] = time();
    }
}

function isLoggedIn(): bool {
    startSession();
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    requireAnyLogin();
}

// ----------------------------------------------------------------
// XSS対策
// ----------------------------------------------------------------
function h(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ----------------------------------------------------------------
// 社員関連
// ----------------------------------------------------------------
function getAllEmployees(bool $activeOnly = true, ?int $companyId = null): array {
    $db = getDB();
    $conditions = [];
    $params = [];

    if ($activeOnly) {
        $conditions[] = 'e.is_active = 1';
    }
    if ($companyId !== null) {
        $conditions[] = 'e.company_id = ?';
        $params[] = $companyId;
    }

    $sql = 'SELECT e.*, t.name AS team_name
            FROM employees e
            LEFT JOIN team_members tm ON e.id = tm.employee_id
            LEFT JOIN teams t ON tm.team_id = t.id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY e.employee_number';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getEmployee(int $id, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT e.*, t.name AS team_name, t.id AS team_id
            FROM employees e
            LEFT JOIN team_members tm ON e.id = tm.employee_id
            LEFT JOIN teams t ON tm.team_id = t.id
            WHERE e.id = ?';
    $params = [$id];
    if ($companyId !== null) {
        $sql .= ' AND e.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getEmployeeCareer(int $employeeId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM career_history WHERE employee_id = ? ORDER BY start_year DESC, start_month DESC'
    );
    $stmt->execute([$employeeId]);
    return $stmt->fetchAll();
}

function getStrengthsFinder(int $employeeId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM strengths_finder WHERE employee_id = ?');
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

function getSpiResult(int $employeeId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM spi_results WHERE employee_id = ?');
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// チーム関連
// ----------------------------------------------------------------
function getAllTeams(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT t.*,
                   m.name AS manager_name,
                   sm.name AS sub_manager_name,
                   COUNT(tm.employee_id) AS member_count
            FROM teams t
            LEFT JOIN employees m ON t.manager_id = m.id
            LEFT JOIN employees sm ON t.sub_manager_id = sm.id
            LEFT JOIN team_members tm ON t.id = tm.team_id';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' WHERE t.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' GROUP BY t.id ORDER BY t.sort_order, t.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTeam(int $id, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT t.*,
                    m.name AS manager_name,
                    sm.name AS sub_manager_name
             FROM teams t
             LEFT JOIN employees m ON t.manager_id = m.id
             LEFT JOIN employees sm ON t.sub_manager_id = sm.id
             WHERE t.id = ?';
    $params = [$id];
    if ($companyId !== null) {
        $sql .= ' AND t.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getTeamMembers(int $teamId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT e.*, sf.achiever, sf.strategic, sf.learner, sf.maximizer,
                sf.relator, sf.futuristic, sf.analytical, sf.top5_text,
                sp.leadership, sp.teamwork, sp.problem_solving
         FROM employees e
         JOIN team_members tm ON e.id = tm.employee_id
         LEFT JOIN strengths_finder sf ON e.id = sf.employee_id
         LEFT JOIN spi_results sp ON e.id = sp.employee_id
         WHERE tm.team_id = ? AND e.is_active = 1
         ORDER BY e.name'
    );
    $stmt->execute([$teamId]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// 会社関連
// ----------------------------------------------------------------
function getAllCompanies(): array {
    $db = getDB();
    return $db->query('SELECT * FROM companies ORDER BY id')->fetchAll();
}

function getCompany(int $id): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getCompanyName(?int $companyId): string {
    if (!$companyId) return 'システム管理';
    $company = getCompany($companyId);
    return $company ? $company['company_name'] : '';
}

// ----------------------------------------------------------------
// ストレングスファインダー
// ----------------------------------------------------------------
function getStrengthsThemeDefinitions(): array {
    return [
        'achiever'         => ['ja' => '達成欲',   'domain' => '実行力',   'desc' => '勤勉で常に達成感を追い求める。目標達成のために懸命に働く。'],
        'activator'        => ['ja' => '活発性',   'domain' => '影響力',   'desc' => 'アイデアを行動に変える。今すぐ始めることを重視する。'],
        'adaptability'     => ['ja' => '適応性',   'domain' => '人間関係力', 'desc' => '今この瞬間を大切にし、変化を自然に受け入れる。'],
        'analytical'       => ['ja' => '分析思考', 'domain' => '戦略的思考力', 'desc' => 'データや証拠を求め、論理的な根拠を重視する。'],
        'arranger'         => ['ja' => '指令性',   'domain' => '実行力',   'desc' => '複数の要素をうまく調整し、最高の成果を追求する。'],
        'belief'           => ['ja' => '信念',     'domain' => '実行力',   'desc' => '変わることのない価値観を持ち、それを中心に生きる。'],
        'command'          => ['ja' => '自我',     'domain' => '影響力',   'desc' => '存在感が強く、他者を主導し方向性を示す。'],
        'communication'    => ['ja' => 'コミュニケーション', 'domain' => '影響力', 'desc' => 'アイデアをわかりやすく伝えることが得意。'],
        'competition'      => ['ja' => '競争性',   'domain' => '影響力',   'desc' => '他者と比較することでモチベーションを高める。'],
        'connectedness'    => ['ja' => '運命思考', 'domain' => '人間関係力', 'desc' => 'すべてのことはつながっていると信じる。'],
        'consistency'      => ['ja' => '公平性',   'domain' => '実行力',   'desc' => '一貫したルールや公平性を重視する。'],
        'context'          => ['ja' => '原点思考', 'domain' => '戦略的思考力', 'desc' => '過去を振り返ることで現在を理解しようとする。'],
        'deliberative'     => ['ja' => '慎重さ',   'domain' => '実行力',   'desc' => 'リスクを見極め、慎重に意思決定する。'],
        'developer'        => ['ja' => '成長促進', 'domain' => '人間関係力', 'desc' => '他者の可能性を見出し、成長を助ける。'],
        'discipline'       => ['ja' => '規律性',   'domain' => '実行力',   'desc' => '秩序と構造を求め、計画的に行動する。'],
        'empathy'          => ['ja' => '共感性',   'domain' => '人間関係力', 'desc' => '他者の感情を感じ取り、気持ちを理解する。'],
        'focus'            => ['ja' => 'フォーカス', 'domain' => '実行力',  'desc' => '目標に向けてエネルギーを集中させる。'],
        'futuristic'       => ['ja' => '未来志向', 'domain' => '戦略的思考力', 'desc' => '未来を鮮明にイメージし、ビジョンを描く。'],
        'harmony'          => ['ja' => '調和性',   'domain' => '人間関係力', 'desc' => '意見の一致点を見つけ、対立を避ける。'],
        'ideation'         => ['ja' => '着想',     'domain' => '戦略的思考力', 'desc' => '新しいアイデアを生み出すことに喜びを感じる。'],
        'includer'         => ['ja' => '包含',     'domain' => '人間関係力', 'desc' => '誰も排除せず、すべての人を受け入れる。'],
        'individualization'=> ['ja' => '個別化',   'domain' => '人間関係力', 'desc' => '個人の違いを見抜き、それぞれに合ったアプローチを取る。'],
        'input'            => ['ja' => '収集心',   'domain' => '戦略的思考力', 'desc' => '情報やモノを集めることに喜びを感じる。'],
        'intellection'     => ['ja' => '内省',     'domain' => '戦略的思考力', 'desc' => '深く考え、思索することを好む。'],
        'learner'          => ['ja' => '学習欲',   'domain' => '戦略的思考力', 'desc' => '学ぶこと自体に喜びを感じ、常に成長し続ける。'],
        'maximizer'        => ['ja' => '最上志向', 'domain' => '影響力',   'desc' => '優れた部分をさらに磨き上げ、卓越した成果を追求する。'],
        'positivity'       => ['ja' => 'ポジティブ', 'domain' => '人間関係力', 'desc' => '前向きな雰囲気を作り出し、周囲を明るくする。'],
        'relator'          => ['ja' => '親密性',   'domain' => '人間関係力', 'desc' => '深い人間関係を築くことに喜びを感じる。'],
        'responsibility'   => ['ja' => '責任感',   'domain' => '実行力',   'desc' => '約束したことを必ず果たす、強い責任感を持つ。'],
        'restorative'      => ['ja' => '回復志向', 'domain' => '実行力',   'desc' => '問題を見つけ、解決することに長けている。'],
        'self_assurance'   => ['ja' => '自己確信', 'domain' => '影響力',   'desc' => '自分の判断を信じ、自信を持って行動する。'],
        'significance'     => ['ja' => '自我',     'domain' => '影響力',   'desc' => '重要な存在として認められたいという強い欲求を持つ。'],
        'strategic'        => ['ja' => '戦略性',   'domain' => '戦略的思考力', 'desc' => '複雑な状況を整理し、最適な道筋を見つける。'],
        'woo'              => ['ja' => '社交性',   'domain' => '影響力',   'desc' => '初対面の人と素早く打ち解け、好意を勝ち取る。'],
    ];
}

function getSpiDimensions(): array {
    return [
        'behavioral' => [
            'label' => '行動的側面',
            'items' => [
                'social_introversion' => '社会的内向性',
                'introspection'       => '内省性',
                'physical_activity'   => '身体活動性',
                'persistence'         => '持続性',
                'caution'             => '慎重性',
            ]
        ],
        'motivational' => [
            'label' => '意欲的側面',
            'items' => [
                'achievement_drive' => '達成意欲',
                'activity_drive'    => '活動意欲',
            ]
        ],
        'emotional' => [
            'label' => '情緒的側面',
            'items' => [
                'sensitivity'       => '敏感性',
                'self_blame'        => '自責性',
                'mood_variation'    => '気分性',
                'uniqueness'        => '独自性',
                'self_confidence'   => '自信性',
                'elation'           => '高揚性',
            ]
        ],
        'social' => [
            'label' => '社会関係的側面',
            'items' => [
                'compliance'   => '従順性',
                'avoidance'    => '回避性',
                'criticism'    => '批判性',
                'self_respect' => '自己尊重性',
                'skepticism'   => '懐疑思考性',
            ]
        ],
        'workplace' => [
            'label' => '職場適応性',
            'items' => [
                'leadership'           => 'リーダーシップ',
                'teamwork'             => 'チームワーク',
                'relationship_building'=> '関係構築力',
                'creative_thinking'    => '創造的思考力',
                'problem_solving'      => '問題解決力',
                'situation_adaptability'=> '状況適応力',
                'ownership'            => '当事者意識',
                'energetic_action'     => '精力的行動力',
            ]
        ],
    ];
}

// ストレングスファインダーのトップ5を返す
function getTop5Strengths(array $sf): array {
    $themes = getStrengthsThemeDefinitions();
    $scores = [];
    foreach (array_keys($themes) as $key) {
        if (isset($sf[$key]) && $sf[$key] !== null) {
            $scores[$key] = (int)$sf[$key];
        }
    }
    asort($scores); // 小さい数字（1位）が上位
    return array_slice($scores, 0, 5, true);
}

// ドメイン別集計
function getDomainSummary(array $sf): array {
    $themes = getStrengthsThemeDefinitions();
    $domains = ['実行力' => 0, '影響力' => 0, '人間関係力' => 0, '戦略的思考力' => 0];
    foreach ($themes as $key => $def) {
        if (isset($sf[$key]) && $sf[$key] !== null && (int)$sf[$key] <= 10) {
            $domains[$def['domain']] = ($domains[$def['domain']] ?? 0) + 1;
        }
    }
    return $domains;
}

// ----------------------------------------------------------------
// ユーティリティ
// ----------------------------------------------------------------
function formatDate(?string $date, string $format = 'Y年n月'): string {
    if (!$date) return '';
    return date($format, strtotime($date));
}

function getYearsOfService(?string $hireDate): string {
    if (!$hireDate) return '';
    $hire = new DateTime($hireDate);
    $now  = new DateTime();
    $diff = $hire->diff($now);
    return $diff->y . '年' . ($diff->m > 0 ? $diff->m . 'ヶ月' : '');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// CSRF トークン
function getCsrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    startSession();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * API用CSRF防御: Origin/Refererヘッダーで同一オリジンを検証
 * SameSite=Strict + このチェックで二重防御
 */
function verifyApiOrigin(): bool {
    $allowedHost = $_SERVER['HTTP_HOST'] ?? '';
    if (!$allowedHost) return false;

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($origin) {
        $parsed = parse_url($origin);
        if (!$parsed || empty($parsed['host'])) return false;
        $originHost = $parsed['host'];
        if (!empty($parsed['port'])) $originHost .= ':' . $parsed['port'];
        return $originHost === $allowedHost;
    }
    if ($referer) {
        $parsed = parse_url($referer);
        if (!$parsed || empty($parsed['host'])) return false;
        $refHost = $parsed['host'];
        if (!empty($parsed['port'])) $refHost .= ':' . $parsed['port'];
        return $refHost === $allowedHost;
    }
    return false;
}

/**
 * API POST リクエストの保護（認証+CSRF）
 */
function requireApiAuth(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $csrfOk = !empty($data['csrf']) && verifyCsrfToken($data['csrf']);
        $headerCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $csrfHeaderOk = $headerCsrf && verifyCsrfToken($headerCsrf);
        $originOk = verifyApiOrigin();

        if (!$csrfOk && !$csrfHeaderOk && !$originOk) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF validation failed']);
            exit;
        }
    }
}
