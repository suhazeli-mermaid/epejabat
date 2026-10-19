<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class SqlPolicy {
 public static function safe(string $sql):bool {
  $text=preg_replace('~/\*.*?\*/|--[^\r\n]*|\#[^\r\n]*~s',' ',$sql);$text=preg_replace("~'(?:''|\\\\.|[^'\\\\])*'|\"(?:\"\"|\\\\.|[^\"\\\\])*\"~s",'STRING',$text);
  $text=preg_replace('/ON\s+DELETE\s+(?:RESTRICT|NO\s+ACTION|SET\s+NULL)/i','',$text);
  return !preg_match('/\b(?:DROP|TRUNCATE|DELETE|REPLACE|RENAME|GRANT|REVOKE|LOAD|OUTFILE|DUMPFILE)\b|ALTER\s+TABLE[^;]+\b(?:CHANGE|MODIFY)\b/i',$text);
 }
 public static function tables(string $sql):array {preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i',$sql,$m);return $m[1];}
}
