<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Organisations {
    public static function create(\PDO $p,int $actor,string $code,string $name,string $type,?int $parent=null): int {
        $tid=Database::query($p,"SELECT id FROM master_data WHERE category='organisation_type' AND code=? AND is_active=1",[$type])->fetchColumn();
        return self::save($p,$actor,['code'=>$code,'name'=>$name,'type_id'=>$tid,'parent_id'=>$parent,'reason'=>'Create organisation']);
    }
    public static function save(\PDO $p,int $actor,array $d,?int $id=null): int {
        $parent=Core::id($d['parent_id']??null,true);$type=MasterData::validate($p,Core::id($d['type_id']??''),'organisation_type');$name=Core::text($d['name']??'');$code=Core::text($d['code']??'',50,false);if($code!==''&&!preg_match('/\A[A-Za-z0-9_-]{2,50}\z/',$code))throw new \InvalidArgumentException('Kod organisasi tidak sah.');$reason=Core::text($d['reason']??'',500);$state=MasterData::validate($p,Core::id($d['state_id']??null,true),'state');$email=Core::text($d['email']??'',190,false);if($email!=='')$email=Core::email($email);
        if((int)Database::query($p,"SELECT GET_LOCK(CONCAT('ep_org_',LEFT(SHA2(DATABASE(),256),40)),5)")->fetchColumn()!==1)throw new \RuntimeException('Cuba semula.');
        try{return Core::tx($p,function()use($p,$actor,$d,$id,$parent,$type,$name,$code,$reason,$state,$email){
            $before=$id?Database::query($p,'SELECT * FROM organisations WHERE id=? FOR UPDATE',[$id])->fetch():null;
            if($id&&!$before)throw new \InvalidArgumentException('Organisasi tidak wujud.');Authorization::require($p,$actor,'core.organisations.manage',$id??$parent);$parentChanged=!$before||($before['parent_id']===null?null:(int)$before['parent_id'])!==$parent;if($parentChanged)Authorization::require($p,$actor,'core.organisations.manage',$parent);
            if($parentChanged&&$parent!==null&&!Database::query($p,'SELECT 1 FROM organisations WHERE id=? AND is_active=1',[$parent])->fetchColumn())throw new \InvalidArgumentException('Parent tidak aktif.');
            if($id&&($parent===$id||($parent!==null&&Database::query($p,'SELECT 1 FROM organisation_hierarchy WHERE ancestor_id=? AND descendant_id=?',[$id,$parent])->fetchColumn())))throw new \InvalidArgumentException('Hierarchy mempunyai kitaran.');
            $typeCode=Database::query($p,'SELECT code FROM master_data WHERE id=?',[$type])->fetchColumn();$values=[$code?:null,$name,$typeCode,$parent,$type,$state,Core::text($d['address']??'',1000,false),$email?:null,Core::text($d['phone']??'',30,false),(string)($d['is_active']??'1')==='1'?1:0];
            if($id)Database::query($p,'UPDATE organisations SET code=?,name=?,type=?,parent_id=?,type_id=?,state_id=?,address=?,email=?,phone=?,is_active=? WHERE id=?',[...$values,$id]);else{Database::query($p,'INSERT INTO organisations(code,name,type,parent_id,type_id,state_id,address,email,phone,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)',$values);$id=(int)$p->lastInsertId();}
            self::rebuild($p);$after=Database::query($p,'SELECT * FROM organisations WHERE id=?',[$id])->fetch();Database::query($p,'INSERT INTO organisation_change_history(organisation_id,actor_id,reason,before_json,after_json) VALUES(?,?,?,?,?)',[$id,$actor,$reason,json_encode($before?:[],JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);Audit::record($p,'organisation.saved',$actor,$id,(string)$id,'core',$reason,$before?:null,$after);return $id;
        });}finally{Database::query($p,"SELECT RELEASE_LOCK(CONCAT('ep_org_',LEFT(SHA2(DATABASE(),256),40)))");}
    }
    private static function rebuild(\PDO $p): void {
        $rows=$p->query('SELECT id,parent_id FROM organisations')->fetchAll();if(count($rows)>10000)throw new \RuntimeException('Hierarchy terlalu besar; hubungi pentadbir.');$map=[];foreach($rows as $r)$map[(int)$r['id']]=$r['parent_id']===null?null:(int)$r['parent_id'];$closure=[];
        foreach($map as $id=>$parent){$seen=[];$cur=$id;$depth=0;while($cur!==null){if(isset($seen[$cur])||!array_key_exists($cur,$map))throw new \RuntimeException('Hierarchy tidak sah.');$seen[$cur]=true;$closure[]=[$cur,$id,$depth++];$cur=$map[$cur];}}
        $p->exec('DELETE FROM organisation_hierarchy');foreach($closure as $r)Database::query($p,'INSERT INTO organisation_hierarchy(ancestor_id,descendant_id,depth) VALUES(?,?,?)',$r);
    }
}
