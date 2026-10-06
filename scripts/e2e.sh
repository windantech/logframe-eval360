#!/usr/bin/env bash
# End-to-end test of the import path, no API key needed:
#   bash scripts/e2e.sh
# Starts PHP's built-in server with AI_MOCK=1, opens the demo with tests/e2e.js
# injected, and checks the real browser converters (Word, Excel, PDF, CSV), the
# proxy, the review step, acceptance and browser storage. Exit 1 on failure.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
CHROME="$(command -v google-chrome || command -v google-chrome-stable || command -v chromium || true)"
[ -z "$CHROME" ] && { echo "Chrome or Chromium is required."; exit 2; }
PORT=8799; T="_e2e_test.html"; TMPSTORE="$(mktemp -d)"
python3 - "$T" <<'PY'
import sys; s = open('logframe_eval360_demo.html').read(); t = open('tests/e2e.js').read()
i = s.rindex('</body>'); open(sys.argv[1], 'w').write(s[:i] + '<script>\n' + t + '\n</script>\n' + s[i:])   # the last </body>: the page also has one inside a JS string
PY
PHP_CLI_SERVER_WORKERS=4 AI_MOCK=1 AI_LIMIT_PER_IP_DAY=100 php -S 127.0.0.1:$PORT -t . >/dev/null 2>&1 & PID=$!
trap 'kill $PID 2>/dev/null; rm -f "$ROOT/$T"; rm -rf "$TMPSTORE"' EXIT
sleep 1
OUT="$(timeout 120 "$CHROME" --headless=new --disable-gpu --no-sandbox --user-data-dir="$TMPSTORE" --virtual-time-budget=60000 \
  --dump-dom "http://127.0.0.1:$PORT/$T#e2e" 2>/dev/null \
  | python3 -c "import sys,re,html; d=sys.stdin.read(); m=re.search(r'<pre id=\"e2e\">(.*?)</pre>', d, re.S); print(html.unescape(m.group(1)) if m else 'FAIL no e2e output')")"
echo "$OUT"
echo "$OUT" | grep -q "^FAIL" && exit 1 || exit 0
