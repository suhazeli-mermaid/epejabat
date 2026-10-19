<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Auth {
    public static function user(\PDO $pdo): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        if(time()-($_SESSION['last_seen']??0)>1800 || time()-($_SESSION['signed_in']??0)>28800) { unset($_SESSION['user_id']); return null; }
        $phase=Database::query($pdo,"SELECT setting_value FROM system_settings WHERE setting_key='phase'")->fetchColumn();
        $extra=$phase==='1'?',u.credential_revision':'';
        $u=Database::query($pdo,'SELECT u.id,u.staff_identity_id,u.username,u.email,u.is_active,p.full_name,p.organisation_id'.$extra.' FROM users u JOIN staff_profiles p ON p.staff_identity_id=u.staff_identity_id WHERE u.id=? AND u.is_active=1',[$_SESSION['user_id']])->fetch();
        if($u && $phase==='1' && (int)($u['credential_revision'])!==(int)($_SESSION['credential_revision']??-1)) $u=false;
        if(!$u) {unset($_SESSION['user_id']);return null;} $_SESSION['last_seen']=time(); return $u;
    }
    public static function login(\PDO $pdo,string $name,string $password): bool {
        $name=mb_strtolower(trim($name)); $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'cli'); $account=hash('sha256',$name);
        // Per-database advisory lock makes threshold checks atomic across parallel requests.
        $lock='ep_login_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,48);
        if((int)Database::query($pdo,"SELECT GET_LOCK(?,5)",[$lock])->fetchColumn()!==1) throw new \RuntimeException('Sila cuba sebentar lagi.');
        try {
            Database::query($pdo,'DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $u=Database::query($pdo,'SELECT * FROM users WHERE username=? OR email=?',[$name,$name])->fetch();
            if($u) $account=hash('sha256','user:'.$u['id']);
            $ipCount=(int)Database::query($pdo,'SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE',[$ip])->fetchColumn();
            $accountCount=(int)Database::query($pdo,'SELECT COUNT(*) FROM login_attempts WHERE account_hash=? AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE',[$account])->fetchColumn();
            if($ipCount>=20 || $accountCount>=5) { Audit::record($pdo,'auth.rate_limited'); throw new \RuntimeException('Terlalu banyak cubaan. Cuba selepas 15 minit.'); }
            $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $valid=password_verify($password,$hash);
            if(!$u || !$valid || !$u['is_active']) { Database::query($pdo,'INSERT INTO login_attempts (ip_hash,account_hash) VALUES (?,?)',[$ip,$account]); Audit::record($pdo,'auth.failed'); return false; }
            // Keep preceding failures until expiry; success cannot reset another user's IP budget.
            if(password_needs_rehash($hash,PASSWORD_DEFAULT)) Database::query($pdo,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
            Audit::record($pdo,'auth.login',(int)$u['id']);
            Database::query($pdo,'UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?',[$u['id']]);
            session_regenerate_id(true); $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'user_id'=>(int)$u['id'],'signed_in'=>time(),'last_seen'=>time(),'credential_revision'=>(int)($u['credential_revision']??0)];
            return true;
        } finally { Database::query($pdo,"SELECT RELEASE_LOCK(?)",[$lock]); }
    }
    public static function logout(\PDO $pdo): void {
        Audit::record($pdo,'auth.logout',isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null); $_SESSION=[];
        if(ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$p['path'],'secure'=>$p['secure'],'httponly'=>true,'samesite'=>'Lax']); } session_destroy();
    }
}
