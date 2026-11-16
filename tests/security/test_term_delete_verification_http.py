"""Destructive DDL and synthetic records: run ONLY in the disposable app fixture."""
import json
import os
import unittest
import urllib.parse
import uuid
from test_attendance_phase1 import BASE, WebSession, db, _live_ready

@unittest.skipUnless(os.environ.get('SSMS_TERM_GUARD_FIXTURE') == '1' and
                     os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated MariaDB fixture required')
class TermDeleteVerification(unittest.TestCase):
    def test_usage_unavailable_does_not_authorize_deletion(self):
        edu = WebSession('edu_dept')
        teacher = WebSession('teacher')
        year = int(db("SELECT id FROM academic_years WHERE status='active' LIMIT 1"))
        suffix = uuid.uuid4().hex[:10]
        name = 'Guard'+suffix
        db(f"INSERT INTO academic_terms (academic_year_id,term_name,term_number,is_current) VALUES ({year},'{name}',9,0)")
        term = int(db(f"SELECT id FROM academic_terms WHERE term_name='{name}'"))
        def preview():
            status, body = edu._open(BASE + f'/admin/api_education.php?action=impact_delete_term&term_id={term}')
            return status, json.loads(body)
        def delete(session, phrase='DELETE SEMESTER', extra=None):
            data = urllib.parse.urlencode(dict({'action':'delete_term','term_id':term,'confirm_phrase':phrase,'csrf_token':session.csrf}, **(extra or {}))).encode()
            status, body = session._open(BASE+'/admin/api_education.php',data=data)
            return status, json.loads(body)
        try:
            code, result = preview()
            self.assertEqual(code,200, result)
            self.assertFalse(result['blocked'])
            self.assertEqual(delete(teacher)[0],403)
            self.assertEqual(delete(edu,'')[0],409)
            code, result = delete(edu, extra={'fixture_status_failure':'1'})
            self.assertEqual(code,503,result)
            self.assertEqual(int(db(f'SELECT COUNT(*) FROM academic_terms WHERE id={term}')),1)
            db(f"UPDATE academic_years SET status='closed' WHERE id={year}")
            try:
                code, result = delete(edu)
                self.assertEqual(result['status'],'error',result)
                self.assertIn('closed',result['message'].lower())
                self.assertEqual(int(db(f'SELECT COUNT(*) FROM academic_terms WHERE id={term}')),1)
            finally:
                db(f"UPDATE academic_years SET status='active' WHERE id={year}")
            teacher_id = int(db("SELECT id FROM users WHERE username='audit_teach'"))
            class_id = int(db('SELECT id FROM classes LIMIT 1'))
            db(f"INSERT INTO grade_submissions (teacher_id,class_id,academic_year_id,term_id,submission_type,status) VALUES ({teacher_id},{class_id},{year},{term},'marklist','draft')")
            try:
                code, result = preview()
                self.assertTrue(result['blocked'], result)
                self.assertEqual(result['counts']['grade_submissions'], 1)
                code, result = delete(edu)
                self.assertEqual(result.get('code'),'term_in_use',result)
                self.assertEqual(int(db(f'SELECT COUNT(*) FROM academic_terms WHERE id={term}')),1)
            finally:
                db(f'DELETE FROM grade_submissions WHERE term_id={term}')
            db('RENAME TABLE grade_submissions TO fixture_guard_hidden')
            try:
                code, result = preview()
                self.assertEqual(code,503,result)
                self.assertEqual(result['status'],'error')
                self.assertNotIn('counts',result)
                code, result = delete(edu)
                self.assertEqual(code,503,result)
                self.assertEqual(result['status'],'error')
                self.assertEqual(int(db(f'SELECT COUNT(*) FROM academic_terms WHERE id={term}')),1)
            finally:
                db('RENAME TABLE fixture_guard_hidden TO grade_submissions')
            code, result = delete(edu)
            self.assertEqual((code,result.get('status')),(200,'success'),result)
            self.assertEqual(int(db(f'SELECT COUNT(*) FROM academic_terms WHERE id={term}')),0)
        finally:
            db(f'DELETE FROM academic_terms WHERE id={term}')
