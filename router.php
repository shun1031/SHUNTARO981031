<?php
// Railway用PHPビルトインサーバー ルーター
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 機密ディレクトリへのアクセスをブロック
$blocked_dirs = ['/config/', '/includes/', '/sql/', '/docs/', '/backups/', '/archive/', '/logs/'];
foreach ($blocked_dirs as $dir) {
    if (strpos($uri, $dir) === 0) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// 機密ファイル拡張子をブロック
if (preg_match('/\.(py|sh|env|bak|sql|git|log)$/i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// ルートへのアクセスはlogin.phpにリダイレクト
if ($uri === '/' || $uri === '') {
    header('Location: /login.php');
    exit;
}

// ファイルが存在すればPHPビルトインサーバーに処理させる
$file = __DIR__ . $uri;
if (is_file($file)) {
    return false;
}

// 存在しないパスは404
http_response_code(404);
exit('Not Found');
