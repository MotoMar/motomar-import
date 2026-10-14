"""Give the tires match_export.py found by catalogue number their EPREL number and label, before checkPricelist.

Reads `out/Enis - wszystkie.csv` and `out/eksport-eprel.csv`, writes `out/Enis - do EPREL.csv` (what
checkPricelist and apply_eprel.py read) and appends to `out/Enis - ryzyka.csv`.
"""
import csv
import re

OUT = 'out'
found = {d['ean']: d for d in csv.DictReader(open(f'{OUT}/eksport-eprel.csv', encoding='utf-8'), delimiter=';')
         if d['dopasowanie'] == 'numer modelu' and d['kandydatow'] == '1'}
lines = open(f'{OUT}/Enis - wszystkie.csv', encoding='utf-8').read().splitlines()
header = lines[0].split('@')
rows = [dict(zip(header, line.split('@'))) for line in lines[1:]]
log = []
for r in rows:
    d = found.get(r['ean'])
    if d is None or r['eprel'] or r['opor']:
        continue
    r['eprel'] = d['eprel_reg']
    label = re.fullmatch(r'([A-G])/([A-G])/(\d+) dB/([A-C])', d['eprel_etykieta'])
    if label:
        r['opor'], r['mokre'], r['halas'], r['fale'] = label.groups()
    log.append(r | {'detail': f"{d['eprel_reg']} ({d['eprel_etykieta']}), na rynku od {d['na_rynku_od']}"})
with open(f'{OUT}/Enis - do EPREL.csv', 'w', encoding='utf-8', newline='') as f:
    f.write('@'.join(header) + '\n')
    for r in rows:
        f.write('@'.join(r[c] for c in header) + '\n')
with open(f'{OUT}/Enis - ryzyka.csv', 'a', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter=';')
    for r in log:
        w.writerow([r['producent'], '', r['numkat1'], r['ean'], f"{r['rozmiar']} {r['indeksy']} {r['bieznik']}", 'EPREL po numerze modelu', r['detail']])
print('z numerem z eksportu:', len(log))
