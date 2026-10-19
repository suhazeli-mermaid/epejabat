<?php
declare(strict_types=1);
namespace EPejabat\Modules\EForms;
use EPejabat\Core\{Core,Database,Audit,Support};
/** Immutable PDF bindings extend the existing version and submission ledger. */
final class Visual {
 public static function ready(\PDO $p):bool {return (bool)Database::query($p,"SELECT 1 FROM database_migrations WHERE version='eforms:Phase2:210_package_pdf_support.sql' AND status='applied'")->fetchColumn();}
 public static function source(\PDO $p,int $u,int $id,bool $admin=true):array {
  $r=Database::query($p,'SELECT * FROM eforms_pdf_sources WHERE id=?',[$id])->fetch();if(!$r)throw new \RuntimeException('PDF tidak tersedia.');
  if($admin)Service::permit($p,$u,'templates.manage',(int)$r['organisation_id']);
  $r['path']=APP_ROOT.'/storage/eforms-originals/'.$r['storage_key'].'.pdf';
  foreach([APP_ROOT.'/storage',dirname($r['path']),$r['path']] as $f)if(is_link($f))throw new \RuntimeException('Symlink ditolak.');
  if(!is_file($r['path'])||!hash_equals($r['sha256'],hash_file('sha256',$r['path'])))throw new \RuntimeException('Integriti PDF asal gagal.');return $r;
 }
 public static function upload(\PDO $p,int $u,int $org,array $f):int {
  Service::permit($p,$u,'templates.manage',$org);
  if(($f['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??'')||($f['size']??0)<8||$f['size']>10485760||strtolower(pathinfo($f['name']??'',PATHINFO_EXTENSION))!=='pdf')throw new \InvalidArgumentException('PDF sahaja, maksimum 10 MB.');
  $path=$f['tmp_name'];if((new \finfo(FILEINFO_MIME_TYPE))->file($path)!=='application/pdf'||file_get_contents($path,false,null,0,5)!=='%PDF-')throw new \InvalidArgumentException('Format PDF tidak sah.');
  $dir=APP_ROOT.'/storage/eforms-originals';if(is_link(APP_ROOT.'/storage')||is_link($dir))throw new \RuntimeException('Storage tidak selamat.');
  if(!is_dir($dir)&&!mkdir($dir,0700))throw new \RuntimeException('Storage tidak tersedia.');
  // Defence in depth; root/storage deny rules remain required and checked before deployment.
  if(!is_file($dir.'/.htaccess')&&file_put_contents($dir.'/.htaccess',"Require all denied\n")===false)throw new \RuntimeException('Perlindungan storage gagal.');
  $key=bin2hex(random_bytes(24));$dest=$dir.'/'.$key.'.pdf';$sha=hash_file('sha256',$path);
  if(!move_uploaded_file($path,$dest))throw new \RuntimeException('Simpanan PDF gagal.');chmod($dest,0600);
  try{Database::query($p,'INSERT INTO eforms_pdf_sources(organisation_id,created_by,storage_key,sha256,byte_size,original_name) VALUES(?,?,?,?,?,?)',[$org,$u,$key,$sha,filesize($dest),Core::text(basename($f['name']),190)]);$id=(int)$p->lastInsertId();Audit::record($p,'eforms.pdf_uploaded',$u,$org,(string)$id,'eforms',null,null,['sha256'=>$sha]);return $id;}catch(\Throwable $e){unlink($dest);throw $e;}
 }
 public static function definition(array $d,array $fields):array {
  $pages=$d['pages']??[];$maps=$d['maps']??[];
  if(!is_array($pages)||count($pages)>30||!is_array($maps)||count($maps)>500)throw new \InvalidArgumentException('Had 30 halaman / 500 pemetaan.');
  $out=[];foreach($pages as $pg){$w=(float)($pg['width']??0);$h=(float)($pg['height']??0);if(!is_finite($w)||!is_finite($h)||$w<50||$h<50||$w>3000||$h>3000)throw new \InvalidArgumentException('Saiz halaman tidak sah.');$out[]=['width'=>$w,'height'=>$h];}
  $keys=array_column($fields,'key');$clean=[];
  foreach($maps as $m){$key=(string)($m['key']??'');$page=filter_var($m['page']??null,FILTER_VALIDATE_INT);if((!in_array($key,[...$keys,'@applicant','@submitted_at','@approved_by','@approved_at','@approved_position','@approval_remarks'],true)&&!preg_match('/\A@step_[1-5]_(?:name|position|date|remarks)\z/',$key))||$page===false||$page<1||$page>count($out))throw new \InvalidArgumentException('Medan/halaman pemetaan tidak sah.');
   $r=['key'=>$key,'page'=>$page];foreach(['x','y','width','height','fontSize'] as $k){$v=(float)($m[$k]??0);if(!is_finite($v)||$v<0)throw new \InvalidArgumentException('Koordinat tidak sah.');$r[$k]=$v;}
   if($r['width']<4||$r['height']<4||$r['fontSize']<6||$r['fontSize']>48||$r['x']+$r['width']>$out[$page-1]['width']+.01||$r['y']+$r['height']>$out[$page-1]['height']+.01)throw new \InvalidArgumentException('Pemetaan di luar halaman.');
   $r['align']=in_array($m['align']??'',['left','center','right'],true)?$m['align']:'left';$r['wrap']=!empty($m['wrap']);$r['markValue']=Core::text($m['markValue']??'',100,false);$r['tableColumn']=max(-1,min(7,(int)($m['tableColumn']??-1)));$r['rowHeight']=max(8,min(100,(float)($m['rowHeight']??14)));$r['confirmed']=!empty($m['confirmed']);$clean[]=$r;
  }
  $effective=Core::text($d['effective_date']??'',10,false);if($effective!==''&&(!($dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$effective))||$dt->format('Y-m-d')!==$effective))throw new \InvalidArgumentException('Tarikh kuat kuasa tidak sah.');
  return ['owner_id'=>Core::id($d['owner_id']??null,true),'pages'=>$out,'maps'=>$clean,'review_confirmed'=>!empty($d['review_confirmed']),'category'=>Core::text($d['category']??'',100,false),'effective_date'=>$effective,'document_version'=>Core::text($d['document_version']??'',100,false),'recognition'=>'local PDF.js rules / admin-reviewed'];
 }
 public static function binding(\PDO $p,int $v):?array {$r=Database::query($p,'SELECT * FROM eforms_visual_versions WHERE version_id=?',[$v])->fetch();if(!$r)return null;if(!hash_equals($r['checksum'],hash('sha256',(string)$r['source_id'].'|'.$r['definition_json'])))throw new \RuntimeException('Integriti pemetaan gagal.');$r['definition']=json_decode($r['definition_json'],true,64,JSON_THROW_ON_ERROR);return $r;}
 public static function save(\PDO $p,int $u,int $org,string $title,array $fields,array $steps,string $ack,?int $id,?int $source,array $definition,?int $expected):int {
  return Core::tx($p,function()use($p,$u,$org,$title,$fields,$steps,$ack,$id,$source,$definition,$expected){
   if($id){$t=Database::query($p,'SELECT * FROM eforms_templates WHERE id=? FOR UPDATE',[$id])->fetch();if(!$t)throw new \RuntimeException('Template tidak tersedia.');Service::permit($p,$u,'templates.manage',(int)$t['organisation_id']);$last=(int)Database::query($p,'SELECT MAX(id) FROM eforms_versions WHERE template_id=?',[$id])->fetchColumn();if($expected!==$last)throw new \RuntimeException('Versi berubah. Buka semula sebelum menyimpan.');}
   if($source){$s=self::source($p,$u,$source);if((int)$s['organisation_id']!==$org)throw new \RuntimeException('Skop PDF tidak sepadan.');}
   $fields=Schema::definition($fields);$definition=self::definition($definition,$fields);$definition['owner_id']=$definition['owner_id']??$u;Service::permit($p,(int)$definition['owner_id'],'templates.manage',$org);if($source&&!$definition['pages'])throw new \InvalidArgumentException('Analisis PDF diperlukan.');
   $id=Service::template($p,$u,$org,$title,$fields,$steps,false,$ack,$id,true);$v=(int)Database::query($p,'SELECT MAX(id) FROM eforms_versions WHERE template_id=?',[$id])->fetchColumn();$json=json_encode($definition,JSON_THROW_ON_ERROR);
   Database::query($p,'INSERT INTO eforms_visual_versions(version_id,source_id,definition_json,checksum) VALUES(?,?,?,?)',[$v,$source,$json,hash('sha256',(string)$source.'|'.$json)]);return $id;
  });
 }
 public static function publishCheck(\PDO $p,int $id):void {
  $v=Database::query($p,'SELECT * FROM eforms_versions WHERE template_id=? ORDER BY version DESC LIMIT 1',[$id])->fetch();if(!$v)throw new \RuntimeException('Versi tiada.');$b=self::binding($p,(int)$v['id']);if(!$b)return;$d=$b['definition'];
  if(!$d['review_confirmed'])throw new \RuntimeException('Admin mesti mengesahkan medan dan workflow sebelum publish.');
  foreach($d['maps'] as $m)if(!$m['confirmed'])throw new \RuntimeException('Sahkan semua pemetaan PDF sebelum publish.');
  if($b['source_id'])foreach(json_decode($v['schema_json'],true) as $f)if($f['required']&&!in_array($f['type'],['file','section','pagebreak'],true)&&!in_array($f['key'],array_column($d['maps'],'key'),true))throw new \RuntimeException('Medan wajib belum dipetakan: '.$f['label']);
 }
 public static function copyRevision(\PDO $p,int $u,int $id,int $version,bool $duplicate):int {
  $r=Database::query($p,'SELECT t.organisation_id,t.title,v.* FROM eforms_versions v JOIN eforms_templates t ON t.id=v.template_id WHERE t.id=? AND v.id=?',[$id,$version])->fetch();if(!$r)throw new \RuntimeException('Versi tidak tersedia.');Service::permit($p,$u,'templates.manage',(int)$r['organisation_id']);$b=self::binding($p,$version);$d=$b['definition']??[];$d['review_confirmed']=false;
  return self::save($p,$u,(int)$r['organisation_id'],$r['title'].($duplicate?' (Salinan)':''),json_decode($r['schema_json'],true),json_decode($r['workflow_json'],true),$r['acknowledgement'],$duplicate?null:$id,!empty($b['source_id'])?(int)$b['source_id']:null,$d,$duplicate?null:(int)Database::query($p,'SELECT MAX(id) FROM eforms_versions WHERE template_id=?',[$id])->fetchColumn());
 }
 public static function capturePrint(\PDO $p,int $id,int $actor,bool $approval,?int $step=null):void {
  $staff=Database::query($p,'SELECT s.full_name,s.job_title FROM users u JOIN staff_profiles s ON s.staff_identity_id=u.staff_identity_id WHERE u.id=?',[$actor])->fetch();
  $data=($approval||$step!==null)?json_decode(Database::query($p,'SELECT data_json FROM eforms_print_snapshots WHERE submission_id=?',[$id])->fetchColumn()?:'{}',true):[];
  if($approval||$step!==null){$r=Database::query($p,"SELECT reason,created_at FROM eforms_history WHERE submission_id=? AND action='approve' ORDER BY id DESC LIMIT 1",[$id])->fetch();if($step!==null){if($step<1||$step>5)throw new \InvalidArgumentException('Langkah tidak sah.');foreach(['name'=>$staff['full_name']??'','position'=>$staff['job_title']??'','date'=>$r['created_at']??'','remarks'=>$r['reason']??''] as $key=>$value)$data['@step_'.$step.'_'.$key]=$value;}if($approval){$data['@approved_by']=$staff['full_name']??'';$data['@approved_position']=$staff['job_title']??'';$data['@approved_at']=$r['created_at']??'';$data['@approval_remarks']=$r['reason']??'';}}
  else{$data['@applicant']=$staff['full_name']??'';$data['@submitted_at']=Database::query($p,'SELECT acknowledged_at FROM eforms_submissions WHERE id=?',[$id])->fetchColumn()?:'';}
  Database::query($p,'INSERT INTO eforms_print_snapshots(submission_id,data_json) VALUES(?,?) ON DUPLICATE KEY UPDATE data_json=VALUES(data_json)',[$id,json_encode($data,JSON_THROW_ON_ERROR)]);
 }
 public static function printData(\PDO $p,int $u,int $id):array {
  $r=Service::get($p,$u,$id);$b=self::binding($p,(int)$r['version_id']);if(!$b||!$b['source_id'])throw new \RuntimeException('Template ini tiada PDF asal.');$s=self::source($p,$u,(int)$b['source_id'],false);$values=$r['values'];
  foreach($r['fields'] as $f){if(!Schema::visible($f,$values)){$values[$f['key']]='';continue;}if(in_array($f['type'],['staff','organisation'],true)&&!empty($values[$f['key']]))$values[$f['key']]=Database::query($p,$f['type']==='staff'?'SELECT full_name FROM staff_profiles WHERE staff_identity_id=?':'SELECT name FROM organisations WHERE id=?',[$values[$f['key']]])->fetchColumn();}
  $snapshot=json_decode(Database::query($p,'SELECT data_json FROM eforms_print_snapshots WHERE submission_id=?',[$id])->fetchColumn()?:'{}',true);foreach($snapshot as $k=>$v){if(str_starts_with($k,'@approved')||str_starts_with($k,'@step_')||$k==='@approval_remarks'){if($r['status']!=='approved')continue;}$values[$k]=$v;}
  Audit::record($p,'eforms.original_pdf_requested',$u,(int)$r['organisation_id'],(string)$id,'eforms');return ['definition'=>$b['definition'],'fields'=>$r['fields'],'values'=>$values,'pdf_url'=>'/eforms?original_submission='.$id,'sha256'=>$s['sha256'],'status'=>$r['status']];
 }
 public static function autofill(\PDO $p,int $u):array {
  $r=Database::query($p,'SELECT s.full_name,u.email,u.staff_identity_id,s.organisation_id,o.name AS organisation FROM users u LEFT JOIN staff_profiles s ON s.staff_identity_id=u.staff_identity_id LEFT JOIN organisations o ON o.id=s.organisation_id WHERE u.id=?',[$u])->fetch();
  return ['full_name'=>$r['full_name']??'','email'=>$r['email']??'','staff_id'=>(string)($r['staff_identity_id']??''),'organisation'=>$r['organisation']??'','organisation_id'=>(string)($r['organisation_id']??'')];
 }
}
