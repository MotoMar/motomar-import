"""Rejestracje EPREL w eksporcie dziennym dla nowych opon, którym Enis nie podał ani numeru, ani etykiety.

Eksport nie ma EAN-ów ani nazw handlowych (1 z 331 195 modeli, 2026-10-14), a API po numerze też nie ma
nazwy u większości dostawców, więc jedynym kluczem jest identyfikator modelu dostawcy: tam, gdzie numkat
Enisa to kod producenta (Michelin, Nokian, Kumho, Hankook, Yokohama), równa się `modelIdentifier`. Tam,
gdzie `kod` Enisa to EAN (Bridgestone, Nexen, Sailun…), nic się nie znajdzie.

Dopasowanie wymaga tej samej marki, rozmiaru, indeksu nośności, indeksu prędkości i identyfikatora modelu.
Opony: nowe (ani EAN-u, ani numkatu w bazie), bez numeru EPREL i bez etykiety.
Czyta $EPREL_EXPORT/tyres_*.json (bin/fetchExport.php w data-fixerze). Pisze `out/eksport-eprel.csv`;
promote.py bierze wiersze z dokładnie jednym dopasowaniem.
"""
import collections
import csv
import glob
import json
import os
import re
import sys

EXPORT = os.environ['EPREL_EXPORT']


def squash(s):
    return re.sub(r'[^0-9a-z]', '', str(s or '').lower())


def digits(s):
    return re.sub(r'\D', '', str(s or ''))


def ref(s):
    return squash(s).lstrip('0')


base = list(csv.DictReader(open('db_all.tsv', encoding='utf-8'), delimiter='\t'))
eans, refs = {d['ean'] for d in base}, {(d['pp'], d['ref']) for d in base}
producer_id = {d['producer']: d['id'] for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t')}
reader = csv.DictReader(open('out/Enis - wszystkie.csv', encoding='utf-8'), delimiter='@')
rows = [r for r in reader
        if r['ean'] not in eans and (producer_id[r['producent']], r['numkat1']) not in refs and not r['eprel'] and not r['opor']]
brands = sorted({squash(r['producent']) for r in rows})


def brand_of(trademark):
    t = squash(trademark)
    if t in brands:
        return t
    for b in brands:  # `EVENT` to nasz `Event Tyre`, `NOKIANTYRES` nasz `Nokian Tyres`
        if len(b) >= 4 and len(t) >= 4 and (t.startswith(b) or b.startswith(t)):
            return b
    return None


by_spec = collections.defaultdict(list)
for path in sorted(glob.glob(f'{EXPORT}/tyres_*.json')):
    for e in json.load(open(path, encoding='utf-8')):
        b = brand_of(e.get('supplierOrTrademark'))
        if b is None:
            continue
        key = (b, digits(e.get('sizeDesignation') or e.get('sizeDesignationFiltered')), str(e.get('loadCapacityIndex')))
        by_spec[key].append({
            'reg': e.get('eprelRegistrationNumber'), 'model': ref(e.get('modelIdentifier')),
            'si': re.sub(r'PAR_|_PAR', '', str(e.get('speedCategorySymbol'))), 'end': e.get('onMarketEndDate'), 'start': e.get('onMarketFirstStartDate') or e.get('onMarketStartDate'),
            'label': f"{e.get('energyClass')}/{e.get('wetGripClass')}/{e.get('externalRollingNoiseValue')} dB/{e.get('externalRollingNoiseClass')}",
        })
    print(path, len(by_spec), file=sys.stderr)

out, stats = [], collections.Counter()
for r in rows:
    li, si = re.match(r'(\d+)', r['indeksy']).group(1), r['indeksy'][-1]
    cands = by_spec.get((squash(r['producent']), digits(r['rozmiar']), li), [])
    hit = [c for c in cands if c['si'] == si and c['model'] and c['model'] in (ref(r['numkat1']), ref(r['ean']))]
    how = 'numer modelu' if hit else 'rozmiar jest, numer modelu nie' if cands else 'brak rozmiaru'
    stats[how] += 1
    best = hit[0] if hit else {}
    out.append(r | {'dopasowanie': how, 'eprel_reg': best.get('reg', ''), 'eprel_etykieta': best.get('label', ''),
                    'na_rynku_od': best.get('start') or '', 'na_rynku_do': best.get('end') or '', 'kandydatow': len(hit)})
with open('out/eksport-eprel.csv', 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=reader.fieldnames + ['dopasowanie', 'eprel_reg', 'eprel_etykieta', 'na_rynku_od', 'na_rynku_do', 'kandydatow'], delimiter=';')
    w.writeheader()
    w.writerows(out)
print(dict(stats))
ended = collections.Counter(('zakończona' if o['na_rynku_do'] else 'na rynku') for o in out if o['eprel_reg'])
print(dict(ended))
