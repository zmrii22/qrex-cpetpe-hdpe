"""Document every inspection column and its source instruction, including summary styling."""
from pathlib import Path
import openpyxl
ROOT = Path(__file__).resolve().parents[1]
lines=['# Workbook field dictionary','','Generated from the original workbooks. These are source column definitions, not the application layout. Each repeated sample is stored as a numbered reading.','']
for product,filename in [('HDPE','Dev_HDPE Template.xlsx'),('CPE / TPE','Dev_CPE TPE Template.xlsx')]:
    book=openpyxl.load_workbook(Path.home()/'Downloads'/filename)
    sheet=next(s for s in book if 'Template' in s.title)
    lines += ['## '+product,'','Source: `'+filename+'`, worksheet `'+sheet.title+'`.','','| Column | Group / field | Sample / subfield | Source instruction | Summary marker |','| --- | --- | --- | --- | --- |']
    def merged_value(row,col):
        cell=sheet.cell(row,col)
        for area in sheet.merged_cells.ranges:
            if cell.coordinate in area: return sheet.cell(area.min_row,area.min_col)
        return cell
    for col in range(1,sheet.max_column+1):
        heading=merged_value(3,col); sub=sheet.cell(4,col); rule=sheet.cell(5,col)
        if not heading.value and not sub.value: continue
        color=heading.font.color
        red=color is not None and color.type=='rgb' and str(color.rgb)[-6:] in ['FF0000','C00000']
        cells=[openpyxl.utils.get_column_letter(col),heading.value or '',sub.value or '',rule.value or '', 'Red header' if red else '']
        lines.append('| '+' | '.join(str(v).replace('|','/').replace('\n',' ') for v in cells)+' |')
    lines+=['']
out=ROOT/'docs';out.mkdir(exist_ok=True)
(out/'field-dictionary.md').write_text('\n'.join(lines),encoding='utf-8')
print('Documented',sum(line.startswith('| ') for line in lines)-4,'inspection columns.')
