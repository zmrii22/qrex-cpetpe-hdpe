matchingSpecifications = function(i) {
    const wantedSize = String(i.size || '').toLowerCase();
    const wantedGlove = String(i.glove_type || i.data.fields.glove_type_code || '').toLowerCase();
    const score = s => {
        let points = 0;
        if (String(s.size || '').toLowerCase() === wantedSize) points += 4;
        if (String(s.glove_type || '').toLowerCase() === wantedGlove) points += 2;
        return points;
    };
    return (S.meta.specifications || [])
        .filter(s => s.product === i.product)
        .sort((a, b) => score(b) - score(a) || String(a.reference_name || a.id).localeCompare(String(b.reference_name || b.id)));
};

function visualDefectQuantity(i, id) {
    return Number(i.data.defects?.find(d => d.defect_id === id)?.quantity || 0);
}

function setVisualDefectQuantity(i, id, rawValue) {
    const master = S.meta.defects.find(d => d.id === id && d.product === i.product);
    if (!master) return;
    const qty = Math.max(0, Math.min(1000000, Number(rawValue || 0)));
    i.data.defects ??= [];
    i.data.defect_checks ??= {};
    i.data.defects = i.data.defects.filter(d => d.defect_id !== id);
    if (Number.isFinite(qty) && qty > 0) {
        i.data.defects.push({ ...master, defect_id: master.id, quantity: qty });
        i.data.defect_checks[id] = 'fail';
    } else {
        i.data.defect_checks[id] = 'pass';
    }
    i.data.checked_categories = [...new Set(S.meta.defects.filter(d => d.product === i.product).map(d => d.category))];
}

defectsView = function(i) {
    const data = i.data;
    data.defect_checks ??= {};
    data.defects ??= [];
    const masters = S.meta.defects.filter(d => d.product === i.product);
    const categories = ['Critical', 'Holes', 'Major', 'Minor', 'Packaging'].filter(c => masters.some(d => d.category === c));
    const categoryLabel = c => c === 'Major' ? 'Major visual' : c === 'Minor' ? 'Minor visual' : c;
    const totals = Object.fromEntries(categories.map(c => [c, data.defects.filter(d => d.category === c).reduce((n, d) => n + Number(d.quantity), 0)]));
    const groups = Object.fromEntries(categories.map(c => [c, masters.filter(d => d.category === c)]));
    const maxRows = Math.max(...categories.map(c => groups[c].length));
    i.evaluation=localEvaluateInspection(i);
    i.result=i.evaluation.result;
    const chips = categories.map(c => {const check=i.evaluation.checks['defect_'+c];return `<div class="defect-summary-chip ${check.result==='fail' ? 'failed' : ''}" data-defect-category="${esc(c)}"><span class="defect-category-name">${esc(categoryLabel(c))}</span>${qrexCategorySamplingReference(i,c)}<strong class="defect-category-total">${totals[c] || 0}</strong>${status(check.result)}</div>`;}).join('');
    return `<section class="form-surface"><header class="form-section-head sampling-section-head">${qrexSamplingReference(i,{compact:true})}</header><div class="form-content">
        <div class="defect-summary-row">${chips}</div>
        <div class="section-head compact-defect-head">
            <h3>Defect checklist <span class="tag" id="recorded-defects-count">${data.defects.length} recorded</span></h3>
        </div>
        <div class="table-wrap visual-defect-table-wrap">
            <table class="visual-defect-table">
                <thead><tr>${categories.map(c => `<th>${esc(categoryLabel(c))}</th>`).join('')}</tr></thead>
                <tbody>
                    ${Array.from({ length: maxRows }, (_, n) => `<tr>${categories.map(c => {
                        const d = groups[c][n];
                        if (!d) return '<td class="visual-defect-empty">--</td>';
                        const qty = visualDefectQuantity(i, d.id);
                        return `<td class="${i.evaluation.checks['defect_'+c]?.result==='fail' && qty>0 ? 'failed' : ''}" data-visual-category="${esc(c)}">
                            <label class="visual-defect-entry">
                                <span class="visual-defect-line"><strong class="defect-code">${esc(d.code || '-')}</strong><span class="defect-name">${esc(d.name)}</span></span>
                                <input class="defect-qty defect-qty-only" aria-label="${esc(d.name)} quantity" type="number" min="0" max="1000000" step="1" inputmode="numeric" data-defect-qty="${esc(d.id)}" value="${qty > 0 ? qty : ''}" placeholder="Qty">
                            </label>
                        </td>`;
                    }).join('')}</tr>`).join('')}
                </tbody>
            </table>
        </div>
        <div style="margin-top:18px"><label class="field"><span>Visual inspection remarks</span><textarea name="defect_remarks" placeholder="Location, appearance, or other useful observations">${esc(data.fields.defect_remarks)}</textarea></label></div>
    </div></section>`;
};

function specificationReferenceControl(i) {
    const specs = matchingSpecifications(i);
    const selected = i.data.fields.spec_id || i.spec?.id || '';
    const specChoices = specs.map(s => ({ value: s.id, label: `${s.reference_name || s.id} / ${s.glove_type || '-'} / Size ${s.size || '-'}` }));
    return `<div class="measure-matrix-head spec-select-head">
        <div>
            ${specChoices.length ? select('spec_id', 'Specification masterlist reference', specChoices, selected, 'required') : `<div class="notice">${icon('info-circle')}<div><strong>Specification not available</strong>No active masterlist specification is available for this inspection type.</div></div>`}
            ${i.spec ? `<span class="muted spec-reference-note">Using ${esc(i.spec.reference || selected)} · ${esc(i.spec.glove_type)} · Size ${esc(i.spec.size)}</span>` : '<span class="muted spec-reference-note">Choose a masterlist reference to apply limits.</span>'}
        </div>
        <div>${status(i.evaluation.sections.tests)}</div>
    </div>`;
}

function compactAirPumpCard(i, m) {
    if (!m) return '';
    const c = i.evaluation.checks[m.key] || {};
    return `<section class="performance-card air-pump-compact-card">
        <div class="performance-card-head">
            <div><h3>${esc(m.label)}</h3><p>${m.samples} sample readings${m.unit ? ' · ' + esc(m.unit) : ''}</p></div>
            <div id="metric-result-${m.key}">${status(c.result || 'pending')}</div>
        </div>
        <div class="performance-limit-line">${esc(measurementLimitSummary(i, m))}</div>
        <div class="table-wrap performance-air-wrap">
            <table class="performance-air-table" aria-label="${esc(m.label)} samples">
                <thead><tr>${Array.from({ length: m.samples }, (_, n) => `<th>${String(n + 1).padStart(2, '0')}</th>`).join('')}</tr></thead>
                <tbody><tr>${Array.from({ length: m.samples }, (_, n) => compactMeasurementCell(i, m, n)).join('')}</tr></tbody>
            </table>
        </div>
    </section>`;
}

function compactPerformanceTestCard(i, test) {
    const values = i.data.tests[test.key] || [];
    const normalized = Array.from({ length: test.samples }, (_, n) => values[n] || 'na');
    const result = normalized.includes('fail') ? 'fail' : normalized.includes('pass') ? 'pass' : 'na';
    return `<section class="performance-card quick-test-card ${result === 'fail' ? 'failed' : ''}">
        <div class="performance-card-head">
            <div><h3>${esc(test.label)}</h3><p>${test.samples === 1 ? 'Result' : test.samples + ' samples'}</p></div>
            ${status(result)}
        </div>
        <div class="quick-test-samples">
            ${Array.from({ length: test.samples }, (_, n) => `<div class="test-sample compact-test-sample"><small>${test.samples === 1 ? 'Result' : 'S' + (n + 1)}</small><div class="segments" role="group" aria-label="${esc(test.label)} sample ${n + 1}">${['na', 'pass', 'fail'].map(v => `<button type="button" data-action="test-value" data-test="${esc(test.key)}" data-sample="${n}" data-value="${v}" class="${normalized[n] === v ? 'selected ' + v : ''}" aria-pressed="${normalized[n] === v}">${v === 'na' ? 'N/A' : v.toUpperCase()}</button>`).join('')}</div></div>`).join('')}
        </div>
        ${result === 'fail' ? `<div class="test-failure compact-test-failure">${test.defects ? select(test.key + '_defect', 'Failure reason', test.defects, i.data.fields[test.key + '_defect']) : icon('alert-circle') + ' ' + esc(test.failure)}</div>` : ''}
    </section>`;
}

testsView = function(i) {
    const definition = S.meta.products[i.product];
    setTimeout(() => bindSpecSelector(i), 0);
    const airPump = i.product === 'cpetpe' ? definition.metrics.find(m => m.key === 'air_pump') : null;
    const quickTests = definition.tests.map(test => compactPerformanceTestCard(i, test)).join('');
    return surface('Performance tests', i.product === 'hdpe' ? 'Select the specification reference, then check seal integrity, donning, smell and layering.' : 'Select the specification reference, then check air pressure, seal integrity, donning and smell.', `
        ${specificationReferenceControl(i)}
        <div class="performance-compact-stack">
            ${airPump ? `<div class="performance-top-row">${compactAirPumpCard(i, airPump)}</div>` : ''}
            <div class="quick-test-grid">${quickTests}</div>
        </div>
    `, status(i.evaluation.sections.tests));
};

function measurementDisplayLabel(m) {
    if (m.key === 'thickness') return 'Thickness';
    return m.label;
}

const qrexBaseMeasurementLimitSummary = measurementLimitSummary;
measurementLimitSummary = function(i, m) {
    const limit = i.spec?.limits?.[m.key] || {};
    if (limit.na) return '--';
    return qrexBaseMeasurementLimitSummary(i, m);
};

function compactMeasurementCell(i, m, n) {
    const limit = i.spec?.limits?.[m.key] || {};
    const values = i.data.measurements[m.key] || [];
    const required = n < m.samples;
    const sampleLabel = m.samples === 1 ? 'Reading' : String(n + 1).padStart(2, '0');
    if (!required) return `<td class="measurement-cell muted-cell" data-sample-label="${sampleLabel}">--</td>`;
    if (limit.na) return `<td class="measurement-cell muted-cell" data-sample-label="${sampleLabel}">--</td>`;
    const v = values[n], r = readingResult(v, limit);
    return `<td class="measurement-cell ${r === 'fail' ? 'failed' : ''}" data-reading-cell="${m.key}-${n}" data-sample-label="${sampleLabel}"><input aria-label="${esc(m.label)} sample ${n + 1}" type="number" min="0" max="1000000" step="any" inputmode="decimal" data-measurement="${esc(m.key)}" data-sample="${n}" value="${v ?? ''}" placeholder="${esc(m.unit || 'Reading')}"></td>`;
}

function measurementDispositionState(i, m) {
    const result = compactMetricResult(i, m.key);
    return result === 'na' ? 'pending' : result;
}

function measurementDispositionCell(i, m) {
    return `<td class="measurement-disposition-cell" id="metric-disposition-${esc(m.key)}">${status(measurementDispositionState(i, m))}</td>`;
}

function measurementGroupTable(i, title, metrics, transpose = false) {
    if (!metrics.length) return '';
    const maxSamples = Math.max(...metrics.map(m => m.samples));
    const singleReading = metrics.length === 1 && maxSamples === 1;
    const sampleLabel = singleReading ? 'Reading' : 'Sample';
    if (transpose) {
        const singleReading = maxSamples === 1;
        const tableClass = singleReading ? 'single-transposed-measurement-grid' : '';
        return `<section class="measurement-group-panel measurement-dimensions-panel">
            <div class="measurement-group-head"><h3>${esc(title)}</h3><span class="muted">${metrics.length} field${metrics.length === 1 ? '' : 's'}</span></div>
            <div class="measurement-grid-wrap compact-measurement-wrap">
                <table class="measurement-grid compact-measurement-grid transposed-measurement-grid ${tableClass}" aria-label="${esc(title)} readings by measurement">
                    <thead><tr><th class="sticky-sample">Measurement</th>${Array.from({ length: maxSamples }, (_, n) => `<th>${singleReading ? 'Reading' : String(n + 1).padStart(2, '0')}</th>`).join('')}<th class="disposition-col">Disposition</th></tr></thead>
                    <tbody>${metrics.map(m => `<tr><th class="sticky-sample measurement-row-label"><span>${esc(measurementDisplayLabel(m))}</span><small>${esc(measurementLimitSummary(i, m))}</small></th>${Array.from({ length: maxSamples }, (_, n) => compactMeasurementCell(i, m, n)).join('')}${measurementDispositionCell(i, m)}</tr>`).join('')}</tbody>
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
}

measurementsView = function(i) {
    const metrics = S.meta.products[i.product].metrics.filter(m => m.section === 'measurements');
    const missing = metrics.filter(m => {
        const l = i.spec?.limits?.[m.key] || {};
        return m.key !== 'thickness' && !l.na && l.min == null && l.max == null;
    });
    const failing = metrics.filter(m => (i.evaluation.checks[m.key]?.result) === 'fail');
    const weightMetrics = metrics.filter(m => m.key === 'weight');
    const thicknessMetrics = metrics.filter(m => m.key === 'thickness');
    const dimensionMetrics = metrics.filter(m => !['weight', 'thickness'].includes(m.key));
    return surface('Measurements', 'Enter all readings for Sample 1, then use Tab or Enter to continue down the next sample.', `
        <div class="measure-matrix-head">
            <div>
                ${i.spec ? `<span class="tag">${esc(i.spec.reference)}</span><span class="muted">${esc(i.spec.glove_type)} · Size ${esc(i.spec.size)}</span>` : `<div class="notice">${icon('info-circle')}<div><strong>Specification not selected</strong>Choose the masterlist reference in Performance tests before entering readings.</div></div>`}
            </div>
        </div>
        ${missing.length && i.spec ? `<div class="notice">${icon('info-circle')}<div><strong>Some measurement limits are not available</strong>${esc(missing.map(m => m.label).join(', '))} will be recorded for review.</div></div>` : ''}
        <div class="measurement-group-stack">
            <div class="measurement-top-grid">
                ${measurementGroupTable(i, 'Weight', weightMetrics, true)}
                ${measurementGroupTable(i, 'Thickness', thicknessMetrics, true)}
            </div>
            ${measurementGroupTable(i, 'Dimensions', dimensionMetrics, true)}
        </div>
    `);
};

function orderedTransposedMeasurementInputs(table) {
    const rows = Array.from(table.tBodies?.[0]?.rows || []);
    const inputs = Array.from(table.querySelectorAll('[data-measurement]'));
    const maxSample = inputs.reduce((max, el) => Math.max(max, Number(el.dataset.sample || 0)), 0);
    const ordered = [];
    for (let sample = 0; sample <= maxSample; sample += 1) {
        rows.forEach(row => {
            const input = row.querySelector(`[data-measurement][data-sample="${sample}"]`);
            if (input) ordered.push(input);
        });
    }
    return ordered;
}

function bindTransposedMeasurementNavigation() {
    $$('.transposed-measurement-grid').forEach(table => {
        const ordered = orderedTransposedMeasurementInputs(table);
        ordered.forEach((el, index) => {
            if (el.dataset.sampleFirstNavBound) return;
            el.dataset.sampleFirstNavBound = '1';
            el.addEventListener('keydown', event => {
                if (!['Enter', 'Tab'].includes(event.key)) return;
                const next = ordered[index + (event.shiftKey ? -1 : 1)];
                if (!next) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                next.focus();
                next.select?.();
            }, true);
        });
    });
}

function compactMetricResult(i, key) {
    if (key === 'thickness') return 'na';
    const metric = S.meta.products[i.product].metrics.find(m => m.key === key);
    if (!metric) return 'pending';
    const limit = i.spec?.limits?.[key] || {};
    if (limit.na) return 'na';
    const values = i.data.measurements?.[key] || [];
    const results = Array.from({ length: metric.samples }, (_, n) => readingResult(values[n] ?? null, limit));
    const entered = values.filter(v => v !== null && v !== '' && v !== undefined).length;
    let result = localAggregate(results.filter(r => r !== 'pending'));
    if (key === 'air_pump' && i.product === 'cpetpe' && (limit.min ?? null) === null && (limit.max ?? null) === null && entered > 0) result = 'pass';
    if (key === 'thickness' && i.product === 'hdpe' && (limit.min ?? null) === null && (limit.max ?? null) === null && entered > 0) result = 'na';
    return result || 'pending';
}

const qrexBaseBindActivity = bindActivity;
bindActivity = function() {
    qrexBaseBindActivity();
    const i = S.inspection;
    if (!i) return;
    const active = () => S.inspection?.id === i.id;
    $$('[name=sample_size]',$('#activity-content')).forEach(el=>{
        el.addEventListener('change',async()=>{
            if(!active())return;
            await evaluateCurrent(true);
            if(active() && S.section==='defects')renderActivity();
        });
    });
    bindTransposedMeasurementNavigation();
    $$('.air-pump-compact-card [data-measurement]').forEach(el => {
        if (el.dataset.compactResultBound) return;
        el.dataset.compactResultBound = '1';
        el.addEventListener('input', () => {
            if (!active()) return;
            const target = $(`#metric-result-${el.dataset.measurement}`);
            if (target) target.innerHTML = status(compactMetricResult(i, el.dataset.measurement));
        });
    });
    $$('.measurement-group-panel [data-measurement]').forEach(el => {
        if (el.dataset.measurementDispositionBound) return;
        el.dataset.measurementDispositionBound = '1';
        el.addEventListener('input', () => {
            if (!active()) return;
            const target = $(`#metric-disposition-${el.dataset.measurement}`);
            if (target) {
                const metric = S.meta.products[i.product].metrics.find(m => m.key === el.dataset.measurement);
                if (metric) target.innerHTML = status(measurementDispositionState(i, metric));
            }
        });
    });
    $$('.defect-qty-only').forEach(el => {
        if (el.dataset.qtyOnlyBound) return;
        el.dataset.qtyOnlyBound = '1';
        el.addEventListener('input', () => {
            if (!active()) return;
            setVisualDefectQuantity(i, el.dataset.defectQty, el.value);
            markDirty();
            evaluateCurrent(true);
            $$('[data-defect-category]').forEach(chip=>{
                const check=i.evaluation.checks['defect_'+chip.dataset.defectCategory];
                if(!check)return;
                chip.classList.toggle('failed',check.result==='fail');
                $('.defect-category-total',chip).textContent=check.total;
                $('.status',chip).outerHTML=status(check.result);
            });
            $$('[data-visual-category]').forEach(cell=>{
                cell.classList.toggle('failed',i.evaluation.checks['defect_'+cell.dataset.visualCategory]?.result==='fail' && Number($('[data-defect-qty]',cell)?.value||0)>0);
            });
            const count=$('#recorded-defects-count');
            if(count)count.textContent=i.data.defects.length+' recorded';
        });
    });
};

const qrexBaseSyncActivityState = syncActivityState;
syncActivityState = function() {
    qrexBaseSyncActivityState();
    const i = S.inspection;
    if (!i) return;
    $$('.defect-qty-only').forEach(el => setVisualDefectQuantity(i, el.dataset.defectQty, el.value));
};
