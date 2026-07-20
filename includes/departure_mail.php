<?php
// ============================================================
// 出発報告メール 共通送信処理（手動API・自動スケジューラー共用）
// 依存: includes/mailer.php（sendAppMail）
// ============================================================

/**
 * 回答リンクのベースURLを解決する
 * 優先順: APP_URL環境変数 → RAILWAY_PUBLIC_DOMAIN → リクエストのホスト
 */
function departureBaseUrl(): string {
    $appUrl = getenv('APP_URL') ?: '';
    if ($appUrl !== '') return rtrim($appUrl, '/');
    $rw = getenv('RAILWAY_PUBLIC_DOMAIN') ?: '';
    if ($rw !== '') return 'https://' . $rw . (defined('BASE_PATH') ? BASE_PATH : '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (defined('BASE_PATH') ? BASE_PATH : '');
}

/**
 * 出発報告メールを送信する（トークン発行 + メール送信）
 * @param array $emp ['id','name','email']
 * @param int|null $shiftId 自動送信時のシフトID（重複送信防止。手動はnull）
 * @param string $baseUrl 例 https://example.com/bms
 * @return array{success: bool, error?: string}
 */
function sendDepartureReportMail(PDO $db, int $cid, array $emp, ?string $adminEmail, ?int $shiftId, string $baseUrl): array {
    if (empty($emp['email'])) {
        return ['success' => false, 'error' => 'メールアドレスが登録されていません'];
    }
    $token = bin2hex(random_bytes(24));
    try {
        $db->prepare('INSERT INTO departure_reports (company_id, employee_id, token, sent_to, admin_email, shift_id, is_auto) VALUES (?,?,?,?,?,?,?)')
           ->execute([$cid, (int)$emp['id'], $token, $emp['email'], $adminEmail, $shiftId, $shiftId !== null ? 1 : 0]);
    } catch (PDOException $e) {
        // shift_id UNIQUE違反 = このシフトには送信済み
        return ['success' => false, 'error' => 'このシフトには送信済みです'];
    }

    $yesUrl = $baseUrl . '/departure_reply.php?token=' . $token . '&answer=yes';
    $noUrl  = $baseUrl . '/departure_reply.php?token=' . $token . '&answer=no';
    $name   = htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8');

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
        // 送信失敗時はトークン行を削除（自動送信の再試行を可能にする）
        $db->prepare('DELETE FROM departure_reports WHERE token = ?')->execute([$token]);
    }
    return $result;
}
