"""Disposable-only multipart registration fault test; never targets a live installation."""
import json
import os
import unittest
import uuid
from pathlib import Path
from test_attendance_phase1 import BASE, WebSession, db, _live_ready, USERS

ROOT = Path(__file__).resolve().parents[2]

@unittest.skipUnless(os.environ.get('SSMS_REGISTRATION_FAULT_FIXTURE') == '1' and _live_ready(), 'isolated injected fixture required')
class RegistrationUploadOutcome(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        db("INSERT INTO users(username,email,full_name,role,password_hash,is_active) SELECT 'fixture_hr','fixture_hr@test.local','Fixture HR','hr_dept',password_hash,1 FROM users WHERE username='audit_edu' ON DUPLICATE KEY UPDATE is_active=1")
        USERS['hr_dept'] = 'fixture_hr'
        cls.session = WebSession('hr_dept')

    def submit(self, name, request_id, extra=None):
        fields = {'full_name_am': name, 'gender': 'male', 'registration_type': 'waiting',
                  'registration_request_id': request_id, 'csrf_token': self.session.csrf}
        fields.update(extra or {})
        boundary = 'fixture' + uuid.uuid4().hex
        body = bytearray()
        for k, v in fields.items():
            body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n').encode())
        body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="doc_school_records"; filename="test.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode())
        body.extend(b'%PDF-1.4\nfixture-document\n%%EOF\n')
        body.extend((f'\r\n--{boundary}--\r\n').encode())
        status, response = self.session._open(BASE + '/admin/hr_register_member.php', data=bytes(body), headers={'Content-Type': 'multipart/form-data; boundary='+boundary})
        return status, json.loads(response)

    def files(self):
        root = ROOT.parent / 'ssms_private' / 'members' / 'docs'
        return set(root.glob('*')) if root.exists() else set()

    def test_normal_and_ambiguous_commit_and_early_cleanup(self):
        before = self.files()
        unique = uuid.uuid4().hex[:12]
        status, result = self.submit('Normal '+unique, uuid.uuid4().hex)
        self.assertEqual((status, result.get('status')), (200, 'success'), result)
        normal_files = self.files() - before
        self.assertEqual(len(normal_files), 1)
        name = 'Ambiguous '+unique
        key = uuid.uuid4().hex
        status, result = self.submit(name, key, {'fixture_commit_response_loss': '1'})
        self.assertEqual(status, 503, result)
        self.assertTrue(result.get('outcome_unknown'), result)
        preserved = self.files() - before - normal_files
        self.assertEqual(len(preserved), 1)
        self.assertEqual(int(db("SELECT COUNT(*) FROM members WHERE full_name_am='"+name+"'")), 1)
        stored = db("SELECT doc_school_records_path FROM members WHERE full_name_am='"+name+"'").strip()
        self.assertEqual(next(iter(preserved)).name, stored.split('/')[-1])
        status, result = self.submit(name, key, {'fixture_commit_response_loss': '1'})
        self.assertEqual(status, 409, result)
        self.assertEqual(self.files() - before - normal_files, preserved)
        status, result = self.submit('Rollback '+unique, uuid.uuid4().hex, {'fixture_before_write_failure': '1'})
        self.assertEqual(status, 500, result)
        self.assertEqual(int(db("SELECT COUNT(*) FROM members WHERE full_name_am='Rollback "+unique+"'")), 0)
        self.assertEqual(self.files() - before, normal_files | preserved)
        rolled_back_name = 'Afterwrite '+unique
        status, result = self.submit(rolled_back_name, uuid.uuid4().hex, {'fixture_after_write_failure': '1'})
        self.assertEqual(status, 500, result)
        self.assertEqual(int(db("SELECT COUNT(*) FROM members WHERE full_name_am='"+rolled_back_name+"'")), 0)
        self.assertEqual(self.files() - before, normal_files | preserved)
        unknown_name = 'RollbackUnknown '+unique
        unknown_key = uuid.uuid4().hex
        fault = {'fixture_after_write_failure': '1', 'fixture_rollback_response_loss': '1'}
        status, result = self.submit(unknown_name, unknown_key, fault)
        self.assertEqual(status, 503, result)
        self.assertTrue(result.get('outcome_unknown'), result)
        self.assertEqual(int(db("SELECT COUNT(*) FROM members WHERE full_name_am='"+unknown_name+"'")), 0)
        uncertain_files = self.files() - before - normal_files - preserved
        self.assertEqual(len(uncertain_files), 1)
        status, result = self.submit(unknown_name, unknown_key, fault)
        self.assertEqual(status, 409, result)
        self.assertEqual(self.files() - before, normal_files | preserved | uncertain_files)
        status, result = self.submit('Promotion '+unique, uuid.uuid4().hex, {'upgrade_member_id': '123'})
        self.assertEqual(result.get('status'), 'error', result)
        self.assertIn('signed registration form', result.get('message',''))
        self.assertEqual(self.files() - before, normal_files | preserved | uncertain_files)
