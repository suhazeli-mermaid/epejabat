<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
use EPejabat\Core\{Database,Authorization,Core};
final class Policy {
 public static function actor(\PDO $p,int $u):array {
  $r=Database::query($p,'SELECT u.id,u.staff_identity_id,s.* FROM users u JOIN staff_profiles s ON s.staff_identity_id=u.staff_identity_id JOIN organisations o ON o.id=s.organisation_id WHERE u.id=? AND u.is_active=1 AND o.is_active=1',[$u])->fetch();
  if(!$r){if(Authorization::super($p,$u))return Database::query($p,'SELECT u.id,u.staff_identity_id,s.* FROM users u JOIN staff_profiles s ON s.staff_identity_id=u.staff_identity_id WHERE u.id=? AND u.is_active=1',[$u])->fetch();throw new \RuntimeException('Akaun atau organisasi tidak aktif.');}return $r;
 }
 public static function programme(\PDO $p,int $id,bool $lock=false):array {
  Core::module($p,'training');if(!Migrations::ready($p))throw new \RuntimeException('Training belum bersedia.');
  $r=Database::query($p,'SELECT t.*,o.is_active AS owner_active FROM training_programmes t JOIN organisations o ON o.id=t.owner_organisation_id WHERE t.id=?'.($lock?' FOR UPDATE':''),[$id])->fetch();if(!$r||!$r['owner_active'])throw new \RuntimeException('Program tidak tersedia.');return $r;
 }
 public static function allowed(\PDO $p,int $u,string $action,array $r):bool {
  self::actor($p,$u);$perm='training.'.$action;
  if(Authorization::super($p,$u)||Authorization::allowed($p,$u,$perm,(int)$r['owner_organisation_id']))return true;
  return (bool)Database::query($p,"SELECT 1 FROM training_access_assignments a JOIN roles r ON r.id=a.role_id JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions pp ON pp.id=rp.permission_id WHERE a.programme_id=? AND a.user_id=? AND a.revoked_at IS NULL AND a.expires_at>UTC_TIMESTAMP() AND r.is_active=1 AND r.module_code='training' AND pp.module_code='training' AND pp.code=?",[$r['id'],$u,$perm])->fetchColumn();
 }
 public static function require(\PDO $p,int $u,string $action,array $r):void {if(!self::allowed($p,$u,$action,$r)){if(PHP_SAPI!=='cli')http_response_code(403);throw new \RuntimeException('Akses program tidak dibenarkan.');}}
 public static function create(\PDO $p,int $u,int $org):void {Core::module($p,'training');self::actor($p,$u);if(!Database::query($p,'SELECT 1 FROM organisations WHERE id=? AND is_active=1',[$org])->fetchColumn()||!Authorization::super($p,$u)&&!Authorization::allowed($p,$u,'training.programmes.create',$org))throw new \RuntimeException('Scope penciptaan tidak dibenarkan.');}
 public static function config(\PDO $p,array $r,bool $published=false):array {
  $id=$published?$r['published_revision_id']:$r['current_revision_id'];if(!$id)throw new \RuntimeException('Versi program belum tersedia.');$json=Database::query($p,'SELECT config_json FROM training_revisions WHERE id=? AND programme_id=?',[$id,$r['id']])->fetchColumn();return json_decode($json,true,512,JSON_THROW_ON_ERROR);
 }
 public static function published(\PDO $p,int $id,bool $lock=false):array {Core::module($p,'training');$r=self::programme($p,$id,$lock);if(!$r['is_published']||$r['is_cancelled'])throw new \RuntimeException('Pendaftaran program tidak tersedia.');return $r;}
}
