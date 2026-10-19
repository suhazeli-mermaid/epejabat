<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Core {
    public static function tx(\PDO $p,callable $fn): mixed {
        if($p->inTransaction()) return $fn();
        $p->beginTransaction();try {$v=$fn();$p->commit();return $v;}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    public static function text(mixed $v,int $max=190,bool $required=true): string {
        if(!is_string($v)||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v)||($required&&trim($v)==='')) throw new \InvalidArgumentException('Input tidak sah.'); return trim($v);
    }
    public static function email(mixed $v): string { $v=mb_strtolower(self::text($v));if(!filter_var($v,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$v))throw new \InvalidArgumentException('E-mel tidak sah.');return $v; }
    public static function id(mixed $v,bool $nullable=false): ?int {
        if($nullable&&($v===''||$v===null))return null;
        if(!is_scalar($v)||!preg_match('/\A[1-9][0-9]{0,17}\z/',(string)$v))throw new \InvalidArgumentException('ID tidak sah.');return (int)$v;
    }
    public static function password(string $v): string {if(strlen($v)<12||strlen($v)>72||str_contains($v,"\0"))throw new \InvalidArgumentException('Kata laluan mesti 12–72 bait.');return $v;}
    public static function uuid(): string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    public static function module(\PDO $p,string $key,bool $active=true): array {
        $r=Database::query($p,'SELECT * FROM module_registry WHERE module_key=?',[$key])->fetch();if(!$r||($active&&$r['status']!=='active'))throw new \RuntimeException('Modul tidak tersedia.');return $r;
    }
}
