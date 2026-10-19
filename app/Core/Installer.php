<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Installer {
    public function __construct(private ?array $config, private ?array $setup, private bool $https, private bool $local) {}
    private function requirements(): array {
        $checks=['PHP 8.2 atau lebih baharu'=>PHP_VERSION_ID>=80200,'PDO MySQL'=>extension_loaded('pdo_mysql'),'mbstring'=>extension_loaded('mbstring'),'ctype'=>extension_loaded('ctype'),'filter'=>extension_loaded('filter'),'hash'=>extension_loaded('hash'),'json'=>extension_loaded('json'),'fileinfo'=>extension_loaded('fileinfo'),'openssl'=>extension_loaded('openssl'),'ZIP'=>extension_loaded('zip'),'SimpleXML'=>extension_loaded('simplexml'),'Session extension'=>extension_loaded('session'),'Session aktif'=>session_status()===PHP_SESSION_ACTIVE,'HTTPS'=>($this->https||$this->local)];
        foreach(['config','storage','storage/logs','storage/files','storage/sessions'] as $dir) $checks['Folder '.$dir.' boleh ditulis']=is_writable(APP_ROOT.'/'.$dir);
        return $checks;
    }
    private function database(): \PDO { return Database::connect($this->config['db']??$_SESSION['install_db']??[]); }
    private function state(): int {
        if(!$this->config) return isset($_SESSION['install_db'])?3:2;
        $pdo=$this->database(); Migrations::verify($pdo);
        $instance=Database::query($pdo,"SELECT setting_value FROM system_settings WHERE setting_key='installation_instance'")->fetchColumn();
        if(!is_string($instance) || !hash_equals($this->config['instance'],$instance)) throw new \RuntimeException('Instance database tidak sepadan. Pemulihan diperlukan.');
        $status=Database::query($pdo,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn();
        if($status==='complete') return 7;
        if(!in_array($status,['pending_admin','pending_verification'],true)) throw new \RuntimeException('Status pemasangan tidak sah.');
        $count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if($status==='pending_admin' && $count===0) return 4;
        if($status==='pending_verification' && $count===1) return ($_SESSION['verified_instance']??'')===$instance?6:5;
        throw new \RuntimeException('Akaun sedia ada dikesan. Pemulihan diperlukan.');
    }
    private function token(): string {
        $token=$this->setup['token']??'';
        if(!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{32,255}\z/',$token) || str_starts_with($token,'REPLACE_') || str_starts_with($token,'GANTI_')) throw new \RuntimeException('Sediakan config/setup.php dengan token rawak dahulu. Rujuk panduan pemasangan.');
        return $token;
    }
    private function authorized(): bool { return isset($_SESSION['setup_auth'],$_SESSION['setup_until']) && $_SESSION['setup_until']>time() && hash_equals(hash('sha256',$this->token()),$_SESSION['setup_auth']); }
    private function authorize(): void {
        $file=APP_ROOT.'/storage/setup-throttle.json';$h=fopen($file,'c+');if(!$h || !flock($h,LOCK_EX)) throw new \RuntimeException('Setup throttle tidak tersedia.');
        try {
            $data=json_decode(stream_get_contents($h),true)?:[]; $now=time();
            foreach($data as $key=>$v) if($v['until']<=$now) unset($data[$key]);
            $key=hash('sha256',$_SERVER['REMOTE_ADDR']??'');$v=$data[$key]??['count'=>0,'until'=>$now+900];
            if($v['count']>=5) throw new \RuntimeException('Terlalu banyak cubaan token. Tunggu 15 minit.');
            $input=Support::post('token');$valid=hash_equals($this->token(),$input);
            if(!$valid) {$v['count']++;$data[$key]=$v;} else {unset($data[$key]);}
            rewind($h);ftruncate($h,0);fwrite($h,json_encode($data,JSON_THROW_ON_ERROR));fflush($h);
            if(!$valid) throw new \RuntimeException('Token pemasangan tidak sah.');
            session_regenerate_id(true); $_SESSION['setup_auth']=hash('sha256',$this->token());$_SESSION['setup_until']=$now+3600;
        } finally {flock($h,LOCK_UN);fclose($h);}
    }
    private function verifyAdmin(\PDO $pdo): array {
        Migrations::verify($pdo);
        $u=Database::query($pdo,"SELECT u.*,p.full_name FROM users u JOIN staff_profiles p ON p.staff_identity_id=u.staff_identity_id JOIN user_role_assignments a ON a.user_id=u.id JOIN roles r ON r.id=a.role_id WHERE r.code='super_admin' AND a.organisation_id IS NULL AND u.is_active=1")->fetchAll();
        if(count($u)!==1 || !Authorization::allowed($pdo,(int)$u[0]['id'],'core.dashboard.view')) throw new \RuntimeException('Super Admin tidak sah.');
        $loaded=require APP_ROOT.'/config/app.php';if($loaded!==$this->config) throw new \RuntimeException('Konfigurasi tidak sepadan.'); return $u[0];
    }
    private function migrate(): void {
        foreach($this->requirements() as $ok) if(!$ok) throw new \RuntimeException('Keperluan server belum dipenuhi.');
        AccessProtection::assert($this->setup['app_url']);
        $pdo=$this->database();
        $lock='ep_install_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,48);
        if((int)Database::query($pdo,"SELECT GET_LOCK(?,5)",[$lock])->fetchColumn()!==1) throw new \RuntimeException('Pemasangan lain sedang berjalan.');
        try {
            if($pdo->query('SHOW TABLES')->fetchAll()) throw new \RuntimeException('Database mesti kosong. Tiada data akan ditimpa.');
            $instance=bin2hex(random_bytes(16));$config=['app_url'=>$this->setup['app_url'],'environment'=>$this->setup['environment'],'instance'=>$instance,'db'=>$_SESSION['install_db']];
            $file=APP_ROOT.'/config/app.php';$h=fopen($file,'x');if(!$h) throw new \RuntimeException('Konfigurasi sudah wujud.');
            try { if(!chmod($file,0600) || fwrite($h,"<?php\nreturn ".var_export($config,true).";\n")===false) throw new \RuntimeException('Gagal menyimpan konfigurasi.'); } finally {fclose($h);}
            $this->config=$config; Migrations::runFresh($pdo,$instance);unset($_SESSION['install_db']);
        } finally {Database::query($pdo,"SELECT RELEASE_LOCK(?)",[$lock]);}
    }
    private function admin(): void {
        $name=Support::post('full_name',190);$username=mb_strtolower(Support::post('username',64));$email=mb_strtolower(Support::post('email',190));$password=Support::password();
        if($name==='' || !preg_match('/\A[a-z0-9_.-]{3,64}\z/',$username) || !filter_var($email,FILTER_VALIDATE_EMAIL) || $password!==($_POST['confirm_password']??null)) throw new \InvalidArgumentException('Semak nama, username, email dan pengesahan kata laluan.');
        $orgName=Support::post('organisation_name',190);$orgCode=Support::post('organisation_code',50);if($orgName===''||!preg_match('/\A[A-Za-z0-9_-]{2,50}\z/',$orgCode))throw new \InvalidArgumentException('Organisasi awal tidak sah.');$_SESSION['initial_org']=[$orgCode,$orgName];
        $pdo=$this->database();$pdo->beginTransaction();try {
            $status=Database::query($pdo,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status' FOR UPDATE")->fetchColumn();
            if($status!=='pending_admin' || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) throw new \RuntimeException('Akaun admin sudah wujud.');
            $hex=bin2hex(random_bytes(16));$uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);
            Database::query($pdo,'INSERT INTO staff_identities (identity_uuid) VALUES (?)',[$uuid]);$staff=(int)$pdo->lastInsertId();
            Database::query($pdo,'INSERT INTO staff_profiles (staff_identity_id,full_name) VALUES (?,?)',[$staff,$name]);
            Database::query($pdo,'INSERT INTO users (staff_identity_id,username,email,password_hash) VALUES (?,?,?,?)',[$staff,$username,$email,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$pdo->lastInsertId();
            Database::query($pdo,"INSERT INTO user_role_assignments (user_id,role_id,organisation_id,include_descendants) SELECT ?,id,NULL,1 FROM roles WHERE code='super_admin'",[$user]);
            Database::query($pdo,"UPDATE system_settings SET setting_value='pending_verification' WHERE setting_key='installation_status'");Audit::record($pdo,'installation.admin_created',$user);$pdo->commit();
        }catch(\Throwable $e){$pdo->rollBack();throw $e;}
    }
    private function finish(): void {
        $pdo=$this->database();$u=$this->verifyAdmin($pdo);$pdo->beginTransaction();try {
            Database::query($pdo,"UPDATE system_settings SET setting_value='complete' WHERE setting_key='installation_status'");Audit::record($pdo,'installation.complete',(int)$u['id']);$pdo->commit();
        }catch(\Throwable $e){$pdo->rollBack();throw $e;}
        define('FRESH_INSTALL_MIGRATION',true);
        try {FreshPlatform::complete($pdo,(int)$u['id'],$_SESSION['initial_org']??['PKDKB','PKD Kota Bharu']);AccessProtection::assert($this->config['app_url']);}catch(\Throwable $e){Database::query($pdo,"UPDATE system_settings SET setting_value='fresh_failed' WHERE setting_key='installation_status'");throw $e;}
        Support::secureWrite(APP_ROOT.'/storage/installed.lock',json_encode(['instance'=>$this->config['instance'],'completed_at'=>gmdate('c')],JSON_THROW_ON_ERROR));
        @unlink(APP_ROOT.'/config/setup.php');$_SESSION=[];session_regenerate_id(true);Support::redirect('/login');
    }
    public function handle(): never {
        if(is_file(APP_ROOT.'/storage/installed.lock')) {http_response_code(403);Support::layout('Installer dikunci','<p>Pemasangan sudah selesai.</p><a href="/login">Log masuk</a>');}
        $error='';$step=1;
        try {
            if($this->config && $this->state()===7) {http_response_code(403);Support::layout('Installer dikunci','<p>Pemasangan sudah selesai. Installer tidak boleh digunakan semula.</p><a href="/login">Log masuk</a>');}
            $this->token();
            if($_SERVER['REQUEST_METHOD']==='POST') {
                Support::checkCsrf();$action=Support::post('action',30);
                if($action==='authorize') {$this->authorize();Support::redirect('/install');}
                if(!$this->authorized()) throw new \RuntimeException('Token diperlukan.');
                $lock=fopen(APP_ROOT.'/storage/installation-process.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new \RuntimeException('Pemasangan sedang berjalan. Cuba semula.');
                try {
                    if(is_file(APP_ROOT.'/storage/installed.lock')) throw new \RuntimeException('Installer dikunci.');
                    $step=$this->state();
                    if($action==='database' && $step===2) {
                        foreach($this->requirements() as $ok) if(!$ok) throw new \RuntimeException('Keperluan server belum dipenuhi.');
                        AccessProtection::assert($this->setup['app_url']);
                        $password=$_POST['db_password']??'';if(!is_string($password) || strlen($password)<1 || strlen($password)>1024) throw new \InvalidArgumentException('Input database tidak sah.');
                        $port=Support::post('db_port',5);if(!ctype_digit($port)) throw new \InvalidArgumentException('Port mesti nombor.');
                        $db=['host'=>Support::post('db_host'),'name'=>Support::post('db_name'),'user'=>Support::post('db_user'),'password'=>$password,'port'=>(int)$port];
                        $pdo=Database::connect($db);$version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
                        $maria=str_contains($version,'MariaDB');preg_match('/(\d+\.\d+\.\d+)/',$version,$match);
                        if(!isset($match[1]) || version_compare($match[1],$maria?'10.6.0':'8.0.16','<')) throw new \RuntimeException('Perlu MySQL 8.0.16+ atau MariaDB 10.6+.');
                        if($pdo->query('SHOW TABLES')->fetchAll()) throw new \RuntimeException('Database mesti kosong. Pilih database baharu.');
                        $_SESSION['install_db']=$db;
                    }elseif($action==='migrate' && $step===3) {$this->migrate();}
                    elseif($action==='admin' && $step===4) {$this->admin();}
                    elseif($action==='verify' && $step===5) {$u=$this->verifyAdmin($this->database());if(!password_verify(Support::password(),$u['password_hash'])) throw new \RuntimeException('Pengesahan kata laluan admin gagal.');$_SESSION['verified_instance']=$this->config['instance'];}
                    elseif($action==='finish' && $step===6) {$this->finish();}
                    else {throw new \RuntimeException('Urutan pemasangan tidak sah.');}
                }finally {flock($lock,LOCK_UN);fclose($lock);}
                Support::redirect('/install');
            }
            $step=$this->authorized()?$this->state():1;
        }catch(\InvalidArgumentException|\RuntimeException $e){
            if($e instanceof \PDOException) {$id=Support::log($e);$error='Database tidak dapat diproses. Semak konfigurasi dan privileges. Rujukan: '.$id;}
            else {$error=$e->getMessage();}
        }catch(\Throwable $e){$error='Pemasangan dihentikan. Rujukan: '.Support::log($e);}
        // A failed migration never resumes automatically. Configuration remains for recovery.
        if($this->config && $error!=='' && $step===1) Support::layout('Pemulihan diperlukan','<p class="error">'.Support::escape($error).'</p><p>Jangan padam database atau konfigurasi. Rujuk docs/ROLLBACK.md.</p>');
        $body='<p class="badge">Pemasangan baharu · Langkah '.$step.' daripada 6</p>';
        if($error!=='') $body.='<p role="alert" class="error">'.Support::escape($error).'</p>';
        if($step===1){
            $body.='<h2>1. Keperluan server</h2><ul class="checks">';foreach($this->requirements() as $label=>$ok) $body.='<li>'.($ok?'✓':'✗').' '.Support::escape($label).'</li>'; $body.='</ul><p>Masukkan token yang disediakan dalam konfigurasi setup.</p>'.Support::form('authorize',Support::fields(['token'=>['Token pemasangan','password','']]),'Teruskan');
        }elseif($step===2){$body.='<h2>2. Konfigurasi database</h2><p>Gunakan database kosong yang dicipta dalam cPanel. Sambungan diuji sebelum migration.</p>'.Support::form('database',Support::fields(['db_host'=>['Database Host','text','localhost'],'db_name'=>['Database Name','text',''],'db_user'=>['Database Username','text',''],'db_password'=>['Database Password','password',''],'db_port'=>['Database Port','number','3306']]),'Test Connection');}
        elseif($step===3){$body.='<h2>3. Pemasangan database</h2><p>Sambungan berjaya. Jadual asas dan permission akan dibina menggunakan migration berversi.</p>'.Support::form('migrate','','Pasang database');}
        elseif($step===4){$body.='<h2>4. Super Administrator</h2><p>Username: 3–64 aksara (huruf kecil, nombor, titik, garis bawah atau sempang). Kata laluan: 12–72 bait.</p>'.Support::form('admin',Support::fields(['full_name'=>['Nama penuh','text',''],'username'=>['Username','text',''],'email'=>['Email','email',''],'password'=>['Kata laluan','password',''],'confirm_password'=>['Sahkan kata laluan','password',''],'organisation_name'=>['Organisasi PKD awal','text','PKD Kota Bharu'],'organisation_code'=>['Kod organisasi','text','PKDKB']]),'Cipta Super Admin');}
        elseif($step===5){$body.='<h2>5. Pengesahan pemasangan</h2><p>Masukkan semula kata laluan Super Admin untuk mengesahkan authentication, role, jadual, checksum migration dan konfigurasi.</p>'.Support::form('verify',Support::fields(['password'=>['Kata laluan Super Admin','password','']]),'Sahkan pemasangan');}
        elseif($step===6){$body.='<h2>6. Kunci pemasangan</h2><p>Pengesahan berjaya. Tindakan ini memasang Shared Core dan Training T1–T6, mengesahkan migration dan mengunci installer.</p>'.Support::form('finish','','Selesai & kunci installer');}
        Support::layout('Installation Wizard',$body);
    }
}
