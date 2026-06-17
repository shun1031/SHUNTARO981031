<?php
// ============================================================
// SPI受検・SF受検 ヘルパー関数
// ============================================================

// ----------------------------------------------------------------
// 質問取得
// ----------------------------------------------------------------
function getSpiQuestions(): array {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM spi_questions ORDER BY sort_order, id');
    return $stmt->fetchAll();
}

function getSpiQuestionsByCategory(): array {
    $dims = getSpiDimensions();
    $questions = getSpiQuestions();

    $grouped = [];
    foreach ($dims as $catKey => $cat) {
        $grouped[$catKey] = [
            'label' => $cat['label'],
            'questions' => [],
        ];
        $dimKeys = array_keys($cat['items']);
        foreach ($questions as $q) {
            if (in_array($q['dimension_key'], $dimKeys)) {
                $q['dimension_label'] = $cat['items'][$q['dimension_key']] ?? $q['dimension_key'];
                $grouped[$catKey]['questions'][] = $q;
            }
        }
    }
    return $grouped;
}

function getSfQuestions(): array {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM sf_questions ORDER BY sort_order, id');
    return $stmt->fetchAll();
}

function getSfQuestionsByDomain(): array {
    $themes = getStrengthsThemeDefinitions();
    $questions = getSfQuestions();

    // テーマ→ドメインのマッピング
    $themeDomain = [];
    foreach ($themes as $key => $def) {
        $themeDomain[$key] = $def['domain'];
    }

    $domains = ['実行力' => [], '影響力' => [], '人間関係力' => [], '戦略的思考力' => []];
    foreach ($questions as $q) {
        $domain = $themeDomain[$q['theme_key']] ?? '実行力';
        $q['theme_label'] = $themes[$q['theme_key']]['ja'] ?? $q['theme_key'];
        $domains[$domain][] = $q;
    }

    $result = [];
    foreach ($domains as $domLabel => $qs) {
        $result[$domLabel] = [
            'label' => $domLabel,
            'questions' => $qs,
        ];
    }
    return $result;
}

// ----------------------------------------------------------------
// 受検セッション管理
// ----------------------------------------------------------------
function getOrCreateAttempt(string $type, int $employeeId, ?int $companyId = null): array {
    $db = getDB();
    $table = $type === 'spi' ? 'spi_attempts' : 'sf_attempts';

    // 進行中のattemptを探す
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE employee_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    $attempt = $stmt->fetch();
    if ($attempt) return $attempt;

    // なければ新規作成
    $stmt = $db->prepare("INSERT INTO {$table} (company_id, employee_id) VALUES (?, ?)");
    $stmt->execute([$companyId, $employeeId]);
    $id = (int)$db->lastInsertId();

    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getLatestCompletedAttempt(string $type, int $employeeId): array|false {
    $db = getDB();
    $table = $type === 'spi' ? 'spi_attempts' : 'sf_attempts';
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE employee_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// 回答保存・取得
// ----------------------------------------------------------------
function saveAssessmentAnswer(string $type, int $attemptId, int $employeeId, ?int $companyId, int $questionId, int $answer): void {
    $db = getDB();
    $table = $type === 'spi' ? 'spi_answers' : 'sf_answers';
    $stmt = $db->prepare(
        "INSERT INTO {$table} (company_id, employee_id, question_id, answer, attempt_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE answer = VALUES(answer)"
    );
    $stmt->execute([$companyId, $employeeId, $questionId, $answer, $attemptId]);
}

function getAttemptAnswers(string $type, int $attemptId): array {
    $db = getDB();
    $table = $type === 'spi' ? 'spi_answers' : 'sf_answers';
    $stmt = $db->prepare("SELECT question_id, answer FROM {$table} WHERE attempt_id = ?");
    $stmt->execute([$attemptId]);
    $result = [];
    while ($row = $stmt->fetch()) {
        $result[(int)$row['question_id']] = (int)$row['answer'];
    }
    return $result;
}

// ----------------------------------------------------------------
// SPI スコア計算
// ----------------------------------------------------------------
function calculateSpiScores(int $attemptId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT a.answer, q.dimension_key, q.is_reverse
         FROM spi_answers a
         JOIN spi_questions q ON a.question_id = q.id
         WHERE a.attempt_id = ?'
    );
    $stmt->execute([$attemptId]);
    $rows = $stmt->fetchAll();

    // 次元ごとに集計
    $dimSums = [];
    foreach ($rows as $r) {
        $key = $r['dimension_key'];
        $answer = (int)$r['answer'];
        if ($r['is_reverse']) {
            $answer = 6 - $answer; // 逆転 (1→5, 2→4, 3→3, 4→2, 5→1)
        }
        if (!isset($dimSums[$key])) $dimSums[$key] = 0;
        $dimSums[$key] += $answer;
    }

    // 3問合計(3-15) → 1-10スケール変換
    $scores = [];
    foreach ($dimSums as $key => $sum) {
        $score = round(($sum - 3) / 12 * 9 + 1);
        $scores[$key] = max(1, min(10, (int)$score));
    }

    return $scores;
}

// ----------------------------------------------------------------
// SF ランク計算
// ----------------------------------------------------------------
function calculateSfRanks(int $attemptId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT a.answer, q.theme_key
         FROM sf_answers a
         JOIN sf_questions q ON a.question_id = q.id
         WHERE a.attempt_id = ?'
    );
    $stmt->execute([$attemptId]);
    $rows = $stmt->fetchAll();

    // テーマごとに集計
    $themeSums = [];
    $themeCounts = [];
    foreach ($rows as $r) {
        $key = $r['theme_key'];
        if (!isset($themeSums[$key])) { $themeSums[$key] = 0; $themeCounts[$key] = 0; }
        $themeSums[$key] += (int)$r['answer'];
        $themeCounts[$key]++;
    }

    // 平均×2でrawスコア (1-10スケール)
    $rawScores = [];
    foreach ($themeSums as $key => $sum) {
        $avg = $themeCounts[$key] > 0 ? $sum / $themeCounts[$key] : 0;
        $rawScores[$key] = round($avg * 2, 2);
    }

    // rawスコア降順ソート → ランク割当
    arsort($rawScores);
    $ranks = [];
    $rank = 1;
    foreach ($rawScores as $key => $score) {
        $ranks[$key] = $rank;
        $rank++;
    }

    return $ranks;
}

// ----------------------------------------------------------------
// 結果書き込み
// ----------------------------------------------------------------
function writeSpiResults(int $employeeId, array $scores): void {
    $db = getDB();
    $dims = getSpiDimensions();
    $allKeys = [];
    foreach ($dims as $cat) {
        foreach (array_keys($cat['items']) as $key) {
            $allKeys[] = $key;
        }
    }

    // 既存チェック
    $existing = $db->prepare('SELECT id FROM spi_results WHERE employee_id = ?');
    $existing->execute([$employeeId]);

    if ($existing->fetch()) {
        // UPDATE
        $sets = [];
        $params = [];
        foreach ($allKeys as $key) {
            $sets[] = "{$key} = ?";
            $params[] = $scores[$key] ?? null;
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $employeeId;
        $db->prepare('UPDATE spi_results SET ' . implode(', ', $sets) . ' WHERE employee_id = ?')->execute($params);
    } else {
        // INSERT
        $cols = array_merge(['employee_id'], $allKeys);
        $vals = array_merge([$employeeId], array_map(function($k) use ($scores) { return $scores[$k] ?? null; }, $allKeys));
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare('INSERT INTO spi_results (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')')->execute($vals);
    }
}

function writeSfResults(int $employeeId, array $ranks): void {
    $db = getDB();
    $themes = getStrengthsThemeDefinitions();
    $allKeys = array_keys($themes);

    // top5_text生成
    $top5 = [];
    foreach ($ranks as $key => $rank) {
        if ($rank <= 5) {
            $top5[$rank] = $themes[$key]['ja'] ?? $key;
        }
    }
    ksort($top5);
    $top5Text = implode('・', $top5);

    // 既存チェック
    $existing = $db->prepare('SELECT id FROM strengths_finder WHERE employee_id = ?');
    $existing->execute([$employeeId]);

    if ($existing->fetch()) {
        $sets = [];
        $params = [];
        foreach ($allKeys as $key) {
            $sets[] = "{$key} = ?";
            $params[] = $ranks[$key] ?? null;
        }
        $sets[] = 'top5_text = ?';
        $params[] = $top5Text;
        $sets[] = 'updated_at = NOW()';
        $params[] = $employeeId;
        $db->prepare('UPDATE strengths_finder SET ' . implode(', ', $sets) . ' WHERE employee_id = ?')->execute($params);
    } else {
        $cols = array_merge(['employee_id'], $allKeys, ['top5_text']);
        $vals = array_merge(
            [$employeeId],
            array_map(function($k) use ($ranks) { return $ranks[$k] ?? null; }, $allKeys),
            [$top5Text]
        );
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare('INSERT INTO strengths_finder (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')')->execute($vals);
    }
}

// ----------------------------------------------------------------
// 受検完了
// ----------------------------------------------------------------
function completeAttempt(string $type, int $attemptId): void {
    $db = getDB();
    $table = $type === 'spi' ? 'spi_attempts' : 'sf_attempts';
    $db->prepare("UPDATE {$table} SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$attemptId]);
}

// ----------------------------------------------------------------
// 受検状況チェック
// ----------------------------------------------------------------
function getAssessmentStatus(int $employeeId): array {
    $db = getDB();

    $spiDone = false;
    $sfDone = false;
    $spiInProgress = false;
    $sfInProgress = false;

    $stmt = $db->prepare("SELECT status FROM spi_attempts WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    $r = $stmt->fetch();
    if ($r) {
        $spiDone = ($r['status'] === 'completed');
        $spiInProgress = ($r['status'] === 'in_progress');
    }

    $stmt = $db->prepare("SELECT status FROM sf_attempts WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    $r = $stmt->fetch();
    if ($r) {
        $sfDone = ($r['status'] === 'completed');
        $sfInProgress = ($r['status'] === 'in_progress');
    }

    // spi_results/strengths_finderにデータがあるか（管理者入力分も含む）
    $spiResult = $db->prepare('SELECT id FROM spi_results WHERE employee_id = ?');
    $spiResult->execute([$employeeId]);
    $spiHasResult = (bool)$spiResult->fetch();

    $sfResult = $db->prepare('SELECT id FROM strengths_finder WHERE employee_id = ?');
    $sfResult->execute([$employeeId]);
    $sfHasResult = (bool)$sfResult->fetch();

    return [
        'spi_done' => $spiDone || $spiHasResult,
        'sf_done' => $sfDone || $sfHasResult,
        'spi_in_progress' => $spiInProgress,
        'sf_in_progress' => $sfInProgress,
    ];
}
