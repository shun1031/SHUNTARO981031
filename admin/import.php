<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = 'CSVインポート';
$db        = getDB();
$cid       = getCompanyId();
$csrf      = getCsrfToken();
$message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) die('不正なリクエストです');

    $type = $_POST['import_type'] ?? '';
    $file = $_FILES['csv_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $message = 'error:ファイルのアップロードに失敗しました';
    } elseif (!in_array($type, ['employees', 'strengths_finder', 'spi'])) {
        $message = 'error:インポートタイプが不正です';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        // BOM除去
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $headers = fgetcsv($handle);
        if (!$headers) {
            $message = 'error:CSVの読み込みに失敗しました';
        } else {
            $headers = array_map('trim', $headers);
            $count   = 0;
            $errors  = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) continue;
                $data = array_combine($headers, array_pad($row, count($headers), ''));

                try {
                    if ($type === 'employees') {
                        // 社員インポート
                        $stmt = $db->prepare(
                            'INSERT INTO employees (employee_number, name, name_kana, email, job_title, department, hire_date)
                             VALUES (?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE name=VALUES(name), job_title=VALUES(job_title), department=VALUES(department)'
                        );
                        $stmt->execute([
                            $data['employee_number'] ?? '',
                            $data['name'] ?? '',
                            $data['name_kana'] ?? '',
                            $data['email'] ?? '',
                            $data['job_title'] ?? '',
                            $data['department'] ?? '',
                            !empty($data['hire_date']) ? $data['hire_date'] : null,
                        ]);
                        $count++;
                    } elseif ($type === 'strengths_finder') {
                        // SF インポート（社員番号 or メールで照合）
                        $emp = null;
                        if (!empty($data['employee_number'])) {
                            $s = $db->prepare('SELECT id FROM employees WHERE employee_number = ?');
                            $s->execute([$data['employee_number']]);
                            $emp = $s->fetch();
                        }
                        if (!$emp && !empty($data['email'])) {
                            $s = $db->prepare('SELECT id FROM employees WHERE email = ?');
                            $s->execute([$data['email']]);
                            $emp = $s->fetch();
                        }
                        if (!$emp) { $errors[] = ($data['name'] ?? '?') . ': 社員が見つかりません'; continue; }

                        $sfDefs = getStrengthsThemeDefinitions();
                        $sfVals = ['employee_id' => $emp['id']];
                        foreach (array_keys($sfDefs) as $key) {
                            $sfVals[$key] = isset($data[$key]) && $data[$key] !== '' ? (int)$data[$key] : null;
                        }
                        $sfVals['top5_text'] = $data['top5_text'] ?? '';

                        $exists = $db->prepare('SELECT id FROM strengths_finder WHERE employee_id = ?');
                        $exists->execute([$emp['id']]);
                        if ($exists->fetch()) {
                            $setC = implode(',', array_map(fn($k) => "$k = ?", array_diff(array_keys($sfVals), ['employee_id'])));
                            $vals = array_diff_key($sfVals, ['employee_id' => null]);
                            $db->prepare("UPDATE strengths_finder SET $setC WHERE employee_id = ?")->execute([...array_values($vals), $emp['id']]);
                        } else {
                            $cols = implode(',', array_keys($sfVals));
                            $pl   = implode(',', array_fill(0, count($sfVals), '?'));
                            $db->prepare("INSERT INTO strengths_finder ($cols) VALUES ($pl)")->execute(array_values($sfVals));
                        }
                        $count++;
                    } elseif ($type === 'spi') {
                        // SPI インポート
                        $emp = null;
                        if (!empty($data['employee_number'])) {
                            $s = $db->prepare('SELECT id FROM employees WHERE employee_number = ?');
                            $s->execute([$data['employee_number']]);
                            $emp = $s->fetch();
                        }
                        if (!$emp && !empty($data['email'])) {
                            $s = $db->prepare('SELECT id FROM employees WHERE email = ?');
                            $s->execute([$data['email']]);
                            $emp = $s->fetch();
                        }
                        if (!$emp) { $errors[] = ($data['name'] ?? '?') . ': 社員が見つかりません'; continue; }

                        $spiDims = getSpiDimensions();
                        $spiVals = ['employee_id' => $emp['id']];
                        foreach ($spiDims as $cat) {
                            foreach (array_keys($cat['items']) as $key) {
                                $spiVals[$key] = isset($data[$key]) && $data[$key] !== '' ? (int)$data[$key] : null;
                            }
                        }

                        $exists = $db->prepare('SELECT id FROM spi_results WHERE employee_id = ?');
                        $exists->execute([$emp['id']]);
                        if ($exists->fetch()) {
                            $setC = implode(',', array_map(fn($k) => "$k = ?", array_diff(array_keys($spiVals), ['employee_id'])));
                            $vals = array_diff_key($spiVals, ['employee_id' => null]);
                            $db->prepare("UPDATE spi_results SET $setC WHERE employee_id = ?")->execute([...array_values($vals), $emp['id']]);
                        } else {
                            $cols = implode(',', array_keys($spiVals));
                            $pl   = implode(',', array_fill(0, count($spiVals), '?'));
                            $db->prepare("INSERT INTO spi_results ($cols) VALUES ($pl)")->execute(array_values($spiVals));
                        }
                        $count++;
                    }
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
            fclose($handle);

            $message = "success:{$count}件インポートしました" . (!empty($errors) ? "（エラー " . count($errors) . "件）" : '');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-upload me-2"></i>CSVインポート</h1>
        <p>社員情報・ストレングスファインダー・SPIデータをCSVから一括登録</p>
    </div>

    <?php if ($message): ?>
    <?php [$mtype, $mtext] = explode(':', $message, 2); ?>
    <div class="alert alert-<?= $mtype === 'success' ? 'success' : 'danger' ?>">
        <?= h($mtext) ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- インポートフォーム -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-2"></i>CSVアップロード</div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">インポート種別</label>
                            <select name="import_type" class="form-select" required id="importType" onchange="showTemplate()">
                                <option value="">選択してください</option>
                                <option value="employees">社員基本情報</option>
                                <option value="strengths_finder">ストレングスファインダー</option>
                                <option value="spi">SPI</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">CSVファイル</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            <div class="form-text">UTF-8（BOM付き）またはShift-JISのCSVファイル</div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload me-1"></i>インポート実行
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- テンプレート説明 -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>CSVフォーマット</div>
                <div class="card-body">
                    <div id="template-employees">
                        <h6>社員基本情報</h6>
                        <pre style="font-size:11px;background:#f8f9fa;padding:12px;border-radius:8px">employee_number,name,name_kana,email,job_title,department,hire_date
E001,山田太郎,やまだたろう,yamada@example.com,マネージャー,営業部,2018-04-01
E002,佐藤花子,さとうはなこ,sato@example.com,デザイナー,デザイン部,2020-01-15</pre>
                    </div>

                    <div id="template-sf" style="display:none">
                        <h6>ストレングスファインダー</h6>
                        <p class="small text-muted">各資質のランク（1〜34）を入力。空欄可。</p>
                        <pre style="font-size:11px;background:#f8f9fa;padding:12px;border-radius:8px">employee_number,name,achiever,strategic,learner,maximizer,relator,top5_text
E001,山田太郎,1,2,3,4,5,達成欲・戦略性・学習欲・最上志向・親密性</pre>
                        <p class="small text-muted mt-2">利用可能なキー: achiever, activator, adaptability, analytical, arranger, belief, command, communication, competition, connectedness, consistency, context, deliberative, developer, discipline, empathy, focus, futuristic, harmony, ideation, includer, individualization, input, intellection, learner, maximizer, positivity, relator, responsibility, restorative, self_assurance, significance, strategic, woo</p>
                    </div>

                    <div id="template-spi" style="display:none">
                        <h6>SPI</h6>
                        <p class="small text-muted">各項目を1〜10で入力。空欄可。</p>
                        <pre style="font-size:11px;background:#f8f9fa;padding:12px;border-radius:8px">employee_number,name,leadership,teamwork,problem_solving,achievement_drive
E001,山田太郎,7,6,8,9</pre>
                        <p class="small text-muted mt-2">利用可能なキー: social_introversion, introspection, physical_activity, persistence, caution, achievement_drive, activity_drive, sensitivity, self_blame, mood_variation, uniqueness, self_confidence, elation, compliance, avoidance, criticism, self_respect, skepticism, leadership, teamwork, relationship_building, creative_thinking, problem_solving, situation_adaptability, ownership, energetic_action</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showTemplate() {
    const type = document.getElementById('importType').value;
    document.getElementById('template-employees').style.display = 'none';
    document.getElementById('template-sf').style.display = 'none';
    document.getElementById('template-spi').style.display = 'none';
    if (type) {
        const map = { employees: 'template-employees', strengths_finder: 'template-sf', spi: 'template-spi' };
        document.getElementById(map[type] || 'template-employees').style.display = '';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
