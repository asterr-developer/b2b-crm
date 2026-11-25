"""Read a shared IMAP mailbox and submit text messages to the CRM."""

import email.policy
import hashlib
import hmac
import imaplib
import json
import os
import re
import sqlite3
import ssl
import sys
import time
import uuid
from email.parser import BytesParser
from pathlib import Path
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

MAX_MESSAGE = 2 * 1024 * 1024
BATCH = 25


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def parse_message(raw, authserv=None):
    message = BytesParser(policy=email.policy.default).parsebytes(raw)
    sender = getattr(message.get("From"), "addresses", ())
    if len(sender) != 1 or not sender[0].username or not sender[0].domain or len(message.get_all("From", [])) != 1:
        return None
    address = sender[0].addr_spec
    if len(address) > 255 or "\x00" in address:
        return None
    plain = message.get_body(preferencelist=("plain",))
    try:
        body = plain.get_content(errors="replace").replace("\x00", "").strip() if plain else ""
    except LookupError:
        body = ""
    subject = str(message.get("Subject") or "").replace("\x00", "").strip() or "(Без темы)"
    reference = str(message.get("In-Reply-To") or "").strip()
    automated = (
        str(message.get("Auto-Submitted", "no")).strip().lower() != "no"
        or message.get_content_type() == "multipart/report"
        or not body
    )
    results = [str(header) for header in message.get_all("Authentication-Results", [])]
    trusted = [result.partition(";")[2] for result in results if result.partition(";")[0].strip().lower() == authserv]
    aligned = len(trusted) == 1 and any(
        re.match(r"\s*dmarc\s*=\s*pass\b", clause, re.I)
        and re.search(r"\bheader\.from\s*=\s*" + re.escape(sender[0].domain) + r"(?=\s|$)", clause, re.I)
        for clause in trusted[0].split(";")
    )
    if automated or not aligned or not re.fullmatch(r"crm-proposal-\d{1,18}-[a-f0-9]{64}@crm\.invalid", reference.removeprefix("<").removesuffix(">")):
        reference = None
    return {
        "sender": address,
        "subject": subject[:255],
        "body": body[:10000] or "Письмо без текстовой части. Откройте оригинал в общем ящике.",
        "in_reply_to": reference,
    }


def signed_request(endpoint, secret, event, data):
    payload = json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    stamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), f"{stamp}.{event}.".encode() + payload, hashlib.sha256).hexdigest()
    return Request(endpoint, data=payload, headers={
        "Content-Type": "application/json", "Accept": "application/json",
        "X-CRM-Timestamp": stamp, "X-CRM-Event": event, "X-CRM-Signature": signature,
    })


def submit(endpoint, secret, raw, data):
    event = "imap-" + hashlib.sha256(raw).hexdigest()
    with build_opener(NoRedirect()).open(signed_request(endpoint, secret, event, data), timeout=15) as response:
        receipt = json.loads(response.read(4096))
        if response.status not in (200, 201) or not isinstance(receipt.get("reply_id"), int):
            raise RuntimeError("CRM did not acknowledge the message")
        return response.status == 201


def heartbeat(endpoint, secret, result=(0, 0, 0), error=None):
    request = signed_request(endpoint + "/heartbeat", secret, "sync-" + uuid.uuid4().hex, {
        "status": "failed" if error else "done", "imported": result[0], "duplicates": result[1],
        "skipped": result[2], "error": error,
    })
    try:
        with build_opener(NoRedirect()).open(request, timeout=10):
            pass
    except OSError:
        print("CRM did not receive the sync status.", file=sys.stderr)


def require_ok(response):
    status, data = response
    if status != "OK":
        raise RuntimeError("IMAP command failed")
    return data


def mailbox_number(mailbox, field):
    _, values = mailbox.response(field)
    if not values or not values[-1] or not values[-1].isdigit():
        raise RuntimeError(f"IMAP did not return {field}")
    return int(values[-1])


def sync(mailbox, state, key, deliver, import_existing=False, authserv=None):
    validity = mailbox_number(mailbox, "UIDVALIDITY")
    checkpoint = state.execute("SELECT validity, uid FROM mailbox WHERE name = ?", (key,)).fetchone()
    if checkpoint is None:
        uid = 0 if import_existing else mailbox_number(mailbox, "UIDNEXT") - 1
    elif checkpoint[0] == validity:
        uid = checkpoint[1]
    else:
        uid = 0

    def save(position):
        state.execute("""
            INSERT INTO mailbox VALUES (?, ?, ?)
            ON CONFLICT(name) DO UPDATE SET validity = excluded.validity,
                uid = CASE WHEN mailbox.validity = excluded.validity THEN MAX(mailbox.uid, excluded.uid) ELSE excluded.uid END
        """, (key, validity, position))
        state.commit()

    save(uid)
    ids = require_ok(mailbox.uid("SEARCH", None, "UID", f"{uid + 1}:*"))
    candidates = sorted({int(value) for value in ids[0].split() if int(value) > uid})[:BATCH]
    imported = duplicates = skipped = 0
    for current in candidates:
        size_response = require_ok(mailbox.uid("FETCH", str(current), "(RFC822.SIZE)"))
        size = next((re.search(rb"RFC822\.SIZE (\d+)", row) for row in size_response if isinstance(row, bytes) and b"RFC822.SIZE" in row), None)
        if not size:
            raise RuntimeError(f"IMAP did not return message size for UID {current}")
        if int(size[1]) > MAX_MESSAGE:
            print(f"Skipped oversized message UID {current}; original retained.", file=sys.stderr)
            skipped += 1
        else:
            rows = require_ok(mailbox.uid("FETCH", str(current), "(BODY.PEEK[])"))
            raw = next((item[1] for item in rows if isinstance(item, tuple)), None)
            if raw is None:
                raise RuntimeError(f"IMAP did not return message UID {current}")
            data = parse_message(raw, authserv)
            if data is None:
                print(f"Skipped unreadable sender UID {current}; original retained.", file=sys.stderr)
                skipped += 1
            elif deliver(raw, data):
                imported += 1
            else:
                duplicates += 1
        save(current)
    return imported, duplicates, skipped


def run():
    import_existing = sys.argv[1:] == ["--import-existing"]
    if sys.argv[1:] and not import_existing:
        raise ValueError("Usage: sync-mailbox.py [--import-existing]")
    host = os.environ["CRM_IMAP_HOST"]
    port = int(os.getenv("CRM_IMAP_PORT", "993"))
    user = os.environ["CRM_IMAP_USER"]
    folder = os.getenv("CRM_IMAP_FOLDER", "INBOX")
    authserv = os.getenv("CRM_IMAP_TRUSTED_AUTHSERV_ID", "").strip().lower() or None
    endpoint = os.environ["CRM_MAILBOX_ENDPOINT"]
    secret = os.environ["CRM_REPLY_WEBHOOK_SECRET"]
    url = urlsplit(endpoint)
    if len(secret) < 32 or url.path != "/api/intake/replies" or url.username or url.password or url.query or url.fragment or not (
        url.scheme == "https" and url.hostname
        or url.scheme == "http" and url.hostname in ("127.0.0.1", "localhost", "::1")
    ):
        raise ValueError("Expected a 32-character secret and a CRM HTTPS or loopback webhook URL")
    if not re.fullmatch(r"[A-Za-z0-9_./ -]{1,100}", folder):
        raise ValueError("Use an ASCII mailbox folder name")
    path = Path(os.environ["CRM_IMAP_STATE"])
    with sqlite3.connect(path) as state, imaplib.IMAP4_SSL(host, port, ssl_context=ssl.create_default_context(), timeout=20) as mailbox:
        state.execute("CREATE TABLE IF NOT EXISTS mailbox (name TEXT PRIMARY KEY, validity INTEGER NOT NULL, uid INTEGER NOT NULL)")
        try:
            mailbox.login(user, os.environ["CRM_IMAP_PASSWORD"])
        except imaplib.IMAP4.abort:
            heartbeat(endpoint, secret, error="connection")
            print("IMAP connection interrupted during sign-in.", file=sys.stderr)
            return 1
        except imaplib.IMAP4.error:
            heartbeat(endpoint, secret, error="login")
            print("Yandex rejected IMAP sign-in. Check that IMAP and app passwords are enabled for this account, and use a Mail app password.", file=sys.stderr)
            return 1
        try:
            require_ok(mailbox.select(f'"{folder}"', readonly=True))
        except imaplib.IMAP4.abort:
            heartbeat(endpoint, secret, error="connection")
            print("IMAP connection interrupted while opening the folder.", file=sys.stderr)
            return 1
        except (imaplib.IMAP4.error, RuntimeError):
            heartbeat(endpoint, secret, error="folder")
            print(f"IMAP sign-in succeeded, but folder {folder!r} could not be opened. Check that it exists at the mailbox root.", file=sys.stderr)
            return 1
        try:
            result = sync(mailbox, state, f"{host}:{port}/{user}/{folder}/{endpoint}",
                          lambda raw, data: submit(endpoint, secret, raw, data), import_existing, authserv)
        except (OSError, RuntimeError, imaplib.IMAP4.error):
            heartbeat(endpoint, secret, error="sync")
            raise
    heartbeat(endpoint, secret, result)
    print(f"Imported {result[0]}, already in CRM {result[1]}, skipped {result[2]}.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(run())
    except Exception as error:
        # Avoid exposing credentials, email content or provider response bodies in unattended logs.
        print(f"Mailbox sync failed ({type(error).__name__}). Retry after checking IMAP and CRM availability.", file=sys.stderr)
        sys.exit(1)
