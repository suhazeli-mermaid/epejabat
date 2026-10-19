<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class OrganisationNaming {
 public const FULL='Pejabat Kesihatan Daerah Kota Bharu';
 public static function rename(\PDO $p,int $actor):void {
  Authorization::require($p,$actor,'core.organisations.manage',3);
  Core::tx($p,function()use($p,$actor){$r=Database::query($p,'SELECT * FROM organisations WHERE id=3 FOR UPDATE')->fetch();if(!$r||$r['type']!=='pkd'||(int)$r['parent_id']!==2||MasterSourceImport::normalisedName($r['name'])!=='pejabat kesihatan daerah kota bharu')throw new \RuntimeException('Rekod Kota Bharu ID 3 / induk tidak sepadan; tiada perubahan.');if($r['name']===self::FULL)return;MasterSourceImport::organisationOfficialName($p,$actor,3,self::FULL,'https://jknkelantan.moh.gov.my/jknkelantan/v1/index.php/component/sppagebuilder/page/7','User-approved official organisation full-name standardisation; preserve ID/code/parent/history');});
 }
 public static function panel(\PDO $p,int $actor):string {
  if(!Authorization::allowed($p,$actor,'core.organisations.manage',3))return '';$name=Database::query($p,'SELECT name FROM organisations WHERE id=3')->fetchColumn();return '<section class="card"><h2>Nama rasmi Kota Bharu</h2><p>Nama semasa: <strong>'.Support::escape($name?:'Rekod tiada').'</strong></p><p>ID 3 dikekalkan. Nama digunakan bersama pada semua halaman yang membaca organisasi semasa. Alias lama dan audit sejarah tidak dipadam.</p>'.($name===self::FULL?'<p role="status">Nama penuh sudah digunakan.</p>':Support::form('organisation_full_name','','Seragamkan kepada '.self::FULL)).'</section>';
 }
}
