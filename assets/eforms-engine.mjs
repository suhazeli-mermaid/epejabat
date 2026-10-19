/** Pure local recognition and original-document overlay. No network AI calls here. */
import {PDFDocument,StandardFonts,rgb,degrees} from './eforms-vendor/pdf-lib.mjs';
export const limits={bytes:10485760,pages:30,fields:150,maps:500};
export const fieldTypes=['text','textarea','number','date','time','email','tel','dropdown','searchable','radio','checkbox','multiselect','file','table','signature','organisation','staff','section','pagebreak'];
export function visible(f,v){return !f.condition||String(v[f.condition.field]??'')===String(f.condition.equals);}
// Mirror section inheritance and scalar dependency ordering used by Schema.php.
// Used by the editor before save and by previews; the server remains authoritative.
export function effectiveFields(fields){
 const seen=new Map();let section=null;
 return fields.map(f=>{let c=f.condition||null;if(c&&(!seen.has(c.field)||['file','section','pagebreak','table','multiselect'].includes(seen.get(c.field))))throw Error('Syarat '+f.label+' mesti merujuk medan scalar terdahulu.');
  if(['section','pagebreak'].includes(f.type))section=c;
  else if(section){if(c&&(c.field!==section.field||String(c.equals)!==String(section.equals)))throw Error('Gunakan syarat section pada '+f.label+'.');c=section;}
  seen.set(f.key,f.type);return {...f,condition:c};
 });
}
export function previewState(fields,raw){
 const values={},visibility={};for(const f of effectiveFields(fields)){visibility[f.key]=visible(f,values);if(!visibility[f.key]||['section','pagebreak'].includes(f.type))continue;values[f.key]=raw[f.key]??(['checkbox','signature'].includes(f.type)?'0':['table','multiselect'].includes(f.type)?[]:'');}
 return {values,visibility};
}
export function previewErrors(fields,raw){
 const {values,visibility}=previewState(fields,raw),errors=[];
 for(const f of fields){if(!visibility[f.key]||['section','pagebreak'].includes(f.type))continue;const v=values[f.key];
  const empty=Array.isArray(v)?!v.some(x=>Array.isArray(x)?x.some(c=>String(c).trim()):String(x).trim()):String(v??'').trim()==='';
  if(f.required&&(empty||(['checkbox','signature'].includes(f.type)&&String(v)!=='1')))errors.push('Medan diperlukan: '+f.label);
  if(f.type==='table'&&Array.isArray(v)&&v.length>(f.max_rows||10))errors.push('Terlalu banyak baris: '+f.label);
  if(f.type==='multiselect'&&Array.isArray(v)&&v.some(x=>!(f.options||[]).includes(x)))errors.push('Pilihan tidak sah: '+f.label);
  if(['dropdown','searchable','radio'].includes(f.type)&&!empty&&!(f.options||[]).includes(String(v)))errors.push('Pilihan tidak sah: '+f.label);
  if(f.type==='tel'&&!empty&&!/^[+0-9() .-]{5,30}$/.test(String(v)))errors.push('Telefon tidak sah: '+f.label);
 }return errors;
}
export function moveFields(fields,from,to){
 if(!Number.isInteger(from)||!Number.isInteger(to)||from<0||from>=fields.length||to<0||to>=fields.length||from===to)return fields;
 let end=from+1;if(fields[from].type==='section')while(end<fields.length&&!['section','pagebreak'].includes(fields[end].type))end++;
 // Adjacent down means after the next whole section, not inside the moving group.
 if(to===from+1){to=end;if(to>=fields.length)return fields;if(fields[to].type==='section'){to++;while(to<fields.length&&!['section','pagebreak'].includes(fields[to].type))to++;}else to++;}
 else if(to>from&&to<end)return fields;
 else if(to>from)to++;
 const result=fields.slice(),block=result.splice(from,end-from);result.splice(to>from?to-block.length:to,0,...block);effectiveFields(result);return result;
}
export function setFieldCondition(fields,index,condition){
 const f=fields[index],old=f.condition||null;f.condition=condition;
 if(['section','pagebreak'].includes(f.type))for(let i=index+1;i<fields.length&&!['section','pagebreak'].includes(fields[i].type);i++){
  const c=fields[i].condition;if(!c||(old&&c.field===old.field&&String(c.equals)===String(old.equals)))fields[i].condition=condition?{...condition}:null;
 }
}
export function valueText(v){return Array.isArray(v)?v.map(x=>Array.isArray(x)?x.join(' | '):x).join('\n'):String(v??'');}
export function recognition(pageData){
 const fields=[],maps=[],warnings=[],text=[];let title='Borang baharu';
 function add(label,type,page,x,y,w,h,confidence,extra={}){label=label.replace(/^[\d.\s]+/,'').replace(/[:._\s]+$/,'').trim().slice(0,190);if(!label||fields.length>=limits.fields)return;const key='field_'+(fields.length+1);fields.push({key,label,type,required:false,options:[],condition:null,help:'Cadangan setempat — semak label, jenis dan kedudukan.',confidence,...extra});maps.push({key,page,x:Math.max(0,x),y:Math.max(0,y),width:Math.max(6,Math.min(w,pageData[page-1].width-Math.max(0,x))),height:Math.max(6,Math.min(h,pageData[page-1].height-Math.max(0,y))),fontSize:10,align:'left',wrap:type==='textarea'||type==='table',markValue:'',confirmed:false});}
 for(let pi=0;pi<pageData.length;pi++){
  const p=pageData[pi];text.push(p.items.map(i=>i.str).join(' '));const groups=[];
  for(const i of p.items){if(!i.str?.trim())continue;let g=groups.find(g=>Math.abs(g.y-i.y)<4);if(!g){g={y:i.y,items:[]};groups.push(g);}g.items.push(i);}
  groups.sort((a,b)=>a.y-b.y);const lines=groups.map(g=>({y:g.y,items:g.items.sort((a,b)=>a.x-b.x)}));
  if(pi===0){const l=lines.find(l=>/borang|permohonan|application|request/i.test(l.items.map(i=>i.str).join(' ')));if(l)title=l.items.map(i=>i.str).join(' ').slice(0,190);}
  // AcroForm field metadata takes priority. Values are deliberately ignored.
  if(p.annotations?.length){for(const a of p.annotations){const type=a.checkBox?'checkbox':a.radioButton?'radio':a.fieldType==='Ch'?'dropdown':'text';const opts=(a.options||[]).map(x=>x.displayValue||x.exportValue||String(x));add(a.alternativeText||a.fieldName||'Medan PDF',type,pi+1,a.x,a.y,a.width,a.height,.9,{options:opts.length?opts:(type==='radio'?['Pilihan']:[])});}continue;}
  for(const l of lines){const line=l.items.map(i=>i.str).join(' ');if(!line.trim())continue;
   const blanks=[...line.matchAll(/(?:\.{4,}|_{3,}|…{2,}|[\.\s]{8,})/g)];
   if(blanks.length){let from=0;for(const b of blanks){let before=line.slice(from,b.index).trim();from=b.index+b[0].length;if(!before)continue;const first=l.items[0];const ratio=b.index/Math.max(1,line.length);const extent=Math.max(...l.items.map(i=>i.x+i.width))-first.x;const x=first.x+extent*ratio;const w=Math.max(40,extent*b[0].length/line.length);let type=/tarikh|date|mulai|hingga/i.test(before)?'date':/e-?mel|email/i.test(before)?'email':/telefon|phone/i.test(before)?'tel':/tandatangan|signature/i.test(before)?'signature':'text';add(before,type,pi+1,x,l.y,Math.min(w,p.width-x-10),15,.48);}}
   else if(/^[0-9]+[.)]\s/.test(line)&&line.includes(':')){const parts=line.split(':');add(parts[0],line.length>70?'textarea':'text',pi+1,l.items[0].x,l.y+16,p.width-l.items[0].x-25,line.length>70?35:16,.4);}
   if(/[□▢☐]/u.test(line)){for(const i of l.items){const m=i.str.match(/[□▢☐]\s*(.+)/u);if(m)add(m[1],'checkbox',pi+1,i.x,l.y,12,12,.6);}}
   // Widely spaced short uppercase headings suggest a table, not proven semantics.
   const heads=l.items.filter(i=>i.str.trim().length>2&&i.str.trim().length<35&&i.str===i.str.toUpperCase()&&/[A-Z]/.test(i.str));if(heads.length>=3&&heads.length<=8&&!/[.:]/.test(line)){add('Jadual: '+heads.map(i=>i.str).join(' / '),'table',pi+1,heads[0].x,l.y+18,p.width-heads[0].x-30,60,.35,{columns:heads.map(i=>i.str.trim()),max_rows:5});}
  }
  if(p.items.reduce((n,i)=>n+i.str.trim().length,0)<10)warnings.push(`Halaman ${pi+1}: tiada teks. OCR tidak tersedia secara setempat; tambah medan dan pemetaan manual.`);
 }
 warnings.push('Pengecaman ialah cadangan berasaskan peraturan. Medan inline, jadual, syarat, perakuan dan kuasa approval mesti disemak Admin. Nilai yang sudah diisi tidak disalin.');
 return {title,fields,maps,warnings,text:text.join('\n'),pages:pageData.map(p=>({width:p.width,height:p.height}))};
}
export function validateMaps(def,fields){
 const warnings=[];for(const [i,m] of (def.maps||[]).entries()){const p=def.pages?.[m.page-1];if(!p||![m.x,m.y,m.width,m.height,m.fontSize].every(Number.isFinite)||m.x<0||m.y<0||m.width<4||m.height<4||m.x+m.width>p.width+.01||m.y+m.height>p.height+.01)warnings.push(`Pemetaan ${i+1}: koordinat tidak sah.`);
  for(const n of (def.maps||[]).slice(0,i))if(n.page===m.page&&n.x<m.x+m.width&&n.x+n.width>m.x&&n.y<m.y+m.height&&n.y+n.height>m.y)warnings.push(`Bertindih: ${n.key} / ${m.key}.`);
 }
 for(const f of fields)if(f.required&&!['file','section','pagebreak'].includes(f.type)&&!def.maps?.some(m=>m.key===f.key))warnings.push(`Medan wajib belum dipetakan: ${f.label}`);return [...new Set(warnings)];
}
export async function sha256(bytes){return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(x=>x.toString(16).padStart(2,'0')).join('');}
export async function overlay(original,def,fields,values,{approved=false,watermark=false}={}){
 fields=effectiveFields(fields);values={...Object.fromEntries(Object.entries(values).filter(([k])=>k.startsWith('@'))),...previewState(fields,values).values};
 const doc=await PDFDocument.load(original,{ignoreEncryption:false,updateMetadata:false});const pages=doc.getPages();if(pages.length!==def.pages.length||pages.length>limits.pages)throw Error('Bilangan halaman tidak sepadan.');
 const form=doc.getForm();if(form.getFields().length)form.flatten();const font=await doc.embedFont(StandardFonts.Helvetica);const warnings=validateMaps(def,fields),entries=[];
 for(const m of def.maps){const page=pages[m.page-1];if(!page)throw Error('Halaman tidak sah.');const rotation=((page.getRotation().angle%360)+360)%360;const size=page.getCropBox();const vw=rotation%180?size.height:size.width,vh=rotation%180?size.width:size.height;if(Math.abs(vw-def.pages[m.page-1].width)>1||Math.abs(vh-def.pages[m.page-1].height)>1)throw Error('Dimensi PDF berubah.');
  if(m.key.startsWith('@approved')||m.key.startsWith('@step_')||m.key==='@approval_remarks'){if(!approved)continue;}
  const f=fields.find(f=>f.key===m.key);if(f&&!visible(f,values))continue;
  let val=values[m.key];if(m.markValue)val=Array.isArray(val)?(val.includes(m.markValue)?'X':''):(String(val??'')===m.markValue?'X':'');else if(f&&['checkbox','signature'].includes(f.type))val=String(val)==='1'?'X':'';
  if(f?.type==='table'&&Number(m.tableColumn)>=0&&Array.isArray(val))val=val.map(row=>String(row[Number(m.tableColumn)]??''));let text=valueText(val);if(!text)continue;
  try{font.encodeText(text);}catch{warnings.push(`${m.key}: aksara tidak disokong Helvetica; ubah font/teks diperlukan.`);continue;}
  let lines=[];for(const para of text.split('\n')){if(!m.wrap){lines.push(para);continue;}let line='';for(const word of para.split(/\s+/)){const next=line?line+' '+word:word;if(font.widthOfTextAtSize(next,m.fontSize)>m.width&&line){lines.push(line);line=word;}else line=next;}lines.push(line);}
  if(lines.some(l=>font.widthOfTextAtSize(l,m.fontSize)>m.width+.01)||lines.length*(f?.type==='table'?Number(m.rowHeight||14):m.fontSize*1.2)>m.height+.1){warnings.push(`${m.key}: teks melebihi kotak; cetakan dihentikan sehingga pemetaan diperbetulkan.`);continue;}
  entries.push({m,page,rotation,size,lines,lineHeight:f?.type==='table'?Number(m.rowHeight||14):m.fontSize*1.2});
 }
 if(warnings.some(w=>/koordinat|melebihi|aksara tidak|belum dipetakan/.test(w)))throw Object.assign(Error(warnings.join('\n')),{warnings});
 function point(x,y,s,r){switch(r){case 90:return {x:s.x+y,y:s.y+x};case 180:return {x:s.x+s.width-x,y:s.y+y};case 270:return {x:s.x+s.width-y,y:s.y+s.height-x};default:return {x:s.x+x,y:s.y+s.height-y};}}
 for(const {m,page,rotation,size,lines,lineHeight} of entries)lines.forEach((line,i)=>{const width=font.widthOfTextAtSize(line,m.fontSize);const x=m.x+(m.align==='right'?m.width-width:m.align==='center'?(m.width-width)/2:0);page.drawText(line,{...point(x,m.y+m.fontSize+i*lineHeight,size,rotation),font,size:m.fontSize,rotate:degrees(rotation),color:rgb(0,0,0)});});
 if(watermark)for(const p of pages)p.drawText('PREVIEW - DATA SINTETIK',{x:20,y:12,font,size:8,color:rgb(.5,.1,.1)});
 return {bytes:await doc.save({useObjectStreams:true}),warnings};
}
