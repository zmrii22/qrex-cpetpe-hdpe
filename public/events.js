'use strict';
let inspectionSubmitChecking=false;
async function submitInspection(el,nextPallet=false) {
    if(inspectionSubmitChecking) return;
    inspectionSubmitChecking=true;
    const done=buttonBusy(el,'Checking');
    let i,evaluation;
    try {
        if(S.dirty&&!await flush()) return;
        syncActivityState();
        i=S.inspection;
        if(['information','defects','tests','measurements','packing'].every(section=>i.data.section_flags?.[section]==='pass')) {
            evaluation=await evaluateCurrent(true);
        } else {
            const checked=await api('evaluate',{id:i.id,data:i.data});
            if(S.inspection?.id!==i.id) return;
            i.evaluation=checked.evaluation;i.result=checked.result;i.spec=checked.spec||i.spec;
            S.validationGeneration=S.generation;
            evaluation=i.evaluation;
        }
    } catch(err) {
        toast(err.message,true);
        return;
    } finally { done();inspectionSubmitChecking=false; }
    if(!evaluation?.can_submit) {
        S.section='review';renderInspection();
        window.scrollTo({top:0,behavior:'smooth'});
        toast(evaluation?.missing?.[0]?.message||'Complete the remaining items before submitting.',true);
        return;
    }
    const label=nextPallet?'Submit & next pallet':'Submit inspection';
    const f=modal(label,`<p>${esc(i.unit_label)} under SO ${esc(i.so_number)} will be locked and sent for supervisor verification.</p><div class="notice ${i.result==='fail'?'error':'neutral'}">${icon(i.result==='fail'?'alert-circle':'clipboard-check')}<div><strong>Inspection result: ${labels[i.result]}</strong>${i.result==='fail'?'A rejection investigation will be opened.':'A different supervisor must verify this inspection.'}</div></div>`,`<button type="button" class="btn" data-action="close-dialog">Cancel</button><button type="submit" class="btn primary">${icon(nextPallet?'arrow-right':'send')} ${esc(label)}</button>`);
    f.onsubmit=async ev=>{
        ev.preventDefault();
        const b=$('button[type=submit]',f);
        if(b.disabled) return;
        syncActivityState();
        const errorBox=$('#dialog-error');
        if(errorBox){errorBox.className='';errorBox.textContent='';}
        const finish=buttonBusy(b,'Submitting');
        try {
            const saved=await api('submit',{id:i.id,version:i.version,data:i.data,next_pallet:nextPallet});
            S.dirty=false;
            closeModal();
            if(nextPallet&&saved.next_inspection_id) {
                S.section='information';
                toast('Inspection submitted. Opening next pallet.');
                location.hash='inspection?id='+saved.next_inspection_id;
            } else {
                toast(nextPallet?'Inspection submitted. No more editable pallets in this SO.':'Inspection submitted.');
                location.hash='record?id='+i.id;
            }
        } catch(err) {
            if(err.status===422) {
                try {
                    const checked=await api('evaluate',{id:i.id,data:i.data});
                    if(S.inspection?.id===i.id){i.evaluation=checked.evaluation;i.result=checked.result;i.spec=checked.spec||i.spec;}
                    closeModal();S.section='review';renderInspection();
                    toast(checked.evaluation?.missing?.[0]?.message||err.message,true);
                    return;
                } catch {}
            }
            submitRecoveryError(err);
        } finally { finish(); }
    };
}

function buttonBusy(el,label='Loading'){
const original=el.innerHTML;
el.disabled=true;
el.classList.add('is-busy');
el.innerHTML=icon('loader-2')+' '+label;
return ()=>{if(document.body.contains(el)){el.disabled=false;el.classList.remove('is-busy');el.innerHTML=original;}};
}
async function navigateWorkspace(target){
if(currentRoute()===target)await route();
else{history.pushState(null,'','#'+target);await route();}
}
document.addEventListener('click',event=>{
const rail=$('.rail.is-open');
if(rail&&(!rail.contains(event.target)||event.target.closest('.nav-link'))){
rail.classList.remove('is-open');
rail.querySelector('.rail-toggle')?.setAttribute('aria-expanded','false');
}
});
document.addEventListener('keydown',event=>{
if(event.key!=='Escape')return;
const rail=$('.rail.is-open');
rail?.classList.remove('is-open');
rail?.querySelector('.rail-toggle')?.setAttribute('aria-expanded','false');
});
document.addEventListener('click',async e=>{const el=e.target.closest('[data-action]');if(!el||el.disabled)return;const a=el.dataset.action;try{switch(a){
case'toggle-nav':{const rail=el.closest('.rail');el.setAttribute('aria-expanded',String(rail.classList.toggle('is-open')));break;}
case'close-dialog':closeModal();break;
case'retry-submit-dialog':el.closest('form')?.requestSubmit();break;
case'retry':await initialize();break;
case'auth-login':login('signin');break;
case'auth-register':login('register');break;
case'auth-forgot':login('forgot');break;
case'reload-page':await route();break;
case'refresh-records':{const label=el.innerHTML;el.disabled=true;el.classList.add('is-busy');el.innerHTML=icon('loader-2')+' Loading';try{clearWorkspaceCache();await route();}catch(err){if(document.body.contains(el)){el.disabled=false;el.classList.remove('is-busy');el.innerHTML=label;}throw err;}break;}
case'recent-so':await openRecentProductSo(el.dataset.product,el);break;
case'preview-record':{const done=buttonBusy(el,'Opening');try{closeModal();await navigateWorkspace('record?id='+el.dataset.id);}finally{done();}break;}
case'open-inspection':{const done=buttonBusy(el,'Opening');try{closeModal();S.section='information';await navigateWorkspace('inspection?id='+el.dataset.id);}finally{done();}break;}
case'new':await newInspection(el.dataset.product);break;
case'logout':{const r=await api('logout',{});S.csrf=r.csrf||S.csrf;}S.user=null;S.inspection=null;S.meta=null;S.dirty=false;history.replaceState(null,'','#dashboard');closeModal();login();break;
case'account':accountDialog();break;
case'edit-order':syncActivityState();orderDialog();break;
case'help':modal('Quality workspace help',`<div class="help-grid">${icon('stack-2')}<div><h3>Inspection sets</h3><p>Create the order context once and choose the number of pallets or cartons. Each unit retains its own readings and traceability.</p></div>${icon('ruler-measure')}<div><h3>Measurement entry</h3><p>Choose a measurement and enter readings in sample order. Enter advances to the next sample; Shift+Enter moves back. Every reading is checked against its saved specification.</p></div>${icon('clipboard-check')}<div><h3>Review before submit</h3><p>Use Review & submit to validate the current page data. The inspection is written to the database when you submit it.</p></div>${icon('user-check')}<div><h3>Review and verification</h3><p>Complete each activity, resolve missing entries, then submit. A different supervisor verifies the locked record. Contact your administrator for account or master-data changes.</p></div></div>`,button('Close','close-dialog',null));break;
case'clear-filters':location.hash='records'+(el.dataset.product?'?product='+el.dataset.product:'');break;
case'delete-draft':{const f=modal('Delete draft',`<p>This will permanently remove ${esc(el.dataset.label||'this draft inspection')} and its unsent readings, defects and test entries.</p><div class="notice error">${icon('alert-circle')}<div><strong>Only draft inspections can be deleted.</strong>This action cannot be undone after you confirm.</div></div>`,`<button type="button" class="btn" data-action="close-dialog">Cancel</button><button type="submit" class="btn danger">${icon('trash')} Delete draft</button>`);f.onsubmit=async ev=>{ev.preventDefault();const b=$('button[type=submit]',f);b.disabled=true;try{await api('delete_draft',{id:Number(el.dataset.id)});closeModal();toast('Draft deleted.');await route();}catch(err){modalError(err);b.disabled=false;}};break;}
case'recent-table-scroll':{const wrap=el.closest('.wide-table-shell')?.querySelector('.recent-table-wrap')||$('#recent-table-wrap');if(wrap)wrap.scrollBy({left:Number(el.dataset.dir||1)*(wrap.clientWidth*.85),behavior:'smooth'});break;}
case'recent-scroll':{const track=$('#recent-track');if(track)track.scrollBy({left:Number(el.dataset.dir||1)*(track.clientWidth*.9),behavior:'smooth'});break;}
case'page-prev':case'page-next':{const p=new URLSearchParams(currentRoute().split('?')[1]||'');p.set('page',Math.max(1,Number(p.get('page')||1)+(a==='page-next'?1:-1)));location.hash='records?'+p;break;}
case'section':await sectionChange(el.dataset.section);break;
case'issue':await sectionChange(el.dataset.section,el.dataset.key,el.dataset.sample);break;
case'record-issue':{const target=$('#record-'+(el.dataset.key||el.dataset.section))||$('#record-'+el.dataset.section);target?.closest('details')?.setAttribute('open','');target?.setAttribute('open','');target?.scrollIntoView({behavior:'smooth',block:'center'});break;}
case'record-scroll':{const target=document.getElementById(el.dataset.target);target?.scrollIntoView({behavior:'smooth',block:'start'});break;}
case'continue':{const list=Object.keys(sections),n=list.indexOf(S.section),next=Math.min(n+1,list.length-1);await sectionChange(list[next]);setTimeout(()=>focusActivityControl?.('first'),80);break;}
case'metric':syncActivityState();S.metric=el.dataset.metric;renderActivity();break;
case'next-metric':{syncActivityState();const list=S.meta.products[S.inspection.product].metrics.filter(m=>m.section==='measurements');const n=list.findIndex(m=>m.key===S.metric);if(n===list.length-1){await sectionChange('packing');}else{S.metric=list[n+1].key;renderActivity();$('[data-measurement]')?.focus();}break;}
case'save':break;
case'submit':case'submit-next':await submitInspection(el,a==='submit-next');break;
case'units':await unitSwitcher();break;
case'open-unit':closeModal();S.section='information';location.hash=(el.dataset.state==='draft'?'inspection':'record')+'?id='+el.dataset.id;break;
case'test-value':{const i=S.inspection,key=el.dataset.test;i.data.tests[key]??=Array(S.meta.products[i.product].tests.find(t=>t.key===key).samples).fill('na');i.data.tests[key][Number(el.dataset.sample)]=el.dataset.value;markDirty();renderActivity();break;}
case'defect-status':{const i=S.inspection,id=el.dataset.defectId,value=el.dataset.value;const master=S.meta.defects.find(d=>d.id===id&&d.product===i.product);if(!master)throw new Error('Defect master row not found.');i.data.defect_checks??={};i.data.defects??=[];i.data.defect_checks[id]=value;i.data.defects=i.data.defects.filter(d=>d.defect_id!==id);if(value==='fail'){const container=el.closest('.defect-item')||el.closest('tr'),qtyInput=container?.querySelector('[data-defect-qty]');const qty=Math.max(1,Math.min(1000000,Number(qtyInput?.value||1)));i.data.defects.push({...master,defect_id:master.id,quantity:Number.isFinite(qty)?qty:1});}i.data.checked_categories=[...new Set(S.meta.defects.filter(d=>d.product===i.product).map(d=>d.category))];markDirty();renderActivity();break;}
case'add-defect':{const value=$('[name=defect-search]').value.trim();const category=$('[name=defect-category]').value;const match=S.meta.defects.find(d=>d.product===S.inspection.product&&d.category===category&&(d.code+' - '+d.name===value||d.name.toLowerCase()===value.toLowerCase()||d.code.toLowerCase()===value.toLowerCase()));const qty=Number($('[name=defect-quantity]').value);if(!match||!Number.isInteger(qty)||qty<1){toast('Choose a defect from the list and enter a positive quantity.',true);return;}const found=S.inspection.data.defects.find(d=>d.defect_id===match.id);if(found)found.quantity=Number(found.quantity)+qty;else S.inspection.data.defects.push({...match,defect_id:match.id,quantity:qty});markDirty();renderActivity();break;}
case'remove-defect':S.inspection.data.defects.splice(Number(el.dataset.index),1);markDirty();renderActivity();break;
case'restore-recovery':closeModal();break;
case'discard-recovery':closeModal();break;
case'download-recovery':break;
case'verify':await verifyDialog();break;
case'reopen':reopenDialog();break;
case'print':await qrexPrintInspection(el);break;
case'investigation':investigationDialog(el.dataset.id);break;
case'new-user':userDialog();break;
case'edit-user':userDialog(el.dataset.id);break;
case'delete-user':deleteUserDialog(el.dataset.id,el.dataset.label);break;
case'view-spec':{const done=buttonBusy(el,'Opening');try{await viewSpec(el.dataset.id);}finally{done();}break;}
case'new-spec':{const done=buttonBusy(el,'Opening');try{await specDialog();}finally{done();}break;}
case'edit-spec':{const done=buttonBusy(el,'Opening');try{await specDialog(el.dataset.id);}finally{done();}break;}
case'delete-spec':{const done=buttonBusy(el,'Opening');try{deleteSpecDialog(el.dataset.id,el.dataset.label);}finally{done();}break;}
case'apply-spec':applySpecDialog();break;
}}catch(err){toast(err.message,true);}});
window.addEventListener('beforeunload',e=>{if(S.dirty){e.preventDefault();e.returnValue='';}});
initialize();
