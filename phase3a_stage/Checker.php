<?php
declare(strict_types=1);
namespace EPejabat\Phase3A;
use EPejabat\Core\{Database,Authorization,InstallationKey,UpgradePackage,Backup};
final class Checker {
 public static function safe(string $root,string $rel):bool {
  if(!preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D',$rel))return false;
  $cur=rtrim($root,'/');if(is_link($cur))return false;
  foreach(explode('/',$rel) as $part){if($part==='.'||$part==='..')return false;$cur.='/'.$part;if(is_link($cur))return false;}
  return true;
 }
 public static function files(string $root,string $stage,array $plan,string $mode):array {
  if(!in_array($mode,['before','after'],true))throw new \InvalidArgumentException('Mode tidak sah.');
  $rows=[];$expected=$plan['baseline'];
  if($mode==='after')$expected=array_replace($expected,$plan['payload']);
  foreach($expected as $rel=>$hash){$f=$root.'/'.$rel;$ok=self::safe($root,$rel)&&is_file($f)&&hash_equals($hash,hash_file('sha256',$f));$rows[]=['check'=>'Installed file','item'=>$rel,'status'=>$ok?'PASS':'BLOCKED'];}
  foreach($plan['payload'] as $rel=>$hash){$f=$stage.'/payload/'.$rel;$ok=self::safe($stage,'payload/'.$rel)&&is_file($f)&&hash_equals($hash,hash_file('sha256',$f));$rows[]=['check'=>'Staged integrity','item'=>$rel,'status'=>$ok?'PASS':'BLOCKED'];}
  if($mode==='before')foreach($plan['new'] as $rel){$ok=self::safe($root,$rel)&&!file_exists($root.'/'.$rel);$rows[]=['check'=>'New file absence','item'=>$rel,'status'=>$ok?'PASS':'BLOCKED'];}
  return $rows;
 }
 public static function run(\PDO $p,int $actor,string $root,string $stage,array $plan,string $mode):array {
  if(!Database::query($p,'SELECT 1 FROM users WHERE id=? AND is_active=1',[$actor])->fetchColumn()||!Authorization::super($p,$actor))throw new \RuntimeException('Super Admin aktif diperlukan.');
  $rows=self::files($root,$stage,$plan,$mode);
  $add=function($check,$status,$detail='')use(&$rows){$rows[]=['check'=>$check,'status'=>$status,'detail'=>$detail];};
  $v=Database::query($p,"SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('app_version','core_version','module_version.training','module_version.eforms','module_version.public_programs')")->fetchAll(\PDO::FETCH_KEY_PAIR);
  foreach(['app_version','core_version','module_version.training','module_version.eforms'] as $key)$add('Version '.$key,isset($v[$key])?'PASS':'BLOCKED',$v[$key]??'Not recorded');
  $add('Version policy','WARNING','Versions are observed, not invented. Exact pinned source and migration checks determine local compatibility.');
  $add('PHP',version_compare(PHP_VERSION,'8.2','>=')?'PASS':'BLOCKED',PHP_VERSION);
  foreach(['pdo_mysql','openssl','mbstring','fileinfo','zip'] as $ext)$add('Extension '.$ext,extension_loaded($ext)?'PASS':'BLOCKED');
  foreach(['storage/installed.lock','storage/upgrades','storage/backups'] as $path){$f=$root.'/'.$path;$ok=self::safe($root,$path)&&file_exists($f)&&is_readable($f);if(is_dir($f))$ok=$ok&&is_writable($f);$add('Required path '.$path,$ok?'PASS':'BLOCKED');}
  $add('Maintenance lock',file_exists($root.'/storage/upgrades/maintenance.lock')?'BLOCKED':'PASS');
  foreach(array_keys($plan['payload']) as $rel){$dir=dirname($root.'/'.$rel);while(!is_dir($dir)&&$dir!==dirname($dir))$dir=dirname($dir);$ok=self::safe($root,$rel)&&is_writable($dir)&&(!is_file($root.'/'.$rel)||is_writable($root.'/'.$rel));$add('Write permission '.$rel,$ok?'PASS':'BLOCKED');}
  foreach(['200_eforms_schema.sql','201_eforms_seed.php','210_package_pdf_support.sql','211_form_packages.sql','212_package_version.php','220_public_programs.sql','221_public_program_seed.php'] as $f){
   $new=str_starts_with($f,'22');$row=Database::query($p,'SELECT status,checksum FROM database_migrations WHERE version=?',['eforms:Phase2:'.$f])->fetch();$file=($new&&$mode==='before'?$stage.'/payload':$root).'/database/modules/eforms/'.$f;
   $ok=$new&&$mode==='before'?!$row:($row&&$row['status']==='applied'&&is_file($file)&&hash_equals($row['checksum'],hash_file('sha256',$file)));
   $add('Migration '.$f,$ok?'PASS':'BLOCKED',$new&&$mode==='before'?'Must be pending and untracked':'Must be applied with exact checksum');
  }
  foreach(['public_programs','public_guardians','public_participants','public_program_attempts'] as $table){$has=(bool)Database::query($p,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn();$add('Table '.$table,($mode==='before'?!$has:$has)?'PASS':'BLOCKED');}
  $db=Database::query($p,'SELECT VERSION()')->fetchColumn();$add('Database version','PASS',(string)$db);
  if($mode==='after')foreach(['public_programs'=>['id','organisation_id','public_code','quota','version_id'],'public_guardians'=>['id','program_id','contact_cipher','access_hash','access_expires'],'public_participants'=>['id','program_id','guardian_id','identity_hash','profile_cipher','registration_status','clinical_status','payment_status','place_status'],'public_program_attempts'=>['bucket_hash','attempts','expires_at']] as $table=>$columns){$present=Database::query($p,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(\PDO::FETCH_COLUMN);$add('Required columns '.$table,array_diff($columns,$present)?'BLOCKED':'PASS');}
  try{$key=InstallationKey::configured($p);$add('Installation Key configured',$key?'PASS':'BLOCKED');$add('Installation Key verified for session',InstallationKey::authorized($p,$actor)?'PASS':'WARNING','Verification never substitutes publisher signature.');}catch(\Throwable $e){$add('Installation Key interface','BLOCKED','Configuration or interface unavailable; no key/hash disclosed.');}
  $add('Existing backup create interface',method_exists(Backup::class,'create')?'PASS':'BLOCKED','Existing e-Forms migration handler calls Backup::create. No fallback or replacement is installed.');
  $add('Backup restore completeness','WARNING','No backup created and no restore executed. Writable storage does not prove recovery.');
  $add('Private storage HTTP denial','WARNING','Must verify on hosting; no internal HTTP request is made by this checker.');
  try{$trust=\EPejabat\Core\LiveUpgradeManager::trustPolicy();$add('Existing publisher trust',!empty($trust['publishers'])?'PASS':'BLOCKED','Key material is not displayed.');foreach(array_keys($plan['payload']) as $path){$allowed=UpgradePackage::path('root/'.$path,$trust,'public_programs');$add('Upgrade allowlist '.$path,$allowed?'PASS':'BLOCKED');}}catch(\Throwable $e){$add('Existing trust policy','BLOCKED','Trust unavailable or incompatible; never replaced.');}
  $add('Trusted signed release','BLOCKED','This RC1 artifact is unsigned. Existing UpgradePackage::verify requires a trusted manifest signature. Automatic installation is disabled.');
  $add('Installer operation','BLOCKED','No file copy, backup creation, migration or install-history mutation is executed by this checker. Use only a separately approved supported deployment path.');
  $history=Database::query($p,"SELECT module_key,target_version,status,completed_at FROM upgrade_history WHERE module_key IN ('public_programs','eforms') ORDER BY completed_at DESC LIMIT 10")->fetchAll();
  if($mode==='after'){$add('Phase3A version',($v['module_version.public_programs']??'')==='0.1.0-rc1'?'PASS':'BLOCKED',$v['module_version.public_programs']??'Absent');$add('Repeat install','BLOCKED','Verification-only mode; never reinstall applied migrations.');}
  return ['checked_at'=>gmdate('c'),'mode'=>$mode,'status'=>'BLOCKED','automatic_install'=>'BLOCKED_UNSIGNED','versions'=>$v,'history'=>$history,'checks'=>$rows,'smoke_tests'=>array_map(fn($n)=>['test'=>$n,'status'=>'NOT TESTED'],['Admin interactive login','Program Manager browser','Public and guardian registration','Add child mobile','MyKid duplicate behaviour','Concurrent quota','Waiting list promotion','Approved email delivery','Admin dashboard','Chrome and Safari/iPhone'])];
 }
}
