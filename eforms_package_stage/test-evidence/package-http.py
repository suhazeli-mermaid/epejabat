import subprocess,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,time
from pathlib import Path
B=Path('invitation-addendum/tests').resolve();out=Path('phase2c/reports');checks=[]
server=subprocess.Popen([str(B/'php.sh'),'-S','127.0.0.1:33428',str(B/'eforms_router.php')],stdout=subprocess.DEVNULL,stderr=open(out/'http.log','w'))
def ok(n,v):
 checks.append({'test':n,'status':'PASS' if v else 'FAIL'})
 if not v:raise RuntimeError(n)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(o,path,data=None,headers={}):
 try:
  with o.open(urllib.request.Request('http://127.0.0.1:33428'+path,data=data,headers=headers)) as r:return r.status,r.read(),r.geturl()
 except urllib.error.HTTPError as e:return e.code,e.read(),e.geturl()
def csrf(b):return re.search(rb'name="csrf" value="([^"]+)"',b).group(1).decode()
def login(o,email,pw):
 c,b,_=req(o,'/login');return req(o,'/login',urllib.parse.urlencode({'csrf':csrf(b),'email':email,'password':pw}).encode())
try:
 time.sleep(.3);admin=client();c,b,_=login(admin,'admin@example.invalid','SyntheticFixtureOnly12!');ok('Existing Admin login',c==200)
 c,b,_=req(admin,'/eforms?packages=1');ok('Admin ZIP interface renders',c==200 and b'Upload &amp;' in b or c==200 and b'Upload & Validate' in b);token=csrf(b)
 def upload(path,csrf_value=token):
  boundary='PACKAGE_TEST_BOUNDARY';parts=[]
  for k,v in {'action':'package_upload','csrf':csrf_value,'organisation_id':1}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
  parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="package"; filename="form.zip"\r\nContent-Type: application/zip\r\n\r\n'.encode()+Path(path).read_bytes()+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode());return req(admin,'/eforms',b''.join(parts),{'Content-Type':'multipart/form-data; boundary='+boundary})
 c,b,_=upload('phase2c/examples/Synthetic_FORM_PACKAGE.zip','bad');ok('ZIP CSRF rejected',c>=400)
 c,b,_=upload('phase2c/tests/invalid-hash.zip');ok('Hash mismatch HTTP rejection',c>=400)
 c,b,url=upload('phase2c/examples/Synthetic_FORM_PACKAGE.zip');ok('Multipart ZIP validates and shows confirmation',c==200 and 'stage=' in url);stage=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['stage'][0]
 c,b,url=req(admin,'/eforms',urllib.parse.urlencode({'action':'package_confirm','csrf':csrf(b),'stage':stage,'confirm':'1','reviewers[]':'3'}).encode());ok('Confirmation opens existing studio',c==200 and 'studio=' in url and b'visual-studio' in b)
 staff=client();login(staff,'eforms2@example.invalid','SyntheticPassword12!');c,b,_=req(staff,'/eforms?packages=1');ok('Staff import permission denied',c>=400)
 c,b,_=req(staff,'/eforms?package_original='+stage);ok('Staged PDF actor access denied',c>=400)
 c,b,_=req(staff,'/eforms?library=1');ok('Scoped staff library renders',c==200 and b'Pusat Borang Digital' in b)
 (out/'http-tests.json').write_text(json.dumps({'tests':checks,'pass':len(checks),'fail':0,'actual_browser':'NOT TESTED'},indent=2));print(len(checks),'HTTP assertions PASS')
finally:server.terminate();server.wait(timeout=10)
