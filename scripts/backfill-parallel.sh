#!/usr/bin/env bash
#
# Parallel golden-profile backfill. Run this ON the hub host (app2) or a box on
# the hub's LAN — co-location is what makes it fast; running it remote from the
# hub is ~40x slower and won't hit the 1-2h target.
#
# Pipeline:  N load workers (id-partitioned, resumable)
#              -> S dedup shards + 1 serial mop-up pass
#              -> S sharded finalize
#
# Usage:  scripts/backfill-parallel.sh [WORKERS] [FINALIZE_SHARDS] [CHUNK] [MAX_ID]
#   WORKERS          parallel load workers        (default 20)
#   FINALIZE_SHARDS  parallel finalize+dedup shards(default = WORKERS)
#   CHUNK            rows per chunk / resume grain (default 1000)
#   MAX_ID           cap the source id range      (default = full table)
#
# SANITY / TIMING CHECK first (recommended before a full 20-wide run): cap the
# range so it finishes in minutes and prints the per-worker rate, e.g.
#   scripts/backfill-parallel.sh 3 3 1000 50000
# then extrapolate: full_rows / (rows_loaded / load_seconds) = est. load time.
#
# Safe to re-run: each load worker resumes from its own segment cursor; dedup
# and finalize are idempotent. Start clean first with:
#   php artisan migrate:fresh --force
#
set -euo pipefail

# Default 24: the shortest-duration worker count is bounded by the SHARED hub's
# connection pool (MySQL max_connections, minus what other apps already use),
# not by adding processes. Each load worker holds ~1 hub connection; past the
# knee, more workers only contend for the same hub CPU/link without shortening
# wall-time (measured: 16 workers already saturated a remote link at ~15 rows/s).
# 24 ≈ 2× a typical core count and fits comfortably under the hub's headroom.
# The script auto-caps this to the hub's real free connections at runtime.
WORKERS="${1:-24}"
FINALIZE_SHARDS="${2:-$WORKERS}"
CHUNK="${3:-1000}"
MAX_ID_OVERRIDE="${4:-}"

cd "$(dirname "$0")/.."

echo ">> reading source id range..."
read -r MIN MAX <<<"$(php artisan tinker --execute="\$q=DB::connection('streamline_local')->table('employees'); echo \$q->min('id').' '.\$q->max('id');" | tail -1)"
if [[ -n "$MAX_ID_OVERRIDE" ]]; then
  MAX="$MAX_ID_OVERRIDE"
  echo "   SANITY MODE — capping id range at $MAX"
fi
echo "   employees id range: $MIN .. $MAX"

# Auto-cap workers to the shortest-duration ceiling: whichever is smaller of
# the hub's free connection headroom (leaving 20 for other apps on the shared
# box) and 2x local CPU cores. Beyond this, wall-time doesn't improve.
echo ">> sizing workers to hub headroom..."
read -r MAXCONN USEDCONN <<<"$(php artisan tinker --execute="\$h=DB::connection('golden_profile'); echo \$h->select('SHOW VARIABLES LIKE \"max_connections\"')[0]->Value.' '.\$h->select('SHOW STATUS LIKE \"Threads_connected\"')[0]->Value;" | tail -1)"
CORES="$(nproc 2>/dev/null || echo 8)"
CONN_CAP=$(( MAXCONN - USEDCONN - 20 ))
(( CONN_CAP < 1 )) && CONN_CAP=1
CPU_CAP=$(( CORES * 2 ))
MAXW=$CONN_CAP; (( CPU_CAP < MAXW )) && MAXW=$CPU_CAP
echo "   hub max_connections=$MAXCONN in_use=$USEDCONN -> conn_cap=$CONN_CAP | cores=$CORES -> cpu_cap=$CPU_CAP | max_useful=$MAXW"
if (( WORKERS > MAXW )); then
  echo "   capping workers $WORKERS -> $MAXW (shortest duration; more would only add contention)"
  WORKERS=$MAXW
fi
(( FINALIZE_SHARDS > MAXW )) && FINALIZE_SHARDS=$MAXW

SPAN=$(( MAX - MIN + 1 ))
STRIDE=$(( (SPAN + WORKERS - 1) / WORKERS ))
echo ">> launching $WORKERS load workers (stride $STRIDE, chunk $CHUNK)..."

LOAD_START=$(date +%s)
pids=()
for (( i=0; i<WORKERS; i++ )); do
  FROM=$(( MIN + i*STRIDE ))
  TO=$(( FROM + STRIDE - 1 ))
  (( TO > MAX )) && TO=$MAX
  (( FROM > MAX )) && break
  php artisan gp:backfill --from-id="$FROM" --to-id="$TO" --chunk="$CHUNK" --segment="w$i" --no-finalize &
  pids+=("$!")
done

FAIL=0
for pid in "${pids[@]}"; do wait "$pid" || FAIL=1; done
(( FAIL )) && { echo "!! a load worker failed — re-run this script to resume"; exit 1; }
LOAD_SECS=$(( $(date +%s) - LOAD_START ))
LOADED=$(php artisan tinker --execute="echo DB::connection('golden_profile')->table('gp_source_link')->count();" | tail -1)
echo ">> load complete: $LOADED source links in ${LOAD_SECS}s ($(( LOADED / (LOAD_SECS>0?LOAD_SECS:1) )) rows/s)."

echo ">> dedup across $FINALIZE_SHARDS shards..."
dpids=()
for (( s=0; s<FINALIZE_SHARDS; s++ )); do
  php artisan gp:dedup --shard="$s" --shards="$FINALIZE_SHARDS" &
  dpids+=("$!")
done
for pid in "${dpids[@]}"; do wait "$pid" || FAIL=1; done
(( FAIL )) && { echo "!! a dedup shard failed — re-run: php artisan gp:dedup --shard=<i> --shards=$FINALIZE_SHARDS"; exit 1; }
echo ">> dedup mop-up (serial pass converges cross-shard transitive merges)..."
php artisan gp:dedup

echo ">> finalize across $FINALIZE_SHARDS shards..."
fpids=()
for (( s=0; s<FINALIZE_SHARDS; s++ )); do
  php artisan gp:backfill --finalize-only --shard="$s" --shards="$FINALIZE_SHARDS" &
  fpids+=("$!")
done
for pid in "${fpids[@]}"; do wait "$pid" || FAIL=1; done
(( FAIL )) && { echo "!! a finalize shard failed — re-run: php artisan gp:backfill --finalize-only --shard=<i> --shards=$FINALIZE_SHARDS"; exit 1; }

echo ">> ALL DONE."
