<?php
ini_set('session.save_path',sys_get_temp_dir());session_start();require __DIR__.'/../../invitation-addendum/tests/bootstrap.php';require __DIR__.'/../Checker.php';
use EPejabat\Phase3A\Checker;use EPejabat\Core\{Database,InstallationKey,UpgradePackage,Support};
$base=sys_get_temp_dir().'/checker-'.bin2hex(random_bytes(6));mkdir($base);mkdir($base.'/stage');mkdir($base.'/stage/payload');file_put_contents($base.'/old.txt','baseline');file_put_contents($base.'/stage/payload/old.txt','new');file_put_contents($base.'/stage/payload/new.txt','add');
$plan=['baseline'=>['old.txt'=>hash('sha256','baseline')],'new'=>['new.txt'],'payload'=>['old.txt'=>hash('sha256','new'),'new.txt'=>hash('sha256','add')]];$tests=[];
function ok($name,$v){global $tests;$tests[]=['test'=>$name,'status'=>$v?'PASS':'FAIL'];if(!$v)throw new RuntimeException($name);}
function denied($name,$f){$no=false;try{$f();}catch(Throwable $e){$no=true;}ok($name,$no);}
function passfiles($r){return count(array_filter($r,fn($x)=>$x['status']==='BLOCKED'))===0;}
try{
 ok('Matching baseline and payload',passfiles(Checker::files($base,$base.'/stage',$plan,'before')));
 file_put_contents($base.'/old.txt','tampered');ok('Baseline mismatch blocks',!passfiles(Checker::files($base,$base.'/stage',$plan,'before')));file_put_contents($base.'/old.txt','baseline');
 file_put_contents($base.'/new.txt','conflict');ok('New-file conflict blocks',!passfiles(Checker::files($base,$base.'/stage',$plan,'before')));unlink($base.'/new.txt');
 file_put_contents($base.'/stage/payload/new.txt','tampered');ok('Payload tamper blocks',!passfiles(Checker::files($base,$base.'/stage',$plan,'before')));file_put_contents($base.'/stage/payload/new.txt','add');
 symlink($base.'/old.txt',$base.'/new.txt');ok('Symlink blocks',!passfiles(Checker::files($base,$base.'/stage',$plan,'before')));unlink($base.'/new.txt');
 ok('Traversal absolute and empty segment rejected',!Checker::safe($base,'../x')&&!Checker::safe($base,'/x')&&!Checker::safe($base,'a//b'));
 copy($base.'/stage/payload/old.txt',$base.'/old.txt');copy($base.'/stage/payload/new.txt',$base.'/new.txt');ok('After mode verifies installed payload',passfiles(Checker::files($base,$base.'/stage',$plan,'after')));ok('Repeat installation blocked by before mode',!passfiles(Checker::files($base,$base.'/stage',$plan,'before')));
 denied('Anonymous actor rejected',fn()=>Checker::run($p,0,$base,$base.'/stage',$plan,'before'));
 Database::query($p,"INSERT INTO system_settings(setting_key,setting_value) VALUES('upgrade_installation_key',?)",[json_encode(['hash'=>password_hash('012345678901',PASSWORD_DEFAULT),'epoch'=>str_repeat('a',32)])]);
 if(!is_dir(APP_ROOT.'/storage/upgrades'))mkdir(APP_ROOT.'/storage/upgrades',0700,true);
 // Synthetic fixture-only throttle file; never touches hosting.
 foreach(['key-attempts.json','key-security.lock'] as $f)if(is_file(APP_ROOT.'/storage/upgrades/'.$f))unlink(APP_ROOT.'/storage/upgrades/'.$f);
 denied('Incorrect Installation Key rejected',fn()=>InstallationKey::verify($p,1,'999999999999'));InstallationKey::verify($p,1,'012345678901');ok('Leading-zero key accepted by existing engine',InstallationKey::authorized($p,1));
 $_POST=['csrf'=>'bad'];denied('CSRF rejected by existing guard',fn()=>Support::checkCsrf());
 $r=Checker::run($p,1,$base,$base.'/stage',$plan,'before');ok('Unsigned always blocks automatic install',$r['automatic_install']==='BLOCKED_UNSIGNED');
 ok('Missing Backup::create explicitly blocks',count(array_filter($r['checks'],fn($c)=>$c['check']==='Existing backup create interface'&&$c['status']==='BLOCKED'))===1);
 ok('No invented smoke PASS',count(array_filter($r['smoke_tests'],fn($c)=>$c['status']!=='NOT TESTED'))===0);
 ok('No key hash exposed',!str_contains(json_encode($r),'012345678901')&&!str_contains(json_encode($r),'$2y$'));
 $before=(int)$p->query('SELECT COUNT(*) FROM database_migrations')->fetchColumn();Checker::run($p,1,$base,$base.'/stage',$plan,'after');ok('Checker never applies migrations',(int)$p->query('SELECT COUNT(*) FROM database_migrations')->fetchColumn()===$before);
 $z=$base.'/unsigned.zip';$zip=new ZipArchive();$zip->open($z,ZipArchive::CREATE);$zip->addFromString('manifest.json','{}');$zip->close();denied('Existing verifier rejects unsigned ZIP',fn()=>UpgradePackage::verify($z,hash_file('sha256',$z),['publishers'=>[],'modules'=>[],'shared_core_allowlist'=>[]]));
 file_put_contents(__DIR__.'/../reports/EXAMPLE_BLOCKED_REPORT.json',json_encode($r,JSON_PRETTY_PRINT));
}catch(Throwable $e){$tests[]=['test'=>'Suite exception','status'=>'FAIL','reason'=>$e->getMessage()];}
file_put_contents(__DIR__.'/../reports/CHECKER_TEST_RESULTS.json',json_encode(['tests'=>$tests,'previous_45_tests'=>'NOT RERUN — prior RC1 evidence unchanged','browser'=>'NOT TESTED','production'=>'NOT TESTED','php'=>PHP_VERSION],JSON_PRETTY_PRINT));echo json_encode($tests);exit(count(array_filter($tests,fn($t)=>$t['status']==='FAIL'))?1:0);
