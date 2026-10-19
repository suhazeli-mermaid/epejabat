<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Backup {
 private const LIMIT=536870912;
 private static function key():string {if(UpgradePackage::symlink(APP_ROOT.'/config/core.key'))throw new \RuntimeException('Backup signing key path tidak selamat.');return Crypto::key();}
 private static function quote(string $name):string {return '`'.str_replace('`','``',$name).'`';}
 /** Conservative proof of schema-wide visibility; never infer absence from a filtered inventory. */
 public static function permissionCoverage(string $db,array $grants):array {
  $schema=[];$global=[];$restricted=[];
  foreach($grants as $grant){
   if(!is_string($grant))throw new \RuntimeException('Grant metadata tidak sah.');
   if(!preg_match('/\A(GRANT|REVOKE) (.+?) ON (\*\.\*|`(?:[^`]|``)+`\.\*) (?:TO|FROM) /i',$grant,$m))continue;
   $scope=$m[3];$isGlobal=$scope==='*.*';
   if(!$isGlobal){$name=str_replace('``','`',substr($scope,1,-3));$name=preg_replace('/\\\\([_%\\\\])/','$1',$name);if($name!==$db)continue;}
   $names=array_map('trim',explode(',',strtoupper($m[2])));
   if(in_array('ALL PRIVILEGES',$names,true))$names=['SELECT','TRIGGER','EVENT','EXECUTE','CREATE ROUTINE','ALTER ROUTINE'];
   foreach($names as $priv){if(!preg_match('/\A[A-Z_]+(?: [A-Z_]+)*\z/',$priv))continue;if(strtoupper($m[1])==='REVOKE')$restricted[$priv]=true;elseif($isGlobal)$global[$priv]=true;else $schema[$priv]=true;}
  }
  $has=static fn(string $name):bool=>!isset($restricted[$name])&&(isset($schema[$name])||isset($global[$name]));
  $checks=['SELECT'=>$has('SELECT'),'TRIGGER'=>$has('TRIGGER'),'EVENT'=>$has('EVENT'),'ROUTINE_VISIBILITY'=>$has('EXECUTE')||$has('CREATE ROUTINE')||$has('ALTER ROUTINE')||(!isset($restricted['SELECT'])&&isset($global['SELECT']))||isset($global['SHOW_ROUTINE'])];
  $missing=[];foreach($checks as $name=>$ok)if(!$ok)$missing[]=$name==='ROUTINE_VISIBILITY'?'EXECUTE (atau CREATE ROUTINE / ALTER ROUTINE / SHOW_ROUTINE sedia ada)':$name;
  return ['complete'=>!in_array(false,$checks,true),'checks'=>$checks,'missing'=>$missing,'scope'=>'Current database; schema-wide inventory required. Object counts are trusted only after visibility is proven.'];
 }
 private static function permissions(\PDO $p):array {
  $db=(string)$p->query('SELECT DATABASE()')->fetchColumn();if($db==='')throw new \RuntimeException('Database tidak dipilih.');
  $grants=$p->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN);
  $role=(string)$p->query('SELECT CURRENT_ROLE()')->fetchColumn();
  if($role!==''&&strtoupper($role)!=='NONE'){
   // CURRENT_ROLE() is server-produced; still validate every identifier before USING.
   if(!preg_match('/\A`(?:[^`]|``)+`@`(?:[^`]|``)+`(?:,\s*`(?:[^`]|``)+`@`(?:[^`]|``)+`)*\z/',$role))throw new \RuntimeException('Visibility role tidak dapat disahkan; DBA perlu semak active role.');
   $grants=$p->query('SHOW GRANTS FOR CURRENT_USER() USING '.$role)->fetchAll(\PDO::FETCH_COLUMN);
  }
  return self::permissionCoverage($db,$grants);
 }
 public static function permissionReport(\PDO $p,int $actor):array {
  if(!Authorization::super($p,$actor))throw new \RuntimeException('Super Admin diperlukan.');InstallationKey::requireAuthorization($p,$actor);return self::permissions($p);
 }
 private static function schema(\PDO $p):array {
  $permissions=self::permissions($p);if(!$permissions['complete'])throw new \RuntimeException('Backup visibility belum lengkap. Keizinan per database yang kurang: '.implode(', ',$permissions['missing']).'. Jangan beri ALL PRIVILEGES secara automatik.');
  $s=Database::query($p,'SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll();
  if(!$s)throw new \RuntimeException('Database kosong tidak diterima sebagai backup.');
  foreach($s as $r)if($r['TABLE_TYPE']!=='BASE TABLE'||strcasecmp((string)$r['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('Backup automatik hanya menyokong base tables InnoDB; upgrade dihentikan.');
  foreach(['TRIGGERS'=> 'TRIGGER_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $table=>$column)if(Database::query($p,'SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.'=DATABASE()')->fetchColumn()>0)throw new \RuntimeException('Trigger/routine/event memerlukan backup pentadbiran lengkap; upgrade dihentikan.');
  if(Database::query($p,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE IN ('geometry','point','linestring','polygon','multipoint','multilinestring','multipolygon','geometrycollection')")->fetchColumn()>0)throw new \RuntimeException('Spatial columns memerlukan backup khusus; upgrade dihentikan.');
  return $s;
 }
 public static function readiness(\PDO $p,int $actor):array {
  if(!Authorization::super($p,$actor))throw new \RuntimeException('Super Admin diperlukan.');
  InstallationKey::requireAuthorization($p,$actor);$dir=APP_ROOT.'/storage/backups';
  if(UpgradePackage::symlink($dir)||!is_dir($dir)||!is_writable($dir)||disk_free_space($dir)<134217728||$p->inTransaction())throw new \RuntimeException('Backup storage tidak tersedia.');
  self::key();$s=self::schema($p);return ['status'=>'PASS','tables'=>count($s),'mode'=>'consistent InnoDB snapshot; created and verified immediately before file replacement','restore_test'=>'NOT TESTED'];
 }
 public static function create(\PDO $p,int $actor,string $run):array {
  if(!Authorization::super($p,$actor)||!preg_match('/\A[a-f0-9]{32}\z/',$run))throw new \RuntimeException('Backup authorization tidak sah.');
  InstallationKey::requireAuthorization($p,$actor);
  $dir=APP_ROOT.'/storage/backups';if(UpgradePackage::symlink($dir)||!is_dir($dir)||!is_writable($dir)||$p->inTransaction())throw new \RuntimeException('Backup storage/transaction tidak tersedia.');
  if(disk_free_space($dir)<134217728)throw new \RuntimeException('Ruang backup tidak mencukupi.');
  self::key();$name='auto_'.gmdate('Ymd_His').'_'.$run.'_'.bin2hex(random_bytes(4)).'.sql';$final=$dir.'/'.$name;$tmp=$final.'.partial';$h=null;$published=false;$bytes=0;$stringify=(bool)$p->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES);
  $seconds=(int)ini_get('max_execution_time');$deadline=microtime(true)+($seconds>0?max(1,min(45,$seconds-5)):45);
  $write=static function(string $data)use(&$h,&$bytes,$deadline):void {if(microtime(true)>$deadline||$bytes+strlen($data)>self::LIMIT)throw new \RuntimeException('Backup had masa/saiz dicapai; installation dihentikan.');$off=0;while($off<strlen($data)){$n=fwrite($h,substr($data,$off));if(!$n)throw new \RuntimeException('Backup write gagal.');$off+=$n;}$bytes+=strlen($data);};
  register_shutdown_function(static function()use(&$h,&$published,$tmp){if(!$published){if(is_resource($h))fclose($h);if(is_file($tmp)&&!is_link($tmp))@unlink($tmp);}});
  try {
   $schema=self::schema($p);$h=fopen($tmp,'xb');if(!$h||!chmod($tmp,0600))throw new \RuntimeException('Backup exclusive storage gagal.');
   $p->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES,true);$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
   $write("-- EPEJABAT AUTOMATIC DATABASE SNAPSHOT v1\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n");$tables=[];
   foreach($schema as $r){
    $table=$r['TABLE_NAME'];$q=self::quote($table);$expected=(int)$p->query('SELECT COUNT(*) FROM '.$q)->fetchColumn();$ddl=$p->query('SHOW CREATE TABLE '.$q)->fetch(\PDO::FETCH_NUM)[1];
    $columns=$p->query('SHOW FULL COLUMNS FROM '.$q)->fetchAll();$insert=[];$binary=[];$select=[];
    foreach($columns as $c)if(!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$c['Extra'])){$insert[]=$c['Field'];$bin=preg_match('/\A(?:binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i',$c['Type'])===1;$binary[]=$bin;$select[]=$bin?'HEX('.self::quote($c['Field']).')':'CAST('.self::quote($c['Field']).' AS CHAR CHARACTER SET utf8mb4)';}
    if(!$insert)throw new \RuntimeException('Backup generated-only table tidak disokong.');
    $write($ddl.";\n");$count=0;$hash=hash_init('sha256');$p->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$statement=$p->query('SELECT '.implode(',',$select).' FROM '.$q);
    while($row=$statement->fetch(\PDO::FETCH_NUM)){$values=[];foreach($row as $i=>$value){$v=$value===null?'NULL':($binary[$i]?"X'".(string)$value."'":"CONVERT(X'".bin2hex((string)$value)."' USING utf8mb4)");$values[]=$v;}$line='INSERT INTO '.$q.' ('.implode(',',array_map([self::class,'quote'],$insert)).') VALUES ('.implode(',',$values).");\n";$write($line);hash_update($hash,$line);$count++;}
    $statement->closeCursor();$p->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);if($count!==$expected)throw new \RuntimeException('Backup row completeness gagal.');
    $tables[$table]=['rows'=>$count,'schema_sha256'=>hash('sha256',$ddl),'data_sha256'=>hash_final($hash)];
   }
   if($schema!==self::schema($p))throw new \RuntimeException('Schema berubah semasa backup.');
   foreach($tables as $table=>$meta)if(!hash_equals($meta['schema_sha256'],hash('sha256',$p->query('SHOW CREATE TABLE '.self::quote($table))->fetch(\PDO::FETCH_NUM)[1])))throw new \RuntimeException('Schema drift semasa backup.');
   $write("SET FOREIGN_KEY_CHECKS=1;\n-- EPEJABAT SNAPSHOT COMPLETE\n");$p->rollBack();$p->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES,$stringify);if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new \RuntimeException('Backup flush gagal.');fclose($h);$h=null;
   if(!rename($tmp,$final))throw new \RuntimeException('Backup publish gagal.');
   $meta=['format'=>'epejabat-db-backup-v1','status'=>'COMPLETE','file'=>$name,'run_id'=>$run,'created_at'=>gmdate('c'),'bytes'=>$bytes,'sha256'=>hash_file('sha256',$final),'database_binding'=>hash_hmac('sha256',(string)$p->query('SELECT DATABASE()')->fetchColumn(),self::key()),'tables'=>$tables,'restore_test'=>'NOT TESTED'];
   $raw=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);Support::secureWrite($final.'.json',json_encode(['metadata'=>$raw,'hmac'=>hash_hmac('sha256',$raw,self::key())],JSON_THROW_ON_ERROR));
   $check=self::verify($name.':'.$meta['sha256'],$meta['created_at']);$published=true;
   Audit::record($p,'core.upgrade_database_backup',$actor,null,$run,'core',null,null,['status'=>'COMPLETE','tables'=>count($tables),'sha256'=>$meta['sha256']]);
   return ['reference'=>$name.':'.$meta['sha256'],'at'=>$meta['created_at'],'status'=>'PASS','tables'=>count($tables),'restore_test'=>'NOT TESTED'];
  }catch(\Throwable $e){if(isset($statement)&&$statement instanceof \PDOStatement)$statement->closeCursor();$p->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$p->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES,$stringify);if($p->inTransaction())$p->rollBack();if(is_resource($h))fclose($h);$h=null;if(!$published){foreach([$tmp,$final,$final.'.json'] as $f)if(is_file($f)&&!is_link($f))@unlink($f);}throw new \RuntimeException('Backup automatik gagal. Installation dihentikan. Rujukan '.Support::log($e));}
 }
 public static function verify(string $ref,string $at):array {
  if(!preg_match('/\A([A-Za-z0-9_-]+\.sql(?:\.gz)?):([a-f0-9]{64})\z/',$ref,$m))throw new \RuntimeException('Backup reference tidak sah.');$f=APP_ROOT.'/storage/backups/'.$m[1];$t=strtotime($at);clearstatcache(true,$f);
  if(!$t||$t>time()+60||time()-$t>86400||UpgradePackage::symlink($f)||!is_file($f)||!is_readable($f)||filesize($f)<128||filesize($f)>self::LIMIT||filemtime($f)<time()-86400||!hash_equals($m[2],hash_file('sha256',$f)))throw new \RuntimeException('Backup freshness/integrity gagal.');
  if(str_starts_with($m[1],'auto_')){
   $mf=$f.'.json';if(UpgradePackage::symlink($mf)||!is_file($mf)||filesize($mf)>1048576)throw new \RuntimeException('Backup completeness manifest tiada.');$envelope=json_decode(file_get_contents($mf),true,512,JSON_THROW_ON_ERROR);$raw=$envelope['metadata']??null;
   if(!is_string($raw)||!is_string($envelope['hmac']??null)||!hash_equals(hash_hmac('sha256',$raw,self::key()),$envelope['hmac']))throw new \RuntimeException('Backup manifest signature gagal.');$meta=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
   if(($meta['format']??'')!=='epejabat-db-backup-v1'||($meta['status']??'')!=='COMPLETE'||($meta['file']??'')!==$m[1]||($meta['created_at']??'')!==$at||($meta['bytes']??0)!==filesize($f)||!is_array($meta['tables']??null)||!$meta['tables']||!hash_equals($m[2],$meta['sha256']??''))throw new \RuntimeException('Backup completeness gagal.');
   $h=fopen($f,'rb');if(!$h||fseek($h,-strlen("-- EPEJABAT SNAPSHOT COMPLETE\n"),SEEK_END)!==0)throw new \RuntimeException('Backup footer tiada.');$tail=fread($h,strlen("-- EPEJABAT SNAPSHOT COMPLETE\n"));fclose($h);if($tail!=="-- EPEJABAT SNAPSHOT COMPLETE\n")throw new \RuntimeException('Backup tidak lengkap.');return ['status'=>'PASS','sha256'=>$m[2],'completeness'=>'PASS','restore_test'=>'NOT TESTED'];
  }
  if(str_ends_with($f,'.gz')){$h=gzopen($f,'rb');$head=$h?gzread($h,65536):false;if($h)gzclose($h);}else{$h=fopen($f,'rb');$head=$h?fread($h,65536):false;if($h)fclose($h);}if(!is_string($head)||!preg_match('/CREATE\s+TABLE/i',$head))throw new \RuntimeException('Backup SQL tidak dikenal pasti.');return ['status'=>'PASS','sha256'=>$m[2],'completeness'=>'NOT TESTED','restore_test'=>'NOT TESTED'];
 }
 public static function retain():array {
  // Keep every referenced run backup and at least seven newest; only unreferenced automatic backups older than 30 days are eligible.
  $dir=APP_ROOT.'/storage/backups';if(UpgradePackage::symlink($dir))throw new \RuntimeException('Backup retention path tidak selamat.');$protected=[];
  foreach(glob(APP_ROOT.'/storage/upgrades/*/state.json')?:[] as $state){if(UpgradePackage::symlink($state))throw new \RuntimeException('Retention run path tidak selamat.');$s=json_decode(file_get_contents($state),true,512,JSON_THROW_ON_ERROR);foreach(['backup_reference','automatic_backup'] as $k){$ref=$s[$k]??null;if(is_array($ref))$ref=$ref['reference']??null;if(is_string($ref))$protected[explode(':',$ref,2)[0]]=true;}}
  $files=glob($dir.'/auto_*.sql')?:[];usort($files,static fn($a,$b)=>filemtime($b)<=>filemtime($a));$removed=0;
  foreach($files as $i=>$f){if($i<7||isset($protected[basename($f)])||UpgradePackage::symlink($f)||UpgradePackage::symlink($f.'.json')||filemtime($f)>=time()-30*86400||!is_file($f.'.json'))continue;$env=json_decode(file_get_contents($f.'.json'),true);$raw=$env['metadata']??'';if(!is_string($raw)||!is_string($env['hmac']??null)||!hash_equals(hash_hmac('sha256',$raw,self::key()),$env['hmac']))continue; $m=json_decode($raw,true);if(($m['file']??'')!==basename($f)||!hash_equals($m['sha256']??'',hash_file('sha256',$f)))continue;if(unlink($f)){unlink($f.'.json');$removed++;}}
  return ['removed'=>$removed,'policy'=>'30 days; >=7 newest; all referenced run backups retained; manual backups untouched'];
 }
}
