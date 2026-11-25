import hashlib
import hmac
import importlib.util
import json
import sqlite3
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

spec = importlib.util.spec_from_file_location("sync_mailbox", Path(__file__).with_name("sync-mailbox.py"))
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)
REFERENCE = "crm-proposal-42-" + "a" * 64 + "@crm.invalid"
MESSAGE = (
    f"From: client@example.test\r\nSubject: Re: Booking\r\nIn-Reply-To: <{REFERENCE}>\r\n"
    "Authentication-Results: mx.example.test; dmarc=pass header.from=example.test\r\n"
    "\r\nPlease confirm.\r\n"
).encode()


class FakeMailbox:
    def __init__(self, messages, validity=1):
        self.messages = messages
        self.validity = validity
        self.downloads = []

    def response(self, field):
        value = self.validity if field == "UIDVALIDITY" else max(self.messages, default=0) + 1
        return field, [str(value).encode()]

    def uid(self, operation, *args):
        if operation == "SEARCH":
            return "OK", [" ".join(map(str, self.messages)).encode()]
        uid = int(args[0])
        raw = self.messages[uid]
        if args[1] == "(RFC822.SIZE)":
            return "OK", [f"{uid} (RFC822.SIZE {len(raw)})".encode()]
        self.assert_read_only(args[1])
        self.downloads.append(uid)
        return "OK", [(b"message", raw), b")"]

    @staticmethod
    def assert_read_only(fetch):
        if fetch != "(BODY.PEEK[])":
            raise AssertionError(fetch)


class MailboxTest(unittest.TestCase):
    def setUp(self):
        self.state = sqlite3.connect(":memory:")
        self.addCleanup(self.state.close)
        self.state.execute("CREATE TABLE mailbox (name TEXT PRIMARY KEY, validity INTEGER, uid INTEGER)")

    def test_safe_linking_and_automatic_messages(self):
        self.assertIsNone(adapter.parse_message(MESSAGE)["in_reply_to"])
        self.assertEqual(f"<{REFERENCE}>", adapter.parse_message(MESSAGE, "mx.example.test")["in_reply_to"])
        automated = b"Auto-Submitted: auto-replied\r\n" + MESSAGE
        self.assertIsNone(adapter.parse_message(automated, "mx.example.test")["in_reply_to"])
        spoofed = MESSAGE.replace(b"header.from=example.test", b"header.from=spoof.test")
        self.assertIsNone(adapter.parse_message(spoofed, "mx.example.test")["in_reply_to"])
        self.assertEqual("Please confirm.", adapter.parse_message(MESSAGE)["body"])

    def test_checkpoint_survives_failure_and_uid_reset(self):
        mail = FakeMailbox({1: MESSAGE, 2: MESSAGE + b"another message"})
        accepted = set()

        def deliver(raw, _data):
            if raw not in accepted:
                accepted.add(raw)
                if raw != MESSAGE:
                    raise RuntimeError("Connection lost after CRM commit")
                return True
            return False

        with self.assertRaises(RuntimeError):
            adapter.sync(mail, self.state, "shared", deliver, import_existing=True)
        self.assertEqual((1, 1), self.state.execute("SELECT validity, uid FROM mailbox").fetchone())
        self.assertEqual((0, 1, 0), adapter.sync(mail, self.state, "shared", deliver))
        self.assertEqual([1, 2, 2], mail.downloads)
        moved = FakeMailbox({7: MESSAGE}, validity=2)
        self.assertEqual((0, 1, 0), adapter.sync(moved, self.state, "shared", deliver))

    def test_default_starts_at_uidnext_and_huge_messages_are_not_downloaded(self):
        mail = FakeMailbox({1: MESSAGE})
        self.assertEqual((0, 0, 0), adapter.sync(mail, self.state, "shared", self.fail))
        mail.messages[2] = b"x" * (adapter.MAX_MESSAGE + 1)
        self.assertEqual((0, 0, 1), adapter.sync(mail, self.state, "shared", self.fail))
        self.assertEqual([], mail.downloads)

    def test_http_uses_the_crm_signature_and_preserves_event_key_on_retry(self):
        verified = []
        secret = "test-secret-" * 4

        class CrmHandler(BaseHTTPRequestHandler):
            def do_POST(self):
                body = self.rfile.read(int(self.headers["Content-Length"]))
                event = self.headers["X-CRM-Event"]
                at = self.headers["X-CRM-Timestamp"]
                signature = hmac.new(secret.encode(), f"{at}.{event}.".encode() + body, hashlib.sha256).hexdigest()
                verified.append((event, signature == self.headers["X-CRM-Signature"], json.loads(body)["sender"]))
                reply = b'{"reply_id": 1}'
                self.send_response(201 if len(verified) == 1 else 200)
                self.send_header("Content-Length", str(len(reply)))
                self.end_headers()
                self.wfile.write(reply)

            def log_message(self, *_args):
                pass

        with HTTPServer(("127.0.0.1", 0), CrmHandler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                endpoint = f"http://127.0.0.1:{server.server_port}/api/intake/replies"
                data = adapter.parse_message(MESSAGE)
                self.assertTrue(adapter.submit(endpoint, secret, MESSAGE, data))
                self.assertFalse(adapter.submit(endpoint, secret, MESSAGE, data))
            finally:
                server.shutdown()
                thread.join()
        self.assertEqual(verified[0], verified[1])
        self.assertEqual(("imap-" + hashlib.sha256(MESSAGE).hexdigest(), True, "client@example.test"), verified[0])


if __name__ == "__main__":
    unittest.main()
