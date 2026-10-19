<?php
declare(strict_types=1);
return static function(PDO $p):void {
 $q=fn($sql,$a=[])=>\EPejabat\Core\Database::query($p,$sql,$a);
 $q("INSERT INTO module_registry(module_key,name,status,navigation_path,permission_namespace) VALUES('eforms','e-Forms & Digital Office','active','/eforms','eforms') ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status),navigation_path=VALUES(navigation_path)");
 foreach(['templates.manage','submissions.create','submissions.review','submissions.manage','documents.upload','documents.view','documents.manage'] as $action)$q('INSERT IGNORE INTO permissions(code,module_code,name) VALUES(?,?,?)',['eforms.'.$action,'eforms','e-Forms '.$action]);
 foreach(['eforms_staff'=>['submissions.create','documents.upload','documents.view'],'eforms_reviewer'=>['submissions.create','submissions.review','documents.upload','documents.view'],'eforms_admin'=>['templates.manage','submissions.create','submissions.review','submissions.manage','documents.upload','documents.view','documents.manage']] as $role=>$actions){$q('INSERT IGNORE INTO roles(code,name,module_code,is_system,is_active) VALUES(?,?,?,1,1)',[$role,ucwords(str_replace('_',' ',$role)),'eforms']);$rid=$q('SELECT id FROM roles WHERE code=?',[$role])->fetchColumn();foreach($actions as $a)$q('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?',[$rid,'eforms.'.$a]);}
 $q("INSERT IGNORE INTO notification_templates(template_key,subject,body) VALUES('eforms_status','e-Forms — Makluman status','Permohonan {{submission}}: {{status}}. Semak portal: {{url}}')");
 $q("INSERT INTO system_settings(setting_key,setting_value) VALUES('module_version.eforms','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
};
