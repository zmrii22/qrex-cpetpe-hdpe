"""Create deployable master data from the audited source workbooks."""
import json
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
books = json.loads((ROOT / 'resources/workbook-analysis.json').read_text())
master = {'products': {}, 'specifications': [], 'defects': [], 'sources': {}}
for product, book in books.items():
    master['sources'][product] = {'file': book['file'], 'sha256': book['sha256']}
    sheets = {s['name']: {c['cell']: c['value'] for c in s['cells']} for s in book['sheets']}
    drop = next(v for k,v in sheets.items() if 'Drop Down' in k)
    refs = {}
    for cell, value in drop.items():
        if cell.endswith('3') and re.fullmatch('[A-Z]+3', cell):
            col = cell[:-1]
            values = [v for k,v in drop.items() if re.fullmatch(col+'[0-9]+', k) and int(k[len(col):])>3]
            values = [str(int(v)) if isinstance(v, (int,float)) else str(v).strip() for v in values]
            refs[str(value).strip()] = list(dict.fromkeys(values))
    template = next(v for k,v in sheets.items() if 'Template' in k)
    metrics = ([('weight','Glove weight','g',1,'S','T',None), ('length_a','Length A','mm',13,'E','F',None), ('length_b','Length B','mm',13,'G','H',None), ('header_length','Header length','mm',13,'I','J',None), ('header_width','Header width','mm',13,'K','L',None), ('gap_length','Gap length','mm',13,'M','N',None), ('palm_width','Palm width','mm',13,'O','P',None), ('glove_width','Glove width','mm',13,'Q','R',None), ('thickness','Thickness','mm',3,None,None,None)] if product=='hdpe' else [('weight','Glove weight','g',1,'S','T',None), ('glove_length','Glove length','mm',13,'F','G',None), ('palm_width','Palm width','mm',13,'H','I','J'), ('glove_width','Glove width','mm',13,'K','L',None), ('middle_finger','Middle finger length','mm',13,'M','N','O'), ('thickness','Thickness before embossing','mm',3,'P','Q','R'), ('air_pump','Air pump test','',5,'E',None,None)])
    tests = ([{'key':'header_seal','label':'Header sealing','samples':3,'defects':['PPH','HHM']}] if product=='hdpe' else [{'key':'fstm','label':'FSTM','samples':3,'failure':'Weak Sealing FSTM'}]) + [{'key':'cuff_seal','label':'Cuff sealing','samples':3,'failure':'Weak Sealing Side Glove'}, {'key':'donning','label':'Donning & tearing','samples':3,'failure':'Weak Sealing Donning'}, {'key':'smell','label':'Smelly test','samples':1,'failure':'Bad Smell'}] + ([{'key':'layering','label':'Layering','samples':1,'defects':['Poor','Outside header']}] if product=='hdpe' else [])
    master['products'][product] = {'label':'HDPE' if product=='hdpe' else 'CPE / TPE', 'references':refs, 'metrics':[{'key':m[0],'label':m[1],'unit':m[2],'samples':m[3], 'section':'tests' if m[0]=='air_pump' else 'measurements'} for m in metrics], 'tests':tests}
    standard = sheets['Standard']
    rows = sorted({int(re.search(r'\d+',c)[0]) for c in standard if c.startswith('A') and int(re.search(r'\d+',c)[0])>=5})
    for row in rows:
        get = lambda c: standard.get(f'{c}{row}') if c else None
        limits = {}
        for key,label,unit,count,low,high,nom in metrics:
            minimum, maximum = get(low),get(high)
            limits[key] = {'min':minimum if isinstance(minimum,(int,float)) else None, 'max':maximum if isinstance(maximum,(int,float)) else None, 'nominal':get(nom), 'na': minimum=='NIL' and maximum=='NIL', 'source':f'Standard!{low or "?"}{row}:{high or low or "?"}{row}'}
        master['specifications'].append({'id':f'{product}-{row}', 'product':product, 'reference':get('A'), 'product_type':get('B'), 'glove_type':get('C'), 'size':get('D'), 'revision':book['sha256'][:12], 'source':f'{book["file"]}: Standard!A{row}:T{row}', 'limits':limits})
    defects = sheets['Defects']
    start = 4 if product=='hdpe' else 3
    for group,cols in [('Critical',('B','C','D')),('Holes',('F','G','H')),('Major',('J','K','L')),('Minor',('N','O','P')),('Packaging',('R','S','T'))]:
        for row in range(start,29):
            name = defects.get(f'{cols[1]}{row}')
            if not name: continue
            severity = None
            if group=='Packaging':
                severity = 'Regulatory' if row<start+7 else ('Critical' if row<start+22 else 'Visual')
            code=defects[f'{cols[2]}{row}']
            master['defects'].append({'id':f'{product}-{group.lower()}-{code}', 'product':product,'category':group,'name':name,'code':code,'classification':severity,'source':f'{book["file"]}: Defects!{cols[0]}{row}:{cols[2]}{row}'})
(ROOT/'resources/master.json').write_text(json.dumps(master,indent=2),encoding='utf-8')
print('Specifications:',len(master['specifications']),'Defects:',len(master['defects']))
