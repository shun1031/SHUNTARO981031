<?php
/**
 * Googleドライブ連携の設定層
 *
 * 【重要】認証情報はこのファイルに書かない。すべて環境変数から読み込む。
 * 設定していなくてもアプリは正常に動作し、契約書は「無し」または
 * 保存済みのリンクのみで表示される（連携機能だけが無効になる）。
 *
 * 対応している認証方式（サーバーへアップロード後に .env / Railway Variables で設定）
 *
 *  1) サービスアカウント（推奨・非公開ファイルに対応）
 *     GOOGLE_SERVICE_ACCOUNT_JSON   … サービスアカウントJSONの中身そのもの、
 *                                      またはJSONファイルの絶対パス
 *     GOOGLE_DRIVE_FOLDER_ID        … 契約書を格納するドライブのフォルダID（任意）
 *     ※ 対象フォルダをサービスアカウントのメールアドレスに共有する必要がある
 *
 *  2) OAuth2（ユーザー自身のドライブを使う場合）
 *     GOOGLE_OAUTH_CLIENT_ID
 *     GOOGLE_OAUTH_CLIENT_SECRET
 *     GOOGLE_OAUTH_REFRESH_TOKEN
 *
 *  3) APIキーのみ（公開ファイル限定。非公開の契約書には使えない）
 *     GOOGLE_API_KEY
 */

/** 環境変数を取得（未設定なら null） */
function gdEnv(string $key): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }
    $v = is_string($v) ? trim($v) : '';
    return $v !== '' ? $v : null;
}

/**
 * 現在の連携設定の状態を返す
 * @return array{configured:bool, method:string, label:string, detail:string}
 */
function googleDriveConfigStatus(): array {
    if (gdEnv('GOOGLE_SERVICE_ACCOUNT_JSON') !== null) {
        return [
            'configured' => true,
            'method'     => 'service_account',
            'label'      => 'サービスアカウント',
            'detail'     => '非公開の契約書にもアクセスできます。',
        ];
    }
    if (gdEnv('GOOGLE_OAUTH_CLIENT_ID') !== null
        && gdEnv('GOOGLE_OAUTH_CLIENT_SECRET') !== null
        && gdEnv('GOOGLE_OAUTH_REFRESH_TOKEN') !== null) {
        return [
            'configured' => true,
            'method'     => 'oauth2',
            'label'      => 'OAuth2',
            'detail'     => '連携したGoogleアカウントのドライブを利用します。',
        ];
    }
    if (gdEnv('GOOGLE_API_KEY') !== null) {
        return [
            'configured' => true,
            'method'     => 'api_key',
            'label'      => 'APIキー',
            'detail'     => '公開ファイルのみ取得できます。非公開の契約書にはサービスアカウントの設定が必要です。',
        ];
    }
    return [
        'configured' => false,
        'method'     => 'none',
        'label'      => '未設定',
        'detail'     => '認証情報が未設定のため、契約書は登録済みのリンクのみで開きます。',
    ];
}

/** 連携が有効かどうか（未設定でもアプリは動作する） */
function googleDriveIsConfigured(): bool {
    return googleDriveConfigStatus()['configured'];
}

/** 契約書の格納先フォルダID（任意設定） */
function googleDriveFolderId(): ?string {
    return gdEnv('GOOGLE_DRIVE_FOLDER_ID');
}

/**
 * ファイルIDからGoogleドライブの閲覧URLを組み立てる
 * 認証情報が未設定でもURLの生成自体は可能（開くときにGoogle側で権限判定される）
 */
function googleDriveFileUrl(?string $fileId): ?string {
    $fileId = $fileId !== null ? trim($fileId) : '';
    if ($fileId === '') return null;
    return 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
}

/**
 * 貼り付けられたGoogleドライブURLからファイルIDを抽出する
 * 対応: /file/d/<id>/、?id=<id>、/document|spreadsheets|presentation/d/<id>
 * ID自体が渡された場合はそのまま返す
 */
function googleDriveExtractFileId(?string $input): ?string {
    $s = $input !== null ? trim($input) : '';
    if ($s === '') return null;
    if (preg_match('#/(?:file|document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,})#', $s, $m)) {
        return $m[1];
    }
    if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $s, $m)) {
        return $m[1];
    }
    // URLでなく、IDらしき文字列がそのまま入力された場合
    if (preg_match('#^[A-Za-z0-9_-]{10,}$#', $s)) {
        return $s;
    }
    return null;
}

/**
 * 取引先1件から、一覧に表示する契約書リンクを決定する
 * @return array{has:bool, url:?string, name:?string}
 */
function clientContractLink(array $client): array {
    $fileId = $client['contract_file_id'] ?? '';
    $url    = trim((string)($client['contract_url'] ?? ''));
    $byId   = googleDriveFileUrl($fileId !== '' ? (string)$fileId : null);
    $final  = $byId ?: ($url !== '' ? $url : null);
    return [
        'has'  => $final !== null,
        'url'  => $final,
        'name' => ($client['contract_file_name'] ?? '') !== '' ? (string)$client['contract_file_name'] : null,
    ];
}
