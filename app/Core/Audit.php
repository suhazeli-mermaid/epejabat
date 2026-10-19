<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Audit {
    private static function clean(?array $data): ?string {
        if($data===null)return null;$out=[];foreach($data as $k=>$v){if(preg_match('/password|token|secret|cipher|identification|credential_revision/i',(string)$k))continue;$out[$k]=is_array($v)?json_decode(self::clean($v),true):$v;}return json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
    public static function record(\PDO $p,string $event,?int $actor=null,?int $organisation=null,?string $subject=null,string $module='core',?string $reason=null,?array $before=null,?array $after=null): void {
        $phase=Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='phase'")->fetchColumn();$ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'cli');
        if($phase!=='1'){Database::query($p,'INSERT INTO audit_logs(user_id,organisation_id,event,subject,ip_hash) VALUES(?,?,?,?,?)',[$actor,$organisation,$event,$subject,$ip]);return;}
        $values=[$actor,$organisation,$event,$subject,$ip,$module,$reason,self::clean($before),self::clean($after)];
        Database::query($p,'INSERT INTO audit_logs(user_id,organisation_id,event,subject,ip_hash,module_code,reason,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?)',$values);$id=(int)$p->lastInsertId();
        $r=Database::query($p,'SELECT * FROM audit_logs WHERE id=?',[$id])->fetch();unset($r['integrity_hash']);$hash=hash_hmac('sha256',json_encode($r,JSON_THROW_ON_ERROR),Crypto::key());Database::query($p,'UPDATE audit_logs SET integrity_hash=? WHERE id=?',[$hash,$id]);
    }
    public static function valid(array $r): bool {$h=$r['integrity_hash']??null;unset($r['integrity_hash']);return is_string($h)&&hash_equals($h,hash_hmac('sha256',json_encode($r,JSON_THROW_ON_ERROR),Crypto::key()));}
}
