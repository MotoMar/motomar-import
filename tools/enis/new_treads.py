"""New treads in out/final.csv with the closest existing tread of the producer, for step 2 of the import.

The tread name is Enis's `Bieżnik` column; what this list catches is the same tread spelled another way
(`Hakka Black SUV` / `Hakkapeliitta Black SUV`), which would otherwise become a second tread.
Writes `out/Enis - nowe bieżniki.csv`.
"""
import collections
import csv
import difflib
import re


def squash(s):
    return re.sub(r'[^0-9a-z+]', '', s.lower())


pid = {d['producer']: d['id'] for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t')}
base = collections.defaultdict(dict)
for t in csv.DictReader(open('treads.tsv', encoding='utf-8'), delimiter='\t'):
    base[t['producer_id']][t['tread']] = int(t['n'])
new = collections.Counter()
for r in csv.DictReader(open('out/final.csv', encoding='utf-8'), delimiter='@'):
    if r['bieznik'] not in base[pid[r['producent']]]:
        new[(r['producent'], r['bieznik'])] += 1
rows = []
for (producer, tread), n in new.items():
    best, score = '', 0.0
    for existing in base[pid[producer]]:
        s = difflib.SequenceMatcher(None, squash(tread), squash(existing)).ratio()
        if s > score:
            best, score = existing, s
    rows.append({'producent': producer, 'bieznik_enis': tread, 'opon': n, 'najblizszy_w_bazie': best,
                 'opon_w_bazie': base[pid[producer]].get(best, 0), 'podobienstwo': f'{score:.2f}'})
rows.sort(key=lambda r: (r['producent'], -float(r['podobienstwo'])))
with open('out/Enis - nowe bieżniki.csv', 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]), delimiter=';')
    w.writeheader()
    w.writerows(rows)
bands = collections.Counter('≥0.85' if float(r['podobienstwo']) >= 0.85 else '0.70–0.85' if float(r['podobienstwo']) >= 0.7 else '<0.70' for r in rows)
print(len(rows), 'nowych bieżników', sum(new.values()), 'opon', dict(bands))
for r in [r for r in rows if float(r['podobienstwo']) >= 0.85][:25]:
    print(r['opon'], r['producent'], '|', r['bieznik_enis'], '→', r['najblizszy_w_bazie'], r['podobienstwo'])
