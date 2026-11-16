"""Synthetic HTTP checks for ordinary grade routes and follow-up exception."""
import json
import os
import unittest
import urllib.error
import urllib.parse
import urllib.request

from test_attendance_phase1 import BASE, _live_ready, db


def request(path, token=None, payload=None, params=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    url = BASE + '/api/v1/index.php?_route=' + urllib.parse.quote(path, safe='/')
    if params:
        url += '&' + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, data=None if payload is None else json.dumps(payload).encode(), headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read().decode())


def token(username):
    status, data = request('auth/login', payload={'username': username, 'password': 'AuditTest#2026'})
    if status != 200:
        raise AssertionError('Synthetic API login failed: ' + str((status, data)))
    return data['data']['token']


@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated live server and seeded database required')
class GradeRoleGateLive(unittest.TestCase):
    def test_ordinary_grades_deny_finance_but_allow_education_and_teacher(self):
        finance, edu, teacher = token('audit_fin'), token('audit_edu'), token('audit_teach')
        assessment_id = int(db('SELECT MIN(id) FROM assessments'))
        before = db(f"SELECT COALESCE(GROUP_CONCAT(CONCAT(id,':',score) ORDER BY id SEPARATOR '|'),'') FROM academic_records WHERE assessment_id={assessment_id}")
        cases = [('grades/subjects', None, {'class_id': 1}),
                 ('grades/students', None, {'assessment_id': assessment_id}),
                 ('grades/save', {'assessment_id': assessment_id, 'grades': [{'member_id': 900001, 'score': 1}]}, None)]
        for route, payload, params in cases:
            status, _ = request(route, finance, payload, params)
            self.assertEqual(status, 403, route)
        after = db(f"SELECT COALESCE(GROUP_CONCAT(CONCAT(id,':',score) ORDER BY id SEPARATOR '|'),'') FROM academic_records WHERE assessment_id={assessment_id}")
        self.assertEqual(after, before, 'Denied write changed grades')
        # Missing parameters yield the ordinary validation error, not the role gate.
        for role_token in (edu, teacher):
            status, _ = request('grades/subjects', role_token)
            self.assertEqual(status, 400)
            status, body = request('grades/subjects', role_token, params={'class_id': 1})
            self.assertEqual(status, 200, body)
        # Follow-ups retain their distinct handler and its own policy.
        status, body = request('grades/followups', edu)
        self.assertEqual(status, 200, body)
        # This account exists only in the disposable fixture database. HR may
        # read follow-ups, but may not enter ordinary grade-management routes.
        db("INSERT INTO users(username,email,full_name,role,password_hash,is_active) "
           "SELECT 'fixture_hr','fixture_hr@test.local','Fixture HR','hr_dept',password_hash,1 "
           "FROM users WHERE username='audit_edu' ON DUPLICATE KEY UPDATE is_active=1")
        hr = token('fixture_hr')
        status, body = request('grades/followups', hr)
        self.assertEqual(status, 200, body)
        self.assertEqual(request('grades/subjects', hr, params={'class_id': 1})[0], 403)
