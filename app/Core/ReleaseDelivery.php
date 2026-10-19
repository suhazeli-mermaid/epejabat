<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class ReleaseDelivery {
    private static function gate(\PDO $p,int $actor):void {
        if(!Authorization::super($p,$actor))throw new \RuntimeException('Super Admin diperlukan.');
        InstallationKey::requireAuthorization($p,$actor);
    }
    private static function policy():array {
        $f=APP_ROOT.'/config/release-delivery.php';
        if(UpgradePackage::symlink($f)||!is_file($f))throw new \RuntimeException('Trusted release delivery belum dikonfigurasi; gunakan upload ZIP.');
        $v=require $f;if(!is_array($v)||($v['enabled']??false)!==true||!is_array($v['allowed_urls']??null)||!is_string($v['catalog_url']??null))throw new \RuntimeException('Release policy tidak sah.');return $v;
    }
    public static function configure(\PDO $p,int $actor,string $catalog,string $packageBase,string $password):void {
        self::gate($p,$actor);(new LiveUpgradeManager($p,$actor,APP_ROOT))->confirm($password);
        self::validateUrl($catalog,[$catalog]);self::validateUrl($packageBase,[$packageBase]);
        if(!str_ends_with($catalog,'.json')||!str_ends_with($packageBase,'/')||parse_url($catalog,PHP_URL_HOST)!==parse_url($packageBase,PHP_URL_HOST))throw new \InvalidArgumentException('Catalog JSON dan package folder mesti pada satu trusted HTTPS host.');
        $f=APP_ROOT.'/config/release-delivery.php';if(UpgradePackage::symlink($f))throw new \RuntimeException('Delivery configuration path tidak selamat.');
        $policy=['enabled'=>true,'catalog_url'=>$catalog,'allowed_urls'=>[$catalog,$catalog.'.sig',$packageBase]];
        Support::secureWrite($f,'<?php return '.var_export($policy,true).';');
        Audit::record($p,'release.delivery_configured',$actor,null,'release_repository','core',null,null,['host'=>parse_url($catalog,PHP_URL_HOST)]);
    }
    public static function validateUrl(string $url,array $allowed):array {
        $v=parse_url($url);$listed=in_array($url,$allowed,true);foreach($allowed as $prefix)if(is_string($prefix)&&str_ends_with($prefix,'/')&&str_starts_with($url,$prefix)&&preg_match('/\A[a-z][a-z0-9_]{1,30}\/\d+\.\d+\.\d+\.zip\z/',substr($url,strlen($prefix))))$listed=true;
        if(!$listed||!$v||($v['scheme']??'')!=='https'||isset($v['user'])||isset($v['pass'])||isset($v['query'])||isset($v['fragment'])||isset($v['port'])&&$v['port']!==443||!preg_match('/\A[a-z0-9.-]+\z/i',$v['host']??'')||str_contains($v['path']??'','..'))throw new \RuntimeException('Release URL tidak dibenarkan.');return $v;
    }
    public static function publicAddress(string $ip):bool {return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;}
    private static function fetch(string $url,array $allowed,int $limit):string {
        $v=self::validateUrl($url,$allowed);$records=dns_get_record($v['host'],DNS_A);
        if(!$records)throw new \RuntimeException('Release DNS tidak tersedia.');$ips=[];
        foreach($records as $r){$ip=$r['ip']??'';if(!self::publicAddress($ip))throw new \RuntimeException('Private/reserved release address ditolak.');$ips[]=$ip;}
        $out='';$overflow=false;$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$v['host'].':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$out,&$overflow,$limit){if(strlen($out)+strlen($part)>$limit){$overflow=true;return 0;}$out.=$part;return strlen($part);}]);
        try{$ok=curl_exec($curl);if($ok===false||$overflow||curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)throw new \RuntimeException('Release retrieval gagal.');}finally{curl_close($curl);}return $out;
    }
    public static function verifyCatalog(string $raw,string $signature,array $trust):array {
        if(strlen($raw)>262144)throw new \RuntimeException('Catalog terlalu besar.');$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$pub=$trust['publishers'][$data['publisher']??'']??null;$sig=base64_decode($signature,true);
        if(!is_string($pub)||!$sig||openssl_verify($raw,$sig,$pub,OPENSSL_ALGO_SHA256)!==1)throw new \RuntimeException('Catalog signature tidak dipercayai.');
        $expiry=strtotime($data['expires_at']??'');$created=strtotime($data['published_at']??'');
        if(($data['format']??'')!=='epejabat-release-catalog-v1'||!is_int($data['sequence']??null)||$data['sequence']<1||$expiry===false||$expiry<=time()||$created===false||$created>time()+60||!is_array($data['releases']??null)||count($data['releases'])>50)throw new \RuntimeException('Catalog expired/format tidak sah.');
        $seen=[];foreach($data['releases'] as $r){$key=($r['module']??'').':'.($r['version']??'');if(isset($seen[$key])||!isset($trust['modules'][$r['module']??''])||!preg_match('/\A\d+\.\d+\.\d+\z/',$r['version']??'')||!preg_match('/\A[a-f0-9]{64}\z/',$r['sha256']??'')||!is_string($r['url']??null))throw new \RuntimeException('Release entry tidak sah.');$seen[$key]=true;}return $data;
    }
    public static function reconcileCatalog(array $old,array $data,string $hash,array $allowed):array {
        if($data['sequence']<($old['sequence']??0))throw new \RuntimeException('Catalog rollback ditolak.');
        if($data['sequence']===($old['sequence']??0)&&($old['sha256']??'')!==$hash)throw new \RuntimeException('Catalog sequence conflict.');
        foreach($data['releases'] as $r){self::validateUrl($r['url'],$allowed);$key=$r['module'].':'.$r['version'];if(isset($old['releases'][$key])&&!hash_equals($old['releases'][$key],$r['sha256']))throw new \RuntimeException('Immutable release conflict.');$old['releases'][$key]=$r['sha256'];}
        $old['sequence']=$data['sequence'];$old['sha256']=$hash;return $old;
    }
    public static function catalog(\PDO $p,int $actor):array {
        self::gate($p,$actor);$policy=self::policy();$raw=self::fetch($policy['catalog_url'],$policy['allowed_urls'],262144);$signature=self::fetch($policy['catalog_url'].'.sig',$policy['allowed_urls'],8192);$data=self::verifyCatalog($raw,$signature,LiveUpgradeManager::trustPolicy());
        $dir=APP_ROOT.'/storage/upgrades';$path=$dir.'/delivery-state.json';if(UpgradePackage::symlink($path))throw new \RuntimeException('Delivery state tidak selamat.');
        $lock=$dir.'/delivery.lock';if(UpgradePackage::symlink($lock))throw new \RuntimeException('Delivery lock tidak selamat.');$h=fopen($lock,'c+');if(!$h||!flock($h,LOCK_EX))throw new \RuntimeException('Delivery lock gagal.');chmod($lock,0600);
        try{$old=['sequence'=>0,'releases'=>[]];if(is_file($path)){$envelope=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$rawState=$envelope['payload']??null;if(!is_string($rawState)||!hash_equals(hash_hmac('sha256',$rawState,Crypto::key()),(string)($envelope['hmac']??'')))throw new \RuntimeException('Delivery state integrity gagal.');$old=json_decode($rawState,true,512,JSON_THROW_ON_ERROR);}
            $old=self::reconcileCatalog($old,$data,hash('sha256',$raw),$policy['allowed_urls']);$rawState=json_encode($old,JSON_THROW_ON_ERROR);Support::secureWrite($path,json_encode(['payload'=>$rawState,'hmac'=>hash_hmac('sha256',$rawState,Crypto::key())],JSON_THROW_ON_ERROR));
        }finally{flock($h,LOCK_UN);fclose($h);}
        Audit::record($p,'release.catalog_verified',$actor,null,(string)$data['sequence']);return $data;
    }
    public static function assertRelease(array $manifest,string $module,string $version):void {if(($manifest['module']??null)!==$module||($manifest['version']??null)!==$version)throw new \RuntimeException('Retrieved manifest berbeza daripada catalog.');}
    public static function retrieve(\PDO $p,int $actor,string $module,string $version):array {
        self::gate($p,$actor);$catalog=self::catalog($p,$actor);$found=null;foreach($catalog['releases'] as $r)if($r['module']===$module&&$r['version']===$version)$found=$r;
        if(!$found)throw new \RuntimeException('Release tiada dalam catalog sah.');$policy=self::policy();$bytes=self::fetch($found['url'],$policy['allowed_urls'],33554432);
        if(!hash_equals($found['sha256'],hash('sha256',$bytes)))throw new \RuntimeException('Package delivery hash gagal.');$d=APP_ROOT.'/storage/upgrades';if(UpgradePackage::symlink($d))throw new \RuntimeException('Private retrieval path tidak selamat.');$file=$d.'/retrieved-'.bin2hex(random_bytes(16)).'.zip';
        try{Support::secureWrite($file,$bytes);$verified=UpgradePackage::verify($file,$found['sha256'],LiveUpgradeManager::trustPolicy());self::assertRelease($verified['manifest'],$module,$version);$manager=new LiveUpgradeManager($p,$actor,APP_ROOT);return $manager->stageAutomatic($file);}finally{if(is_file($file))unlink($file);}
    }
}
