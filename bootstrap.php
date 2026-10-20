<?php
declare(strict_types=1);

use EPejabat\Core\Support;

define('APP_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $modulePrefix = 'EPejabat\\Modules\\Training\\';
    if (str_starts_with($class, $modulePrefix)) {
        $name = substr($class, strlen($modulePrefix));
        if (preg_match('/\A[A-Za-z]+\z/', $name) && is_file($file = APP_ROOT . '/app/Modules/Training/' . $name . '.php')) require $file;
        return;
    }
    $prefix = 'EPejabat\\Core\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/\A[A-Za-z]+\z/', $name)) return;
    $file = APP_ROOT . '/app/Core/' . $name . '.php';
    if (is_file($file)) require $file;
});

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Fail closed until the environment has been resolved below. Production must
// never disclose exception details, including during early bootstrap failures.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
umask(0077);

set_exception_handler(static function (Throwable $e): void {
    $id = Support::log($e);
    if (http_response_code() < 400) http_response_code(500);
    $msg = '<p>Permintaan tidak dapat diselesaikan. Rujukan: ' . Support::escape($id) . '</p>';
    if (ini_get('display_errors') === '1' || ini_get('display_errors') === 'On') {
        $msg .= '<div style="background:#fff0f0;color:#900;padding:15px;border:1px solid #c00;margin:15px 0;text-align:left;font-family:Consolas,monospace;border-radius:4px;">'
             . '<strong style="font-size:1.1em;">' . Support::escape(get_class($e)) . ':</strong> ' . Support::escape($e->getMessage()) . '<br>'
             . '<small>in ' . Support::escape($e->getFile()) . ':' . $e->getLine() . '</small><br><br>'
             . '<pre style="white-space:pre-wrap;overflow:auto;background:#fff;padding:10px;border:1px solid #ddd;">' . Support::escape($e->getTraceAsString()) . '</pre>'
             . '</div>';
    }
    Support::layout('Ralat sistem', $msg);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Muat turun pembolehubah persekitaran daripada .env sekiranya wujud
if (is_file(APP_ROOT . '/.env')) {
    $envLines = file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!isset($_SERVER[$k])) $_SERVER[$k] = $v;
            if (!isset($_ENV[$k])) $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}

$config = is_file(APP_ROOT . '/config/app.php') ? require APP_ROOT . '/config/app.php' : null;
$setup = is_file(APP_ROOT . '/config/setup.php') ? require APP_ROOT . '/config/setup.php' : null;

if (($config !== null && !is_array($config)) || ($setup !== null && !is_array($setup))) {
    throw new RuntimeException('Invalid configuration structure');
}

// -------------------------------------------------------------------------
// PERUBAHAN: Pengesanan Persekitaran Tempatan (Local Environment Detection)
// -------------------------------------------------------------------------
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Mengesan jika akses dibuat daripada localhost/loopback
$isLoopbackRequest = in_array($remoteAddr, ['127.0.0.1', '::1', ''], true)
    || str_starts_with($currentHost, 'localhost')
    || str_starts_with($currentHost, '127.0.0.1');

$envSetting = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ($config['environment'] ?? $setup['environment'] ?? 'production');

// Diiktiraf sebagai local jika environment ditetapkan ke 'local' ATAU diakses melalui loopback/localhost XAMPP
$localCandidate = ($envSetting === 'local') || $isLoopbackRequest;

ini_set('display_errors', $localCandidate ? '1' : '0');
ini_set('log_errors', '1');

$active = $config ?? $setup ?? [
    'app_url' => $localCandidate ? 'http://' . ($currentHost ?: 'localhost') . '/epejabat' : 'https://pkdkb.epejabat.net',
    'environment' => $localCandidate ? 'local' : 'production'
];

$configuredUrl = $active['app_url'] ?? '';
if (!is_string($configuredUrl)) throw new RuntimeException('Invalid application origin');

// Canonical origin deployment
$deployment = is_file(APP_ROOT . '/config/deployment.php') ? require APP_ROOT . '/config/deployment.php' : [];
if (!is_array($deployment)) throw new RuntimeException('Invalid deployment configuration');

$deploymentOrigin = $deployment['app_url'] ?? $configuredUrl;

if (!$localCandidate && $deploymentOrigin === 'https://epejabat.net') {
    $deploymentOrigin = 'https://pkdkb.epejabat.net';
}

$url = $localCandidate ? ($configuredUrl ?: 'http://' . $currentHost . '/epejabat') : $deploymentOrigin;
$parts = parse_url($url);

if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
    || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
    || isset($parts['query']) || isset($parts['fragment'])) {
    throw new RuntimeException('Invalid application origin');
}

$basePath = rtrim((string)($parts['path'] ?? ''), '/');
if ($basePath !== '' && !preg_match('#\A(?:/[A-Za-z0-9._~-]+)+\z#', $basePath)) {
    throw new RuntimeException('Invalid application base path');
}
$GLOBALS['app_base_path'] = $basePath;

$isLocal = $localCandidate;
$GLOBALS['isLocal'] = $isLocal;
$GLOBALS['config'] = $config;
$https = in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true);

if (!$isLocal && $parts['scheme'] !== 'https') {
    throw new RuntimeException('Production requires HTTPS');
}

$expectedHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

// Mencegah lencongan paksa HTTPS jika berada dalam mod tempatan
if (!$https && !$isLocal) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    header('Location: ' . $url . (str_starts_with($requestPath, '/') ? $requestPath : '/'), true, 308);
    exit;
}

// Semakan Host Domain (Dikecualikan semakan ketat jika $isLocal aktif)
if (!$isLocal && strcasecmp($_SERVER['HTTP_HOST'] ?? '', $expectedHost) !== 0) {
    http_response_code(400);
    exit('Domain tidak sepadan. Sila buka https://pkdkb.epejabat.net/install');
}

if ($config === null && $setup !== null) {
    $setup['app_url'] = $url;
}

// -------------------------------------------------------------------------
// PERUBAHAN: Penyelarasan Single-Root untuk Subfolder XAMPP (htdocs/epejabat)
// -------------------------------------------------------------------------
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$appRoot = realpath(APP_ROOT);

if (!$isLocal && (!$documentRoot || $documentRoot !== $appRoot)) {
    throw new RuntimeException('Single-root document root mismatch');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; media-src 'self' blob:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');

if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Pastikan direktori sesi wujud sebelum memulakan sesi
$sessionPath = APP_ROOT . '/storage/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');
session_cache_limiter('');
session_name('epejabat_session');
session_save_path($sessionPath);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !$isLocal,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (!session_start()) {
    throw new RuntimeException('Session unavailable');
}
