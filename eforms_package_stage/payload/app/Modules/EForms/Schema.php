<?php
declare(strict_types=1);
namespace EPejabat\Modules\EForms;
use EPejabat\Core\{Core,Database};
require_once __DIR__.'/Visual.php';require_once __DIR__.'/VisualWeb.php';require_once __DIR__.'/Packages.php';require_once __DIR__.'/PackageWeb.php';
final class Schema {
 const TYPES=['text','textarea','number','date','time','email','tel','dropdown','searchable','radio','checkbox','multiselect','file','table','signature','organisation','staff','section','pagebreak'];
 public static function definition(array $rows):array {
  if(!$rows||count($rows)>150)throw new \InvalidArgumentException('1–150 medan diperlukan.');$out=[];$seen=[];$section=null;
  foreach($rows as $r){if(!is_array($r))throw new \InvalidArgumentException('Medan tidak sah.');$key=Core::text($r['key']??'',40);$type=$r['type']??'';
   if(!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/',$key)||isset($seen[$key])||!in_array($type,self::TYPES,true))throw new \InvalidArgumentException('Kod/type medan tidak sah atau duplicate.');
   $c=$r['condition']??null;if($c!==null){if(!is_array($c)||!isset($seen[$c['field']??''])||!is_scalar($c['equals']??null)||in_array($seen[$c['field']],['file','section','pagebreak','table','multiselect'],true))throw new \InvalidArgumentException('Syarat mesti merujuk medan scalar terdahulu.');$c=['field'=>$c['field'],'equals'=>Core::text((string)$c['equals'],1000,false)];}
   if(in_array($type,['section','pagebreak'],true))$section=$c;elseif($section){if($c!==null&&$c!==$section)throw new \InvalidArgumentException('Gunakan syarat section pada medan dalam section tersebut.');$c=$section;}
   $opts=$r['options']??[];if(!is_array($opts)||count($opts)>100)throw new \InvalidArgumentException('Pilihan tidak sah.');$opts=array_values(array_unique(array_map(fn($o)=>Core::text($o,100),$opts)));if(in_array($type,['dropdown','radio','searchable','multiselect'],true)&&!$opts)throw new \InvalidArgumentException('Pilihan diperlukan.');
   $columns=$r['columns']??[];if(!is_array($columns)||count($columns)>8)throw new \InvalidArgumentException('Maksimum 8 lajur jadual.');$columns=array_values(array_unique(array_map(fn($x)=>Core::text($x,80),$columns)));if($type==='table'&&!$columns)throw new \InvalidArgumentException('Lajur jadual diperlukan.');
   $min=$r['min']??null;$max=$r['max']??null;foreach([$min,$max] as $n)if($n!==null&&$n!==''&&(!is_numeric($n)||!is_finite((float)$n)))throw new \InvalidArgumentException('Had nombor tidak sah.');$min=$min===''||$min===null?null:(float)$min;$max=$max===''||$max===null?null:(float)$max;if($min!==null&&$max!==null&&$min>$max)throw new \InvalidArgumentException('Min melebihi max.');
   $auto=$r['autofill']??'';if(!in_array($auto,['','full_name','email','staff_id','organisation','organisation_id'],true))throw new \InvalidArgumentException('Autofill tidak dibenarkan.');
   $out[]=['key'=>$key,'label'=>Core::text($r['label']??'',190),'type'=>$type,'required'=>!empty($r['required']),'options'=>$opts,'condition'=>$c,'placeholder'=>Core::text($r['placeholder']??'',190,false),'help'=>Core::text($r['help']??'',1000,false),'columns'=>$columns,'max_rows'=>max(1,min(30,(int)($r['max_rows']??10))),'min'=>$min,'max'=>$max,'max_length'=>max(1,min(10000,(int)($r['max_length']??($type==='textarea'?10000:1000)))),'autofill'=>$auto,'confidence'=>max(0,min(1,(float)($r['confidence']??1)))];$seen[$key]=$type;
  }return $out;
 }
 public static function visible(array $f,array $v):bool {$c=$f['condition']??null;return !$c||(is_scalar($v[$c['field']]??'')&&(string)($v[$c['field']]??'')===$c['equals']);}
 public static function values(\PDO $p,array $fields,array $raw,int $org,bool $complete,array $files=[]):array {
  $out=[];foreach($fields as $f){if(!self::visible($f,$out)||in_array($f['type'],['section','pagebreak'],true))continue;$k=$f['key'];$type=$f['type'];
   if($type==='file'){if($complete&&$f['required']&&empty($files[$k]))throw new \InvalidArgumentException('Lampiran diperlukan: '.$f['label']);continue;}
   $v=$raw[$k]??(in_array($type,['checkbox','signature'],true)?'0':(in_array($type,['table','multiselect'],true)?[]:''));
   if(in_array($type,['table','multiselect'],true)){
    if(!is_array($v))throw new \InvalidArgumentException('Senarai tidak sah.');
    if($type==='multiselect'){if(count($v)>100)throw new \InvalidArgumentException('Terlalu banyak pilihan.');$v=array_values(array_unique(array_map(fn($x)=>Core::text($x,100),$v)));foreach($v as $x)if(!in_array($x,$f['options'],true))throw new \InvalidArgumentException('Pilihan tidak sah.');}
    else{if(count($v)>($f['max_rows']??10))throw new \InvalidArgumentException('Terlalu banyak baris.');$clean=[];foreach($v as $row){if(!is_array($row)||count($row)>count($f['columns']))throw new \InvalidArgumentException('Jadual tidak sah.');$r=[];foreach($f['columns'] as $i=>$label)$r[]=Core::text($row[$i]??'',1000,false);if(implode('',$r)!=='')$clean[]=$r;}$v=$clean;}
    if($complete&&$f['required']&&!$v)throw new \InvalidArgumentException('Medan diperlukan: '.$f['label']);$out[$k]=$v;continue;
   }
   if(!is_scalar($v))throw new \InvalidArgumentException('Nilai tidak sah.');$v=trim((string)$v);
   if($complete&&$f['required']&&($v===''||(in_array($type,['checkbox','signature'],true)&&$v!=='1')))throw new \InvalidArgumentException('Medan diperlukan: '.$f['label']);
   if($v===''){$out[$k]='';continue;}if(mb_strlen($v)>($f['max_length']??($type==='textarea'?10000:1000))||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new \InvalidArgumentException('Nilai terlalu panjang/tidak sah.');
   switch($type){case 'number':if(!preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/',$v)||!is_finite((float)$v)||(($f['min']??null)!==null&&(float)$v<$f['min'])||(($f['max']??null)!==null&&(float)$v>$f['max']))throw new \InvalidArgumentException('Nombor di luar julat.');break;
    case 'email':if(!filter_var($v,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('E-mel tidak sah.');break;
    case 'tel':if(!preg_match('/\A[+0-9() .-]{5,30}\z/',$v))throw new \InvalidArgumentException('Telefon tidak sah.');break;
    case 'date':$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new \InvalidArgumentException('Tarikh tidak sah.');break;
    case 'time':if(!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/',$v))throw new \InvalidArgumentException('Masa tidak sah.');break;
    case 'checkbox':case 'signature':if(!in_array($v,['0','1'],true))throw new \InvalidArgumentException('Perakuan tidak sah.');break;
    case 'dropdown':case 'searchable':case 'radio':if(!in_array($v,$f['options'],true))throw new \InvalidArgumentException('Pilihan tidak sah.');break;
    case 'organisation':$id=Core::id($v);if(!Database::query($p,'SELECT 1 FROM organisations o JOIN organisation_hierarchy h ON h.descendant_id=o.id WHERE h.ancestor_id=? AND o.id=? AND o.is_active=1',[$org,$id])->fetchColumn())throw new \RuntimeException('Organisasi di luar skop borang.');break;
    case 'staff':$id=Core::id($v);if(!Database::query($p,'SELECT 1 FROM staff_profiles s JOIN organisation_hierarchy h ON h.descendant_id=s.organisation_id WHERE h.ancestor_id=? AND s.staff_identity_id=?',[$org,$id])->fetchColumn())throw new \RuntimeException('Staf di luar skop borang.');break;
   }$out[$k]=$v;
  }return $out;
 }
}
