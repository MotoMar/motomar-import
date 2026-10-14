"""Copy the per-producer CSVs to $CENNIKI/Enis/CSV with their name changes, the new treads and a summary.

The folder is this script's output and nothing else: it is emptied first, so a producer that dropped out
leaves no stale file behind."""
import collections, csv, os, shutil
import openpyxl

DEST = os.path.join(os.environ['CENNIKI'], 'Enis', 'CSV')
shutil.rmtree(DEST, ignore_errors=True)
os.makedirs(DEST)
base_treads = {(t['producer_id'], t['tread']) for t in csv.DictReader(open('treads.tsv', encoding='utf-8'), delimiter='\t')}
producer_id = {d['producer']: d['id'] for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t')}
existing, refs = set(), set()
for d in csv.DictReader(open('db_all.tsv', encoding='utf-8'), delimiter='\t'):
    existing.add(d['ean'])
    refs.add((d['pp'], d['ref']))
sim = list(csv.DictReader(open('out/sim.tsv', encoding='utf-8'), delimiter='\t'))
sim_header = list(sim[0].keys())
rows = list(csv.DictReader(open('out/final.csv', encoding='utf-8'), delimiter='@'))
producer_of = {r['ean']: r['producent'] for r in rows}
stats = collections.defaultdict(collections.Counter)
new_treads = collections.defaultdict(set)
for r in rows:
    s = stats[r['producent']]
    s['wierszy'] += 1
    is_new = r['ean'] not in existing and (producer_id[r['producent']], r['numkat1']) not in refs  # import matches by ref too
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
            w = csv.DictWriter(f, fieldnames=sim_header, delimiter='\t')
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
