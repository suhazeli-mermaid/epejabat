<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
use EPejabat\Core\{Database,Upgrade,Core,Audit,Authorization};
final class Migrations {
 public static function files():array {$f=glob(APP_ROOT.'/database/modules/training/*.{sql,php}',GLOB_BRACE);sort($f);return $f;}
 public static function plan(\PDO $p):array {
  if(!Upgrade::ready($p)||(!is_file(APP_ROOT.'/storage/installed.lock')&&!(defined('FRESH_INSTALL_MIGRATION')&&FRESH_INSTALL_MIGRATION)))throw new \RuntimeException('Shared Core Phase 1 mesti lengkap dan installer dikunci.');$out=[];
  foreach(self::files() as $f){$v='training:T1:'.basename($f);$r=Database::query($p,'SELECT * FROM database_migrations WHERE version=?',[$v])->fetch();if($r&&($r['status']!=='applied'||!hash_equals($r['checksum'],hash_file('sha256',$f))))throw new \RuntimeException('Migration Training tidak lengkap/checksum berbeza; pemulihan manual diperlukan.');$out[]=['version'=>$v,'status'=>$r?'applied':'pending'];}return $out;
 }
 public static function ready(\PDO $p):bool {
  try{foreach(self::plan($p) as $s)if($s['status']!=='applied')return false;foreach(['training_programmes','training_revisions','training_approvals','training_access_assignments','training_registrations','training_registration_history','training_participation_roles','training_participation_assignments','training_invitations','training_links','training_notification_events','training_programme_documents'] as $t)if(!Database::query($p,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t])->fetchColumn())return false;return Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='training_t1_version'")->fetchColumn()==='1.0.0';}catch(\Throwable $e){return false;}
 }
 public static function apply(\PDO $p,int $actor):void {
  if(!Authorization::super($p,$actor))throw new \RuntimeException('Super Admin diperlukan.');if((int)Database::query($p,"SELECT GET_LOCK(CONCAT('ep_t1_',LEFT(SHA2(DATABASE(),256),40)),5)")->fetchColumn()!==1)throw new \RuntimeException('Migration sedang berjalan.');
  try{self::plan($p);foreach(self::files() as $f){$v='training:T1:'.basename($f);if(Database::query($p,'SELECT 1 FROM database_migrations WHERE version=?',[$v])->fetchColumn())continue;Database::query($p,"INSERT INTO database_migrations(version,checksum,status) VALUES(?,?,'started')",[$v,hash_file('sha256',$f)]);if(str_ends_with($f,'.sql'))$p->exec(file_get_contents($f));else($fn=require $f)($p);Database::query($p,"UPDATE database_migrations SET status='applied',applied_at=UTC_TIMESTAMP() WHERE version=?",[$v]);}Core::tx($p,function()use($p,$actor){Database::query($p,"INSERT INTO system_settings(setting_key,setting_value) VALUES('training_t1_version','1.0.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");Audit::record($p,'training.migrated',$actor,null,'T1','training','Backup-confirmed upgrade');});if(!self::ready($p))throw new \RuntimeException('Pengesahan modul gagal.');}finally{Database::query($p,"SELECT RELEASE_LOCK(CONCAT('ep_t1_',LEFT(SHA2(DATABASE(),256),40)))");}
 }
 public static function activate(\PDO $p,int $actor,bool $active):void {if(!Authorization::super($p,$actor)||$active&&!self::ready($p))throw new \RuntimeException('Modul belum bersedia atau akses ditolak.');Core::tx($p,function()use($p,$actor,$active){Database::query($p,"UPDATE module_registry SET status=?,navigation_path='/training' WHERE module_key='training'",[$active?'active':'inactive']);Audit::record($p,'training.activation_changed',$actor,null,'training','training',null,null,['active'=>$active]);});}
}
