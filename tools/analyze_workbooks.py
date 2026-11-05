"""Read source workbooks without changing them; retain cell-level provenance."""
import json
import hashlib
from pathlib import Path
import openpyxl

ROOT = Path(__file__).resolve().parents[1]
sources = {'hdpe': Path.home() / 'Downloads/Dev_HDPE Template.xlsx', 'cpetpe': Path.home() / 'Downloads/Dev_CPE TPE Template.xlsx'}
output = {}
for product, path in sources.items():
    book = openpyxl.load_workbook(path, data_only=False)
    cached = openpyxl.load_workbook(path, data_only=True)
    sheets = []
    for sheet in book:
        cells = []
        for row in sheet:
            for cell in row:
                if cell.value is not None:
                    cells.append({'cell': cell.coordinate, 'value': cell.value, 'cached': cached[sheet.title][cell.coordinate].value if cell.data_type == 'f' else None, 'type': cell.data_type, 'format': cell.number_format, 'locked': cell.protection.locked, 'comment': cell.comment.text if cell.comment else None})
        sheets.append({'name': sheet.title, 'state': sheet.sheet_state, 'rows': sheet.max_row, 'columns': sheet.max_column, 'merged': [str(x) for x in sheet.merged_cells.ranges], 'validations': [{'range': str(v.sqref), 'type': v.type, 'formula1': v.formula1, 'formula2': v.formula2} for v in sheet.data_validations.dataValidation], 'cells': cells})
    output[product] = {'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'names': {k: str(v.attr_text) for k,v in book.defined_names.items()}, 'sheets': sheets}
destination = ROOT / 'resources/workbook-analysis.json'
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_text(json.dumps(output, indent=2, default=str), encoding='utf-8')
for product, source in output.items():
    print(product, source['file'], 'names:', source['names'])
    for sheet in source['sheets']:
        print(sheet['name'], sheet['rows'], 'x', sheet['columns'], 'cells:', len(sheet['cells']), 'formulas:', sum(c['type']=='f' for c in sheet['cells']), 'validations:', sheet['validations'])
        for c in sheet['cells']:
            print(c['cell'], str(c['value']))
