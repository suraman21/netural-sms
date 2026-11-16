"""Authenticated read-only hierarchy API against an explicitly isolated fixture."""
import json
import os
import unittest
import urllib.error
import urllib.request

from test_attendance_phase1 import BASE, USERS, WebSession, db, _live_ready

USERS.setdefault('finance_dept', 'audit_fin')


@unittest.skipUnless(os.environ.get('SSMS_STRUCTURE_FIXTURE') == '1'
                     and os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated structure fixture required')
class SchoolStructureReadLive(unittest.TestCase):
    def test_roles_order_method_and_missing_schema_fail_closed(self):
        path = '/admin/api_school_structure.php'
        # The test account exists only in the disposable fixture database.
        db("INSERT INTO users(username,email,full_name,role,password_hash,is_active) "
           "SELECT 'audit_school','audit_school@test.local','Fixture School','school_admin',password_hash,1 "
           "FROM users WHERE username='audit_edu' ON DUPLICATE KEY UPDATE is_active=1")
        edu, school, superuser = WebSession('edu_dept'), WebSession('school_admin'), WebSession('super_admin')
        teacher, finance = WebSession('teacher'), WebSession('finance_dept')
        status, raw = edu._open(BASE + path)
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(raw.decode()), {'status': 'success', 'levels': [], 'grades': [], 'sections': []})
        for session in (teacher, finance):
            status, body = session._open(BASE + path)
            self.assertEqual(status, 403)
        # Unauthenticated central guard may redirect to the login page or deny.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl): return None
        try:
            urllib.request.build_opener(NoRedirect()).open(BASE + path, timeout=10)
            self.fail('Unauthenticated structure request succeeded')
        except urllib.error.HTTPError as err:
            self.assertIn(err.code, (302, 303, 401, 403))
        # Only isolated fixture data, never an existing school database.
        try:
            db("INSERT INTO school_education_levels(id,name,sort_order,is_active) VALUES(900001,'Fixture Level',2,1)")
            db("INSERT INTO school_grades(id,level_id,name,sort_order,is_active) VALUES(900001,900001,'Fixture Grade',3,1)")
            db("INSERT INTO school_grade_sections(id,grade_id,name,sort_order,is_active) VALUES(900001,900001,'Fixture B',2,0),(900002,900001,'Fixture A',1,1)")
            for session in (edu, school, superuser):
                status, raw = session._open(BASE + path)
                self.assertEqual(status, 200)
                out = json.loads(raw.decode())
                self.assertEqual(out['status'], 'success')
            self.assertEqual([v['name'] for v in out['sections'] if v['grade_id'] == 900001], ['Fixture A', 'Fixture B'])
            row = next(v for v in out['sections'] if v['id'] == 900001)
            self.assertEqual(row['is_active'], 0)
            self.assertEqual(row['grade_id'], 900001)
            status, _ = edu._open(BASE + path, data=b'')
            self.assertEqual(status, 405)
            # Restore the renamed table even if an assertion fails.
            db('RENAME TABLE school_grade_sections TO school_grade_sections_fixture_hidden')
            try:
                status, raw = edu._open(BASE + path)
                self.assertEqual(status, 503)
                out = json.loads(raw.decode())
                self.assertEqual(out['status'], 'error')
                self.assertNotIn('levels', out)
            finally:
                db('RENAME TABLE school_grade_sections_fixture_hidden TO school_grade_sections')
        finally:
            db('DELETE FROM school_grade_sections WHERE id IN (900001,900002)')
            db('DELETE FROM school_grades WHERE id=900001')
            db('DELETE FROM school_education_levels WHERE id=900001')
