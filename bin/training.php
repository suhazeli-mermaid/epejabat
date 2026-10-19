<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function($c){foreach(['EPejabat\\Core\\'=>'Core/','EPejabat\\Modules\\Training\\'=>'Modules/Training/'] as $prefix=>$dir)if(str_starts_with($c,$prefix)){$name=substr($c,strlen($prefix));if(preg_match('/\A[A-Za-z]+\z/',$name)&&is_file($f=APP_ROOT.'/app/'.$dir.$name.'.php'))require $f;}});
use EPejabat\Core\{Database,Support,Notifications};
use EPejabat\Modules\Training\{Migrations,Events};
try{if(is_file(APP_ROOT.'/storage/upgrades/maintenance.lock')||is_file(APP_ROOT.'/storage/upgrades/blocked.lock'))throw new RuntimeException('Upgrade recovery/maintenance aktif');$c=require APP_ROOT.'/config/app.php';$p=Database::connect($c['db']);$mode=$argv[1]??'plan';if($mode==='queue'){if(!Migrations::ready($p))throw new RuntimeException('T1 not ready');$n=Events::dispatch($p);$result=Notifications::process($p);echo json_encode(['events_queued'=>$n,...$result])."\n";exit($result['blocked']?2:0);}if($mode==='apply-backup-confirmed')throw new RuntimeException('Use bin/platform.php migrate with verified backup');elseif($mode==='activate')Migrations::activate($p,(int)($argv[2]??0),true);elseif($mode==='deactivate')Migrations::activate($p,(int)($argv[2]??0),false);elseif($mode!=='plan')throw new RuntimeException('Invalid action');echo json_encode(Migrations::plan($p),JSON_PRETTY_PRINT)."\n";}catch(Throwable $e){fwrite(STDERR,'Training unavailable; reference '.Support::log($e)."\n");exit(1);}
