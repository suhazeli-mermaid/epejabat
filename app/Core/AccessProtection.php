<?php
declare(strict_types=1);
namespace EPejabat\Core;

final class AccessProtection {
    public static function check(string $origin): array {
        $parts = parse_url($origin);
        $local = ($GLOBALS['isLocal'] ?? false)
            || (PHP_SAPI === 'cli' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true));
        if (!$parts || !in_array($parts['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !empty($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Origin probe tidak sah.');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL diperlukan untuk pemeriksaan perlindungan folder.');
        }
        $out = [];
        foreach (['app', 'config', 'database', 'storage', 'bin', 'backup', 'log', 'docs'] as $dir) {
            $url = $origin . '/' . $dir . '/access-probe.html';
            $body = '';
            $ch = curl_init($url);
            if ($ch === false) {
                $out[] = ['path' => '/' . $dir . '/access-probe.html', 'http' => 0, 'status' => 'FAIL'];
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPGET => true,
                CURLOPT_USERAGENT => 'EPejabat-AccessProtection/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    $body .= substr($chunk, 0, max(0, 1024 - strlen($body)));
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $pass = in_array($status, [403, 404], true)
                && !str_contains($body, 'EPEJABAT_PRIVATE_ACCESS_PROBE');
            $out[] = [
                'path' => '/' . $dir . '/access-probe.html',
                'http' => $status,
                'status' => $pass ? 'PASS' : 'FAIL',
            ];
        }
        return $out;
    }

    public static function assert(string $origin): void {
        foreach (self::check($origin) as $row) {
            if ($row['status'] !== 'PASS') {
                throw new \RuntimeException('BLOCKED: perlindungan folder dalaman gagal. Semak AllowOverride/.htaccess dan TLS.');
            }
        }
    }
}
