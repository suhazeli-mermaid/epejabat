<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI sahaja.\n");
}

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'EPejabat\\Core\\' => APP_ROOT . '/app/Core/',
        'EPejabat\\Modules\\Training\\' => APP_ROOT . '/app/Modules/Training/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $name = substr($class, strlen($prefix));
        if (preg_match('/\A[A-Za-z]+\z/', $name) !== 1) return;
        $file = $directory . $name . '.php';
        if (is_file($file)) require $file;
        return;
    }
});

exit(EPejabat\Core\CpanelDeployment::main($argv));
