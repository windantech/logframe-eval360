#!/usr/bin/env bash
#
# verify_deploy.sh — check a deployment of the Logframe demo, from the server.
#
#   cd /home/vantage/domains/eval360.tech/public_html/mel/logframe
#   bash scripts/verify_deploy.sh
#   bash scripts/verify_deploy.sh https://mel.eval360.tech/logframe/   # explicit URL
#
# It reads; it never writes, never restarts anything and never touches the key
# file. It prints the NAMES of settings that are present, never their values.
#
# Exit 0 if everything a visitor depends on is working, 1 if not.
set -uo pipefail

BASE="${1:-https://mel.eval360.tech/logframe/}"
BASE="${BASE%/}/"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PASS=0; FAIL=0; WARN=0
ok()   { echo "  PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
warn() { echo "  WARN  $1"; WARN=$((WARN+1)); }
head2(){ echo; echo "$1"; }

code() { curl -s -m 15 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null; }
body() { curl -s -m 15 "$1" 2>/dev/null; }

echo "=============================================="
echo " Logframe Eval360 — deployment check"
echo " Target: $BASE"
echo " Files:  $ROOT_DIR"
echo "=============================================="

# ── 1 · The code that is actually on disk ───────────────────────────────────
head2 "1 · Code"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git fetch origin main --quiet 2>/dev/null
    LOCAL="$(git rev-parse HEAD)"; REMOTE="$(git rev-parse origin/main 2>/dev/null || echo '')"
    if [ -z "$REMOTE" ]; then warn "could not reach GitHub to compare — checked the files only"
    elif [ "$LOCAL" = "$REMOTE" ]; then ok "up to date with origin/main ($(git rev-parse --short HEAD))"
    else bad "NOT up to date — run: bash scripts/deploy_pull.sh"; fi
    DIRTY="$(git status --porcelain --untracked-files=no | wc -l)"
    [ "$DIRTY" -eq 0 ] && ok "no local edits to tracked files" || warn "$DIRTY tracked file(s) edited on the server — deploy_pull.sh will discard them"
else
    warn "not a git checkout — cannot tell which build this is"
fi

# ── 2 · The key file, which a deploy must never touch ───────────────────────
head2 "2 · Settings"
ENVFILE=""
for f in "${LOGFRAME_ENV_FILE:-}" "$(cd "$ROOT_DIR/../../.." 2>/dev/null && pwd)/private/logframe.env" "$ROOT_DIR/.env"; do
    [ -n "$f" ] && [ -r "$f" ] && { ENVFILE="$f"; break; }
done
if [ -z "$ENVFILE" ]; then
    bad "no key file found — AI and sharing are both off (see docs/DEPLOY.md §3)"
else
    ok "key file present and readable: $ENVFILE"
    [ "$(stat -c '%a' "$ENVFILE" 2>/dev/null)" = "600" ] && ok "key file is chmod 600" || warn "key file is not chmod 600 — run: chmod 600 $ENVFILE"
    # Names only. A value is never printed by this script.
    for k in OPENAI_API_KEY APP_SALT SHARE_NOTIFY_EMAIL SHARE_NOTIFY_FROM SHARE_ADMIN_KEY SHARE_MEETING_URL; do
        if grep -qE "^[[:space:]]*$k[[:space:]]*=[[:space:]]*[^[:space:]]" "$ENVFILE" 2>/dev/null; then ok "$k is set"
        else
            case "$k" in
              OPENAI_API_KEY)    bad "$k is NOT set — the demo cannot build anything" ;;
              APP_SALT)          warn "$k is NOT set — a default is used; set any random string" ;;
              SHARE_NOTIFY_FROM) bad "$k is NOT set — demo links we email visitors will likely go to spam" ;;
              SHARE_ADMIN_KEY)   warn "$k is NOT set — api/pipeline.php stays a 404, so you cannot see who opened what" ;;
              *)                 warn "$k is NOT set — see docs/DEPLOY.md §3b" ;;
            esac
        fi
    done
fi

# ── 3 · Can the server write, and can it send mail ──────────────────────────
head2 "3 · Server"
if mkdir -p storage/share/demos 2>/dev/null && [ -w storage/share/demos ]; then ok "storage/share/demos is writable — demo links can be saved"
else bad "storage/share/demos is NOT writable — nobody can create a demo link"; fi
PHPBIN="$(command -v php || true)"
if [ -n "$PHPBIN" ]; then
    php -r 'exit(function_exists("mail") ? 0 : 1);' 2>/dev/null \
      && ok "PHP mail() is available — visitors can be sent their link" \
      || bad "PHP mail() is disabled — visitors cannot be emailed their link, and lose the demo if they close the tab"
    ok "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
else
    warn "no php on PATH — skipped the mail() and version checks"
fi

# ── 4 · What a visitor gets ─────────────────────────────────────────────────
head2 "4 · The site"
STATUS="$(body "${BASE}api/status.php")"
case "$STATUS" in
  *'"ok":true'*) ok "api/status.php answers" ;;
  "")            bad "api/status.php did not answer — is the URL right?" ;;
  *)             bad "api/status.php returned something unexpected" ;;
esac
case "$STATUS" in *'"ai":true'*) ok "AI is on" ;; *) bad "AI is OFF — check OPENAI_API_KEY" ;; esac
case "$STATUS" in
  *'"share"'*) case "$STATUS" in
                 *'"enabled":true'*) ok "demo links are on" ;;
                 *) bad "demo links are off — SHARE_DISABLE=1 in the key file?" ;;
               esac ;;
  *) bad "this is the OLD build — status.php has no \"share\" section. Run: bash scripts/deploy_pull.sh" ;;
esac
case "$STATUS" in
  *'"meeting_url":null'*|*'"meeting_url":""'*) warn "no SHARE_MEETING_URL — the demo offers email and phone instead of a booking page" ;;
  *'"meeting_url"'*) ok "discovery-meeting booking link is set" ;;
esac
LIMIT="$(printf '%s' "$STATUS" | sed -n 's/.*"per_visitor_day":\([0-9]*\).*/\1/p')"
if [ -n "$LIMIT" ]; then
    [ "$LIMIT" -ge 25 ] && ok "AI limit is $LIMIT requests per visitor per day" \
      || warn "AI limit is only $LIMIT per visitor per day — a three-programme set-up spends 4 before a single question; consider AI_LIMIT_PER_IP_DAY=40"
fi
ok "demo page: HTTP $(code "${BASE}logframe_eval360_demo.html")"

# ── 5 · What must never be reachable ────────────────────────────────────────
head2 "5 · Locked down"
LOCKED_FAIL=0
for p in .env api/lib.php api/lib_share.php api/lib_ai.php api/lib_web.php api/lib_org.php \
         storage/ storage/share/demos/ tests/ scripts/ .git/config CLAUDE.md; do
    C="$(code "${BASE}${p}")"
    case "$C" in
      403|404) ok "blocked ($C): $p" ;;
      000)     warn "no answer for $p" ;;
      *)       bad "REACHABLE ($C): $p"; LOCKED_FAIL=$((LOCKED_FAIL+1)) ;;
    esac
done
if [ "$LOCKED_FAIL" -gt 2 ]; then
    echo
    echo "  Nothing above is blocked, which means .htaccess is not being applied at all:"
    echo "    · PHP's built-in server never applies it — if that is what you are testing, ignore this section."
    echo "    · On Apache, the vhost needs AllowOverride All for this directory."
    echo "    · On LiteSpeed, check .htaccess is enabled for the domain."
    echo "  Until it is, api/lib_share.php and the key file are downloadable by anyone."
fi

# ── 6 · The pipeline page ───────────────────────────────────────────────────
head2 "6 · Pipeline page"
C="$(code "${BASE}api/pipeline.php?key=definitely-not-the-key")"
[ "$C" = "404" ] && ok "invisible without the key (404)" || bad "returned $C to a wrong key — expected 404"
if [ -n "$ENVFILE" ] && grep -qE '^[[:space:]]*SHARE_ADMIN_KEY[[:space:]]*=[[:space:]]*[^[:space:]]' "$ENVFILE" 2>/dev/null; then
    K="$(sed -n 's/^[[:space:]]*SHARE_ADMIN_KEY[[:space:]]*=[[:space:]]*//p' "$ENVFILE" | head -1 | tr -d '"'"'"'\r')"
    C="$(code "${BASE}api/pipeline.php?key=$(printf '%s' "$K" | sed 's/ /%20/g')")"
    [ "$C" = "200" ] && ok "opens with the key (200)" || bad "returned $C with the key — expected 200"
fi

echo
echo "=============================================="
echo " $PASS passed · $WARN warning(s) · $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
    echo " Something a visitor depends on is not working."
    echo "=============================================="
    exit 1
fi
[ "$WARN" -gt 0 ] && echo " Working. The warnings above are worth a look."
echo " Send yourself a demo and check the email arrives in the inbox."
echo "=============================================="
