<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI sahaja.\n");
}

$path = dirname(__DIR__) . '/config/core.key';
if (is_file($path)) {
    fwrite(STDERR, "config/core.key sudah wujud; fail tidak ditimpa.\n");
    exit(1);
}

$handle = fopen($path, 'x');
if ($handle === false) {
    fwrite(STDERR, "config/core.key tidak boleh dicipta.\n");
    exit(1);
}

try {
    $key = bin2hex(random_bytes(32));
    if (fwrite($handle, $key) !== 64 || !fflush($handle) || !chmod($path, 0600)) {
        throw new RuntimeException('Kunci tidak boleh disimpan dengan selamat.');
    }
} catch (Throwable $error) {
    fclose($handle);
    @unlink($path);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fclose($handle);
fwrite(STDOUT, "config/core.key berjaya dicipta (mod 0600).\n");
