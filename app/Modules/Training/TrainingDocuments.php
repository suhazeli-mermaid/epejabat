<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
use EPejabat\Core\{Database,Authorization};
final class TrainingDocuments {
 public static function access(\PDO $p,int $u,array $d,bool $manage):void {
  if(Official::accessDocument($p,$u,$d,$manage))return;
  $link=Database::query($p,'SELECT programme_id FROM training_programme_documents WHERE document_id=?',[$d['id']])->fetchColumn();if(!$link)throw new \RuntimeException('Dokumen Training tidak dipautkan.');$r=Policy::programme($p,(int)$link);if((int)$d['organisation_id']!==(int)$r['owner_organisation_id'])throw new \RuntimeException('Scope dokumen tidak sepadan.');if(Policy::allowed($p,$u,'documents.manage',$r)||!$manage&&Policy::allowed($p,$u,'documents.upload',$r))return;
  $a=Policy::actor($p,$u);if(!$manage&&$r['is_published']&&!$r['is_cancelled']&&Authorization::allowed($p,$u,'training.documents.view',(int)$a['organisation_id'])&&Database::query($p,"SELECT 1 FROM training_registrations WHERE programme_id=? AND staff_identity_id=? AND status='CONFIRMED'",[$r['id'],$a['staff_identity_id']])->fetchColumn())return;
  throw new \RuntimeException('Akses dokumen program ditolak.');
 }
 public static function upload(\PDO $p,int $u,int $org,?int $programme,?int $doc):int {
  if($doc&&ExtendedMigrations::ready($p)&&\EPejabat\Core\Database::query($p,'SELECT 1 FROM training_outputs WHERE document_id=?',[$doc])->fetchColumn())throw new \RuntimeException('Official generated document immutable; VOID/REISSUE diperlukan.');
  if($doc){$link=Database::query($p,'SELECT programme_id FROM training_programme_documents WHERE document_id=?',[$doc])->fetchColumn();if(!$link)throw new \RuntimeException('Dokumen tidak dipautkan.');if($programme!==null&&$programme!==(int)$link)throw new \RuntimeException('Program berbeza.');$programme=(int)$link;}if(!$programme)throw new \RuntimeException('Program ID diperlukan.');$r=Policy::programme($p,$programme);Policy::require($p,$u,'documents.upload',$r);if($org!==(int)$r['owner_organisation_id']||$r['is_cancelled'])throw new \RuntimeException('Scope dokumen tidak sah.');return $programme;
 }
}
