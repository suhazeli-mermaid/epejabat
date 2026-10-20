<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Upgrade {
    public static function files(): array {
        $f=glob(APP_ROOT.'/database/upgrades/*.{sql,php}',GLOB_BRACE);
        usort($f, static function(string $a, string $b): int {
            $baseA = basename($a);
            $baseB = basename($b);
            $map = [
                '102_registration_status_policy.php' => '124_a_registration_status_policy.php',
                '101_registration_catalog_metadata.sql' => '124_b_registration_catalog_metadata.sql',
            ];
            $orderA = $map[$baseA] ?? $baseA;
            $orderB = $map[$baseB] ?? $baseB;
            return strcmp($orderA, $orderB);
        });
        return $f;
    }
    public static function plan(\PDO $p): array {
        if(PHP_VERSION_ID<80200)throw new \RuntimeException('PHP 8.2+ diperlukan.');$version=(string)$p->query('SELECT VERSION()')->fetchColumn();preg_match('/(\d+\.\d+\.\d+)/',$version,$match);$isLocal=($GLOBALS['isLocal']??false)||(defined('APP_ROOT')&&is_file(APP_ROOT.'/config/app.php')&&((require APP_ROOT.'/config/app.php')['environment']??'')==='local');$minVer=str_contains($version,'MariaDB')?($isLocal?'10.4.0':'10.6.0'):($isLocal?'5.7.0':'8.0.16');if(!isset($match[1])||version_compare($match[1],$minVer,'<'))throw new \RuntimeException('MySQL 8.0.16+ atau MariaDB 10.6+ diperlukan.');
        Migrations::verify($p);
        $required=['users'=>['id','staff_identity_id','username','email','password_hash','is_active'],'staff_profiles'=>['staff_identity_id','full_name','organisation_id','phone','job_title'],'organisations'=>['id','code','name','type','parent_id','is_active'],'roles'=>['id','code'],'permissions'=>['id','code','module_code'],'user_role_assignments'=>['id','user_id','role_id','organisation_id','include_descendants']];
        foreach($required as $table=>$columns){$actual=Database::query($p,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(\PDO::FETCH_COLUMN);if(array_diff($columns,$actual))throw new \RuntimeException('Schema Phase 0 berbeza; semak sebelum upgrade.');}
        $out=[];
        foreach(self::files() as $f){$v=basename($f);$r=Database::query($p,'SELECT * FROM database_migrations WHERE version=?',[$v])->fetch();if($r&&(!hash_equals($r['checksum'],hash_file('sha256',$f))||$r['status']!=='applied'))throw new \RuntimeException('Upgrade tidak lengkap atau checksum berbeza. Hentikan dan semak pemulihan.');$out[]=['version'=>$v,'status'=>$r?'applied':'pending'];}return $out;
    }
    public static function ready(\PDO $p): bool {foreach(self::plan($p) as $s)if($s['status']!=='applied')return false;if(Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='phase'")->fetchColumn()!=='1')return false;Crypto::key();self::verifySchema($p);return true;}
    public static function verifySchema(\PDO $p):void {
        $actual=[];foreach(Database::query($p,'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll() as $row)$actual[$row['TABLE_NAME']][$row['COLUMN_NAME']]=true;
        foreach(self::files() as $file){if(!str_ends_with($file,'.sql')&&basename($file)!=='102_registration_status_policy.php')continue;$sql=file_get_contents($file);foreach(SqlPolicy::tables($sql) as $table)if(!isset($actual[$table]))throw new \RuntimeException('Schema Phase 1 tidak lengkap. Pemulihan diperlukan.');preg_match_all('/ALTER TABLE ([a-z_]+)([^;]+);/i',$sql,$statements,PREG_SET_ORDER);foreach($statements as $statement){preg_match_all('/\bADD\s+(?:COLUMN\s+)?([a-z_]+)\s+(?:BIGINT|SMALLINT|TINYINT|VARCHAR|DATETIME|INT|TEXT|LONGTEXT|CHAR)\b/i',$statement[2],$cols);foreach($cols[1] as $column)if(!isset($actual[$statement[1]][$column]))throw new \RuntimeException('Kolum Phase 1 tidak lengkap. Pemulihan diperlukan.');}}

    }
    public static function conflicts(\PDO $p,string $sql):array {
        $out=[];
        foreach(SqlPolicy::tables($sql) as $table)if(Database::query($p,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn())$out[]='Untracked table: '.$table;
        preg_match_all('/\bALTER\s+TABLE\s+`?([a-z_][a-z0-9_]*)`?([^;]+);/i',$sql,$statements,PREG_SET_ORDER);
        foreach($statements as $statement){$table=$statement[1];if(!Database::query($p,'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchColumn()){$out[]='Missing alteration target: '.$table;continue;}preg_match_all('/\bADD\s+(?:COLUMN\s+)?`?([a-z_][a-z0-9_]*)`?\s+/i',$statement[2],$columns);foreach($columns[1] as $column){if(in_array(strtoupper($column),['CONSTRAINT','INDEX','KEY','UNIQUE','PRIMARY','CHECK','FOREIGN'],true))continue;if(Database::query($p,'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetchColumn())$out[]='Untracked column: '.$table.'.'.$column;}}
        return $out;
    }
    public static function apply(\PDO $p,?int $actor=null): void {
        if((int)Database::query($p,"SELECT GET_LOCK(CONCAT('ep_upgrade_',LEFT(SHA2(DATABASE(),256),40)),5)")->fetchColumn()!==1)throw new \RuntimeException('Upgrade lain sedang berjalan.');
        try {
            $plan=self::plan($p);if(!is_file(APP_ROOT.'/config/core.key')){foreach($plan as $item)if($item['status']==='applied')throw new \RuntimeException('Kunci hilang. Pulihkan core.key daripada backup; jangan jana kunci baharu.');}
            if(Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn()!=='complete')throw new \RuntimeException('Selesaikan pemasangan Phase 0 dahulu.');
            foreach(['openssl','fileinfo'] as $x)if(!extension_loaded($x))throw new \RuntimeException('Extension diperlukan: '.$x);
            foreach(['documents','imports'] as $dir){$path=APP_ROOT.'/storage/'.$dir;if(!is_dir($path)&&!mkdir($path,0700,true))throw new \RuntimeException('Storan private tidak boleh disediakan.');if(!is_writable($path))throw new \RuntimeException('Storan private tidak boleh ditulis.');}
            Crypto::initialize();
            foreach(self::files() as $f){$v=basename($f);if(Database::query($p,'SELECT 1 FROM database_migrations WHERE version=?',[$v])->fetchColumn())continue;
                if(str_ends_with($f,'.sql')&&self::conflicts($p,file_get_contents($f)))throw new \RuntimeException('Schema conflicts detected before migration; reconciliation required.');
                Database::query($p,"INSERT INTO database_migrations(version,checksum,status) VALUES (?,?,'started')",[$v,hash_file('sha256',$f)]);
                if(str_ends_with($f,'.sql'))$p->exec(file_get_contents($f));else ($fn=require $f)($p);
                Database::query($p,"UPDATE database_migrations SET status='applied',applied_at=UTC_TIMESTAMP() WHERE version=?",[$v]);
            }
            self::verifySchema($p);Core::tx($p,function()use($p,$actor){$before=Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='phase' FOR UPDATE")->fetchColumn();Database::query($p,"INSERT INTO system_settings(setting_key,setting_value) VALUES('phase','1') ON DUPLICATE KEY UPDATE setting_value='1'");Audit::record($p,'system.upgrade_verified',$actor,null,'phase1','core',$actor===null?'Authorized CLI/service upgrade':'Authenticated browser upgrade',['phase'=>$before],['phase'=>'1']);});
        } finally {Database::query($p,"SELECT RELEASE_LOCK(CONCAT('ep_upgrade_',LEFT(SHA2(DATABASE(),256),40)))");}
    }
}
