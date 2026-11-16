"""Normal enrollment round-trip only on the explicitly disposable app fixture."""
import json
import os
import unittest
import urllib.parse
from pathlib import Path
from test_attendance_phase1 import BASE, WebSession, db, _live_ready

ROOT = Path(__file__).resolve().parents[2]


class EnrollmentOutcomePins(unittest.TestCase):
    def test_unknown_outcome_propagated_to_masking_callers(self):
        service = (ROOT/'admin/backend/services/EnrollmentService.php').read_text()
        edu = (ROOT/'admin/api_education.php').read_text()
        registration = (ROOT/'admin/hr_register_member.php').read_text()
        mobile = (ROOT/'api/v1/routes/enrollment.php').read_text()
        seed = (ROOT/'admin/backend/services/TestMemberSeed.php').read_text()
        self.assertIn("!empty($enr['outcome_unknown'])", seed)
        self.assertIn("'outcome_unknown'=>true", service)
        self.assertIn("if ($commitAttempted || $rollbackAttempted)", service)
        bulk = edu.split("case 'bulk_enroll':",1)[1].split("case 'transfer_student':",1)[0]
        self.assertIn("!empty($res['outcome_unknown'])", bulk)
        self.assertIn("'outcome_unknown'=>true", bulk)
        self.assertIn("!empty($enr['outcome_unknown'])", registration)
        self.assertIn("!empty($result['outcome_unknown']) ? 503 : 400", mobile)


@unittest.skipUnless(os.environ.get('SSMS_STRUCTURE_FIXTURE') == '1' and
                     os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated fixture required')
class EnrollmentRoundtrip(unittest.TestCase):
    def test_single_and_bulk_enrollment(self):
        edu = WebSession('edu_dept')
        db("INSERT INTO classes (id,class_name,class_name_en,class_code,level_order,is_active) VALUES (910001,'Enrollment Fixture','Enrollment Fixture','ENRFIX',91,1)")
        for mid in (910001, 910002):
            db(f"INSERT INTO members (id,member_code,full_name_am,student_name,father_name,gender,status,member_type,age_group,created_at) VALUES ({mid},'ENR{mid}','Fixture','Fixture','Test','male','active','regular','7_13',NOW())")
        def call(payload):
            payload['csrf_token'] = edu.csrf
            status, body = edu._open(BASE+'/admin/api_education.php',data=urllib.parse.urlencode(payload, doseq=True).encode())
            return status, json.loads(body.decode())
        try:
            status, result = call({'action':'enroll','member_id':910001,'class_id':910001})
            self.assertEqual(status,200); self.assertEqual(result['status'],'success',result)
            status, result = call({'action':'enroll','member_id':910001,'class_id':910001})
            self.assertEqual(status,200); self.assertEqual(result['status'],'success',result)
            status, result = call({'action':'bulk_enroll','class_id':910001,'member_ids':json.dumps([910002])})
            self.assertEqual(status,200); self.assertEqual(result['status'],'success',result)
            self.assertEqual(int(db('SELECT COUNT(*) FROM class_enrollments WHERE member_id IN (910001,910002) AND class_id=910001 AND status=\'active\'')),2)
        finally:
            db('DELETE FROM class_enrollments WHERE member_id IN (910001,910002)')
            db('DELETE FROM members WHERE id IN (910001,910002)')
            db('DELETE FROM classes WHERE id=910001')
