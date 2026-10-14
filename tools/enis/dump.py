"""`Baza produktowa.xlsx` Enisa (arkusz „Baza produktowa”) → rows.jsonl, jeden obiekt JSON na wiersz.

Puste komórki są pomijane, napisy przycięte; `_row` to numer wiersza w arkuszu, do raportu ryzyk. 19
nagłówków powtórzonych w środku arkusza zostaje, a convert.py je odrzuca (`Typ` == `Typ`). Wymaga openpyxl.
"""
import json
import sys

import openpyxl

wb = openpyxl.load_workbook(sys.argv[1], read_only=True, data_only=True)
rows = wb['Baza produktowa'].iter_rows(values_only=True)
header = list(next(rows))
with open('rows.jsonl', 'w', encoding='utf-8') as f:
    for n, row in enumerate(rows, start=2):
        d = {h: (v.strip() if isinstance(v, str) else v) for h, v in zip(header, row) if v not in (None, '')}
        d['_row'] = n
        f.write(json.dumps(d, ensure_ascii=False, default=str) + '\n')
