import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import {effectiveFields,previewState,previewErrors,moveFields,setFieldCondition,overlay} from '../payload/assets/eforms-engine.mjs';
import {PDFDocument} from '../payload/assets/eforms-vendor/pdf-lib.mjs';
import * as pdfjs from '../../phase2b/deps/pdfjs-dist/package/legacy/build/pdf.mjs';
const results=[];
async function test(name,run){try{await run();results.push({test:name,status:'PASS'});}catch(e){results.push({test:name,status:'FAIL',error:e.message});}}
const f=(key,type='text',condition=null)=>({key,label:key,type,condition,required:false});
const fields=[f('travel'),f('details','section',{field:'travel',equals:'car'}),{...f('vehicle'),required:true},f('next','pagebreak'),f('remarks')];
await test('Section condition inherited before first save',()=>assert.deepEqual(effectiveFields(fields)[2].condition,fields[1].condition));
await test('Page break clears inherited condition',()=>assert.equal(effectiveFields(fields)[4].condition,null));
await test('Hidden section child excluded from preview payload',()=>{const p=previewState(fields,{travel:'bus',vehicle:'SECRET',remarks:'ok'});assert.equal(p.visibility.vehicle,false);assert.equal(p.values.vehicle,undefined);assert.equal(p.values.remarks,'ok');});
await test('Visible required section child checked',()=>assert.equal(previewErrors(fields,{travel:'car'}).length,1));
await test('Hidden required child does not block preview',()=>assert.equal(previewErrors(fields,{travel:'bus'}).length,0));
await test('Chained hidden values cannot reveal dependent field',()=>{const ff=[f('first'),f('second','text',{field:'first',equals:'yes'}),f('third','text',{field:'second',equals:'stale'})];const r=previewState(ff,{first:'no',second:'stale',third:'SECRET'});assert.equal(r.visibility.third,false);assert.equal(r.values.third,undefined);});
await test('Conflicting section and child conditions rejected',()=>assert.throws(()=>effectiveFields([f('travel'),f('details','section',{field:'travel',equals:'car'}),f('vehicle','text',{field:'travel',equals:'bus'})])));
await test('Forward condition reference rejected before save',()=>assert.throws(()=>effectiveFields([f('one','text',{field:'two',equals:'x'}),f('two')])));
await test('Required empty repeat table rejected',()=>assert.equal(previewErrors([{...f('rows','table'),required:true,max_rows:2}],{rows:[['','']]}).length,1));
await test('Repeat table limit checked',()=>assert.equal(previewErrors([{...f('rows','table'),max_rows:1}],{rows:[['a'],['b']]}).length,1));
await test('Required multiselect checked',()=>assert.equal(previewErrors([{...f('choices','multiselect'),required:true,options:['A']}],{choices:[]}).length,1));
await test('Unlisted multiselect option rejected',()=>assert.equal(previewErrors([{...f('choices','multiselect'),options:['A']}],{choices:['forged']}).length,1));
await test('Unchecked signature rejected when required',()=>assert.equal(previewErrors([{...f('ack','signature'),required:true}],{ack:'0'}).length,1));
await test('Telephone format preview validation',()=>assert.equal(previewErrors([f('tel','tel')],{tel:'wrong'}).length,1));
const groups=[f('s1','section'),f('a'),f('b'),f('s2','section'),f('c')];
await test('Down arrow moves entire section after next section',()=>assert.deepEqual(moveFields(groups,0,1).map(x=>x.key),['s2','c','s1','a','b']));
await test('Last section down is safe no-op',()=>assert.equal(moveFields(groups,3,4),groups));
await test('Negative drag index rejected without mutation',()=>assert.equal(moveFields(groups,-1,2),groups));
await test('Out of range drag source rejected',()=>assert.equal(moveFields(groups,99,0),groups));
await test('Scalar reorder preserves values',()=>assert.deepEqual(moveFields([f('a'),f('b'),f('c')],0,1).map(x=>x.key),['b','a','c']));
await test('Reorder cannot make dependency forward reference',()=>assert.throws(()=>moveFields([f('a'),f('b','text',{field:'a',equals:'yes'})],0,1)));
await test('Changing saved section condition updates inherited children',()=>{const ff=effectiveFields(fields);setFieldCondition(ff,1,{field:'travel',equals:'bus'});assert.equal(effectiveFields(ff)[2].condition.equals,'bus');});
await test('Removing section condition clears inherited children',()=>{const ff=effectiveFields(fields);setFieldCondition(ff,1,null);assert.equal(effectiveFields(ff)[2].condition,null);});
await test('Searchable input does not accept value outside options',()=>assert.equal(previewErrors([{...f('choice','searchable'),options:['Valid']}],{choice:'Forged'}).length,1));
// Execute the changed overlay against a fresh in-memory original. No production writes.
const doc=await PDFDocument.create();doc.addPage([300,300]);const bytes=await doc.save();const baseMap={page:1,x:20,y:30,width:200,height:20,fontSize:10,wrap:false,align:'left'};
const definition={pages:[{width:300,height:300}],maps:[{...baseMap,key:'vehicle'},{...baseMap,key:'remarks',y:60},{...baseMap,key:'@approved_by',y:90}]};
async function textOf(data){const p=await pdfjs.getDocument({data:new Uint8Array(data),isEvalSupported:false,useWasm:false}).promise;const page=await p.getPage(1);const text=(await page.getTextContent()).items.map(i=>i.str).join(' ');await p.cleanup();return text;}
await test('PDF overlay omits hidden section data and unapproved certification',async()=>{const out=await overlay(bytes,definition,fields,{travel:'bus',vehicle:'SECRET',remarks:'PUBLIC', '@approved_by':'APPROVER'});const text=await textOf(out.bytes);assert.match(text,/PUBLIC/);assert.doesNotMatch(text,/SECRET|APPROVER/);});
await test('PDF overlay includes visible section and authorised certification',async()=>{const out=await overlay(bytes,definition,fields,{travel:'car',vehicle:'VEHICLE',remarks:'PUBLIC','@approved_by':'APPROVER'},{approved:true});const text=await textOf(out.bytes);assert.match(text,/VEHICLE/);assert.match(text,/APPROVER/);});
// Lampiran A: same reviewed definition, exercise both conditional branches without overwriting approved sample artifacts.
const lamp=JSON.parse(fs.readFileSync('phase2b/samples/Lampiran_A_Reviewed_Template.json'));const original=new Uint8Array(fs.readFileSync('phase2b/samples/Lampiran_A_Blank.pdf'));
await test('Lampiran A revised overlay preserves both page dimensions',async()=>{const out=await overlay(original,lamp.definition,lamp.fields,{});const p=await PDFDocument.load(out.bytes);assert.equal(p.getPageCount(),2);for(const page of p.getPages())assert.deepEqual(page.getSize(),{width:612,height:792});});
const hash=p=>crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
const out={kind:'Targeted JavaScript model and PDF integration; NOT browser visual testing',results,pass:results.filter(r=>r.status==='PASS').length,fail:results.filter(r=>r.status==='FAIL').length,source_hashes:Object.fromEntries(['eforms-engine.mjs','eforms-visual.mjs'].map(n=>[n,hash('phase2c/payload/assets/'+n)]))};
fs.writeFileSync('phase2c/reports/editor-completion-results.json',JSON.stringify(out,null,2));console.log(JSON.stringify(out,null,2));if(out.fail)process.exitCode=1;
