"""Raport `bin/eprelCheck.php` (`out/eprel.csv`) zastosowany do `out/Enis - do EPREL.csv`, potem CSV per producent.

EPREL wygrywa z Enisem (CLAUDE.md, zasada 4) tylko na numerze tej opony, a to rozstrzyga się, zanim
cokolwiek z niego weźmiemy: numer, którego rejestr nie zna, albo rejestracja z innym rozmiarem, indeksem
nośności, indeksem prędkości lub GTIN-em jest usuwana i nie daje wierszowi nic — opona zostaje, jej
tożsamością jest EAN. Indeks prędkości, z którym rejestr się nie zgadza, to nie błąd Enisa do poprawienia:
nic nie potwierdza, że numer jest tej opony.
Potem:
- klasy etykiety i dB z rejestru; etykietę, której Enis nie podał, uzupełnia rejestr;
- C i 3PMSF, które podaje rejestr, są dopisywane; C tylko gdy oznaczenie w rejestrze je ma (`195/75R16C`),
  bo checker czyta C także z opon LT; 3PMSF, któremu rejestr przeczy, wypada, chyba że ma je baza;
- o XL decyduje indeks nośności (powyżej standardowego dla rozmiaru w bazie); słowo rejestru liczy się
  tylko, gdy się z nim zgadza, i nigdy przeciw HL;
- podwójny indeks nośności (`102/100`), który rejestr podaje nowej oponie C, zastępuje pojedynczy Enisa;
- pisownia marki w rejestrze (`NEXENTIRE`, `GOODRIDE`) sama nie jest rozjazdem.

Żadnych duplikatów opon — na końcu, gdy oznaczenia są już ostateczne. Nowa opona (EAN i numkat nieznane
bazie) wypada, gdy to opona, którą już mamy albo już zapisujemy:
- jej numer EPREL ma opona producenta w bazie albo wcześniejszy wiersz (Kumho, AKN-748);
- opona z bazy (z EAN-em albo bez) albo wcześniejszy nowy wiersz ma ten sam bieżnik, rozmiar, pełne
  indeksy (`104/102R` spotyka tylko `104/102R`) i oznaczenia tożsamości: te same homologacje oraz
  run-flat, uszczelnienie i wyciszenie obecne albo nie (każda marka ma na nie własny kod) — chyba że obie
  mają numery EPREL i są one różne: dwie rejestracje to dwie opony (Falken `336735`/`336735TH`).
  Wzmocnienie jest poza porównaniem, bo mówi o nim już indeks nośności, a rant, bo baza zapisuje go na
  cztery sposoby (FR, FSL, MFS, FP) i równie często go pomija.
Najpierw idą wiersze aktualizujące oponę z bazy, potem nowe z numerem EPREL, potem z etykietą, więc
zostaje najlepiej udokumentowana kopia.

Pisze `out/CSV/<Producent>.csv` i dopisuje do `out/Enis - ryzyka.csv`.
"""
import collections
import csv
import os
import re

OUT = 'out'
FIELD = {'opór toczenia': 'opor', 'przyczepność': 'mokre', 'hałas dB': 'halas', 'klasa hałasu': 'fale'}
IDENTITY = {'szerokość', 'profil', 'średnica', 'rozmiar', 'indeks nośności', 'indeks prędkości', 'EAN'}

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
    # także opony bez EAN-u: Accelera 6526 go nie ma, a w `ref` ma EAN, który Enis podaje pod innym numkatem
    base_spec[spec(d['pp'], d['tread'], d['tire_size'], d['tire_li'], d['tire_si'])].append(
        (identity(d['other'] if d['other'] != 'NULL' else ''), d['eprel_id'] if d['eprel_id'] not in ('', 'NULL', '0') else '', d['id']))
    base_other[d['ean']] = {t.strip() for t in d['other'].split(';')}
    marks = {t.strip() for t in d['other'].split(';')} if d['other'] not in ('', 'NULL') else set()
    if d['vt'] in ('1', '3') and not marks & {'XL', 'HL', 'RF', 'C', 'EL', 'Reinforced', 'CP'} and d['tire_li'].isdigit():
        standard[d['tire_size']][int(d['tire_li'])] += 1


def is_new(r):
    """Ani EAN-u, ani numkatu nie ma w bazie — import szuka po obu."""
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
    wrong = e['wynik'] == 'BRAK_W_EPREL' or bool(fields & IDENTITY)
    if wrong:
        what = 'nic nie zwraca' if e['wynik'] == 'BRAK_W_EPREL' else f"to inna opona: {e['rozjazdy']} ({e['eprel_oznaczenie']})"
        log.append((r, 'EPREL — numer usunięty', f"{r['eprel']} {what}"))
        r['eprel'] = ''
        continue
    label = re.fullmatch(r'([A-G])/([A-G])/(\d+) dB/([A-C])', e['eprel_etykieta'] or '')
    if label and not r['opor']:
        # Enis nie podał etykiety albo podał złą (`7` dB): rejestr ją ma
        r['opor'], r['mokre'], r['halas'], r['fale'] = label.groups()
        log.append((r, 'etykieta z EPREL', e['eprel_etykieta']))
    dual = re.search(r'\s(\d{2,3})/(\d{2,3})\s*[A-Z]\b', e['eprel_oznaczenie'] or '')
    if is_new(r) and dual and re.fullmatch(dual.group(1) + r'[A-Z]', r['indeksy']):
        # Enis daje oponom C jeden indeks nośności (`102`), rejestr oba (`102/100`), tak jak trzyma je baza
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
            # Enis oznacza 3PMSF opony letnie (Radial RA10, PMS01); baza nie, rejestr mówi, że nie
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

# najpierw wiersze, które znają swoją oponę, potem nowe z numerem EPREL, potem z etykietą
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
