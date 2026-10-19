<?php
declare(strict_types=1);
namespace EPejabat\Core;

/** Metadata only. Never export file contents, configuration, staff or token values. */
final class ProductionBaseline {
    private static function gate(\PDO $p,int $actor):void {
        if (!Authorization::super($p,$actor)) throw new \RuntimeException('Super Admin diperlukan.');
        InstallationKey::requireAuthorization($p,$actor);
    }
    public static function inventory(string $root):array {
        if (UpgradePackage::symlink($root) || realpath($root)!==$root) throw new \RuntimeException('Baseline root tidak selamat.');
        $paths=['index.php','bootstrap.php','.htaccess','database/release.json'];if(is_file($root.'/database/eforms-manifest.json')||is_link($root.'/database/eforms-manifest.json'))$paths[]='database/eforms-manifest.json';
        foreach (['app','database/migrations','database/upgrades','database/modules','database/reference','assets','bin'] as $dir) {
            $full=$root.'/'.$dir;if($dir==='database/reference'&&!file_exists($full))continue;
            if (UpgradePackage::symlink($full) || !is_dir($full)) throw new \RuntimeException('Baseline directory tidak selamat.');
            $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full,\FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $item) {
                if ($item->isLink()) throw new \RuntimeException('Baseline symlink ditolak.');
                $rel=substr($item->getPathname(),strlen($root)+1);
                if ($item->isFile() && preg_match('/\.(php|sql|json|css|js|mjs|png|jpe?g|svg|webp|woff2)\z/i',$rel)) $paths[]=$rel;
            }
        }
        $paths=array_unique($paths); sort($paths); if(count($paths)>2000) throw new \RuntimeException('Inventori terlalu besar.');
        $out=[];
        foreach($paths as $rel) {
            $f=$root.'/'.$rel;
            if(UpgradePackage::symlink($f)||!is_file($f)||!is_readable($f)||filesize($f)>16777216) throw new \RuntimeException('Baseline file tidak boleh dibaca.');
            $hash=hash_file('sha256',$f); if(!is_string($hash)) throw new \RuntimeException('Baseline hash gagal.');
            $out[$rel]=$hash;
        }
        return $out;
    }
    private static function directory():string {
        $d=APP_ROOT.'/storage/upgrades/baselines';
        if(UpgradePackage::symlink($d)) throw new \RuntimeException('Private baseline path tidak selamat.');
        if(!is_dir($d)&&!mkdir($d,0700,true))throw new \RuntimeException('Private baseline storage gagal.');
        return $d;
    }
    private static function signer():string {
        $f=self::directory().'/site-signing.pem';
        if(UpgradePackage::symlink($f))throw new \RuntimeException('Baseline signer tidak selamat.');
        if(!is_file($f)) {
            $key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
            if(!$key||!openssl_pkey_export($key,$pem))throw new \RuntimeException('Baseline signer gagal.');
            $h=fopen($f,'x');if(!$h)throw new \RuntimeException('Signer race; cuba semula.');
            try {chmod($f,0600);if(fwrite($h,$pem)!==strlen($pem)||!fflush($h))throw new \RuntimeException('Signer write gagal.');}finally{fclose($h);}
        }
        $pem=file_get_contents($f);if(!is_string($pem))throw new \RuntimeException('Signer tidak tersedia.');return $pem;
    }
    private static function stableLocks():void {
        foreach(['maintenance.lock','blocked.lock'] as $name){$f=APP_ROOT.'/storage/upgrades/'.$name;if(UpgradePackage::symlink($f)||file_exists($f))throw new \RuntimeException('Baseline capture ditolak semasa upgrade/recovery.');}
        if(UpgradePackage::symlink(APP_ROOT.'/storage/installed.lock')||!is_file(APP_ROOT.'/storage/installed.lock'))throw new \RuntimeException('Installation lock diperlukan.');
    }
    public static function capture(\PDO $p,int $actor):array {
        self::gate($p,$actor); AccessProtection::assert(Accounts::origin());self::stableLocks();
        if($p->inTransaction())throw new \RuntimeException('Baseline memerlukan transaction sendiri.');
        $d=self::directory();$f=$d.'/capture.lock';if(UpgradePackage::symlink($f))throw new \RuntimeException('Baseline lock tidak selamat.');
        $lock=fopen($f,'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Capture sedang berjalan.');chmod($f,0600);
        try {
            $before=self::inventory(APP_ROOT);$p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
            try {
                $versions=Versions::read($p);
                $ledger=Database::query($p,'SELECT version,checksum,status,applied_at FROM database_migrations ORDER BY version')->fetchAll();
                $modules=Database::query($p,'SELECT module_key,status,permission_namespace FROM module_registry ORDER BY module_key')->fetchAll();
                $history=Database::query($p,'SELECT module_key,previous_version,target_version,status,completed_at FROM upgrade_history ORDER BY id DESC LIMIT 50')->fetchAll();
                $status=Database::query($p,"SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('installation_status','last_upgrade_at','last_upgrade_status')")->fetchAll(\PDO::FETCH_KEY_PAIR);
                $schema=Database::query($p,'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll();
                $server=(string)$p->getAttribute(\PDO::ATTR_SERVER_VERSION);
                $p->commit();
            } catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
            self::stableLocks();if($before!==self::inventory(APP_ROOT))throw new \RuntimeException('Source berubah semasa capture.');
            foreach($ledger as $row)if($row['status']!=='applied'||!preg_match('/\A[a-f0-9]{64}\z/',$row['checksum']))throw new \RuntimeException('Migration ledger tidak stabil.');
            $trust=LiveUpgradeManager::trustPolicy();$fingerprints=[];
            foreach($trust['publishers'] as $id=>$key)$fingerprints[$id]=hash('sha256',$key);
            $metadata=['format'=>'epejabat-production-baseline-v1','id'=>bin2hex(random_bytes(16)),'captured_at'=>gmdate('c'),'origin'=>Accounts::origin(),'provenance'=>'Authenticated server-side metadata capture','acceptance'=>'NOT VERIFIED','versions'=>$versions,'files'=>$before,'schema'=>$schema,'migration_ledger'=>$ledger,'module_registry'=>$modules,'release_history'=>$history,'upgrade_status'=>$status,'compatibility'=>['php'=>PHP_VERSION,'database'=>$server,'publisher_fingerprints'=>$fingerprints,'shared_core_allowlist'=>$trust['shared_core_allowlist']],'locks'=>['installed'=>is_file(APP_ROOT.'/storage/installed.lock'),'maintenance'=>is_file(APP_ROOT.'/storage/upgrades/maintenance.lock'),'recovery'=>is_file(APP_ROOT.'/storage/upgrades/blocked.lock')]];
            $metadata['verification']=self::metadataChecks($metadata);
            $raw=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$key=openssl_pkey_get_private(self::signer());$public=openssl_pkey_get_details($key)['key'];
            if(!openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256))throw new \RuntimeException('Baseline signature gagal.');
            $envelope=['format'=>'epejabat-signed-baseline-v1','payload'=>$raw,'sha256'=>hash('sha256',$raw),'signature'=>base64_encode($signature),'public_key'=>$public,'key_fingerprint'=>hash('sha256',$public),'trust'=>'Pin fingerprint through approved authenticated channel; self-signature alone does not establish trust'];
            Support::secureWrite($d.'/'.$metadata['id'].'.json',json_encode($envelope,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            Support::secureWrite($d.'/latest.json',json_encode(['id'=>$metadata['id']],JSON_THROW_ON_ERROR));
            Audit::record($p,'baseline.captured',$actor,null,$metadata['id'],'core',null,null,['sha256'=>$envelope['sha256']]);return $envelope;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function latest(\PDO $p,int $actor):array {
        self::gate($p,$actor);$d=self::directory();$latest=$d.'/latest.json';
        if(UpgradePackage::symlink($latest)||!is_file($latest))throw new \RuntimeException('Baseline belum dijana.');
        $id=json_decode(file_get_contents($latest),true,512,JSON_THROW_ON_ERROR)['id']??'';
        if(!preg_match('/\A[a-f0-9]{32}\z/',$id)||UpgradePackage::symlink($d.'/'.$id.'.json'))throw new \RuntimeException('Baseline reference tidak sah.');
        $e=json_decode(file_get_contents($d.'/'.$id.'.json'),true,512,JSON_THROW_ON_ERROR);
        $keyfile=$d.'/site-signing.pem';if(UpgradePackage::symlink($keyfile)||!is_file($keyfile))throw new \RuntimeException('Baseline signing key hilang; pulihkan key, jangan jana semula.');$private=openssl_pkey_get_private(file_get_contents($keyfile));if(!$private)throw new \RuntimeException('Baseline signing key tidak sah.');$public=openssl_pkey_get_details($private)['key'];self::verify($e,hash('sha256',$public));return $e;
    }
    public static function metadataChecks(array $baseline):array {
        $checks=[];foreach(['app_version','core_version','module_version.training'] as $key)$checks[]=['check'=>'Version '.$key,'status'=>preg_match('/\A\d+\.\d+\.\d+\z/',(string)($baseline['versions'][$key]??''))?'PASS':'FAIL'];
        foreach($baseline['migration_ledger']??[] as $row){$version=(string)$row['version'];$path=null;if(preg_match('/\Atraining:(T1|FULL):([a-zA-Z0-9_.-]+)\z/',$version,$m))$path='database/modules/'.($m[1]==='T1'?'training':'training_complete').'/'.$m[2];elseif(preg_match('/\Aeforms:Phase2:([0-9]+_[A-Za-z_]+\.(sql|php))\z/',$version,$m))$path='database/modules/eforms/'.$m[1];elseif(preg_match('/\A[a-zA-Z0-9_-]+\z/',$version))$path='database/migrations/'.$version.'.sql';elseif(preg_match('/\A[a-zA-Z0-9_-]+\.(sql|php)\z/',$version))$path='database/upgrades/'.$version;
            $actual=$path===null?null:($baseline['files'][$path]??null);$checks[]=['check'=>'Migration '.$version,'status'=>$actual!==null&&$row['status']==='applied'&&hash_equals((string)$row['checksum'],$actual)?'PASS':'FAIL','reason'=>$actual===null?'MIGRATION_SOURCE_NOT_MAPPED':($actual===$row['checksum']?'MATCH':'CHECKSUM_MISMATCH')];}
        if(empty($baseline['migration_ledger']))$checks[]=['check'=>'Migration ledger present','status'=>'FAIL'];
        $checks[]=['check'=>'Trusted publisher configured','status'=>!empty($baseline['compatibility']['publisher_fingerprints'])?'PASS':'FAIL'];
        return ['decision'=>in_array('FAIL',array_column($checks,'status'),true)?'BLOCKED':'METADATA VERIFIED','checks'=>$checks,'deployment_acceptance'=>'NOT VERIFIED','scope'=>'Source metadata consistency only; signed package preflight remains mandatory'];
    }
    public static function browserVerify(\PDO $p,int $actor):array {
        $envelope=self::capture($p,$actor);$latest=self::latest($p,$actor);$metadata=self::verify($latest,$latest['key_fingerprint']);return ['captured_at'=>$metadata['captured_at'],'versions'=>$metadata['versions'],'verification'=>$metadata['verification'],'key_fingerprint'=>$latest['key_fingerprint']];
    }
    public static function verify(array $e,string $trustedFingerprint):array {
        if(($e['format']??'')!=='epejabat-signed-baseline-v1'||!is_string($e['public_key']??null)||!hash_equals($trustedFingerprint,hash('sha256',$e['public_key']))||!is_string($e['payload']??null)||strlen($e['payload'])>2097152||!hash_equals((string)($e['sha256']??''),hash('sha256',$e['payload'])))throw new \RuntimeException('Baseline integrity/trust gagal.');
        $sig=base64_decode($e['signature']??'',true);
        if(!$sig||openssl_verify($e['payload'],$sig,$e['public_key'],OPENSSL_ALGO_SHA256)!==1)throw new \RuntimeException('Baseline signature gagal.');
        return json_decode($e['payload'],true,512,JSON_THROW_ON_ERROR);
    }
    public static function reconcile(array $baseline,array $manifest,int $maxAge=86400):array {
        $at=strtotime($baseline['captured_at']??'');$checks=[];
        $checks[]=['check'=>'Fresh baseline','status'=>$at!==false&&$at<=time()+60&&time()-$at<=$maxAge?'PASS':'FAIL'];
        foreach($manifest['required_versions']??[] as $key=>$expected)$checks[]=['check'=>'Version '.$key,'status'=>($baseline['versions'][$key]??null)===$expected?'PASS':'FAIL'];
        foreach($manifest['files']??[] as $row){$rel=substr($row['file'],5);$actual=$baseline['files'][$rel]??null;$checks[]=['check'=>$rel,'status'=>$actual===$row['before_sha256']?'PASS':'FAIL','reason'=>$actual===$row['before_sha256']?'MATCH':'BASELINE_MISMATCH'];}
        $ledger=array_column($baseline['migration_ledger']??[],null,'version');foreach($manifest['required_ledger']??[] as $version=>$hash)$checks[]=['check'=>'Ledger '.$version,'status'=>($ledger[$version]['checksum']??null)===$hash&&($ledger[$version]['status']??null)==='applied'?'PASS':'FAIL'];
        return ['decision'=>in_array('FAIL',array_column($checks,'status'),true)?'BLOCKED':'BASELINE MATCH','checks'=>$checks];
    }
}
