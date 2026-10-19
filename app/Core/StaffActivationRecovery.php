<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class StaffActivationRecovery {
 public static function inspect(\PDO $p,int $actor,string $email):array {
  $email=Core::email($email);$rows=Database::query($p,"SELECT DISTINCT s.staff_identity_id,s.full_name,s.organisation_id FROM staff_profiles s LEFT JOIN users u ON u.staff_identity_id=s.staff_identity_id LEFT JOIN staff_email_contacts c ON c.staff_identity_id=s.staff_identity_id AND c.email=? AND c.status IN ('PENDING_ADMIN_APPROVAL','APPROVED') WHERE s.official_email=? OR u.email=? OR c.id IS NOT NULL",[$email,$email,$email])->fetchAll();$out=[];
  foreach($rows as $r){if(!Authorization::allowed($p,$actor,'core.staff.manage',(int)$r['organisation_id']))continue;$u=Database::query($p,'SELECT id,email,is_active,email_verified_at,last_login_at FROM users WHERE staff_identity_id=?',[$r['staff_identity_id']])->fetch();$registry=Database::query($p,'SELECT status FROM staff_registry WHERE staff_identity_id=?',[$r['staff_identity_id']])->fetchColumn();$r['login_email']=$u['email']??null;$r['account_state']=$u?($u['is_active']?'ACCOUNT_EXISTS_ACTIVE':'ACCOUNT_EXISTS_INACTIVE'):'NO_ACCOUNT';$r['registry']=$registry?:'MISSING';$r['ready_to_resume']=!$u&&$registry==='PRE_VERIFIED'&&count($rows)===1&&Authorization::allowed($p,$actor,'core.registrations.review',(int)$r['organisation_id']);$out[]=$r;}
  return $out;
 }
 public static function resume(\PDO $p,int $actor,int $staff,string $email,string $confirmedName,string $reason):void {
  $email=Core::email($email);$confirmedName=Core::text($confirmedName);$reason=Core::text($reason,500);Accounts::throttle($p,'activation_recovery','actor:'.$actor.':staff:'.$staff);
  Core::tx($p,function()use($p,$actor,$staff,$email,$confirmedName,$reason){
   $s=Database::query($p,'SELECT * FROM staff_profiles WHERE staff_identity_id=? FOR UPDATE',[$staff])->fetch();if(!$s)throw new \RuntimeException('Rekod tidak tersedia.');$org=Core::id($s['organisation_id']);Authorization::require($p,$actor,'core.staff.manage',$org);Authorization::require($p,$actor,'core.registrations.review',$org);
   if(!Database::query($p,'SELECT 1 FROM users WHERE id=? AND is_active=1',[$actor])->fetchColumn())throw new \RuntimeException('Admin aktif diperlukan.');
   if(mb_strtolower(trim($s['full_name']))!==mb_strtolower(trim($confirmedName)))throw new \RuntimeException('Nama pengesahan tidak sepadan dengan rekod. Tiada perubahan.');
   if(Database::query($p,'SELECT 1 FROM users WHERE staff_identity_id=? FOR UPDATE',[$staff])->fetchColumn())throw new \RuntimeException('Akaun sudah wujud. Password pertama tidak boleh dikeluarkan; semak login/recovery atau account suspension secara berasingan.');
   if(Database::query($p,'SELECT status FROM staff_registry WHERE staff_identity_id=? FOR UPDATE',[$staff])->fetchColumn()!=='PRE_VERIFIED')throw new \RuntimeException('Registry mesti PRE_VERIFIED; approval identiti tidak boleh dilangkau.');
   if(Database::query($p,'SELECT 1 FROM users WHERE email=?',[$email])->fetchColumn()||Database::query($p,'SELECT 1 FROM staff_profiles WHERE official_email=? AND staff_identity_id<>?',[$email,$staff])->fetchColumn()||Database::query($p,"SELECT 1 FROM staff_email_contacts WHERE email=? AND staff_identity_id<>? AND status IN ('PENDING_ADMIN_APPROVAL','APPROVED')",[$email,$staff])->fetchColumn())throw new \RuntimeException('E-mel mempunyai identiti bercanggah. Hentikan dan semak; jangan pindahkan pautan secara automatik.');
   $contact=Database::query($p,'SELECT * FROM staff_email_contacts WHERE email=? FOR UPDATE',[$email])->fetch();
   if($contact&&(int)$contact['staff_identity_id']!==$staff)throw new \RuntimeException('E-mel dipautkan kepada Staff ID lain. Semakan manual diperlukan.');
   if($s['official_email']!==$email){if(!$contact||(int)$contact['staff_identity_id']!==$staff||!$contact['verified_at']||!in_array($contact['status'],['PENDING_ADMIN_APPROVAL','APPROVED'],true))throw new \RuntimeException('Pautan e-mel kepada Staff ID ini belum disahkan. Tiada perubahan.');
    if($contact['status']==='PENDING_ADMIN_APPROVAL'){Database::query($p,"UPDATE staff_email_contacts SET status='APPROVED',approved_by=? WHERE id=?",[$actor,$contact['id']]);Audit::record($p,'profile.email_contact_reviewed',$actor,$org,(string)$staff,'core',$reason,null,['contact_id'=>$contact['id'],'status'=>'APPROVED']);}
    Database::query($p,'UPDATE staff_profiles SET official_email=? WHERE staff_identity_id=?',[$email,$staff]);
   }
   Accounts::invite($p,$actor,$staff,$reason);Audit::record($p,'staff.unclaimed_activation_resumed',$actor,$org,(string)$staff,'core',$reason,['registry_email'=>$s['official_email']],['registry_email'=>$email,'same_staff_id'=>true]);
  });
 }
}
