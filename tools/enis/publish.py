"""Kopiuje CSV producentów do $CENNIKI/Enis/CSV razem ze zmianami nazw, nowymi bieżnikami i podsumowaniem.

Ten katalog to wyjście tego skryptu i nic więcej: przed zapisem jest czyszczony, żeby producent, który
wypadł, nie zostawił starego pliku — ale dopiero gdy nowy wynik jest na miejscu i kompletny."""
import collections, csv, os, shutil, sys
import openpyxl

DEST = os.path.join(os.environ['CENNIKI'], 'Enis', 'CSV')
base_treads = {(t['producer_id'], t['tread']) for t in csv.DictReader(open('treads.tsv', encoding='utf-8'), delimiter='\t')}
producer_id = {d['producer']: d['id'] for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t')}
existing, refs = set(), set()
for d in csv.DictReader(open('db_all.tsv', encoding='utf-8'), delimiter='\t'):
    existing.add(d['ean'])
    refs.add((d['pp'], d['ref']))
sim_reader = csv.DictReader(open('out/sim.tsv', encoding='utf-8'), delimiter='\t')
sim = list(sim_reader)
rows = list(csv.DictReader(open('out/final.csv', encoding='utf-8'), delimiter='@'))
missing = [p for p in ('out/Enis - ryzyka.csv', 'out/Enis - nowe bieżniki.csv') if not os.path.exists(p)]
missing += [f'out/CSV/{p}.csv' for p in {r['producent'] for r in rows} if not os.path.exists(f'out/CSV/{p}.csv')]
if not rows or missing:
    # poprzedni wynik zostaje, dopóki nie ma kompletnego, który go zastąpi
    sys.exit(f"Nie publikuję: {'final.csv jest pusty' if not rows else 'brak ' + ', '.join(missing)}")
shutil.rmtree(DEST, ignore_errors=True)
os.makedirs(DEST)
producer_of = {r['ean']: r['producent'] for r in rows}
stats = collections.defaultdict(collections.Counter)
new_treads = collections.defaultdict(set)
for r in rows:
    s = stats[r['producent']]
    s['wierszy'] += 1
    is_new = r['ean'] not in existing and (producer_id[r['producent']], r['numkat1']) not in refs  # import szuka też po numkacie
    s['nowe' if is_new else 'aktualizacje'] += 1
    if (producer_id[r['producent']], r['bieznik']) not in base_treads:
        new_treads[r['producent']].add(r['bieznik'])
        s['opon na nowych bieżnikach'] += 1
changes = collections.defaultdict(list)
for d in sim:
    if d['old_name'] != d['new_name']:
        changes[producer_of[d['ean']]].append(d)
for producer in stats:
    shutil.copy(f'out/CSV/{producer}.csv', f'{DEST}/{producer}.csv')
    stats[producer]['nowych bieżników'] = len(new_treads[producer])
    stats[producer]['zmian nazw'] = len(changes[producer])
    if changes[producer]:
        with open(f'{DEST}/{producer} - zmiany nazw.tsv', 'w', encoding='utf-8', newline='') as f:
            w = csv.DictWriter(f, fieldnames=sim_reader.fieldnames, delimiter='\t')
            w.writeheader()
            w.writerows(changes[producer])
shutil.copy('out/Enis - ryzyka.csv', f'{DEST}/Enis - ryzyka.csv')
shutil.copy('out/Enis - nowe bieżniki.csv', f'{DEST}/Enis - nowe bieżniki.csv')
cols = ['wierszy', 'aktualizacje', 'nowe', 'nowych bieżników', 'opon na nowych bieżnikach', 'zmian nazw']
wb = openpyxl.Workbook(write_only=True)
ws = wb.create_sheet('CSV z Enisa')
ws.append(['producent'] + cols)
for producer, s in sorted(stats.items(), key=lambda kv: -kv[1]['nowe']):
    ws.append([producer] + [s[c] for c in cols])
wb.save(f'{DEST}/Enis CSV - podsumowanie.xlsx')
total = collections.Counter()
for s in stats.values():
    total.update(s)
print(len(stats), 'producentów', dict(total))
for producer, s in sorted(stats.items(), key=lambda kv: -kv[1]['nowe'])[:20]:
    print(producer, [s[c] for c in cols])
