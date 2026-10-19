<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class UpgradePackage {
 public static function publicProgramPaths():array {return ['root/public-program.php','root/assets/public-program.css','root/assets/public-program.js','root/database/eforms-manifest.json','root/app/Modules/PublicPrograms/Service.php','root/app/Modules/PublicPrograms/Web.php','root/database/modules/eforms/220_public_programs.sql','root/database/modules/eforms/221_public_program_seed.php']; }
 public static function path(string $f,array $trust,string $module):bool {
  if(($trust['owner_authorized']??false)===true&&UpgradePolicy::protectedPath($f))return false;
  if(!preg_match('~\A(root)/[A-Za-z0-9_./-]+\z~',$f)||in_array('..',explode('/',$f),true)||str_contains($f,'//'))return false;
  if($module==='public_programs')return ($trust['modules'][$module]??null)==='PublicPrograms'&&in_array($f,self::publicProgramPaths(),true);
  if(in_array($f,$trust['shared_core_allowlist'],true))return true;
  $ns=$trust['modules'][$module]??null;if(!is_string($ns))return false;
  return (bool)preg_match('~\Aroot/(app/Modules/'.preg_quote($ns,'~').'/[A-Za-z]+\.php|database/modules/'.preg_quote($module,'~').'(?:_complete)?/[0-9]+_[A-Za-z_]+\.(sql|php)|bin/'.preg_quote($module,'~').'\.php)\z~',$f);
 }
 public static function verify(string $file,string $expected,array $trust):array {
  if(!preg_match('/\A[0-9a-f]{64}\z/',$expected)||!is_file($file)||is_link($file)||filesize($file)>33554432||!hash_equals($expected,hash_file('sha256',$file)))throw new \RuntimeException('Package SHA-256 tidak sepadan.');
  $z=new \ZipArchive();if($z->open($file)!==true)throw new \RuntimeException('ZIP tidak sah.');
  try{
   if($z->numFiles>300)throw new \RuntimeException('ZIP terlalu besar.');$names=[];$size=0;
   for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$n=$s['name'];if(isset($names[$n])||str_contains($n,'..')||str_contains($n,'\\')||str_starts_with($n,'/')||str_contains($n,"\0"))throw new \RuntimeException('Path ZIP tidak sah.');$names[$n]=true;$size+=$s['size'];if($s['size']>8388608||$size>67108864||$s['size']>0&&$s['comp_size']>0&&$s['size']/$s['comp_size']>200)throw new \RuntimeException('Had ZIP dilampaui.');$ops=0;$attr=0;$z->getExternalAttributesIndex($i,$ops,$attr);$mode=($attr>>16)&0170000;if($mode!==0&&$mode!==0100000)throw new \RuntimeException('ZIP symlink/directory tidak dibenarkan.');}
   $raw=$z->getFromName('manifest.json');$sig=$z->getFromName('manifest.sig');if(!is_string($raw)||strlen($raw)>262144)throw new \RuntimeException('Manifest tiada.');$m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$owner=($m['format']??null)==='epejabat-owner-upgrade-v1';if($owner&&(($trust['owner_authorized']??false)!==true||$sig!==false))throw new \RuntimeException('Owner policy belum aktif atau signature tidak dijangka.');
   if(!$owner){$pub=$trust['publishers'][$m['publisher']??'']??null;$bytes=is_string($sig)?base64_decode($sig,true):false;if(!is_string($pub)||!is_string($bytes)||openssl_verify($raw,$bytes,$pub,OPENSSL_ALGO_SHA256)!==1)throw new \RuntimeException('Publisher/signature tidak dipercayai.');}
   if(!in_array($m['format']??null,[$owner?'epejabat-owner-upgrade-v1':'epejabat-upgrade-v1'],true)||!preg_match('/\A[a-z][a-z0-9_]{1,30}\z/',$m['module']??'')||!isset($trust['modules'][$m['module']])||!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/',$m['version']??'')||($m['php_min']??'')!=='8.2'||!is_array($m['files']??null)||!count($m['files']))throw new \RuntimeException('Package compatibility tidak sah.');
   $allowed=$owner?['manifest.json']:['manifest.json','manifest.sig'];$core=[];$seen=[];
   foreach($m['files'] as $r){$f=$r['file']??'';if(!is_string($f)||!self::path($f,$trust,$m['module'])||isset($seen[$f])||!array_key_exists('before_sha256',$r)||$r['before_sha256']!==null&&!preg_match('/\A[0-9a-f]{64}\z/',$r['before_sha256'])||!preg_match('/\A[0-9a-f]{64}\z/',$r['after_sha256']??''))throw new \RuntimeException('File allowlist/hash tidak sah.');$seen[$f]=true;$n='payload/'.$f;$allowed[]=$n;$b=$z->getFromName($n);if(!is_string($b)||!hash_equals($r['after_sha256'],hash('sha256',$b)))throw new \RuntimeException('Payload integrity gagal.');if(in_array($f,$trust['shared_core_allowlist'],true))$core[]=$f;}
   sort($core);$declared=$m['shared_core_changes']??[];sort($declared);if($core!==$declared)throw new \RuntimeException('Shared Core changes tidak dinyatakan.');sort($allowed);$actual=array_keys($names);sort($actual);if($allowed!==$actual)throw new \RuntimeException('Fail ZIP tambahan atau tiada.');
   self::validateMigrations($m);
   return ['manifest'=>$m,'zip_sha256'=>$expected,'manifest_sha256'=>hash('sha256',$raw)];
  }finally{$z->close();}
 }
 public static function validateMigrations(array $m):void {
  $seen=array_fill_keys(array_column($m['files'],'file'),true);
   if(!in_array($m['migration_adapter']??'', ['training-v1','core-v1','eforms-v1','none'],true)||!is_array($m['migrations']??null))throw new \RuntimeException('Migration adapter tidak berdaftar.');
   $migrationFiles=[];$migrationVersions=[];foreach($m['migrations'] as $mi){if(isset($migrationFiles[$mi['file']??''])||isset($migrationVersions[$mi['version']??'']))throw new \RuntimeException('Duplicate migration.');$migrationFiles[$mi['file']]=true;$derived=$m['migration_adapter']==='eforms-v1'?'eforms:Phase2:'.basename($mi['file']):($m['module']==='core'?basename($mi['file']):'training:'.(str_contains($mi['file'],'/training_complete/')?'FULL:':'T1:').basename($mi['file']));if(($mi['version']??'')!==$derived)throw new \RuntimeException('Migration filename/version mismatch.');$migrationVersions[$mi['version']]=true;if(!isset($seen[$mi['file']??''])||!(($m['module']==='training'&&$m['migration_adapter']==='training-v1'&&preg_match('/\Atraining:(T1|FULL):[0-9]+_[A-Za-z_]+\.(sql|php)\z/',$mi['version']??''))||($m['module']==='public_programs'&&$m['migration_adapter']==='eforms-v1'&&in_array($mi['file'],['root/database/modules/eforms/220_public_programs.sql','root/database/modules/eforms/221_public_program_seed.php'],true))||($m['module']==='core'&&$m['migration_adapter']==='core-v1'&&preg_match('/\A[0-9]+_[A-Za-z_]+\.(sql|php)\z/',$mi['version']??''))))throw new \RuntimeException('Migration declaration tidak sah.');}
   foreach($m['files'] as $r)if(str_contains($r['file'],'/database/modules/')&&!isset($migrationFiles[$r['file']]))throw new \RuntimeException('Undeclared migration payload.');if($m['migration_adapter']==='none'&&$m['migrations'])throw new \RuntimeException('Migration adapter required.');
   if($m['migration_adapter']==='eforms-v1'&&($m['module']!=='public_programs'||array_column($m['migrations'],'file')!==['root/database/modules/eforms/220_public_programs.sql','root/database/modules/eforms/221_public_program_seed.php']))throw new \RuntimeException('Phase 3A migration dependency order tidak sah.');
 }
 public static function symlink(string $p):bool {$c='';foreach(explode('/',ltrim($p,'/')) as $s){$c.='/'.$s;if(is_link($c))return true;}return false;}
 public static function baseline(string $root,array $r):array {
  $path=$root.'/'.$r['relative'];$parent=dirname($path);while(!file_exists($parent)&&!is_link($parent)&&dirname($parent)!==$parent)$parent=dirname($parent);
  if(self::symlink($path))return ['status'=>'FAIL','reason'=>'SYMLINK'];if(!is_dir($parent)||!is_readable($parent)||!is_executable($parent)||!is_writable($parent))return ['status'=>'FAIL','reason'=>'PERMISSIONS'];
  if($r['before_sha256']===null)return ['status'=>file_exists($path)?'FAIL':'PASS','reason'=>file_exists($path)?'NEW_PATH_EXISTS':'ABSENT'];
  $h=is_file($path)&&is_readable($path)?hash_file('sha256',$path):null;return ['status'=>is_string($h)&&hash_equals($r['before_sha256'],$h)&&is_writable($path)?'PASS':'FAIL','reason'=>'BASELINE','actual_sha256'=>$h];
 }
}
