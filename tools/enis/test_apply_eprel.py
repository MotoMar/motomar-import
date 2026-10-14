"""Contracts of the Enis tools on hand-made inputs: python3 -m unittest discover -s tools/enis -p 'test_*.py'

Each test runs a script the way run.sh does, in a scratch work directory, and reads what it wrote.
The EPREL cases come from the review of PR #7 (AKN-756), where each was a measured defect in a real run.
"""
import csv
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

TOOLS = Path(__file__).resolve().parent
COLUMNS = 'numkat1 numkat2 ean id producent rodzaj bieznik rozmiar rozmiar2 indeksy indeksy2 inne opor mokre halas fale eprel netto'.split()
DB_COLUMNS = 'id pp ean ref tread tire_size tire_li tire_si other vt eprel_id rr wg nd nc'.split()
REPORT_COLUMNS = 'ean wynik rozjazdy eprel_etykieta eprel_oznaczenie'.split()


def write_table(path, columns, rows, delimiter):
    with path.open('w', newline='', encoding='utf-8') as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter=delimiter)
        writer.writeheader()
        writer.writerows(rows)


def run(work, script, env=None):
    return subprocess.run([sys.executable, '-E', str(TOOLS / script)], cwd=work, capture_output=True, text=True,
                          env=os.environ | (env or {}))


def apply(row_changes=None, base_changes=None, check_changes=None):
    """apply_eprel.py on one CSV row, at most one base tire and at most one checkPricelist result."""
    with tempfile.TemporaryDirectory(prefix='enis-test-') as directory:
        work = Path(directory)
        (work / 'out').mkdir()
        row = dict.fromkeys(COLUMNS, '') | {
            'ean': '4000000000006', 'numkat1': 'new-ref', 'producent': 'Example',
            'rodzaj': 'O', 'bieznik': 'Model', 'rozmiar': '205/55R16', 'indeksy': '91V',
        } | (row_changes or {})
        base = [] if base_changes is None else [dict.fromkeys(DB_COLUMNS, '') | {
            'id': '1', 'pp': '1', 'ean': '4000000000013', 'ref': 'old-ref',
            'tread': 'Model', 'tire_size': '205/55R16', 'tire_li': '91', 'tire_si': 'V', 'vt': '1',
        } | base_changes]
        check = [] if check_changes is None else [dict.fromkeys(REPORT_COLUMNS, '') | {
            'ean': row['ean'], 'wynik': 'ROZJAZD',
        } | check_changes]
        write_table(work / 'producers.tsv', ['id', 'producer'], [{'id': '1', 'producer': 'Example'}], '\t')
        write_table(work / 'dictionary_kinds.tsv', ['kind', 'code'], [{'kind': 'reinforcement', 'code': 'XL'}], '\t')
        write_table(work / 'db_all.tsv', DB_COLUMNS, base, '\t')
        write_table(work / 'out/Enis - do EPREL.csv', COLUMNS, [row], '@')
        write_table(work / 'out/eprel.csv', REPORT_COLUMNS, check, ',')
        result = run(work, 'apply_eprel.py')
        if result.returncode:
            raise AssertionError(result.stderr)
        rows = []
        for path in (work / 'out/CSV').glob('*.csv'):
            with path.open(encoding='utf-8') as handle:
                rows.extend(csv.DictReader(handle, delimiter='@'))
        return rows


class EprelIdentity(unittest.TestCase):
    def test_wrong_size_does_not_supply_label(self):
        rows = apply({'eprel': '123'}, check_changes={
            'rozjazdy': 'szerokość: 205 → 225', 'eprel_etykieta': 'E/C/72 dB/B', 'eprel_oznaczenie': '225/55R16 91 V',
        })
        self.assertEqual(rows[0]['eprel'], '')
        self.assertEqual(rows[0]['opor'], '', 'a rejected registration supplied the label')

    def test_different_gtin_invalidates_registration(self):
        rows = apply({'eprel': '123'}, check_changes={
            'rozjazdy': 'EAN: 4000000000006 → 4000000000013', 'eprel_etykieta': 'E/C/72 dB/B', 'eprel_oznaczenie': '205/55R16 91 V',
        })
        self.assertEqual(rows[0]['eprel'], '', 'another GTIN kept its registration')
        self.assertEqual(rows[0]['opor'], '')

    def test_speed_mismatch_does_not_rewrite_identity(self):
        rows = apply({'eprel': '123'}, check_changes={
            'rozjazdy': 'indeks prędkości: V → H', 'eprel_etykieta': 'E/C/72 dB/B', 'eprel_oznaczenie': '205/55R16 91 H',
        })
        self.assertEqual(rows[0]['indeksy'], '91V', 'the registration rewrote the speed index')
        self.assertEqual(rows[0]['eprel'], '')

    def test_verified_label_is_filled(self):
        rows = apply({'eprel': '123'}, check_changes={
            'wynik': 'OK', 'eprel_etykieta': 'E/C/72 dB/B', 'eprel_oznaczenie': '205/55R16 91 V',
        })
        self.assertEqual(rows[0]['opor'], 'E')


class Duplicates(unittest.TestCase):
    def test_matching_single_index_is_dropped(self):
        self.assertEqual(apply(base_changes={}), [])

    def test_full_dual_load_index_matches_existing_tire(self):
        self.assertEqual(apply({'indeksy': '104/102R'}, {'tire_li': '104/102', 'tire_si': 'R'}), [])

    def test_distinct_second_load_index_is_not_collapsed(self):
        self.assertEqual(len(apply({'indeksy': '104/102R'}, {'tire_li': '104', 'tire_si': 'R'})), 1)

    def test_base_tire_without_ean_participates_in_deduplication(self):
        self.assertEqual(apply(base_changes={'ean': ''}), [])

    def test_distinct_eprel_keeps_both_variants(self):
        self.assertEqual(len(apply({'eprel': '123'}, {'eprel_id': '456'})), 1)


class EmptyReports(unittest.TestCase):
    """A run where nothing qualifies is a valid run: header-only reports, the previous output left alone."""

    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix='enis-test-'))
        (self.work / 'out/CSV').mkdir(parents=True)
        write_table(self.work / 'producers.tsv', ['id', 'producer'], [{'id': '1', 'producer': 'Example'}], '\t')
        write_table(self.work / 'db_all.tsv', DB_COLUMNS, [], '\t')
        write_table(self.work / 'treads.tsv', ['producer_id', 'id', 'tread', 'tread_code', 'n'], [], '\t')
        for name in ('out/final.csv', 'out/Enis - wszystkie.csv'):
            write_table(self.work / name, COLUMNS, [], '@')
        write_table(self.work / 'out/sim.tsv', ['ean', 'tire_id', 'old_name', 'new_name', 'old_tread', 'new_tread'], [], '\t')

    def tearDown(self):
        shutil.rmtree(self.work)

    def test_match_export_writes_header_only(self):
        (self.work / 'export').mkdir()
        result = run(self.work, 'match_export.py', {'EPREL_EXPORT': str(self.work / 'export')})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('dopasowanie', (self.work / 'out/eksport-eprel.csv').read_text(encoding='utf-8'))

    def test_new_treads_writes_header_only(self):
        result = run(self.work, 'new_treads.py')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((self.work / 'out/Enis - nowe bieżniki.csv').read_text(encoding='utf-8').startswith('producent;'))

    def test_publish_keeps_previous_output_when_nothing_to_publish(self):
        previous = self.work / 'Cenniki/Enis/CSV/Example.csv'
        previous.parent.mkdir(parents=True)
        previous.write_text('poprzedni wynik', encoding='utf-8')
        result = run(self.work, 'publish.py', {'CENNIKI': str(self.work / 'Cenniki')})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(previous.read_text(encoding='utf-8'), 'poprzedni wynik')


if __name__ == '__main__':
    unittest.main()
