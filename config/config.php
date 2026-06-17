<?php
// ============================================================
// システム設定ファイル
// 実際の認証情報はプロジェクトルート直下の .env から読み込みます
// ============================================================

require_once __DIR__ . '/env.php';

// .env をプロジェクトルートから読み込み
// (config.php から見た .env の位置: __DIR__/../.env)
$envLoaded = loadEnv(__DIR__ . '/../.env');

if (!$envLoaded) {
    // Railway等のコンテナ環境ではプラットフォームが環境変数を注入するため .env は不要
    // DB_HOST が環境変数にない場合のみ停止
    if (getenv('DB_HOST') === false) {
        http_response_code(500);
        error_log('FATAL: .env file not found at ' . realpath(__DIR__ . '/..') . '/.env');
        die('Configuration error: environment file is missing. Contact administrator.');
    }
}

// --- データベース設定 ---
define('DB_HOST',    requireEnv('DB_HOST'));
define('DB_NAME',    requireEnv('DB_NAME'));
define('DB_USER',    requireEnv('DB_USER'));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// --- アプリケーション設定 ---
define('APP_NAME',    env('APP_NAME', 'bMS - タレントマネジメントシステム'));
define('APP_VERSION', env('APP_VERSION', '1.0.0'));

// Xserver の場合はドキュメントルートに合わせて変更
// 例: https://example.com/bms/ の場合は '/bms'
define('BASE_PATH', env('BASE_PATH', '/bms'));
define('BASE_URL',  'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH);

// アップロード設定
define('UPLOAD_DIR',      __DIR__ . '/../public/assets/images/employees/');
define('UPLOAD_URL',      BASE_PATH . '/public/assets/images/employees/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// セッション設定
define('SESSION_NAME',     'bms_session');
define('SESSION_LIFETIME', 7200); // 2時間

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// エラー設定（本番環境では false に変更）
define('DEBUG_MODE', (bool)env('DEBUG_MODE', false));

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_error.log');
}

// --- セキュリティヘッダー ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
