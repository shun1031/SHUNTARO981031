<?php
// ============================================================
// メール送信（Gmail SMTP / SMTPS 465）
// 環境変数: SMTP_USER（Gmailアドレス）, SMTP_PASS（アプリパスワード）
//           SMTP_HOST（省略時 smtp.gmail.com）, SMTP_PORT（省略時 465）
// ============================================================

/**
 * HTMLメールを送信する
 * @return array{success: bool, error?: string}
 */
function sendAppMail(string $to, string $subject, string $htmlBody): array {
    $user = getenv('SMTP_USER') ?: '';
    $pass = getenv('SMTP_PASS') ?: '';
    $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $port = (int)(getenv('SMTP_PORT') ?: 465);

    if ($user === '' || $pass === '') {
        return ['success' => false, 'error' => 'メール送信が未設定です。環境変数 SMTP_USER / SMTP_PASS（Gmailアプリパスワード）を設定してください。'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => '宛先メールアドレスが不正です'];
    }

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("[mailer] connect failed: {$errno} {$errstr}");
        return ['success' => false, 'error' => 'メールサーバーに接続できませんでした'];
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break; // 複数行応答（250-xxx）対応
        }
        return $data;
    };
    $cmd = function (string $c, array $expect) use ($fp, $read): array {
        fwrite($fp, $c . "\r\n");
        $res = $read();
        $code = (int)substr($res, 0, 3);
        return [in_array($code, $expect, true), $res];
    };

    try {
        $greet = $read();
        if ((int)substr($greet, 0, 3) !== 220) throw new Exception('greeting: ' . trim($greet));

        [$ok, $res] = $cmd('EHLO bms.local', [250]);
        if (!$ok) throw new Exception('EHLO: ' . trim($res));
        [$ok, $res] = $cmd('AUTH LOGIN', [334]);
        if (!$ok) throw new Exception('AUTH: ' . trim($res));
        [$ok, $res] = $cmd(base64_encode($user), [334]);
        if (!$ok) throw new Exception('AUTH user: ' . trim($res));
        [$ok, $res] = $cmd(base64_encode($pass), [235]);
        if (!$ok) throw new Exception('認証失敗（SMTP_USER/SMTP_PASSを確認してください）');
        [$ok, $res] = $cmd("MAIL FROM:<{$user}>", [250]);
        if (!$ok) throw new Exception('MAIL FROM: ' . trim($res));
        [$ok, $res] = $cmd("RCPT TO:<{$to}>", [250, 251]);
        if (!$ok) throw new Exception('宛先が拒否されました: ' . trim($res));
        [$ok, $res] = $cmd('DATA', [354]);
        if (!$ok) throw new Exception('DATA: ' . trim($res));

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            "From: {$user}",
            "To: {$to}",
            "Subject: {$encSubject}",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
        // ドット始まり行のエスケープ
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $res = $read();
        if ((int)substr($res, 0, 3) !== 250) throw new Exception('送信失敗: ' . trim($res));

        $cmd('QUIT', [221]);
        fclose($fp);
        return ['success' => true];
    } catch (Exception $e) {
        @fclose($fp);
        error_log('[mailer] ' . $e->getMessage());
        return ['success' => false, 'error' => 'メール送信に失敗しました（' . $e->getMessage() . '）'];
    }
}
