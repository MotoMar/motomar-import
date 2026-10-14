"""Enis's `Baza produktowa.xlsx` (sheet „Baza produktowa”) -> rows.jsonl, one JSON object per row.

Empty cells are left out and strings stripped; `_row` is the sheet's row number for the risk report. The 19
header rows repeated inside the sheet stay in and convert.py drops them (`Typ` == `Typ`). Needs openpyxl.
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
