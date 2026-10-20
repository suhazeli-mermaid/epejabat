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
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
umask(0077);

set_exception_handler(static function (Throwable $e): void {
    $id = Support::log($e);
    if (http_response_code() < 400) http_response_code(500);
    Support::layout('Ralat sistem', '<p>Permintaan tidak dapat diselesaikan. Rujukan: ' . Support::escape($id) . '</p>');
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException('PHP runtime error', 0, $severity);
});

$config = is_file(APP_ROOT . '/config/app.php') ? require APP_ROOT . '/config/app.php' : null;
$setup = is_file(APP_ROOT . '/config/setup.php') ? require APP_ROOT . '/config/setup.php' : null;
if (($config !== null && !is_array($config)) || ($setup !== null && !is_array($setup))) {
    throw new RuntimeException('Invalid configuration structure');
}

$active = $config ?? $setup ?? ['app_url' => 'https://pkdkb.epejabat.net', 'environment' => 'production'];
$configuredUrl = $active['app_url'] ?? '';
if (!is_string($configuredUrl)) throw new RuntimeException('Invalid application origin');

// Local test mode is allowed only on loopback with a loopback origin.
$localCandidate = ($active['environment'] ?? 'production') === 'local'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && in_array(parse_url($configuredUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true);

// Canonical origin for this deployment; independent of legacy config URLs.
// Existing database configuration and credentials are never rewritten here.
$deployment = is_file(APP_ROOT . '/config/deployment.php') ? require APP_ROOT . '/config/deployment.php' : [];
if (!is_array($deployment)) throw new RuntimeException('Invalid deployment configuration');
$deploymentOrigin = $deployment['app_url'] ?? $configuredUrl;
// Compatibility with Phase 0 packages originally configured for the parent domain.
if (!$localCandidate && $deploymentOrigin === 'https://epejabat.net') $deploymentOrigin = 'https://pkdkb.epejabat.net';
$url = $localCandidate ? $configuredUrl : $deploymentOrigin;
$parts = parse_url($url);
if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
    || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
    || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
    throw new RuntimeException('Invalid application origin');
}

$isLocal = $localCandidate;
$https = in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true);
if (!$isLocal && $parts['scheme'] !== 'https') throw new RuntimeException('Production requires HTTPS');

$expectedHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
if (!$https && !$isLocal) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    header('Location: ' . $url . (str_starts_with($requestPath, '/') ? $requestPath : '/'), true, 308);
    exit;
}
if (strcasecmp($_SERVER['HTTP_HOST'] ?? '', $expectedHost) !== 0) {
    http_response_code(400);
    exit('Domain tidak sepadan. Sila buka https://pkdkb.epejabat.net/install');
}

// A fresh installer saves the canonical origin into its new configuration.
// Do not alter loaded app.php in memory: installer verification compares it.
if ($config === null && $setup !== null) $setup['app_url'] = $url;

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
if (!$documentRoot || $documentRoot !== realpath(APP_ROOT)) throw new RuntimeException('Single-root document root mismatch');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; connect-src 'self'; media-src 'self' blob:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');
if ($https) header('Strict-Transport-Security: max-age=31536000');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');
session_cache_limiter('');
session_name('epejabat_session');
session_save_path(APP_ROOT . '/storage/sessions');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => !$isLocal,
    'httponly' => true, 'samesite' => 'Lax',
]);
if (!session_start()) throw new RuntimeException('Session unavailable');
