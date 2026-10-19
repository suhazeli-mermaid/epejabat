<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class GeneratedDocuments {
 public static function store(\PDO $p,int $u,int $programme,string $title,string $pdf):int {
  $r=\EPejabat\Modules\Training\Policy::programme($p,$programme);\EPejabat\Modules\Training\Policy::require($p,$u,'outputs.manage',$r);if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)>10000000)throw new \RuntimeException('Generated PDF tidak sah.');$key=bin2hex(random_bytes(32));$file=APP_ROOT.'/storage/documents/'.$key;$h=fopen($file,'x');if(!$h)throw new \RuntimeException('Storan tidak tersedia.');try{chmod($file,0600);if(fwrite($h,$pdf)!==strlen($pdf))throw new \RuntimeException('Write gagal.');}finally{fclose($h);}try{return Core::tx($p,function()use($p,$u,$programme,$title,$pdf,$key,$r){Database::query($p,"INSERT INTO documents(owner_id,organisation_id,module_code,title,status) VALUES(?,?,'training',?,'locked')",[$u,$r['owner_organisation_id'],Core::text($title)]);$id=(int)$p->lastInsertId();Database::query($p,'INSERT INTO document_versions(document_id,version,storage_key,original_name,mime,byte_size,checksum,uploaded_by) VALUES(?,1,?,?,?, ?,?,?)',[$id,$key,'document-'.$id.'.pdf','application/pdf',strlen($pdf),hash('sha256',$pdf),$u]);Database::query($p,'INSERT INTO training_programme_documents(document_id,programme_id,purpose) VALUES(?,?,?)',[$id,$programme,'official_generated']);Audit::record($p,'document.generated',$u,(int)$r['owner_organisation_id'],(string)$id,'training',null,null,['checksum'=>hash('sha256',$pdf)]);return $id;});}catch(\Throwable $e){@unlink($file);throw $e;}
 }
}
