<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Crypto {
    public static function initialize(): void {
        $f=APP_ROOT.'/config/core.key'; if(is_file($f)) {self::key();return;}
        $h=fopen($f,'x'); if(!$h) throw new \RuntimeException('Kunci keselamatan tidak tersedia.');
        try {if(!chmod($f,0600)||fwrite($h,bin2hex(random_bytes(32)))!==64) throw new \RuntimeException('Gagal menyimpan kunci.');} finally {fclose($h);}
    }
    public static function key(): string {
        $v=is_file(APP_ROOT.'/config/core.key')?trim(file_get_contents(APP_ROOT.'/config/core.key')):'';
        if(!preg_match('/\A[a-f0-9]{64}\z/',$v)) throw new \RuntimeException('Kunci keselamatan tidak tersedia.'); return hex2bin($v);
    }
    public static function seal(string $plain): string {
        $iv=random_bytes(12);$tag='';$c=openssl_encrypt($plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'epejabat-core-v1',16);
        if($c===false) throw new \RuntimeException('Penyulitan gagal.');return base64_encode($iv.$tag.$c);
    }
    public static function open(string $cipher): string {
        $raw=base64_decode($cipher,true); if($raw===false||strlen($raw)<28) throw new \RuntimeException('Data terlindung tidak sah.');
        $p=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'epejabat-core-v1');
        if($p===false) throw new \RuntimeException('Data terlindung tidak sah.');return $p;
    }
}
