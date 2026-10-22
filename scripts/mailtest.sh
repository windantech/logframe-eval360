#!/usr/bin/env bash
# Checks api/lib_mail.php against a fake SMTP server — no network, no real mailbox.
#
#   bash scripts/mailtest.sh
#
# It covers the two authentication mechanisms, that the message survives intact
# (dots, unicode, long lines), and that the client refuses to send credentials
# in the clear when STARTTLS is missing or the certificate does not verify.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
command -v php >/dev/null 2>&1 || { echo "php is required."; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "python3 is required."; exit 2; }
exec python3 -I "$ROOT/tests/smtp_test.py"
