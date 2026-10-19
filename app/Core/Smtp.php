<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class SmtpFailure extends \RuntimeException {
 public function __construct(public readonly string $stage,public readonly string $category,public readonly ?int $smtpCode=null,public readonly ?int $systemCode=null){parent::__construct('SMTP '.$stage.' '.$category);}
 public function safeDiagnostic():string{return 'SMTP '.$this->stage.' '.$this->category.($this->smtpCode!==null?' code='.$this->smtpCode:'').($this->systemCode!==null?' errno='.$this->systemCode:'');}
}
final class Smtp {
 public static function send(array $c,string $to,string $subject,string $body):void {
  try{$to=Core::email($to);$from=Core::email($c['from_email']??'');$host=Core::text($c['host']??'',253);$port=filter_var($c['port']??null,FILTER_VALIDATE_INT);$enc=$c['encryption']??null;if(!preg_match('/\A[A-Za-z0-9.-]+\z/',$host)||!$port||$port<1||$port>65535||!in_array($enc,['tls','ssl','local-test'],true))throw new \RuntimeException();$subject=Core::text($subject,190);if(strpbrk($subject,"\r\n")!==false)throw new \RuntimeException();if(($c['username']??'')!==''&&(!is_string($c['username'])||!is_string($c['password']??null)||$c['password']===''))throw new \RuntimeException();}catch(\Throwable $e){throw new SmtpFailure('CONFIG','INVALID');}
  $app=require APP_ROOT.'/config/app.php';if($enc==='local-test'&&(($app['environment']??'')!=='local'||!in_array($host,['127.0.0.1','localhost'],true)))throw new SmtpFailure('CONFIG','TLS_REQUIRED');
  if($enc!=='local-test'&&!extension_loaded('openssl'))throw new SmtpFailure('TLS','EXTENSION_MISSING');
  $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
  // Connect TCP first so connection and TLS failures remain distinguishable.
  $errno=0;$errstr='';$s=self::quiet(function()use($host,$port,$context,&$errno,&$errstr){return stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);});
  if(!$s)throw new SmtpFailure('CONNECT','UNAVAILABLE',null,isset($errno)?(int)$errno:0);
  stream_set_timeout($s,10);
  try{
   if($enc==='ssl')self::tls($s); // Implicit TLS: no greeting read or credentials before handshake.
   self::reply($s,[220],'GREETING');self::command($s,'EHLO epejabat.net',[250],'EHLO');
   if($enc==='tls'){self::command($s,'STARTTLS',[220],'STARTTLS');self::tls($s);self::command($s,'EHLO epejabat.net',[250],'EHLO');}
   if(($c['username']??'')!==''){self::command($s,'AUTH LOGIN',[334],'AUTH');self::command($s,base64_encode($c['username']),[334],'AUTH');self::command($s,base64_encode($c['password']),[235],'AUTH');}
   self::command($s,'MAIL FROM:<'.$from.'>',[250],'MAIL_FROM');self::command($s,'RCPT TO:<'.$to.'>',[250,251],'RCPT_TO');self::command($s,'DATA',[354],'DATA');
   $body=str_replace(["\r\n","\r"],"\n",$body);$body=preg_replace('/^\./m','..',$body);$headers='Date: '.gmdate('D, d M Y H:i:s').' +0000'."\r\n".'Message-ID: <'.bin2hex(random_bytes(16)).'@epejabat.net>'."\r\n".'From: <'.$from.'>'."\r\n".'To: <'.$to.'>'."\r\n".'Subject: '.mb_encode_mimeheader($subject,'UTF-8','B',"\r\n",9)."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
   self::write($s,$headers.chunk_split(base64_encode($body),76,"\r\n")."\r\n.\r\n",'DATA');self::reply($s,[250],'ACCEPT');self::quiet(fn()=>fwrite($s,"QUIT\r\n"));
  }finally{fclose($s);}
 }
 private static function quiet(callable $f):mixed {set_error_handler(static fn()=>true);try{return $f();}finally{restore_error_handler();}}
 private static function tls($s):void {if(self::quiet(fn()=>stream_socket_enable_crypto($s,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))!==true)throw new SmtpFailure('TLS','HANDSHAKE_OR_CERTIFICATE_FAILED');}
 private static function write($s,string $data,string $stage):void {$n=0;while($n<strlen($data)){$w=self::quiet(fn()=>fwrite($s,substr($data,$n)));if($w===false||$w===0)throw new SmtpFailure($stage,'WRITE_FAILED');$n+=$w;}}
 private static function command($s,string $cmd,array $expected,string $stage):void {if(strpbrk($cmd,"\r\n")!==false)throw new SmtpFailure($stage,'INVALID_COMMAND');self::write($s,$cmd."\r\n",$stage);self::reply($s,$expected,$stage);}
 private static function reply($s,array $expected,string $stage):void {$code=null;for($i=0;$i<100;$i++){$l=self::quiet(fn()=>fgets($s,4096));if($l===false)throw new SmtpFailure($stage,stream_get_meta_data($s)['timed_out']?'TIMEOUT':'CONNECTION_CLOSED');if(!preg_match('/\A([0-9]{3})([ -])/',$l,$m))throw new SmtpFailure($stage,'INVALID_RESPONSE');$n=(int)$m[1];if($code!==null&&$code!==$n)throw new SmtpFailure($stage,'INVALID_RESPONSE');$code=$n;if($m[2]===' '){if(!in_array($code,$expected,true))throw new SmtpFailure($stage,'REJECTED',$code);return;}}throw new SmtpFailure($stage,'RESPONSE_TOO_LONG');}
}
