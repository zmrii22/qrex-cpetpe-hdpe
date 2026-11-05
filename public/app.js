'use strict';
const $ = (s, root=document) => root.querySelector(s);
const $$ = (s, root=document) => [...root.querySelectorAll(s)];
const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon = name => `<i class="ti ti-${esc(name)}" aria-hidden="true"></i>`;
const labels = {pass:'PASS',fail:'FAIL',pending:'Pending',draft:'Draft',submitted:'Awaiting review',released:'Released',rejected:'Rejected',held:'On hold',hold:'Requires review',unassessed:'Not assessed',na:'N/A',open:'Open',investigating:'Investigating',contained:'Contained',closed:'Closed'};
const status = state => `<span class="status ${esc(state)}">${icon(({pass:'circle-check',fail:'circle-x',released:'circle-check',rejected:'circle-x',hold:'alert-circle',held:'alert-circle',unassessed:'alert-circle',na:'minus',submitted:'clock-check'})[state]||'clock')}${esc(labels[state]||state)}</span>`;
const button = (label, action, glyph, style='', attrs='') => `<button type="button" class="btn ${style}" data-action="${action}" ${attrs}>${glyph?icon(glyph):''}${label}</button>`;
const options = (items,value='',placeholder='Select') => `<option value="">${esc(placeholder)}</option>` + items.map(item=>{const v=typeof item==='object'?item.value:item;const l=typeof item==='object'?item.label:item;return `<option value="${esc(v)}" ${String(v)===String(value)?'selected':''}>${esc(l)}</option>`;}).join('');
const input = (name,label,value='',type='text',attrs='',hint='') => `<label class="field"><span>${esc(label)}</span><input name="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}>${hint?`<small>${esc(hint)}</small>`:''}</label>`;
const select = (name,label,items,value='',attrs='') => `<label class="field"><span>${esc(label)}</span><select name="${esc(name)}" ${attrs}>${options(items,value)}</select></label>`;
const dateText = value => value ? new Date(value.replace(' ','T')).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : 'Not recorded';
const productLabel = p => p==='hdpe'?'M26B':'M26A';
const sizeLabel = row => row?.size_desc || row?.common?.size_desc || row?.size || '';
const factoryOptions = [{value:'M26A',label:'M26A (Plant 3726)'},{value:'M26B',label:'M26B (Plant 6726)'}];
const brandLogo = `<img class="brand-logo" src="assets/images/QREXLogo.png" alt="QREXLogo">`;
let S={user:null,csrf:'',meta:null,route:'',inspection:null,section:'information',metric:'weight',dirty:false,saving:null,generation:0,timer:null,routeTicket:0,sessionLoaded:false};
function toast(message,error=false){const t=$('#toast');t.textContent=message;t.className=`show ${error?'error':''}`;clearTimeout(t.timeout);t.timeout=setTimeout(()=>t.className='',error?10000:4000);}
const workspaceCache=new Map();
const workspaceCacheTtl=5*60*1000;
function clearWorkspaceCache(){workspaceCache.clear();}
function workspaceCacheKey(action,params){
    const query=new URLSearchParams(params);
    for(const [key,value]of [...query])if(value===''||(key==='page'&&Number(value)===1))query.delete(key);
    query.sort();
    return `${S.user?.id||0}:${S.user?.role||''}:${action}:${query}`;
}
function cachedWorkspaceRequest(action,params,request){
    const key=workspaceCacheKey(action,params),previous=workspaceCache.get(key);
    if(previous&&(previous.pending||previous.expires>Date.now()))return previous.promise;
    const entry={pending:true,expires:0,promise:null};
    entry.promise=request().then(body=>{
        entry.pending=false;
        entry.expires=Date.now()+workspaceCacheTtl;
        for(const [oldKey,oldEntry]of workspaceCache){
            if(workspaceCache.size<=30)break;
            if(!oldEntry.pending&&oldKey!==key)workspaceCache.delete(oldKey);
        }
        return body;
    }).catch(error=>{
        if(workspaceCache.get(key)===entry)workspaceCache.delete(key);
        throw error;
    });
    workspaceCache.set(key,entry);
    return entry.promise;
}
async function api(action,data=null,params={},requestOptions={}){
    const changesData=data!==null&&action!=='evaluate';
    const request=async()=>{
        const url='api.php?'+new URLSearchParams({action,...params});
        const options={method:data?'POST':'GET',credentials:'same-origin',headers:data?{'Content-Type':'application/json','X-CSRF-Token':S.csrf}:{},body:data?JSON.stringify(data):undefined,signal:requestOptions.signal};
        if(typeof qrexRequestJson==='function')return await qrexRequestJson(url,options,{timeoutMs:requestOptions.timeoutMs||30000});
        const response=await fetch(url,options);
        let body;
        try{body=await response.json();}catch{throw Error('The server is unavailable. Your entries are still on this page.');}
        if(!response.ok){
            if(response.status===401||response.status===419)clearWorkspaceCache();
            const e=Error(body.error||'The action could not be completed.');e.status=response.status;throw e;
        }
        return body;
    };
    if(data===null&&['records','dashboard'].includes(action))return cachedWorkspaceRequest(action,params,request);
    if(changesData)clearWorkspaceCache();
    try{return await request();}finally{if(changesData)clearWorkspaceCache();}
}
function modal(title,body,footer='',wide=false){const d=$('#dialog');if(d.open)d.close();d.className='';d.style.width=wide?'min(900px,calc(100% - 32px))':'';d.innerHTML=`<form id="dialog-form"><div class="dialog-head"><h2 id="dialog-title">${esc(title)}</h2><button type="button" class="icon-btn" data-action="close-dialog" aria-label="Close dialog">${icon('x')}</button></div><div id="dialog-error"></div><div class="dialog-body">${body}</div>${footer?`<div class="dialog-foot">${footer}</div>`:''}</form>`;d.showModal();setTimeout(()=>$('input,select,textarea,button', $('.dialog-body'))?.focus(),40);return $('#dialog-form');}
function modalError(error){$('#dialog-error').className='dialog-error';$('#dialog-error').textContent=error.message||error;}
function submitRecoveryError(error){const box=$('#dialog-error');box.className='dialog-error submit-recovery';box.innerHTML=`<strong>Submit failed, but your entries are still on this page.</strong><span>${esc(error.message||error)}</span><div class="submit-recovery-actions"><button type="button" class="btn small" data-action="retry-submit-dialog">${icon('refresh')} Retry submit</button><button type="button" class="btn small" data-action="close-dialog">Keep editing</button></div>`;}
function closeModal(){const d=$('#dialog');if(!d)return;if(d.open)d.close();d.className='';d.style.width='';}
function formValues(form){return Object.fromEntries(new FormData(form));}
function pageLoading(){$('#main').innerHTML='<div class="skeleton" role="status">Loading workspace...</div>';}
function shell(){const user=S.user;const initials=user.name.split(' ').slice(0,2).map(x=>x[0]).join('');const link=(path,name,glyph)=>`<a href="#${path}" class="nav-link" data-nav="${path}">${icon(glyph)}<span>${name}</span></a>`;
$('#app').innerHTML=`<aside class="rail"><a class="brand" href="#dashboard" aria-label="QREX dashboard">${brandLogo}<span class="brand-copy">QREX<small>Quality record system</small></span></a><nav aria-label="Main navigation"><div class="nav-group"><p class="eyebrow">Workspace</p>${link('dashboard','Overview','layout-dashboard')}${link('records?product=cpetpe','M26A','stack-2')}${link('records?product=hdpe','M26B','package')}${link('rejections','Rejections','alert-triangle')}</div><div class="nav-group"><p class="eyebrow">Management</p>${link('reports','Reports','chart-bar')}${link('master','Specifications','ruler-measure')}${user.role==='admin'?link('users','Team & access','users'):''}</div></nav><div class="rail-foot"><div class="account"><button class="avatar" data-action="account" title="Account settings">${esc(initials)}</button><div class="account-copy"><strong>${esc(user.name)}</strong><small>${esc(user.role)}</small></div><button class="icon-btn" data-action="logout" title="Sign out" aria-label="Sign out">${icon('logout')}</button></div></div></aside><div class="app-body"><header class="topbar"><div class="breadcrumb"><span>Quality record system</span>${icon('chevron-right')}<strong id="crumb">Overview</strong></div><div class="topbar-end"><span class="connection">Local workspace</span><span>${new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</span><button class="icon-btn" data-action="help" title="Help" aria-label="Help">${icon('help-circle')}</button></div></header><main id="main" class="workspace" tabindex="-1"></main></div>`;}
async function openWorkspace(forceOverview=false){S.meta=await api('bootstrap');shell();if(forceOverview&&currentRoute()!=='dashboard')history.replaceState(null,'','#dashboard');await route();}
async function initialize(){try{if(!S.sessionLoaded){const session=await api('session');S.sessionLoaded=true;S.csrf=session.csrf;S.user=session.user;}if(!S.user){login();return;}await openWorkspace();}catch(e){$('#app').innerHTML=`<div class="startup"><h2>Workspace unavailable</h2><p>${esc(e.message)}</p><p>Ask your administrator to check the server installation.</p>${button('Try again','retry','refresh')}</div>`;}}
function login(mode='signin'){
    S.inspection=null;
    const panels={
        signin:{
            title:'Welcome back',
            copy:'Sign in to your quality workspace.',
            action:'login',
            button:'Sign in',
            fields:`${input('email','Username / Work email','','text','required autocomplete="username"')}${input('password','Password','','password','required autocomplete="current-password"')}`,
            foot:`<button type="button" class="auth-link" data-action="auth-register">Create account</button><button type="button" class="auth-link" data-action="auth-forgot">Forgot password?</button>`
        },
        register:{
            title:'Create account',
            copy:'Create your QREX user account. The first account becomes administrator; later accounts become inspector accounts.',
            action:'register',
            button:'Create account',
            fields:`${input('name','Full name','','text','required autocomplete="name"')}${input('email','Work email','','email','required autocomplete="username"')}${input('employee_id','Employee ID','','text','autocomplete="off"')}${select('factory','Factory / plant',factoryOptions,'M26A')}${input('password','Password','','password','required autocomplete="new-password" minlength="6"')}${input('confirm_password','Confirm password','','password','required autocomplete="new-password" minlength="6"')}`,
            foot:`<button type="button" class="auth-link" data-action="auth-login">Already have an account? Sign in</button>`
        },
        forgot:{
            title:'Reset password',
            copy:'Enter your username or work email and new password. If your account has an Employee ID, enter it for verification.',
            action:'forgot_password',
            button:'Update password',
            fields:`${input('email','Username / Work email','','text','required autocomplete="username"')}${input('employee_id','Employee ID if registered','','text','autocomplete="off"')}${input('password','New password','','password','required autocomplete="new-password" minlength="6"')}${input('confirm_password','Confirm new password','','password','required autocomplete="new-password" minlength="6"')}`,
            foot:`<button type="button" class="auth-link" data-action="auth-login">Back to sign in</button><button type="button" class="auth-link" data-action="auth-register">Create account</button>`
        }
    };
    const panel=panels[mode]||panels.signin;
    $('#app').innerHTML=`<div class="login"><div class="login-intro"><div class="brand login-brand">${brandLogo}<span>QREX<small>Quality record system</small></span></div><div><h1>Confidence in every inspection.</h1><p>Finished good inspection for your manufacturing floor.</p></div><small>M26A &nbsp; / &nbsp; M26B</small></div><main class="login-main" id="main"><form id="login-form" class="login-form" data-auth-action="${panel.action}"><h2>${panel.title}</h2><p>${panel.copy}</p><div id="login-error"></div>${panel.fields}<button class="btn primary" type="submit">${panel.button} ${icon(mode==='forgot'?'key':'arrow-right')}</button><div class="auth-actions">${panel.foot}</div></form></main></div>`;
    $('#login-form').onsubmit=async e=>{
        e.preventDefault();
        const b=$('button[type=submit]',e.target);
        b.disabled=true;
        const values=formValues(e.target),action=e.target.dataset.authAction;
        try{
            const r=await api(action,values);
            if(action==='forgot_password'){
                toast(r.message||'Password updated. Sign in with the new password.');
                login('signin');
                return;
            }
            S.csrf=r.csrf;
            S.user=r.user;
            S.meta=null;
            toast(r.message||'Signed in.');
            await openWorkspace(true);
        }catch(err){
            $('#login-error').innerHTML=`<div class="notice error">${esc(err.message)}</div>`;
        }finally{
            b.disabled=false;
        }
    };
}
function currentRoute(){return (location.hash.slice(1)||'dashboard');}
async function route(){if(!S.user)return;const target=currentRoute();if(S.inspection&&S.dirty){if(!await flush()){history.replaceState(null,'','#'+S.route);return;}}if($('#dialog')?.open)closeModal();const ticket=++S.routeTicket;S.route=target;S.inspection=null;clearTimeout(S.timer);$('.actionbar')?.remove();$('#main').className='workspace';const [path,queryString]=target.split('?');const q=new URLSearchParams(queryString||'');if(path==='records'&&!q.get('product')){location.hash='dashboard';return;}document.body.classList.toggle('compact-form-shell',path==='inspection');$$('.nav-link').forEach(a=>a.classList.toggle('active',a.dataset.nav===target||(path==='inspection'&&a.dataset.nav==='records')));$('#crumb').textContent=({dashboard:'Overview',records:q.get('product')?productLabel(q.get('product'))+' inspections':'Workspace',inspection:'Inspection workspace',record:'Inspection record',reports:'Reports',rejections:'Rejections',users:'Team & access',master:'Specifications'})[path]||'Workspace';pageLoading();
try{switch(path){case'dashboard':await dashboard(ticket);break;case'records':await records(q,ticket);break;case'inspection':await inspection(q,ticket);break;case'record':await record(q,ticket);break;case'rejections':await rejections(q,ticket);break;case'reports':await reports(q,ticket);break;case'users':await users(ticket);break;case'master':await masters(ticket);break;default:location.hash='dashboard';}}catch(e){if(ticket!==S.routeTicket)return;$('#main').innerHTML=`<div class="empty">${icon('alert-circle')}<h3>Unable to open this page</h3><p>${esc(e.message)}</p>${button('Try again','reload-page','refresh')}</div>`;}}
window.addEventListener('hashchange',route);
function canDeleteDraft(r){return r.state==='draft'&&(S.user.role==='admin'||S.user.role==='supervisor'||Number(r.created_by)===Number(S.user.id));}
function recordActions(r){const href=`#${r.state==='draft'?'inspection':'record'}?id=${r.id}`;return `<div class="record-actions"><a href="${href}" class="icon-btn" title="Open inspection" aria-label="Open inspection QI-${r.id}">${icon('arrow-up-right')}</a>${canDeleteDraft(r)?`<button type="button" class="icon-btn danger" data-action="delete-draft" data-id="${r.id}" data-label="QI-${String(r.id).padStart(5,'0')} Â· ${esc(r.unit_label)}" title="Delete draft" aria-label="Delete draft QI-${r.id}">${icon('trash')}</button>`:''}</div>`;}
function recordTable(rows,emptyText='No inspections match these filters.'){if(!rows.length)return `<div class="empty">${icon('clipboard-list')}<h3>No inspections yet</h3><p>${esc(emptyText)}</p>${S.user.role!=='viewer'?button('New inspection','new','plus','primary'):''}</div>`;return `<div class="table-wrap"><table><thead><tr><th>Inspection / order</th><th>Product</th><th>Unit</th><th>Workflow</th><th>Result</th><th>Updated</th><th></th></tr></thead><tbody>${rows.map(r=>`<tr><td><a href="#${r.state==='draft'?'inspection':'record'}?id=${r.id}" class="row-link">SO ${esc(r.so_number)}</a><small>QI-${String(r.id).padStart(5,'0')}${r.customer?' Â· '+esc(r.customer):''}</small></td><td><strong>${esc(r.glove_type)}</strong><small>${productLabel(r.product)} Â· Size ${esc(sizeLabel(r))}</small></td><td>${esc(r.unit_label)}</td><td>${status(r.state)}</td><td>${status(r.result)}</td><td>${dateText(r.updated_at)}<small>${esc(r.inspector)}</small></td><td>${recordActions(r)}</td></tr>`).join('')}</tbody></table></div>`;}
async function records(q,ticket){const result=await api('records',null,Object.fromEntries(q));if(ticket!==S.routeTicket)return;const product=q.get('product')||'';$('#main').innerHTML=`<div class="page-heading"><div><h1>${product?productLabel(product):'All'} inspections</h1><p>Find records, continue drafts, and review inspection results.</p></div><div class="actions"><a class="btn" href="api.php?${new URLSearchParams({action:'export',...Object.fromEntries(q)})}">${icon('download')} Export</a>${S.user.role!=='viewer'?button('New inspection','new','plus','primary',`data-product="${product}"`):''}</div></div><form id="record-filters"><input type="hidden" name="product" value="${esc(product)}"><div class="filters"><label class="search">${icon('search')}<input name="q" aria-label="Search inspections" placeholder="Search order, product or customer" value="${esc(q.get('q'))}"></label><select name="state" aria-label="Workflow status">${options(['draft','submitted','released','held','rejected'].map(v=>({value:v,label:labels[v]})),q.get('state'),'All workflow statuses')}</select><select name="result" aria-label="Inspection result">${options(['pass','fail','hold','pending'].map(v=>({value:v,label:labels[v]})),q.get('result'),'All results')}</select><button type="submit" class="btn">${icon('filter')} Apply</button>${button('Clear','clear-filters','x','ghost',`data-product="${product}"`)}</div><details class="filter-more"><summary>More filters</summary><div class="filter-grid">${input('so','Sales order',q.get('so'))}${input('item','Item number',q.get('item'))}${input('customer','Customer',q.get('customer'))}${input('brand','Brand',q.get('brand'))}${input('glove','Glove type',q.get('glove'))}${select('size','Size',['XXS','XS','S','M','L','XL','XXL','FS'],q.get('size'))}${input('from','Updated from',q.get('from'),'date')}${input('to','Updated to',q.get('to'),'date')}</div></details></form><div class="section-head"><span class="muted" style="font-size:12px">${result.total} inspection${result.total===1?'':'s'}</span></div><div class="surface">${recordTable(result.rows)}<div class="pager"><span>Page ${result.page} of ${result.pages}</span><div class="actions">${button('Previous','page-prev','chevron-left','small',result.page<=1?'disabled':'')}${button('Next','page-next','chevron-right','small',result.page>=result.pages?'disabled':'')}</div></div></div>`;$('#record-filters').onsubmit=e=>{e.preventDefault();const p=new URLSearchParams(formValues(e.target));for(const [k,v]of [...p])if(!v)p.delete(k);location.hash='records?'+p;};}
async function newInspection(product='hdpe'){if(S.dirty&&!await flush())return;let order=null;const form=modal('Create inspection set',`<p>Enter the order context once. QREX will autofill the order data. Choose the sizes and key in how many pallet forms to generate.</p><div class="field-grid two">${select('product','Inspection type',[{value:'cpetpe',label:'CPE / TPE'},{value:'hdpe',label:'HDPE'}],product||'hdpe','required')}${select('factory','Factory / plant',factoryOptions,product==='hdpe'?'M26B':'M26A','required')}</div><div class="field order-search-row"><span>Sales order</span><div class="input-action"><input name="so_number" aria-label="Sales order" type="text" required maxlength="80" placeholder="e.g. DEMO-5700001"><button type="button" class="btn" id="lookup-order">${icon('search')} Find order</button></div></div><div id="order-preview" class="order-preview empty-preview">${icon('database-search')}<div><strong>No order loaded</strong><span>Choose inspection type, factory and SO number, then find the order.</span></div></div>`,`<button class="btn" type="button" data-action="close-dialog">Cancel</button><button class="btn primary" type="submit" disabled>Create inspection set ${icon('arrow-right')}</button>`,true);
const submitButton=$('button[type=submit]',form);const preview=$('#order-preview');
const cartonTotal=(s,count)=>{const counts=s.pallet_carton_counts||[];let total=0;for(let n=0;n<count;n++)total+=Number(counts[n]??s.cartons_per_pallet??0);return total;};
const updateSizeControls=()=>{let ok=false;$$('.size-choice',preview).forEach(card=>{const item=card.dataset.item;const s=order?.sizes.find(row=>row.so_item_no===item);const cb=$('input[name=sizes]',card);const count=$('[data-size-count]',card);const cartons=$('[data-carton-count]',card);const locked=s?.state&&s.state!=='NOT_STARTED';const checked=!!cb?.checked&&!locked;if(!count){card.classList.toggle('selected',false);return;}count.disabled=!checked;card.classList.toggle('selected',checked);const value=Number(count.value||0);const valid=Number.isInteger(value)&&value>=1&&value<=100;if(cartons&&s)cartons.value=valid?cartonTotal(s,value):'';ok=ok||(checked&&valid);});submitButton.disabled=!ok;};
const loadOrder=async()=>{submitButton.disabled=true;order=null;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('loader-2')}<div><strong>Finding order...</strong><span>Checking QAPQC02.QREX_MMSO.</span></div>`;try{order=await api('order_lookup',null,{product:form.elements.product.value,factory:form.elements.factory.value,so:form.elements.so_number.value.trim()});const o=order.order;preview.className='order-preview';preview.innerHTML=`<div class="order-found">${icon('circle-check')}<div><strong>Order found</strong><span>${esc(o.customer)} Â· ${esc(o.product_type)} / ${esc(o.glove_type)} Â· ${Number(o.total_quantity).toLocaleString()} pcs</span></div></div><dl class="order-kv"><div><dt>Factory</dt><dd>${esc(o.factory)}${o.plant?' / '+esc(o.plant):''}</dd></div><div><dt>Item no.</dt><dd>${esc(o.item_number)}</dd></div><div><dt>Lot no.</dt><dd>${esc(o.lot_number)}</dd></div><div><dt>Colour</dt><dd>${esc(o.colour)}</dd></div><div><dt>Brand</dt><dd>${esc(o.brand)}</dd></div><div><dt>Country</dt><dd>${esc(o.country)}</dd></div></dl><div class="size-picker"><div class="subheading"><h3>Inspection units</h3><p>Completed or verified Sales Order items stay visible and locked. Select only the units that are still not started.</p></div>${order.sizes.map(s=>{const locked=s.state&&s.state!=='NOT_STARTED',href=s.existing_id?`#${s.state==='IN_PROGRESS'?'inspection':'record'}?id=${s.existing_id}`:'';return `<div class="size-choice ${locked?'locked':''}" data-item="${esc(s.so_item_no)}"><label class="size-select"><input type="checkbox" name="sizes" value="${esc(s.so_item_no)}" ${locked?'disabled':''} ${s.has_spec?'':'data-missing-spec="1"'}><span><strong>${esc(sizeLabel(s))}</strong><small>SO item ${esc(s.so_item_no)} Â· ${Number(s.quantity).toLocaleString()} pcs Â· planned ${s.pallet_count} pallets Â· ${s.carton_count} cartons${s.has_spec?'':' Â· Specification not available'}</small></span></label><div class="size-counts">${locked?`<span class="status ${s.state==='IN_PROGRESS'?'draft':'submitted'}">${s.state.replace('_',' ')}</span>${href?`<a class="btn small" href="${href}">${s.state==='IN_PROGRESS'?'Resume':'View'}</a>`:''}`:`<label class="mini-field"><span>Pallet forms</span><input type="number" min="1" max="100" step="1" value="${s.form_count}" data-size-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} pallet forms" disabled></label><label class="mini-field"><span>Cartons</span><input type="number" value="${s.carton_count}" data-carton-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} calculated cartons" readonly></label>`}</div></div>`;}).join('')}</div>`;$$('input[name=sizes],[data-size-count]',preview).forEach(el=>el.addEventListener('input',updateSizeControls));$$('input[name=sizes]',preview).forEach(el=>el.addEventListener('change',updateSizeControls));updateSizeControls();}catch(err){preview.className='order-preview error-preview';preview.innerHTML=`${icon('alert-circle')}<div><strong>Order not found</strong><span>${esc(err.message)}</span><small>Choose M26A (3726) or M26B (6726), then enter an SO from QREX_MMSO.</small></div>`;}};
$('#lookup-order').onclick=loadOrder;['product','factory'].forEach(name=>form.elements[name].addEventListener('change',()=>{order=null;submitButton.disabled=true;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('database-search')}<div><strong>No order loaded</strong><span>Find the order again after changing ${name.replace('_',' ')}.</span></div>`;}));form.elements.so_number.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadOrder();}});
form.onsubmit=async e=>{e.preventDefault();if(!order){await loadOrder();if(!order)return;}const sizes=$$('input[name=sizes]:checked',preview).map(cb=>cb.value);if(!sizes.length){modalError('Choose at least one size.');return;}const unit_counts={};for(const card of $$('.size-choice',preview)){const cb=$('input[name=sizes]',card);if(!cb?.checked)continue;const value=Number($('[data-size-count]',card).value||0);if(!Number.isInteger(value)||value<1||value>50){modalError('Enter between 1 and 50 pallet forms for each selected size.');return;}unit_counts[cb.value]=value;}const b=$('button[type=submit]',form);b.disabled=true;try{const r=await api('create',{product:form.elements.product.value,factory:form.elements.factory.value,so_number:form.elements.so_number.value.trim(),sizes,unit_counts});closeModal();S.section='information';location.hash='inspection?id='+r.id;}catch(err){modalError(err);b.disabled=false;}};}
newInspection=async function(product='hdpe'){if(S.dirty&&!await flush())return;let order=null;const form=modal('Create inspection set',`<p>Enter the order context once. QREX will autofill the order data. Choose the sizes and key in how many pallet forms to generate.</p><div class="field-grid two">${select('product','Inspection type',[{value:'cpetpe',label:'CPE / TPE'},{value:'hdpe',label:'HDPE'}],product||'hdpe','required')}${select('factory','Factory / plant',factoryOptions,product==='hdpe'?'M26B':'M26A','required')}</div><div class="field order-search-row"><span>Sales order</span><div class="input-action"><input name="so_number" aria-label="Sales order" type="text" required maxlength="80" placeholder="e.g. DEMO-5700001"><button type="button" class="btn" id="lookup-order">${icon('search')} Find order</button></div></div><div id="order-preview" class="order-preview empty-preview">${icon('database-search')}<div><strong>No order loaded</strong><span>Choose inspection type, factory and SO number, then find the order.</span></div></div>`,`<button class="btn" type="button" data-action="close-dialog">Cancel</button><button class="btn primary" type="submit" disabled>Create inspection set ${icon('arrow-right')}</button>`,true);
const submitButton=$('button[type=submit]',form);const preview=$('#order-preview');
const previewField=(name,label,value,limit=120)=>`<div><dt>${esc(label)}</dt><dd><input name="${esc(name)}" aria-label="${esc(label)}" value="${esc(value)}" maxlength="${limit}" ${name==='ov_item_code'?'placeholder="Fill Item Number"':''}></dd></div>`;
const cartonTotal=(s,count)=>{const counts=s.pallet_carton_counts||[];let total=0;for(let n=0;n<count;n++)total+=Number(counts[n]??s.cartons_per_pallet??0);return total;};
const updateSizeControls=()=>{let ok=false;$$('.size-choice',preview).forEach(card=>{const item=card.dataset.item;const s=order?.sizes.find(row=>row.so_item_no===item);const cb=$('input[name=sizes]',card);const count=$('[data-size-count]',card);const cartons=$('[data-carton-count]',card);const locked=s?.state&&s.state!=='NOT_STARTED';const checked=!!cb?.checked&&!locked;if(!count){card.classList.toggle('selected',false);return;}count.disabled=!checked;card.classList.toggle('selected',checked);const value=Number(count.value||0);const valid=Number.isInteger(value)&&value>=1&&value<=100;if(cartons&&s)cartons.value=valid?cartonTotal(s,value):'';ok=ok||(checked&&valid);});submitButton.disabled=!ok;};
const loadOrder=async()=>{submitButton.disabled=true;order=null;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('loader-2')}<div><strong>Finding order...</strong><span>Checking QAPQC02.QREX_MMSO.</span></div>`;try{order=await api('order_lookup',null,{product:form.elements.product.value,factory:form.elements.factory.value,so:form.elements.so_number.value.trim()});const o=order.order;preview.className='order-preview';preview.innerHTML=`<div class="order-found">${icon('circle-check')}<div><strong>Order found</strong><span>${esc(o.customer)} Â· ${esc(o.product_type)} / ${esc(o.glove_type)} Â· ${Number(o.total_quantity).toLocaleString()} pcs</span></div></div><dl class="order-kv editable-preview">${previewField('ov_factory_display','Factory',`${o.factory}${o.plant?' / '+o.plant:''}`,80)}${previewField('ov_item_code','Item no.',o.item_number)}${previewField('ov_lot_number','Lot no.',o.lot_number,180)}${previewField('ov_colour','Colour',o.colour,80)}${previewField('ov_brand','Brand',o.brand,120)}${previewField('ov_country','Country',o.country,80)}</dl><div class="size-picker"><div class="subheading"><h3>Inspection units</h3><p>Completed or verified Sales Order items stay visible and locked. Select only the units that are still not started.</p></div>${order.sizes.map(s=>{const locked=s.state&&s.state!=='NOT_STARTED',href=s.existing_id?`#${s.state==='IN_PROGRESS'?'inspection':'record'}?id=${s.existing_id}`:'';return `<div class="size-choice ${locked?'locked':''}" data-item="${esc(s.so_item_no)}"><label class="size-select"><input type="checkbox" name="sizes" value="${esc(s.so_item_no)}" ${locked?'disabled':''} ${s.has_spec?'':'data-missing-spec="1"'}><span><strong>${esc(sizeLabel(s))}</strong><small>SO item ${esc(s.so_item_no)} Â· ${Number(s.quantity).toLocaleString()} pcs Â· planned ${s.pallet_count} pallets Â· ${s.carton_count} cartons${s.has_spec?'':' Â· Specification not available'}</small></span></label><div class="size-counts">${locked?`<span class="status ${s.state==='IN_PROGRESS'?'draft':'submitted'}">${s.state.replace('_',' ')}</span>${href?`<a class="btn small" href="${href}">${s.state==='IN_PROGRESS'?'Resume':'View'}</a>`:''}`:`<label class="mini-field"><span>Pallet forms</span><input type="number" min="1" max="100" step="1" value="${s.form_count}" data-size-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} pallet forms" disabled></label><label class="mini-field"><span>Cartons</span><input type="number" value="${s.carton_count}" data-carton-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} calculated cartons" readonly></label>`}</div></div>`;}).join('')}</div>`;$$('input[name=sizes],[data-size-count]',preview).forEach(el=>el.addEventListener('input',updateSizeControls));$$('input[name=sizes]',preview).forEach(el=>el.addEventListener('change',updateSizeControls));updateSizeControls();}catch(err){preview.className='order-preview error-preview';preview.innerHTML=`${icon('alert-circle')}<div><strong>Order not found</strong><span>${esc(err.message)}</span><small>Choose M26A (3726) or M26B (6726), then enter an SO from QREX_MMSO.</small></div>`;}};
$('#lookup-order').onclick=loadOrder;['product','factory'].forEach(name=>form.elements[name].addEventListener('change',()=>{order=null;submitButton.disabled=true;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('database-search')}<div><strong>No order loaded</strong><span>Find the order again after changing ${name.replace('_',' ')}.</span></div>`;}));form.elements.so_number.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadOrder();}});
form.onsubmit=async e=>{e.preventDefault();if(!order){await loadOrder();if(!order)return;}const sizes=$$('input[name=sizes]:checked',preview).map(cb=>cb.value);if(!sizes.length){modalError('Choose at least one size.');return;}const unit_counts={};for(const card of $$('.size-choice',preview)){const cb=$('input[name=sizes]',card);if(!cb?.checked)continue;const value=Number($('[data-size-count]',card).value||0);if(!Number.isInteger(value)||value<1||value>50){modalError('Enter between 1 and 50 pallet forms for each selected size.');return;}unit_counts[cb.value]=value;}const order_overrides={factory_display:form.elements.ov_factory_display?.value||'',item_code:form.elements.ov_item_code?.value||'',lot_number:form.elements.ov_lot_number?.value||'',colour:form.elements.ov_colour?.value||'',brand:form.elements.ov_brand?.value||'',country:form.elements.ov_country?.value||''};const b=$('button[type=submit]',form);b.disabled=true;try{const r=await api('create',{product:form.elements.product.value,factory:form.elements.factory.value,so_number:form.elements.so_number.value.trim(),sizes,unit_counts,order_overrides});closeModal();S.section='information';location.hash='inspection?id='+r.id;}catch(err){modalError(err);b.disabled=false;}};}

newInspection=async function(product='hdpe'){if(S.dirty&&!await flush())return;let order=null;const selectedProduct=['hdpe','cpetpe'].includes(product)?product:'cpetpe';const form=modal('Create inspection set',`<p>Enter the order context once. QREX will autofill the order data. Choose the sizes and key in how many pallet forms to generate.</p><div class="field-grid two">${select('product','Inspection type',[{value:'cpetpe',label:'CPE / TPE'},{value:'hdpe',label:'HDPE'}],selectedProduct,'required')}${select('factory','Factory / plant',factoryOptions,selectedProduct==='hdpe'?'M26B':'M26A','required')}</div><div class="field order-search-row"><span>Sales order</span><div class="input-action"><input name="so_number" aria-label="Sales order" type="text" required maxlength="80" placeholder="e.g. 2000119546"><button type="button" class="btn" id="lookup-order">${icon('search')} Find order</button></div></div><div id="order-preview" class="order-preview empty-preview">${icon('database-search')}<div><strong>No order loaded</strong><span>Choose inspection type, factory and SO number, then find the order.</span></div></div>`,`<button class="btn" type="button" data-action="close-dialog">Cancel</button><button class="btn primary" type="submit" disabled>Create inspection set ${icon('arrow-right')}</button>`,true);
const submitButton=$('button[type=submit]',form);const preview=$('#order-preview');const previewField=(name,label,value,limit=120)=>`<div><dt>${esc(label)}</dt><dd><input name="${esc(name)}" aria-label="${esc(label)}" value="${esc(value)}" maxlength="${limit}" ${name==='ov_item_code'?'placeholder="Fill Item Number"':''}></dd></div>`;const cartonTotal=(s,count)=>{const counts=s.pallet_carton_counts||[];let total=0;for(let n=0;n<count;n++)total+=Number(counts[n]??s.cartons_per_pallet??0);return total;};const updateSizeControls=()=>{let ok=false;$$('.size-choice',preview).forEach(card=>{const item=card.dataset.item;const s=order?.sizes.find(row=>row.so_item_no===item);const cb=$('input[name=sizes]',card);const count=$('[data-size-count]',card);const cartons=$('[data-carton-count]',card);const locked=s?.state&&s.state!=='NOT_STARTED';const checked=!!cb?.checked&&!locked;if(!count){card.classList.toggle('selected',false);return;}count.disabled=!checked;card.classList.toggle('selected',checked);const value=Number(count.value||0);const valid=Number.isInteger(value)&&value>=1&&value<=100;if(cartons&&s)cartons.value=valid?cartonTotal(s,value):'';ok=ok||(checked&&valid);});submitButton.disabled=!ok;};
const resetPreview=message=>{order=null;submitButton.disabled=true;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('database-search')}<div><strong>No order loaded</strong><span>${esc(message)}</span></div>`;};
const loadOrder=async()=>{submitButton.disabled=true;order=null;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('loader-2')}<div><strong>Finding order...</strong><span>Checking QAPQC02.QREX_MMSO by inspection type, factory and Sales Order.</span></div>`;try{order=await api('order_lookup',null,{product:form.elements.product.value,factory:form.elements.factory.value,so:form.elements.so_number.value.trim()});const o=order.order;preview.className='order-preview';preview.innerHTML=`<div class="order-found">${icon('circle-check')}<div><strong>Order found</strong><span>${esc(productLabel(o.product))} Â· ${esc(o.customer)} Â· ${esc(o.product_type)} / ${esc(o.glove_type)} Â· ${Number(o.total_quantity).toLocaleString()} pcs</span></div></div><dl class="order-kv editable-preview">${previewField('ov_factory_display','Factory',`${o.factory}${o.plant?' / '+o.plant:''}`,80)}${previewField('ov_item_code','Item no.',o.item_number)}${previewField('ov_lot_number','Lot no.',o.lot_number,180)}${previewField('ov_colour','Colour',o.colour,80)}${previewField('ov_brand','Brand',o.brand,120)}${previewField('ov_country','Country',o.country,80)}</dl><div class="size-picker"><div class="subheading"><h3>Inspection units</h3><p>Completed or verified Sales Order items stay visible and locked. Select only the units that are still not started.</p></div>${order.sizes.map(s=>{const locked=s.state&&s.state!=='NOT_STARTED',href=s.existing_id?`#${s.state==='IN_PROGRESS'?'inspection':'record'}?id=${s.existing_id}`:'';return `<div class="size-choice ${locked?'locked':''}" data-item="${esc(s.so_item_no)}"><label class="size-select"><input type="checkbox" name="sizes" value="${esc(s.so_item_no)}" ${locked?'disabled':''} ${s.has_spec?'':'data-missing-spec="1"'}><span><strong>${esc(sizeLabel(s))}</strong><small>${esc(productLabel(s.product))} Â· SO item ${esc(s.so_item_no)} Â· ${Number(s.quantity).toLocaleString()} pcs Â· planned ${s.pallet_count} pallets Â· ${s.carton_count} cartons${s.has_spec?'':' Â· Specification not available'}</small></span></label><div class="size-counts">${locked?`<span class="status ${s.state==='IN_PROGRESS'?'draft':'submitted'}">${s.state.replace('_',' ')}</span>${href?`<a class="btn small" href="${href}">${s.state==='IN_PROGRESS'?'Resume':'View'}</a>`:''}`:`<label class="mini-field"><span>Pallet forms</span><input type="number" min="1" max="100" step="1" value="${s.form_count}" data-size-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} pallet forms" disabled></label><label class="mini-field"><span>Cartons</span><input type="number" value="${s.carton_count}" data-carton-count="${esc(s.so_item_no)}" aria-label="${esc(sizeLabel(s))} calculated cartons" readonly></label>`}</div></div>`;}).join('')}</div>`;$$('input[name=sizes],[data-size-count]',preview).forEach(el=>el.addEventListener('input',updateSizeControls));$$('input[name=sizes]',preview).forEach(el=>el.addEventListener('change',updateSizeControls));updateSizeControls();}catch(err){preview.className='order-preview error-preview';preview.innerHTML=`${icon('alert-circle')}<div><strong>Order not found</strong><span>${esc(err.message)}</span><small>Choose the correct inspection type. M26B uses plant 6726; M26A uses plant 3726.</small></div>`;}};
$('#lookup-order').onclick=loadOrder;form.elements.product.addEventListener('change',()=>{form.elements.factory.value=form.elements.product.value==='hdpe'?'M26B':'M26A';resetPreview('Find the order again after changing inspection type.');});form.elements.factory.addEventListener('change',()=>resetPreview('Find the order again after changing factory.'));form.elements.so_number.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadOrder();}});form.elements.so_number.addEventListener('input',()=>{if(order)resetPreview('Find the order again after changing Sales Order.');});
form.onsubmit=async e=>{e.preventDefault();if(!order){await loadOrder();if(!order)return;}const sizes=$$('input[name=sizes]:checked',preview).map(cb=>cb.value);if(!sizes.length){modalError('Choose at least one Sales Order item.');return;}const unit_counts={};for(const card of $$('.size-choice',preview)){const cb=$('input[name=sizes]',card);if(!cb?.checked)continue;const value=Number($('[data-size-count]',card).value||0);if(!Number.isInteger(value)||value<1||value>50){modalError('Enter between 1 and 50 pallet forms for each selected item.');return;}unit_counts[cb.value]=value;}const order_overrides={factory_display:form.elements.ov_factory_display?.value||'',item_code:form.elements.ov_item_code?.value||'',lot_number:form.elements.ov_lot_number?.value||'',colour:form.elements.ov_colour?.value||'',brand:form.elements.ov_brand?.value||'',country:form.elements.ov_country?.value||''};const b=$('button[type=submit]',form);b.disabled=true;try{const r=await api('create',{product:form.elements.product.value,factory:form.elements.factory.value,so_number:form.elements.so_number.value.trim(),sizes,unit_counts,order_overrides});closeModal();S.section='information';location.hash='inspection?id='+r.id;}catch(err){modalError(err);b.disabled=false;}};
}

function templateSummaryGrid(template){const entries=Object.entries(template||{}).filter(([k])=>!['SO No.','Glove Type / Code','Pallet No.','Size','Final Disposition'].includes(k));if(!entries.length)return'<p class="muted">No template summary available.</p>';return `<dl class="template-summary-grid">${entries.map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${esc(v||'Not recorded')}</dd></div>`).join('')}</dl>`;}

function recentTemplateValue(row,label){return row.template?.[label]||'Not recorded';}
function recentInspectionTable(rows){if(!rows.length)return recordTable(rows,'Create an inspection set to begin. Each pallet or carton will have its own inspection record.');const cols=['Fac','Customer','Country','Product Type','Item No.','Lot No.','Color','Brand','Sample Qty (Ctn x pcs)','Inspection Count','Final Disposition','Reading'];return `<div class="wide-table-shell"><div class="wide-table-controls"><span class="muted">${rows.length} recent inspection${rows.length===1?'':'s'}</span><div class="actions">${button('Left','recent-table-scroll','chevron-left','small',`data-dir="-1"`)}${button('Right','recent-table-scroll','chevron-right','small',`data-dir="1"`)}</div></div><div class="table-wrap recent-table-wrap" id="recent-table-wrap"><table class="recent-wide-table"><thead><tr><th>Inspection / order</th><th>Product</th><th>Unit</th><th>Workflow</th><th>Result</th><th>Updated</th>${cols.map(c=>`<th>${esc(c)}</th>`).join('')}<th></th></tr></thead><tbody>${rows.map(r=>`<tr><td><button class="row-link as-button" data-action="preview-record" data-id="${r.id}">SO ${esc(r.so_number)}</button><small>QI-${String(r.id).padStart(5,'0')}${r.customer?' Â· '+esc(r.customer):''}</small></td><td><strong>${esc(r.glove_type)}</strong><small>${productLabel(r.product)} Â· Size ${esc(sizeLabel(r))}</small></td><td>${esc(r.unit_label)}</td><td>${status(r.state)}</td><td>${status(r.result)}</td><td>${dateText(r.updated_at)}<small>${esc(r.inspector)}</small></td>${cols.map(c=>`<td>${esc(recentTemplateValue(r,c))}</td>`).join('')}<td><div class="record-actions"><button type="button" class="icon-btn" data-action="preview-record" data-id="${r.id}" title="Preview inspection" aria-label="Preview inspection QI-${r.id}">${icon('eye')}</button><a href="#${r.state==='draft'?'inspection':'record'}?id=${r.id}" class="icon-btn" title="Open inspection" aria-label="Open inspection QI-${r.id}">${icon('arrow-up-right')}</a>${canDeleteDraft(r)?`<button type="button" class="icon-btn danger" data-action="delete-draft" data-id="${r.id}" data-label="QI-${String(r.id).padStart(5,'0')} Â· ${esc(r.unit_label)}" title="Delete draft" aria-label="Delete draft QI-${r.id}">${icon('trash')}</button>`:''}</div></td></tr>`).join('')}</tbody></table></div></div>`;}
async function inspectionPreviewDialog(id){const i=await api('inspection',null,{id});const order={'Factory':i.common.factory,'Plant':i.common.plant,'Item number':i.common.item_no,'Lot number':i.common.lot_no,'Customer':i.common.customer,'Country':i.common.country,'Brand':i.common.brand,'Product type':i.common.product_type,'Colour':i.common.colour};const body=`<div class="preview-head"><div><p class="eyebrow">${productLabel(i.product)} inspection Â· QI-${String(i.id).padStart(5,'0')}</p><h3>SO ${esc(i.so_number)} / ${esc(i.unit_label)}</h3><p>${esc(i.glove_type)} Â· Size ${esc(sizeLabel(i))}</p></div><div class="recent-status">${status(i.state)}${status(i.result)}</div></div><dl class="key-values preview-grid">${Object.entries(order).map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${esc(v||'Not recorded')}</dd></div>`).join('')}</dl><div class="divider"></div><div class="compact-result-list">${[['Visual inspection',i.evaluation.sections.defects],['Performance tests',i.evaluation.sections.tests],['Measurements',i.evaluation.sections.measurements],['Packing',i.evaluation.sections.packing]].map(([name,state])=>`<div><span>${esc(name)}</span><strong>${esc(labels[state]||state)}</strong>${status(state)}</div>`).join('')}</div>`;modal('Inspection preview',body,`<button type="button" class="btn" data-action="close-dialog">Close</button><a class="btn primary" href="#${i.editable?'inspection':'record'}?id=${i.id}" onclick="document.getElementById('dialog').close()">${icon('arrow-up-right')} Open inspection</a>`,true);}

const templateColumns=[
    ['Fac','Fac'],['SO No.','SO No.'],['Customer','Customer'],['Country','Country'],['Product Type','Product Type'],
    ['Glove Type / Code','Glove Type / Code'],['Pallet No.','Pallet No.'],['Size','Size'],['Color','Color'],['Brand','Brand'],
    ['Sample Qty (Ctn x pcs)','Sample Qty (Ctn x pcs)'],['Inspection Count','Inspection Count'],['Qty of Ctn per Pallet','Qty of Ctn per Pallet'],['Inspection Date','Inspection Date'],
    ['Critical defect','Critical defect'],['Holes','Holes'],['Major Viusal','Major Visual'],['Minor Visual','Minor Visual'],['Packaging Defects','Packaging Defects'],['Counting','Counting'],
    ['Packer ID','Packer ID'],['Inspector ID','Inspector ID'],['Verify ID','Verify ID'],['Verify Date','Verify Date'],['Final Disposition','Final Disposition'],
    ['Defects Name','Defects Name'],['Total','Total'],['Disposition','Disposition'],['P/F','P/F'],['Reading','Reading']
];
function templateColumnValue(row,key){return row.template?.[key]||'Not recorded';}
function recordActions(r){const href=`#${r.state==='draft'?'inspection':'record'}?id=${r.id}`;return `<div class="record-actions"><a href="${href}" class="icon-btn" title="Open inspection" aria-label="Open inspection QI-${r.id}">${icon('arrow-up-right')}</a>${canDeleteDraft(r)?`<button type="button" class="icon-btn danger" data-action="delete-draft" data-id="${r.id}" data-label="QI-${String(r.id).padStart(5,'0')} Â· ${esc(r.unit_label)}" title="Delete draft" aria-label="Delete draft QI-${r.id}">${icon('trash')}</button>`:''}</div>`;}
function recordTable(rows,emptyText='No inspections match these filters.'){if(!rows.length)return `<div class="empty">${icon('clipboard-list')}<h3>No inspections yet</h3><p>${esc(emptyText)}</p>${S.user.role!=='viewer'?button('New inspection','new','plus','primary'):''}</div>`;return `<div class="wide-table-shell"><div class="wide-table-controls"><span class="muted">${rows.length} inspection${rows.length===1?'':'s'} shown</span><div class="actions">${button('Left','recent-table-scroll','chevron-left','small',`data-dir="-1"`)}${button('Right','recent-table-scroll','chevron-right','small',`data-dir="1"`)}</div></div><div class="table-wrap recent-table-wrap"><table class="recent-wide-table template-wide-table"><thead><tr><th>Inspection / order</th><th>Product</th><th>Unit</th><th>Workflow</th><th>Result</th><th>Updated</th>${templateColumns.map(([label])=>`<th>${esc(label)}</th>`).join('')}<th></th></tr></thead><tbody>${rows.map(r=>`<tr><td><a class="row-link" href="#record?id=${r.id}">SO ${esc(r.so_number)}</a><small>QI-${String(r.id).padStart(5,'0')}${r.customer?' Â· '+esc(r.customer):''}</small></td><td><strong>${esc(r.glove_type)}</strong><small>${productLabel(r.product)} Â· Size ${esc(sizeLabel(r))}</small></td><td>${esc(r.unit_label)}</td><td>${status(r.state)}</td><td>${status(r.result)}</td><td>${dateText(r.updated_at)}<small>${esc(r.inspector)}</small></td>${templateColumns.map(([,key])=>`<td>${esc(templateColumnValue(r,key))}</td>`).join('')}<td>${recordActions(r)}</td></tr>`).join('')}</tbody></table></div></div>`;}
function recentInspectionTable(rows){return recordTable(rows,'Create an inspection set to begin. Each pallet or carton will have its own inspection record.');}

newInspection=async function(product='hdpe'){
    if(S.dirty&&!await flush())return;
    let order=null;
    let lookupTicket=0,lookupController=null,lookupPending=false,creating=false;
    const selectedProduct=['hdpe','cpetpe'].includes(product)?product:'cpetpe';
    const form=modal('Create inspection set',`<div class="field-grid creation-context">${select('product','Inspection type',[{value:'cpetpe',label:'CPE / TPE'},{value:'hdpe',label:'HDPE'}],selectedProduct,'required')}${select('factory','Factory / plant',factoryOptions,selectedProduct==='hdpe'?'M26B':'M26A','required')}${select('inspection_plan','Inspection Plan',S.meta.sampling?.plans||[],'','required')}<div class="field order-search-row"><span>Sales order</span><div class="input-action"><input name="so_number" aria-label="Sales order" type="text" required maxlength="80" placeholder="e.g. 2000119546"><button type="button" class="btn" id="lookup-order">${icon('search')} Find order</button></div></div></div><div id="order-preview" class="order-preview empty-preview">${icon('database-search')}<div><strong>No order loaded</strong><span>Choose inspection type, factory and SO number, then find the order.</span></div></div>`,`<button class="btn" type="button" data-action="close-dialog">Cancel</button><button class="btn primary" type="submit" disabled>Create inspection set ${icon('arrow-right')}</button>`,true);
    $('#dialog').classList.add('inspection-create-dialog');
    const submitButton=$('button[type=submit]',form);
    const preview=$('#order-preview');
    const lookupButton=$('#lookup-order'),lookupLabel=lookupButton.innerHTML;
    const finishLookup=()=>{lookupPending=false;lookupButton.disabled=false;lookupButton.classList.remove('is-busy');lookupButton.innerHTML=lookupLabel;};
    const cancelLookup=()=>{lookupTicket++;lookupController?.abort();lookupController=null;finishLookup();};
    $('#dialog').addEventListener('close',cancelLookup,{once:true});
    $('#dialog').addEventListener('cancel',event=>{if(creating)event.preventDefault();});
    const previewField=(name,label,value,limit=120)=>{const placeholders={ov_item_code:'Fill Item Number',ov_lot_number:'Fill In',ov_country:'Fill In'};return `<div><dt>${esc(label)}</dt><dd><input name="${esc(name)}" aria-label="${esc(label)}" value="${esc(value)}" maxlength="${limit}" ${placeholders[name]?`placeholder="${esc(placeholders[name])}"`:''}></dd></div>`;};
    const previewSelect=(name,label,choices,value)=>`<div><dt>${esc(label)}</dt><dd><select name="${esc(name)}" aria-label="${esc(label)}">${options((choices||[]).map(v=>({value:v,label:v})),value)}</select></dd></div>`;
    const maxGeneratedUnits=50;
    const palletBasis=s=>Number(s?.so_qty||0)>0?Number(s.so_qty):Number(s?.quantity||0);
    const orderQtyText=s=>`${Number(s.quantity||0).toLocaleString()} pcs${Number(s.so_qty||0)>0?` @ ${Number(s.so_qty).toLocaleString()}${s.uom?' '+esc(s.uom):''}`:''}`;
    const minCartonPerPallet=s=>Math.max(1,Math.ceil(Math.max(1,palletBasis(s))/maxGeneratedUnits));
    const wholeNumberText=value=>/^\d+$/.test(String(value||'').trim());
    const calcPallets=(quantity,cartonsPerPallet)=>Math.max(1,Math.ceil(Math.max(1,Number(quantity)||1)/Math.max(1,Number(cartonsPerPallet)||1)));
    const cartonPlanError=(s,value,whole,raw)=>{
        const basis=Math.max(1,palletBasis(s));
        if(!raw)return'Enter Carton per pallet/lot/batch';
        if(!whole)return'Use whole number only';
        if(value<1)return'Enter Carton per pallet/lot/batch';
        if(value>basis)return`Too big. Maximum is ${basis.toLocaleString()}`;
        const pallets=calcPallets(basis,value);
        if(pallets>maxGeneratedUnits)return`Too many pallets: ${pallets}. Maximum ${maxGeneratedUnits}`;
        return'';
    };
    const createKeyStore='qrex-create-keys-v1';
    const createPayloadKey=payload=>`${S.user?.id||0}:${payload.product}:${payload.factory}:${payload.inspection_plan}:${payload.so_number}:${payload.sizes.join(',')}:${JSON.stringify(payload.cartons_per_pallet)}:${JSON.stringify(payload.unit_counts)}:${JSON.stringify(payload.lot_numbers)}:${JSON.stringify(payload.order_overrides)}`;
    const createKeyRead=payload=>{
        try{
            const all=JSON.parse(sessionStorage.getItem(createKeyStore)||'{}');
            const key=createPayloadKey(payload);
            if(all[key])return all[key];
            const token=(globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2));
            all[key]=token;
            sessionStorage.setItem(createKeyStore,JSON.stringify(Object.fromEntries(Object.entries(all).slice(-20))));
            return token;
        }catch{
            return globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2);
        }
    };
    const createKeyClear=payload=>{
        try{
            const all=JSON.parse(sessionStorage.getItem(createKeyStore)||'{}');
            delete all[createPayloadKey(payload)];
            sessionStorage.setItem(createKeyStore,JSON.stringify(all));
        }catch{}
    };
    const createDisabledState=new WeakMap();
    const setCreateLocked=(locked,total=0)=>{
        creating=locked;
        $$('input,select,button',form).forEach(el=>{
            if(el===submitButton)return;
            if(locked){
                if(!createDisabledState.has(el))createDisabledState.set(el,el.disabled);
                el.disabled=true;
            }else{
                el.disabled=createDisabledState.get(el)??false;
                createDisabledState.delete(el);
            }
        });
        if(locked){
            submitButton.disabled=true;
            submitButton.classList.add('is-busy');
            submitButton.innerHTML=icon('loader-2')+` Creating ${total||''}`.trim();
            preview.insertAdjacentHTML('afterbegin',`<div class="notice neutral create-progress">${icon('loader-2')}<div><strong>Creating inspection set...</strong><span>${total?`Preparing ${total} pallet form${total===1?'':'s'}.`:''} Keep this dialog open until it finishes.</span></div></div>`);
        }else{
            $('.create-progress',preview)?.remove();
        }
    };
    const resetPreview=message=>{cancelLookup();order=null;submitButton.disabled=true;preview.className='order-preview empty-preview';preview.innerHTML=`${icon('database-search')}<div><strong>No order loaded</strong><span>${esc(message)}</span></div>`;};
    const updateSizeControls=()=>{
        let ok=false;
        $$('.size-choice',preview).forEach(card=>{
            const item=card.dataset.item;
            const s=order?.sizes.find(row=>(row.key||row.so_item_no)===item);
            const cb=$('input[name=sizes]',card);
            const cartonInput=$('[data-cartons-per-pallet]',card);
            const lotInput=$('[data-lot-number]',card);
            const calc=$('[data-calculated-pallets]',card);
            const locked=s?.state&&s.state!=='NOT_STARTED';
            const checked=!!cb?.checked&&!locked;
            if(!cartonInput){card.classList.toggle('selected',false);return;}
            cartonInput.disabled=!checked;
            if(lotInput)lotInput.disabled=!checked;
            card.classList.toggle('selected',checked);
            const raw=cartonInput.value.trim();
            const whole=wholeNumberText(raw);
            const value=whole?Number(raw):0;
            const pallets=s?calcPallets(palletBasis(s),value):0;
            const minRequired=s?minCartonPerPallet(s):1;
            const error=s?cartonPlanError(s,value,whole,raw):'';
            const valid=!error&&whole&&value>=minRequired&&value<=100000&&pallets>=1&&pallets<=maxGeneratedUnits;
            if(calc)calc.textContent=error||`${pallets} pallet${pallets===1?'':'s'} from ${Number(palletBasis(s)).toLocaleString()} / ${value}`;
            ok=ok||(checked&&valid);
            card.classList.toggle('invalid-plan',checked&&!valid);
        });
        submitButton.disabled=!ok;
    };
    const loadOrder=async()=>{
        if(lookupPending||creating)return;
        const ticket=++lookupTicket;
        lookupPending=true;
        lookupController=new AbortController();
        const current=()=>ticket===lookupTicket&&form.isConnected&&$('#dialog-form')===form;
        if(lookupButton){lookupButton.disabled=true;lookupButton.classList.add('is-busy');lookupButton.innerHTML=icon('loader-2')+' Finding';}
        submitButton.disabled=true;
        order=null;
        preview.className='order-preview empty-preview';
        preview.innerHTML=`${icon('loader-2')}<div><strong>Finding order...</strong><span>Checking QAPQC02.QREX_MMSO.</span></div>`;
        try{
            const found=await api('order_lookup',null,{product:form.elements.product.value,factory:form.elements.factory.value,so:form.elements.so_number.value.trim()},{signal:lookupController.signal});
            if(!current())return;
            order=found;
            const o=order.order;
            let gloveOptions=S.meta.products[form.elements.product.value]?.references?.['Glove Type']||[];
            if(o.glove_type&&!gloveOptions.includes(o.glove_type))gloveOptions=[o.glove_type,...gloveOptions];
            preview.className='order-preview';
            preview.innerHTML=`<div class="order-found">${icon('circle-check')}<div><strong>Order found</strong><span>${esc(productLabel(o.product))} &middot; ${esc(o.customer)} &middot; ${esc(o.product_type)} / ${esc(o.glove_type)} &middot; ${Number(o.total_quantity).toLocaleString()} pcs</span></div></div><dl class="order-kv editable-preview">${previewField('ov_factory_display','Factory',`${o.factory}${o.plant?' / '+o.plant:''}`,80)}${previewField('ov_item_code','Item no.','')}${previewField('ov_colour','Colour',o.colour,80)}${previewField('ov_brand','Brand',o.brand,120)}${previewSelect('ov_glove_type_code','Glove Type / Code',gloveOptions,o.glove_type)}${previewField('ov_country','Country',o.country,80)}</dl><div class="size-picker"><div class="subheading"><h3>Inspection units</h3><p>Lot No is taken from the database batch for each selected SO item. Key in Carton per pallet/lot/batch.</p></div>${order.sizes.map(s=>{const key=s.key||s.so_item_no;const locked=s.state&&s.state!=='NOT_STARTED',href=s.existing_id?`#${s.state==='IN_PROGRESS'?'inspection':'record'}?id=${s.existing_id}`:'';const minRequired=minCartonPerPallet(s);return `<div class="size-choice ${locked?'locked':''}" data-item="${esc(key)}"><label class="size-select"><input type="checkbox" name="sizes" value="${esc(key)}" ${locked?'disabled':''} ${s.has_spec?'':'data-missing-spec="1"'}><span><strong>${esc(sizeLabel(s))}</strong><small>${esc(productLabel(s.product))} &middot; SO item ${esc(s.so_item_no)} &middot; ${orderQtyText(s)}${s.has_spec?'':' &middot; Specification not available'}</small></span></label><div class="size-counts carton-plan-counts">${locked?`<span class="status ${s.state==='IN_PROGRESS'?'draft':'submitted'}">${s.state.replace('_',' ')}</span>${href?`<a class="btn small" href="${href}">${s.state==='IN_PROGRESS'?'Resume':'View'}</a>`:''}`:`<label class="mini-field lot-number-field"><span>Lot No</span><input type="text" maxlength="180" value="${esc(s.lot_number||s.batch_no||'')}" data-lot-number="${esc(key)}" aria-label="${esc(sizeLabel(s))} lot number" readonly disabled></label><label class="mini-field carton-per-pallet-field"><span>Carton per pallet/lot/batch</span><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="${esc(s.cartons_per_pallet||'')}" data-cartons-per-pallet="${esc(key)}" aria-label="${esc(sizeLabel(s))} carton per pallet lot batch" data-min-cartons="${minRequired}" disabled><small data-calculated-pallets></small></label>`}</div></div>`;}).join('')}</div>`;
            $$('input[name=sizes],[data-cartons-per-pallet]',preview).forEach(el=>el.addEventListener('input',updateSizeControls));
            $$('[data-cartons-per-pallet]',preview).forEach(el=>{
                el.addEventListener('keydown',event=>{if(['.','e','E','+','-'].includes(event.key))event.preventDefault();});
                el.addEventListener('paste',event=>{const text=(event.clipboardData||window.clipboardData).getData('text');if(text&&!/^\d+$/.test(text.trim()))event.preventDefault();});
            });
            $$('input[name=sizes]',preview).forEach(el=>el.addEventListener('change',updateSizeControls));
            updateSizeControls();
        }catch(err){
            if(!current()||err.name==='AbortError')return;
            preview.className='order-preview error-preview';
            const temporary=Number(err.status)===503;
            const driverMissing=String(err.message||'').toLowerCase().includes('sql server driver');
            preview.innerHTML=`${icon('alert-circle')}<div><strong>${driverMissing?'SQL Server driver missing':temporary?'QAPQC unavailable':'Order not found'}</strong><span>${esc(err.message)}</span><small>${driverMissing?'Install or enable pdo_sqlsrv for this XAMPP PHP, then restart Apache.':temporary?'Try Find order again. If it keeps failing, check the SQL Server connection.':'Check the Sales Order in QAPQC02.QREX_MMSO for plants 3726 or 6726.'}</small></div>`;
        }finally{
            if(ticket===lookupTicket)finishLookup();
        }
    };
    $('#lookup-order').onclick=loadOrder;
    form.elements.product.addEventListener('change',()=>resetPreview('Find the order again after changing inspection type.'));
    form.elements.factory.addEventListener('change',()=>resetPreview('Find the order again after changing factory.'));
    form.elements.so_number.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadOrder();}});
    form.elements.so_number.addEventListener('input',()=>resetPreview('Find the order again after changing Sales Order.'));
    form.onsubmit=async e=>{
        e.preventDefault();
        if(creating||lookupPending)return;
        if(!order){await loadOrder();if(!order)return;}
        const sizes=$$('input[name=sizes]:checked',preview).map(cb=>cb.value);
        if(!sizes.length){modalError('Choose at least one Sales Order item.');return;}
        const cartons_per_pallet={},unit_counts={},lot_numbers={};
        for(const card of $$('.size-choice',preview)){
            const cb=$('input[name=sizes]',card);
            if(!cb?.checked)continue;
            const s=order.sizes.find(row=>(row.key||row.so_item_no)===cb.value);
            const raw=$('[data-cartons-per-pallet]',card).value.trim();
            const value=wholeNumberText(raw)?Number(raw):0;
            const pallets=calcPallets(palletBasis(s),value);
            const error=cartonPlanError(s,value,wholeNumberText(raw),raw);
            if(error||value<minCartonPerPallet(s)||value>100000||pallets<1||pallets>maxGeneratedUnits){modalError(error||`Enter a whole-number Carton per pallet/lot/batch so the calculated pallet count is between 1 and ${maxGeneratedUnits}.`);return;}
            cartons_per_pallet[cb.value]=value;
            unit_counts[cb.value]=pallets;
            lot_numbers[cb.value]=$('[data-lot-number]',card)?.value.trim()||'';
        }
        const order_overrides={factory_display:form.elements.ov_factory_display?.value||'',item_code:form.elements.ov_item_code?.value||'',colour:form.elements.ov_colour?.value||'',brand:form.elements.ov_brand?.value||'',glove_type_code:form.elements.ov_glove_type_code?.value||'',country:form.elements.ov_country?.value||''};
        const payload={product:form.elements.product.value,factory:form.elements.factory.value,inspection_plan:form.elements.inspection_plan.value,so_number:form.elements.so_number.value.trim(),sizes,cartons_per_pallet,unit_counts,lot_numbers,order_overrides};
        payload.creation_key=createKeyRead(payload);
        const totalUnits=Object.values(unit_counts).reduce((a,v)=>a+Number(v||0),0);
        const b=$('button[type=submit]',form);
        const label=b.innerHTML;
        setCreateLocked(true,totalUnits);
        try{
            const r=await api('create',payload,{}, {timeoutMs:120000});
            createKeyClear(payload);
            closeModal();
            S.section='information';
            location.hash='inspection?id='+r.id;
        }catch(err){
            modalError(err);
            setCreateLocked(false);
            b.classList.remove('is-busy');
            b.innerHTML=label;
            updateSizeControls();
        }
    };
}
function bindRecordFilters(form){
    form.onsubmit=async e=>{
        e.preventDefault();
        const p=new URLSearchParams(formValues(e.target));
        for(const [k,v]of [...p])if(!String(v||'').trim())p.delete(k);
        if(p.get('product')&&!p.get('so')){
            toast('Enter a Sales Order number first.',true);
            e.target.elements.so?.focus();
            return;
        }
        p.delete('page');
        const b=e.submitter||$('button[type=submit]',form);
        if(b){
            b.disabled=true;
            b.classList.add('is-busy');
            b.innerHTML=icon('loader-2')+' Loading';
        }
        const next='records?'+p;
        if(currentRoute()===next)await route();
        else location.hash=next;
    };
}
function productRecordPrompt(product){
    return `<div class="surface"><div class="empty records-search-empty">${icon('search')}<h3>Search by Sales Order Number</h3><p>${esc(productLabel(product))} records are loaded only after you enter an SO number. This keeps Turso fast and avoids loading the whole table.</p></div></div>`;
}
async function openRecentProductSo(product,buttonEl=null){
    const p=['hdpe','cpetpe'].includes(product)?product:'';
    if(!p){toast('Choose HDPE or CPE / TPE first.',true);return;}
    const label=buttonEl?.innerHTML;
    if(buttonEl){
        buttonEl.disabled=true;
        buttonEl.classList.add('is-busy');
        buttonEl.innerHTML=icon('loader-2')+' Recent SO';
    }
    try{
        const r=await api('recent_so',null,{product:p});
        const next='records?'+new URLSearchParams({product:p,so:r.so});
        if(currentRoute()===next)await route();
        else location.hash=next;
    }catch(err){
        if(buttonEl&&document.body.contains(buttonEl)){
            buttonEl.disabled=false;
            buttonEl.classList.remove('is-busy');
            buttonEl.innerHTML=label;
        }
        throw err;
    }
}
function recordsShell(q,body){
    const product=q.get('product')||'', productMode=!!product, hasSo=!!q.get('so');
    const exportAttrs=productMode&&!hasSo?'aria-disabled="true" tabindex="-1"':'';
    const exportClass=productMode&&!hasSo?'btn disabled':'btn';
    const exportHref=productMode&&!hasSo?'#':'api.php?'+new URLSearchParams({action:'export',...Object.fromEntries(q)});
    const title=product?productLabel(product):'All';
    const copy=productMode?'Search one Sales Order at a time, then review matching inspection units.':'Find records, continue drafts, and review inspection results.';
    const extraFilters=`<details class="filter-more" open><summary>More filters</summary><div class="filter-grid">${input('item','Item number',q.get('item'))}${input('customer','Customer',q.get('customer'))}${input('brand','Brand',q.get('brand'))}${input('glove','Glove type',q.get('glove'))}${select('size','Size',['XXS','XS','S','M','L','XL','XXL','FS'],q.get('size'))}${input('from','Updated from',q.get('from'),'date')}${input('to','Updated to',q.get('to'),'date')}</div></details>`;
    const controls=productMode
        ? `<div class="filters records-so-search"><label class="search">${icon('search')}<input name="so" aria-label="Sales order number" placeholder="Enter Sales Order number" value="${esc(q.get('so'))}" autocomplete="off" required></label><select name="state" aria-label="Workflow status">${options(['draft','submitted','released','held','rejected'].map(v=>({value:v,label:labels[v]})),q.get('state'),'All workflow statuses')}</select><select name="result" aria-label="Inspection result">${options(['pass','fail','hold','pending'].map(v=>({value:v,label:labels[v]})),q.get('result'),'All results')}</select><button type="submit" class="btn primary">${icon('filter')} Apply filter</button>${button('Recent SO','recent-so','history','',`data-product="${product}"`)}${button('Clear','clear-filters','x','ghost',`data-product="${product}"`)}</div>${extraFilters}`
        : `<div class="filters"><label class="search">${icon('search')}<input name="q" aria-label="Search inspections" placeholder="Search order, product or customer" value="${esc(q.get('q'))}"></label><select name="state" aria-label="Workflow status">${options(['draft','submitted','released','held','rejected'].map(v=>({value:v,label:labels[v]})),q.get('state'),'All workflow statuses')}</select><select name="result" aria-label="Inspection result">${options(['pass','fail','hold','pending'].map(v=>({value:v,label:labels[v]})),q.get('result'),'All results')}</select><button type="submit" class="btn">${icon('filter')} Apply</button>${button('Clear','clear-filters','x','ghost',`data-product="${product}"`)}</div><details class="filter-more" open><summary>More filters</summary><div class="filter-grid">${input('so','Sales order',q.get('so'))}${input('item','Item number',q.get('item'))}${input('customer','Customer',q.get('customer'))}${input('brand','Brand',q.get('brand'))}${input('glove','Glove type',q.get('glove'))}${select('size','Size',['XXS','XS','S','M','L','XL','XXL','FS'],q.get('size'))}${input('from','Updated from',q.get('from'),'date')}${input('to','Updated to',q.get('to'),'date')}</div></details>`;
    return `<div class="page-heading"><div><h1>${esc(title)} inspections</h1><p>${esc(copy)}</p></div><div class="actions"><a class="${exportClass}" href="${exportHref}" ${exportAttrs}>${icon('download')} Export</a>${S.user.role!=='viewer'?button('New inspection','new','plus','primary',`data-product="${product}"`):''}</div></div><form id="record-filters"><input type="hidden" name="product" value="${esc(product)}">${controls}</form><div id="records-data">${body}</div>`;
}
async function appendInspectionRows(target,rows,total,ticket){
    const body=$('tbody',target),label=$('.wide-table-controls > span',target);
    if(label)label.textContent=`${total} inspections shown`;
    const table=body?.closest('table');
    if(table){
        const widths=[...table.tHead.rows[0].cells].map(cell=>cell.getBoundingClientRect().width);
        const columns=document.createElement('colgroup');
        widths.forEach(width=>{const column=document.createElement('col');column.style.width=width+'px';columns.append(column);});
        table.prepend(columns);
        table.style.width=widths.reduce((sum,width)=>sum+width,0)+'px';
        table.style.tableLayout='fixed';
    }
    for(let offset=0;offset<rows.length;offset+=25){
        if(!document.hidden)await new Promise(resolve=>requestAnimationFrame(()=>setTimeout(resolve,0)));
        else await new Promise(resolve=>setTimeout(resolve,0));
        if(ticket!==S.routeTicket || !target.isConnected)return;
        const template=document.createElement('template');
        template.innerHTML=recordTable(rows.slice(offset,offset+25));
        const next=template.content.querySelector('tbody');
        if(body&&next)body.append(...next.children);
    }
}
records=async function(q,ticket){
    const product=q.get('product')||'', productMode=!!product, hasSo=!!q.get('so');
    const initialBody=productMode&&!hasSo?productRecordPrompt(product):`<div class="surface records-loading-surface"><div class="skeleton" role="status">Loading workspace...</div></div>`;
    $('#main').innerHTML=recordsShell(q,initialBody);
    $('.page-heading .actions').insertAdjacentHTML('afterbegin',`<button type="button" class="icon-btn" data-action="refresh-records" title="Refresh inspections" aria-label="Refresh inspections" ${productMode&&!hasSo?'disabled':''}>${icon('refresh')}</button>`);
    bindRecordFilters($('#record-filters'));
    if(productMode&&!hasSo){
        $('#records-data').setAttribute('aria-busy','false');
        $('[name=so]')?.focus();
        return;
    }
    const target=$('#records-data');
    target.setAttribute('aria-busy','true');
    try{
        const result=await api('records',null,Object.fromEntries(q));
        if(ticket!==S.routeTicket)return;
        const summary=productMode?`SO ${esc(q.get('so'))} · ${result.total} inspection${result.total===1?'':'s'}`:`${result.total} inspection${result.total===1?'':'s'}`;
        const staged=result.rows.length>100 && new Set(result.rows.map(row=>row.product)).size===1;
        const initialRows=staged?result.rows.slice(0,30):result.rows;
        target.innerHTML=`<div class="section-head"><span class="muted" style="font-size:12px">${summary}</span></div><div class="surface">${recordTable(initialRows,productMode?'No inspections found for this Sales Order.':'No inspections match these filters.')}<div class="pager"><span>Page ${result.page} of ${result.pages}</span><div class="actions">${button('Previous','page-prev','chevron-left','small',result.page<=1?'disabled':'')}${button('Next','page-next','chevron-right','small',result.page>=result.pages?'disabled':'')}</div></div></div>`;
        if(staged) await appendInspectionRows(target,result.rows.slice(30),result.rows.length,ticket);
    }catch(error){
        if(ticket!==S.routeTicket)return;
        target.innerHTML=`<div class="empty" role="alert">${icon('alert-circle')}<h3>Unable to load inspections</h3><p>${esc(error.message)}</p>${button('Try again','refresh-records','refresh')}</div>`;
    }finally{
        if(ticket===S.routeTicket){target.setAttribute('aria-busy','false');$('[data-action="refresh-records"]').disabled=false;}
    }
};
