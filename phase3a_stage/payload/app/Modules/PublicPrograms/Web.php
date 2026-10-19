<?php
declare(strict_types=1);
namespace EPejabat\Modules\PublicPrograms;
use EPejabat\Core\{Core,Support};
final class Web {
 static function e(mixed $x):string{return Support::escape($x);}
 static function input(string $n,string $l,string $type='text',mixed $v='',bool $required=true):string{return '<label>'.self::e($l).'<input name="'.self::e($n).'" type="'.self::e($type).'" value="'.self::e($v).'"'.($required?' required':'').' maxlength="255"></label>';}
 static function form(string $a,string $h,string $label):string{return Support::form($a,$h,$label);}
 public static function handle(\PDO $p,?array $user,string $origin):never {
  $h='';$admin=isset($_GET['admin']);$id=Core::id($_GET['id']??null,true);
  try {
   if($admin&&!$user)Support::redirect('/login');
   if($_SERVER['REQUEST_METHOD']==='POST'){
    Support::checkCsrf();$action=Support::post('action',30);
    if($action==='register'){
     Service::throttle($p,$_SERVER['REMOTE_ADDR']??'unknown');
     $key=Support::post('request',48);$issued=$_SESSION['public_program_issued'][$key]??0;
     if(!$issued||time()-$issued<2||time()-$issued>7200||Support::post('website',100)!==''||($_POST['ack']??'')!=='1')throw new \RuntimeException('Permintaan tidak sah. Muat semula borang.');
     $r=Service::register($p,Support::post('code',32),$_POST['guardian']??[],$_POST['children']??[],$key,$origin);
     unset($_SESSION['public_program_issued'][$key]);
     $h='<h2>Permohonan diterima</h2><p>Pendaftaran bukan jaminan tempat, kelulusan klinikal atau subsidi.</p>';
     if(isset($r['url']))$h.='<p>Dalam kuota permohonan: '.$r['received'].' · Senarai menunggu: '.$r['waiting'].'</p><a href="'.self::e($r['url']).'">Simpan pautan status sulit</a>';
     self::page($h);
    }
    if($action==='status'){
     Service::throttle($p,$_SERVER['REMOTE_ADDR']??'unknown');$rows=Service::status($p,Support::post('access',64));
     $h='<h2>Status permohonan</h2><p>Pautan ini hanya memberi akses status; bukan pengesahan identiti penjaga.</p>';
     foreach($rows as $r)$h.='<article><strong>ID Peserta: '.self::e($r['participant_code']).'</strong><p>'.self::e($r['registration_status']).'</p><p>Klinikal: '.self::e($r['clinical_status']).' · Bayaran: '.self::e($r['payment_status']).' · Tempat: '.self::e($r['place_status']).'</p></article>';
     self::page($h);
    }
    if(!$admin||!$user)throw new \RuntimeException('Akses ditolak.');
    if($action==='save'){$new=Service::save($p,(int)$user['id'],$_POST,$id);Support::redirect('/public-program.php?admin=1&id='.$new);}
    elseif($action==='promote'){Service::promote($p,(int)$user['id'],(int)$id);$h='<p role="status">Peserta terawal dinaikkan ke kuota permohonan. Tempat masih belum disahkan.</p>';}
    elseif($action==='export'){
     $rows=Service::registry($p,(int)$user['id'],(int)$id,true);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="program-participants.csv"');$f=fopen('php://output','w');if($rows){fputcsv($f,array_keys($rows[0]));foreach($rows as $r)fputcsv($f,array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$r));}fclose($f);exit;
    }else throw new \RuntimeException('Tindakan tidak sah.');
   }
   if($admin){$h.=self::admin($p,(int)$user['id'],$id,$origin);}
   elseif(isset($_GET['p'])){$r=Service::q($p,'SELECT * FROM public_programs WHERE public_code=?',[is_string($_GET['p'])?$_GET['p']:''])->fetch();if(!$r)throw new \RuntimeException('Program tidak tersedia.');$h.=self::registration($p,$r);}
   else $h.='<h2>Semak permohonan</h2>'.self::form('status',self::input('access','Kod akses selamat','password'),'Semak status');
  }catch(\PDOException $e){http_response_code(503);Support::log($e);$h='<p role="alert">Permintaan tidak dapat diproses. Cuba kemudian.</p>';}catch(\InvalidArgumentException|\RuntimeException $e){http_response_code(400);$h='<p role="alert">'.self::e($e->getMessage()).'</p><a href="'.($admin?'/public-program.php?admin=1':'/public-program.php').'">Kembali</a>';}
  self::page($h);
 }
 static function page(string $h):never {
  $base=APP_ROOT.'/assets/public-program.';
  Support::layout('Program Awam','<link rel="stylesheet" href="/assets/public-program.css?v='.substr(hash_file('sha256',$base.'css'),0,12).'"><main class="pp">'.$h.'</main><script src="/assets/public-program.js?v='.substr(hash_file('sha256',$base.'js'),0,12).'" defer></script>');
 }
 static function admin(\PDO $p,int $u,?int $id,string $origin):string {
  $r=$id?Service::program($p,$id):null;$orgs=[];
  foreach(Service::q($p,'SELECT id,name FROM organisations WHERE is_active=1')->fetchAll() as $o){try{Service::permit($p,$u,(int)$o['id']);$orgs[]=$o;}catch(\RuntimeException $e){}}
  if(!$orgs)throw new \RuntimeException('Permission Program Awam diperlukan.');
  if($r)Service::permit($p,$u,(int)$r['organisation_id']);
  $h='<h2>Program Manager</h2><p><a href="/public-program.php?admin=1">Program baharu</a> · <a href="/eforms?studio=new">Bina borang tambahan tanpa PDF</a></p>';
  foreach(Service::q($p,'SELECT id,title,organisation_id FROM public_programs ORDER BY id DESC')->fetchAll() as $a)if(in_array($a['organisation_id'],array_column($orgs,'id')))$h.='<p><a href="/public-program.php?admin=1&id='.$a['id'].'">'.self::e($a['title']).'</a></p>';
  $f='<input type="hidden" name="revision" value="'.($r['revision']??1).'"><label>Organisasi<select name="organisation_id" required>';
  foreach($orgs as $o)$f.='<option value="'.$o['id'].'"'.(($r['organisation_id']??null)==$o['id']?' selected':'').'>'.self::e($o['name']).'</option>';$f.='</select></label>';
  foreach(['title'=>['Nama program','text'],'location'=>['Lokasi','text'],'event_date'=>['Tarikh program','date'],'quota'=>['Kuota anak','number'],'min_age'=>['Umur minimum pada tarikh program','number'],'max_age'=>['Umur maksimum pada tarikh program','number'],'opens_at'=>['Buka (waktu Malaysia)','datetime-local'],'closes_at'=>['Tutup (waktu Malaysia)','datetime-local']] as $k=>$spec){$v=$r[$k]??'';if($v&&in_array($k,['opens_at','closes_at']))$v=(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Kuala_Lumpur'))->format('Y-m-d\TH:i');$f.=self::input($k,$spec[0],$spec[1],$v);}
  $f.='<label>Borang tambahan (pilihan)<select name="version_id"><option value="">Tiada</option>';
  foreach(Service::q($p,"SELECT v.id,t.title,v.version,t.organisation_id FROM eforms_versions v JOIN eforms_templates t ON t.id=v.template_id WHERE t.status='published' ORDER BY t.id,v.version DESC")->fetchAll() as $v)if(in_array($v['organisation_id'],array_column($orgs,'id')))$f.='<option value="'.$v['id'].'"'.(($r['version_id']??null)==$v['id']?' selected':'').'>'.self::e($v['title'].' v'.$v['version']).'</option>';
  $f.='</select></label><p>Medan tambahan ringkas sahaja; condition, staff autofill, fail dan jadual belum disokong pada borang awam RC1.</p>';
  foreach(['is_open'=>'Buka pendaftaran','waiting_enabled'=>'Benarkan senarai menunggu'] as $k=>$l)$f.='<label><input type="checkbox" name="'.$k.'" value="1"'.(!empty($r[$k])?' checked':'').'>'.$l.'</label>';
  $h.=self::form('save','<div class="pp-grid">'.$f.'</div>','Simpan program');
  if($r){$url=$origin.'/public-program.php?p='.$r['public_code'];$h.='<h3>Pautan awam</h3><a href="'.self::e($url).'">'.self::e($url).'</a>';
   if(class_exists('EPejabat\\Modules\\Training\\Qr'))$h.=\EPejabat\Modules\Training\Qr::svg($url);
   $rows=Service::registry($p,$u,$id);$counts=array_count_values(array_column($rows,'registration_status'));$h.='<p>Jumlah: '.count($rows).' · Dalam kuota: '.($counts['RECEIVED']??0).' / '.$r['quota'].' · Menunggu: '.($counts['WAITLIST']??0).'</p>';
   $h.=self::form('promote','<p>Promosi hanya peserta menunggu paling awal. Tidak mengesahkan tempat.</p>','Promosi peserta terawal').self::form('export','<p>Eksport tanpa MyKid/MyKad.</p>','Eksport CSV');
   $h.='<label>Cari peserta<input type="search" data-search></label><label>Status<select data-status><option value="">Semua</option><option>RECEIVED</option><option>WAITLIST</option><option>CANCELLED</option></select></label><div class="pp-scroll"><table><thead><tr><th>ID</th><th>Nama</th><th>Umur</th><th>Pengisytiharan</th><th>Status</th></tr></thead><tbody>';
   foreach($rows as $a)$h.='<tr data-row data-status="'.$a['registration_status'].'"><td>'.self::e($a['participant_code']).'</td><td>'.self::e($a['name']).'</td><td>'.$a['age'].'</td><td>'.self::e($a['category']).'</td><td>'.$a['registration_status'].'</td></tr>';$h.='</tbody></table></div>';
  }return $h;
 }
 static function registration(\PDO $p,array $r):string {
  if(!$r['is_open']||gmdate('Y-m-d H:i:s')<$r['opens_at']||gmdate('Y-m-d H:i:s')>$r['closes_at'])return '<h2>'.self::e($r['title']).'</h2><p>Pendaftaran ditutup.</p>';
  $request=bin2hex(random_bytes(24));$_SESSION['public_program_issued']=array_filter($_SESSION['public_program_issued']??[],fn($t)=>time()-$t<7200);if(count($_SESSION['public_program_issued'])>20)array_shift($_SESSION['public_program_issued']);$_SESSION['public_program_issued'][$request]=time();
  $fields=Service::fields($p,$r['version_id']?(int)$r['version_id']:null,(int)$r['organisation_id']);
  $child='<fieldset data-child><legend>Anak <span data-number>1</span></legend><div class="pp-grid">'.self::input('children[0][name]','Nama penuh anak').self::input('children[0][mykid]','MyKid/MyKad (12 digit)','text').self::input('children[0][dob]','Tarikh lahir','date').'<label>Pengisytiharan sosioekonomi<select name="children[0][category]"><option value="none">Tiada</option><option value="poor">Fakir miskin</option><option value="orphan">Anak yatim</option><option value="low_income">Pendapatan rendah</option><option value="other">Lain-lain</option></select></label>'.self::input('children[0][note]','Catatan pengisytiharan','text','',false);
  foreach($fields as $f){$n='children[0][extra]['.$f['key'].']';$label=self::e($f['label']);if($f['type']==='section')$child.='<h3>'.$label.'</h3>';elseif(in_array($f['type'],['dropdown','radio'])){$child.='<label>'.$label.'<select name="'.$n.'"'.($f['required']?' required':'').'><option value="">Pilih</option>';foreach($f['options'] as $o)$child.='<option>'.self::e($o).'</option>';$child.='</select></label>';}elseif($f['type']==='checkbox')$child.='<label><input type="checkbox" name="'.$n.'" value="1"'.($f['required']?' required':'').'>'.$label.'</label>';elseif($f['type']==='textarea')$child.='<label>'.$label.'<textarea name="'.$n.'"'.($f['required']?' required':'').'></textarea></label>';else $child.=self::input($n,$f['label'],$f['type'],'',$f['required']);}
  $child.='</div><button type="button" data-remove>Buang anak</button></fieldset>';
  return '<h2>'.self::e($r['title']).'</h2><p>'.self::e($r['event_date'].' · '.$r['location']).'</p><p>Umur '.$r['min_age'].'–'.$r['max_age'].' tahun pada tarikh program. Tiada akaun staf atau OTP diperlukan.</p>'.self::form('register','<input type="hidden" name="code" value="'.$r['public_code'].'"><input type="hidden" name="request" value="'.$request.'"><div class="pp-trap" aria-hidden="true"><label>Laman web<input name="website" tabindex="-1" autocomplete="off"></label></div><h3>Penjaga</h3><div class="pp-grid">'.self::input('guardian[name]','Nama penjaga').self::input('guardian[email]','E-mel penjaga','email').self::input('guardian[phone]','Telefon','tel').'</div><div data-children>'.$child.'</div><template data-child-template>'.$child.'</template><button type="button" data-add>Tambah Anak</button><p>Pendaftaran bukan jaminan tempat. Pengisytiharan tidak meluluskan subsidi. Data digunakan untuk pengurusan program dan hanya petugas diberi kuasa boleh mengaksesnya. Jangan kongsi pautan status.</p><label><input type="checkbox" name="ack" value="1" required>Saya penjaga yang diberi kuasa dan mengesahkan maklumat permohonan.</label>','Hantar permohonan');
 }
}
