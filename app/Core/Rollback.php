<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Rollback {
 public static function files(string $id):array {
  if(PHP_SAPI!=='cli'||!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new \RuntimeException('CLI run ID diperlukan.');$dir=APP_ROOT.'/storage/upgrades';$lock=fopen($dir.'/upgrade-process.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Upgrade aktif.');try{
   foreach(['blocked.lock','maintenance.lock'] as $l)if(!is_file($dir.'/'.$l)||trim(file_get_contents($dir.'/'.$l))!==$id)throw new \RuntimeException('Recovery lock/run mismatch.');$d=$dir.'/'.$id;if(UpgradePackage::symlink($d))throw new \RuntimeException('Symlink recovery ditolak.');$state=json_decode(file_get_contents($d.'/state.json'),true,512,JSON_THROW_ON_ERROR);$z=new \ZipArchive();if($z->open($d.'/files-backup.zip')!==true)throw new \RuntimeException('Backup tiada.');$restore=[];
   try{foreach($state['manifest']['files'] as $r){$rel=substr($r['file'],5);if(!preg_match('~\A(?:app|assets|database|bin)/[A-Za-z0-9_./-]+\z~',$rel)||in_array('..',explode('/',$rel),true))throw new \RuntimeException('Rollback path ditolak.');$f=APP_ROOT.'/'.$rel;if(UpgradePackage::symlink($f))throw new \RuntimeException('Symlink recovery ditolak.');$actual=is_file($f)?hash_file('sha256',$f):null;if($actual!==$r['after_sha256']&&$actual!==$r['before_sha256'])throw new \RuntimeException('Rollback hash conflict: '.$rel);$bytes=$r['before_sha256']===null?null:$z->getFromName($r['file']);if($r['before_sha256']!==null&&(!is_string($bytes)||!hash_equals($r['before_sha256'],hash('sha256',$bytes))))throw new \RuntimeException('Backup checksum conflict.');$restore[]=[$f,$bytes];}foreach($restore as [$f,$bytes]){if($bytes===null){if(is_file($f)&&!unlink($f))throw new \RuntimeException('Rollback delete gagal.');}else{Support::secureWrite($f,$bytes);if(str_contains($f,'/assets/'))chmod($f,0644);}if(str_ends_with($f,'.php')&&function_exists('opcache_invalidate'))@opcache_invalidate($f,true);}}finally{$z->close();}
   return ['status'=>'FILES_RESTORED','database'=>'MANUAL RECOVERY REQUIRED','locks'=>'RETAINED: do not resume writes until reviewed database recovery'];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
