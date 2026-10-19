<?php
ob_start();session_start();require __DIR__.'/bootstrap.php';require APP_ROOT.'/upgrade-policy-transition/Transition.php';
use EPejabat\Core\{Database as D,Versions,InstallationKey as K,UpgradePackage as U,LiveUpgradeManager as L,Backup,Support};
$stage=$argv[1];$tests=[];
function check($n,$v){global $tests;$tests[]=['test'=>$n,'status'=>$v?'PASS':'FAIL'];if(!$v)throw new RuntimeException($n);}
function deny($n,$fn){try{$fn();}catch(Throwable $e){check($n,true);return;}check($n,false);}
function keyAuth(){global $p;K::verify($p,1,'000000123456');}
try{
 if($stage==='prepare'){
 foreach(array_merge(glob(APP_ROOT.'/database/migrations/*.sql'),glob(APP_ROOT.'/database/upgrades/*.{sql,php}',GLOB_BRACE)) as $f){$v=str_contains($f,'/migrations/')?basename($f,'.sql'):basename($f);D::query($p,"INSERT INTO database_migrations(version,checksum,status,applied_at) VALUES(?,?,'applied',UTC_TIMESTAMP())",[$v,hash_file('sha256',$f)]);}
 file_put_contents(APP_ROOT.'/storage/installed.lock','synthetic');require APP_ROOT.'/app/Modules/EForms/Migrations.php';\EPejabat\Modules\EForms\Migrations::apply($p,1);
 foreach(['app_version'=>'1.2.0','core_version'=>'1.2.0','module_version.training'=>'1.0.5','module_version.eforms'=>'1.3.0'] as $k=>$v)Versions::set($p,$k,$v);
 K::setup($p,1,'000000123456','000000123456','SyntheticFixtureOnly12!');
 deny('Old publisher policy rejects owner ZIP',fn()=>U::verify(__DIR__.'/phase3a-test.zip',hash_file('sha256',__DIR__.'/phase3a-test.zip'),L::trustPolicy()));
 deny('No key authorization denies grant',fn()=>PolicyTransition::grant($p,1,'activate'));
 keyAuth();check('12 digit leading zero key works',K::authorized($p,1));
 $_POST=[];deny('CSRF omission denied',fn()=>Support::checkCsrf());$_POST['csrf']=Support::csrf();Support::checkCsrf();check('Valid CSRF accepted',true);
 deny('Non-admin denies grant',fn()=>PolicyTransition::grant($p,999,'activate'));
 $r=PolicyTransition::checks($p,1);file_put_contents(__DIR__.'/preflight.json',json_encode($r,JSON_PRETTY_PRINT));check('Exact baseline and actual backup/HTTP preflight', $r['status']==='PASS');
 $target=APP_ROOT.'/app/Core/UpgradeWeb.php';$originalFile=file_get_contents($target);file_put_contents($target,$originalFile.'// drift');deny('Baseline drift prevents hosting grant',fn()=>PolicyTransition::grant($p,1,'activate'));file_put_contents($target,$originalFile);
 $p->exec('CREATE VIEW blocked_backup AS SELECT id FROM users');deny('Backup unsupported schema blocks activation grant',fn()=>PolicyTransition::grant($p,1,'activate'));$p->exec('DROP VIEW blocked_backup');
 PolicyTransition::grant($p,1,'activate');$f=PolicyTransition::dir().'/approval.json';$original=file_get_contents($f);$e=json_decode($original,true);$e['hmac']=str_repeat('0',64);file_put_contents($f,json_encode($e));deny('Tampered hosting grant rejected',fn()=>PolicyTransition::consume($p,'activate'));file_put_contents($f,$original);
 $g=PolicyTransition::open($original);$g['expires']=time()-1;file_put_contents($f,PolicyTransition::seal($g));deny('Expired hosting grant rejected',fn()=>PolicyTransition::consume($p,'activate'));file_put_contents($f,$original);
 deny('Action substitution rejected',fn()=>PolicyTransition::consume($p,'rollback'));
 }
 if($stage==='activate'){
 $beforeRows=[];foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=array_map('serialize',$p->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$beforeRows[$table]=$rows;}
 $r=PolicyTransition::run($p,'activate');check('Hosting activation copies fixed five files',$r['status']==='ACTIVATED');
 check('Policy recorded in upgrade history',D::query($p,"SELECT COUNT(*) FROM upgrade_history WHERE module_key='core' AND target_version='1.2.1' AND status='VERIFIED_LOCAL_INSTALL'")->fetchColumn()>0);
 check('Consumed grant cannot replay',!file_exists(PolicyTransition::dir().'/approval.json'));
 $m=PolicyTransition::manifest();foreach($m['files'] as $f)check('After hash '.$f['file'],hash_file('sha256',APP_ROOT.'/'.$f['file'])===$f['after_sha256']);
 $s=PolicyTransition::open(file_get_contents(PolicyTransition::dir().'/state.json'));$b=$s['backup'];check('Automatic backup verified',Backup::verify($b['reference'],$b['at'])['completeness']==='PASS');
 $p->exec('CREATE DATABASE policy_restore CHARACTER SET utf8mb4');$restore=new PDO('mysql:host=127.0.0.1;port=33438;dbname=policy_restore;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$file=APP_ROOT.'/storage/backups/'.explode(':',$b['reference'])[0];$restore->exec(file_get_contents($file));
 $meta=json_decode(json_decode(file_get_contents($file.'.json'),true)['metadata'],true);$ok=true;foreach($meta['tables'] as $table=>$t){$q='`'.str_replace('`','``',$table).'`';$ok=$ok&&(int)$restore->query('SELECT COUNT(*) FROM '.$q)->fetchColumn()===$t['rows']&&hash('sha256',$restore->query('SHOW CREATE TABLE '.$q)->fetch(PDO::FETCH_NUM)[1])===$t['schema_sha256'];}check('Restored backup table counts and schema verified',$ok);
 $all=true;foreach($beforeRows as $table=>$rows){$restored=array_map('serialize',$restore->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($restored);$all=$all&&$rows===$restored;}check('Restore all table values equals pre-transition snapshot',$all);
 check('Restored pre-transition version preserved',$restore->query("SELECT setting_value FROM system_settings WHERE setting_key='app_version'")->fetchColumn()==='1.2.0');
 PolicyTransition::grant($p,1,'rollback');
 }
 if($stage==='rollback'){
 $p->exec("INSERT INTO upgrade_history(run_id,module_key,target_version,status,actor_id) VALUES('synthetic-later','training','1.0.6','VERIFIED_LOCAL_INSTALL',1)");deny('Later successful release blocks policy rollback',fn()=>PolicyTransition::run($p,'rollback'));$p->exec("DELETE FROM upgrade_history WHERE run_id='synthetic-later'");keyAuth();PolicyTransition::grant($p,1,'rollback');
 $r=PolicyTransition::run($p,'rollback');check('Hosting rollback restores prior five-file state',$r['status']==='ROLLED_BACK');foreach(PolicyTransition::manifest()['files'] as $f)check('Restored '.$f['file'],$f['before_sha256']===null?!file_exists(APP_ROOT.'/'.$f['file']):hash_file('sha256',APP_ROOT.'/'.$f['file'])===$f['before_sha256']);check('Policy marker removed',!file_exists(APP_ROOT.'/config/upgrade-policy.json'));
 }
 if($stage==='reactivate'){
 // TEST FIXTURE ONLY: reset completed rollback state to exercise a second disposable activation.
 unlink(PolicyTransition::dir().'/state.json');keyAuth();PolicyTransition::grant($p,1,'activate');check('Second isolated activation',PolicyTransition::run($p,'activate')['status']==='ACTIVATED');
 }
 if($stage==='owner'){
 keyAuth();$trust=L::trustPolicy();check('Hosting marker enables owner policy',$trust['owner_authorized']===true);
 $marker=APP_ROOT.'/config/upgrade-policy.json';$old=file_get_contents($marker);$e=json_decode($old,true);$e['hmac']=str_repeat('f',64);file_put_contents($marker,json_encode($e));deny('Policy marker corruption fails closed',fn()=>L::trustPolicy());file_put_contents($marker,$old);
 foreach(['UpgradePolicy.php','UpgradePackage.php','LiveUpgradeManager.php','UpgradeWeb.php','InstallationKey.php'] as $f)check('Ordinary ZIP cannot replace '.$f,!U::path('root/app/Core/'.$f,$trust,'core'));
 $zip=__DIR__.'/phase3a-test.zip';$v=U::verify($zip,hash_file('sha256',$zip),$trust);check('Owner package verifies actual eight payloads',count($v['manifest']['files'])===8);
 $off=$trust;$off['owner_authorized']=false;deny('ZIP cannot self-authorize policy',fn()=>U::verify($zip,hash_file('sha256',$zip),$off));
 $bad=__DIR__.'/bad.zip';copy($zip,$bad);$z=new ZipArchive();$z->open($bad);$z->addFromString('payload/root/public-program.php','tampered');$z->close();deny('Payload checksum mismatch rejected',fn()=>U::verify($bad,hash_file('sha256',$bad),$trust));
 $manager=new L($p,1,APP_ROOT);$badRun=$manager->stageAutomatic($zip);$p->exec('CREATE VIEW deny_backup_install AS SELECT id FROM users');deny('Backup failure prevents automatic file installation',fn()=>$manager->installAutomatic($badRun['id'],'',false));check('Failed backup leaves Phase3A files absent',!file_exists(APP_ROOT.'/public-program.php'));check('Backup failure reports BLOCKED',$manager->state($badRun['id'])['status']==='BLOCKED');$p->exec('DROP VIEW deny_backup_install');$s=$manager->stageAutomatic($zip);check('Owner automatic stage works',$s['status']==='STAGED');
 $_SESSION['upgrade_key_auth']['expires']=time()-1;deny('Expired authorization denies install',fn()=>$manager->installAutomatic($s['id'],'',false));check('Staged package survives expired auth',$manager->state($s['id'])['status']==='STAGED');keyAuth();
 $r=$manager->preflight($s['id']);file_put_contents(__DIR__.'/module-preflight.json',json_encode($r,JSON_PRETTY_PRINT));check('Full Phase3A preflight passes',$r['decision']==='BASELINE MATCH');
 $s=$manager->installAutomatic($s['id'],'',false);check('Auto install without extra account password',$s['status']==='VERIFIED_LOCAL_INSTALL');
 foreach($s['manifest']['migrations'] as $mi)check('Existing eforms engine applied '.$mi['version'],D::query($p,'SELECT status FROM database_migrations WHERE version=?',[$mi['version']])->fetchColumn()==='applied');
 check('Core version preserved',Versions::read($p)['core_version']==='1.2.1');check('Training version preserved',Versions::read($p)['module_version.training']==='1.0.5');
 deny('Installation replay blocked',fn()=>$manager->installAutomatic($s['id'],'',false));
 PolicyTransition::grant($p,1,'rollback');deny('Policy rollback blocked after dependent migrations',fn()=>PolicyTransition::run($p,'rollback'));
 check('Successful install leaves no recovery lock',!file_exists(APP_ROOT.'/storage/upgrades/blocked.lock'));
 }
}catch(Throwable $e){$tests[]=['test'=>'runner '.$stage,'status'=>'FAIL','reason'=>$e->getMessage()];}
file_put_contents(__DIR__.'/results-'.$stage.'.json',json_encode($tests,JSON_PRETTY_PRINT));ob_end_clean();echo json_encode($tests);exit(in_array('FAIL',array_column($tests,'status'),true)?1:0);
