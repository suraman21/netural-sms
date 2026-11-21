"""Live API test for danger-confirmation DC-4 (Finance + Groups).
Runs against a LOCAL test server only (default http://127.0.0.1:8080).
Never point this at production.

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc4_api.py [base_url]

Covers the server-side typed-phrase checks added in DC-4:
  finance.delete_transaction (DELETE TRANSACTION) — hard delete of a money record
  groups.delete_group (DELETE GROUP) — group + all its leaders and members,
  now in one transaction, plus the read-only impact_delete_group preview.
"""
import os, re, sys, time, subprocess
import requests

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
PASS = FAIL = 0


def ck(name, cond, info=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', name)
    else:
        FAIL += 1; print('FAIL', name, '::', str(info)[:300])


def sql(q):
    return subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '--default-character-set=utf8mb4', '-N', '-B', '-e', q],
                          capture_output=True, text=True).stdout.strip()


def login(user, page):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    h = s.get(BASE + page).text
    m = (re.search(r"CSRF(?:_TOKEN)?\s*=\s*['\"]([a-f0-9]{20,})['\"]", h) or re.search(r'name="csrf-token" content="([a-f0-9]{20,})"', h)
         or re.search(r'name="csrf_token" value="([a-f0-9]{20,})"', h))
    s.csrf = m.group(1) if m else ''
    return s


def post(s, url, **data):
    data.setdefault('csrf_token', s.csrf)
    r = s.post(BASE + url, data=data)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:200]}


def refused(code, d):
    return code == 409 and d.get('code') == 'confirm_phrase_required'


tag = str(int(time.time()))[-6:]

# ── finance.delete_transaction ──
fin = login('audit_fin', '/admin/dashboards/finance_department.php')
ck('finance login + csrf', bool(fin.csrf))
sql(f"INSERT INTO finance_transactions (type, amount, description, transaction_date, receipt_number) VALUES ('income', 123.45, 'DC4 test {tag}', CURDATE(), 'DC4-{tag}')")
tid = sql(f"SELECT id FROM finance_transactions WHERE receipt_number='DC4-{tag}'")
ck('throwaway transaction created', tid.isdigit(), tid)
code, d = post(fin, '/admin/api_finance.php', action='delete_transaction', id=tid)
ck('delete transaction without phrase -> 409', refused(code, d), (code, d))
code, d = post(fin, '/admin/api_finance.php', action='delete_transaction', id=tid, confirm_phrase='DELETE')
ck('delete transaction with wrong phrase -> 409', refused(code, d), (code, d))
ck('transaction still there after refusals', sql(f"SELECT COUNT(*) FROM finance_transactions WHERE id={tid}") == '1')
code, d = post(fin, '/admin/api_finance.php', action='delete_transaction', id=tid, confirm_phrase='delete transaction')
ck('delete transaction with phrase (case-insensitive) -> deleted',
   code == 200 and d.get('status') == 'success' and sql(f"SELECT COUNT(*) FROM finance_transactions WHERE id={tid}") == '0', (code, d))
code, d = post(fin, '/admin/api_finance.php', action='delete_transaction', id=tid, confirm_phrase='DELETE TRANSACTION', csrf_token='bad')
ck('bad CSRF is still refused before anything else', code in (400, 403, 419), (code, d))

# ── groups.delete_group ── REMOVED (N-4, ADR-0028 A4)
# The groups subsystem was deleted along with its tables, so the DC-4 contract
# it exercised (preview counts, refusal without phrase, atomic delete) has no
# subject. tests/security/test_danger_confirm.py pins its absence instead: if a
# groups API is ever reintroduced it must bring the preview + transaction back.
