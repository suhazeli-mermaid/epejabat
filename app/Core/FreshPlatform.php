<?php
declare(strict_types=1);
namespace EPejabat\Core;
use EPejabat\Modules\Training\{Migrations as TrainingMigrations,ExtendedMigrations};
final class FreshPlatform {
 public static function complete(\PDO $p,int $actor,array $org):void {
  if(!defined('FRESH_INSTALL_MIGRATION')||!FRESH_INSTALL_MIGRATION||!Authorization::super($p,$actor))throw new \RuntimeException('Fresh installation context diperlukan.');
  Upgrade::apply($p,$actor);TrainingMigrations::apply($p,$actor);ExtendedMigrations::apply($p,$actor);
  $national=Organisations::create($p,$actor,'MY','Malaysia','national');$state=Organisations::create($p,$actor,'MY-KTN','Kelantan','state',$national);$pkd=Organisations::create($p,$actor,$org[0],$org[1],'pkd',$state);
  $staff=(int)Database::query($p,'SELECT staff_identity_id FROM users WHERE id=?',[$actor])->fetchColumn();Staff::employmentChange($p,$actor,$staff,['organisation_id'=>$pkd,'effective_from'=>gmdate('Y-m-d'),'reason'=>'Initial installation placement']);
  TrainingMigrations::activate($p,$actor,true);Versions::set($p,'default_staff_role.training','training_participant');ModuleAccess::sync($p,$actor,$pkd,$actor);
  foreach(['app_version'=>'1.0.0','core_version'=>'1.0.0','module_version.core'=>'1.0.0','module_version.training'=>'1.0.0','required_php'=>'8.2','installed_at'=>gmdate('c'),'migration_version'=>'200_platform_versioning.sql'] as $k=>$v)Versions::set($p,$k,$v);
  Database::query($p,"INSERT INTO upgrade_history(run_id,module_key,target_version,status,actor_id,completed_at) VALUES(?,'platform','1.0.0','FRESH_INSTALL_VERIFIED',?,UTC_TIMESTAMP())",['install-'.bin2hex(random_bytes(16)),$actor]);
  if(!Upgrade::ready($p)||!ExtendedMigrations::ready($p))throw new \RuntimeException('Fresh migration verification gagal.');Audit::record($p,'platform.fresh_verified',$actor,$pkd,'1.0.0','core');
 }
 public static function smoke(\PDO $p):array { $rows=[];foreach(['Shared Core'=>fn()=>Upgrade::ready($p),'Training T1'=>fn()=>TrainingMigrations::ready($p),'Training T2–T6'=>fn()=>ExtendedMigrations::ready($p),'Installer lock'=>fn()=>is_file(APP_ROOT.'/storage/installed.lock'),'Version'=>fn()=>preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/',Versions::read($p)['app_version']??'')===1] as $name=>$f){try{$ok=$f();}catch(\Throwable $e){$ok=false;}$rows[]=['check'=>$name,'status'=>$ok?'PASS':'FAIL'];}return $rows; }
}
