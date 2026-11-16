#!/usr/bin/env python3
import argparse
import base64
import ipaddress
import os
from pathlib import Path
import re
import secrets
from urllib.parse import urlsplit


def public_host(host):
    try:
        return ipaddress.ip_address(host).is_global
    except ValueError:
        reserved = ('localhost', 'test', 'invalid', 'example', 'example.com', 'example.org', 'example.net', 'local')
        return len(host) <= 253 and '.' in host and all(re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?', part) for part in host.split('.')) and not any(host == suffix or host.endswith('.' + suffix) for suffix in reserved)


def main():
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description='Create a private fresh environment file without printing or overwriting secrets.')
    parser.add_argument('--url', required=True)
    parser.add_argument('--output', type=Path, default=root / '.env')
    parser.add_argument('--project', default='kardorost')
    parser.add_argument('--image-tag', default='local')
    parser.add_argument('--revision', default='unknown')
    parser.add_argument('--web-port', type=int)
    parser.add_argument('--local', action='store_true', help='Allow loopback HTTP for isolated local acceptance only.')
    args = parser.parse_args()
    try:
        url = urlsplit(args.url)
        host = (url.hostname or '').lower()
        port = url.port
        loopback = host == 'localhost'
        try:
            loopback = loopback or ipaddress.ip_address(host).is_loopback
        except ValueError:
            pass
        allowed = args.local and loopback and url.scheme in ('http', 'https') or url.scheme == 'https' and public_host(host)
        if not allowed or url.username is not None or url.password is not None or url.query or url.fragment or url.path not in ('', '/'):
            raise ValueError
    except ValueError:
        parser.error('Use a real public HTTPS root URL, or --local with a loopback URL. Credentials, queries and fragments are not allowed.')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,49}', args.project):
        parser.error('Project name must use lowercase letters, digits, underscore or hyphen.')
    if not re.fullmatch(r'[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}', args.image_tag) or not re.fullmatch(r'[a-zA-Z0-9_.-]{1,100}', args.revision):
        parser.error('Image tag or revision is not valid.')
    web_port = args.web_port if args.web_port is not None else (port if args.local and port else 8080)
    if not 1024 <= web_port <= 65535:
        parser.error('Use an unprivileged local web port from 1024 through 65535.')
    settings = {
        'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': args.url.rstrip('/'),
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_CONNECTION': 'pgsql', 'DB_HOST': 'postgres', 'DB_PORT': '5432',
        'DB_DATABASE': 'kardorost', 'DB_USERNAME': 'kardorost',
        'DB_PASSWORD': secrets.token_hex(32), 'DB_ADMIN_PASSWORD': secrets.token_hex(32),
        'COMPOSE_PROJECT_NAME': args.project, 'IMAGE_TAG': args.image_tag,
        'APP_REVISION': args.revision, 'WEB_PORT': str(web_port),
        'SESSION_SECURE_COOKIE': 'true' if url.scheme == 'https' else 'false',
        'SMS_DRIVER': 'disabled', 'ZIBAL_ENABLED': 'false', 'ZIBAL_MERCHANT': '',
        'MELIPAYAMAK_USERNAME': '', 'MELIPAYAMAK_API_KEY': '', 'MELIPAYAMAK_OTP_BODY_ID': '',
        'MELIPAYAMAK_INVITATION_BODY_ID': '', 'MELIPAYAMAK_REMINDER_BODY_ID': '',
        'MELIPAYAMAK_INVITATIONS_ENABLED': 'false', 'MELIPAYAMAK_REMINDERS_ENABLED': 'false',
        'MELIPAYAMAK_DELIVERY_ENABLED': 'false', 'MANUAL_PAYMENT_DESTINATION': '',
        'MANUAL_PAYMENT_HOLDER': '', 'AWS_ACCESS_KEY_ID': '', 'AWS_SECRET_ACCESS_KEY': '',
    }
    lines = []
    for line in (root / '.env.example').read_text(encoding='utf-8').splitlines():
        key, separator, _ = line.partition('=')
        if separator and key in settings:
            line = key + '=' + settings.pop(key)
        lines.append(line)
    lines.extend(key + '=' + value for key, value in settings.items())
    try:
        descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        parser.exit(1, 'Environment file already exists; preserved unchanged.\n')
    except OSError:
        parser.exit(1, 'Environment file could not be created. Check the output directory.\n')
    with os.fdopen(descriptor, 'w', encoding='utf-8') as handle:
        os.fchmod(handle.fileno(), 0o600)
        handle.write('\n'.join(lines) + '\n')
        handle.flush()
        os.fsync(handle.fileno())
    print('Private environment file created. Live SMS and gateway remain disabled.')


if __name__ == '__main__':
    main()
