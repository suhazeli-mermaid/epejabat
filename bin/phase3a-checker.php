<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EPejabat\Core\{Database,Auth,Authorization,Support,InstallationKey};
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
if(!$config){http_response_code(503);exit('Configuration unavailable');}
$p=Database::connect($config['db']);$u=Auth::user($p);
if(!$u)Support::redirect('/login');
if(!Authorization::super($p,(int)$u['id'])){http_response_code(403);exit('Super Admin required');}
$stage=__DIR__.'/phase3a_stage';
// Pin checker logic and plan before including or trusting them. Hash integrity is not publisher authentication.
$expected=['Checker.php'=>'54a867fb306d610989ec8f1ae6a6beafd3f560854affbc8f1dca56474b7e4d5c','preflight.json'=>'ecb77280c7b1ace3b3ff838c525f5308d06978e5fa9eef083ae9d5fe02481207'];
foreach($expected as $name=>$hash){$cur=$stage;$safe=!is_link($stage);foreach(explode('/',$name) as $part){$cur.='/'.$part;if(is_link($cur))$safe=false;}if(!$safe||!is_file($cur)||!hash_equals($hash,hash_file('sha256',$cur))){http_response_code(409);exit('BLOCKED: checker integrity mismatch');}}
require $stage.'/Checker.php';$plan=json_decode(file_get_contents($stage.'/preflight.json'),true,512,JSON_THROW_ON_ERROR);
$mode=($_GET['mode']??'before')==='after'?'after':'before';$message='';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  Support::checkCsrf();$a=Support::post('action',30);
  if($a!=='verify_key')throw new RuntimeException('Pemasangan automatik BLOCKED; tiada tindakan install/migrate/rollback tersedia.');
  InstallationKey::verify($p,(int)$u['id'],Support::post('installation_key',12));$message='Installation Key disahkan untuk sesi ini. Signature masih wajib.';
 }
 $report=\EPejabat\Phase3A\Checker::run($p,(int)$u['id'],__DIR__,$stage,$plan,$mode);
 if(($_GET['download']??'')==='1'){header('Content-Type: application/json');header('Content-Disposition: attachment; filename="PHASE3A_DEPLOYMENT_CHECK.json"');echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;}
 $h='<h2>Phase 3A — Deployment Checker</h2><p>Deploy Check → Backup → Install → Verify</p><p><strong>Automatic installation: BLOCKED — unsigned package</strong></p><p>'.Support::escape($message).'</p><p>Checker tidak memasang fail atau menjalankan migration. Pengesahan key menggunakan kawalan rate-limit/audit sedia ada.</p><p><a href="?mode=before">Semak sebelum pemasangan</a> · <a href="?mode=after">Semak selepas pemasangan</a> · <a href="?mode='.$mode.'&amp;download=1">Muat turun JSON</a> · <a href="/upgrade">Upgrade rasmi</a></p>';
 $h.=Support::form('verify_key','<label>Installation Key 12 digit<input type="password" name="installation_key" inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" autocomplete="off" required></label>','Sahkan key');
 $h.='<p>Tiada butang Install: keselamatan publisher tidak dipintas.</p><details open><summary>Hasil pemeriksaan</summary><div style="overflow:auto"><table><tr><th>Semakan</th><th>Status</th><th>Butiran</th></tr>';
 foreach($report['checks'] as $r)$h.='<tr><td>'.Support::escape($r['check']).'</td><td>'.Support::escape($r['status']).'</td><td>'.Support::escape($r['item']??$r['detail']??'').'</td></tr>';
 $h.='</table></div></details><p>Semakan fail/ledger bukan bukti smoke test fungsi. Ujian pendaftaran dan penghantaran e-mel memerlukan fixture sintetik serta kelulusan.</p>';
}catch(Throwable $e){http_response_code(409);Support::log($e);$h='<h2>BLOCKED</h2><p>Checker tidak dapat diselesaikan atau authorization ditolak. Semak key, konfigurasi dan keserasian. Tiada pemasangan dilakukan.</p>';}
Support::layout('Phase 3A Deployment Checker',$h);
