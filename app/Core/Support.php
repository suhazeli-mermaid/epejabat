<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Support {
    public static function escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function basePath(): string {
        $base = $GLOBALS['app_base_path'] ?? '';
        return is_string($base) ? $base : '';
    }
    public static function url(string $path): string {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) return $path;
        $base = self::basePath();
        if ($base === '' || $path === $base || str_starts_with($path, $base . '/')
            || str_starts_with($path, $base . '?') || str_starts_with($path, $base . '#')) return $path;
        return $base . ($path === '/' ? '/' : $path);
    }
    public static function withBasePath(string $html): string {
        if (self::basePath() === '') return $html;
        return preg_replace_callback(
            '/\b(href|src|action)=(["\'])(\/(?!\/)[^"\']*)\2/i',
            static fn(array $match): string => $match[1] . '=' . $match[2] . self::url($match[3]) . $match[2],
            $html
        ) ?? $html;
    }
    public static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function checkCsrf(): void {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals(self::csrf(), $_POST['csrf'])) { http_response_code(403); throw new \RuntimeException('Permintaan tidak sah. Muat semula halaman.'); }
    }
    public static function redirect(string $path): never { header('Location: '.self::url($path), true, 303); exit; }
    public static function post(string $key, int $max=255): string {
        $v=$_POST[$key] ?? ''; if (!is_string($v) || mb_strlen($v)>$max) throw new \InvalidArgumentException('Input tidak sah.'); return trim($v);
    }
    public static function password(string $key='password'): string {
        $v=$_POST[$key] ?? ''; if (!is_string($v) || strlen($v)<12 || strlen($v)>72 || str_contains($v,"\0")) throw new \InvalidArgumentException('Kata laluan mesti 12–72 bait.'); return $v;
    }
    public static function secureWrite(string $file, string $content): void {
        $tmp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($tmp,$content,LOCK_EX)===false || !chmod($tmp,0600) || !rename($tmp,$file)) { @unlink($tmp); throw new \RuntimeException('Tidak dapat menyimpan konfigurasi. Semak permissions.'); }
    }
    public static function log(\Throwable $e): string {
        $id=bin2hex(random_bytes(8));
        // Never write exception messages, stack traces, request bodies or SQL/credentials.
        @file_put_contents(APP_ROOT.'/storage/logs/error.log',date('c').' '.$id.' '.get_class($e)."\n",FILE_APPEND|LOCK_EX);
        return $id;
    }
    public static function layout(string $title, string $body): never {
        Ui::layout($title,$body);
    }
    public static function fields(array $fields): string {
        $html=''; foreach($fields as $key=>$spec) { [$label,$type,$value]=$spec+[2=>'']; $html.='<label>'.self::escape($label).'<input name="'.self::escape($key).'" type="'.self::escape($type).'" value="'.self::escape($value).'" required maxlength="'.($key==='db_password'?'1024':($key==='token'?'255':($type==='password'?'72':'255'))).'"'.($type==='password'?' autocomplete="new-password"':'').'></label>'; } return $html;
    }
    public static function form(string $action, string $fields, string $button): string {
        return '<form method="post"><input type="hidden" name="csrf" value="'.self::csrf().'"><input type="hidden" name="action" value="'.self::escape($action).'">'.$fields.'<button>'.self::escape($button).'</button></form>';
    }
}
