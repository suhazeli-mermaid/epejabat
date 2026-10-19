<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class ModuleAccess {
 // Default roles grant self-service only, never programme/admin access.
 public static function sync(\PDO $p,int $user,int $org,?int $actor=null):void {
  $rows=Database::query($p,"SELECT m.module_key,r.id,r.code FROM module_registry m JOIN system_settings s ON s.setting_key=CONCAT('default_staff_role.',m.module_key) JOIN roles r ON r.code=s.setting_value AND r.module_code=m.module_key AND r.is_active=1 WHERE m.status='active'")->fetchAll();
  foreach($rows as $r){$perms=Database::query($p,'SELECT pp.code FROM role_permissions rp JOIN permissions pp ON pp.id=rp.permission_id WHERE rp.role_id=?',[$r['id']])->fetchAll(\PDO::FETCH_COLUMN);foreach($perms as $code)if(!in_array(substr($code,strlen($r['module_key'])+1),['dashboard.view','registrations.self','documents.view'],true))throw new \RuntimeException('Default role has non-self-service permissions.');$before=Database::query($p,'SELECT * FROM user_role_assignments WHERE user_id=? AND role_id=?',[$user,$r['id']])->fetchAll();if(count($before)===1&&(int)$before[0]['organisation_id']===$org)continue;Database::query($p,'DELETE FROM user_role_assignments WHERE user_id=? AND role_id=?',[$user,$r['id']]);Database::query($p,'INSERT INTO user_role_assignments(user_id,role_id,organisation_id,module_code) VALUES(?,?,?,?)',[$user,$r['id'],$org,$r['module_key']]);Database::query($p,'INSERT INTO role_assignment_history(user_id,actor_id,action,assignment_json,reason) VALUES(?,?,?,?,?)',[$user,$actor,'DEFAULT_SELF_SERVICE',json_encode(['role_id'=>$r['id'],'organisation_id'=>$org],JSON_THROW_ON_ERROR),'Active module self-service enrolment']);Audit::record($p,'module.self_service_assigned',$actor,$org,(string)$user,$r['module_key'],null,['assignments'=>$before],['role'=>$r['code'],'organisation'=>$org]);}
 }
}
