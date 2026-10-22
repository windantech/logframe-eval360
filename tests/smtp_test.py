"""Checks api/lib_mail.php against a fake SMTP server: that it authenticates both ways,
sends the message intact, and — the part that matters — never sends the password in
the clear when TLS is unavailable or the certificate does not verify.
Run with: bash scripts/mailtest.sh
"""
import base64, os, subprocess, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_smtp import FakeSMTP

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SEND = os.path.join(REPO, 'tests', 'send_one.php')
PASSWORD = 'sup3r-s3cret-mailbox-pw'
BODY = "Hello Amina,\n\nYOUR LINK — keep this one\nhttps://x.example/?demo=abc&t=tok\n.\n..dot line\nCafé — ünicode ✓\n"
results = []
def ok(name, cond, info=''):
    results.append((cond, name, info))
    print(('  PASS  ' if cond else '  FAIL  ') + name + (' — ' + str(info) if info else ''))

def send(srv, secure='none', user='info@eval360.tech', port=None, host='127.0.0.1'):
    env = dict(os.environ, SMTP_HOST=host, SMTP_PORT=str(port if port else srv.port),
               SMTP_SECURE=secure, SMTP_FROM='info@eval360.tech', SMTP_FROM_NAME='Eval360',
               SHARE_CONTACT_EMAIL='eval360@vantageafricaleaders.com', SMTP_DEBUG='1',
               LOGFRAME_ENV_FILE='/nonexistent')
    if user: env.update(SMTP_USER=user, SMTP_PASS=PASSWORD)
    r = subprocess.run(['php', SEND, REPO, 'amina@kijani.example', 'Your Eval360 demo — Kijani', BODY, 'Amina Phiri'],
                       capture_output=True, text=True, env=env, timeout=40)
    return r.stdout.strip(), r.stderr

print('1 · AUTH PLAIN, no TLS')
s = FakeSMTP(auth='PLAIN LOGIN', starttls=False); out, err = send(s); time.sleep(.3)
ok('message accepted', out == 'SENT', out)
ok('authenticated with AUTH PLAIN', any(c.startswith('AUTH PLAIN') for c in s.dialogue if c.startswith('C: AUTH PLAIN')) or any('|info@eval360.tech|' in c for c in s.creds), s.creds)
ok('password reached the server intact', any(PASSWORD in c for c in s.creds))
hdr, _, b64 = (s.message or '').partition('\r\n\r\n')
decoded = base64.b64decode(b64).decode('utf-8') if b64 else ''
ok('body round-trips exactly, dots and unicode included', decoded == BODY, repr(decoded[-28:]))
ok('From is the authenticated mailbox', 'From: Eval360 <info@eval360.tech>' in hdr)
ok('Reply-To is the public contact address', 'Reply-To: eval360@vantageafricaleaders.com' in hdr)
ok('non-ASCII subject is RFC 2047 encoded', 'Subject: =?UTF-8?B?' in hdr, [l for l in hdr.split('\r\n') if l.startswith('Subject')])
ok('To carries the name', 'To: Amina Phiri <amina@kijani.example>' in hdr)
ok('has Date and Message-ID', 'Date: ' in hdr and 'Message-ID: <' in hdr)
s.close()

print('2 · AUTH LOGIN only')
s = FakeSMTP(auth='LOGIN', starttls=False); out, _ = send(s); time.sleep(.3)
ok('message accepted', out == 'SENT', out)
ok('fell back to AUTH LOGIN and sent both parts', s.creds == ['info@eval360.tech', PASSWORD], s.creds)
s.close()

print('3 · Wrong password')
s = FakeSMTP(auth='PLAIN LOGIN', starttls=False, accept_auth=False); out, err = send(s); time.sleep(.3)
ok('refuses to pretend it sent', out == 'NOTSENT', out)
ok('logged the reason without the password', 'authentication refused' in err and PASSWORD not in err, err.strip()[:90])
s.close()

print('4 · STARTTLS required but not offered')
s = FakeSMTP(auth='PLAIN LOGIN', starttls=False); out, err = send(s, secure='tls'); time.sleep(.3)
ok('gives up rather than downgrade', out == 'NOTSENT', out)
ok('password never left the process', PASSWORD not in ''.join(s.creds) and not s.creds, s.creds)
ok('says why', 'does not offer STARTTLS' in err, err.strip()[:90])
s.close()

print('5 · STARTTLS offered — handshake must be real')
s = FakeSMTP(auth='PLAIN LOGIN', starttls=True); out, err = send(s, secure='tls'); time.sleep(.5)
ok('fake TLS is rejected, nothing sent', out == 'NOTSENT', out)
ok('no credentials sent before the handshake', not s.creds, s.creds)
ok('STARTTLS was requested', any('STARTTLS' in d for d in s.dialogue))
s.close()

print('6 · Server down')
out, err = send(None, port=59999)
ok('fails cleanly', out == 'NOTSENT', out)
ok('logged a connect failure', 'SMTP connect failed' in err)

print('7 · No SMTP configured at all')
env = dict(os.environ, LOGFRAME_ENV_FILE='/nonexistent')
for k in ('SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_FROM','SMTP_SECURE','SMTP_PORT'): env.pop(k, None)
r = subprocess.run(['php','-r','define("LF_ROOT","%s");require "%s/api/lib_mail.php";var_dump(lf_mail_smtp_ready());' % (REPO, REPO)],
                   capture_output=True, text=True, env=env, timeout=20)
ok('falls back to mail() rather than erroring', 'bool(false)' in r.stdout, r.stdout.strip())

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} passed")
sys.exit(1 if bad else 0)
