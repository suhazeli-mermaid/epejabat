<?php
declare(strict_types=1);
use EPejabat\Core\{UpgradePackage,Support,Database,Versions,Authorization,InstallationKey,Backup,Crypto,Audit,AccessProtection};
/** Fixed maintenance operation; never accepts an uploaded manifest or arbitrary destination. */
final class PolicyTransition {
 const FILES=['app/Core/LiveUpgradeManager.php','app/Core/ProductionBaseline.php','app/Core/UpgradePackage.php','app/Core/UpgradePolicy.php','app/Core/UpgradeWeb.php'];
 public static function dir():string{return APP_ROOT.'/storage/upgrades/policy-transition';}
 public static function manifest():array {
  $m=json_decode(file_get_contents(__DIR__.'/transition-manifest.json'),true,64,JSON_THROW_ON_ERROR);
  if(($m['format']??'')!=='epejabat-hosting-policy-transition-v1'||array_column($m['files']??[],'file')!==self::FILES||($m['from_app']??'')!=='1.2.0'||($m['to_app']??'')!=='1.2.1')throw new RuntimeException('Transition manifest tidak sah.');
  return $m;
 }
 public static function write(string $f,string $s):void {if(UpgradePackage::symlink($f))throw new RuntimeException('Symlink ditolak.');Support::secureWrite($f,$s);}
 public static function seal(array $p):string {$raw=json_encode($p,JSON_THROW_ON_ERROR);return json_encode(['payload'=>$raw,'hmac'=>hash_hmac('sha256',$raw,Crypto::key())],JSON_THROW_ON_ERROR);}
 public static function open(string $raw):array {$e=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_string($e['payload']??null)||!is_string($e['hmac']??null)||!hash_equals(hash_hmac('sha256',$e['payload'],Crypto::key()),$e['hmac']))throw new RuntimeException('Maintenance integrity gagal.');return json_decode($e['payload'],true,64,JSON_THROW_ON_ERROR);}
 public static function authorize(PDO $p,int $actor):void {if(!Authorization::super($p,$actor))throw new RuntimeException('Super Admin diperlukan.');InstallationKey::requireAuthorization($p,$actor);}
 public static function checks(PDO $p,int $actor):array {
  self::authorize($p,$actor);$m=self::manifest();$r=[];$add=function($n,$ok)use(&$r){$r[]=['check'=>$n,'status'=>$ok?'PASS':'BLOCKED'];};
  $add('PHP 8.2',PHP_VERSION_ID>=80200);$v=Versions::read($p);$add('App/Core baseline 1.2.0',($v['app_version']??'')==='1.2.0'&&($v['core_version']??'')==='1.2.0');
  $add('Installation completed',is_file(APP_ROOT.'/storage/installed.lock'));$add('Policy not activated',!file_exists(APP_ROOT.'/config/upgrade-policy.json'));
  $add('No installation/recovery lock',!file_exists(APP_ROOT.'/storage/upgrades/maintenance.lock')&&!file_exists(APP_ROOT.'/storage/upgrades/blocked.lock'));
  foreach($m['files'] as $f){$add('Baseline '.$f['file'],UpgradePackage::baseline(APP_ROOT,$f+['relative'=>$f['file']])['status']==='PASS');$path=__DIR__.'/payload/'.$f['file'];$add('Payload '.$f['file'],!UpgradePackage::symlink($path)&&is_file($path)&&hash_equals($f['after_sha256'],hash_file('sha256',$path)));}
  $add('Backup implementation pin',hash_file('sha256',APP_ROOT.'/app/Core/Backup.php')==='f362f0c6535ae8eb31543cbadf1a573b9a1967448da8c721d8e89ec3f5ceddae');
  try{Backup::readiness($p,$actor);$add('Database backup readiness',true);}catch(Throwable $e){$add('Database backup readiness',false);}
  try{AccessProtection::assert((require APP_ROOT.'/config/app.php')['app_url']);$add('HTTP internal storage protection',true);}catch(Throwable $e){$add('HTTP internal storage protection',false);}
  return ['status'=>in_array('BLOCKED',array_column($r,'status'),true)?'BLOCKED':'PASS','checks'=>$r];
 }
 public static function grant(PDO $p,int $actor,string $action):void {
  self::authorize($p,$actor);if(!in_array($action,['activate','rollback'],true))throw new RuntimeException('Action invalid.');
  if($action==='activate'&&self::checks($p,$actor)['status']!=='PASS')throw new RuntimeException('Preflight BLOCKED.');
  $d=self::dir();if(UpgradePackage::symlink($d))throw new RuntimeException('Unsafe storage.');if(!is_dir($d)&&!mkdir($d,0700,true))throw new RuntimeException('Storage gagal.');
  $g=['actor'=>$actor,'action'=>$action,'auth'=>$_SESSION['upgrade_key_auth'],'expires'=>time()+600,'root'=>hash('sha256',realpath(APP_ROOT)),'manifest'=>hash_file('sha256',__DIR__.'/transition-manifest.json'),'nonce'=>bin2hex(random_bytes(16))];
  self::write($d.'/approval.json',self::seal($g));Audit::record($p,'core.policy_hosting_authorized',$actor,null,$g['nonce'],'core',null,null,['action'=>$action,'expires'=>$g['expires']]);
 }
 public static function consume(PDO $p,string $action):int {
  $f=self::dir().'/approval.json';if(UpgradePackage::symlink($f)||!is_file($f))throw new RuntimeException('Browser Super Admin authorization diperlukan.');$g=self::open(file_get_contents($f));
  if(($g['action']??'')!==$action||($g['expires']??0)<time()||($g['root']??'')!==hash('sha256',realpath(APP_ROOT))||($g['manifest']??'')!==hash_file('sha256',__DIR__.'/transition-manifest.json'))throw new RuntimeException('Authorization tamat atau tidak sepadan.');
  $_SESSION['upgrade_key_auth']=$g['auth'];$actor=(int)$g['actor'];self::authorize($p,$actor);if(!unlink($f))throw new RuntimeException('Cannot consume grant.');return $actor;
 }
 public static function run(PDO $p,string $action):array {
  if(PHP_SAPI!=='cli')throw new RuntimeException('Hosting administration only.');$lock=APP_ROOT.'/storage/upgrades/upgrade-process.lock';if(UpgradePackage::symlink($lock))throw new RuntimeException('Unsafe lock.');$h=fopen($lock,'c');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new RuntimeException('Upgrade sedang berjalan.');$dbLock=false;$changed=false;
  try{
   $dbLock=(int)Database::query($p,"SELECT GET_LOCK(CONCAT('ep_upgrade_',LEFT(SHA2(DATABASE(),256),40)),1)")->fetchColumn()===1;if(!$dbLock)throw new RuntimeException('Database lock aktif.');
   $actor=self::consume($p,$action);$m=self::manifest();$d=self::dir();
   if($action==='rollback')return self::rollback($p,$actor,$m);
   if($action!=='activate'||self::checks($p,$actor)['status']!=='PASS')throw new RuntimeException('Preflight BLOCKED.');
   if(file_exists($d.'/state.json'))throw new RuntimeException('Transition pernah dimulakan. Gunakan reviewed recovery.');
   $backup=Backup::create($p,$actor,bin2hex(random_bytes(16)));Backup::verify($backup['reference'],$backup['at']);
   foreach($m['files'] as $f)if($f['before_sha256']!==null){$to=$d.'/'.basename($f['file']).'.before';self::write($to,file_get_contents(APP_ROOT.'/'.$f['file']));if(!hash_equals($f['before_sha256'],hash_file('sha256',$to)))throw new RuntimeException('File backup tidak sepadan.');}
   if(self::checks($p,$actor)['status']!=='PASS')throw new RuntimeException('Preflight changed.');
   $s=['history_run'=>'policy-'.bin2hex(random_bytes(16)),'history_max_id'=>(int)$p->query('SELECT COALESCE(MAX(id),0) FROM upgrade_history')->fetchColumn(),'status'=>'APPLYING','backup'=>$backup,'actor'=>$actor,'manifest_sha256'=>hash_file('sha256',__DIR__.'/transition-manifest.json'),'at'=>gmdate('c')];self::write($d.'/state.json',self::seal($s));
   self::write(APP_ROOT.'/storage/upgrades/maintenance.lock','policy-transition');$changed=true;
   register_shutdown_function(function()use(&$changed){if($changed)self::write(APP_ROOT.'/storage/upgrades/blocked.lock','policy-transition interrupted');});
   foreach($m['files'] as $f){if(UpgradePackage::baseline(APP_ROOT,$f+['relative'=>$f['file']])['status']!=='PASS')throw new RuntimeException('Baseline changed.');$bytes=file_get_contents(__DIR__.'/payload/'.$f['file']);if(!hash_equals($f['after_sha256'],hash('sha256',$bytes)))throw new RuntimeException('Payload changed.');$to=APP_ROOT.'/'.$f['file'];self::write($to,$bytes);chmod($to,0644);if(function_exists('opcache_invalidate'))opcache_invalidate($to,true);if(!hash_equals($f['after_sha256'],hash_file('sha256',$to)))throw new RuntimeException('Copy failed.');}
   self::write(APP_ROOT.'/config/upgrade-policy.json',self::seal(['mode'=>'owner-authorized-v1','root_sha256'=>hash('sha256',realpath(APP_ROOT)),'activated_at'=>gmdate('c'),'actor'=>$actor]));
   $p->beginTransaction();try{Versions::set($p,'app_version','1.2.1');Versions::set($p,'core_version','1.2.1');Database::query($p,"INSERT INTO upgrade_history(run_id,module_key,previous_version,target_version,status,actor_id,completed_at) VALUES(?,'core','1.2.0','1.2.1','VERIFIED_LOCAL_INSTALL',?,UTC_TIMESTAMP())",[$s['history_run'],$actor]);Audit::record($p,'core.policy_transition_activated',$actor,null,'owner-authorized-v1','core',null,null,['manifest'=>$s['manifest_sha256']]);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
   $s['status']='ACTIVATED';self::write($d.'/state.json',self::seal($s));unlink(APP_ROOT.'/storage/upgrades/maintenance.lock');$changed=false;return ['status'=>'ACTIVATED','next'=>'/upgrade','version'=>'1.2.1'];
  }catch(Throwable $e){if($changed)self::write(APP_ROOT.'/storage/upgrades/blocked.lock','policy-transition');throw $e;}
  finally{if($dbLock)Database::query($p,"SELECT RELEASE_LOCK(CONCAT('ep_upgrade_',LEFT(SHA2(DATABASE(),256),40)))");flock($h,LOCK_UN);fclose($h);}
 }
 private static function rollback(PDO $p,int $actor,array $m):array {
  $d=self::dir();$sf=$d.'/state.json';if(UpgradePackage::symlink($sf)||!is_file($sf))throw new RuntimeException('Recovery state missing.');$s=self::open(file_get_contents($sf));
  if(!in_array($s['status'],['ACTIVATED','APPLYING'],true)||$s['manifest_sha256']!==hash_file('sha256',__DIR__.'/transition-manifest.json'))throw new RuntimeException('Recovery mismatch.');
  if(Database::query($p,"SELECT COUNT(*) FROM database_migrations WHERE version IN ('eforms:Phase2:220_public_programs.sql','eforms:Phase2:221_public_program_seed.php')")->fetchColumn()>0)throw new RuntimeException('Phase3A installed: separate reviewed database recovery required.');
  if(Database::query($p,"SELECT COUNT(*) FROM upgrade_history WHERE id>? AND run_id<>? AND status IN ('VERIFIED_LOCAL_INSTALL','RECOVERY_REQUIRED','COPYING','MIGRATING')",[$s['history_max_id'],$s['history_run']])->fetchColumn()>0)throw new RuntimeException('Later installation requires reviewed recovery.');
  $v=Versions::read($p);if(!in_array($v['app_version']??'',['1.2.0','1.2.1'],true)||!in_array($v['core_version']??'',['1.2.0','1.2.1'],true))throw new RuntimeException('Later release installed.');
  foreach(['maintenance.lock','blocked.lock'] as $l){$f=APP_ROOT.'/storage/upgrades/'.$l;if(file_exists($f)&&(UpgradePackage::symlink($f)||!str_starts_with(file_get_contents($f),'policy-transition')))throw new RuntimeException('Other deployment recovery lock.');}
  foreach($m['files'] as $f){$to=APP_ROOT.'/'.$f['file'];if(UpgradePackage::symlink($to))throw new RuntimeException('Unsafe recovery.');$h=is_file($to)?hash_file('sha256',$to):null;if(!in_array($h,[$f['before_sha256'],$f['after_sha256']],true))throw new RuntimeException('Later file changes; rollback stopped.');if($f['before_sha256']!==null){$b=$d.'/'.basename($f['file']).'.before';if(UpgradePackage::symlink($b)||!is_file($b)||!hash_equals($f['before_sha256'],hash_file('sha256',$b)))throw new RuntimeException('Backup integrity failed.');}}
  self::write(APP_ROOT.'/storage/upgrades/maintenance.lock','policy-transition rollback');self::write(APP_ROOT.'/storage/upgrades/blocked.lock','policy-transition rollback');
  foreach($m['files'] as $f){$to=APP_ROOT.'/'.$f['file'];if($f['before_sha256']===null){if(file_exists($to)&&!unlink($to))throw new RuntimeException('Remove failed.');}else{self::write($to,file_get_contents($d.'/'.basename($f['file']).'.before'));chmod($to,0644);}if(function_exists('opcache_invalidate'))opcache_invalidate($to,true);}
  $marker=APP_ROOT.'/config/upgrade-policy.json';if(UpgradePackage::symlink($marker))throw new RuntimeException('Unsafe policy.');if(file_exists($marker)&&!unlink($marker))throw new RuntimeException('Policy removal failed.');
  $p->beginTransaction();try{Versions::set($p,'app_version','1.2.0');Versions::set($p,'core_version','1.2.0');Database::query($p,"UPDATE upgrade_history SET status='ROLLED_BACK' WHERE run_id=?",[$s['history_run']]);Audit::record($p,'core.policy_transition_rolled_back',$actor);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
  $s['status']='ROLLED_BACK';self::write($sf,self::seal($s));unlink(APP_ROOT.'/storage/upgrades/blocked.lock');unlink(APP_ROOT.'/storage/upgrades/maintenance.lock');return ['status'=>'ROLLED_BACK'];
 }
}
