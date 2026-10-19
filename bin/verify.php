<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $c):void{$f=APP_ROOT.'/app/Core/'.substr($c,strrpos($c,'\\')+1).'.php';if(is_file($f))require $f;});
use EPejabat\Core\{Database,Migrations,Authorization,Support};
try {
    if(!is_file(APP_ROOT.'/config/app.php'))throw new RuntimeException('Configuration missing');
    $c=require APP_ROOT.'/config/app.php';$p=Database::connect($c['db']);Migrations::verify($p);
    $status=Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
    $instance=Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='installation_instance'")->fetchColumn();
    if($status!=='complete' || !is_string($instance) || !hash_equals($c['instance'],$instance))throw new RuntimeException('Installation not complete');
    $admins=Database::query($p,"SELECT u.id FROM users u JOIN staff_profiles p ON p.staff_identity_id=u.staff_identity_id JOIN user_role_assignments a ON a.user_id=u.id JOIN roles r ON r.id=a.role_id WHERE r.code='super_admin' AND a.organisation_id IS NULL AND u.is_active=1")->fetchAll();
    if(count($admins)<1 || !Authorization::allowed($p,(int)$admins[0]['id'],'core.dashboard.view'))throw new RuntimeException('Admin invalid');
    if(class_exists('EPejabat\\Core\\Upgrade') && !\EPejabat\Core\Upgrade::ready($p)) throw new RuntimeException('Phase 1 upgrade incomplete');
    if(in_array('--authorized-recovery-lock',$argv,true)) {
        $file=APP_ROOT.'/storage/installed.lock';
        if(is_file($file))throw new RuntimeException('Lock already exists; no overwrite');
        $h=fopen($file,'x');if(!$h)throw new RuntimeException('Cannot create lock');chmod($file,0600);fwrite($h,json_encode(['instance'=>$instance,'completed_at'=>gmdate('c'),'recovered'=>true],JSON_THROW_ON_ERROR));fclose($h);
        echo "Completed installation verified; missing lock recreated. No database changes.\n";
    } else {echo 'Completed installation verified. Lock: '.(is_file(APP_ROOT.'/storage/installed.lock')?'present':'missing (installer still blocked by DB status)')."\n";}
} catch(Throwable $e) {fwrite(STDERR,'Verification failed. Ref: '.Support::log($e)."\n");exit(1);}
