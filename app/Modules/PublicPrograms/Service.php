<?php
declare(strict_types=1);
namespace EPejabat\Modules\PublicPrograms;
use EPejabat\Core\{Core,Database,Crypto,Authorization,Audit};
use EPejabat\Modules\EForms\Schema;
final class Service {
 public static function q(\PDO $p,string $s,array $a=[]):\PDOStatement{return Database::query($p,$s,$a);}
 public static function can(\PDO $p,int $u,int $o,string $action='participants.view',?int $program=null):bool {
  if(!in_array($action,['manage','participants.view','participants.export'],true))return false;
  if(!self::q($p,'SELECT 1 FROM users WHERE id=? AND is_active=1',[$u])->fetchColumn()||!self::q($p,'SELECT 1 FROM organisations WHERE id=? AND is_active=1',[$o])->fetchColumn())return false;
  if(Authorization::super($p,$u))return true;
  if(Authorization::allowed($p,$u,'public_programs.'.$action,$o))return true;
  if($action==='participants.view'){
   $roles=self::q($p,"SELECT a.organisation_id,a.include_descendants,r.code FROM user_role_assignments a JOIN roles r ON r.id=a.role_id WHERE a.user_id=? AND a.module_code='core' AND r.module_code='core' AND r.is_active=1 AND r.code IN ('pkd_admin','clinic_admin','unit_admin') AND EXISTS(SELECT 1 FROM organisations z WHERE z.id=a.organisation_id AND z.is_active=1)",[$u])->fetchAll();
   foreach($roles as $a){$org=(int)$a['organisation_id'];if($org===$o)return true;if($a['code']==='pkd_admin'&&$a['include_descendants']&&self::q($p,'SELECT 1 FROM organisation_hierarchy WHERE ancestor_id=? AND descendant_id=?',[$org,$o])->fetchColumn())return true;}
  }
  if($program!==null){
   $r=self::program($p,$program);if((int)$r['organisation_id']!==$o)return false;
   $raw=self::q($p,'SELECT setting_value FROM system_settings WHERE setting_key=?',['public_program.access.'.$program.'.'.$u])->fetchColumn();
   if(is_string($raw)){$g=json_decode($raw,true);$issuer=(int)($g['actor']??0);
    if(is_array($g)&&($g['program_id']??0)===$program&&($g['user_id']??0)===$u&&($g['organisation_id']??0)===$o&&($g['expires']??0)>time()&&in_array($action,$g['permissions']??[],true)&&self::canDelegate($p,$issuer,$o))return true;
   }
  }return false;
 }
 public static function canDelegate(\PDO $p,int $u,int $o):bool {
  return (bool)self::q($p,'SELECT 1 FROM users WHERE id=? AND is_active=1',[$u])->fetchColumn()&&(Authorization::super($p,$u)||Authorization::allowed($p,$u,'core.roles.manage',$o));
 }
 public static function delegate(\PDO $p,int $actor,int $program,int $target,array $permissions,int $days,string $reason):void {
  $r=self::program($p,$program);$org=(int)$r['organisation_id'];
  if(!self::canDelegate($p,$actor,$org)||$actor===$target)throw new \RuntimeException('Delegasi tidak dibenarkan.');
  if(!self::q($p,'SELECT 1 FROM users WHERE id=? AND is_active=1',[$target])->fetchColumn())throw new \RuntimeException('Akaun tidak tersedia.');
  $permissions=array_values(array_unique($permissions));if($permissions&&!in_array('participants.view',$permissions,true))throw new \InvalidArgumentException('Pelantikan memerlukan permission melihat peserta.');if(array_diff($permissions,['participants.view','manage','participants.export'])||$days<1||$days>365||trim($reason)===''||strlen($reason)>500)throw new \InvalidArgumentException('Delegasi tidak sah.');
  // A delegator cannot grant an action they cannot exercise themselves.
  foreach($permissions as $permission)if(!self::can($p,$actor,$org,$permission,$program))throw new \RuntimeException('Kuasa delegasi melebihi permission pemberi.');
  Core::tx($p,function()use($p,$actor,$program,$target,$permissions,$days,$reason,$org){
   $key='public_program.access.'.$program.'.'.$target;
   $old=self::q($p,'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',[$key])->fetchColumn();
   $data=['program_id'=>$program,'user_id'=>$target,'organisation_id'=>$org,'actor'=>$actor,'permissions'=>$permissions,'expires'=>time()+$days*86400];
   self::q($p,'INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,json_encode($data,JSON_THROW_ON_ERROR)]);
   Audit::record($p,'public_program.access_delegated',$actor,$org,(string)$target,'public_programs',$reason,$old?json_decode($old,true):null,$data);
  });
 }
 public static function permit(\PDO $p,int $u,int $o,string $action='manage',?int $program=null):void {
  if(!self::can($p,$u,$o,$action,$program))throw new \RuntimeException('Akses ditolak.');
 }
 public static function program(\PDO $p,int $id,bool $lock=false):array {
  $r=self::q($p,'SELECT * FROM public_programs WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetch();if(!$r)throw new \RuntimeException('Program tidak tersedia.');return $r;
 }
 public static function fields(\PDO $p,?int $v,int $org):array {
  if(!$v)return [];
  $r=self::q($p,'SELECT v.*,t.organisation_id,t.status FROM eforms_versions v JOIN eforms_templates t ON t.id=v.template_id WHERE v.id=?',[$v])->fetch();
  if(!$r||(int)$r['organisation_id']!==$org||$r['status']!=='published')throw new \InvalidArgumentException('Pilih versi borang diterbitkan dalam organisasi program.');
  $h=hash('sha256',$r['schema_json'].$r['workflow_json'].$r['allow_self_approval'].$r['acknowledgement']);
  if(!hash_equals($r['checksum'],$h))throw new \RuntimeException('Integriti borang gagal.');
  $fields=Schema::definition(json_decode($r['schema_json'],true,64,JSON_THROW_ON_ERROR));
  foreach($fields as $f)if(!in_array($f['type'],['text','textarea','number','date','time','email','tel','dropdown','radio','checkbox','section'],true)||$f['condition']!==null||$f['autofill']!=='')throw new \InvalidArgumentException('Borang awam RC1 menyokong medan ringkas tanpa condition/autofill. Gunakan versi borang khusus program.');
  return $fields;
 }
 public static function save(\PDO $p,int $u,array $d,?int $id=null):int {
  $org=Core::id($d['organisation_id']??null);self::permit($p,$u,$org,'manage',$id);
  $title=Core::text($d['title']??'',190);$location=Core::text($d['location']??'',255);
  $event=self::date($d['event_date']??'');
  foreach(['opens_at','closes_at'] as $k){$dt=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($d[$k]??''),new \DateTimeZone('Asia/Kuala_Lumpur'));if(!$dt||$dt->format('Y-m-d\TH:i')!==$d[$k])throw new \InvalidArgumentException('Tarikh/masa tidak sah.');$d[$k]=$dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
  if($d['opens_at']>=$d['closes_at']||substr($d['closes_at'],0,10)>$event)throw new \InvalidArgumentException('Susunan tarikh tidak sah.');
  foreach(['quota','min_age','max_age'] as $k){if(filter_var($d[$k]??null,FILTER_VALIDATE_INT)===false)throw new \InvalidArgumentException('Nilai integer diperlukan.');$d[$k]=(int)$d[$k];}
  if($d['quota']<0||$d['quota']>100000||$d['min_age']<0||$d['max_age']>120||$d['max_age']<$d['min_age'])throw new \InvalidArgumentException('Had kuota/umur tidak sah.');
  $v=empty($d['version_id'])?null:Core::id($d['version_id']);self::fields($p,$v,$org);
  return Core::tx($p,function()use($p,$u,$d,$id,$org,$title,$location,$event,$v){
   if($id){$old=self::program($p,$id,true);if((int)$old['organisation_id']!==$org)throw new \RuntimeException('Organisasi tidak boleh ditukar.');if((int)$old['revision']!==(int)($d['revision']??0))throw new \RuntimeException('Program telah berubah. Muat semula.');
    $count=(int)self::q($p,'SELECT COUNT(*) FROM public_participants WHERE program_id=?',[$id])->fetchColumn();
    if($count&&($old['event_date']!==$event||(int)$old['min_age']!==$d['min_age']||(int)$old['max_age']!==$d['max_age']||(int)$old['version_id']!==(int)$v))throw new \RuntimeException('Tarikh, umur dan versi borang dikunci selepas pendaftaran pertama.');
    if($d['quota']<self::reserved($p,$id))throw new \RuntimeException('Kuota kurang daripada permohonan dalam kuota.');
    self::q($p,'UPDATE public_programs SET title=?,location=?,opens_at=?,closes_at=?,quota=?,is_open=?,waiting_enabled=?,event_date=?,min_age=?,max_age=?,version_id=?,revision=revision+1 WHERE id=?',[$title,$location,$d['opens_at'],$d['closes_at'],$d['quota'],!empty($d['is_open'])?1:0,!empty($d['waiting_enabled'])?1:0,$event,$d['min_age'],$d['max_age'],$v,$id]);
   }else{self::q($p,'INSERT INTO public_programs(organisation_id,public_code,title,location,event_date,opens_at,closes_at,quota,min_age,max_age,is_open,waiting_enabled,version_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$org,bin2hex(random_bytes(16)),$title,$location,$event,$d['opens_at'],$d['closes_at'],$d['quota'],$d['min_age'],$d['max_age'],!empty($d['is_open'])?1:0,!empty($d['waiting_enabled'])?1:0,$v,$u]);$id=(int)$p->lastInsertId();}
   Audit::record($p,'public_program.saved',$u,$org,(string)$id,'public_programs');return $id;
  });
 }
 public static function date(string $v):string {$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new \InvalidArgumentException('Tarikh tidak sah.');return $v;}
 public static function reserved(\PDO $p,int $id):int{return (int)self::q($p,"SELECT COUNT(*) FROM public_participants WHERE program_id=? AND registration_status='RECEIVED'",[$id])->fetchColumn();}
 public static function throttle(\PDO $p,string $ip):void {
  $key=hash_hmac('sha256','public-program:'.(int)(time()/900).':'.$ip,Crypto::key());
  self::q($p,'INSERT INTO public_program_attempts(bucket_hash,attempts,expires_at) VALUES(?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE attempts=attempts+1',[$key]);
  if((int)self::q($p,'SELECT attempts FROM public_program_attempts WHERE bucket_hash=?',[$key])->fetchColumn()>12)throw new \RuntimeException('Terlalu banyak cubaan. Cuba kemudian.');
 }
 public static function register(\PDO $p,string $code,array $guardian,array $children,string $request,string $origin):array {
  if(!preg_match('/\A[a-f0-9]{32}\z/',$code)||!preg_match('/\A[a-f0-9]{48}\z/',$request))throw new \InvalidArgumentException('Permintaan tidak sah.');
  $g=['name'=>Core::text($guardian['name']??'',190),'email'=>Core::email($guardian['email']??''),'phone'=>Core::text($guardian['phone']??'',30)];
  if(!preg_match('/\A\+?[0-9 ()-]{7,25}\z/',$g['phone'])||count($children)<1||count($children)>10)throw new \InvalidArgumentException('Semak telefon dan bilangan anak (1–10).');
  return Core::tx($p,function()use($p,$code,$g,$children,$request,$origin){
   $r=self::q($p,'SELECT * FROM public_programs WHERE public_code=? FOR UPDATE',[$code])->fetch();
   if(!$r||!$r['is_open']||gmdate('Y-m-d H:i:s')<$r['opens_at']||gmdate('Y-m-d H:i:s')>$r['closes_at'])throw new \RuntimeException('Pendaftaran tidak dibuka.');
   if(self::q($p,'SELECT 1 FROM public_guardians WHERE request_hash=?',[hash('sha256',$request)])->fetchColumn())return ['duplicate_request'=>true];
   $fields=self::fields($p,$r['version_id']?(int)$r['version_id']:null,(int)$r['organisation_id']);$seen=[];$clean=[];
   foreach($children as $c){
    if(!is_array($c)||!preg_match('/\A[0-9]{12}\z/',(string)($c['mykid']??'')))throw new \InvalidArgumentException('MyKid/MyKad mesti tepat 12 digit.');
    $dob=self::date((string)($c['dob']??''));$event=new \DateTimeImmutable($r['event_date']);$birth=new \DateTimeImmutable($dob);$age=$birth->diff($event)->y;
    if($birth>$event||$birth>new \DateTimeImmutable('today')||$age<(int)$r['min_age']||$age>(int)$r['max_age'])throw new \InvalidArgumentException('Tarikh lahir/umur di luar kelayakan program.');
    $hash=hash_hmac('sha256','program:'.$r['id'].':'.$c['mykid'],Crypto::key());
    if(isset($seen[$hash])||self::q($p,'SELECT 1 FROM public_participants WHERE program_id=? AND identity_hash=?',[$r['id'],$hash])->fetchColumn())throw new \RuntimeException('Permohonan tidak dapat diteruskan. Semak maklumat atau hubungi urus setia; jangan daftar semula.');
    $seen[$hash]=true;$category=$c['category']??'none';if(!in_array($category,['none','poor','orphan','low_income','other'],true))throw new \InvalidArgumentException('Kategori tidak sah.');
    $clean[]=['hash'=>$hash,'data'=>['name'=>Core::text($c['name']??'',190),'mykid'=>$c['mykid'],'dob'=>$dob,'age_at_event'=>$age,'category'=>$category,'note'=>Core::text($c['note']??'',1000,false),'extra'=>Schema::values($p,$fields,$c['extra']??[],(int)$r['organisation_id'],true)]];
   }
   $remaining=max(0,(int)$r['quota']-self::reserved($p,(int)$r['id']));
   // Waiting applicants keep priority even after a quota increase; only Admin promotes them.
   if(self::q($p,"SELECT 1 FROM public_participants WHERE program_id=? AND registration_status='WAITLIST' LIMIT 1",[$r['id']])->fetchColumn())$remaining=0;
   if(!$r['waiting_enabled']&&count($clean)>$remaining)throw new \RuntimeException('Kuota tidak mencukupi untuk semua anak. Tiada rekod disimpan.');
   $token=bin2hex(random_bytes(32));$expiry=gmdate('Y-m-d H:i:s',time()+30*86400);
   self::q($p,'INSERT INTO public_guardians(program_id,contact_cipher,access_hash,access_expires,request_hash) VALUES(?,?,?,?,?)',[$r['id'],Crypto::seal(json_encode($g,JSON_THROW_ON_ERROR)),hash('sha256',$token),$expiry,hash('sha256',$request)]);$gid=(int)$p->lastInsertId();$received=0;$waiting=0;$ids=[];
   foreach($clean as $c){$status=$remaining-->0?'RECEIVED':'WAITLIST';$status==='RECEIVED'?$received++:$waiting++;$pid=bin2hex(random_bytes(12));
    self::q($p,'INSERT INTO public_participants(participant_code,program_id,guardian_id,identity_hash,profile_cipher,registration_status) VALUES(?,?,?,?,?,?)',[$pid,$r['id'],$gid,$c['hash'],Crypto::seal(json_encode($c['data'],JSON_THROW_ON_ERROR)),$status]);$ids[]=$pid;
   }
   // Existing delivery queue and SMTP worker; direct exact recipient avoids staff preferred-email redirection.
   $url=rtrim($origin,'/').'/public-program.php#access='.$token;
   self::q($p,'INSERT INTO notification_deliveries(email,template_key,payload_cipher) VALUES(?,?,?)',[$g['email'],'public_program_receipt',Crypto::seal(json_encode(['received'=>$received,'waiting'=>$waiting,'url'=>$url,'expiry'=>$expiry],JSON_THROW_ON_ERROR))]);
   Audit::record($p,'public_program.received',null,(int)$r['organisation_id'],(string)$gid,'public_programs',null,null,['children'=>count($clean)]);
   return ['received'=>$received,'waiting'=>$waiting,'participants'=>$ids,'url'=>$url];
  });
 }
 public static function status(\PDO $p,string $token):array {
  if(!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new \RuntimeException('Pautan tidak sah atau telah tamat.');
  $g=self::q($p,'SELECT id,program_id FROM public_guardians WHERE access_hash=? AND access_expires>UTC_TIMESTAMP()',[hash('sha256',$token)])->fetch();if(!$g)throw new \RuntimeException('Pautan tidak sah atau telah tamat.');
  return self::q($p,'SELECT participant_code,registration_status,clinical_status,payment_status,place_status FROM public_participants WHERE guardian_id=? ORDER BY id',[$g['id']])->fetchAll();
 }
 public static function promote(\PDO $p,int $u,int $program):int {
  return Core::tx($p,function()use($p,$u,$program){$r=self::program($p,$program,true);self::permit($p,$u,(int)$r['organisation_id'],'manage',$program);if(self::reserved($p,$program)>=(int)$r['quota'])throw new \RuntimeException('Kuota penuh.');$id=self::q($p,"SELECT id FROM public_participants WHERE program_id=? AND registration_status='WAITLIST' ORDER BY id LIMIT 1 FOR UPDATE",[$program])->fetchColumn();if(!$id)throw new \RuntimeException('Senarai menunggu kosong.');self::q($p,"UPDATE public_participants SET registration_status='RECEIVED' WHERE id=?",[$id]);$contact=self::q($p,'SELECT g.contact_cipher,c.participant_code FROM public_participants c JOIN public_guardians g ON g.id=c.guardian_id WHERE c.id=?',[$id])->fetch();$email=json_decode(Crypto::open($contact['contact_cipher']),true)['email'];self::q($p,'INSERT INTO notification_deliveries(email,template_key,payload_cipher) VALUES(?,?,?)',[$email,'public_program_update',Crypto::seal(json_encode(['participant'=>$contact['participant_code']],JSON_THROW_ON_ERROR))]);Audit::record($p,'public_program.promoted',$u,(int)$r['organisation_id'],(string)$id,'public_programs');return (int)$id;});
 }
 public static function registry(\PDO $p,int $u,int $program,bool $export=false):array {
  $r=self::program($p,$program);self::permit($p,$u,(int)$r['organisation_id'],$export?'participants.export':'participants.view',$program);
  $out=[];foreach(self::q($p,'SELECT id,participant_code,profile_cipher,registration_status,clinical_status,payment_status,place_status,created_at FROM public_participants WHERE program_id=? ORDER BY id',[$program])->fetchAll() as $row){$d=json_decode(Crypto::open($row['profile_cipher']),true);unset($row['profile_cipher']);$row['name']=$d['name'];$row['age']=$d['age_at_event'];$row['category']=$d['category'];$out[]=$row;}
  Audit::record($p,$export?'public_program.exported':'public_program.registry_viewed',$u,(int)$r['organisation_id'],(string)$program,'public_programs');return $out;
 }
}
