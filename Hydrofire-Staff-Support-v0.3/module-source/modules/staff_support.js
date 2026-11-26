/* Staff Support. Uses the intranet session, with its own API, CSRF token and records. */
window.HFSupport = (() => {
  'use strict';
  const labels = {new:'New',acknowledged:'Acknowledged',in_progress:'In progress',waiting_requester:'Waiting for you',waiting_team:'Waiting on another team',resolved:'Resolved',closed:'Closed',cancelled:'Cancelled'};
  const problems = {status:'Status looks wrong',blocked:'I cannot complete this action',guidance:'I need guidance',availability:'Availability looks wrong',pricing:'Price discrepancy',other:'Something else'};
  const modules = {general:'General help',website:'Website problem',leads:'Customer Leads',quotes:'Quotes / payment links',backorders:'Backorders',dispatch:'Dispatch / Deliveries',invoices:'Invoices','price-lists':'Price Lists',library:'Product Library',b2b_portal:'B2B Portal','credit-notes':'Credit Notes',cashsales:'Cash Sales'};
  const state = {enabled:false,meta:null,view:'open',offset:0,total:0,detail:null,context:null,sequence:0,dirty:false,returnFocus:null,booting:null,issues:[],answers:[]};
  const el = id => document.getElementById(id);
  const h = v => String(v ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const key = () => crypto.randomUUID().replace(/-/g,'');
  const options = (o,current='') => Object.entries(o).map(([v,t])=>`<option value="${h(v)}" ${String(v)===String(current)?'selected':''}>${h(t)}</option>`).join('');
  const date = s => s ? new Date(s.replace(' ','T')+(s.includes('+')||s.endsWith('Z')?'':'+02:00')).toLocaleString('en-ZA',{timeZone:'Africa/Johannesburg',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})+' SAST' : 'Not set';
  const terminal = s => ['resolved','closed','cancelled'].includes(s);
  const overdue = t => t.next_update_at && !terminal(t.status) && t.status!=='waiting_requester' && new Date(t.next_update_at.replace(' ','T')+'+02:00')<new Date();
  const pill = t => `<span class="ss-pill ss-${h(t.status)}">${h(labels[t.status]||t.status)}</span>${overdue(t)?'<span class="ss-pill ss-overdue">Update overdue</span>':''}`;
  const field = (label,input) => `<label class="ss-field"><span>${label}</span>${input}</label>`;
  const dueInput = (value='') => field('Next update (South African time)',`<input name="next_update_at" type="datetime-local" value="${h(value.slice(0,16).replace(' ','T'))}" step="60"><small>An update commitment, not a promised fix time. Branch working hours apply.</small><span class="ss-help-time"><button type="button" class="btn btn-s" data-ss-time="hour">Next working hour</button><button type="button" class="btn btn-s" data-ss-time="tomorrow">Tomorrow</button></span>`);
  const tabs = active => `<div class="ss-area-tabs" aria-label="Leads and support">${typeof canAccess==='function' && canAccess('leads')?`<button class="${active==='leads'?'selected':''}" data-ss-nav="leads">Customer Leads</button>`:''}<button class="${active==='staff_support'?'selected':''}" data-ss-nav="staff_support">Staff Support</button></div>`;

  async function request(action, data={}, write=false, formData=null) {
    const accountId=CU?.id;const headers={}; if(typeof _VIEW_AS_TOKEN!=='undefined' && _VIEW_AS_TOKEN) headers['X-View-As-Token']=_VIEW_AS_TOKEN;
    const opt={method:write?'POST':'GET',credentials:'same-origin',cache:'no-store',headers};
    let url='api.php?'+new URLSearchParams({action,...(!write?data:{})});
    if(write){headers['X-Support-CSRF']=state.meta?.csrf||'';if(formData) opt.body=formData;else{headers['Content-Type']='application/json';opt.body=JSON.stringify(data);}}
    const response=await fetch(url,opt);
    let result;try{result=await response.json();}catch{throw new Error('The server could not complete this request. Please retry.');}
    if(CU?.id!==accountId)throw new Error('Your account changed. Open the request again.');
    if(!response.ok || !result.success){const error=new Error(result.error||'Request failed.');error.status=response.status;throw error;}
    return result;
  }
  function command(form,action,data) {
    const signature=JSON.stringify(data);
    if(form._ssSignature!==signature){form._ssSignature=signature;form._ssKey=key();}
    return request(action,{...data,request_key:form._ssKey},true);
  }
  function announce(text,error=false){const box=el('ss-notice');box.textContent=text;box.className='ss-notice'+(error?' error':'');box.hidden=false;}
  async function busy(form,fn) {
    if(form.dataset.busy) return;
    form.dataset.busy='1'; const buttons=[...form.querySelectorAll('button[type="submit"]')];buttons.forEach(b=>b.disabled=true);
    const error=form.querySelector('.ss-error');if(error) error.textContent='';
    try{await fn();}catch(e){if(error) error.textContent=e.message;else announce(e.message,true);}
    finally{delete form.dataset.busy;buttons.forEach(b=>b.disabled=false);}
  }
  function mount() {
    if(el('page-staff_support')) return;
    el('main').insertAdjacentHTML('beforeend',`<div id="page-staff_support" class="page"><div class="ss-workspace">
      <div id="ss-tabs"></div><header class="ss-heading"><div><p class="ss-eyebrow">LEADS & SUPPORT</p><h1>Staff Support</h1><p>One place to ask, follow up and get back to work.</p></div><button class="btn btn-p" data-ss="new">+ Get help</button></header>
      <div id="ss-notice" role="status" class="ss-notice" hidden></div>
      <nav class="ss-sections" aria-label="Support views"><button data-ss-section="queue" class="selected" id="ss-queue-label">My requests</button><button data-ss-section="issues">Known issues</button><button data-ss-section="answers" class="ss-team-only" hidden>Suggested answers</button><button data-ss-section="report" class="ss-team-only" hidden>Overview</button><button data-ss-section="settings" class="ss-admin-only" hidden>Settings</button></nav>
      <section id="ss-section-queue"><div class="ss-filters"><label class="ss-search"><span class="ss-sr">Search requests</span><input id="ss-search" placeholder="Search request, order or product…" maxlength="100"></label><select id="ss-view" aria-label="Request view"></select><select id="ss-module-filter" aria-label="Module"><option value="">All modules</option>${options(modules)}</select><select id="ss-priority-filter" aria-label="Priority"><option value="">All priorities</option>${options({normal:'Normal',high:'High',urgent:'Urgent'})}</select><button class="btn btn-s" data-ss="refresh">Refresh</button></div><div id="ss-queue" aria-live="polite"></div><div class="ss-pagination"><span id="ss-total"></span><button class="btn btn-s" id="ss-prev" data-ss="prev">Previous</button><button class="btn btn-s" id="ss-next" data-ss="next">Next</button></div></section>
      <section id="ss-section-issues" hidden><div class="ss-section-head"><p>Follow an issue to receive shared updates. Individual requests stay private.</p><button class="btn btn-p ss-publisher-only" data-ss="new-issue" hidden>Publish known issue</button></div><div id="ss-issues" class="ss-cards"></div></section>
      <section id="ss-section-answers" hidden><div class="ss-section-head"><p>Reviewed answers linked to Manuals, Procedures or the Product Library.</p><button class="btn btn-p" data-ss="new-answer">Draft an answer</button></div><div id="ss-answers" class="ss-cards"></div></section>
      <section id="ss-section-report" hidden></section><section id="ss-section-settings" hidden></section>
    </div></div>`);
    document.body.insertAdjacentHTML('beforeend',`<dialog id="ss-panel" class="ss-panel" aria-labelledby="ss-panel-title"><header><div><p class="ss-eyebrow" id="ss-panel-kicker">STAFF SUPPORT</p><h2 id="ss-panel-title">Get help</h2></div><button class="ss-close" aria-label="Close support panel" data-ss="close">×</button></header><div id="ss-panel-body"></div></dialog><button id="ss-global-help" class="ss-global-help" data-ss="context-help" hidden>Get help</button>`);
    el('ss-panel').addEventListener('cancel',e=>{e.preventDefault();close();});
    el('ss-panel').addEventListener('input',()=>{state.dirty=true;});
    let timer;el('ss-search').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{state.offset=0;loadQueue().catch(e=>announce(e.message,true));},250);});
    for(const id of ['ss-view','ss-module-filter','ss-priority-filter']) el(id).addEventListener('change',()=>{state.offset=0;state.view=el('ss-view').value;loadQueue().catch(e=>announce(e.message,true));});
  }
  async function boot() {
    mount();state.enabled=false;
    try {
      state.meta=await request('support_meta');state.enabled=true;
      el('ss-tabs').innerHTML=tabs('staff_support');
      const header=document.querySelector('#page-leads .leads-page-header');
      if(header && !el('ss-lead-tabs')) header.insertAdjacentHTML('afterbegin',`<div id="ss-lead-tabs">${tabs('leads')}</div>`);
      const lead=HF.modules.find(m=>m.id==='leads');if(lead) lead.label='Leads & Support';
      state.view='open';state.offset=0;el('ss-search').value='';el('ss-module-filter').value='';el('ss-priority-filter').value='';
      el('ss-queue-label').textContent=state.meta.team?'Team queue':'My requests';
      el('ss-view').innerHTML=options({...{open:'Open requests',my_requests:'My requests'},...(state.meta.team?{mine:'Assigned to me',unassigned:'Unassigned',overdue:'Updates overdue'}:{}),...labels,all:'All requests'},'open');
      document.querySelectorAll('.ss-team-only').forEach(n=>n.hidden=!state.meta.team);
      document.querySelectorAll('.ss-publisher-only').forEach(n=>n.hidden=!state.meta.publisher);
      document.querySelectorAll('.ss-admin-only').forEach(n=>n.hidden=!state.meta.admin);
      document.querySelector('[data-ss-section="issues"]').hidden=!state.meta.features.incidents;
      document.querySelector('[data-ss-section="answers"]').hidden=!state.meta.team||!state.meta.features.answers;
      await section('queue');
    }catch(e){if(e.status!==404 && e.status!==403) console.warn('Staff Support unavailable:',e.message);}
    if(typeof buildNav==='function') buildNav();
    onNavigate(document.querySelector('.page.active')?.id.replace('page-','')||'home');
    const match=location.hash.match(/^#support-(\d+)$/);
    if(state.enabled && match){navTo('staff_support',null);await openTicket(Number(match[1]));}
    else if(state.enabled && /^#support-issue-\d+$/.test(location.hash)){navTo('staff_support',null);await section('issues');}
    return state.enabled;
  }
  async function enter(){if(!state.enabled){await boot();if(!state.enabled){el('ss-queue').textContent='Staff Support is unavailable for this account.';return;}}el('ss-tabs').innerHTML=tabs('staff_support');await loadQueue().catch(e=>announce(e.message,true));}
  async function loadQueue(){
    const sequence=++state.queueSequence|| (state.queueSequence=1);
    const d=await request('support_list',{view:state.view,search:el('ss-search').value,module:el('ss-module-filter').value,priority:el('ss-priority-filter').value,offset:state.offset});
    if(sequence!==state.queueSequence) return;
    state.total=d.total;
    el('ss-total').textContent=`${d.total} request${d.total===1?'':'s'}`;
    el('ss-prev').disabled=state.offset===0;el('ss-next').disabled=state.offset+50>=d.total;
    el('ss-queue').innerHTML=d.tickets.length?`<div class="ss-table-wrap"><table class="ss-table"><thead><tr><th>Request</th><th>Status</th><th>Owner</th><th>Next update</th></tr></thead><tbody>${d.tickets.map(t=>`<tr><td><button class="ss-ticket-link" data-ss-ticket="${t.id}">#${t.id} ${h(t.title)}</button>${Number(t.unread)?`<span class="ss-unread">${Number(t.unread)} unread</span>`:''}<small>${h(t.requester_name)} · ${h(t.branch||'No branch')} · ${h(modules[t.module]||t.module)}${t.priority!=='normal'?' · '+h(t.priority):''}</small></td><td>${pill(t)}</td><td>${h(t.owner_name||'Unassigned')}<small>${t.acknowledged_at?'Accepted '+date(t.acknowledged_at):'Awaiting acknowledgement'}</small></td><td class="${overdue(t)?'ss-due':''}">${t.status==='waiting_requester'?'Paused — waiting for reply':t.next_update_at?date(t.next_update_at):'—'}</td></tr>`).join('')}</tbody></table></div>`:'<div class="ss-empty"><div>✓</div><h3>No requests in this view</h3><p>Use Get help when you need a hand.</p></div>';
  }
  function panel(title,kicker='STAFF SUPPORT') {
    state.returnFocus=document.activeElement;el('ss-panel-title').textContent=title;el('ss-panel-kicker').textContent=kicker;state.dirty=false;
    if(!el('ss-panel').open) el('ss-panel').showModal();
  }
  function close(force=false){if(!force && state.dirty && !confirm('Discard your unsent changes?')) return false;state.sequence++;el('ss-panel').close();state.dirty=false;state.returnFocus?.focus?.();return true;}
  function contextCard(ctx){return `<div class="ss-context"><strong>${h(ctx.reference)}</strong><span>${h(modules[ctx.module])}</span>${Object.entries(ctx.fields||{}).filter(([,v])=>v!==null&&v!=='').map(([k,v])=>`<small><b>${h(k.replace(/_/g,' '))}:</b> ${h(v)}</small>`).join('')}<small>${ctx.verified?'Captured':'General request'} ${date(ctx.captured_at)}${ctx.verified?' · cached record details':''}</small></div>`;}
  async function open(source={module:'general',record_id:''}) {
    if(!state.enabled) return;
    if(el('ss-panel').open && !close()) return;
    const seq=++state.sequence;state.detail=null;panel('Get help');el('ss-panel-body').innerHTML='<p class="ss-muted">Loading record details…</p>';
    try{
      const d=await request('support_context',source);if(seq!==state.sequence)return;state.context=d.context;
      el('ss-panel-title').textContent='Help with '+d.context.reference;
      const website=source.module==='website';
      el('ss-panel-body').innerHTML=`${!source.record_id?'<div class="ss-request-types"><button type="button" data-ss="general" class="btn btn-s">General help</button><button type="button" data-ss="website" class="btn btn-s">Website problem</button><button type="button" data-ss="find-so" class="btn btn-s">Find a sales order</button></div>':''}${website?'':contextCard(d.context)}<form id="ss-create-form">
        ${website?'<input type="hidden" name="problem" value="other">'+field('Page URL (optional)','<input name="website_url" type="url" maxlength="2000" placeholder="https://heliosliving.co.za/…">'):field('What is the problem?',`<select name="problem">${options(problems,'guidance')}</select>`)}<div id="ss-suggestions" aria-live="polite"></div>
        ${field(website?'What is the problem? *':'Anything else we should know?', `<textarea name="body" rows="4" maxlength="8000" ${website?'required':''} placeholder="${website?'Describe what needs fixing or changing…':'Optional for a standard issue. Required for Something else.'}"></textarea>`)}
        ${website?'<input type="hidden" name="impact" value="normal">':field('Impact',`<select name="impact">${options({normal:'Normal',customer_waiting:'Customer waiting',sale_blocked:'Sale blocked',several_people:'Several people affected'})}</select>`)}
        ${field('Screenshot or PDF (optional)', '<input type="file" name="file" accept="image/png,image/jpeg,image/webp,application/pdf"><small>Up to 5 MB. You can add more evidence to the saved request.</small>')}
        <p class="ss-error" role="alert"></p><button class="btn btn-p ss-wide" type="submit">Send request</button><small class="ss-muted">Your request goes to the support queue. Updates appear here and are queued for email.</small></form>`;
      const form=el('ss-create-form');form.addEventListener('change',e=>{if(e.target.name==='problem'){form.elements.body.required=e.target.value==='other';suggest().catch(e=>{el('ss-suggestions').textContent=e.message;});}});
      form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{
        const file=form._ssFile||form.elements.file.files[0];if(file && file.size>5*1024*1024) throw new Error('Choose evidence up to 5 MB.');
        const data={...source,problem:form.elements.problem.value,body:form.elements.body.value,impact:form.elements.impact.value,...(website?{website_url:form.elements.website_url.value}:{})};
        const r=await command(form,'support_create',data);state.dirty=false;
        let warning='';if(file){try{await uploadFile(r.id,r.version,file,'public');}catch(err){warning='Request saved. Evidence was not uploaded: '+err.message;}}
        await openTicket(r.id,true);if(warning){const box=document.createElement('p');box.className='ss-error';box.textContent=warning;el('ss-panel-body').prepend(box);}
        if(el('page-staff_support').classList.contains('active')) await loadQueue();
      });});
      evidenceInput(form);await suggest();(website?form.elements.body:form.elements.problem).focus();
    }catch(e){if(seq!==state.sequence)return;el('ss-panel-body').innerHTML=`<p class="ss-error">${h(e.message)}</p><button class="btn btn-p" data-ss="general">Start a general request</button>`;}
  }
  async function suggest(){
    const seq=++state.suggestSequence||(state.suggestSequence=1),form=el('ss-create-form');if(!form)return;
    if(!state.meta.features.answers&&!state.meta.features.incidents){el('ss-suggestions').hidden=true;return;}
    const d=await request('support_suggestions',{module:state.context.module,record_id:state.context.record_id,problem:form.elements.problem.value});
    if(seq!==state.suggestSequence||el('ss-create-form')!==form)return;
    el('ss-suggestions').innerHTML=(d.duplicates.length?`<div class="ss-hint"><strong>You have a similar open request</strong>${d.duplicates.map(t=>`<button type="button" class="ss-ticket-link" data-ss-ticket="${t.id}">Open #${t.id} — ${h(t.title)}</button>`).join('')}<small>Continue below if this is a different issue.</small></div>`:'')+
      d.issues.map(i=>`<article class="ss-hint"><span class="ss-eyebrow">KNOWN ISSUE</span><strong>${h(i.title)}</strong><p>${h(i.explanation)}</p>${i.workaround?`<p><b>Workaround:</b> ${h(i.workaround)}</p>`:''}<small>Next update ${date(i.next_update_at)}</small><button type="button" class="btn btn-s" data-ss-follow="${i.id}">This affects me</button></article>`).join('')+
      d.answers.map(a=>`<article class="ss-hint"><span class="ss-eyebrow">TRY THIS FIRST</span><strong>${h(a.title)}</strong><p>${h(a.body)}</p><small>Reviewed ${h(a.reviewed_at)}</small><button type="button" class="ss-ticket-link" data-ss-source="${h(a.source_module)}" data-record="${h(a.source_id)}">Open approved source</button><button type="button" class="btn btn-s" data-ss-helped="${a.id}">This helped</button></article>`).join('');
  }
  async function openTicket(id,force=false){
    if(el('ss-panel').open && state.dirty && !force && !close())return;
    const seq=++state.sequence;panel('Request #'+id);el('ss-panel-body').innerHTML='<p class="ss-muted">Loading request…</p>';
    try{
      const d=await request('support_detail',{id});if(seq!==state.sequence)return;state.detail=d;const t=d.ticket;
      el('ss-panel-title').textContent='#'+id+' · '+t.title;
      const internal=t.can_manage;
      el('ss-panel-body').innerHTML=`<div class="ss-ticket-summary">${pill(t)}<p><b>${h(t.owner_name)}</b> · ${t.acknowledged_at?'Acknowledged '+date(t.acknowledged_at):'Not yet acknowledged'}</p><p>${t.status==='waiting_requester'?'Waiting for your reply. Update reminders are paused.':`Next update: <b>${date(t.next_update_at)}</b>`}</p></div>
        ${t.live_at?`<div class="ss-hint"><strong>Live on site · ${date(t.live_at)}</strong><p>Please check the change, then confirm the resolution or reopen this request.</p>${t.commit_url?`<a href="${h(t.commit_url)}" target="_blank" rel="noopener">View commit</a>`:''}</div>`:''}${contextCard(t.context)}${t.source_available?`<button class="ss-ticket-link" data-ss-source="${h(t.module)}" data-record="${h(t.record_id)}">Return to source record</button>`:''}
        ${d.issue?`<div class="ss-hint"><strong>Linked known issue: ${h(d.issue.title)}</strong><p>${h(d.issue.explanation)}</p><small>Shared next update: ${date(d.issue.next_update_at)} · ${h(d.issue.status)}</small><small>Your individual request remains open until its own work is finished.</small></div>`:''}
        <h3>Conversation & activity</h3><ol class="ss-thread">${d.events.map(e=>`<li class="${e.visibility==='internal'?'ss-internal':''}"><div><strong>${h(e.actor_name||'Former account')}</strong><span>${h(e.kind.replace(/_/g,' '))}${e.visibility==='internal'?' · PRIVATE SUPPORT NOTE':''}</span><time>${date(e.created_at)}</time></div><p>${h(e.body)}</p>${renderMeta(e.meta_json)}${d.files.filter(f=>Number(f.event_id)===Number(e.id)).map(f=>`<button class="ss-ticket-link" data-ss-file="${f.id}" data-name="${h(f.name)}">Download ${h(f.name)}</button>`).join('')}</li>`).join('')}</ol>
        ${!terminal(t.status)?`<form id="ss-reply-form">${field('Reply', '<textarea name="body" rows="3" maxlength="8000" required placeholder="Add an update or answer the question…"></textarea>')}${internal?field('Who can see this?',`<select name="visibility">${options({public:'Requester and support',internal:'Private support note'})}</select>`):'<input type="hidden" name="visibility" value="public">'}<p class="ss-error" role="alert"></p><button class="btn btn-p" type="submit">Send reply</button></form><form id="ss-file-form" class="ss-evidence">${field('Add evidence','<input name="file" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" required>')}${internal?field('File visibility',`<select name="visibility">${options({public:'Requester and support',internal:'Private support file'})}</select>`):'<input type="hidden" name="visibility" value="public">'}<p class="ss-error" role="alert"></p><button class="btn btn-s" type="submit">Upload evidence</button></form>`:''}
        ${updateForm(t)}${internal&&d.mail.length?`<details class="ss-mail"><summary>Email queue: ${d.mail.length} pending or failed</summary>${d.mail.map(m=>`<p>${h(m.status)} · ${m.attempts} attempts ${h(m.last_error)}</p>`).join('')}</details>`:''}
        ${internal&&t.status==='resolved'&&state.meta.features.answers?'<button class="btn btn-s" data-ss="resolution-answer">Draft an answer from this resolution</button>':''}`;
      const reply=el('ss-reply-form');if(reply) reply.addEventListener('submit',e=>{e.preventDefault();busy(reply,async()=>{await command(reply,'support_reply',{id,version:t.version,body:reply.elements.body.value,visibility:reply.elements.visibility.value});await changed(id);});});
      const files=el('ss-file-form');if(files){evidenceInput(files,el('ss-reply-form'));files.addEventListener('submit',e=>{e.preventDefault();busy(files,async()=>{await uploadFile(id,t.version,files._ssFile||files.elements.file.files[0],files.elements.visibility.value,files);await changed(id);});});}
      const form=el('ss-update-form');if(form){form.elements.operation.addEventListener('change',()=>updateFields(form,t));updateFields(form,t);form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{await command(form,'support_update',{...Object.fromEntries(new FormData(form)),id,version:t.version});await changed(id);});});}
      const last=d.events.at(-1);if(last) await request('support_read',{id,event_id:Number(last.id),request_key:key()},true);
    }catch(e){if(seq===state.sequence) el('ss-panel-body').innerHTML=`<p class="ss-error">${h(e.message)}</p>`;}
  }
  function renderMeta(json){let m;try{m=JSON.parse(json);}catch{return '';}
    return Object.entries(m).map(([k,v])=>`<small>${h(k.replace(/_/g,' '))}: ${v&&typeof v==='object'?`${h(v.from??'—')} → ${h(v.to??'—')}`:h(v)}</small>`).join('');}
  function updateForm(t){
    let ops=terminal(t.status)?{reopen:'Still need help — reopen'}:t.can_manage?{...(t.status==='new'?{acknowledge:'Acknowledge & take ownership'}:{}),status:'Change status',assign:'Assign owner',commitment:'Change next update',priority:'Set priority',link_issue:'Link known issue'}:{status:'Close or cancel request'};
    if(t.status==='resolved')ops.status='Confirm resolution — close';
    if(!state.meta.features.incidents)delete ops.link_issue;
    if(t.module==='website'&&t.can_manage&&!terminal(t.status)&&t.acknowledged_at)ops.live='Live on site — ask requester to confirm';
    return `<form id="ss-update-form" class="ss-management"><h3>${t.can_manage?'Manage request':'Request actions'}</h3>${field('Action',`<select name="operation">${options(ops)}</select>`)}<div id="ss-update-fields"></div>${field('Update / reason','<textarea name="body" rows="2" maxlength="8000" required placeholder="This update will be visible to the requester."></textarea>')}<p class="ss-error" role="alert"></p><button class="btn btn-p" type="submit">Save update</button></form>`;
  }
  function updateFields(form,t){
    const op=form.elements.operation.value;let html='';
    if(['acknowledge','commitment'].includes(op))html=dueInput(t.next_update_at||'');
    if(op==='live')html=field('GitHub commit URL (optional)','<input name="commit_url" type="url" placeholder="https://github.com/owner/repo/commit/…">');
    if(op==='status')html=field('Status',`<select name="status">${options(t.can_manage?{in_progress:labels.in_progress,waiting_requester:labels.waiting_requester,waiting_team:labels.waiting_team,resolved:labels.resolved,closed:labels.closed,cancelled:labels.cancelled}:(t.status==='resolved'?{closed:'Confirm resolved'}:{cancelled:'Cancel — no longer needed'}),t.status==='resolved'?'closed':'in_progress')}</select>`)+(t.can_manage?dueInput(t.next_update_at||''):'');
    if(op==='assign')html=field('Owner',`<select name="owner_id">${options({0:'Unassigned',...Object.fromEntries(state.meta.agents.map(a=>[a.id,a.name]))},t.owner_id||0)}</select>`);
    if(op==='priority')html=field('Priority',`<select name="priority">${options({normal:'Normal',high:'High',urgent:'Urgent'},t.priority)}</select>`);
    if(op==='link_issue'){
      html=field('Known issue',`<select name="issue_id"><option value="0">Remove link</option></select>`);
      request('support_issues').then(d=>{if(form.elements.operation.value==='link_issue')form.elements.issue_id.innerHTML=options({0:'Remove link',...Object.fromEntries(d.issues.filter(i=>i.status!=='resolved').map(i=>[i.id,i.title]))},t.issue_id||0);}).catch(e=>{form.querySelector('.ss-error').textContent=e.message;});
    }
    el('ss-update-fields').innerHTML=html;
    const toggleDue=()=>{const input=form.elements.next_update_at;if(input && form.elements.status){const needed=['in_progress','waiting_team'].includes(form.elements.status.value);input.closest('label').hidden=!needed;input.disabled=!needed;}};
    if(form.elements.status){form.elements.status.addEventListener('change',toggleDue);toggleDue();}
  }
  async function changed(id){state.dirty=false;await openTicket(id,true);if(el('page-staff_support').classList.contains('active'))await loadQueue();}
  function evidenceInput(form,pasteTarget=null){
    const input=form.elements.file;if(!input)return;
    const hint=document.createElement('div');hint.className='ss-dropzone';hint.tabIndex=0;hint.textContent='Drop an image or PDF here, or paste a screenshot (Ctrl+V).';input.after(hint);
    function select(file){
      if(!file)return;
      if(file.size>5*1024*1024||!['image/png','image/jpeg','image/webp','application/pdf'].includes(file.type)){form.querySelector('.ss-error').textContent='Choose a PNG, JPG, WebP or PDF up to 5 MB.';return;}
      form._ssFile=file;input.required=false;hint.textContent='Attached: '+file.name+' — upload validation also runs on the server.';state.dirty=true;
    }
    input.addEventListener('change',()=>{form._ssFile=null;select(input.files[0]);});
    form.addEventListener('dragover',e=>{e.preventDefault();hint.classList.add('dragging');});
    form.addEventListener('dragleave',()=>hint.classList.remove('dragging'));
    form.addEventListener('drop',e=>{e.preventDefault();hint.classList.remove('dragging');select(e.dataTransfer?.files[0]);});
    const paste=e=>{const item=[...(e.clipboardData?.items||[])].find(x=>x.kind==='file'&&x.type.startsWith('image/'));if(item){e.preventDefault();select(item.getAsFile());if(pasteTarget)hint.scrollIntoView({block:'nearest'});}};
    form.addEventListener('paste',paste);if(pasteTarget)pasteTarget.addEventListener('paste',paste);
  }
  async function uploadFile(id,version,file,visibility,form=null){
    if(!file || file.size>5*1024*1024)throw new Error('Choose an image or PDF up to 5 MB.');
    const signature=[id,version,visibility,file.name,file.size,file.lastModified].join(':');
    if(form && form._ssUploadSignature!==signature){form._ssUploadSignature=signature;form._ssUploadKey=key();}
    const data=new FormData();Object.entries({id,version,visibility,request_key:form?form._ssUploadKey:key()}).forEach(([k,v])=>data.append(k,v));data.append('file',file);
    return request('support_upload',{},true,data);
  }
  async function section(name){
    document.querySelectorAll('[id^="ss-section-"]').forEach(n=>n.hidden=n.id!=='ss-section-'+name);
    document.querySelectorAll('[data-ss-section]').forEach(n=>n.classList.toggle('selected',n.dataset.ssSection===name));
    if(name==='queue')await loadQueue();
    if(name==='issues'){
      const d=await request('support_issues');state.issues=d.issues;
      el('ss-issues').innerHTML=d.issues.map(i=>`<article class="ss-card"><div class="ss-section-head"><span class="ss-pill">${h(i.status)}</span><span class="ss-muted">${h(modules[i.module])} · ${h(i.branch||'All branches')}</span></div><h3>${h(i.title)}</h3><p>${h(i.explanation)}</p>${i.workaround?`<p><b>Workaround:</b> ${h(i.workaround)}</p>`:''}<small>Next update: ${date(i.next_update_at)}</small><div class="ss-actions"><button class="btn btn-s" data-ss-follow="${i.id}" data-unfollow="${i.following?'1':''}">${i.following?'Following — unsubscribe':'This affects me'}</button><button class="btn btn-s" data-ss="new">I still need individual help</button>${state.meta.publisher?`<button class="btn btn-s" data-ss-edit-issue="${i.id}">Update issue</button>`:''}</div></article>`).join('')||'<div class="ss-empty">No published known issues.</div>';
    }
    if(name==='answers'){
      const d=await request('support_answers');state.answers=d.answers;
      el('ss-answers').innerHTML=d.answers.map(a=>`<article class="ss-card"><span class="ss-pill">${h(a.status)}</span><h3>${h(a.title)}</h3><p>${h(a.body)}</p><small>Review due ${h(a.review_due)} · ${h(a.source_module)}</small><button class="btn btn-s" data-ss-edit-answer="${a.id}">Edit answer</button></article>`).join('')||'<div class="ss-empty">Draft an approved answer to help with repeat questions.</div>';
    }
    if(name==='report'){
      const d=await request('support_report');
      el('ss-section-report').innerHTML=`<div class="ss-cards">${Object.entries(d.counts).map(([k,v])=>`<article class="ss-card"><span class="ss-eyebrow">${h(k)}</span><h2>${v}</h2></article>`).join('')}</div><p>Median acknowledgement: <b>${d.median_ack_minutes??'—'} minutes</b> (${d.ack_sample} requests). Requester waiting time: ${d.requester_wait_hours} hours.</p><p class="ss-muted">${h(d.basis)}</p>`;
    }
    if(name==='settings')renderSettings();
  }
  function editIssue(id){
    const i=state.issues.find(x=>Number(x.id)===Number(id))||{};panel(i.id?'Update known issue':'Publish known issue');
    el('ss-panel-body').innerHTML=`<form id="ss-issue-form">${field('Title',`<input name="title" maxlength="180" required value="${h(i.title)}">`)}${field('Module',`<select name="module">${options(modules,i.module||'general')}</select>`)}${field('Problem',`<select name="problem">${options(problems,i.problem||'other')}</select>`)}${field('Branch','<input name="branch" maxlength="100" value="'+h(i.branch??CU.branch)+'" placeholder="Blank means all branches">')}${field('Status',`<select name="status">${options({investigating:'Investigating',workaround:'Workaround available',monitoring:'Monitoring',resolved:'Resolved'},i.status||'investigating')}</select>`)}${field('Published update',`<textarea name="body" rows="4" maxlength="6000" required>${h(i.explanation)}</textarea>`)}${field('Approved workaround (optional)',`<textarea name="workaround" rows="2" maxlength="4000">${h(i.workaround)}</textarea>`)}${dueInput(i.next_update_at||'')}<p class="ss-error" role="alert"></p><button class="btn btn-p" type="submit">Publish update</button></form>`;
    const f=el('ss-issue-form');const toggle=()=>{f.elements.next_update_at.disabled=f.elements.status.value==='resolved';f.elements.next_update_at.closest('label').hidden=f.elements.next_update_at.disabled;};f.elements.status.addEventListener('change',toggle);toggle();f.addEventListener('submit',e=>{e.preventDefault();busy(f,async()=>{await command(f,'support_issue_save',{...Object.fromEntries(new FormData(f)),id:i.id||0,version:i.version||0});close(true);await section('issues');});});
  }
  function editAnswer(id,fromResolution=false){
    const a=state.answers.find(x=>Number(x.id)===Number(id))||{};const ticket=state.detail?.ticket;
    panel(a.id?'Edit suggested answer':'Draft suggested answer');
    el('ss-panel-body').innerHTML=`<p class="ss-hint">Remove customer names, order numbers and personal details. Published answers must link to an approved source and have a review date.</p><form id="ss-answer-form">${field('Title',`<input name="title" maxlength="180" required value="${h(a.title)}">`)}${field('Short answer',`<textarea name="body" rows="5" maxlength="6000" required>${h(a.body||'')}</textarea>`)}${field('Module',`<select name="module">${options(modules,a.module||(fromResolution?ticket?.module:'general'))}</select>`)}${field('Problem',`<select name="problem">${options(problems,a.problem||(fromResolution?ticket?.problem:'guidance'))}</select>`)}${field('Source',`<select name="source_module">${options({manuals:'Staff Manuals',procedures:'Procedures',library:'Product Library'},a.source_module||'manuals')}</select>`)}${field('Source reference',`<input name="source_id" required maxlength="255" value="${h(a.source_id)}" placeholder="Manual filename, or procedure / library document ID">`)}${field('Review due',`<input name="review_due" type="date" required value="${h(a.review_due)}">`)}${field('Publication',`<select name="status">${options(state.meta.publisher?{draft:'Draft',published:'Published',withdrawn:'Withdrawn'}:{draft:'Draft'},a.status||'draft')}</select>`)}<p class="ss-error" role="alert"></p><button class="btn btn-p" type="submit">Save answer</button></form>`;
    const f=el('ss-answer-form');f.addEventListener('submit',e=>{e.preventDefault();busy(f,async()=>{await command(f,'support_answer_save',{...Object.fromEntries(new FormData(f)),id:a.id||0,version:a.version||0});close(true);await section('answers');});});
  }
  function renderSettings(){
    if(!state.meta.admin)return;const s=state.meta.settings,people={0:'Unassigned',...Object.fromEntries(state.meta.agents.map(a=>[a.id,a.name]))},hours=s.hours['*'];
    el('ss-section-settings').innerHTML=`<form id="ss-settings-form" class="ss-settings"><h3>Routing & working hours</h3><p>Agents need Staff Support Edit or Approve permission in Users. Approve also allows publishing answers and known issues.</p>${field('Default owner',`<select name="default_owner">${options(people,s.default_owner)}</select>`)}${field('Backup for unassigned and overdue requests',`<select name="backup_id">${options(people,s.backup_id)}</select>`)}<div class="ss-two">${field('Day starts',`<input type="time" name="start" value="${h(hours.start)}" required>`)}${field('Day ends',`<input type="time" name="end" value="${h(hours.end)}" required>`)}</div><fieldset><legend>Working days</legend>${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map((d,i)=>`<label><input type="checkbox" name="day" value="${i+1}" ${hours.days.includes(i+1)?'checked':''}> ${d}</label>`).join(' ')}</fieldset>${field('Escalate after overdue hours',`<input name="escalate_hours" type="number" min="1" max="168" value="${s.escalate_hours}">`)}<h3>Route by module</h3>${Object.entries(modules).map(([k,v])=>field(h(v),`<select name="route_${k}">${options({'':'Use default',...people},s.routing[k]??'')}</select>`)).join('')}<h3>Agent branch access</h3><p>Enter branch names separated by commas. Use * for all branches.</p>${state.meta.agents.map(a=>field(h(a.name),`<input name="branches_${a.id}" value="${h((s.agent_branches[a.id]||[a.branch]).join(', '))}">`)).join('')}<p class="ss-error" role="alert"></p><button class="btn btn-p" type="submit">Save settings</button></form>`;
    const f=el('ss-settings-form');f.addEventListener('submit',e=>{e.preventDefault();busy(f,async()=>{
      const d=new FormData(f),routing={},agent_branches={};Object.keys(modules).forEach(m=>{if(d.get('route_'+m)!=='')routing[m]=Number(d.get('route_'+m));});state.meta.agents.forEach(a=>agent_branches[a.id]=d.get('branches_'+a.id).split(',').map(s=>s.trim()).filter(Boolean));
      const settings={...s,default_owner:Number(d.get('default_owner')),backup_id:Number(d.get('backup_id')),escalate_hours:Number(d.get('escalate_hours')),routing,agent_branches,hours:{...s.hours,'*':{start:d.get('start'),end:d.get('end'),days:d.getAll('day').map(Number)}}};
      await command(f,'support_settings_save',{settings});state.meta=await request('support_meta');announce('Support settings saved.');
    });});
  }
  function onNavigate(page){
    state.currentPage=page;
    const module=['send','quote-status','invoice-check'].includes(page)?'quotes':page;
    if(el('ss-global-help'))el('ss-global-help').hidden=!state.enabled||page==='staff_support'||(!modules[module]&&page!=='home');
    document.querySelectorAll('.ss-module-notice').forEach(n=>n.remove());
    if(!state.enabled||!state.meta.features.incidents||!modules[module]||page==='staff_support')return;
    request('support_issues').then(d=>{
      if(state.currentPage!==page)return;
      const issues=d.issues.filter(i=>i.status!=='resolved'&&(i.module===module||i.module==='general'));
      const target=el('page-'+page);if(!target||!issues.length)return;
      target.insertAdjacentHTML('afterbegin',`<div class="ss-module-notice ss-hint" role="status">${issues.map(i=>`<strong>${h(i.title)}</strong><p>${h(i.explanation)}</p><small>Next update: ${date(i.next_update_at)}</small>`).join('')}<button class="btn btn-s" data-ss-nav="staff_support" data-ss-section="issues">View known issues</button></div>`);
    }).catch(()=>{});
  }
  function button(module,id){return state.enabled?`<button type="button" class="btn btn-s" data-ss-context="${h(module)}" data-record="${h(id)}">Get help with this</button>`:'';}
  async function source(module,id){
    if(!close())return;
    if(typeof window.HFSupportSource==='function' && await window.HFSupportSource(module,id))return;
    if(module==='leads' && typeof leadsOpenEdit==='function'){await navTo('leads',null);await leadsOpenEdit(Number(id));}
    else if(module==='dispatch' && typeof dlvOpenDetail==='function'){await navTo('dispatch',null);await dlvOpenDetail(Number(id));}
    else if(module==='quotes' && typeof doPLLookup==='function'){await navTo('send',null);el('pl-qli').value=id;await doPLLookup();}
    else if(module==='backorders' && typeof filterBackorders==='function'){
      const d=await request('support_context',{module,record_id:id});await navTo('backorders',null);
      el('bo-search').value=d.context.reference;BO_EXPANDED.add(d.context.reference);filterBackorders();
    }
    else if(module==='manuals' && typeof manOpen==='function'){await navTo('manuals',null);await manOpen(id);}
    else if(['procedures','library'].includes(module)){
      const headers={};if(typeof _VIEW_AS_TOKEN!=='undefined'&&_VIEW_AS_TOKEN)headers['X-View-As-Token']=_VIEW_AS_TOKEN;
      const r=await fetch('api.php?'+new URLSearchParams({action:module==='library'?'library_get_file':'get_procedure_file',id}),{credentials:'same-origin',headers});
      if(!r.ok)throw new Error('Approved source unavailable.');const url=URL.createObjectURL(await r.blob());const a=document.createElement('a');a.href=url;a.download=module+'-'+id;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    }
    else {navTo(module==='quotes'?'send':module,null);if(typeof toast==='function')toast('Source reference: '+id);}
  }
  document.addEventListener('click',async e=>{
    const b=e.target.closest('button');if(!b)return;
    try{
      if(b.dataset.ssTime){
        const input=b.closest('label').querySelector('input');const branch=state.detail?.ticket.branch||CU.branch||'';
        const hours=state.meta.settings.hours[branch]||state.meta.settings.hours['*'];
        let candidate=new Date(Date.now()+7200000+(b.dataset.ssTime==='tomorrow'?86400000:3600000));
        if(b.dataset.ssTime==='tomorrow')candidate.setUTCHours(Number(hours.start.slice(0,2)),Number(hours.start.slice(3)),0,0);
        for(let n=0;n<1500;n++,candidate=new Date(candidate.getTime()+15*60000)){
          const day=candidate.getUTCDay()||7,time=candidate.toISOString().slice(11,16);
          if(hours.days.includes(day)&&time>=hours.start&&time<hours.end){input.value=candidate.toISOString().slice(0,16);state.dirty=true;break;}
        }
      }
      if(b.dataset.ssNav){if(el('ss-panel')?.open&&!close())return;navTo(b.dataset.ssNav,null);}
      if(b.dataset.ssContext){e.stopPropagation();await open({module:b.dataset.ssContext,record_id:b.dataset.record});}
      if(b.dataset.ssTicket)await openTicket(Number(b.dataset.ssTicket));
      if(b.dataset.ssSection)await section(b.dataset.ssSection);
      if(b.dataset.ssSource)await source(b.dataset.ssSource,b.dataset.record);
      if(b.dataset.ssFollow){b.disabled=true;try{await request('support_issue_follow',{id:Number(b.dataset.ssFollow),unfollow:!!b.dataset.unfollow,...(el('ss-create-form')?{module:state.context.module,record_id:state.context.record_id}:{}),request_key:key()},true);b.textContent=b.dataset.unfollow?'Unsubscribed':'Following — updates will appear here';if(!el('ss-panel').open)await section('issues');}finally{b.disabled=false;}}
      if(b.dataset.ssHelped){await request('support_answer_helped',{id:Number(b.dataset.ssHelped),request_key:key()},true);close(true);announce('Glad that helped. No request was created.');if(typeof toast==='function')toast('Glad that helped — no ticket needed.');}
      if(b.dataset.ssEditIssue)editIssue(Number(b.dataset.ssEditIssue));
      if(b.dataset.ssEditAnswer)editAnswer(Number(b.dataset.ssEditAnswer));
      if(b.dataset.ssFile){const headers={};if(typeof _VIEW_AS_TOKEN!=='undefined'&&_VIEW_AS_TOKEN)headers['X-View-As-Token']=_VIEW_AS_TOKEN;const r=await fetch('api.php?'+new URLSearchParams({action:'support_file',id:b.dataset.ssFile}),{headers,credentials:'same-origin'});if(!r.ok)throw new Error('File unavailable.');const url=URL.createObjectURL(await r.blob()),a=document.createElement('a');a.href=url;a.download=b.dataset.name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
      switch(b.dataset.ss){
        case 'close':close();break;
        case 'new':case 'general':await open();break;
        case 'website':await open({module:'website',record_id:''});break;
        case 'find-so':if(close()&&window.focusDemoSearch)window.focusDemoSearch();break;
        case 'context-help':await open({module:['send','quote-status','invoice-check'].includes(state.currentPage)?'quotes':modules[state.currentPage]?state.currentPage:'general',record_id:''});break;
        case 'refresh':await loadQueue();break;
        case 'prev':state.offset=Math.max(0,state.offset-50);await loadQueue();break;
        case 'next':state.offset+=50;await loadQueue();break;
        case 'new-issue':editIssue();break;
        case 'new-answer':editAnswer();break;
        case 'resolution-answer':editAnswer(null,true);break;
      }
    }catch(err){if(el('ss-panel')?.open){const p=document.createElement('p');p.className='ss-error';p.setAttribute('role','alert');p.textContent=err.message;el('ss-panel-body').prepend(p);}else announce(err.message,true);}
  });
  window.addEventListener('beforeunload',e=>{if(state.dirty){e.preventDefault();e.returnValue='';}});
  HF.registerModule({id:'staff_support',label:'Staff Support',permission:'staff_support',approveLevel:true,supportFeature:true,icon:'M21 11.5a8.4 8.4 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.4 8.4 0 01-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.4 8.4 0 013.8-.9h.5a8.5 8.5 0 018 8v.5z',homeColor:'#0f766e',homeBg:'#ecfdf5',desc:'Ask for help and follow your requests.',onEnter:enter});
  return {boot,open,openTicket,button,onNavigate,get enabled(){return state.enabled;},reset(){state.enabled=false;state.meta=null;state.detail=null;state.context=null;state.issues=[];state.answers=[];state.queueSequence++;state.dirty=false;for(const id of ['ss-panel-body','ss-queue','ss-issues','ss-answers','ss-section-report','ss-section-settings'])el(id)?.replaceChildren();if(el('ss-panel'))close(true);if(el('ss-global-help'))el('ss-global-help').hidden=true;document.querySelectorAll('.ss-module-notice').forEach(n=>n.remove());el('ss-lead-tabs')?.remove();const lead=HF.modules.find(m=>m.id==='leads');if(lead)lead.label='New Lead';}};
})();
