<?php
// ============================================================
// 評価システム ヘルパー関数
// ============================================================

// ----------------------------------------------------------------
// 評価期間
// ----------------------------------------------------------------
function getEvalPeriods(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM eval_periods';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' WHERE company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY fiscal_year DESC, half DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getEvalPeriod(int $id, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT * FROM eval_periods WHERE id = ?';
    $params = [$id];
    if ($companyId !== null) {
        $sql .= ' AND company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getActivePeriod(?int $companyId = null): array|false {
    $db = getDB();
    $sql = "SELECT * FROM eval_periods WHERE status NOT IN ('draft','closed')";
    $params = [];
    if ($companyId !== null) {
        $sql .= ' AND company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY start_date DESC LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// 部署別ウェイト
// ----------------------------------------------------------------
function getAxisWeights(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM eval_axis_weights';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' WHERE company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY sort_order, department_label';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAxisWeight(int $companyId, string $departmentKey): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM eval_axis_weights WHERE company_id = ? AND department_key = ?');
    $stmt->execute([$companyId, $departmentKey]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// 評価項目テンプレート
// ----------------------------------------------------------------
function getPerformanceItems(int $periodId, ?string $departmentKey = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM eval_performance_items WHERE period_id = ? AND (department_key IS NULL OR department_key = ?)';
    $stmt = $db->prepare($sql);
    $stmt->execute([$periodId, $departmentKey]);
    return $stmt->fetchAll();
}

function getActionItems(int $periodId, ?string $departmentKey = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM eval_action_items WHERE period_id = ? AND (department_key IS NULL OR department_key = ?)';
    $stmt = $db->prepare($sql);
    $stmt->execute([$periodId, $departmentKey]);
    return $stmt->fetchAll();
}

function getCompetencyItems(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM eval_competency_items';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' WHERE company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY sort_order, name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// 評価シート
// ----------------------------------------------------------------
function getEvalSheet(int $id, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT es.*, e.name AS employee_name, e.employee_number, e.department,
                   ev.name AS evaluator_name, ep.name AS period_name, ep.status AS period_status
            FROM eval_sheets es
            JOIN employees e ON es.employee_id = e.id
            LEFT JOIN employees ev ON es.evaluator_id = ev.id
            JOIN eval_periods ep ON es.period_id = ep.id
            WHERE es.id = ?';
    $params = [$id];
    if ($companyId !== null) {
        $sql .= ' AND es.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getEvalSheetByEmployee(int $periodId, int $employeeId, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT es.*, ep.name AS period_name, ep.status AS period_status
         FROM eval_sheets es
         JOIN eval_periods ep ON es.period_id = ep.id
         WHERE es.period_id = ? AND es.employee_id = ?';
    $params = [$periodId, $employeeId];
    if ($companyId) {
        $sql .= ' AND es.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getEvalSheetsForPeriod(int $periodId, ?int $companyId = null, ?string $status = null): array {
    $db = getDB();
    $sql = 'SELECT es.*, e.name AS employee_name, e.employee_number, e.department,
                   ev.name AS evaluator_name
            FROM eval_sheets es
            JOIN employees e ON es.employee_id = e.id
            LEFT JOIN employees ev ON es.evaluator_id = ev.id
            WHERE es.period_id = ?';
    $params = [$periodId];
    if ($companyId !== null) {
        $sql .= ' AND es.company_id = ?';
        $params[] = $companyId;
    }
    if ($status) {
        $sql .= ' AND es.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY e.employee_number';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSubordinateSheets(int $periodId, int $evaluatorId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT es.*, e.name AS employee_name, e.employee_number, e.department
         FROM eval_sheets es
         JOIN employees e ON es.employee_id = e.id
         WHERE es.period_id = ? AND es.evaluator_id = ?
         ORDER BY e.employee_number'
    );
    $stmt->execute([$periodId, $evaluatorId]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// スコア取得
// ----------------------------------------------------------------
function getPerformanceScores(int $sheetId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT eps.*, epi.name AS item_name, epi.unit, epi.weight
         FROM eval_performance_scores eps
         JOIN eval_performance_items epi ON eps.item_id = epi.id
         WHERE eps.sheet_id = ?
         ORDER BY epi.sort_order'
    );
    $stmt->execute([$sheetId]);
    return $stmt->fetchAll();
}

function getActionScores(int $sheetId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT eas.*, eai.name AS item_name, eai.target_value AS item_target, eai.target_unit, eai.frequency, eai.weight
         FROM eval_action_scores eas
         JOIN eval_action_items eai ON eas.item_id = eai.id
         WHERE eas.sheet_id = ?
         ORDER BY eai.sort_order'
    );
    $stmt->execute([$sheetId]);
    return $stmt->fetchAll();
}

function getCompetencyScores(int $sheetId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT ecs.*, eci.name AS item_name, eci.description, eci.weight,
                eci.level1_desc, eci.level2_desc, eci.level3_desc, eci.level4_desc, eci.level5_desc
         FROM eval_competency_scores ecs
         JOIN eval_competency_items eci ON ecs.item_id = eci.id
         WHERE ecs.sheet_id = ?
         ORDER BY eci.sort_order'
    );
    $stmt->execute([$sheetId]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// 褒めポイント
// ----------------------------------------------------------------
function getPraisePoints(int $employeeId, ?string $from = null, ?string $to = null, ?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT pp.*, a.name AS author_name
            FROM praise_points pp
            JOIN employees a ON pp.author_id = a.id
            WHERE pp.employee_id = ?';
    $params = [$employeeId];
    if ($from) {
        $sql .= ' AND pp.praised_date >= ?';
        $params[] = $from;
    }
    if ($to) {
        $sql .= ' AND pp.praised_date <= ?';
        $params[] = $to;
    }
    if ($companyId !== null) {
        $sql .= ' AND pp.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY pp.praised_date DESC, pp.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getRecentPraise(?int $companyId = null, int $limit = 20): array {
    $db = getDB();
    $sql = 'SELECT pp.*, e.name AS employee_name,
                   COALESCE(a.name, u.display_name, "管理者") AS author_name
            FROM praise_points pp
            JOIN employees e ON pp.employee_id = e.id
            LEFT JOIN employees a ON pp.author_id = a.id
            LEFT JOIN users u ON pp.author_id = u.id AND a.id IS NULL';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' WHERE pp.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY pp.created_at DESC LIMIT ' . (int)$limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// 研修
// ----------------------------------------------------------------
function getTrainingCatalog(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT * FROM training_catalog WHERE is_active = 1';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' AND company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY category, name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTrainingRules(?int $companyId = null): array {
    $db = getDB();
    $sql = 'SELECT tr.*, tc.name AS training_name, tc.category
            FROM training_rules tr
            JOIN training_catalog tc ON tr.training_id = tc.id
            WHERE tr.is_active = 1';
    $params = [];
    if ($companyId !== null) {
        $sql .= ' AND tr.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY tr.priority DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTrainingRecommendations(int $sheetId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT trec.*, tc.name AS training_name, tc.description, tc.category, tc.duration_hours, tc.url
         FROM training_recommendations trec
         JOIN training_catalog tc ON trec.training_id = tc.id
         WHERE trec.sheet_id = ?
         ORDER BY trec.created_at'
    );
    $stmt->execute([$sheetId]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// スコア計算
// ----------------------------------------------------------------
function calculateWeightedScore(array $sheet, array $weights): array {
    $wp = ($weights['weight_performance'] ?? 40) / 100;
    $wa = ($weights['weight_action'] ?? 40) / 100;
    $wc = ($weights['weight_competency'] ?? 20) / 100;

    $result = [];
    foreach (['self', 'primary', 'final'] as $prefix) {
        $sp = $sheet[$prefix . '_score_performance'] ?? null;
        $sa = $sheet[$prefix . '_score_action'] ?? null;
        $sc = $sheet[$prefix . '_score_competency'] ?? null;

        if ($sp !== null && $sa !== null && $sc !== null) {
            $result[$prefix . '_total'] = round($sp * $wp + $sa * $wa + $sc * $wc, 2);
        }
    }
    return $result;
}

function getEvalGrade(float $score): string {
    if ($score >= 90) return 'S';
    if ($score >= 80) return 'A';
    if ($score >= 60) return 'B';
    if ($score >= 40) return 'C';
    return 'D';
}

function getGradeBadgeClass(string $grade): string {
    return match ($grade) {
        'S' => 'bg-danger',
        'A' => 'bg-primary',
        'B' => 'bg-success',
        'C' => 'bg-warning text-dark',
        'D' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}

// ----------------------------------------------------------------
// 評価シート一括生成
// ----------------------------------------------------------------
function generateEvalSheets(int $periodId, int $companyId): int {
    $db = getDB();
    $period = getEvalPeriod($periodId, $companyId);
    if (!$period) return 0;

    // 会社の全アクティブ社員を取得
    $employees = getAllEmployees(true, $companyId);
    $count = 0;

    foreach ($employees as $emp) {
        // 既存シートがあればスキップ
        $existing = $db->prepare('SELECT id FROM eval_sheets WHERE period_id = ? AND employee_id = ?');
        $existing->execute([$periodId, $emp['id']]);
        if ($existing->fetch()) continue;

        // 評価者を決定（チームのマネージャー）
        $evaluatorId = null;
        $tmStmt = $db->prepare(
            'SELECT t.manager_id FROM team_members tm JOIN teams t ON tm.team_id = t.id WHERE tm.employee_id = ? AND t.company_id = ? LIMIT 1'
        );
        $tmStmt->execute([$emp['id'], $companyId]);
        $tm = $tmStmt->fetch();
        if ($tm) $evaluatorId = $tm['manager_id'];

        // department_key: 部署名を正規化してキーに
        $deptKey = $emp['department'] ? mb_convert_kana(trim($emp['department']), 'as') : 'general';
        $deptKey = preg_replace('/[^a-zA-Z0-9\x{3000}-\x{9FFF}]/u', '_', $deptKey);

        $stmt = $db->prepare(
            'INSERT INTO eval_sheets (company_id, period_id, employee_id, evaluator_id, department_key)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $periodId, $emp['id'], $evaluatorId, $deptKey]);
        $sheetId = (int)$db->lastInsertId();

        // 業績項目のスコアレコード生成
        $perfItems = getPerformanceItems($periodId, $deptKey);
        foreach ($perfItems as $item) {
            $db->prepare('INSERT INTO eval_performance_scores (sheet_id, item_id) VALUES (?, ?)')->execute([$sheetId, $item['id']]);
        }

        // 行動項目のスコアレコード生成
        $actItems = getActionItems($periodId, $deptKey);
        foreach ($actItems as $item) {
            $db->prepare('INSERT INTO eval_action_scores (sheet_id, item_id) VALUES (?, ?)')->execute([$sheetId, $item['id']]);
        }

        // コンピテンシー項目のスコアレコード生成
        $compItems = getCompetencyItems($companyId);
        foreach ($compItems as $item) {
            $db->prepare('INSERT INTO eval_competency_scores (sheet_id, item_id) VALUES (?, ?)')->execute([$sheetId, $item['id']]);
        }

        $count++;
    }
    return $count;
}

// ----------------------------------------------------------------
// 研修自動推奨
// ----------------------------------------------------------------
function generateTrainingRecommendations(int $sheetId): int {
    $db = getDB();
    $sheet = getEvalSheet($sheetId);
    if (!$sheet) return 0;

    $rules = getTrainingRules($sheet['company_id']);
    $count = 0;

    foreach ($rules as $rule) {
        $triggered = false;
        $reason = '';

        if ($rule['trigger_axis'] === 'performance') {
            $score = $sheet['final_score_performance'] ?? $sheet['primary_score_performance'];
            if ($score !== null && $score <= $rule['threshold_value']) {
                $triggered = true;
                $reason = "業績スコア({$score})が閾値({$rule['threshold_value']})以下";
            }
        } elseif ($rule['trigger_axis'] === 'action') {
            $score = $sheet['final_score_action'] ?? $sheet['primary_score_action'];
            if ($score !== null && $score <= $rule['threshold_value']) {
                $triggered = true;
                $reason = "行動スコア({$score})が閾値({$rule['threshold_value']})以下";
            }
        } elseif ($rule['trigger_axis'] === 'competency') {
            $score = $sheet['final_score_competency'] ?? $sheet['primary_score_competency'];
            if ($score !== null && $score <= $rule['threshold_value']) {
                $triggered = true;
                $reason = "コンピテンシースコア({$score})が閾値({$rule['threshold_value']})以下";
            }
        }

        if ($triggered) {
            // 重複チェック
            $dup = $db->prepare('SELECT id FROM training_recommendations WHERE sheet_id = ? AND training_id = ?');
            $dup->execute([$sheetId, $rule['training_id']]);
            if (!$dup->fetch()) {
                $db->prepare('INSERT INTO training_recommendations (sheet_id, training_id, rule_id, reason) VALUES (?,?,?,?)')
                   ->execute([$sheetId, $rule['training_id'], $rule['id'], $reason]);
                $count++;
            }
        }
    }
    return $count;
}

// ----------------------------------------------------------------
// 評価ステータスラベル
// ----------------------------------------------------------------
function getEvalStatusLabel(string $status): array {
    return match ($status) {
        'draft'             => ['label' => '下書き', 'class' => 'bg-secondary'],
        'open'              => ['label' => '公開中', 'class' => 'bg-primary'],
        'self_eval'         => ['label' => '自己評価中', 'class' => 'bg-info'],
        'primary_eval'      => ['label' => '1次評価中', 'class' => 'bg-warning text-dark'],
        'adjustment'        => ['label' => '調整中', 'class' => 'bg-purple'],
        'feedback'          => ['label' => 'FB中', 'class' => 'bg-success'],
        'closed'            => ['label' => '完了', 'class' => 'bg-dark'],
        'self_submitted'    => ['label' => '自己提出済', 'class' => 'bg-info'],
        'primary_submitted' => ['label' => '1次提出済', 'class' => 'bg-warning text-dark'],
        'adjusted'          => ['label' => '調整済', 'class' => 'bg-success'],
        'feedback_done'     => ['label' => 'FB完了', 'class' => 'bg-dark'],
        default             => ['label' => $status, 'class' => 'bg-light text-dark'],
    };
}

function getPraiseCategories(): array {
    return [
        'teamwork'   => ['label' => 'チームワーク', 'icon' => 'bi-people', 'color' => '#3498db'],
        'initiative' => ['label' => '主体性', 'icon' => 'bi-lightning', 'color' => '#e74c3c'],
        'quality'    => ['label' => '品質', 'icon' => 'bi-check-circle', 'color' => '#27ae60'],
        'growth'     => ['label' => '成長', 'icon' => 'bi-graph-up', 'color' => '#9b59b6'],
        'other'      => ['label' => 'その他', 'icon' => 'bi-star', 'color' => '#f39c12'],
    ];
}

// ----------------------------------------------------------------
// 等級テーブル（号俸制）
// ----------------------------------------------------------------
function getGradeTable(int $companyId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM grade_table WHERE company_id = ? ORDER BY sort_order, grade_rank, step'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getGradeTableGrouped(int $companyId): array {
    $rows = getGradeTable($companyId);
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['grade_rank']]['label'] = $r['grade_label'];
        $grouped[$r['grade_rank']]['steps'][$r['step']] = $r['base_salary'];
        $grouped[$r['grade_rank']]['sort_order'] = $r['sort_order'];
    }
    return $grouped;
}

function getGradeSalary(int $companyId, string $gradeRank, int $step): ?int {
    $db = getDB();
    $stmt = $db->prepare('SELECT base_salary FROM grade_table WHERE company_id = ? AND grade_rank = ? AND step = ?');
    $stmt->execute([$companyId, $gradeRank, $step]);
    $r = $stmt->fetch();
    return $r ? (int)$r['base_salary'] : null;
}

// ----------------------------------------------------------------
// 社員の現在等級
// ----------------------------------------------------------------
function getEmployeeCurrentGrade(int $employeeId): array|false {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT eg.*, gt.grade_label, gt.base_salary
         FROM employee_grades eg
         LEFT JOIN grade_table gt ON eg.company_id = gt.company_id AND eg.grade_rank = gt.grade_rank AND eg.step = gt.step
         WHERE eg.employee_id = ?
         ORDER BY eg.effective_date DESC, eg.id DESC LIMIT 1'
    );
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// 昇給・賞与ルール
// ----------------------------------------------------------------
function getSalaryRules(int $companyId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM salary_rules WHERE company_id = ? ORDER BY FIELD(eval_grade,"S","A","B","C","D")');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getSalaryRuleByGrade(int $companyId, string $evalGrade): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM salary_rules WHERE company_id = ? AND eval_grade = ?');
    $stmt->execute([$companyId, $evalGrade]);
    return $stmt->fetch();
}

function getBonusSettings(int $companyId, ?int $periodId = null): array|false {
    $db = getDB();
    if ($periodId) {
        $stmt = $db->prepare('SELECT * FROM bonus_settings WHERE company_id = ? AND period_id = ? LIMIT 1');
        $stmt->execute([$companyId, $periodId]);
        $r = $stmt->fetch();
        if ($r) return $r;
    }
    $stmt = $db->prepare('SELECT * FROM bonus_settings WHERE company_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$companyId]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// 給与シミュレーション計算
// ----------------------------------------------------------------
function simulateSalary(int $companyId, int $employeeId, string $evalGrade, ?int $periodId = null): array {
    $currentGrade = getEmployeeCurrentGrade($employeeId);
    $rule = getSalaryRuleByGrade($companyId, $evalGrade);
    $bonus = getBonusSettings($companyId, $periodId);
    $gradeTable = getGradeTableGrouped($companyId);

    $result = [
        'employee_id' => $employeeId,
        'eval_grade' => $evalGrade,
        'current_grade_rank' => $currentGrade['grade_rank'] ?? null,
        'current_step' => $currentGrade ? (int)$currentGrade['step'] : null,
        'current_salary' => $currentGrade ? (int)$currentGrade['base_salary'] : null,
        'new_grade_rank' => null,
        'new_step' => null,
        'new_salary' => null,
        'salary_diff' => null,
        'bonus_base' => null,
        'bonus_coefficient' => $rule ? (float)$rule['bonus_coefficient'] : 1.0,
        'bonus_amount' => null,
        'rule_description' => $rule['description'] ?? '',
    ];

    if (!$currentGrade || !$rule) return $result;

    $curRank = $currentGrade['grade_rank'];
    $curStep = (int)$currentGrade['step'];
    $stepChange = (int)$rule['step_change'];

    // 新しい号俸を計算
    $newStep = $curStep + $stepChange;
    $newRank = $curRank;

    // 号俸が範囲外なら等級変動
    $gradeRanks = array_keys($gradeTable);
    $curIdx = array_search($curRank, $gradeRanks);

    if (isset($gradeTable[$curRank])) {
        $maxStep = max(array_keys($gradeTable[$curRank]['steps']));
        $minStep = min(array_keys($gradeTable[$curRank]['steps']));

        if ($newStep > $maxStep) {
            // 上の等級へ昇格
            if ($curIdx !== false && isset($gradeRanks[$curIdx + 1])) {
                $newRank = $gradeRanks[$curIdx + 1];
                $newStep = 1; // 新等級の1号俸から
            } else {
                $newStep = $maxStep; // 最高等級の場合は最大号俸
            }
        } elseif ($newStep < $minStep) {
            // 下の等級へ降格（C/D評価で下限の場合）
            if ($curIdx !== false && $curIdx > 0 && isset($gradeRanks[$curIdx - 1])) {
                $newRank = $gradeRanks[$curIdx - 1];
                $prevMax = max(array_keys($gradeTable[$newRank]['steps']));
                $newStep = $prevMax;
            } else {
                $newStep = $minStep; // 最低等級の場合は最小号俸
            }
        }
    }

    $result['new_grade_rank'] = $newRank;
    $result['new_step'] = $newStep;
    $result['new_salary'] = getGradeSalary($companyId, $newRank, $newStep);
    $result['salary_diff'] = ($result['new_salary'] !== null && $result['current_salary'] !== null)
        ? $result['new_salary'] - $result['current_salary'] : null;

    // 賞与計算: 基本給 × 基準月数 × 業績評価係数 × 会社業績指数
    if ($bonus && $result['current_salary']) {
        $baseSalary = $result['current_salary'];
        $baseMonths = (float)$bonus['base_months'];
        $compIdx = (float)$bonus['company_performance_index'];
        $coeff = (float)$rule['bonus_coefficient'];
        $minGuarantee = (float)$bonus['min_guarantee_rate'];

        $bonusBase = (int)round($baseSalary * $baseMonths);
        $bonusAmount = (int)round($bonusBase * $coeff * $compIdx);
        $minAmount = (int)round($bonusBase * $minGuarantee);
        $bonusAmount = max($bonusAmount, $minAmount);

        $result['bonus_base'] = $bonusBase;
        $result['bonus_amount'] = $bonusAmount;
    }

    return $result;
}

// 全社員の一括シミュレーション
function simulateAllSalaries(int $companyId, int $periodId): array {
    $db = getDB();
    $sheets = getEvalSheetsForPeriod($periodId, $companyId);
    $results = [];
    $totalCurrentSalary = 0;
    $totalNewSalary = 0;
    $totalBonus = 0;

    foreach ($sheets as $sheet) {
        $evalGrade = $sheet['final_grade'] ?: getEvalGrade((float)($sheet['final_score_total'] ?? $sheet['primary_score_total'] ?? 0));
        $sim = simulateSalary($companyId, $sheet['employee_id'], $evalGrade, $periodId);
        $sim['employee_name'] = $sheet['employee_name'];
        $sim['employee_number'] = $sheet['employee_number'];
        $sim['department'] = $sheet['department'] ?? '';
        $sim['sheet_id'] = $sheet['id'];
        $sim['score_total'] = $sheet['final_score_total'] ?? $sheet['primary_score_total'];
        $results[] = $sim;

        if ($sim['current_salary']) $totalCurrentSalary += $sim['current_salary'];
        if ($sim['new_salary']) $totalNewSalary += $sim['new_salary'];
        if ($sim['bonus_amount']) $totalBonus += $sim['bonus_amount'];
    }

    return [
        'employees' => $results,
        'summary' => [
            'total_current_monthly' => $totalCurrentSalary,
            'total_new_monthly' => $totalNewSalary,
            'monthly_diff' => $totalNewSalary - $totalCurrentSalary,
            'annual_salary_impact' => ($totalNewSalary - $totalCurrentSalary) * 12,
            'total_bonus' => $totalBonus,
            'employee_count' => count($results),
        ],
    ];
}

// ----------------------------------------------------------------
// 面談メモ
// ----------------------------------------------------------------
function getInterviewNotes(int $employeeId, ?int $companyId = null, ?int $limit = null): array {
    $db = getDB();
    $sql = 'SELECT n.*, COALESCE(i.name, u.display_name, "管理者") AS interviewer_name
            FROM interview_notes n
            LEFT JOIN employees i ON n.interviewer_id = i.id
            LEFT JOIN users u ON n.interviewer_id = u.id AND i.id IS NULL
            WHERE n.employee_id = ?';
    $params = [$employeeId];
    // company_idフィルタは常に適用（NULLの場合はSAなので全社アクセス可）
    if ($companyId !== null) {
        $sql .= ' AND n.company_id = ?';
        $params[] = $companyId;
    } elseif (!isSuperAdmin()) {
        // SAでもなくcompany_idもない場合は空を返す（安全策）
        return [];
    }
    $sql .= ' ORDER BY n.interview_date DESC, n.id DESC';
    if ($limit) $sql .= ' LIMIT ' . (int)$limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getInterviewNote(int $id, ?int $companyId = null): array|false {
    $db = getDB();
    $sql = 'SELECT n.*, COALESCE(i.name, u.display_name, "管理者") AS interviewer_name, e.name AS employee_name
            FROM interview_notes n
            LEFT JOIN employees i ON n.interviewer_id = i.id
            LEFT JOIN users u ON n.interviewer_id = u.id AND i.id IS NULL
            JOIN employees e ON n.employee_id = e.id
            WHERE n.id = ?';
    $params = [$id];
    if ($companyId !== null) {
        $sql .= ' AND n.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getNoteTypeLabel(string $type): string {
    return match ($type) {
        'one_on_one' => '1on1',
        'mid_term'   => '中間面談',
        'end_term'   => '期末面談',
        'career'     => 'キャリア面談',
        'other'      => 'その他',
        default      => $type,
    };
}

function getMoodLabel(int $mood): array {
    return match ($mood) {
        5 => ['label' => '非常に前向き', 'icon' => 'bi-emoji-laughing', 'color' => '#27ae60'],
        4 => ['label' => '前向き', 'icon' => 'bi-emoji-smile', 'color' => '#2ecc71'],
        3 => ['label' => '普通', 'icon' => 'bi-emoji-neutral', 'color' => '#f39c12'],
        2 => ['label' => 'やや不安', 'icon' => 'bi-emoji-frown', 'color' => '#e67e22'],
        1 => ['label' => '不安・不満', 'icon' => 'bi-emoji-angry', 'color' => '#e74c3c'],
        default => ['label' => '未記録', 'icon' => 'bi-dash', 'color' => '#95a5a6'],
    };
}

// ----------------------------------------------------------------
// 研修割当
// ----------------------------------------------------------------
function getTrainingAssignments(int $employeeId, ?int $companyId = null, ?string $status = null): array {
    $db = getDB();
    $sql = 'SELECT ta.*, tc.name AS training_name, tc.category, tc.duration_hours, tc.url,
                   ab.name AS assigned_by_name
            FROM training_assignments ta
            JOIN training_catalog tc ON ta.training_id = tc.id
            JOIN employees ab ON ta.assigned_by = ab.id
            WHERE ta.employee_id = ?';
    $params = [$employeeId];
    if ($companyId) {
        $sql .= ' AND ta.company_id = ?';
        $params[] = $companyId;
    }
    if ($status) {
        $sql .= ' AND ta.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY ta.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function assignTraining(int $companyId, int $employeeId, int $trainingId, int $assignedBy, string $reason = '', ?int $noteId = null, ?int $sheetId = null): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO training_assignments (company_id, employee_id, training_id, assigned_by, reason, interview_note_id, sheet_id) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$companyId, $employeeId, $trainingId, $assignedBy, $reason, $noteId, $sheetId]);
    return (int)$db->lastInsertId();
}
