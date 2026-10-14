"""checkPricelist's report (`out/eprel.csv`) applied to `out/Enis - do EPREL.csv`, then one CSV per producer.

EPREL wins over Enis (CLAUDE.md, rule 4) only where the number is this tire's, and that is settled before
anything is taken from it: a number the register does not know, or whose registration has another size,
load index, speed index or GTIN, is removed and gives the row nothing — the tire stays, its identity is the
EAN. A speed index the register disagrees with is not Enis's mistake to correct: nothing confirms the number.
Then:
- label classes and dB from the register; a label Enis left out is filled from it;
- C and 3PMSF the register names are added; C only when the register's designation carries it (`195/75R16C`),
  because the checker also reads C off LT tires; 3PMSF the register denies is removed unless the base has it;
- XL is decided by the load index (above the standard index of that size in the base); the register's word
  counts only when it agrees, and never against HL;
- a dual load index (`102/100`) the register gives a new C tire replaces Enis's single one;
- the register's brand spelling (`NEXENTIRE`, `GOODRIDE`) is not a mismatch on its own.

No duplicate tires, last, once the marks are final. A new tire (EAN and catalogue number unknown to the base)
is dropped when it is a tire we already have or already write:
- its EPREL number belongs to a base tire of the producer, or to an earlier row (Kumho, AKN-748);
- a base tire (with an EAN or without), or an earlier new row, has the same tread, size, full indices
  (`104/102R` meets only `104/102R`) and identity marks: the same
  homologations, and run-flat, seal and silent present or absent (each brand has its own code for them) —
  unless both carry EPREL numbers and they differ: two registrations are two tires (Falken `336735`/`336735TH`).
  Reinforcement is left out because the load index already says it, and the rim protector because the base
  writes it four ways (FR, FSL, MFS, FP) and leaves it out as often.
Rows that update a base tire go first, then new ones with an EPREL number, then with a label, so the copy
that stays is the best documented one.

Writes `out/CSV/<Producent>.csv` and appends to `out/Enis - ryzyka.csv`.
"""
import collections
import csv
import os
import re

OUT = 'out'
FIELD = {'opór toczenia': 'opor', 'przyczepność': 'mokre', 'hałas dB': 'halas', 'klasa hałasu': 'fale'}
IDENTITY = {'profil', 'średnica', 'rozmiar', 'indeks nośności', 'indeks prędkości', 'EAN'}

PRESENCE_KINDS = {'runflat', 'seal', 'silent'}
kinds = collections.defaultdict(set)
for line in open('dictionary_kinds.tsv', encoding='utf-8').read().splitlines()[1:]:
    kind, code = line.split('\t')
    kinds[code].add(kind)
producer_id = {d['producer']: d['id'] for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t')}


def identity(other):
    marks = set()
    for t in (t.strip() for t in (other or '').split(';')):
        k = kinds.get(t, set())
        marks |= k & PRESENCE_KINDS
        if 'homologation' in k:
            marks.add(t)
    return frozenset(marks)


def spec(pp, tread, size, li, si):
    """Duplicate key; the base and the CSV both go through here, `li` whole (`104/102`)."""
    return pp, (tread or '').lower(), size, li, si


def split_indices(indices):
    m = re.fullmatch(r'(\d+(?:/\d+)?)([A-Z]+)', indices)
    return m.groups() if m else (indices, '')


standard = collections.defaultdict(collections.Counter)
existing_eans, existing_refs = set(), set()
base_eprel = set()
base_spec = collections.defaultdict(list)
base_other = {}
for d in csv.DictReader(open('db_all.tsv', encoding='utf-8'), delimiter='\t'):
    existing_eans.add(d['ean'])
    existing_refs.add((d['pp'], d['ref']))
    if d['eprel_id'] not in ('', 'NULL', '0'):
        base_eprel.add((d['pp'], d['eprel_id']))
    # tires without an EAN too: Accelera 6526 has none, and its ref is the EAN Enis gives under another numkat
    base_spec[spec(d['pp'], d['tread'], d['tire_size'], d['tire_li'], d['tire_si'])].append(
        (identity(d['other'] if d['other'] != 'NULL' else ''), d['eprel_id'] if d['eprel_id'] not in ('', 'NULL', '0') else '', d['id']))
    base_other[d['ean']] = {t.strip() for t in d['other'].split(';')}
    marks = {t.strip() for t in d['other'].split(';')} if d['other'] not in ('', 'NULL') else set()
    if d['vt'] in ('1', '3') and not marks & {'XL', 'HL', 'RF', 'C', 'EL', 'Reinforced', 'CP'} and d['tire_li'].isdigit():
        standard[d['tire_size']][int(d['tire_li'])] += 1


def is_new(r):
    """Neither the EAN nor the catalogue number is in the base — the import matches by both."""
    return r['ean'] not in existing_eans and (producer_id[r['producent']], r['numkat1']) not in existing_refs


def is_xl_by_index(size, indices):
    m = re.match(r'(\d+)', indices)
    if not m or not standard[size]:
        return None
    return int(m.group(1)) > standard[size].most_common(1)[0][0]


path = f'{OUT}/Enis - do EPREL.csv'
lines = open(path, encoding='utf-8').read().splitlines()
header = lines[0].split('@')
rows = [dict(zip(header, line.split('@'))) for line in lines[1:]]
by_ean = {r['ean']: r for r in rows}
log = []
for e in csv.DictReader(open(f'{OUT}/eprel.csv', encoding='utf-8')):
    r = by_ean.get(e['ean'])
    if r is None:
        continue
    if e['wynik'] not in ('OK', 'ROZJAZD', 'BRAK_W_EPREL'):
        continue
    parts = [p for p in e['rozjazdy'].split('; ') if p and not p.startswith('marka: ')]
    fields = {p.split(': ', 1)[0] for p in parts}
    # `szerokość` on an inch size (`31x10.50R15`) is the checker reading the width two ways, not another tire
    wrong = e['wynik'] == 'BRAK_W_EPREL' or bool(fields & IDENTITY) or ('szerokość' in fields and 'x' not in r['rozmiar'])
    if wrong:
        what = 'nic nie zwraca' if e['wynik'] == 'BRAK_W_EPREL' else f"to inna opona: {e['rozjazdy']} ({e['eprel_oznaczenie']})"
        log.append((r, 'EPREL — numer usunięty', f"{r['eprel']} {what}"))
        r['eprel'] = ''
        continue
    label = re.fullmatch(r'([A-G])/([A-G])/(\d+) dB/([A-C])', e['eprel_etykieta'] or '')
    if label and not r['opor']:
        # Enis left the label out or wrote it wrong (`7` dB): the register has it
        r['opor'], r['mokre'], r['halas'], r['fale'] = label.groups()
        log.append((r, 'etykieta z EPREL', e['eprel_etykieta']))
    dual = re.search(r'\s(\d{2,3})/(\d{2,3})\s*[A-Z]\b', e['eprel_oznaczenie'] or '')
    if is_new(r) and dual and re.fullmatch(dual.group(1) + r'[A-Z]', r['indeksy']):
        # Enis gives C tires one load index (`102`), the register both (`102/100`), as the base keeps them
        r['indeksy'] = f"{dual.group(1)}/{dual.group(2)}{r['indeksy'][-1]}"
        log.append((r, 'podwójny LI z EPREL', e['eprel_oznaczenie']))
    for part in parts:
        field, change = part.split(': ', 1)
        after = change.split(' → ')[1]
        tokens = r['inne'].split(';') if r['inne'] else []
        if field in FIELD:
            r[FIELD[field]] = after
        elif field == 'C' and after == 'tak' and not re.search(r'\dC\b', e['eprel_oznaczenie']):
            log.append((r, 'EPREL — rozjazd', f"{part} — rejestr pisze rozmiar bez C ({e['eprel_oznaczenie']}), zostaje Enis"))
            continue
        elif field in ('C', '3PMSF') and after == 'tak':
            tokens.append(field)
        elif field == '3PMSF' and '3PMSF' not in base_other.get(r['ean'], set()):
            # Enis marks summer tires 3PMSF (Radial RA10, PMS01); the base did not, the register says no
            tokens = [t for t in tokens if t != '3PMSF']
        elif field == 'XL' and 'HL' in tokens:
            log.append((r, 'EPREL — rozjazd', f'{part} — opona HL, zostaje HL'))
            continue
        elif field == 'XL':
            by_index = is_xl_by_index(r['rozmiar'], r['indeksy'])
            if by_index is None or by_index != (after == 'tak'):
                log.append((r, 'EPREL — rozjazd', f"{part} — indeks nośności {'nie rozstrzyga' if by_index is None else 'przeczy rejestrowi'}, zostaje Enis"))
                continue
            tokens = [t for t in tokens if t != 'XL'] + (['XL'] if after == 'tak' else [])
        else:
            log.append((r, 'EPREL — rozjazd', f'{part} — zostaje Enis'))
            continue
        r['inne'] = ';'.join(dict.fromkeys(tokens))
        log.append((r, 'EPREL poprawia Enisa', f"{part} (EPREL {r['eprel']}: {e['eprel_oznaczenie']}, {e['eprel_etykieta']})"))

# rows that already know their tire first, then new ones with an EPREL number, then with a label
order = sorted(rows, key=lambda r: (is_new(r), not r['eprel'], not r['opor']))
seen_eprel, seen_spec, dropped = set(), collections.defaultdict(list), set()
for r in order:
    pp = producer_id[r['producent']]
    key, ident = spec(pp, r['bieznik'], r['rozmiar'], *split_indices(r['indeksy'])), identity(r['inne'])
    if is_new(r):
        why = None
        if r['eprel'] and ((pp, r['eprel']) in base_eprel or (pp, r['eprel']) in seen_eprel):
            why = f"EPREL {r['eprel']} ma już opona {'w bazie' if (pp, r['eprel']) in base_eprel else 'wcześniej w pliku'}"
        else:
            for other_ident, other_eprel, where in base_spec.get(key, []) + seen_spec[key]:
                if other_ident == ident and not (r['eprel'] and other_eprel and r['eprel'] != other_eprel):
                    why = f"ta sama opona co {where} (bieżnik, rozmiar, indeksy, oznaczenia {sorted(ident) or '—'})"
                    break
        if why:
            dropped.add(r['ean'])
            log.append((r, 'pominięta: duplikat', why))
            continue
    if r['eprel']:
        seen_eprel.add((pp, r['eprel']))
    seen_spec[key].append((ident, r['eprel'], f"EAN {r['ean']} w pliku"))
per = collections.defaultdict(list)
for r in rows:
    if r['ean'] not in dropped:
        per[r['producent']].append(r)
os.makedirs(f'{OUT}/CSV', exist_ok=True)
for producer, items in per.items():
    with open(f'{OUT}/CSV/{producer}.csv', 'w', encoding='utf-8', newline='') as f:
        f.write('@'.join(header) + '\n')
        for r in items:
            f.write('@'.join(r[c] for c in header) + '\n')
with open(f'{OUT}/Enis - ryzyka.csv', 'a', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter=';')
    for r, kind, detail in log:
        w.writerow([r['producent'], '', r['numkat1'], r['ean'], f"{r['rozmiar']} {r['indeksy']} {r['bieznik']}", kind, detail])
print('plików', len(per), 'wierszy', sum(len(v) for v in per.values()), 'duplikatów pominiętych', len(dropped))
print(collections.Counter(k for _, k, _ in log))
print(collections.Counter(d.split(':')[0] for _, k, d in log if k.startswith('EPREL')).most_common(12))
