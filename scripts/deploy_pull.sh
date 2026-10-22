#!/usr/bin/env bash
#
# deploy_pull.sh — pull the latest Logframe build from GitHub onto the live server.
#
# Run it from anywhere; the script finds the project root itself:
#   cd /home/vantage/domains/eval360.tech/public_html/mel/logframe
#   bash scripts/deploy_pull.sh
#
# What it does NOT touch:
#   - .env / ../../../private/logframe.env   (the OpenAI key — never in git)
#   - storage/        (uploads, logs, exports — gitignored)
#   - anything untracked; `git clean` is never run
#
# The key file is untracked, so `git reset --hard` cannot touch it.
#
set -euo pipefail

BRANCH="${1:-main}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "=============================================="
echo " Logframe Eval360 — deploy from GitHub (branch: $BRANCH)"
echo " Target: $ROOT_DIR"
echo "=============================================="

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    cat <<'MSG'
ERROR: this folder is not a git repository.

First-time setup on the server:
  cd /home/vantage/domains/eval360.tech/public_html/mel
  git clone https://github.com/windantech/logframe-eval360.git logframe
  cd logframe
  bash scripts/deploy_pull.sh
MSG
    exit 1
fi

echo "[git] fetching origin/$BRANCH …"
git fetch origin "$BRANCH"

BEFORE="$(git rev-parse --short HEAD)"
git reset --hard "origin/$BRANCH"
AFTER="$(git rev-parse --short HEAD)"

if [ "$BEFORE" = "$AFTER" ]; then
    echo "[git] already up to date at $AFTER"
else
    echo "[git] $BEFORE -> $AFTER"
    git --no-pager log --oneline "$BEFORE..$AFTER" | sed 's/^/        /'
fi

find . -path ./.git -prune -o -type f \( -name '*.html' -o -name '*.php' \) -exec chmod 644 {} \; 2>/dev/null || true
mkdir -p storage && chmod 775 storage 2>/dev/null || true
chmod +x scripts/*.sh 2>/dev/null || true

echo
echo
echo "Key file: $( [ -f ../../../private/logframe.env ] && echo 'private/logframe.env found' || ([ -f .env ] && echo '.env in project root' || echo 'NOT FOUND — AI import is off until you add it (see docs/DEPLOY.md)') )"
echo "Done. https://mel.eval360.tech/logframe/"
