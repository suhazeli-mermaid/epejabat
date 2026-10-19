<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class MasterData {
    public const CATEGORIES=['state','district','organisation_type','facility','unit','profession','position','grade','regulator','employment_status'];
    public static function validate(\PDO $p,?int $id,string $category): ?int {if($id===null)return null;if(!Database::query($p,'SELECT 1 FROM master_data WHERE id=? AND category=? AND is_active=1',[$id,$category])->fetchColumn())throw new \InvalidArgumentException('Master data tidak sah: '.$category);return $id;}
    public static function save(\PDO $p,int $actor,array $d,?int $id=null): int {
        Authorization::require($p,$actor,'core.master.manage');$category=Core::text($d['category']??'',40);if(!in_array($category,self::CATEGORIES,true))throw new \InvalidArgumentException('Kategori tidak sah.');$code=Core::text($d['code']??'',64);if(!preg_match('/\A[a-zA-Z0-9_-]+\z/',$code))throw new \InvalidArgumentException('Kod tidak sah.');$name=Core::text($d['name']??'');$org=Core::id($d['organisation_id']??null,true);$parent=Core::id($d['parent_id']??null,true);
        if(in_array($category,['district','facility','unit'],true)&&$org===null)throw new \InvalidArgumentException('Pautkan rekod kepada organisasi.');
        if($org!==null&&!Database::query($p,'SELECT 1 FROM organisations WHERE id=?',[$org])->fetchColumn())throw new \InvalidArgumentException('Organisasi tidak wujud.');
        if($parent!==null&&($parent===$id||!Database::query($p,'SELECT 1 FROM master_data WHERE id=?',[$parent])->fetchColumn()))throw new \InvalidArgumentException('Parent tidak sah.');
        return Core::tx($p,function()use($p,$actor,$d,$id,$category,$code,$name,$org,$parent){$before=$id?Database::query($p,'SELECT * FROM master_data WHERE id=? FOR UPDATE',[$id])->fetch():null;if($id&&!$before)throw new \InvalidArgumentException('Rekod tidak wujud.');if($before&&$before['category']!==$category)throw new \InvalidArgumentException('Kategori rekod tidak boleh ditukar.');$active=(string)($d['is_active']??'1')==='1'?1:0;if($id)Database::query($p,'UPDATE master_data SET code=?,name=?,organisation_id=?,parent_id=?,is_active=? WHERE id=?',[$code,$name,$org,$parent,$active,$id]);else{Database::query($p,'INSERT INTO master_data(category,code,name,organisation_id,parent_id,is_active) VALUES(?,?,?,?,?,?)',[$category,$code,$name,$org,$parent,$active]);$id=(int)$p->lastInsertId();}Audit::record($p,'master.saved',$actor,$org,(string)$id,'core',null,$before?:null,['category'=>$category,'code'=>$code,'name'=>$name,'is_active'=>$active]);return $id;});
    }
}
