"""HTTP creation checks: ONLY run on the explicitly attested disposable app fixture."""
import json
import os
import unittest
import urllib.error
import urllib.request

from test_attendance_phase1 import BASE, USERS, WebSession, db, _live_ready

USERS.setdefault('finance_dept', 'audit_fin')
PATH = '/admin/api_school_structure_create.php'


def post(session, payload):
    status, body = session._open(BASE + PATH, data=json.dumps(payload).encode(),
                                 headers={'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf})
    return status, json.loads(body.decode())


@unittest.skipUnless(os.environ.get('SSMS_STRUCTURE_FIXTURE') == '1'
                     and os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated structure fixture required')
class StructureCreateHttp(unittest.TestCase):
    def test_creation_and_security(self):
        edu, teacher, finance = WebSession('edu_dept'), WebSession('teacher'), WebSession('finance_dept')
        status, page = edu._open(BASE + '/admin/school_structure.php')
        self.assertEqual(status, 200)
        self.assertIn(b'not yet connected', page)
        self.assertIn(b'school_structure.js', page)
        for user in (teacher, finance):
            self.assertEqual(user._open(BASE + '/admin/school_structure.php')[0], 403)
        initial = int(db('SELECT COUNT(*) FROM school_academic_structure_audit'))
        payload = {'kind': 'level', 'name': 'Fixture HTTP ክፍል', 'sort_order': 3}
        for user in (teacher, finance):
            self.assertEqual(post(user, payload)[0], 403)
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl): return None
        req = urllib.request.Request(BASE + PATH, data=b'{}', headers={'Content-Type':'application/json'})
        try:
            urllib.request.build_opener(NoRedirect()).open(req, timeout=10)
            self.fail('Anonymous creation succeeded')
        except urllib.error.HTTPError as error:
            self.assertIn(error.code, (302, 303, 401, 403))
        self.assertEqual(edu._open(BASE + PATH)[0], 405)
        self.assertEqual(edu._open(BASE + PATH, data=b'{}', headers={'Content-Type':'application/json'})[0], 403)
        self.assertEqual(edu._open(BASE + PATH, data=b'{}', headers={'X-CSRF-Token':edu.csrf})[0], 415)
        for invalid in ({'kind':'level','name':'', 'sort_order':0},
                        {'kind':'level','name':'A','sort_order':'1'},
                        {'kind':'grade','name':'A','parent_id':'1','sort_order':0},
                        {'kind':'level','name':'A','sort_order':0,'actor_id':1}):
            self.assertEqual(post(edu, invalid)[0], 400)
        self.assertEqual(edu._open(BASE + PATH, data=b'{' + b' '*4096,
                         headers={'Content-Type':'application/json','X-CSRF-Token':edu.csrf})[0], 413)
        status, result = post(edu, payload)
        self.assertEqual(status, 201, result)
        level = result['id']
        status, result = post(edu, {'kind':'grade','name':'Grade fixture','parent_id':level,'sort_order':1})
        self.assertEqual(status, 201, result)
        grade = result['id']
        status, result = post(edu, {'kind':'section','name':'A','parent_id':grade,'sort_order':1})
        self.assertEqual(status, 201, result)
        section = result['id']
        self.assertEqual(post(edu, payload)[0], 409)
        self.assertEqual(int(db('SELECT COUNT(*) FROM school_academic_structure_audit')), initial + 3)
        self.assertEqual(db("SELECT CONCAT(entity_kind,':',actor_role) FROM school_academic_structure_audit WHERE entity_kind='section' AND entity_id=" + str(section)), 'section:edu_dept')
        # Failure injection affects only the attested disposable fixture.
        db('RENAME TABLE school_academic_structure_audit TO fixture_audit_hidden')
        try:
            code, result = post(edu, {'kind':'level','name':'Missing audit fixture','sort_order':0})
            self.assertEqual(code, 503)
            self.assertNotIn('school_academic_structure_audit', json.dumps(result))
            self.assertEqual(int(db("SELECT COUNT(*) FROM school_education_levels WHERE name='Missing audit fixture'")), 0)
        finally:
            db('RENAME TABLE fixture_audit_hidden TO school_academic_structure_audit')
        # A version-only revocation denies the cached session without changing role or password.
        db("UPDATE users SET authorization_version=authorization_version+1 WHERE username='audit_edu'")
        self.assertIn(post(edu, {'kind':'level','name':'Revoked version fixture','sort_order':0})[0], (401,403))
        self.assertEqual(int(db("SELECT COUNT(*) FROM school_education_levels WHERE name='Revoked version fixture'")), 0)
        self.assertEqual(int(db('SELECT COUNT(*) FROM school_academic_structure_audit')), initial + 3)
        edu = WebSession('edu_dept')  # fresh login captures the current version
        status, result = post(edu, {'kind':'level','name':'Fresh login fixture','sort_order':0})
        self.assertEqual(status, 201, result)
        fresh = result['id']
        db(f"DELETE FROM school_academic_structure_audit WHERE entity_kind='level' AND entity_id={fresh}")
        db(f'DELETE FROM school_education_levels WHERE id={fresh}')
        # Account is changed only in the disposable fixture; cached session must not authorize writes.
        try:
            db("UPDATE users SET is_active=0 WHERE username='audit_edu'")
            self.assertIn(post(edu, {'kind':'level','name':'Disabled fixture','sort_order':0})[0], (401,403))
        finally:
            db("UPDATE users SET is_active=1 WHERE username='audit_edu'")
        self.assertEqual(int(db("SELECT COUNT(*) FROM school_education_levels WHERE name='Disabled fixture'")), 0)
        self.assertEqual(int(db('SELECT COUNT(*) FROM school_academic_structure_audit')), initial + 3)
        # Cleanup synthetic hierarchy and audit rows; never run against an existing school database.
        db(f"DELETE FROM school_academic_structure_audit WHERE (entity_kind='section' AND entity_id={section}) OR (entity_kind='grade' AND entity_id={grade}) OR (entity_kind='level' AND entity_id={level})")
        db(f'DELETE FROM school_grade_sections WHERE id={section}')
        db(f'DELETE FROM school_grades WHERE id={grade}')
        db(f'DELETE FROM school_education_levels WHERE id={level}')
        # Legacy-login fallback: only this disposable fixture drops its version column.
        db('ALTER TABLE users DROP COLUMN authorization_version')
        try:
            legacy = WebSession('edu_dept')
            status, _ = post(legacy, {'kind':'level','name':'Legacy no version','sort_order':0})
            self.assertEqual(status, 403)
            self.assertEqual(int(db("SELECT COUNT(*) FROM school_education_levels WHERE name='Legacy no version'")), 0)
        finally:
            db('ALTER TABLE users ADD COLUMN authorization_version BIGINT UNSIGNED NOT NULL DEFAULT 1')
