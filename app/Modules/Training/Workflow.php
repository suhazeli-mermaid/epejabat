<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
use EPejabat\Core\{Database,Core,Authorization};
final class Workflow {
 public static function date(mixed $v):string {$s=Core::text($v,19);$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$s,new \DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d H:i:s')!==$s)throw new \InvalidArgumentException('UTC timestamp YYYY-MM-DD HH:MM:SS diperlukan.');return $s;}
 public static function number(mixed $v,float $max=1000000):float {if(!is_numeric($v)||!is_finite((float)$v)||$v<0||$v>$max)throw new \InvalidArgumentException('Nilai angka tidak sah.');return (float)$v;}
 public static function registration(\PDO $p,int $u,int $programme,int $rid,string $adminAction='participants.view',bool $confirmed=true):array {ExtendedMigrations::require($p);$r=Policy::programme($p,$programme);$a=Policy::actor($p,$u);$reg=Database::query($p,'SELECT * FROM training_registrations WHERE id=? AND programme_id=?',[$rid,$programme])->fetch();if(!$reg||$confirmed&&$reg['status']!=='CONFIRMED')throw new \RuntimeException('Pendaftaran confirmed diperlukan.');if((int)$reg['staff_identity_id']!==(int)$a['staff_identity_id'])Policy::require($p,$u,$adminAction,$r);return $reg;}
 public static function reviewer(\PDO $p,int $u,int $creator):void {if($u===$creator&&!Authorization::super($p,$u))throw new \RuntimeException('Independent reviewer diperlukan.');}
 public static function json(array $a):string {return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
}
