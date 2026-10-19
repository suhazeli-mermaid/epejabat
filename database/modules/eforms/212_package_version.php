<?php
declare(strict_types=1);
return static function(PDO $p):void {$s=$p->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('module_version.eforms','1.3.0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute();};
