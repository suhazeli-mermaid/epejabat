<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('APP_ROOT',dirname(__DIR__));spl_autoload_register(static function($c){$prefix='EPejabat\\Core\\';$module='EPejabat\\Modules\\Training\\';$base=str_starts_with($c,$module)?'app/Modules/Training/':'app/Core/';$p=str_starts_with($c,$module)?$module:$prefix;if(str_starts_with($c,$p)&&preg_match('/\A[A-Za-z]+\z/',$n=substr($c,strlen($p)))&&is_file($f=APP_ROOT.'/'.$base.$n.'.php'))require $f;});
use EPejabat\Core\{Database,Upgrade,Versions,FreshPlatform,AccessProtection,Backup,UpgradePackage,Rollback};
try{
 $cmd=$argv[1]??'help';$config=is_file(APP_ROOT.'/config/app.php')?require APP_ROOT.'/config/app.php':null;$needsDb=in_array($cmd,['database','version','migration-plan','migration-status','smoke','migrate','key-recovery'],true);$p=$config&&$needsDb?Database::connect($config['db']):null;
 $result=match($cmd){
 'environment'=>['php'=>PHP_VERSION,'minimum'=>'8.2','compatible'=>PHP_VERSION_ID>=80200,'extensions'=>array_combine(['pdo_mysql','mbstring','ctype','openssl','fileinfo','zip','simplexml','session'],array_map('extension_loaded',['pdo_mysql','mbstring','ctype','openssl','fileinfo','zip','simplexml','session']))],
 'database'=>['status'=>$p?'PASS':'BLOCKED','server'=>$p?$p->query('SELECT VERSION()')->fetchColumn():null],
 'version'=>$p?Versions::read($p):['status'=>'NOT INSTALLED'],
 'key-recovery'=>keyRecovery($p),
 'migration-plan'=>$p?['core'=>Upgrade::plan($p),'training'=>\EPejabat\Modules\Training\Migrations::plan($p),'extended'=>\EPejabat\Modules\Training\ExtendedMigrations::plan($p)]:['status'=>'BLOCKED'],
 'migration-status'=>$p?$p->query('SELECT version,checksum,status,applied_at FROM database_migrations ORDER BY version')->fetchAll():['status'=>'BLOCKED'],
 'smoke'=>$p?FreshPlatform::smoke($p):['status'=>'BLOCKED'],
 'http-deny'=>AccessProtection::check($config['app_url']??'https://pkdkb.epejabat.net'),
 'integrity'=>integrity(),
 'backup-verify'=>Backup::verify($argv[2]??'',$argv[3]??''),
 'rollback'=>Rollback::files($argv[2]??''),
 'diagnostics'=>['php'=>PHP_VERSION,'installed_lock'=>is_file(APP_ROOT.'/storage/installed.lock'),'recovery_lock'=>is_file(APP_ROOT.'/storage/upgrades/blocked.lock'),'maintenance'=>is_file(APP_ROOT.'/storage/upgrades/maintenance.lock'),'error_references'=>is_file(APP_ROOT.'/storage/logs/error.log')?array_slice(file(APP_ROOT.'/storage/logs/error.log'),-20):[]],
 'migrate'=>migrate($p,$argv),
 default=>['commands'=>['key-recovery','environment','database','version','migration-plan','migration-status','migrate <super-id> <backup.sql:sha256> <ISO-time>','integrity','smoke','http-deny','backup-verify <backup.sql:sha256> <ISO-time>','rollback <run-id>','diagnostics']]
 };echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(str_contains(json_encode($result),'"FAIL"'))exit(1);
}catch(Throwable $e){fwrite(STDERR,"BLOCKED: ".($e instanceof PDOException?'Database error':$e->getMessage())."\n");exit(1);}
function integrity():array {$m=json_decode(file_get_contents(APP_ROOT.'/database/release.json'),true,512,JSON_THROW_ON_ERROR);$trust=\EPejabat\Core\LiveUpgradeManager::trustPolicy();$states=glob(APP_ROOT.'/storage/upgrades/*/state.json');sort($states);$updates=[];foreach($states as $state){if(UpgradePackage::symlink($state))throw new RuntimeException('Upgrade state symlink');$r=json_decode(file_get_contents($state),true,512,JSON_THROW_ON_ERROR);if(($r['status']??'')==='VERIFIED_LOCAL_INSTALL')$updates[]=$r;}usort($updates,fn($a,$b)=>strcmp($a['completed_at'],$b['completed_at']));foreach($updates as $r){$verified=UpgradePackage::verify(APP_ROOT.'/storage/upgrades/'.$r['id'].'/package.zip',$r['zip_sha256'],$trust);if($verified['manifest']!==$r['manifest'])throw new RuntimeException('Installed manifest drift');foreach($r['manifest']['files'] as $file)$m['files'][substr($file['file'],5)]=$file['after_sha256'];foreach($r['manifest']['retained_files']??[] as $file){$rel=substr($file['file']??'',5);if(!in_array($rel,['.htaccess','app/Core/AccessProtection.php'],true)||($file['file']??'')!=='root/'.$rel||!preg_match('/\A[0-9a-f]{64}\z/',$file['sha256']??''))throw new RuntimeException('Retained baseline declaration invalid');$m['files'][$rel]=$file['sha256'];}}$out=[];foreach($m['files'] as $f=>$h){$path=APP_ROOT.'/'.$f;$out[]=['file'=>$f,'status'=>!UpgradePackage::symlink($path)&&is_file($path)&&hash_equals($h,hash_file('sha256',$path))?'PASS':'FAIL'];}return $out;}
function migrate(?PDO $p,array $args):array {if(!$p||!is_file(APP_ROOT.'/storage/installed.lock'))throw new RuntimeException('Installed database diperlukan.');$actor=filter_var($args[2]??'',FILTER_VALIDATE_INT);if(!$actor||!\EPejabat\Core\Authorization::super($p,$actor))throw new RuntimeException('Super Admin diperlukan.');Backup::verify($args[3]??'',$args[4]??'');AccessProtection::assert((require APP_ROOT.'/config/app.php')['app_url']);$h=fopen(APP_ROOT.'/storage/upgrades/upgrade-process.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new RuntimeException('Upgrade lock aktif.');try{if(is_file(APP_ROOT.'/storage/upgrades/blocked.lock')||is_file(APP_ROOT.'/storage/upgrades/maintenance.lock'))throw new RuntimeException('Recovery lock aktif.');\EPejabat\Core\Support::secureWrite(APP_ROOT.'/storage/upgrades/maintenance.lock','cli-migration');try{Upgrade::apply($p,$actor);\EPejabat\Modules\Training\Migrations::apply($p,$actor);\EPejabat\Modules\Training\ExtendedMigrations::apply($p,$actor);$report=FreshPlatform::smoke($p);foreach($report as $r)if($r['status']!=='PASS')throw new RuntimeException('Verification failed');unlink(APP_ROOT.'/storage/upgrades/maintenance.lock');return $report;}catch(Throwable $e){\EPejabat\Core\Support::secureWrite(APP_ROOT.'/storage/upgrades/blocked.lock','cli-migration');throw $e;}}finally{flock($h,LOCK_UN);fclose($h);}}

function keyRecovery(?PDO $p):array {
 if(!$p||!is_file(APP_ROOT.'/storage/installed.lock'))throw new RuntimeException('Installed application diperlukan.');
 if(!function_exists('stream_isatty')||!stream_isatty(STDIN)||!function_exists('shell_exec'))throw new RuntimeException('Recovery memerlukan interactive Terminal dengan hidden password input.');
 fwrite(STDERR,"Super Admin username: ");$name=trim((string)fgets(STDIN));
 $u=Database::query($p,'SELECT id FROM users WHERE username=? AND is_active=1',[$name])->fetchColumn();
 if(!$u||!\EPejabat\Core\Authorization::super($p,(int)$u))throw new RuntimeException('Pengesahan pentadbir tidak sah.');
 $tty=trim((string)shell_exec('stty -g 2>/dev/null'));if($tty===''||!preg_match('/\A[0-9a-fA-F:]+\z/',$tty))throw new RuntimeException('Hidden password input tidak tersedia.');
 try {shell_exec('stty -echo 2>/dev/null');fwrite(STDERR,"Super Admin password (hidden): ");$password=rtrim((string)fgets(STDIN),"\r\n");}
 finally {shell_exec('stty '.escapeshellarg($tty).' 2>/dev/null');fwrite(STDERR,"\n");}
 $token=\EPejabat\Core\InstallationKey::recovery($p,(int)$u,$password);unset($password);
 return ['status'=>'ONE_TIME_RECOVERY_AUTHORIZATION','token'=>$token,'expires_in_seconds'=>900,'next'=>'Gunakan token pada /upgrade bersama kata laluan Super Admin dan key baharu. Jangan kongsi atau simpan token dalam log.'];
}
