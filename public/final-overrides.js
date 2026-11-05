'use strict';

const qrexNormalizeInspectionData = normalizeInspectionData;
normalizeInspectionData = function(i) {
    qrexNormalizeInspectionData(i);
    // PHP encodes empty maps as arrays; named array properties are lost in JSON.
    i.data.measurement_flags = {...i.data.measurement_flags};
    i.data.section_flags = {...i.data.section_flags};
    const previousSample=String(i.data.fields.sample_size || '');
    if(!['125','200','315'].includes(previousSample)) i.data.fields.sample_size='';
    if (!String(i.data.fields.inspector_id || '').trim()) {
        i.data.fields.inspector_id = String(S.user?.employee_id || S.user?.id || '');
    }
    if(previousSample && i.data.fields.sample_size==='') {
        i.evaluation=localEvaluateInspection(i);
        i.result=i.evaluation.result;
    }
};

function qrexSpecMatchValue(value) {
    return String(value || '').trim().toLowerCase();
}

function qrexPreferredSpecification(i) {
    const product = i.product;
    const wantedGlove = qrexSpecMatchValue(i.data.fields.glove_type_code || i.glove_type);
    const sizeValues = [i.size, sizeLabel(i), i.common?.size_desc].map(qrexSpecMatchValue).filter(Boolean);
    const specs = (S.meta.specifications || []).filter(s => s.product === product);
    return specs.find(s => sizeValues.includes(qrexSpecMatchValue(s.size)) && qrexSpecMatchValue(s.glove_type) === wantedGlove) || null;
}

async function qrexAutoApplyPreferredSpec(i) {
    if (!i || i.data.fields.spec_id) return;
    const preferred = qrexPreferredSpecification(i);
    if (!preferred?.id) return;
    i.data.fields.spec_id = preferred.id;
    const selector = $('[name=spec_id]');
    if (selector) selector.value = preferred.id;
    try {
        i.spec = await api('spec', null, {id: preferred.id});
        await evaluateCurrent(true);
        if (S.inspection?.id === i.id && S.section === 'tests') renderActivity();
    } catch (err) {
        toast(err.message, true);
    }
}

const qrexBaseBindSpecSelectorFinal = bindSpecSelector;
bindSpecSelector = async function(i) {
    await qrexBaseBindSpecSelectorFinal(i);
    qrexAutoApplyPreferredSpec(i);
};

function qrexMetricEntered(i, key) {
    return (i.data.measurements?.[key] || []).filter(v => v !== null && v !== '' && v !== undefined).length;
}

function qrexBlankPassMetrics(i) {
    return S.meta.products[i.product].metrics
        .filter(m => m.section === 'measurements')
        .filter(m => qrexMetricEntered(i, m.key) === 0);
}

function qrexBlankFlagState(i) {
    const blank = qrexBlankPassMetrics(i);
    const flagged = blank.filter(m => i.data.measurement_flags?.[m.key] === 'blank_pass');
    return {blank, flagged, allFlagged: blank.length > 0 && flagged.length === blank.length};
}

function qrexFlagAllButton(i) {
    const state = qrexBlankFlagState(i);
    if (!state.blank.length) return '';
    const label = 'PASS';
    return button(label, 'flag-blank-measurements-all', state.allFlagged ? 'flag-off' : 'flag', `small blank-pass-toggle ${state.allFlagged ? 'is-active' : ''}`);
}

function qrexSectionPassButton(i, section, evaluation) {
    const active = i.data.section_flags?.[section] === 'pass';
    const attrs = `data-section="${esc(section)}" aria-pressed="${active}" title="${active ? 'Remove PASS override' : 'Mark this section as PASS'}"`;
    return button('PASS', 'flag-section-pass', active ? 'flag-off' : 'flag', `small blank-pass-toggle ${active ? 'is-active' : ''}`, attrs);
}

function qrexCountRows(i) {
    const f = i.data.fields;
    const mode = f.counting_mode === 'polybag' ? 'polybag' : 'inner';
    return [['pcs', 'Pieces'], [mode, mode === 'polybag' ? 'Polybag' : 'Inner']];
}

function qrexSamplingUsesPlan(i) {
    const f=i.data.fields;
    return !!f.inspection_plan;
}

function qrexSamplingLimit(i, category) {
    if(category==='Packaging' || !qrexSamplingUsesPlan(i)) return {aql:null,acc:0,rej:1,source:'existing'};
    return S.meta.sampling?.limits?.[i.data.fields.sample_size]?.[i.data.fields.inspection_plan]?.[category] || null;
}

function qrexSamplingCheck(i, category, total) {
    const sampling=qrexSamplingLimit(i,category);
    return {label:category,section:'defects',result:sampling===null?'pending':total<=sampling.acc?'pass':'fail',total,sampling};
}

function qrexCategorySamplingReference(i, category) {
    const f=i.data.fields,master=S.meta.sampling;
    let content;
    if(category==='Packaging') content='<span>Existing rule</span><span>Acc 0 / Rej 1</span>';
    else if(!master?.limits) content='<span>Reference unavailable</span>';
    else if(!(master.plans || []).some(plan=>plan.value===f.inspection_plan)) content='<span>Plan not recorded</span>';
    else if(!['125','200','315'].includes(String(f.sample_size))) content='<span>Sample Size not selected</span>';
    else {
        const limit=qrexSamplingLimit(i,category);
        content=limit?`<span>AQL ${esc(limit.aql)}</span><span>Acc ${esc(limit.acc)} / Rej ${esc(limit.rej)}</span>`:'<span>Reference unavailable</span>';
    }
    return `<span class="defect-aql-reference" aria-label="${esc(category)} sampling reference">${content}</span>`;
}

function qrexSamplingReference(i, {compact=false}={}) {
    const f=i.data.fields;
    const master=S.meta.sampling,plans=master?.plans || [],selectedPlan=plans.find(plan=>plan.value===f.inspection_plan);
    const sizes=master?.sample_sizes || [125,200,315];
    const sample=sizes.map(String).includes(String(f.sample_size))?String(f.sample_size):'';
    const categories=['Critical','Holes','Major','Minor'];
    const context=selectedPlan?.label || 'No saved inspection plan';
    const control=`<span class="sampling-size-label">Sample Size ${esc(sample || 'Not selected')}</span>`;
    let reference;
    if(!master?.limits) reference='<p class="sampling-reference-empty">Sampling reference unavailable.</p>';
    else if(!selectedPlan) reference='<p class="sampling-reference-empty">No Inspection Plan was saved when this inspection was created.</p>';
    else if(!sample) reference='<p class="sampling-reference-empty">Sample Size not selected.</p>';
    else if(compact) reference='';
    else {
        const headers=['AQL','Acc','Rej'];
        const rows=categories.map(category=>{
            const limit=master.limits[sample]?.[selectedPlan.value]?.[category];
            const cells=['aql','acc','rej'].map(key=>`<td>${esc(limit?.[key] ?? '-')}</td>`).join('');
            return `<tr><th scope="row">${esc(category)}</th>${cells}</tr>`;
        }).join('');
        reference=`<div class="table-wrap sampling-reference-wrap"><table class="sampling-reference-table" aria-label="AQL acceptance and rejection reference"><thead><tr><th scope="col">Category</th>${headers.map(label=>`<th scope="col">${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
    }
    return `<div class="sampling-reference"><div class="sampling-reference-toolbar"><div class="sampling-reference-heading"><h2>AQL reference</h2><span>${esc(context)}</span></div>${control}</div>${reference}</div>`;
}

function qrexSampleSizeControl(i) {
    const current=String(i.data.fields.sample_size || '');
    const sizes=(S.meta.sampling?.sample_sizes || [125,200,315]).map(size=>({value:String(size),label:String(size)}));
    return select('sample_size','Sample Size (pcs) *',sizes,sizes.some(size=>size.value===current)?current:'','required');
}

informationView = function(i) {
    const f = i.data.fields, refs = S.meta.products[i.product].references;
    const orderContext = {
        Factory: i.common.factory,
        Plant: i.common.plant,
        'Inspection Plan': S.meta.sampling?.plans?.find(plan=>plan.value===f.inspection_plan)?.label || 'Not recorded',
        'Item number': i.common.item_no,
        Customer: i.common.customer,
        Country: i.common.country,
        Brand: i.common.brand,
        'Glove Type / Code': f.glove_type_code || i.glove_type,
        'Product type': i.common.product_type,
        Colour: i.common.colour
    };
    return surface('Confirm this inspection unit', 'Order details are shared. Sampling, batch and unit identity belong to this inspection.', `<div class="subheading"><h3>Order context</h3></div><dl class="key-values" style="grid-template-columns:repeat(3,minmax(0,1fr));margin:0">${Object.entries(orderContext).map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v || 'Not recorded')}</dd></div>`).join('')}</dl><div class="divider"></div><div class="subheading"><h3>Unit identity & sampling</h3><p>Fields marked * are needed before submission.</p></div><div class="field-grid">${input('batch_no', 'Batch number *', f.batch_no)}${input('lot_no', 'Lot number', f.lot_no || i.common.lot_no)}${input('pallet_no', 'Pallet number *', f.pallet_no, 'text', 'readonly')}${input('carton_no', 'Carton number', f.carton_no)}${input('inspection_date', 'Inspection date *', f.inspection_date, 'date')}${select('inspection_status', 'Inspection Count *', refs['Inspection Status'], f.inspection_status)}${refs.Embossment ? select('embossment', 'Embossment', refs.Embossment, f.embossment) : input('embossment', 'Embossment', f.embossment)}${select('machine_no', i.product === 'hdpe' ? 'HD number' : 'STM number', refs[i.product === 'hdpe' ? 'HD No.' : 'STM No.'], f.machine_no)}${input('stamping_date', 'Stamping date', f.stamping_date, 'date')}${qrexSampleSizeControl(i)}${input('sample_qty', 'Sample quantity (cartons x pcs) *', f.sample_qty, 'text', 'placeholder="e.g. 2 cartons x 100 pcs"')}${input('cartons_per_pallet', 'Carton per pallet/lot/batch *', f.cartons_per_pallet, 'number', 'min="0" step="1"')}</div>`, status(i.evaluation.sections.information));
};

packingView = function(i) {
    const f = i.data.fields;
    const mode = f.counting_mode === 'polybag' ? 'polybag' : 'inner';
    const rows = qrexCountRows(i).map(([k, l]) => `<tr><td>${esc(l)}</td><td>${input('count_' + k, l + ' count', f['count_' + k], 'number', 'min="0" step="1"')}</td><td>${select('count_' + k + '_result', l + ' disposition', ['PASS', 'Less Count', 'Extra Count'], f['count_' + k + '_result'])}</td><td>${status(qrexEffectiveSectionResult(i, 'packing', !f['count_' + k + '_result'] ? 'pending' : f['count_' + k + '_result'] === 'PASS' ? 'pass' : 'fail'))}</td></tr>`).join('');
    const equipment = S.meta.equipment?.[i.product] || {};
    const withCurrent = (items = [], value = '') => value && !items.includes(value) ? [value, ...items] : items;
    const equipmentField = (name, label, items) => {
        const values = withCurrent(items || [], f[name] || '');
        return values.length ? select(name, label, values, f[name]) : input(name, label, f[name]);
    };
    const packerField = input('packer_id', 'Packer ID *', f.packer_id);
    return surface('Packing & traceability', 'Record packaging counts and the instruments used for this inspection.', `<div class="subheading"><h3>Counting</h3><p>Choose the packaging level that applies to this pallet.</p></div><div class="field-grid two">${select('counting_mode', 'Counting type', [{value:'inner', label:'Pieces and Inner'}, {value:'polybag', label:'Pieces and Polybag'}], mode)}</div><div class="table-wrap"><table><thead><tr><th>Packaging level</th><th>Actual count</th><th>Disposition</th><th>Result</th></tr></thead><tbody>${rows}</tbody></table></div><div class="divider"></div><div class="subheading"><h3>Traceability</h3></div><div class="field-grid two">${equipmentField('scale_id', 'Weighing scale ID *', equipment.scale)}${equipmentField('ruler_id', 'Ruler ID *', equipment.ruler)}${equipmentField('gauge_id', 'Thickness gauge ID *', equipment.gauge)}${packerField}<label class="field span-all"><span>Inspection remarks</span><textarea name="remarks">${esc(f.remarks)}</textarea></label></div>`, status(i.evaluation.sections.packing));
};

const qrexBaseMeasurementCell = measurementCell;
measurementCell = function(i, m, n) {
    if (m.key !== 'thickness') return qrexBaseMeasurementCell(i, m, n);
    const limit = i.spec?.limits?.[m.key] || {}, values = i.data.measurements[m.key] || [], required = n < m.samples;
    if (!required) return `<td class="measurement-cell muted-cell">--</td>`;
    const v = values[n];
    return `<td class="measurement-cell" data-reading-cell="${m.key}-${n}"><input aria-label="${esc(m.label)} sample ${n + 1}" type="number" min="0" max="1000000" step="any" inputmode="decimal" data-measurement="${esc(m.key)}" data-sample="${n}" value="${v ?? ''}" placeholder="${esc(m.unit || 'Reading')}"></td>`;
};

reviewView = function(i) {
    const e = i.evaluation;
    const inspectorId = i.data.fields.inspector_id || S.user?.employee_id || S.user?.id || '';
    const activityRows = Object.entries(e.sections).map(([k, v]) => {
        const flags = `<div class="actions review-inline-flags">${qrexSectionPassButton(i, k, e)}</div>`;
        return `<div class="review-row"><button class="btn ghost small" data-action="section" data-section="${k}">${sections[k]} ${icon('arrow-up-right')}</button>${flags}${status(v)}</div>`;
    }).join('');
return surface('Review inspection', 'Resolve incomplete entries and inspect exceptions before submission.', `<div class="review-summary">${icon(e.result === 'fail' ? 'clipboard-x' : e.can_submit ? 'clipboard-check' : 'clipboard-list')}<div><h2>${e.can_submit ? 'Ready for supervisor review' : e.missing.length + ' items need completion'}</h2><p>${esc(i.unit_label)} &middot; ${esc(i.glove_type)} &middot; Size ${esc(sizeLabel(i))}</p></div>${status(e.result)}</div><div class="field-grid two review-submit-fields">${input('inspector_id', 'Inspector ID *', inspectorId, 'text', 'readonly')}${input('verify_id', 'Verify ID (optional)', i.data.fields.verify_id, 'text', 'maxlength="80"')}</div><div class="review-columns"><div><h3>Activity results</h3>${activityRows}<div class="info-band">${icon('user-check')}<span>Submission locks this record. Verification is completed by a different supervisor.</span></div></div><div><h3>${e.issues.length ? 'Exceptions (' + e.issues.length + ')' : 'No failed checks'}</h3>${e.issues.map(issue => issueLink(issue, true)).join('')}${e.missing.length ? `<h3 style="margin-top:22px">To complete</h3>${e.missing.map(issue => issueLink(issue)).join('')}` : '<p class="muted" style="margin-top:12px;font-size:12px">No outstanding requirements.</p>'}</div></div>`, status(e.result));
};

localEvaluateInspection = function(i) {
    const definition = S.meta.products[i.product], data = i.data, limits = i.spec?.limits || {}, checks = {}, issues = [], missing = [], categories = ['Critical', 'Holes', 'Major', 'Minor', 'Packaging'];
    data.measurement_flags ??= {};
    for (const [key, label] of Object.entries({batch_no:'Batch number', pallet_no:'Pallet number', inspection_date:'Inspection date', inspection_status:'Inspection Count', sample_size:'Sample size', sample_qty:'Sample quantity', cartons_per_pallet:'Carton per pallet/lot/batch'})) {
        if (String(data.fields?.[key] ?? '').trim() === '') missing.push({section:'information', key, message:`Enter ${label}.`});
    }
    if (String(data.fields?.inspector_id ?? '').trim() === '') missing.push({section:'review', key:'inspector_id', message:'Enter Inspector ID.'});
    if(qrexSamplingUsesPlan(i)) {
        if(!(S.meta.sampling?.plans || []).some(p=>p.value===data.fields.inspection_plan)) missing.push({section:'information',key:'inspection_plan',message:'Choose an Inspection Plan.'});
        if(!(S.meta.sampling?.sample_sizes || []).map(String).includes(String(data.fields.sample_size || ''))) missing.push({section:'information',key:'sample_size',message:'Choose Sample Size 125, 200 or 315.'});
    }
    for (const metric of definition.metrics) {
        const key = metric.key, limit = limits[key] || {}, values = data.measurements?.[key] || [], results = [];
        if (key === 'thickness') {
            checks[key] = {label:metric.label, section:metric.section, result:'na', entered:values.filter(v=>v!==null&&v!==''&&v!==undefined).length, expected:metric.samples, readings:Array(metric.samples).fill('na')};
            continue;
        }
        if (limit.na) {
            checks[key] = {label:metric.label, section:metric.section, result:'na', entered:0, expected:0, readings:[]};
            continue;
        }
        for (let n = 0; n < metric.samples; n++) {
            const v = values[n] ?? null, r = readingResult(v, limit);
            results.push(r);
            if (r === 'fail') issues.push({section:metric.section, key, sample:n + 1, message:`${metric.label} sample ${n + 1}: ${v} ${metric.unit}; required ${localSpecRange(limit, metric.unit)}.`});
        }
        const entered = values.filter(v => v !== null && v !== '' && v !== undefined).length;
        const assessed = results.filter(r => r !== 'pending');
        let result = localAggregate(assessed);
        if (data.measurement_flags[key] === 'blank_pass' && entered === 0) result = 'pass';
        if (key === 'thickness' && i.product === 'hdpe' && (limit.min ?? null) === null && (limit.max ?? null) === null && entered > 0) result = 'na';
        if (key === 'air_pump' && i.product === 'cpetpe' && (limit.min ?? null) === null && (limit.max ?? null) === null && entered > 0) result = 'pass';
        if (result === 'unassessed' && entered > 0) issues.push({section:metric.section, key, message:`${metric.label}: specification not available; requires review.`});
        checks[key] = {label:metric.label, section:metric.section, result, entered, expected:metric.samples, readings:results};
    }
    for (const test of definition.tests) {
        const key = test.key, values = data.tests?.[key] || [], results = [];
        for (let n = 0; n < test.samples; n++) results.push(String(values[n] || 'na').toLowerCase());
        const result = localAggregate(results.filter(r => r !== 'pending'));
        checks[key] = {label:test.label, section:'tests', result, readings:results};
        if (result === 'fail') {
            issues.push({section:'tests', key, message:`${test.label}: ${test.failure || data.fields?.[key + '_defect'] || 'Failed test'}.`});
            if (test.defects && !data.fields?.[key + '_defect']) missing.push({section:'tests', key, message:`Select the ${test.label} defect.`});
        }
    }
    for (const category of categories) {
        const total = (data.defects || []).filter(d => d.category === category).reduce((n, d) => n + Number(d.quantity || 0), 0);
        const check=qrexSamplingCheck(i,category,total);
        checks['defect_' + category] = check;
        if (check.result === 'fail') issues.push({section:'defects', key:category, message:`${category}: ${total} defect(s); Acc ${check.sampling.acc} / Rej ${check.sampling.rej}${check.sampling.aql!==null?' at AQL '+check.sampling.aql:' (existing rule)'}.`});
    }
    const countMode = data.fields?.counting_mode === 'polybag' ? 'polybag' : 'inner';
    const requiredCounts = countMode === 'polybag' ? {pcs:'Pieces', polybag:'Polybag'} : {pcs:'Pieces', inner:'Inner'};
    for (const [key, label] of Object.entries({pcs:'Pieces', inner:'Inner', polybag:'Polybag'})) {
        if (!requiredCounts[key]) {
            checks['count_' + key] = {label:label + ' count', section:'packing', result:'na'};
            continue;
        }
        const value = data.fields?.['count_' + key] ?? '', disposition = data.fields?.['count_' + key + '_result'] ?? '', result = disposition === '' ? 'pending' : disposition === 'PASS' ? 'pass' : 'fail';
        checks['count_' + key] = {label:label + ' count', section:'packing', result};
        if (value === '' || disposition === '') missing.push({section:'packing', key:'count_' + key, message:`Complete ${label} count and disposition.`});
        if (result === 'fail') issues.push({section:'packing', key:'count_' + key, message:`${label} count: ${disposition}.`});
    }
    for (const [key, label] of Object.entries({scale_id:'Weighing scale ID', ruler_id:'Ruler ID', gauge_id:'Thickness gauge ID', packer_id:'Packer ID'})) {
        if (!data.fields?.[key]) missing.push({section:'packing', key, message:`Enter ${label}.`});
    }
    for (const key of ['length_a', 'glove_length', 'gap_length']) {
        if (checks[key]?.result === 'fail' && !data.fields?.[key + '_defect']) missing.push({section:'measurements', key, message:`Select the ${checks[key].label} defect.`});
    }
    const sectionStates = {};
    for (const section of ['information', 'defects', 'tests', 'measurements', 'packing']) {
        const states = Object.values(checks).filter(c => c.section === section).map(c => c.result);
        if (missing.some(m => m.section === section)) states.push('pending');
        sectionStates[section] = localAggregate(states.length ? states : ['pass']);
    }
    let result = localAggregate(Object.values(sectionStates));
    if (result === 'unassessed') result = 'hold';
    return {result, checks, sections:sectionStates, issues, missing, can_submit:missing.length === 0, passed:Object.values(checks).filter(c => c.result === 'pass').length, failed:Object.values(checks).filter(c => c.result === 'fail').length};
};

const qrexBaseLocalEvaluateWithFlags = localEvaluateInspection;
localEvaluateInspection = function(i) {
    const evaluation = qrexBaseLocalEvaluateWithFlags(i);
    i.data.section_flags = {...i.data.section_flags};
    const activitySections = ['information', 'defects', 'tests', 'measurements', 'packing'];
    const allSectionsFlagged = activitySections.every(section => i.data.section_flags[section] === 'pass');
    for (const section of activitySections) {
        if (i.data.section_flags[section] !== 'pass') continue;
        Object.values(evaluation.checks || {}).forEach(check => {
            if (check.section === section) check.result = 'pass';
        });
        evaluation.sections[section] = 'pass';
        evaluation.missing = evaluation.missing.filter(item => item.section !== section);
        evaluation.issues = evaluation.issues.filter(item => item.section !== section);
    }
    if (allSectionsFlagged) evaluation.missing = [];
    evaluation.result = localAggregate(Object.values(evaluation.sections));
    if (evaluation.result === 'unassessed') evaluation.result = 'hold';
    evaluation.can_submit = evaluation.missing.length === 0;
    evaluation.passed = Object.values(evaluation.checks).filter(c => c.result === 'pass').length;
    evaluation.failed = Object.values(evaluation.checks).filter(c => c.result === 'fail').length;
    return evaluation;
};

const qrexBaseRecord = record;
record = async function(q, ticket) {
    await qrexBaseRecord(q, ticket);
    const i = S.record;
    if (!i || ticket !== S.routeTicket) return;
    const unitFields=$('#record-unit .record-field-grid');
    const planLabel=S.meta.sampling?.plans?.find(plan=>plan.value===i.data.fields.inspection_plan)?.label || 'Existing sampling rule';
    if(unitFields)unitFields.insertAdjacentHTML('beforeend',`<div><dt>Sample Size (pcs)</dt><dd>${esc(i.data.fields.sample_size || 'Not recorded')}</dd></div><div><dt>Inspection Plan</dt><dd>${esc(planLabel)}</dd></div>`);
    $('#record-defects .section-head')?.insertAdjacentHTML('afterend',qrexSamplingReference(i));
    const supervisor = ['admin', 'supervisor'].includes(S.user.role);
    const reopenButton = $('.page-heading .actions [data-action="reopen"]');
    if (reopenButton) reopenButton.innerHTML = `${icon('edit')} Re-open for editing`;
    if (supervisor && i.product === 'cpetpe' && i.state !== 'draft' && !$('.page-heading .actions [data-action="retest"]')) {
        const printButton = $('.page-heading .actions [data-action="print"]');
        printButton?.insertAdjacentHTML('beforebegin', button('Re-test', 'retest', 'refresh', 'primary'));
    }
};

recordMeasurementSampleBlockTable = function(i, title, metrics, start, count, totalSamples, singleReading) {
    const end = Math.min(start + count, totalSamples);
    const rangeLabel = singleReading ? 'Reading' : `Samples ${String(start + 1).padStart(2, '0')}-${String(end).padStart(2, '0')}`;
    const showDisposition = !metrics.every(m => m.key === 'thickness') || metrics.some(m => i.data.section_flags?.[m.section] === 'pass');
    return `<div class="record-sample-block"><div class="record-sample-block-title">${esc(rangeLabel)}</div><div class="measurement-grid-wrap compact-measurement-wrap record-measurement-grid"><table class="measurement-grid compact-measurement-grid transposed-measurement-grid ${singleReading ? 'single-transposed-measurement-grid' : ''}" aria-label="${esc(title)} saved readings ${esc(rangeLabel)}"><thead><tr><th class="sticky-sample">Measurement</th>${Array.from({length:end - start}, (_, n) => `<th>${singleReading ? 'Reading' : String(start + n + 1).padStart(2, '0')}</th>`).join('')}${showDisposition ? '<th class="disposition-col">Disposition</th>' : ''}</tr></thead><tbody>${metrics.map(m => `<tr><th class="sticky-sample measurement-row-label"><span>${esc(recordMeasurementLabel(m))}</span><small>${esc(measurementLimitSummary(i, m))}</small></th>${Array.from({length:end - start}, (_, n) => recordMeasurementCell(i, m, start + n)).join('')}${showDisposition ? `<td class="measurement-disposition-cell">${recordMeasurementDisposition(i, m)}</td>` : ''}</tr>`).join('')}</tbody></table></div></div>`;
};

recordPrintMeasurementGroupTable = function(i, title, metrics) {
    if (!metrics.length) return '';
    const maxSamples = Math.max(...metrics.map(m => m.samples));
    const showDisposition = !metrics.every(m => m.key === 'thickness') || metrics.some(m => i.data.section_flags?.[m.section] === 'pass');
    return `<section class="record-pdf-measurement-panel"><div class="measurement-group-head"><h3>${esc(title)}</h3><span class="muted">${metrics.length} field${metrics.length === 1 ? '' : 's'}</span></div><table class="record-pdf-measurement-table" aria-label="${esc(title)} print readings by sample"><thead><tr><th>Sample</th>${metrics.map(m => `<th><span>${esc(recordMeasurementLabel(m))}</span><small>${esc(measurementLimitSummary(i, m))}</small></th>`).join('')}</tr></thead><tbody>${Array.from({length:maxSamples}, (_, n) => `<tr><th>${String(n + 1).padStart(2, '0')}</th>${metrics.map(m => recordPrintMeasurementValue(i, m, n)).join('')}</tr>`).join('')}</tbody>${showDisposition ? `<tfoot><tr><th>Disposition</th>${metrics.map(m => `<td>${recordMeasurementDisposition(i, m)}</td>`).join('')}</tr></tfoot>` : ''}</table></section>`;
};

measurementGroupTable = function(i, title, metrics, transpose = false) {
    if (!metrics.length) return '';
    const maxSamples = Math.max(...metrics.map(m => m.samples));
    const singleReading = metrics.length === 1 && maxSamples === 1;
    const sampleLabel = singleReading ? 'Reading' : 'Sample';
    const showDisposition = !metrics.every(m => m.key === 'thickness');
    if (transpose) {
        const oneReading = maxSamples === 1;
        const tableClass = oneReading ? 'single-transposed-measurement-grid' : '';
        return `<section class="measurement-group-panel measurement-dimensions-panel">
            <div class="measurement-group-head"><h3>${esc(title)}</h3><span class="muted">${metrics.length} field${metrics.length === 1 ? '' : 's'}</span></div>
            <div class="measurement-grid-wrap compact-measurement-wrap">
                <table class="measurement-grid compact-measurement-grid transposed-measurement-grid ${tableClass}" aria-label="${esc(title)} readings by measurement">
                    <thead><tr><th class="sticky-sample">Measurement</th>${Array.from({ length: maxSamples }, (_, n) => `<th>${oneReading ? 'Reading' : String(n + 1).padStart(2, '0')}</th>`).join('')}${showDisposition ? '<th class="disposition-col">Disposition</th>' : ''}</tr></thead>
                    <tbody>${metrics.map(m => `<tr><th class="sticky-sample measurement-row-label"><span>${esc(measurementDisplayLabel(m))}</span><small>${esc(measurementLimitSummary(i, m))}</small></th>${Array.from({ length: maxSamples }, (_, n) => compactMeasurementCell(i, m, n)).join('')}${showDisposition ? measurementDispositionCell(i, m) : ''}</tr>`).join('')}</tbody>
                </table>
            </div>
        </section>`;
    }
    return `<section class="measurement-group-panel">
        <div class="measurement-group-head"><h3>${esc(title)}</h3><span class="muted">${metrics.length} field${metrics.length === 1 ? '' : 's'}</span></div>
        <div class="measurement-grid-wrap compact-measurement-wrap">
            <table class="measurement-grid compact-measurement-grid" aria-label="${esc(title)} readings by sample">
                <thead><tr><th class="sticky-sample">${sampleLabel}</th>${metrics.map(m => `<th><span>${esc(measurementDisplayLabel(m))}</span><small>${esc(measurementLimitSummary(i, m))}</small></th>`).join('')}</tr></thead>
                <tbody>${Array.from({ length: maxSamples }, (_, n) => `<tr><th class="sticky-sample numeric">${singleReading ? 'Reading' : String(n + 1).padStart(2, '0')}</th>${metrics.map(m => measurementCell(i, m, n)).join('')}</tr>`).join('')}</tbody>
            </table>
        </div>
    </section>`;
};

orderDialog = function() {
    const i = S.inspection;
    if (!i) return;
    syncActivityState();
    const ticket = S.routeTicket;
    const refs = S.meta.products[i.product].references;
    let gloveOptions = refs['Glove Type'] || [];
    const currentGlove = i.data.fields.glove_type_code || i.glove_type || '';
    if (currentGlove && !gloveOptions.includes(currentGlove)) gloveOptions = [currentGlove, ...gloveOptions];
    const f = modal('Edit shared order details', `<p>These details apply to all ${i.units.length} units in this set. Once any unit is submitted, reopen all units before correcting shared order data.</p><div class="field-grid two">${input('factory', 'Factory / plant', i.common.factory)}${input('item_no', 'Item number', i.common.item_no)}${input('customer', 'Customer', i.common.customer)}${input('country', 'Country', i.common.country)}${input('colour', 'Colour', i.common.colour)}${input('brand', 'Brand', i.common.brand)}${select('glove_type_code', 'Glove Type / Code', gloveOptions, currentGlove)}${input('product_type', 'Product type', i.common.product_type)}</div>`, `<button type="button" class="btn" data-action="close-dialog">Cancel</button><button type="submit" class="btn primary">Save shared details</button>`);
    let saving = false;
    const active = () => S.inspection === i && S.routeTicket === ticket && $('#dialog')?.open && $('#dialog-form') === f;
    f.onsubmit = async e => {
        e.preventDefault();
        if (saving || !active()) return;
        saving = true;
        const b = $('button[type=submit]', f), label = b.innerHTML;
        b.disabled = true;
        b.classList.add('is-busy');
        b.innerHTML = icon('loader-2') + ' Saving';
        try {
            await api('order', {set_id:i.set_id, token:i.common_token, ...formValues(f)});
            if (!active()) return;
            const fresh = await api('inspection', null, {id:i.id});
            if (!active()) return;
            if (fresh.id !== i.id) throw new Error('The refreshed inspection does not match this form.');
            // Shared-order changes must not replace the current, unsaved inspection data.
            const draftSpecChanged = (i.data.fields.spec_id || '') !== (fresh.data.fields.spec_id || '');
            for (const key of ['common', 'common_token', 'glove_type', 'version', 'updated_at', 'updated_by', 'units']) {
                if (Object.prototype.hasOwnProperty.call(fresh, key)) i[key] = fresh[key];
            }
            i.data.fields.glove_type_code = fresh.glove_type;
            if (!draftSpecChanged) i.spec = fresh.spec;
            i.evaluation = localEvaluateInspection(i);
            i.result = i.evaluation.result;
            S.generation++;
            S.validationGeneration = S.generation;
            closeModal();
            renderInspection();
            toast('Shared order details updated.');
        } catch (err) {
            if (active()) modalError(err);
        } finally {
            saving = false;
            b.disabled = false;
            b.classList.remove('is-busy');
            b.innerHTML = label;
        }
    };
};

function retestDialog() {
    const i = S.record;
    const nextNo = Math.max(2, Number(i.inspection_no || 1) + 1);
    const f = modal('Re-test inspection', `<p>This starts ${nextNo}${nextNo === 2 ? 'nd' : nextNo === 3 ? 'rd' : 'th'} inspection for ${esc(i.unit_label)}. Existing saved data is retained until you edit and submit the re-test.</p><label class="field"><span>Reason for re-test *</span><textarea name="notes" required minlength="5"></textarea></label>`, `<button type="button" class="btn" data-action="close-dialog">Cancel</button><button type="submit" class="btn primary">Start re-test</button>`);
    f.onsubmit = async e => {
        e.preventDefault();
        const b = $('button[type=submit]', f), label = b.innerHTML;
        b.disabled = true;
        b.classList.add('is-busy');
        b.innerHTML = icon('loader-2') + ' Starting';
        try {
            await api('retest', {id:i.id, version:i.version, ...formValues(f)});
            closeModal();
            location.hash = 'inspection?id=' + i.id;
        } catch (err) {
            modalError(err);
            b.disabled = false;
            b.classList.remove('is-busy');
            b.innerHTML = label;
        }
    };
}

document.addEventListener('click', async event => {
    const el = event.target.closest('[data-action]');
    if (!el || el.disabled) return;
    if (el.dataset.action === 'flag-section-pass') {
        event.preventDefault();
        event.stopImmediatePropagation();
        const i = S.inspection, section = el.dataset.section;
        if (!i || !sections[section]) return;
        syncActivityState();
        i.data.section_flags = {...i.data.section_flags};
        if (i.data.section_flags[section] === 'pass') delete i.data.section_flags[section];
        else i.data.section_flags[section] = 'pass';
        markDirty();
        await evaluateCurrent(true);
        renderActivity();
    }
    if (el.dataset.action === 'flag-blank-measurement') {
        event.preventDefault();
        event.stopImmediatePropagation();
        const i = S.inspection, key = el.dataset.key;
        if (!i) return;
        syncActivityState();
        if (qrexMetricEntered(i, key) > 0) {
            toast('Flag only works when all readings for this measurement are blank.', true);
            return;
        }
        i.data.measurement_flags ??= {};
        if (i.data.measurement_flags[key] === 'blank_pass') delete i.data.measurement_flags[key];
        else i.data.measurement_flags[key] = 'blank_pass';
        markDirty();
        await evaluateCurrent(true);
        renderActivity();
    }
    if (el.dataset.action === 'flag-blank-measurements-all') {
        event.preventDefault();
        event.stopImmediatePropagation();
        const i = S.inspection;
        if (!i) return;
        syncActivityState();
        const state = qrexBlankFlagState(i);
        if (!state.blank.length) {
            toast('No fully blank measurement forms to flag.', true);
            return;
        }
        i.data.measurement_flags ??= {};
        if (state.allFlagged) state.blank.forEach(m => delete i.data.measurement_flags[m.key]);
        else state.blank.forEach(m => { i.data.measurement_flags[m.key] = 'blank_pass'; });
        markDirty();
        await evaluateCurrent(true);
        renderActivity();
    }
    if (el.dataset.action === 'retest') {
        event.preventDefault();
        event.stopImmediatePropagation();
        retestDialog();
    }
}, true);

document.addEventListener('input', event => {
    const el = event.target.closest('[data-measurement]');
    if (!el || !S.inspection) return;
    const key = el.dataset.measurement;
    if (S.inspection.data.measurement_flags?.[key] === 'blank_pass' && el.value !== '') {
        delete S.inspection.data.measurement_flags[key];
    }
}, true);

function qrexPointIsOnSelectableText(cell, x, y) {
    if (!cell) return false;
    const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            return String(node.nodeValue || '').trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        }
    });
    while (walker.nextNode()) {
        const range = document.createRange();
        range.selectNodeContents(walker.currentNode);
        const rects = Array.from(range.getClientRects());
        range.detach();
        if (rects.some(rect => x >= rect.left - 3 && x <= rect.right + 3 && y >= rect.top - 3 && y <= rect.bottom + 3)) {
            return true;
        }
    }
    return false;
}

document.addEventListener('mousedown', event => {
    const wrap = event.target.closest?.('.recent-table-wrap');
    if (!wrap || event.button !== 0 || wrap.scrollWidth <= wrap.clientWidth) return;
    if (event.target.closest('a,button,input,select,textarea,label,[contenteditable="true"]')) return;
    const cell = event.target.closest('td,th');
    if (qrexPointIsOnSelectableText(cell, event.clientX, event.clientY)) return;

    const startX = event.clientX;
    const startLeft = wrap.scrollLeft;
    let moved = false;
    wrap.classList.add('is-drag-scrolling');
    event.preventDefault();

    const onMove = moveEvent => {
        const delta = moveEvent.clientX - startX;
        if (Math.abs(delta) > 3) moved = true;
        wrap.scrollLeft = startLeft - delta;
        if (moved) moveEvent.preventDefault();
    };
    const stop = () => {
        wrap.classList.remove('is-drag-scrolling');
        window.removeEventListener('mousemove', onMove, true);
        window.removeEventListener('mouseup', stop, true);
    };
    window.addEventListener('mousemove', onMove, true);
    window.addEventListener('mouseup', stop, true);
}, true);
