from pathlib import Path
import os,tempfile,subprocess,shutil,time,urllib.request,urllib.error,json
b=Path(__file__).resolve().parent;r=b.parents[1]/'invitation-addendum/runtime/root';tmp=Path(tempfile.mkdtemp(prefix='ep-policy-http-'));tmp.chmod(0o755);doc=tmp/'public';doc.mkdir();shutil.copytree(b.parent/'bundle/upgrade-policy-transition',doc/'upgrade-policy-transition')
for d in ['app','config','database','storage','bin','backup','log','docs']:
 (doc/d).mkdir();(doc/d/'.htaccess').write_text('Require all denied\n');(doc/d/'access-probe.html').write_text('EPEJABAT_PRIVATE_ACCESS_PROBE')
for p in doc.rglob('*'):p.chmod(0o755 if p.is_dir() else 0o644)
mods=r/'usr/lib/apache2/modules';conf=tmp/'apache.conf';conf.write_text(f'''ServerRoot "{tmp}"
PidFile "{tmp}/apache.pid"
Listen 127.0.0.1:33439
ServerName localhost
LoadModule mpm_event_module "{mods}/mod_mpm_event.so"
LoadModule authz_core_module "{mods}/mod_authz_core.so"
LoadModule authz_host_module "{mods}/mod_authz_host.so"
LoadModule dir_module "{mods}/mod_dir.so"
User www-data
Group www-data
ErrorLog "{tmp}/error.log"
DocumentRoot "{doc}"
<Directory "{doc}">
AllowOverride All
Require all granted
</Directory>
''')
p=subprocess.Popen([str(r/'usr/sbin/apache2'),'-f',str(conf),'-DFOREGROUND'],env=dict(os.environ,LD_LIBRARY_PATH=str(r/'usr/lib/x86_64-linux-gnu')),stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
try:
 time.sleep(.5);rows=[]
 for path in ['activate.php','Transition.php','transition-manifest.json','payload/app/Core/UpgradePolicy.php']:
  for method in ['HEAD','GET']:
   req=urllib.request.Request('http://127.0.0.1:33439/upgrade-policy-transition/'+path,method=method)
   try:res=urllib.request.urlopen(req,timeout=3);code=res.status
   except urllib.error.HTTPError as e:code=e.code
   rows.append({'test':f'Apache deny {method} {path}','status':'PASS' if code==403 else 'FAIL','http':code})
 (b/'results-http.json').write_text(json.dumps(rows,indent=2))
 if any(x['status']!='PASS' for x in rows):raise RuntimeError(rows)
 subprocess.run(['python3',str(b/'run.py')],check=True)
finally:p.terminate();p.wait(timeout=5);shutil.rmtree(tmp)
