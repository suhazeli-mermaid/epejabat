<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Authorization {
    public static function assignments(\PDO $p,int $u,string $permission): array {
        // Base-only mode is needed to authorize the upgrade before new columns exist.
        $phase=Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='phase'")->fetchColumn();
        $extra=$phase==='1'?' AND r.is_active=1 AND (a.organisation_id IS NULL OR EXISTS(SELECT 1 FROM organisations oo WHERE oo.id=a.organisation_id AND oo.is_active=1)) AND a.module_code=r.module_code AND r.module_code=p.module_code AND LEFT(p.code,CHAR_LENGTH(p.module_code)+1)=CONCAT(p.module_code,\'.\')':'';
        return Database::query($p,'SELECT a.organisation_id,a.include_descendants,r.code FROM user_role_assignments a JOIN roles r ON r.id=a.role_id JOIN role_permissions rp ON rp.role_id=a.role_id JOIN permissions p ON p.id=rp.permission_id JOIN users u ON u.id=a.user_id WHERE a.user_id=? AND p.code=? AND u.is_active=1'.$extra,[$u,$permission])->fetchAll();
    }
    public static function allowed(\PDO $p,int $u,string $permission,?int $organisation=null): bool {
        foreach(self::assignments($p,$u,$permission) as $a){if($a['organisation_id']===null&&$a['code']==='super_admin')return true;if($organisation!==null&&(int)$a['organisation_id']===$organisation)return true;if($organisation!==null&&$a['include_descendants']&&Database::query($p,'SELECT 1 FROM organisation_hierarchy WHERE ancestor_id=? AND descendant_id=?',[$a['organisation_id'],$organisation])->fetchColumn())return true;}return false;
    }
    public static function any(\PDO $p,int $u,string $permission): bool {return (bool)self::assignments($p,$u,$permission);}
    public static function super(\PDO $p,int $u): bool {return self::allowed($p,$u,'core.migrations.manage');}
    public static function require(\PDO $p,int $u,string $permission,?int $organisation=null): void {if(!self::allowed($p,$u,$permission,$organisation)){if(PHP_SAPI!=='cli')http_response_code(403);throw new \RuntimeException('Akses tidak dibenarkan.');}}
    public static function scope(\PDO $p,int $u,string $permission,string $column): array {
        if(!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.]*\z/',$column))throw new \LogicException('Scope column');
        $sql=[];$args=[];foreach(self::assignments($p,$u,$permission) as $a){if($a['organisation_id']===null){if($a['code']==='super_admin')return ['1=1',[]];continue;}if($a['include_descendants']){$sql[]=$column.' IN (SELECT descendant_id FROM organisation_hierarchy WHERE ancestor_id=?)';}else{$sql[]=$column.'=?';}$args[]=(int)$a['organisation_id'];}return [$sql?'('.implode(' OR ',$sql).')':'1=0',$args];
    }
}
