<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Migrations {
    public static function tables(): array { return ['database_migrations','organisations','organisation_hierarchy','staff_identities','staff_profiles','users','roles','permissions','role_permissions','user_role_assignments','audit_logs','system_settings','login_attempts']; }
    public static function runFresh(\PDO $pdo,string $instance): void {
        if($pdo->query('SHOW TABLES')->fetchAll()) throw new \RuntimeException('Database mesti kosong. Pemasangan dihentikan untuk melindungi data.');
        $files=glob(APP_ROOT.'/database/migrations/*.sql'); sort($files);
        foreach($files as $i=>$file) {
            $version=basename($file,'.sql'); $hash=hash_file('sha256',$file);
            if($i===0) $pdo->exec(file_get_contents($file));
            Database::query($pdo,'INSERT INTO database_migrations (version,checksum,status) VALUES (?,?,?)',[$version,$hash,'started']);
            if($i!==0) $pdo->exec(file_get_contents($file));
            Database::query($pdo,"UPDATE database_migrations SET status='applied',applied_at=UTC_TIMESTAMP() WHERE version=?",[$version]);
        }
        $pdo->beginTransaction(); try {
            foreach(['app_name'=>'epejabat.net','phase'=>'0','timezone'=>'Asia/Kuala_Lumpur','installation_instance'=>$instance,'installation_status'=>'pending_admin'] as $k=>$v) Database::query($pdo,'INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)',[$k,$v]);
            Database::query($pdo,"INSERT INTO roles (code,name,is_system) VALUES ('super_admin','Super Administrator',1)"); $role=(int)$pdo->lastInsertId();
            foreach(['core.dashboard.view'=>'Lihat dashboard','core.organisations.manage'=>'Urus organisasi','core.users.manage'=>'Urus pengguna','core.audit.view'=>'Lihat audit','core.settings.manage'=>'Urus tetapan','core.migrations.manage'=>'Urus migration'] as $code=>$name) {
                Database::query($pdo,'INSERT INTO permissions (code,module_code,name) VALUES (?,?,?)',[$code,'core',$name]); $id=(int)$pdo->lastInsertId(); Database::query($pdo,'INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)',[$role,$id]);
            }
            Audit::record($pdo,'installation.schema_created'); $pdo->commit();
        } catch(\Throwable $e) { $pdo->rollBack();throw $e; }
    }
    public static function verify(\PDO $pdo): void {
        foreach(self::tables() as $table) { if(!Database::query($pdo,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn()) throw new \RuntimeException('Jadual asas tidak lengkap. Pemulihan diperlukan.'); }
        foreach(glob(APP_ROOT.'/database/migrations/*.sql') as $file) {
            $row=Database::query($pdo,'SELECT checksum,status FROM database_migrations WHERE version=?',[basename($file,'.sql')])->fetch();
            if(!$row || $row['status']!=='applied' || !hash_equals($row['checksum'],hash_file('sha256',$file))) throw new \RuntimeException('Migration tidak lengkap atau checksum berbeza.');
        }
    }
}
