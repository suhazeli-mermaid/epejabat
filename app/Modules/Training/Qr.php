<?php
declare(strict_types=1);
namespace EPejabat\Modules\Training;
/** ISO/IEC QR byte mode, versions 1–6, level L, valid fixed mask 0. No external service. */
final class Qr {
 private static function mul(int $a,int $b):int {$r=0;for($i=7;$i>=0;$i--){$r=($r<<1)^(($r>>7)*0x11d);$r^=(($b>>$i)&1)*$a;}return $r;}
 public static function matrix(string $text):array {
  if(!preg_match('/\A[\x20-\x7e]+\z/',$text))throw new \InvalidArgumentException('QR URL mesti ASCII.');$data=[1=>19,34,55,80,108,136];$ecc=[1=>7,10,15,20,26,18];$v=1;while($v<=6&&strlen($text)>$data[$v]-2)$v++;if($v>6)throw new \InvalidArgumentException('URL terlalu panjang untuk QR; gunakan pautan pendek.');$bits='0100'.str_pad(decbin(strlen($text)),8,'0',STR_PAD_LEFT);foreach(unpack('C*',$text) as $byte)$bits.=str_pad(decbin($byte),8,'0',STR_PAD_LEFT);$bits.=str_repeat('0',min(4,$data[$v]*8-strlen($bits)));$bits.=str_repeat('0',(8-strlen($bits)%8)%8);$bytes=[];for($i=0;$i<strlen($bits);$i+=8)$bytes[]=bindec(substr($bits,$i,8));$pad=0;while(count($bytes)<$data[$v])$bytes[]=$pad++%2?0x11:0xec;
  // Version 6-L uses two equal blocks of 68 data / 18 parity codewords.
  $ec=$ecc[$v];$poly=array_fill(0,$ec,0);$poly[$ec-1]=1;$root=1;for($i=0;$i<$ec;$i++){for($j=0;$j<$ec;$j++){$poly[$j]=self::mul($poly[$j],$root);if($j+1<$ec)$poly[$j]^=$poly[$j+1];}$root=self::mul($root,2);}
  $parity=static function(array $data)use($ec,$poly):array {$rem=array_fill(0,$ec,0);foreach($data as $b){$factor=$b^array_shift($rem);$rem[]=0;foreach($rem as $j=>&$x)$x^=self::mul($poly[$j],$factor);unset($x);}return $rem;};
  if($v===6){$blocks=array_chunk($bytes,68);$checks=array_map($parity,$blocks);$interleaved=[];for($i=0;$i<68;$i++)foreach($blocks as $block)$interleaved[]=$block[$i];for($i=0;$i<$ec;$i++)foreach($checks as $check)$interleaved[]=$check[$i];$bytes=$interleaved;}else $bytes=[...$bytes,...$parity($bytes)];
  $n=17+4*$v;$m=array_fill(0,$n,array_fill(0,$n,false));$f=$m;$set=static function(int $x,int $y,bool $dark)use(&$m,&$f,$n){if($x>=0&&$y>=0&&$x<$n&&$y<$n){$m[$y][$x]=$dark;$f[$y][$x]=true;}};
  for($i=0;$i<$n;$i++){$set(6,$i,$i%2===0);$set($i,6,$i%2===0);}foreach([[3,3],[$n-4,3],[3,$n-4]] as [$cx,$cy])for($dy=-4;$dy<=4;$dy++)for($dx=-4;$dx<=4;$dx++){$d=max(abs($dx),abs($dy));$set($cx+$dx,$cy+$dy,$d!==2&&$d!==4);}
  if($v>1){$c=$n-7;for($dy=-2;$dy<=2;$dy++)for($dx=-2;$dx<=2;$dx++)$set($c+$dx,$c+$dy,max(abs($dx),abs($dy))!==1);}
  $format=0x77c4;$bit=static fn($i)=>(($format>>$i)&1)!==0;for($i=0;$i<=5;$i++)$set(8,$i,$bit($i));$set(8,7,$bit(6));$set(8,8,$bit(7));$set(7,8,$bit(8));for($i=9;$i<15;$i++)$set(14-$i,8,$bit($i));for($i=0;$i<8;$i++)$set($n-1-$i,8,$bit($i));for($i=8;$i<15;$i++)$set(8,$n-15+$i,$bit($i));$set(8,$n-8,true);
  $stream='';foreach($bytes as $b)$stream.=str_pad(decbin($b),8,'0',STR_PAD_LEFT);$pos=0;for($right=$n-1;$right>=1;$right-=2){if($right===6)$right=5;for($vert=0;$vert<$n;$vert++){$y=(($right+1)&2)===0?$n-1-$vert:$vert;for($j=0;$j<2;$j++){$x=$right-$j;if(!$f[$y][$x]){$dark=$pos<strlen($stream)&&$stream[$pos]==='1';$pos++;$m[$y][$x]=$dark^(($x+$y)%2===0);}}}}return $m;
 }
 public static function svg(string $text):string {$m=self::matrix($text);$n=count($m)+8;$s='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$n.' '.$n.'" width="320" height="320" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="white"/><g fill="black">';foreach($m as $y=>$row)foreach($row as $x=>$b)if($b)$s.='<rect x="'.($x+4).'" y="'.($y+4).'" width="1" height="1"/>';return $s.'</g></svg>';}
}
