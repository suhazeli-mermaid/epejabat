<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
$root=dirname(__DIR__); $file=$root.'/config/setup.php';
if(is_file($file) || is_file($root.'/config/app.php') || is_file($root.'/storage/installed.lock')) {fwrite(STDERR,"Setup/config already exists. No overwrite.\n");exit(1);}
$url=$argv[1]??'https://pkdkb.epejabat.net'; $local=in_array('--local',$argv,true);
if(!filter_var($url,FILTER_VALIDATE_URL) || (!$local && parse_url($url,PHP_URL_SCHEME)!=='https') || parse_url($url,PHP_URL_PATH)) {fwrite(STDERR,"Use origin URL without path; HTTPS in production.\n");exit(1);}
$token=bin2hex(random_bytes(24));
$h=fopen($file,'x'); if(!$h) exit(1); chmod($file,0600); fwrite($h,"<?php\nreturn ".var_export(['token'=>$token,'app_url'=>$url,'environment'=>$local?'local':'production'],true).";\n");fclose($h);
echo "One-time setup token (keep private): $token\n";
