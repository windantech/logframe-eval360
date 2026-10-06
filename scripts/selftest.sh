#!/usr/bin/env bash
# Headless check of the demonstration, no browser window needed.
#
#   bash scripts/selftest.sh
#
# Opens logframe_eval360_demo.html#selftest in headless Chrome. The page renders
# every screen (and every tab) as each of the fourteen demo roles and runs the
# model checks, then lists anything that failed. Exit code 1 if
# any check or screen failed.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHROME="$(command -v google-chrome || command -v google-chrome-stable || command -v chromium || true)"
[ -z "$CHROME" ] && { echo "Chrome or Chromium is required."; exit 2; }
OUT="$(timeout 120 "$CHROME" --headless=new --disable-gpu --no-sandbox --virtual-time-budget=20000 \
  --dump-dom "file://$ROOT/logframe_eval360_demo.html#selftest" 2>/dev/null \
  | python3 -c "import sys,re,html; d=sys.stdin.read(); m=re.search(r'<pre id=\"selftest\">(.*?)</pre>', d, re.S); print(html.unescape(m.group(1)) if m else 'NO SELFTEST OUTPUT — the page failed to load (likely a script error)')")"
echo "$OUT"
echo "$OUT" | grep -qE "CHECKFAIL|^FAIL|NO SELFTEST" && exit 1 || exit 0
