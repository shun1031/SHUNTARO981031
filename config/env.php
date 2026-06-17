<?php
/**
 * .env ファイルローダー
 *
 * プロジェクトルートの .env から KEY=VALUE 形式の設定を読み込み、
 * $_ENV, getenv() で参照可能にする。
 *
 * セキュリティ:
 *   - .env は .gitignore で除外
 *   - .htaccess で Web アクセスを拒否
 *   - 本ファイル自体は config.php から読み込まれる
 */

if (!function_exists('loadEnv')) {
    /**
     * .env ファイルを読み込む
     * @param string $path .envファイルのフルパス
     * @return bool 読み込みに成功したか
     */
    function loadEnv(string $path): bool {
        if (!is_readable($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (!str_contains($trimmed, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $trimmed, 2);
            $key   = trim($key);
            $value = trim($value);

            // クォート除去（"value" or 'value'）
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // 既に環境変数にあれば上書きしない（OSレベルの変数を尊重）
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }
        return true;
    }
}

if (!function_exists('env')) {
    /**
     * 環境変数を取得（デフォルト値対応）
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        // よくある変換
        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('requireEnv')) {
    /**
     * 必須環境変数を取得。未設定ならエラー停止（設定漏れを早期検知）
     */
    function requireEnv(string $key): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            http_response_code(500);
            error_log("FATAL: Required environment variable '{$key}' is not set. Check .env file.");
            die('Configuration error: missing required setting.');
        }
        return $value;
    }
}
