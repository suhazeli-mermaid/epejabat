<?php
return static function(PDO $p):void {
 \EPejabat\Core\Core::tx($p,static function()use($p){
 $actions=['dashboard.view','programmes.view','programmes.create','programmes.edit','programmes.submit','programmes.publish','approvals.review','participants.view','participants.assign','participants.manage','registrations.self','registrations.walkin','roles.manage','invitations.manage','links.manage','documents.view','documents.upload','documents.manage'];
 foreach($actions as $a)\EPejabat\Core\Database::query($p,'INSERT INTO permissions(code,module_code,name) VALUES(?,?,?)',['training.'.$a,'training','Training '.$a]);
 $roles=['training_pkd_admin'=>$actions,'training_clinic_admin'=>array_diff($actions,['approvals.review']),'training_unit_admin'=>array_diff($actions,['approvals.review']),'training_programme_admin'=>array_diff($actions,['programmes.create','approvals.review','roles.manage']),'training_approver'=>['dashboard.view','programmes.view','approvals.review'],'training_participant'=>['dashboard.view','registrations.self','documents.view']];
 foreach($roles as $code=>$list){\EPejabat\Core\Database::query($p,'INSERT INTO roles(code,name,module_code,is_system) VALUES(?,?,?,1)',[$code,ucwords(str_replace('_',' ',$code)),'training']);$rid=(int)$p->lastInsertId();foreach($list as $a)\EPejabat\Core\Database::query($p,'INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?',[$rid,'training.'.$a]);}
 $p->exec("INSERT INTO user_role_assignments(user_id,role_id,organisation_id,module_code) SELECT u.id,r.id,s.organisation_id,'training' FROM users u JOIN staff_profiles s ON s.staff_identity_id=u.staff_identity_id CROSS JOIN roles r WHERE u.is_active=1 AND s.organisation_id IS NOT NULL AND r.code='training_participant'");
 foreach(['training_event'=>['Makluman Program epejabat','{{message}}'],'training_invitation'=>['Jemputan Program epejabat',"{{message}}\n{{url}}"]] as $key=>$t)\EPejabat\Core\Database::query($p,'INSERT INTO notification_templates(template_key,subject,body) VALUES(?,?,?)',[$key,...$t]);
 });
};
