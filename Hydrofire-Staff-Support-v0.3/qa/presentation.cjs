const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {JSDOM,VirtualConsole}=require('jsdom');
const os=require('node:os');
const source=path.resolve(process.argv[3]||path.join(__dirname,'../support-demo'));
const root=fs.mkdtempSync(path.join(os.tmpdir(),'hf-support-qa-'));
fs.cpSync(source,root,{recursive:true,filter:p=>!p.startsWith(path.join(source,'var'))});
const php=process.argv[2]||'php';
const setup=cp.spawnSync(php,[path.join(root,'bin/setup.php')],{cwd:root,encoding:'utf8'});assert.equal(setup.status,0,setup.stderr);
const creds=Object.fromEntries([...setup.stdout.matchAll(/username: (\w+) \| password: (\w+)/g)].map(m=>[m[1],m[2]]));
let count=0;function ok(v,m){assert.ok(v,m);count++;}
const server=cp.spawn(php,['-S','127.0.0.1:8137','-t',path.join(root,'public')],{cwd:root,stdio:['ignore','pipe','pipe']});let log='';server.stderr.on('data',b=>log+=b);server.stdout.on('data',b=>log+=b);
const base='http://127.0.0.1:8137/';
async function waitFor(fn,message){for(let i=0;i<250;i++){if(await fn())return;await new Promise(r=>setTimeout(r,40));}throw new Error('Timeout: '+message);}
function client(){let cookie='',csrf='';return {get csrf(){return csrf;},set csrf(v){csrf=v;},async call(action,data=null,headers={}){const r=await fetch(base+'api.php?action='+action,{method:data?'POST':'GET',headers:{Cookie:cookie,...(data?{'Content-Type':'application/json'}:{}),...headers},body:data?JSON.stringify(data):undefined});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];let d;try{d=await r.json();}catch{throw new Error('Invalid JSON '+action);}return {status:r.status,...d};},async raw(url,opt={}){const r=await fetch(base+url,{...opt,headers:{Cookie:cookie,...opt.headers}});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];return r;},async login(name){const me=await this.call('me');const r=await this.call('login',{username:name,password:creds[name]},{'X-Demo-CSRF':me.csrf});ok(r.success,'login '+name);const meta=await this.call('support_meta');csrf=meta.csrf;return r;},async cmd(action,data){return this.call(action,{...data,request_key:crypto.randomUUID()},{'X-Support-CSRF':csrf});}};}
(async()=>{try{
 await waitFor(async()=>{try{return (await fetch(base+'index.html')).ok;}catch{return false;}},'server');
 const sales=client(),sales2=client(),stacey=client();await sales.login('sales');await sales2.login('sales2');await stacey.login('stacey');
 ok((await client().call('support_list')).status===401,'auth required');
 ok((await sales.call('support_create',{problem:'other',body:'CSRF fail'})).status===403,'CSRF required');
 const record=await sales.call('support_context&module=leads&record_id=1');ok(record.context?.verified,'real HTTP context');
 const r=await sales.cmd('support_create',{module:'leads',record_id:1,problem:'blocked',body:'HTTP test request'});ok(r.success,'create HTTP');let version=r.version,id=r.id;
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT1kAAAAASUVORK5CYII=','base64');
 async function upload(c,bytes,name,visibility,key=crypto.randomUUID(),v=version){const f=new FormData();Object.entries({id,version:v,visibility,request_key:key}).forEach(([k,x])=>f.set(k,x));f.set('file',new Blob([bytes]),name);const res=await c.raw('api.php?action=support_upload',{method:'POST',headers:{'X-Support-CSRF':c.csrf},body:f});return {status:res.status,...await res.json()};}
 const key=crypto.randomUUID(),v=version;let u=await upload(sales,png,'test.png','public',key,v);ok(u.success,'PNG upload');version=u.version;
 const same=await upload(sales,png,'test.png','public',key,v);ok(same.file_id===u.file_id,'upload retry deduplicated');
 ok((await sales2.raw('api.php?action=support_file&id='+u.file_id)).status===404,'unauthorised download');
 let file=await sales.raw('api.php?action=support_file&id='+u.file_id);ok(file.ok&&(await file.arrayBuffer()).byteLength===png.length,'authorised bytes');ok(file.headers.get('content-disposition').startsWith('attachment'),'forced download');
 ok(!(await upload(sales,Buffer.from('<?php echo 1; ?>'),'evidence.png','public')).success,'MIME validation');
 u=await upload(stacey,png,'private.png','internal');ok(u.success,'support private attachment');version=u.version;
 ok((await sales.raw('api.php?action=support_file&id='+u.file_id)).status===404,'private file denied');
 const detail=await sales.call('support_detail&id='+id);ok(detail.files.length===1,'private file excluded from detail');
 const dueDate=new Date(Date.now()+7200000+3600000).toISOString().slice(0,16);
 const ack=await stacey.cmd('support_update',{id,version,operation:'acknowledge',body:'I will check this',next_update_at:dueDate});ok(ack.success,'HTTP acknowledge');
 const worker=cp.spawnSync(php,[path.join(root,'bin/worker.php')],{cwd:root,encoding:'utf8'});ok(worker.status===0&&!worker.stdout.includes('failed":1'), 'worker executes');
 const mail=await stacey.call('demo_mail');ok(mail.mail.length>0&&mail.mail.every(m=>!m.text.includes('private.png')),'captured email content');
 // Actual JS, DOM and PHP API together. Layout is not simulated by jsdom.
 const virtual=new VirtualConsole(),errors=[];virtual.on('jsdomError',e=>errors.push(e.message));
 const dom=new JSDOM(fs.readFileSync(path.join(root,'public/index.html'),'utf8').replace(/<script[^>]*>[\s\S]*?<\/script>/g,''),{url:base,runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:virtual});
 const w=dom.window,browser=client();w.fetch=(url,opt={})=>browser.raw(String(url),opt);w.confirm=()=>true;w.HTMLElement.prototype.scrollIntoView=function(){};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};w.crypto.randomUUID=()=>crypto.randomUUID();w.FormData=class extends FormData{constructor(form){super();if(form)for(const e of form.elements){if(!e.name||e.disabled||['button','submit','file'].includes(e.type))continue;if(['checkbox','radio'].includes(e.type)&&!e.checked)continue;this.append(e.name,e.value);}}};
 w.AbortController=AbortController;
 new (require('node:vm').Script)(fs.readFileSync(path.join(root,'public/demo.js'),'utf8')).runInContext(dom.getInternalVMContext());new (require('node:vm').Script)(fs.readFileSync(path.join(root,'public/modules/staff_support.js'),'utf8')).runInContext(dom.getInternalVMContext());await w.startDemo();
 const lf=w.document.getElementById('login-form');lf.elements.username.value='sales';lf.elements.password.value=creds.sales;lf.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 await waitFor(()=>w.document.getElementById('page-staff_support')?.classList.contains('active'),'sales queue');
 ok(w.document.querySelector('[data-ss-section="issues"]').hidden&&w.document.querySelector('[data-ss-section="answers"]').hidden,'launch flags hide entries');
 w.document.dispatchEvent(new w.KeyboardEvent('keydown',{key:'k',ctrlKey:true,bubbles:true}));ok(w.document.activeElement.id==='global-input','Ctrl K focuses search');
 const search=w.document.getElementById('global-input');search.value='so 10482';search.dispatchEvent(new w.Event('input',{bubbles:true}));
 try{await waitFor(()=>w.document.querySelector('.search-result'),'search results');}catch(e){console.error('SEARCH:',w.document.getElementById('search-results').innerHTML,errors);throw e;}ok(w.document.getElementById('search-results').textContent.includes('SO-10482'),'normalised SO found in interface');
 search.dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));ok(search.getAttribute('aria-activedescendant')==='result-0','keyboard result selection');search.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));
 await waitFor(()=>w.document.querySelector('[data-ss-context="quotes"]'),'customer overview');ok(w.document.getElementById('customer-view').textContent.includes('Demo Patio Customer'),'customer overview');
 w.document.querySelector('[data-ss-context="quotes"][data-record="SO-10482"]').click();await waitFor(()=>w.document.getElementById('ss-create-form'),'help panel');ok(w.document.getElementById('ss-panel-title').textContent.includes('SO-10482'),'SO passed into help');
 const form=w.document.getElementById('ss-create-form');form.elements.body.value='Created through the actual UI';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));try{await waitFor(()=>w.document.getElementById('ss-reply-form'),'saved ticket');}catch(e){console.error('PANEL:',w.document.getElementById('ss-panel-body').textContent);throw e;}ok(w.document.getElementById('ss-panel-body').textContent.includes('Created through the actual UI'),'UI create and history');
 w.document.querySelector('[data-ss="close"]').click();w.document.querySelector('[data-demo-nav="staff_support"]').click();await waitFor(()=>w.document.querySelector('[data-ss-ticket]'),'queue');ok(w.document.getElementById('ss-queue-label').textContent==='My requests','sales queue');
 w.document.getElementById('logout').click();await waitFor(()=>!w.document.getElementById('login').hidden,'logout');
 lf.elements.username.value='stacey';lf.elements.password.value=creds.stacey;lf.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await waitFor(()=>w.document.getElementById('signed-in').textContent.includes('Stacey'),'agent login');await waitFor(()=>w.document.getElementById('ss-queue-label').textContent==='Team queue','agent boot');
 w.document.querySelector('[data-demo-nav="staff_support"]').click();await waitFor(()=>w.document.querySelector('[data-ss-ticket]'),'team queue');
 w.document.querySelector('[data-demo-nav="mail"]').click();await waitFor(()=>w.document.getElementById('mail-list').textContent.includes('Created through the actual UI'),'automatic email capture');ok(w.document.querySelector('[data-email-ticket]'),'email link present');
 w.document.querySelector('[data-demo-nav="staff_support"]').click();w.document.querySelector('[data-ss="new"]').click();await waitFor(()=>w.document.querySelector('[data-ss="website"]')&&w.document.getElementById('ss-create-form'),'request types');w.document.querySelector('#ss-panel [data-ss="website"]').click();await waitFor(()=>w.document.getElementById('ss-create-form')?.elements.website_url,'website form');
 const wf=w.document.getElementById('ss-create-form');ok(wf.elements.body.required&&!wf.elements.website_url.required&&!wf.elements.file.required,'only website description required');ok(!wf.checkValidity(),'browser validation rejects blank description');
 const screenshot=new w.File([png],'pasted.png',{type:'image/png'});const paste=new w.Event('paste',{bubbles:true,cancelable:true});Object.defineProperty(paste,'clipboardData',{value:{items:[{kind:'file',type:'image/png',getAsFile:()=>screenshot}]}});wf.elements.body.dispatchEvent(paste);ok(wf._ssFile===screenshot&&wf.querySelector('.ss-dropzone').textContent.includes('pasted.png'),'clipboard screenshot selected');
 const dropped=new w.File([png],'dropped.png',{type:'image/png'});const drop=new w.Event('drop',{bubbles:true,cancelable:true});Object.defineProperty(drop,'dataTransfer',{value:{files:[dropped]}});wf.dispatchEvent(drop);ok(wf._ssFile===dropped,'drag and drop selected');wf._ssFile=null;
 wf.elements.body.value='UI website problem';wf.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await waitFor(()=>w.document.getElementById('ss-reply-form'),'description-only website created');ok(w.document.getElementById('ss-panel-body').textContent.includes('Samantha'),'website routed to Samantha');
 const manage=w.document.getElementById('ss-update-form');manage.elements.operation.value='acknowledge';manage.elements.operation.dispatchEvent(new w.Event('change'));manage.elements.next_update_at.value=dueDate;manage.elements.body.value='I have picked this up.';manage.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await waitFor(()=>w.document.querySelector('#ss-update-form option[value="live"]'),'acknowledged website');
 const live=w.document.getElementById('ss-update-form');live.elements.operation.value='live';live.elements.operation.dispatchEvent(new w.Event('change'));live.elements.body.value='Image corrected.';live.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await waitFor(()=>w.document.getElementById('ss-panel-body').textContent.includes('Please check the change'),'live confirmation rendered');ok(w.document.getElementById('ss-panel-body').textContent.includes('Live on site'),'live UI flow');
 w.document.querySelector('[data-ss="close"]').click();
 w.document.querySelector('[data-ss-section="report"]').click();await waitFor(()=>w.document.getElementById('ss-section-report').textContent.includes('Median acknowledgement'),'report');ok(errors.length===0,'no DOM runtime errors: '+errors.join(';'));w.close();
 console.log('PASS: '+count+' HTTP, upload and actual UI/API checks.');
 }finally{server.kill();fs.writeFileSync(path.join(__dirname,'server-test.log'),log);fs.rmSync(root,{recursive:true,force:true});}})().catch(e=>{console.error(e.stack);process.exitCode=1;});
