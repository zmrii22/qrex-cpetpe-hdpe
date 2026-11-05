'use strict';

// Corporate-office letterhead, not the address of the inspecting factory.
// Source: https://www.topglove.com/storage/annualreports/December2024/TG%20Integrated%20Annual%20Report%202024.pdf
const qrexInspectionLetterhead = {
    company: 'TOP GLOVE CORPORATION BHD',
    address: ['Level 21, Top Glove Tower, 16, Persiaran Setia Dagang,',
        'Setia Alam, Seksyen U13, 40170 Shah Alam,',
        'Selangor Darul Ehsan, Malaysia.'],
    logo: 'assets/images/TopGloveLogo.png'
};

function qrexFormValue(value) {
    return value === null || value === undefined || value === '' ? '--' : String(value);
}

function qrexFormResult(result) {
    return ({pass:'PASS', fail:'FAIL', na:'N/A', pending:'PENDING', hold:'REQUIRES REVIEW', unassessed:'NOT ASSESSED'})[result] || String(result || 'pending').toUpperCase();
}

function qrexFormTable(headers, rows, className='') {
    return `<table class="inspection-form-table ${className}"><thead><tr>${headers.map(label=>`<th scope="col">${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map(cell=>`<td>${esc(qrexFormValue(cell))}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}

function qrexFormFields(entries) {
    const rows=[];
    for(let n=0;n<entries.length;n+=2) {
        rows.push(`<tr>${[entries[n],entries[n+1]].map(entry=>entry?`<th scope="row">${esc(entry[0])}</th><td>${esc(qrexFormValue(entry[1]))}</td>`:'<th></th><td></td>').join('')}</tr>`);
    }
    return `<table class="inspection-form-table inspection-form-fields"><tbody>${rows.join('')}</tbody></table>`;
}

function qrexFormSection(title, body, className='') {
    return `<section class="inspection-form-section ${className}"><h2>${esc(title)}</h2>${body}</section>`;
}

function qrexFormHeader(i, title) {
    const profile=qrexInspectionLetterhead;
    return `<header class="inspection-form-letterhead"><img src="${esc(profile.logo)}" alt="Top Glove" width="150" height="55"><div class="inspection-form-company"><strong>${esc(profile.company)}</strong><p>${profile.address.map(line=>esc(line)).join('<br>')}</p><small>Corporate office &nbsp; | &nbsp; www.topglove.com</small></div><div class="inspection-form-control"><strong>QI-${String(i.id).padStart(5,'0')}</strong><span>Record version ${esc(i.version)}</span><span>${esc(productLabel(i.product))} / ${i.product==='hdpe'?'HDPE':'CPE / TPE'}</span></div></header><div class="inspection-form-title"><h1>FINISHED GOOD INSPECTION FORM</h1><p>${esc(title)}</p></div><div class="inspection-form-identity"><strong>SO ${esc(i.so_number)}</strong><span>${esc(i.unit_label)} &nbsp; | &nbsp; Size ${esc(sizeLabel(i))}</span><strong>${esc(qrexFormResult(i.result))}</strong></div>`;
}

function qrexFormSheet(i, title, body) {
    // A table header repeats the letterhead if unusually long notes span pages.
    return `<table class="inspection-form-sheet"><thead><tr><td>${qrexFormHeader(i,title)}</td></tr></thead><tbody><tr><td class="inspection-form-body">${body}</td></tr></tbody><tfoot><tr><td><div class="inspection-form-footer"><span>QI-${String(i.id).padStart(5,'0')} / Version ${esc(i.version)}</span><span>${esc(title)} &nbsp; | &nbsp; ${esc(labels[i.state] || i.state)}</span></div></td></tr></tfoot></table>`;
}

function qrexFormEffectiveResult(i, section, result) {
    return qrexFormResult(qrexEffectiveSectionResult(i,section,result || 'pending'));
}

function qrexFormMetricTable(i, title, metrics) {
    if(!metrics.length)return '';
    const max=Math.max(...metrics.map(metric=>metric.samples));
    if(metrics.every(metric=>metric.section==='tests')) {
        const rows=metrics.map(metric=>[recordMeasurementLabel(metric),measurementLimitSummary(i,metric),...Array.from({length:max},(_,n)=>n<metric.samples?i.data.measurements?.[metric.key]?.[n]:'N/A'),qrexFormEffectiveResult(i,metric.section,i.evaluation.checks[metric.key]?.result)]);
        return qrexFormSection(title,qrexFormTable(['Parameter','Specification',...Array.from({length:max},(_,n)=>'Sample '+(n+1)),'Result'],rows));
    }
    const rows=[['Specification',...metrics.map(metric=>measurementLimitSummary(i,metric))]];
    for(let n=0;n<max;n++) rows.push([String(n+1).padStart(2,'0'),...metrics.map(metric=>n<metric.samples?i.data.measurements?.[metric.key]?.[n]:'N/A')]);
    rows.push(['Result',...metrics.map(metric=>qrexFormEffectiveResult(i,metric.section,i.evaluation.checks[metric.key]?.result))]);
    return qrexFormSection(title,qrexFormTable(['Sample',...metrics.map(metric=>`${recordMeasurementLabel(metric)}${metric.unit?' ('+metric.unit+')':''}`)],rows,'inspection-form-readings'));
}

function qrexInspectionForm(i) {
    const f=i.data.fields,common=i.common || {},evaluation=i.evaluation,definition=S.meta.products[i.product];
    const plan=S.meta.sampling?.plans?.find(row=>row.value===f.inspection_plan)?.label || 'Not recorded';
    const metadata=qrexFormFields([
        ['Inspection date',f.inspection_date],['Inspection Count',f.inspection_status],
        ['Factory / plant',`${common.factory || productLabel(i.product)} / ${common.plant || '--'}`],['SO item / item number',common.item_no || i.so_item_no],
        ['Customer',common.customer],['Country',common.country],
        ['Glove type / code',f.glove_type_code || i.glove_type],['Product type',common.product_type],
        ['Brand',common.brand],['Colour',common.colour],
        ['Batch number',f.batch_no],['Lot number',f.lot_no || common.lot_no],
        ['Pallet / carton number',`${f.pallet_no || i.unit_label} / ${f.carton_no || '--'}`],['Cartons per pallet / lot / batch',f.cartons_per_pallet],
        ['Inspection Plan',plan],['Sample Size (pcs)',f.sample_size],
        ['Sample quantity (cartons x pcs)',f.sample_qty],['Specification reference',i.spec?.reference],
        ['Specification revision',i.spec?.revision],['Form status',labels[i.state] || i.state]
    ]);
    const categories=['Critical','Holes','Major','Minor','Packaging'];
    const reference=categories.slice(0,4).map(category=>{
        const limit=qrexSamplingLimit(i,category);
        return [category,limit?.aql ?? '--',limit?.acc ?? '--',limit?.rej ?? '--'];
    });
    const hasReference=!!f.inspection_plan && ['125','200','315'].includes(String(f.sample_size));
    const aql=qrexFormSection(`AQL reference - ${plan} / Sample Size ${f.sample_size || '--'}`,hasReference?qrexFormTable(['Category','AQL','Acc','Rej'],reference,'inspection-form-aql'):'<p class="inspection-form-note">A saved Inspection Plan and valid Sample Size are required for the workbook reference.</p>');
    const visual=qrexFormSection('Visual inspection results',qrexFormTable(['Category','Observed quantity','Result'],categories.map(category=>{
        const check=evaluation.checks['defect_'+category];
        const total=(i.data.defects || []).filter(row=>row.category===category).reduce((sum,row)=>sum+Number(row.quantity),0);
        return [category,total,qrexFormEffectiveResult(i,'defects',check?.result)];
    })));
    const defects=i.data.defects || [];
    const defectRows=rows=>qrexFormTable(['Category','Code','Recorded defect','Quantity'],rows.map(row=>[row.category,row.code,row.name,row.quantity]),'inspection-form-defects');
    const defectBody=defects.length?defectRows(defects.slice(0,10)):'<p class="inspection-form-note">No visual defect quantities recorded.</p>';
    const defectNote=defects.length>10?'<p class="inspection-form-note">Additional recorded defects continue in the visual inspection appendix.</p>':'';
    const overview=qrexFormSection('1. Order context & unit identity',metadata)+aql+visual+qrexFormSection('Recorded visual defects',defectBody+defectNote);

    const qualitative=definition.tests.map(test=>{
        const values=i.data.tests[test.key] || [];
        const check=evaluation.checks[test.key];
        const reasons=({header_seal:'header_seal_defect',layering:'layering_defect'})[test.key];
        return [test.label,Array.from({length:test.samples},(_,n)=>qrexFormResult(values[n] || 'na')).join(' / '),reasons?f[reasons]:null,qrexFormEffectiveResult(i,'tests',check?.result)];
    });
    const performance=qrexFormSection('2. Performance tests',qrexFormTable(['Test parameter','Recorded answers (sample order)','Recorded failure reason','Result'],qualitative,'inspection-form-tests'));
    const dimensions=definition.metrics.filter(metric=>metric.section==='measurements'&&!['weight','thickness'].includes(metric.key));
    const measurements=performance+qrexFormMetricTable(i,'Performance readings',definition.metrics.filter(metric=>metric.section==='tests'))
        +qrexFormMetricTable(i,'3. Dimensional measurements',dimensions)
        +qrexFormMetricTable(i,'Weight & thickness readings',definition.metrics.filter(metric=>['weight','thickness'].includes(metric.key)))
        +qrexFormFields([['Length failure reason',f.length_a_defect || f.glove_length_defect],['Gap length failure reason',f.gap_length_defect]]);

    const mode=f.counting_mode==='polybag'?'polybag':'inner';
    const counts=[['pcs','Pieces'],[mode,mode==='polybag'?'Polybag':'Inner']].map(([key,label])=>[
        label,f['count_'+key],f['count_'+key+'_result'],qrexFormEffectiveResult(i,'packing',evaluation.checks['count_'+key]?.result)
    ]);
    const packing=qrexFormSection('4. Packing & traceability',qrexFormFields([
        ['Packer ID',f.packer_id],['Inspector ID',f.inspector_id],
        [i.product==='hdpe'?'HD number':'STM number',f.machine_no],['Embossment',f.embossment],
        ['Stamping date',f.stamping_date],['Scale ID',f.scale_id],['Ruler ID',f.ruler_id],['Gauge ID',f.gauge_id]
    ])+qrexFormTable(['Counting check','Recorded quantity','Recorded disposition','Result'],counts));
    const sections=[['information','Unit information'],['defects','Visual inspection'],['tests','Performance tests'],['measurements','Measurements'],['packing','Packing & traceability']];
    const results=qrexFormSection('5. Activity results & final disposition',qrexFormTable(['Activity','Effective result','Manual PASS override'],sections.map(([key,label])=>[label,qrexFormEffectiveResult(i,key,evaluation.sections[key]),i.data.section_flags?.[key]==='pass'?'YES':'--']))
        +qrexFormFields([['Final disposition',qrexFormResult(i.result)],['Form status',labels[i.state] || i.state],['Passed / failed checks',`${evaluation.passed} / ${evaluation.failed}`],['Incomplete entries',evaluation.missing.length]])
        +'<p class="inspection-form-note">Manual PASS overrides are reflected in the effective results. Recorded readings, answers and defect quantities are retained unchanged.</p>');
    const notes=qrexFormSection('6. Remarks',`<div class="inspection-form-remarks"><strong>Visual inspection:</strong> ${esc(f.defect_remarks || '--')}<br><strong>Inspection:</strong> ${esc(f.remarks || '--')}</div>`);
    const attention=evaluation.issues.length?qrexFormSection('Recorded exceptions',qrexFormTable(['Exception'],evaluation.issues.map(issue=>[issue.message || issue.label || issue.key]))):'';
    const verifications=i.verifications || [];
    const signoff=qrexFormSection('7. Inspection & verification',qrexFormFields([
        ['Inspected by',i.inspector_name],['Inspector ID',f.inspector_id],
        ['Submitted at',i.submitted_at],['Last saved at',i.updated_at],
        ['Entered Verify ID',f.verify_id],['Inspection record UID',i.inspection_uid]
    ])+(verifications.length?qrexFormTable(['Verified by','Decision','Date / record version','Verification notes'],verifications.map(row=>[row.reviewer,labels[row.decision] || row.decision,`${row.created_at} / v${row.inspection_version}`,row.notes])):'<div class="inspection-form-signatures"><div><span>Inspected by / signature</span><strong>'+esc(i.inspector_name || '--')+'</strong></div><div><span>Verified by / signature</span><strong>Not yet verified</strong></div></div>'));
    const sheets=[qrexFormSheet(i,'Order particulars & visual inspection',overview),qrexFormSheet(i,'Performance tests & measurement record',measurements),qrexFormSheet(i,'Packing, disposition & verification',packing+results+notes+attention+signoff)];
    for(let start=10;start<defects.length;start+=24)sheets.push(qrexFormSheet(i,'Visual inspection appendix',qrexFormSection('Recorded visual defects (continued)',defectRows(defects.slice(start,start+24)))));
    return `<article id="inspection-print-form" aria-label="Formal inspection print form">${sheets.join('')}</article>`;
}

function qrexPrepareInspectionPrint() {
    const i=S.record;
    if(!i || !/^#record(?:\?|$)/.test(location.hash))return null;
    let form=document.getElementById('inspection-print-form');
    if(!form) {
        document.getElementById('main').insertAdjacentHTML('beforeend',qrexInspectionForm(i));
        form=document.getElementById('inspection-print-form');
    }
    return form;
}

const qrexRecordBeforeFormalPrint=record;
record=async function(q,ticket) {
    await qrexRecordBeforeFormalPrint(q,ticket);
    if(ticket===S.routeTicket)qrexPrepareInspectionPrint();
};

let qrexInspectionPrintBusy=false;
async function qrexPrintInspection(button) {
    if(qrexInspectionPrintBusy)return;
    qrexInspectionPrintBusy=true;
    const done=buttonBusy(button,'Preparing');
    const title=document.title;
    let timeout;
    try {
        const form=qrexPrepareInspectionPrint();
        if(!form)throw Error('Open an inspection record before printing.');
        const savedId=S.record.id,routeTicket=S.routeTicket;
        await Promise.race([
            Promise.all([...form.querySelectorAll('img')].map(image=>image.decode().catch(()=>{throw Error('The Top Glove logo could not load. Please try again.');}))),
            new Promise((resolve,reject)=>{timeout=setTimeout(()=>reject(Error('The print logo could not load. Please try again.')),5000);})
        ]);
        if(S.routeTicket!==routeTicket || S.record?.id!==savedId || !form.isConnected)return;
        document.title=`QI-${String(savedId).padStart(5,'0')}_SO-${String(S.record.so_number).replace(/[^a-z0-9-]/gi,'_')}_${S.record.unit_label.replace(/[^a-z0-9-]/gi,'_')}`;
        window.print();
    } finally {
        clearTimeout(timeout);
        document.title=title;
        done();
        qrexInspectionPrintBusy=false;
    }
}

window.addEventListener('beforeprint',qrexPrepareInspectionPrint);
