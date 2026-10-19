<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use EPejabat\Core\{Database,Auth,Authorization,InstallationKey,Support};
$p=Database::connect($config['db']);$u=Auth::user($p);if(!$u||!Authorization::super($p,(int)$u['id'])){http_response_code(403);exit('Login Super Admin diperlukan.');}
header('Cache-Control: no-store');header('X-Robots-Tag: noindex');require __DIR__.'/Transition.php';$message='';$report=null;
try{if($_SERVER['REQUEST_METHOD']==='POST'){Support::checkCsrf();InstallationKey::verify($p,(int)$u['id'],(string)($_POST['installation_key']??''));$action=(string)($_POST['action']??'check');if($action==='check')$report=PolicyTransition::checks($p,(int)$u['id']);else{PolicyTransition::grant($p,(int)$u['id'],$action);$message='Hosting authorization sah selama 10 minit. Tiada fail production dipasang melalui halaman ini.';}}}catch(Throwable $e){$message='BLOCKED. Rujukan '.Support::log($e);}
$csrf=Support::csrf();$html='<p>'.Support::escape($message).'</p><p>Peralihan polisi hanya dilaksanakan oleh pentadbir hosting. Pakej ZIP biasa tidak boleh mengaktifkannya.</p><form method="post"><input type="hidden" name="csrf" value="'.Support::escape($csrf).'"><label>Installation Key 12 digit <input name="installation_key" type="password" inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" autocomplete="off" required></label><button name="action" value="check">Deployment Check</button><button name="action" value="activate">Authorise Hosting Activation</button><button name="action" value="rollback">Authorise Hosting Rollback</button></form>';
if($report)$html.='<pre>'.Support::escape(json_encode($report,JSON_PRETTY_PRINT)).'</pre>';Support::layout('Upgrade Policy Transition',$html);
