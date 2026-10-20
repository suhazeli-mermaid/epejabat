<?php
declare(strict_types=1);
use EPejabat\Core\{Support,Database,Auth,Installer,Upgrade,Web};
require __DIR__.'/bootstrap.php';
$path=rtrim((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'/')?:'/';
$basePath = $isLocal ? Support::basePath() : '';
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
}
if($path==='/install')(new Installer($config,$setup,$https,$isLocal))->handle();
if(!$config)Support::layout('Selamat datang','<p>Foundation belum dipasang.</p><a class="button" href="/install">Buka Installation Wizard</a>');
$pdo=Database::connect($config['db']);
$GLOBALS['pdo']=$pdo;
$status=Database::query($pdo,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
if($status!=='complete'){http_response_code(503);Support::layout('Pemasangan belum selesai','<a href="/install">Sambung pemasangan</a>');}
$user=Auth::user($pdo);$GLOBALS['ui_user']=$user;$GLOBALS['ui_versions']=\EPejabat\Core\Versions::read($pdo);
if(is_file(APP_ROOT.'/storage/upgrades/maintenance.lock')&&!in_array($path,['/login','/logout','/upgrade'],true)){http_response_code(503);Support::layout('Penyelenggaraan','<p>Upgrade sedang berjalan. Sila cuba kemudian.</p>');}
if($path==='/logout'){
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
 Support::checkCsrf();Auth::logout($pdo);Support::redirect('/login');
}
if($path==='/login'){
 if($user)Support::redirect('/dashboard');$error='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  Support::checkCsrf();try{$name=Support::post('username',190);$password=$_POST['password']??'';if(!is_string($password)||strlen($password)>72||str_contains($password,"\0"))throw new InvalidArgumentException('Input tidak sah.');$trainingPending=$_SESSION['training_pending']??null;$attendancePending=$_SESSION['attendance_pending']??null;if(Auth::login($pdo,$name,$password)){if(is_string($attendancePending)){$_SESSION['attendance_pending']=$attendancePending;Support::redirect('/training/scan');}if(is_array($trainingPending)){$_SESSION['training_pending']=$trainingPending;Support::redirect(isset($trainingPending['token'])?'/training/invite':'/training/register');}Support::redirect('/dashboard');}$error='E-mel, username atau kata laluan tidak sah.';}
  catch(PDOException $e){$error='Permintaan tidak dapat diproses. Rujukan: '.Support::log($e);}catch(RuntimeException|InvalidArgumentException $e){$error=$e->getMessage();}
 }
 Support::layout('Log masuk',($error?'<p class="error" role="alert">'.Support::escape($error).'</p>':'').Support::form('login',Support::fields(['username'=>['E-mel / username','text',''],'password'=>['Kata laluan','password','']]),'Log masuk').'<p><a href="/forgot-password">Lupa kata laluan</a> · <a href="/claim">Aktifkan akaun</a> · <a href="/register">Mohon pendaftaran</a></p>');
}
if($path==='/upgrade')\EPejabat\Core\UpgradeWeb::handle($pdo,$user,APP_ROOT);
try {$ready=Upgrade::ready($pdo);}catch(Throwable $e){$ready=false;}
if(!$ready){http_response_code(503);Support::layout('Upgrade diperlukan','<p>Shared Core sedang menunggu upgrade yang disahkan oleh Super Admin. Data Phase 0 dikekalkan.</p>'.($user?'<a href="/upgrade">Semak upgrade</a>':'<a href="/login">Log masuk Super Admin</a>'));}
if(in_array($path,['/training/full-setup','/training/portal','/training/verify','/training/content-image','/training/history','/training/scan','/training/evaluation','/training/export','/training/output-preview','/training/workstation'],true))(new \EPejabat\Modules\Training\ExtendedWeb($pdo,$user))->handle($path);
if($path==='/training'||str_starts_with($path,'/training/'))(new \EPejabat\Modules\Training\Web($pdo,$user))->handle($path);
if(in_array($path,['/registration-admin','/registration-contact'],true))\EPejabat\Core\RegistrationWeb::handle($pdo,$user,$path);
(new Web($pdo,$user))->handle($path);
