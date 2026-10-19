from pathlib import Path
import os,shutil,subprocess,tempfile,socket,time,json
B=Path(__file__).resolve().parent;W=B.parents[1];OLD=W/"invitation-addendum/tests";R=OLD.parent/"runtime/root/usr"
data=Path(tempfile.mkdtemp(prefix='ep-recovery-'))
env=dict(os.environ,LD_LIBRARY_PATH=str(R/'lib/x86_64-linux-gnu'))
p=subprocess.Popen([str(R/'sbin/mariadbd'),'--no-defaults','--user=root','--datadir='+str(data),'--basedir='+str(R),'--lc-messages-dir='+str(R/'share/mysql'),'--skip-grant-tables','--bind-address=127.0.0.1','--port=33438','--socket=','--pid-file='+str(data/'server.pid'),'--log-error='+str(data/'server.log')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
try:
 for _ in range(100):
  try:
   c=socket.create_connection(('127.0.0.1',33438),.2);c.close();break
  except OSError:time.sleep(.1)
 subprocess.run([str(B/'php.sh'),str(B/'privileges.php')],check=True)
 p.terminate();p.wait(timeout=10)
 p=subprocess.Popen([str(R/'sbin/mariadbd'),'--no-defaults','--user=root','--datadir='+str(data),'--basedir='+str(R),'--lc-messages-dir='+str(R/'share/mysql'),'--bind-address=127.0.0.1','--port=33438','--socket=','--pid-file='+str(data/'server.pid'),'--log-error='+str(data/'server.log')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 for _ in range(100):
  try:
   c=socket.create_connection(('127.0.0.1',33438),.2);c.close();break
  except OSError:time.sleep(.1)
 for script in ['init.php']:
  rc=subprocess.run([str(B/'php.sh'),str(B/script)])
  if rc.returncode:raise SystemExit(rc.returncode)
 for stage in ['prepare','activate','rollback','reactivate','owner']:
  rc=subprocess.run([str(B/'php.sh'),str(B/'cases.php'),stage],stdout=subprocess.PIPE,text=True);print(stage,rc.stdout[-1800:]);
  if rc.returncode:raise SystemExit(rc.returncode)
finally:
 p.terminate();p.wait(timeout=10);shutil.rmtree(data)
