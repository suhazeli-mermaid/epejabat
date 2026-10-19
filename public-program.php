<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EPejabat\Core\{Database,Auth,Support,Core};
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
if(!$config||is_file(APP_ROOT.'/storage/upgrades/maintenance.lock')||!is_file(APP_ROOT.'/storage/installed.lock')){http_response_code(503);exit('Perkhidmatan belum tersedia.');}
$p=Database::connect($config['db']);
if(Database::query($p,"SELECT setting_value FROM system_settings WHERE setting_key='installation_status'")->fetchColumn()!=='complete'){http_response_code(503);exit('Perkhidmatan belum tersedia.');}
foreach(['220_public_programs.sql','221_public_program_seed.php'] as $file){$row=Database::query($p,"SELECT checksum,status FROM database_migrations WHERE version=?",['eforms:Phase2:'.$file])->fetch();if(!$row||$row['status']!=='applied'||!hash_equals($row['checksum'],hash_file('sha256',APP_ROOT.'/database/modules/eforms/'.$file))){http_response_code(503);exit('Migration program belum disahkan.');}}
Core::module($p,'public_programs');
require APP_ROOT.'/app/Modules/EForms/Schema.php';
require APP_ROOT.'/app/Modules/PublicPrograms/Service.php';
require APP_ROOT.'/app/Modules/PublicPrograms/Web.php';
$user=Auth::user($p);$GLOBALS['ui_user']=$user;
\EPejabat\Modules\PublicPrograms\Web::handle($p,$user,rtrim($config['app_url'],'/'));
