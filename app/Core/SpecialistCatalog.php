<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class SpecialistCatalog {
 public const JPA='https://docs.jpa.gov.my/docs/sspa/PP_SSPA_INDUK_COMBINED.pdf';
 public static function candidates():array {
  $rows=[];foreach(['UTAMA'=>'Gred Utama (JUSA)','KHAS'=>'Gred Khas'] as $prefix=>$label)foreach(['C','B','A'] as $letter)$rows[]=['category'=>'top_grade','code'=>$prefix.'_'.$letter,'name'=>$label.' '.$letter,'source'=>self::JPA,'reference'=>'Jadual E4-U89, PDF halaman 830','profession_name'=>null];
  foreach(['TURUS'=>'Gred Utama Turus','KHAS_TURUS'=>'Gred Khas Turus'] as $prefix=>$label)foreach(['III','II','I'] as $letter)$rows[]=['category'=>'top_grade','code'=>$prefix.'_'.$letter,'name'=>$label.' '.$letter,'source'=>self::JPA,'reference'=>'Jadual E4-U89, PDF halaman 830','profession_name'=>null];
  $rows[]=['category'=>'speciality','code'=>'NSR_FAMILY_MEDICINE','name'=>'Perubatan Keluarga','source'=>'https://pharmacy.moh.gov.my/ms/dokumen/formulari-ubat-kementerian-kesihatan-malaysia.html','reference'=>'Kategori preskriber A/KK; nomenklatur sahaja','profession_name'=>'Pegawai Perubatan'];
  $rows[]=['category'=>'speciality','code'=>'NSR_PUBLIC_HEALTH','name'=>'Perubatan Kesihatan Awam','source'=>'https://jknjohor.moh.gov.my/jknj/direktori-bahagian-kesihatan-awam/','reference'=>'Direktori rasmi Bahagian Kesihatan Awam; nomenklatur sahaja','profession_name'=>'Pegawai Perubatan'];return $rows;
 }
 public static function preview(\PDO $p,int $actor):array {
  Authorization::require($p,$actor,'core.master.manage');$rows=[];foreach(self::candidates() as $c){$c['profession_id']=$c['profession_name']?Database::query($p,"SELECT id FROM master_data WHERE category='profession' AND name=? AND is_active=1",[$c['profession_name']])->fetchColumn():null;
   $existing=Database::query($p,'SELECT * FROM professional_master_catalog WHERE category=? AND code=?',[$c['category'],$c['code']])->fetch();
   if($existing)$c['status']=$existing['is_active']&&$existing['verification_status']==='VERIFIED'&&($c['profession_name']===null||(int)$existing['profession_id']===(int)$c['profession_id'])?'EXISTING':'HOLD_EXISTING_REVIEW';
   else {$matches=Database::query($p,'SELECT id FROM professional_master_catalog WHERE category=? AND LOWER(name)=LOWER(?)',[$c['category'],$c['name']])->fetchAll();$c['status']=$matches?'HOLD_DUPLICATE':($c['profession_name']&&!$c['profession_id']?'HOLD_MISSING_PROFESSION':'READY');}$rows[]=$c;
  }return $rows;
 }
 public static function activate(\PDO $p,int $actor,string $reason):array {
  Authorization::require($p,$actor,'core.master.manage');$reason=Core::text($reason,500);return Core::tx($p,function()use($p,$actor,$reason){$rows=self::preview($p,$actor);$result=[];foreach($rows as $r){if($r['status']!=='READY'){$result[]=['code'=>$r['code'],'status'=>$r['status']];continue;}$id=RegistrationMaster::professionalCatalogSave($p,$actor,['category'=>$r['category'],'code'=>$r['code'],'name'=>$r['name'],'profession_id'=>$r['profession_id'],'source'=>$r['source'],'reason'=>$reason]);$result[]=['code'=>$r['code'],'id'=>$id,'status'=>'IMPORTED'];}Audit::record($p,'master.specialist_catalog_batch',$actor,null,'JPA_KKM','core',$reason,null,['results'=>$result]);return $result;});
 }
 public static function panel(\PDO $p,int $actor):string {
  $rows=self::preview($p,$actor);$e=fn($v)=>Support::escape((string)$v);$h='<section class="card"><h2>Pakar, Pakar Perunding &amp; Gred JUSA</h2><p>Profesion asas kekal Pegawai Perubatan. Tahap Pakar/Pakar Perunding dan gred JUSA/Khas/Turus adalah medan berasingan. Pilihan ini tidak mengesahkan lantikan pengguna; dokumen dan semakan Admin masih diperlukan. Tiada padanan SSM atau lantikan direka.</p><div class="table-responsive"><table><thead><tr><th>Pilihan</th><th>Status import</th><th>Rujukan</th></tr></thead><tbody>';foreach($rows as $r)$h.='<tr><td>'.$e($r['name']).'</td><td>'.$e($r['status']).'</td><td><a href="'.$e($r['source']).'" target="_blank" rel="noopener">'.$e($r['reference']).'</a></td></tr>';
  return $h.'</tbody></table></div>'.Support::form('specialist_catalog_activate','<label>Sebab kelulusan katalog<input name="reason" required maxlength="500"></label>','Aktifkan pilihan READY sahaja').'<p>Rekod sedia ada tidak diubah; HOLD perlu semakan Admin. Import berulang tidak mencipta pendua. Selepas import, buka Profil saya → Profesion &amp; Kepakaran.</p></section>';
 }
}
