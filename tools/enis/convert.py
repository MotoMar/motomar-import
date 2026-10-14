"""Baza produktowa Enisa (`Cenniki/Baza produktowa.xlsx`, zrzucona do rows.jsonl) → CSV importu.

Jeden konwerter dla wszystkich marek, bo kolumny Enisa są dla wszystkich te same. Zasady przeniesione
z cenników latexopony (AKN-736):

- wiersz, którego EAN jest w którymś z naszych cenników (`Cenniki/*/*.csv`), jest pomijany — cennik
  producenta jest lepszym źródłem;
- tylko producenci, których zna baza (po nazwie, bez wielkości liter, albo przez ALIASES); reszta czeka
  na decyzję;
- istniejąca opona zachowuje swoje `other` i bieżnik, dostaje tylko dopiski; oznaczenia rodzaju, który
  już ma (rant, run-flat, uszczelnienie, wyciszenie, wzmocnienie), nie dostaje drugi raz;
- tożsamością opony jest EAN, nie numer EPREL: nowa opona potrzebuje poprawnego GTIN-13 (cyfra
  kontrolna), nie numeru EPREL; drugi wiersz z tym samym EAN-em jest pomijany;
- rant, run-flat, uszczelnienie i wyciszenie idą kodem, którego marka używa w bazie;
- nowa opona z tym samym bieżnikiem, rozmiarem, indeksami i numerem EPREL co opona z bazy pod innym
  EAN-em to ta sama opona i jest pomijana (Kumho, AKN-748).

Uruchamiany w katalogu roboczym (run.sh). Czyta rows.jsonl, db_all.tsv, treads.tsv, producers.tsv,
dictionary_kinds.tsv i przerobione cenniki z $CENNIKI. Pisze `out/Enis - wszystkie.csv` (jeden plik, żeby
sprawdzić go z EPREL-em za jednym razem) i od nowa `out/Enis - ryzyka.csv`, do którego dopisują kolejne kroki.
Opcjonalne argumenty: nazwy producentów, żeby przerobić tylko ich.
"""
import collections
import csv
import glob
import json
import os
import re
import sys

OUT = 'out'
ONLY = {p.lower() for p in sys.argv[1:]}
COLUMNS = 'numkat1 numkat2 ean id producent rodzaj bieznik rozmiar rozmiar2 indeksy indeksy2 inne opor mokre halas fale eprel netto sezon'.split()
# kolumna `sezon` Enisa → nazwa z `tires_seasons`; import podpowiada ją w kroku 3 dla nowego modelu (AKN-759)
SEASON = {'letnie': 'Letnia', 'zimowe': 'Zimowa', 'całoroczne': 'Całoroczna'}
VT = {'1': 'O', '2': 'D', '3': 'T'}
TYPE = {'osobowy': 'O', 'dostawczy': 'D', '4x4': 'T', 'runflat': 'O', 'wyścigowy': 'O'}
# oznaczenia, które baza trzyma w `other`, nigdy w nazwie bieżnika (`X-Ice XI3 ZP` to `X-Ice XI3` z ZP)
NAME_MARK_KINDS = {'runflat', 'white_wall', 'reinforcement'}
SINGLE_KINDS = {'runflat', 'silent', 'seal', 'rim_protector', 'reinforcement'}
NEUTRAL_KINDS = {'season', 'ev', 'tube_type', 'country', 'tire_technology', 'load_range', 'ice_grip'}
# kolumna flagi Enisa → kod słownika; None: kod tego rodzaju, którego używa marka, albo nic, gdy go nie ma
FLAGS = {'c': 'C', 'lt': 'LT', 'ZR': 'ZR', 'm+s': 'M+S', '3PMSF': '3PMSF', 'Przyczepność na lodzie': 'Ice Grip',
         'owl': 'OWL', 'rbl': 'RBL', 'rwl': 'RWL', 'ww': 'WW', 'por': 'POR', 'connect': 'CONNECT',
         'dt': 'DT', 'dt1': 'DT1', 'k1': 'K1', 'k2': 'K2', 's1': 'S1', 's2': 'S2', 's3': 'S3', 'pz4': 'PZ4', 'ls': 'LS'}
KIND_FLAGS = {'FR': ('rim_protector', 'FR'), 'rft': ('runflat', 'RFT'), 'seal': ('seal', None),
              'noise_reducing_tech': ('silent', None), 'silent_drive': ('silent', None)}
# pisownia Enisa → nasza; te same opony mają wspólne EAN-y (Point S 17, Firststop 33, Event 4)
ALIASES = {'point s': 'point-s', 'firststop': 'first stop', 'event tyres': 'event tyre'}
HOMOLOGATION = {'MOEXTENDED': 'MOE', 'N-0': 'N0', 'N-1': 'N1', '*MOE': ['*', 'MOE'], '☆': '*'}
# wolny tekst w `dodatkowe`, który znaczy oznaczenie
EXTRA = {'płatek śniegu': '3PMSF'}

kinds = collections.defaultdict(set)
for line in open('dictionary_kinds.tsv', encoding='utf-8').read().splitlines()[1:]:
    kind, code = line.split('\t')
    kinds[code].add(kind)
codes_lower = {c.lower(): c for c in kinds}


def kind_of(code):
    return kinds.get(code, set())


def other_tokens(other):
    return [t.strip() for t in other.split(';') if t.strip()] if other not in ('', 'NULL', None) else []


producers = {}
for d in csv.DictReader(open('producers.tsv', encoding='utf-8'), delimiter='\t'):
    producers.setdefault(d['producer'].lower(), d)  # `alcar` dwa razy; to nie marka Enisa
db_by_ean, db_by_ref = {}, {}
brand_tires = collections.defaultdict(list)
for d in csv.DictReader(open('db_all.tsv', encoding='utf-8'), delimiter='\t'):
    if d['ean'] not in ('', 'NULL'):
        db_by_ean[d['ean']] = d
    if d['ref'] not in ('', 'NULL'):
        db_by_ref[(d['pp'], d['ref'])] = d
    brand_tires[d['pp']].append(d)
treads = collections.defaultdict(list)
for t in csv.DictReader(open('treads.tsv', encoding='utf-8'), delimiter='\t'):
    treads[t['producer_id']].append(t)
ours = set()
for p in glob.glob(os.path.join(os.environ['CENNIKI'], '*', '*.csv')):
    if '/Enis/' in p or 'ryzyka' in p:
        continue
    ours |= {r['ean'] for r in csv.DictReader(open(p, encoding='utf-8'), delimiter='@') if r['ean']}


def squash(s):
    return re.sub(r'[^0-9a-z+]', '', s.lower())


def resolve_tread(pp, name):
    exact = [t for t in treads[pp] if t['tread'].lower() == name.lower()]
    if exact:
        return max(exact, key=lambda t: int(t['n']))
    loose = [t for t in treads[pp] if squash(t['tread']) == squash(name)]
    return max(loose, key=lambda t: int(t['n'])) if loose else None


ACRONYMS = {'RFT', 'ROF', 'SSR', 'ZPS', 'AWD', 'UHP', 'HP', 'SUV', 'AT', 'MT', 'HT', 'RT', 'AS', 'LT', 'VAN', 'XL', 'EV', 'UHP2', 'XT', 'II', 'III', 'IV'}


def display_word(w):
    """`SNOWMASTER` → `Snowmaster`, `ICE-SPIDER` → `Ice-Spider`; kody (`ZSR2`, `DU71`, `Z-107`, `A/S`) zostają, `Sa12` → `SA12`."""
    if re.fullmatch(r'[A-Za-z]{1,4}-?\d+[A-Za-z0-9+]*', w):
        return w.upper()
    if w.isupper() and len(w) <= 2:  # `MA 702`, nie `Ma 702`
        return w
    if not w.isupper() or '/' in w or w in ACRONYMS:
        return w
    if re.fullmatch(r'[A-Z]{0,3}\d+[A-Z0-9]*|\d+[A-Z]*', w):
        return w
    return '-'.join(part if re.fullmatch(r'[A-Z]{0,3}\d+[A-Z0-9]*|\d*', part) or part in ACRONYMS else part.capitalize() for part in w.split('-'))


def display_name(pattern):
    return ' '.join(display_word(w) for w in pattern.split())


def pattern_code(name):
    """Kod wzoru w nazwie bieżnika (`Z-401`, `ZSR2`, `KC53`), jeśli jest."""
    codes = [w for w in name.upper().split() if re.fullmatch(r'[A-Z]{1,4}-?\d{2,4}[A-Z+]?', w)]
    return codes[-1] if codes else None


class Brand:
    """Co baza mówi o producencie: jego kody w każdym rodzaju, typy pojazdów jego bieżników i oznaczenia."""

    def __init__(self, pp):
        self.tires = brand_tires[pp]
        self.code = {}
        by_kind = collections.defaultdict(collections.Counter)
        self.tread_vt = collections.defaultdict(collections.Counter)
        self.spec = collections.defaultdict(list)
        for d in self.tires:
            for t in other_tokens(d['other']):
                for k in kind_of(t) & {'rim_protector', 'runflat', 'seal', 'silent'}:
                    by_kind[k][t] += 1
            self.tread_vt[d['tread']][d['vt']] += 1
            self.spec[((d['tread'] or '').lower(), d['tire_size'], d['tire_li'], d['tire_si'])].append(d)
        for k, c in by_kind.items():
            self.code[k] = c.most_common(1)[0][0]


brands = {}
report = []
stats = collections.Counter()
EV_SEEN = collections.defaultdict(collections.Counter)


def flag(r, kind, detail):
    report.append({'producent': r.get('Prod_enis'), 'wiersz': r['_row'], 'kod': r.get('kod'), 'ean': r.get('ean'),
                   'opis': f"{r.get('Rozmiar')} {r.get('LI')}{r.get('SI')} {r.get('Bieżnik')}", 'problem': kind, 'szczegoly': detail})


def on(r, col):
    return str(r.get(col) or '') == '1'


def gtin_ok(ean):
    total = sum(int(c) * (3 if i % 2 else 1) for i, c in enumerate(ean[:12]))
    return (10 - total % 10) % 10 == int(ean[12])


def parse_size(raw):
    s = re.sub(r'\s+', ' ', str(raw).upper()).strip()
    m = re.fullmatch(r'(\d+)X+(\d+(?:\.\d+)?) ?R ?(\d+(?:\.\d+)?)', s)
    if m:
        return f'{m.group(1)}x{float(m.group(2)):.2f}R{m.group(3)}'
    m = re.fullmatch(r'(\d+(?:\.\d+)?)(?:/(\d+(?:\.\d+)?))? ?Z?R ?(\d+(?:\.\d+)?)', s)
    if not m or float(m.group(3)) > 30:
        return None
    w, p, d = m.groups()
    if '.' in w or float(w) < 20:
        w = f'{float(w):.2f}'
    return f'{w}/{p}R{d}' if p else f'{w}R{d}'


rows = [json.loads(l) for l in open('rows.jsonl', encoding='utf-8')]
rows = [r for r in rows if r.get('Typ') != 'Typ' and r.get('Prod')]
for r in rows:
    r['Prod_enis'] = r['Prod']
    r['Prod'] = ALIASES.get(str(r['Prod']).strip().lower(), str(r['Prod']).strip())

# EV: Enis oznacza 10 459 opon, a „EV-ready” to nie opona EV (Goodyear, AKN-741). Marka dostaje EV
# tylko tam, gdzie jej opony w bazie już je mają na tym, co oznacza Enis.
for r in rows:
    d = db_by_ean.get(str(r['ean']))
    if d is not None and on(r, 'EV'):
        EV_SEEN[str(r['Prod']).lower()]['EV' in other_tokens(d['other'])] += 1


def brand_takes_ev(prod):
    seen = EV_SEEN[prod]
    return seen[True] >= 10 and seen[True] >= 4 * seen[False]


# Enis pisze bieżniki po swojemu (`ENRI U08`, `Proxes Comfort`); opony, które obie strony znają po EAN,
# mówią, który bieżnik z bazy znaczy dana nazwa
seen_on = collections.defaultdict(collections.Counter)
for r in rows:
    d = db_by_ean.get(str(r['ean']))
    if d is not None and d['tread'] and r.get('Bieżnik'):
        seen_on[(d['pp'], squash(str(r['Bieżnik'])))][d['tread']] += 1

new_by_code = {}
out = []
taken = set()
for r in rows:
    prod = str(r['Prod']).lower()
    if ONLY and prod not in ONLY:
        continue
    ean = str(r['ean']).strip()
    if ean in ours:
        stats['pominięta: jest w naszym cenniku'] += 1
        continue
    base_producer = producers.get(prod)
    if base_producer is None:
        stats['pominięta: producenta nie ma w bazie'] += 1
        continue
    pp, producer = base_producer['id'], base_producer['producer']
    if pp not in brands:
        brands[pp] = Brand(pp)
    brand = brands[pp]
    if not (ean.isdigit() and len(ean) == 13):
        flag(r, 'pominięta', f'EAN {ean!r} to nie 13 cyfr')
        stats['pominięta: zły EAN'] += 1
        continue
    if ean in taken:
        flag(r, 'pominięta', f'EAN {ean} drugi raz w Enisie')
        stats['pominięta: EAN powtórzony'] += 1
        continue
    code = str(r.get('kod') or '').strip()
    existing = db_by_ean.get(ean)
    if existing is not None and existing['pp'] != pp:
        flag(r, 'pominięta', f"EAN należy w bazie do opony {existing['id']} producenta {existing['pp']}")
        stats['pominięta: EAN innego producenta'] += 1
        continue
    if existing is None and (pp, code) in db_by_ref:
        existing = db_by_ref[(pp, code)]
        flag(r, 'dopasowanie po numkat', f"EAN {ean} nie ma w bazie, kod {code} trafia oponę {existing['id']} (EAN {existing['ean']})")

    size = parse_size(r.get('Rozmiar'))
    li = re.sub(r'\s+', '', str(r.get('LI') or ''))
    si = str(r.get('SI') or '').upper()
    if si == 'ZR' and on(r, '(Y)'):
        si = 'Y'
    if size is None or not re.fullmatch(r'\d+(/\d+)?', li) or not re.fullmatch(r'[A-Z]', si):
        flag(r, 'pominięta', f"rozmiar/indeksy nie do zapisania: {r.get('Rozmiar')!r} {li!r} {si!r}")
        stats['pominięta: rozmiar albo indeksy'] += 1
        continue

    eprel = str(r.get('EPEREL kod') or '').strip()
    eprel = eprel if eprel.isdigit() and int(eprel) > 0 else ''  # Enis pisze `0` zamiast braku (Nexen)
    if existing is None and not gtin_ok(ean):
        flag(r, 'pominięta', f'nowa opona, EAN {ean} ma złą cyfrę kontrolną')
        stats['pominięta: nowa ze złą cyfrą kontrolną EAN'] += 1
        continue

    tokens = []
    if on(r, 'hl'):
        tokens.append('HL')
    elif on(r, 'cp'):
        tokens.append('CP')
    elif on(r, 'XL'):
        tokens.append('XL')
    for col, code_ in FLAGS.items():
        if on(r, col):
            tokens.append(code_)
    for col, (kind, default) in KIND_FLAGS.items():
        if on(r, col):
            mark = brand.code.get(kind, default)
            if mark:
                tokens.append(mark)
            else:
                flag(r, 'bez kodu marki', f'{col}: marka nie ma w bazie kodu rodzaju {kind} — pomijam')
    if on(r, 'EV'):
        if brand_takes_ev(prod):
            tokens.append('EV')
        else:
            stats['EV pominięte'] += 1
    for h in re.split(r',\s*', str(r.get('homo') or '')):
        h = h.strip()
        if not h:
            continue
        mapped = HOMOLOGATION.get(h, h)
        for m_ in mapped if isinstance(mapped, list) else [mapped]:
            if m_.lower() in codes_lower:
                tokens.append(codes_lower[m_.lower()])
            else:
                flag(r, 'homologacja', f'{h!r} — brak kodu w słowniku, pomijam')
    for t in str(r.get('dodatkowe') or '').split('|'):
        t = t.strip()
        t = EXTRA.get(t.lower(), t)
        if t.lower() in codes_lower:
            t = codes_lower[t.lower()]
            single = kind_of(t) & {'rim_protector', 'runflat', 'seal', 'silent'}
            tokens.append(brand.code.get(next(iter(single)), t) if single else t)
    tokens = [codes_lower.get(t.lower(), t) for t in dict.fromkeys(tokens)]
    for t in tokens:
        if t.lower() not in codes_lower:
            flag(r, 'poza słownikiem', f'{t!r}')

    model = str(r.get('Bieżnik') or '').strip().replace('@', 'A')  # `EUFORI@` Pirelli to nasza `Euforia`
    for prefix in (producer, str(r['Prod_enis'])):
        if model.lower().startswith(prefix.lower() + ' '):
            model = model[len(prefix) + 1:]
    words = model.split()
    kept = words[:1]
    for w in words[1:]:
        mark = codes_lower.get(w.lower())
        if not (mark and kind_of(mark) & NAME_MARK_KINDS):
            kept.append(w)
            continue
        kind = next(iter(kind_of(mark) & NAME_MARK_KINDS))
        if not any(kind in kind_of(t) for t in tokens):  # `HL` mówi już to, co powiedziałoby `XL`
            tokens.append(brand.code.get(kind, mark) if kind == 'runflat' else mark)
    model = ' '.join(kept)
    tokens = list(dict.fromkeys(tokens))
    if existing is not None and existing['tread'] not in ('', 'NULL', None):
        tread_out = existing['tread']
    else:  # nowa opona albo taka, którą baza trzyma bez bieżnika (Continental 3569)
        guess = resolve_tread(pp, model) if model else None
        known = seen_on.get((pp, squash(str(r.get('Bieżnik') or ''))))
        if known:
            (tread_out, _), *others = known.most_common()
            if others:
                flag(r, 'bieżnik', f"{model!r}: istniejące opony leżą na {dict(known)} — biorę {tread_out!r}")
        elif guess is not None:
            tread_out = guess['tread']
        elif model:
            tread_out = display_name(model)
            code_ = pattern_code(model)
            if code_:
                by_code = [t for t in treads[pp] if pattern_code(t['tread']) == code_ and int(t['n']) > 0]
                if by_code:
                    tread_out = max(by_code, key=lambda t: int(t['n']))['tread']
                    flag(r, 'bieżnik po kodzie', f'{model!r} → {tread_out!r}')
                else:
                    new_by_code.setdefault((producer, code_), set()).add(tread_out)
            if not (code_ and by_code):
                flag(r, 'nowy bieżnik', f'{model!r} → {tread_out!r}')
                stats['nowa na nowym bieżniku'] += 1
        else:
            flag(r, 'pominięta', 'brak bieżnika')
            stats['pominięta: brak bieżnika'] += 1
            continue

    # `dodatkowe` powtarza to, co mówi już nazwa bieżnika (`SUV` przy `Ventus S1 evo2 SUV`)
    in_name = {w.lower() for w in tread_out.split()}
    tokens = [t for t in tokens if t.lower() not in in_name]

    fuel = str(r.get('Opór toczenia') or '')
    wet = str(r.get('Przyczepność na mokrej nawierzchni') or '')
    noise_class = str(r.get('Klasa hałasu') or '')
    noise_db = str(r.get('Hałas zewnętrzny') or '')
    if not re.fullmatch(r'[A-G]', fuel) or not re.fullmatch(r'[A-G]', wet) or not re.fullmatch(r'[A-C]', noise_class) or not re.fullmatch(r'\d\d', noise_db):
        if fuel or wet or noise_class or noise_db:
            flag(r, 'etykieta', f'niepełna albo zła: {fuel!r}/{wet!r}/{noise_db!r}/{noise_class!r} — z EPREL-u albo pusta')
        fuel = wet = noise_class = noise_db = ''

    purpose = TYPE.get(str(r.get('Typ')), 'O')
    if 'C' in tokens:
        purpose = 'D'
    vt = brand.tread_vt[tread_out].most_common(1)
    rodzaj = VT.get(vt[0][0], purpose) if vt and purpose != 'D' else purpose

    if existing is not None:
        stats['aktualizacja'] += 1
        theirs = other_tokens(existing['other'])
        their_kinds = {k for t in theirs for k in kind_of(t)}
        added = [t for t in tokens if t not in theirs and not (kind_of(t) & SINGLE_KINDS & their_kinds)]
        named = [t for t in added if not kind_of(t) <= NEUTRAL_KINDS]
        if named:
            flag(r, 'dopisane oznaczenie', f"opona {existing['id']}: + {named}")
        tokens = theirs + added
    else:
        twins = [d for d in brand.spec.get((tread_out.lower(), size, li, si), []) if d['ean'] != ean]
        same = [d for d in twins if eprel and d['eprel_id'] == eprel]
        if same:
            flag(r, 'pominięta', f"duplikat: opona {same[0]['id']} (EAN {same[0]['ean']}) ma ten sam EPREL {eprel}")
            stats['pominięta: duplikat po EPREL'] += 1
            continue
        if twins:
            flag(r, 'możliwy duplikat opony', ', '.join(f"{d['id']} (EAN {d['ean']}, EPREL {d['eprel_id']}, inne {d['other']})" for d in twins[:3]))
        stats['nowa'] += 1

    taken.add(ean)
    out.append({c: '' for c in COLUMNS} | {
        'numkat1': code, 'ean': ean, 'producent': producer, 'rodzaj': rodzaj, 'bieznik': tread_out,
        'rozmiar': size, 'indeksy': f'{li}{si}', 'inne': ';'.join(tokens),
        'opor': fuel, 'mokre': wet, 'halas': noise_db, 'fale': noise_class, 'eprel': eprel,
        'sezon': SEASON.get(str(r.get('sezon') or '').strip().lower(), ''),
    })

# jedna pisownia nowego bieżnika: `Van`/`VAN`, `Ice Gripper`/`ICE GRIPPER` dostają najczęstszą
spellings = collections.defaultdict(collections.Counter)
base_names = {(t['producer_id'], t['tread']) for ts in treads.values() for t in ts}


def is_new_tread(item):
    return (producers[item['producent'].lower()]['id'], item['bieznik']) not in base_names


for item in out:
    if is_new_tread(item):
        spellings[(item['producent'], squash(item['bieznik']))][item['bieznik']] += 1
for item in out:
    if is_new_tread(item):
        item['bieznik'] = spellings[(item['producent'], squash(item['bieznik']))].most_common(1)[0][0]

# jeden nowy bieżnik na kod wzoru, pod najpełniejszą nazwą, jaką daje lista
for item in out:
    code_ = pattern_code(item['bieznik'])
    names = new_by_code.get((item['producent'], code_))
    if names and item['bieznik'] in names:
        # `Z-401` dołącza do `All Season Elite Z-401`; `Z-007 SUV` zostaje osobno od `Z-007`, bo baza trzyma bieżniki SUV osobno
        fuller = [n for n in names if squash(n).endswith(squash(item['bieznik']))]
        item['bieznik'] = max(fuller, key=len)

os.makedirs(OUT, exist_ok=True)
with open(f'{OUT}/Enis - wszystkie.csv', 'w', encoding='utf-8', newline='') as f:
    f.write('@'.join(COLUMNS) + '\n')
    for item in out:
        values = [item[c] for c in COLUMNS]
        assert not any('@' in v or '\n' in v for v in values), values
        f.write('@'.join(values) + '\n')
with open(f'{OUT}/Enis - ryzyka.csv', 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=['producent', 'wiersz', 'kod', 'ean', 'opis', 'problem', 'szczegoly'], delimiter=';')
    w.writeheader()
    w.writerows(report)
print(len(out), dict(stats))
print('ryzyka', collections.Counter(x['problem'] for x in report).most_common())
print('EV marki biorące:', sorted(p for p in EV_SEEN if brand_takes_ev(p)))
