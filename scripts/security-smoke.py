#!/usr/bin/env python3
"""Small read-only production check. Never downloads private-file bodies.

No load test, credentials, TLS bypass, or refresh/cache mutation parameters.
"""
import argparse
import datetime
import json
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--api', default='https://xain.in.ua/api/')
    parser.add_argument('--frontend', default='https://xainse.github.io/krop-electro-schedule/')
    args = parser.parse_args()
    for url in (args.api, args.frontend):
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            parser.error('Use an HTTPS URL without credentials, query or fragment')
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    results = []
    def check(name, condition):
        results.append({'check': name, 'passed': bool(condition)})
    def request(url, method='GET', headers=None):
        req = urllib.request.Request(url, method=method, headers=headers or {})
        try:
            response = opener.open(req, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = b'' if method == 'HEAD' else response.read(524289)
            if len(body) > 524288:
                raise ValueError('Response exceeded smoke-check limit')
            return response.status, response.headers, body
    try:
        api = args.api.rstrip('/') + '/'
        endpoint = api + 'blackout.php'
        status, headers, body = request(endpoint + '?queue=1.1', headers={'Origin': 'https://untrusted.example'})
        check('api HTTP 200', status == 200)
        check('api JSON content type', 'application/json' in headers.get('Content-Type', ''))
        check('api nosniff', headers.get('X-Content-Type-Options') == 'nosniff')
        check('api CSP blocks framing', "frame-ancestors 'none'" in headers.get('Content-Security-Policy', ''))
        check('public CORS without credentials', headers.get('Access-Control-Allow-Origin') == '*' and headers.get('Access-Control-Allow-Credentials') != 'true')
        data = json.loads(body)
        if not isinstance(data, dict):
            raise ValueError('API returned a non-object JSON value')
        check('api object schema', isinstance(data, dict) and data.get('success') is True and isinstance(data.get('stale'), bool))
        if isinstance(data, dict) and (data.get('available') or data.get('not_announced')):
            today = datetime.datetime.now(ZoneInfo('Europe/Kyiv')).strftime('%d.%m.%Y')
            now = datetime.datetime.now(datetime.timezone.utc).timestamp()
            updated = data.get('updated')
            check('current data date and verification age', data.get('date') == today and data.get('stale') is False and isinstance(updated, (int, float)) and 0 <= now - updated < 600)
        else:
            check('data source currently available', False)
        for query in ('?queue=9.9', '?queue[]=1.1', '?all[]=1'):
            status, _, _ = request(endpoint + query)
            check('invalid input ' + query, status == 400)
        status, _, _ = request(endpoint + '?queue=1.1', method='POST')
        check('POST refused', status == 405)
        status, _, _ = request(endpoint, method='OPTIONS')
        check('OPTIONS available', status == 204)
        for path in ('config.php', 'parser.php', 'http.php', 'security.php', 'cache/blackout_cache.json', 'logs/', 'test-cors.php', '../.env', '../.git/config'):
            status, _, _ = request(urllib.parse.urljoin(api, path), method='HEAD')
            check('private path denied: ' + path, status in (403, 404))
        status, _, body = request(args.frontend)
        check('frontend available with CSP meta', status == 200 and b'http-equiv="Content-Security-Policy"' in body)
        for url in (args.api, args.frontend):
            plain = urllib.parse.urlsplit(url)._replace(scheme='http').geturl()
            status, headers, _ = request(plain, method='HEAD')
            destination = urllib.parse.urljoin(plain, headers.get('Location', ''))
            check('HTTP redirects to same HTTPS host: ' + urllib.parse.urlsplit(url).hostname,
                  status in (301, 302, 307, 308) and urllib.parse.urlsplit(destination).scheme == 'https' and urllib.parse.urlsplit(destination).hostname == urllib.parse.urlsplit(url).hostname)
    except (urllib.error.URLError, TimeoutError, OSError, ValueError) as error:
        # Error class only: proxy/transport diagnostics may contain private host details.
        results.append({'check': 'network / response validation', 'passed': False, 'error': type(error).__name__})
    print(json.dumps({'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'checks': results}, ensure_ascii=False, indent=2))
    return int(not results or any(not item['passed'] for item in results))

if __name__ == '__main__':
    sys.exit(main())
