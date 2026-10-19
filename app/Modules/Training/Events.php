<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
use EPejabat\Core\{Database,Core,Crypto,Notifications};
final class Events {
 public static function emit(\PDO $p,int $programme,int $user,string $key,string $message,array $extra=[]):void {
  Database::query($p,'INSERT IGNORE INTO training_notification_events(programme_id,recipient_user_id,event_key,payload_cipher) VALUES(?,?,?,?)',[$programme,$user,hash('sha256',$key),Crypto::seal(json_encode(['message'=>Core::text($message,3000),'extra'=>$extra],JSON_THROW_ON_ERROR))]);
 }
 public static function dispatch(\PDO $p,int $limit=100):int {
  $n=0;foreach(Database::query($p,'SELECT id FROM training_notification_events WHERE processed_at IS NULL ORDER BY id LIMIT '.max(1,min(100,$limit)))->fetchAll() as $r){Core::tx($p,function()use($p,$r,&$n){$e=Database::query($p,'SELECT * FROM training_notification_events WHERE id=? AND processed_at IS NULL FOR UPDATE',[$r['id']])->fetch();if(!$e)return;$u=Database::query($p,'SELECT * FROM users WHERE id=? AND is_active=1',[$e['recipient_user_id']])->fetch();if(!$u){Database::query($p,'UPDATE training_notification_events SET processed_at=UTC_TIMESTAMP() WHERE id=?',[$e['id']]);return;}$data=json_decode(Crypto::open($e['payload_cipher']),true,512,JSON_THROW_ON_ERROR);Notifications::inApp($p,(int)$u['id'],'Makluman Program',$data['message'],'training');$payload=['message'=>$data['message'],...$data['extra']];$delivery=Notifications::enqueue($p,$u['email'],isset($payload['url'])?'training_invitation':'training_event',$payload);Database::query($p,'UPDATE training_notification_events SET processed_at=UTC_TIMESTAMP(),delivery_id=?,payload_cipher=? WHERE id=?',[$delivery,Crypto::seal('{}'),$e['id']]);$n++;});}return $n;
 }
}
