"""A fake SMTP server, just enough of ESMTP to test a client against."""
import base64, socket, threading

class FakeSMTP:
    def __init__(self, auth='PLAIN LOGIN', starttls=True, accept_auth=True, port=0):
        self.caps_auth, self.starttls, self.accept_auth = auth, starttls, accept_auth
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(('127.0.0.1', port)); self.sock.listen(1)
        self.port = self.sock.getsockname()[1]
        self.dialogue, self.message, self.creds = [], None, []
        self.t = threading.Thread(target=self._serve, daemon=True); self.t.start()

    def _serve(self):
        try:
            conn, _ = self.sock.accept()
        except OSError:
            return
        f = conn.makefile('rwb', buffering=0)
        def send(s):
            self.dialogue.append('S: ' + s); f.write((s + '\r\n').encode())
        send('220 fake.eval360.test ESMTP')
        in_data, body, expect = False, [], None
        while True:
            line = f.readline()
            if not line: break
            line = line.decode('utf-8', 'replace').rstrip('\r\n')
            if in_data:
                if line == '.':
                    in_data = False; self.message = '\r\n'.join(body); send('250 2.0.0 Ok: queued'); continue
                body.append(line[1:] if line.startswith('..') else line); continue
            self.dialogue.append('C: ' + line)
            up = line.upper()
            if expect:                                   # a bare base64 line during AUTH LOGIN
                self.creds.append(base64.b64decode(line).decode('utf-8', 'replace'))
                if expect == 'user': expect = 'pass'; send('334 UGFzc3dvcmQ6')
                else:
                    expect = None
                    send('235 2.7.0 Authentication successful' if self.accept_auth else '535 5.7.8 Authentication credentials invalid')
                continue
            if up.startswith('EHLO'):
                send('250-fake.eval360.test')
                if self.starttls: send('250-STARTTLS')
                if self.caps_auth: send(f'250-AUTH {self.caps_auth}')
                send('250 SIZE 35882577')
            elif up.startswith('STARTTLS'):
                send('220 2.0.0 Ready to start TLS')      # the client's handshake then fails; that is the point
            elif up.startswith('AUTH PLAIN'):
                self.creds.append(base64.b64decode(line.split(' ', 2)[2]).decode('utf-8', 'replace').replace('\0', '|'))
                send('235 2.7.0 Authentication successful' if self.accept_auth else '535 5.7.8 Authentication credentials invalid')
            elif up == 'AUTH LOGIN':
                expect = 'user'; send('334 VXNlcm5hbWU6')
            elif up.startswith('MAIL FROM'): send('250 2.1.0 Ok')
            elif up.startswith('RCPT TO'):  send('250 2.1.5 Ok')
            elif up == 'DATA':              in_data = True; send('354 End data with <CR><LF>.<CR><LF>')
            elif up == 'QUIT':              send('221 2.0.0 Bye'); break
            else:                           send('250 2.0.0 Ok')
        try: conn.close()
        except OSError: pass

    def close(self):
        try: self.sock.close()
        except OSError: pass
