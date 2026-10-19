<?php
declare(strict_types=1);
namespace EPejabat\Core;
/** Activated only by the separately authorised hosting maintenance procedure. */
final class UpgradePolicy {
 public static function ownerMode():bool {
  $f=APP_ROOT.'/config/upgrade-policy.json';
  if(UpgradePackage::symlink($f))throw new \RuntimeException('Policy path tidak selamat.');
  if(!file_exists($f))return false;
  if(!is_file($f)||filesize($f)>16384)throw new \RuntimeException('Policy tidak sah.');
  $e=json_decode(file_get_contents($f),true,32,JSON_THROW_ON_ERROR);$raw=$e['payload']??null;
  if(!is_string($raw)||!is_string($e['hmac']??null)||!hash_equals(hash_hmac('sha256',$raw,Crypto::key()),$e['hmac']))throw new \RuntimeException('Policy integrity gagal.');
  $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(($p['mode']??null)!=='owner-authorized-v1'||($p['root_sha256']??null)!==hash('sha256',realpath(APP_ROOT)))throw new \RuntimeException('Policy binding gagal.');
  return true;
 }
 public static function protectedPath(string $f):bool {
  return in_array($f,['root/app/Core/UpgradePolicy.php','root/app/Core/UpgradePackage.php','root/app/Core/LiveUpgradeManager.php','root/app/Core/UpgradeWeb.php','root/app/Core/InstallationKey.php','root/config/upgrade-policy.json','root/config/upgrade-trust.php','root/config/core.key'],true);
 }
}
