#!/usr/bin/env bash
# Enis product base -> one import CSV per producer in $CENNIKI/Enis/CSV. See CLAUDE.md, „Baza produktowa Enis”.
#
#   tools/enis/run.sh EXPORT_DIR [XLSX]
#
# EXPORT_DIR: EPREL daily export, fresh — `php bin/fetchExport.php --dir=…` in motomar-data-fixer.
# Reads motomar_dev only; writes nothing to any database.
set -euo pipefail

TOOLS=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$TOOLS/../.." && pwd)
export EPREL_EXPORT=$(cd "$1" && pwd)
export CENNIKI=${CENNIKI:-$HOME/Development/MotoMar/Cenniki}
XLSX=${2:-$CENNIKI/Baza produktowa.xlsx}
WORK=${WORK:-$REPO/tmp/enis}
DB=${DB:-motomar_dev}
# checkPricelist lives on motomar-data-fixer's branch aknicram/eprel-pricelist-check, not on main
EPREL_PRICELIST=${EPREL_PRICELIST:-$HOME/Development/MotoMar/wt/eprel-pricelist}
PY="python3 -E"
PHP=${PHP:-php}

mkdir -p "$WORK/out"
cd "$WORK"
rm -rf out/CSV

sql() { mysql --default-character-set=utf8mb4 -B "$DB" -e "$1"; }
# `mysql -B` escapes \n but passes \r through, and Python splits lines on it: tread 6027 is `Surpax SP5 \r\n(duplikat)`
flat() { echo "REGEXP_REPLACE($1, '[\\r\\n\\t]+', ' ') $2"; }
sql "SELECT p.id, p.producer, COUNT(t.id) n FROM products_producers p LEFT JOIN tires t ON t.id_product_producer = p.id GROUP BY p.id" > producers.tsv
sql "SELECT t.id, t.id_product_producer pp, t.ean, t.ref, $(flat tt.tread tread), t.tire_size, t.tire_li, t.tire_si, t.other, t.id_vehicles_type vt,
            t.eprel_id, t.rolling_resistance rr, t.adhesion wg, t.noise nd, t.waves nc
     FROM tires t LEFT JOIN tires_treads tt ON tt.id = t.id_tires_tread" > db_all.tsv
sql "SELECT tt.producer_id, tt.id, $(flat tt.tread tread), tt.tread_code, COUNT(t.id) n FROM tires_treads tt LEFT JOIN tires t ON t.id_tires_tread = tt.id GROUP BY tt.id" > treads.tsv
sql "SELECT kind, code FROM tires_dictionary" > dictionary_kinds.tsv
$PY "$TOOLS/dump.py" "$XLSX"

$PY "$TOOLS/convert.py"
$PY "$TOOLS/match_export.py"
$PY "$TOOLS/promote.py"
(cd "$EPREL_PRICELIST" && $PHP -d memory_limit=6G bin/checkPricelist.php --concurrency=6 --out="$WORK/out/eprel.csv" "$WORK/out/Enis - do EPREL.csv")
$PY "$TOOLS/apply_eprel.py"

(head -1 "$(ls out/CSV/*.csv | head -1)"; for f in out/CSV/*.csv; do tail -n +2 "$f"; done) > out/final.csv
$PHP -d memory_limit=4G "$TOOLS/validate.php" out/final.csv
$PHP -d memory_limit=6G "$TOOLS/sim.php" out/sim.tsv out/final.csv
$PY "$TOOLS/new_treads.py"
$PY "$TOOLS/publish.py"
