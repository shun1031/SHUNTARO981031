<?php
/**
 * 出発報告メール送信 API（管理者のみ）
 * POST: csrf, employee_id
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['super_admin', 'company_admin'], true)) {
    echo json_encode(['error' => '権限がありません']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }
if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }

$db    = getDB();
$empId = (int)($_POST['employee_id'] ?? 0);

$eSt = $db->prepare('SELECT id, name, email, departure_report_flag FROM employees WHERE id = ? AND company_id = ? AND is_active = 1');
$eSt->execute([$empId, $cid]);
$emp = $eSt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { echo json_encode(['error' => '社員が見つかりません']); exit; }
if (!(int)$emp['departure_report_flag']) { echo json_encode(['error' => '出発報告対象者に設定されていません（チェックを付けて保存してください）']); exit; }
if (empty($emp['email'])) { echo json_encode(['error' => 'この社員にメールアドレスが登録されていません']); exit; }

// 回答の通知先（操作中の管理者の登録メール。なければSMTP_USER宛）
$adminEmail = null;
if (!empty($user['employee_id'])) {
    $aSt = $db->prepare('SELECT email FROM employees WHERE id = ?');
    $aSt->execute([(int)$user['employee_id']]);
    $adminEmail = $aSt->fetchColumn() ?: null;
}
if (!$adminEmail) $adminEmail = getenv('SMTP_USER') ?: null;

// 回答用トークン発行
$token = bin2hex(random_bytes(24));
$db->prepare('INSERT INTO departure_reports (company_id, employee_id, token, sent_to, admin_email) VALUES (?,?,?,?,?)')
   ->execute([$cid, $empId, $token, $emp['email'], $adminEmail]);

// 回答用URL（本番ドメインベース）
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base    = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH;
$yesUrl  = $base . '/departure_reply.php?token=' . $token . '&answer=yes';
$noUrl   = $base . '/departure_reply.php?token=' . $token . '&answer=no';

$name = h($emp['name']);
$body = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:16px">
    <p style="font-size:16px">{$name}さん、出発してますでしょうか。</p>
    <p>下のボタンで回答してください。</p>
    <p style="text-align:center;margin:24px 0">
        <a href="{$yesUrl}" style="display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:12px 36px;border-radius:8px;font-weight:bold;margin:4px">はい</a>
        <a href="{$noUrl}" style="display:inline-block;background:#ef4444;color:#fff;text-decoration:none;padding:12px 36px;border-radius:8px;font-weight:bold;margin:4px">いいえ</a>
    </p>
    <p style="color:#9ca3af;font-size:12px">このメールは bMS 社内ポータルから自動送信されています。</p>
</div>
HTML;

$result = sendAppMail($emp['email'], '【出発確認】' . $emp['name'] . 'さん、出発してますでしょうか', $body);
if (!$result['success']) {
    echo json_encode(['error' => $result['error']]); exit;
}
echo json_encode(['success' => true, 'sent_to' => $emp['email']], JSON_UNESCAPED_UNICODE);
