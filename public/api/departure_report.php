<?php
/**
 * 出発報告メール送信 API（管理者のみ）
 * POST: csrf, employee_id
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/departure_mail.php';

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

$result = sendDepartureReportMail($db, $cid, $emp, $adminEmail, null, departureBaseUrl());
if (!$result['success']) {
    echo json_encode(['error' => $result['error']]); exit;
}
echo json_encode(['success' => true, 'sent_to' => $emp['email']], JSON_UNESCAPED_UNICODE);
