from pathlib import Path
import shutil,json,zipfile
B=Path(__file__).resolve().parent;W=B.parents[1];P=B/'project';OLD=W/'invitation-addendum/tests'
if P.exists():shutil.rmtree(P)
for part in ['app','database']:shutil.copytree(OLD/'project'/part,P/part)
for f in ['Backup.php','InstallationKey.php','UpgradePackage.php','LiveUpgradeManager.php','ProductionBaseline.php','UpgradeWeb.php']:shutil.copy2(W/'grade-catalog/baseline/app/Core'/f,P/'app/Core'/f)
for d in ['config','storage/backups','storage/upgrades','storage/logs']:(P/d).mkdir(parents=True,exist_ok=True)
for f in (P/'database/modules/eforms').glob('22*'):f.unlink()
shutil.copy2(W/'phase2c-iphone-fix/payload/database/eforms-manifest.json',P/'database/eforms-manifest.json')
(P/'config/upgrade-trust.php').write_text("<?php return ['publishers'=>[], 'modules'=>['core'=>'Core','training'=>'Training'],'shared_core_allowlist'=>[]];")
(P/'config/app.php').write_text("<?php return ['app_url'=>'http://127.0.0.1:33439','environment'=>'local','db'=>['host'=>'127.0.0.1','port'=>33438,'name'=>'policy_test','user'=>'root','password'=>'']];")
for n in ['bootstrap.php','init.php','privileges.php']:
 s=(W/'phase3a-recovery/tests'/n).read_text().replace('33428','33438').replace('recovery_test','policy_test');(B/n).write_text(s)
shutil.copytree(W/'policy-transition/bundle/upgrade-policy-transition',P/'upgrade-policy-transition')
s=(P/'app/Core/Support.php').read_text();(P/'app/Core/Support.php').write_text(s.replace('get_class($e)',"get_class($e).' '.$e->getMessage()"))
m=json.loads((W/'phase3a-activation/handoff/phase3a-signing-input/manifest.json').read_text());m['format']='epejabat-owner-upgrade-v1';m.pop('publisher');
with zipfile.ZipFile(B/'phase3a-test.zip','w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('manifest.json',json.dumps(m,indent=2))
 for r in m['files']:z.write(W/'phase3a/payload'/r['file'][5:],'payload/'+r['file'])
