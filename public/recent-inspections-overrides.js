const recentBaseColumns = [
    ['Fac','Fac',''],['SO No.','SO No.',''],['Customer','Customer',''],['Country','Country',''],['Product Type','Product Type',''],
    ['Glove Type / Code','Glove Type / Code',''],['Pallet No.','Pallet No.',''],['Size','Size',''],['Color','Color',''],['Brand','Brand',''],
    ['Sample Qty (Ctn x pcs)','Sample Qty (Ctn x pcs)',''],['Inspection Count','Inspection Count',''],['Qty of Ctn per Pallet','Qty of Ctn per Pallet',''],['Inspection Date','Inspection Date','']
];
const recentCpetpeColumns = [
    ...recentBaseColumns,
    ['Critical defect','Critical defect - Defects Name','Defects Name'],['Critical defect','Critical defect - Total','Total'],['Critical defect','Critical defect - Disposition','Disposition'],
    ['Holes','Holes - Defects Name','Defects Name'],['Holes','Holes - Total','Total'],['Holes','Holes - Disposition','Disposition'],
    ['Major Viusal','Major Viusal - Defects Name','Defects Name'],['Major Viusal','Major Viusal - Total','Total'],['Major Viusal','Major Viusal - Disposition','Disposition'],
    ['Minor Visual','Minor Visual - Defects Name','Defects Name'],['Minor Visual','Minor Visual - Total','Total'],['Minor Visual','Minor Visual - Disposition','Disposition'],
    ['Packaging Defects','Packaging Defects - Disposition','Disposition'],['Air Pump Test','Air Pump Test - Disposition','Disposition'],
    ['FSTM','FSTM - Disposition','Disposition'],['Cuff Sealing Test','Cuff Sealing Test - Disposition','Disposition'],['Donning & Tearng','Donning & Tearng - P/F','P/F'],
    ['Glove Weight (g)','Glove Weight (g) - Reading','Reading'],['Glove Length (mm)','Glove Length (mm) - Disposition','Disposition'],
    ['Palm Width (mm)','Palm Width (mm) - Disposition','Disposition'],['Glove Width (mm)','Glove Width (mm) - Disposition','Disposition'],
    ['Middle Finger Length (mm)','Middle Finger Length (mm) - Disposition','Disposition'],
    ['Counting - Pcs','Counting - Pcs - Disposition','Disposition'],['Counting - Inner','Counting - Inner - Disposition','Disposition'],['Counting - Polybag','Counting - Polybag - Disposition','Disposition'],
    ['Packer ID','Packer ID',''],['Inspector ID','Inspector ID',''],['Verify ID','Verify ID',''],['Verify Date','Verify Date',''],['Final Disposition','Final Disposition','']
];
const recentHdpeColumns = [
    ...recentBaseColumns,
    ['Critical defect','Critical defect - Defects Name','Defects Name'],['Critical defect','Critical defect - Total','Total'],['Critical defect','Critical defect - Disposition','Disposition'],
    ['Holes','Holes - Defects Name','Defects Name'],['Holes','Holes - Total','Total'],['Holes','Holes - Disposition','Disposition'],
    ['Major Viusal','Major Viusal - Defects Name','Defects Name'],['Major Viusal','Major Viusal - Total','Total'],['Major Viusal','Major Viusal - Disposition','Disposition'],
    ['Minor Visual','Minor Visual - Defects Name','Defects Name'],['Minor Visual','Minor Visual - Total','Total'],['Minor Visual','Minor Visual - Disposition','Disposition'],
    ['Packaging Defects','Packaging Defects - Disposition','Disposition'],['Header Sealing','Header Sealing - Disposition','Disposition'],
    ['Cuff Sealing Test','Cuff Sealing Test - Disposition','Disposition'],['Donning & Tearng','Donning & Tearng - P/F','P/F'],
    ['Glove Weight (g)','Glove Weight (g) - Reading','Reading'],['Length A (mm)','Length A (mm) - Disposition','Disposition'],
    ['Length B (mm)','Length B (mm) - Disposition','Disposition'],['Header Length (mm)','Header Length (mm) - Disposition','Disposition'],
    ['Header Width (mm)','Header Width (mm) - Disposition','Disposition'],['Gap Length (mm)','Gap Length (mm) - Disposition','Disposition'],
    ['Palm Width (mm)','Palm Width (mm) - Disposition','Disposition'],['Glove Width (mm)','Glove Width (mm) - Disposition','Disposition'],
    ['Counting - Pcs','Counting - Pcs - Disposition','Disposition'],['Counting - Inner','Counting - Inner - Disposition','Disposition'],['Counting - Polybag','Counting - Polybag - Disposition','Disposition'],
    ['Packer ID','Packer ID',''],['Inspector ID','Inspector ID',''],['Verify ID','Verify ID',''],['Verify Date','Verify Date',''],['Final Disposition','Final Disposition','']
];

function recentColumnsFor(product) {
    return product === 'hdpe' ? recentHdpeColumns : recentCpetpeColumns;
}
function recentCellValue(row, key) {
    const value = row.template?.[key];
    return value === undefined || value === null || value === '' ? 'Not recorded' : value;
}
function recentHeader(label, subfield) {
    return `<th><span>${esc(label)}</span>${subfield ? `<small>${esc(subfield)}</small>` : ''}</th>`;
}
function inspectionOrderCell(r) {
    const label = `QI-${String(r.id).padStart(5,'0')}${r.customer ? ' &middot; ' + esc(r.customer) : ''}`;
    return `<div class="inspection-order-cell"><div class="inspection-order-copy"><button type="button" class="row-link as-button" data-action="preview-record" data-id="${r.id}">SO ${esc(r.so_number)}</button><small>${label}</small></div><div class="record-actions inline-record-actions"><button type="button" class="icon-btn" data-action="preview-record" data-id="${r.id}" title="View inspection record" aria-label="View inspection record QI-${r.id}">${icon('eye')}</button><button type="button" class="icon-btn" data-action="open-inspection" data-id="${r.id}" title="Open inspection" aria-label="Open inspection QI-${r.id}">${icon('arrow-up-right')}</button></div></div>`;
}
function deleteActionCell(r) {
    return canDeleteDraft(r) ? `<div class="record-actions"><button type="button" class="icon-btn danger" data-action="delete-draft" data-id="${r.id}" data-label="QI-${String(r.id).padStart(5,'0')} - ${esc(r.unit_label)}" title="Delete draft" aria-label="Delete draft QI-${r.id}">${icon('trash')}</button></div>` : '';
}
function recentProductTable(rows, product, title = '') {
    if (!rows.length) return '';
    const cols = recentColumnsFor(product);
    return `<div class="recent-product-block">
        ${title ? `<div class="wide-table-controls recent-product-title"><span>${esc(title)} &middot; ${rows.length} inspection${rows.length === 1 ? '' : 's'}</span><div class="actions">${button('Left','recent-table-scroll','chevron-left','small',`data-dir="-1"`)}${button('Right','recent-table-scroll','chevron-right','small',`data-dir="1"`)}</div></div>` : `<div class="wide-table-controls"><span class="muted">${rows.length} inspection${rows.length === 1 ? '' : 's'} shown</span><div class="actions">${button('Left','recent-table-scroll','chevron-left','small',`data-dir="-1"`)}${button('Right','recent-table-scroll','chevron-right','small',`data-dir="1"`)}</div></div>`}
        <div class="table-wrap recent-table-wrap">
            <table class="recent-wide-table template-wide-table recent-template-table">
                <thead><tr><th>Inspection / order</th><th>Product</th><th>Unit</th><th>Workflow</th><th>Result</th><th>Updated</th>${cols.map(([label,,sub]) => recentHeader(label, sub)).join('')}<th></th></tr></thead>
                <tbody>${rows.map(r => `<tr><td>${inspectionOrderCell(r)}</td><td><strong>${esc(r.glove_type)}</strong><small>${productLabel(r.product)} &middot; Size ${esc(sizeLabel(r))}</small></td><td>${esc(r.unit_label)}</td><td>${status(r.state)}</td><td>${status(r.result)}</td><td>${dateText(r.updated_at)}<small>${esc(r.inspector)}</small></td>${cols.map(([,key]) => `<td>${esc(recentCellValue(r,key))}</td>`).join('')}<td>${deleteActionCell(r)}</td></tr>`).join('')}</tbody>
            </table>
        </div>
    </div>`;
}
function recordTable(rows, emptyText = 'No inspections match these filters.') {
    if (!rows.length) return `<div class="empty">${icon('clipboard-list')}<h3>No inspections yet</h3><p>${esc(emptyText)}</p>${S.user.role !== 'viewer' ? button('New inspection','new','plus','primary') : ''}</div>`;
    const products = [...new Set(rows.map(r => r.product))];
    if (products.length === 1) return `<div class="wide-table-shell">${recentProductTable(rows, products[0])}</div>`;
    return `<div class="wide-table-shell">${['cpetpe','hdpe'].map(p => recentProductTable(rows.filter(r => r.product === p), p, `${productLabel(p)} recent inspections`)).join('')}</div>`;
}
function recentInspectionTable(rows) {
    return recordTable(rows, 'Create an inspection set to begin. Each pallet or carton will have its own inspection record.');
}
function shell() {
    const user = S.user;
    const initials = user.name.split(' ').slice(0,2).map(x => x[0]).join('');
    const link = (path,name,glyph) => `<a href="#${path}" class="nav-link" data-nav="${path}" title="${esc(name)}" aria-label="${esc(name)}">${icon(glyph)}<span>${name}</span></a>`;
    $('#app').innerHTML = `<aside class="rail"><a class="brand" href="#dashboard" aria-label="QREX dashboard">${brandLogo}<span class="brand-copy">QREX<small>Quality record system</small></span></a><button type="button" class="icon-btn rail-toggle" data-action="toggle-nav" title="Toggle navigation" aria-label="Toggle navigation" aria-expanded="false">${icon('menu-2')}</button><nav aria-label="Main navigation"><div class="nav-group"><p class="eyebrow">Workspace</p>${link('dashboard','Overview','layout-dashboard')}${link('records?product=cpetpe','M26A','stack-2')}${link('records?product=hdpe','M26B','package')}${link('rejections','Rejections','alert-triangle')}</div><div class="nav-group"><p class="eyebrow">Management</p>${link('reports','Reports','chart-bar')}${link('master','Specifications','ruler-measure')}${user.role === 'admin' ? link('users','Team & access','users') : ''}</div></nav><div class="rail-foot"><div class="account"><button class="avatar" data-action="account" title="Account settings">${esc(initials)}</button><div class="account-copy"><strong>${esc(user.name)}</strong><small>${esc(user.role)}</small></div><button class="icon-btn" data-action="logout" title="Sign out" aria-label="Sign out">${icon('logout')}</button></div></div></aside><div class="app-body"><header class="topbar"><div class="breadcrumb"><span>Quality record system</span>${icon('chevron-right')}<strong id="crumb">Overview</strong></div><div class="topbar-end"><span class="connection">Local workspace</span><span>${new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</span><button class="icon-btn" data-action="help" title="Help" aria-label="Help">${icon('help-circle')}</button></div></header><main id="main" class="workspace" tabindex="-1"></main></div>`;
}
