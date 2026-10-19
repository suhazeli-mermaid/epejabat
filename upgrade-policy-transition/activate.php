<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('APP_ROOT',dirname(__DIR__));umask(0077);
spl_autoload_register(static function($c){$prefix='EPejabat\\Core\\';if(str_starts_with($c,$prefix)&&preg_match('/\A[A-Za-z]+\z/',$n=substr($c,strlen($prefix))))require APP_ROOT.'/app/Core/'.$n.'.php';});
require __DIR__.'/Transition.php';
try{$config=require APP_ROOT.'/config/app.php';$p=\EPejabat\Core\Database::connect($config['db']);echo json_encode(PolicyTransition::run($p,$argv[1]??''),JSON_PRETTY_PRINT)."\n";}catch(Throwable $e){$ref=\EPejabat\Core\Support::log($e);fwrite(STDERR,"BLOCKED. Rujukan: $ref. Recovery locks must not be deleted manually.\n");exit(1);}
