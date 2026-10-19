<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_ROOT',dirname(__DIR__));spl_autoload_register(static function($c){if(str_starts_with($c,'EPejabat\\Core\\')){$n=substr($c,14);if(preg_match('/\A[A-Za-z]+\z/',$n)&&is_file($f=APP_ROOT.'/app/Core/'.$n.'.php'))require $f;}});
use EPejabat\Core\{Database,Upgrade,Notifications,Support};
try {if(is_file(APP_ROOT.'/storage/upgrades/maintenance.lock')||is_file(APP_ROOT.'/storage/upgrades/blocked.lock'))throw new RuntimeException('Upgrade recovery/maintenance aktif');$c=require APP_ROOT.'/config/app.php';$p=Database::connect($c['db']);if(!Upgrade::ready($p))throw new RuntimeException('Upgrade required');$r=Notifications::process($p);echo json_encode($r,JSON_THROW_ON_ERROR)."\n";exit($r['blocked']?2:0);}catch(Throwable $e){fwrite(STDERR,'Queue failed; ref '.Support::log($e)."\n");exit(1);}
