<?php
/**
 * 出発報告 回答受付（メール内の「はい/いいえ」ボタンから遷移。ログイン不要）
 * GET: token, answer=yes|no
 * 回答を記録し、送信元の管理者メールへ結果を自動通知する。
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$token  = $_GET['token'] ?? '';
$answer = $_GET['answer'] ?? '';

$message = '';
$ok = false;

if (!preg_match('/^[a-f0-9]{48}$/', $token) || !in_array($answer, ['yes', 'no'], true)) {
    $message = 'リンクが正しくありません。';
} else {
    $db = getDB();
    $st = $db->prepare('SELECT dr.*, e.name AS emp_name FROM departure_reports dr JOIN employees e ON dr.employee_id = e.id WHERE dr.token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $message = 'リンクが無効です。';
    } else {
        $db->prepare('UPDATE departure_reports SET answer = ?, answered_at = NOW() WHERE id = ?')
           ->execute([$answer, (int)$row['id']]);

        // 管理者へ結果を自動通知
        if (!empty($row['admin_email'])) {
            $ansLabel = $answer === 'yes' ? 'はい（出発しています）' : 'いいえ（出発していません）';
            $ansColor = $answer === 'yes' ? '#059669' : '#ef4444';
            $name = h($row['emp_name']);
            $when = date('Y/m/d H:i');
            $body = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:16px">
    <p style="font-size:15px"><strong>{$name}さん</strong>から出発報告の回答がありました。</p>
    <p style="font-size:18px;font-weight:bold;color:{$ansColor}">回答: {$ansLabel}</p>
    <p style="color:#6b7280;font-size:13px">回答日時: {$when}</p>
    <p style="color:#9ca3af;font-size:12px">このメールは bMS 社内ポータルから自動送信されています。</p>
</div>
HTML;
            sendAppMail($row['admin_email'], '【出発報告】' . $row['emp_name'] . 'さんの回答: ' . ($answer === 'yes' ? 'はい' : 'いいえ'), $body);
        }

        $ok = true;
        $message = ($answer === 'yes' ? '「はい」' : '「いいえ」') . 'で回答を送信しました。ありがとうございました。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出発報告 | <?= h(APP_NAME) ?></title>
    <style>
        body { font-family: 'Noto Sans JP', -apple-system, sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 10px 30px rgba(0,0,0,.06); padding: 32px 28px; max-width: 380px; text-align: center; }
        .icon { font-size: 2.4rem; margin-bottom: 8px; }
        .msg { font-size: .95rem; color: #374151; line-height: 1.7; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><?= $ok ? '✅' : '⚠️' ?></div>
        <p class="msg"><?= h($message) ?></p>
        <?php if ($ok): ?><p class="msg" style="color:#9ca3af;font-size:.75rem">このページは閉じて構いません。</p><?php endif; ?>
    </div>
</body>
</html>
