<?php
declare(strict_types=1);
// One-time adapter: verified Backup class + existing LiveUpgradeManager. No new installer or trust key.
use EPejabat\Core\{Support,Database,Auth,Authorization,InstallationKey,UpgradePackage,LiveUpgradeManager,Backup};
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');header('X-Robots-Tag: noindex, nofollow');
if(!$config||(!$https&&!$isLocal)){http_response_code(403);exit('HTTPS dan pemasangan aktif diperlukan.');}
$p=Database::connect($config['db']);$user=Auth::user($p);if(!$user)Support::redirect('/login');$u=(int)$user['id'];if(!Authorization::super($p,$u)){http_response_code(403);exit('Super Admin aktif diperlukan.');}
$manager=new LiveUpgradeManager($p,$u,APP_ROOT);$dir=APP_ROOT.'/storage/upgrades/backup-repair-v107';$used=$dir.'/used.lock';
if(UpgradePackage::symlink($dir))throw new RuntimeException('Private path tidak selamat.');if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Private storage gagal.');
if(file_exists($used)){http_response_code(410);Support::layout('Backup hotfix','<p>Adapter sudah digunakan. Padam fail epejabat-backup-repair.php melalui cPanel. Jangan reset used.lock.</p>');}
$raw=static function(string $name,int $max=72):string{$s=$_POST[$name]??'';if(!is_string($s)||strlen($s)>$max||str_contains($s,"\0"))throw new InvalidArgumentException('Input tidak sah.');return $s;};$error='';$result='';
$load=static function(array $state)use($manager,$p,$u,$dir):void{
 if(class_exists(Backup::class,false))throw new RuntimeException('Backup lama telah dimuatkan; hentikan dan gunakan request baharu.');
 $id=$state['id'];$current=$manager->state($id);$zip=APP_ROOT.'/storage/upgrades/'.$id.'/package.zip';
 if($current['zip_sha256']!==$state['sha'])throw new RuntimeException('Staged package berubah.');
 $v=UpgradePackage::verify($zip,$state['sha'],LiveUpgradeManager::trustPolicy());$m=$v['manifest'];
 $paths=['root/app/Core/Backup.php','root/app/Core/LiveUpgradeManager.php','root/app/Core/UpgradeWeb.php'];$actual=array_column($m['files'],'file');sort($actual);
 if($m['version']!=='1.0.7'||$m['module']!=='core'||$actual!==$paths||$m['migrations']!==[]||($m['required_versions']['app_version']??'')!=='1.0.5')throw new RuntimeException('Adapter hanya menerima pakej hotfix core 1.0.7 daripada 1.0.5.');
 foreach($m['files'] as $r){$b=UpgradePackage::baseline(APP_ROOT,['relative'=>substr($r['file'],5),'before_sha256'=>$r['before_sha256']]);if($b['status']!=='PASS')throw new RuntimeException('Baseline mismatch: '.$r['file']);}
 $z=new ZipArchive();if($z->open($zip)!==true)throw new RuntimeException('ZIP tidak sah.');try{$bytes=$z->getFromName('payload/root/app/Core/Backup.php');}finally{$z->close();}
 $row=array_values(array_filter($m['files'],static fn($r)=>$r['file']==='root/app/Core/Backup.php'))[0];
 if(!is_string($bytes)||!hash_equals($row['after_sha256'],hash('sha256',$bytes)))throw new RuntimeException('Verified Backup payload berubah.');
 // Exclusive private candidate, integrity checked immediately before load. Never copy over production here.
 $path=$dir.'/candidate-'.bin2hex(random_bytes(16)).'.php';$h=fopen($path,'xb');if(!$h)throw new RuntimeException('Candidate storage gagal.');try{if(fwrite($h,$bytes)!==strlen($bytes)||!chmod($path,0600)||!fflush($h))throw new RuntimeException('Candidate write gagal.');}finally{fclose($h);}
 try{if(UpgradePackage::symlink($path)||!hash_equals($row['after_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Candidate integrity gagal.');require $path;}finally{if(is_file($path)&&!is_link($path))unlink($path);}
 Backup::readiness($p,$u);
};
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{
 Support::checkCsrf();$action=$raw('action',20);InstallationKey::verify($p,$u,$raw('key'));$manager->confirm($raw('password'));
 if($action==='verify'){
  unset($_SESSION['backup_repair_v107']);$f=$_FILES['package']??null;
  if(!is_array($f)||($f['error']??-1)!==UPLOAD_ERR_OK||($f['size']??0)>33554432||!is_uploaded_file($f['tmp_name']??''))throw new RuntimeException('Upload ZIP gagal.');
  if(!hash_equals('093101c81179772680556fab2fcb1cfd9669e74625ea5af57802988c7cd14234',hash_file('sha256',$f['tmp_name'])))throw new RuntimeException('Pilih ZIP hotfix 1.0.7 asal; integriti pakej tidak sepadan.');
  $s=$manager->stageAutomatic($f['tmp_name']);$report=$manager->preflight($s['id']);if($report['decision']!=='BASELINE MATCH')throw new RuntimeException('Preflight BLOCKED. Rujuk laporan run dalam /upgrade; jangan bypass.');
  $state=['id'=>$s['id'],'sha'=>$s['zip_sha256'],'actor'=>$u,'expires'=>time()+600,'nonce'=>bin2hex(random_bytes(32))];$load($state);$_SESSION['backup_repair_v107']=$state;
  $result='<p role="status">PASS — signature, baseline, ledger dan visibility backup disahkan. Sahkan pemasangan di bawah. Database dan fail akan dibackup sebelum penggantian.</p>';
 }elseif($action==='install'){
  $s=$_SESSION['backup_repair_v107']??null;unset($_SESSION['backup_repair_v107']);
  if(!is_array($s)||($s['actor']??null)!==$u||($s['expires']??0)<time()||!hash_equals($s['nonce'],$raw('nonce',64))||$raw('core_approval',50)!=='APPROVE SHARED CORE CHANGES')throw new RuntimeException('Kebenaran install tamat/tidak sah. Verify semula; jangan reset lock.');
  $lock=$dir.'/adapter.lock';if(UpgradePackage::symlink($lock))throw new RuntimeException('Lock path tidak selamat.');$fh=fopen($lock,'c+');if(!$fh||!flock($fh,LOCK_EX|LOCK_NB))throw new RuntimeException('Adapter sedang digunakan.');
  try{if(file_exists($used))throw new RuntimeException('Adapter sudah digunakan.');$load($s);$report=$manager->preflight($s['id']);if($report['decision']!=='BASELINE MATCH')throw new RuntimeException('Preflight BLOCKED.');$done=$manager->installAutomatic($s['id'],$raw('password'),true);Support::secureWrite($used,json_encode(['run'=>$s['id'],'at'=>gmdate('c'),'status'=>$done['status']],JSON_THROW_ON_ERROR));}finally{flock($fh,LOCK_UN);fclose($fh);}
  Support::layout('Backup hotfix selesai','<p>INSTALLED — '.$done['status'].'. App/Core 1.0.7; Training 1.0.5. Padam epejabat-backup-repair.php melalui cPanel. Semak login dan baseline export dalam <a href="/upgrade">/upgrade</a>. Production acceptance masih memerlukan smoke test.</p>');
 }else throw new RuntimeException('Tindakan tidak sah.');
}catch(Throwable $e){$error=($e instanceof RuntimeException&&!$e instanceof PDOException)||$e instanceof InvalidArgumentException?$e->getMessage():'Permintaan dihentikan. Rujukan '.Support::log($e);}
$key='<label>Installation Key 12 digit<input type="password" name="key" inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" autocomplete="off" required></label>';$pass='<label>Sahkan kata laluan Super Admin<input type="password" name="password" maxlength="72" autocomplete="current-password" required></label>';
$body='<p>Adapter sekali sahaja untuk blocker Backup v1.0.5. Menggunakan publisher trust dan upgrade engine sedia ada. Tiada migration; hentikan cron/aktiviti write sepanjang pemasangan.</p>'.($error?'<p class="error" role="alert">'.Support::escape($error).'</p>':'').$result;
$s=$_SESSION['backup_repair_v107']??null;
if(is_array($s)&&$s['actor']===$u&&$s['expires']>=time())$body.=Support::form('install','<input type="hidden" name="nonce" value="'.Support::escape($s['nonce']).'">'.$key.$pass.'<label><input type="checkbox" name="core_approval" value="APPROVE SHARED CORE CHANGES" required> Saya meluluskan tiga perubahan Shared Core dalam pakej 1.0.7.</label>','Confirm Install');
else $body.='<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.Support::csrf().'"><input type="hidden" name="action" value="verify">'.$key.$pass.'<label>Signed hotfix ZIP 1.0.7<input type="file" name="package" accept=".zip" required></label><button>Upload &amp; Verify Automatik</button></form>';
Support::layout('Backup Preflight Hotfix 1.0.7',$body);
