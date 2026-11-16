"""Do not render canonical storage dates in the term containment error."""
import json
import os
from pathlib import Path
import unittest
import urllib.parse

from test_attendance_phase1 import BASE, WebSession, db, _live_ready

ROOT = Path(__file__).resolve().parents[2]


class TermCalendarMessageStatic(unittest.TestCase):
    def test_containment_error_does_not_interpolate_database_dates(self):
        src = (ROOT / 'admin/api_education.php').read_text(encoding='utf-8')
        segment = src.split("case 'save_term':", 1)[1].split("case 'set_current_term':", 1)[0]
        self.assertIn('Semester dates must fall within the year.', segment)
        self.assertNotIn(".$yrow['start_date'].", segment)
        self.assertNotIn(".$yrow['end_date'].", segment)


@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'explicit isolated live server and seeded database required')
class TermCalendarMessageLive(unittest.TestCase):
    def test_outside_term_rejects_without_exposing_storage_dates(self):
        row = db("SELECT CONCAT(id,'|',start_date,'|',end_date) FROM academic_years WHERE status='active' ORDER BY id DESC LIMIT 1")
        year_id, start, end = row.split('|')
        session = WebSession('edu_dept')
        body = urllib.parse.urlencode({
            'csrf_token': session.csrf, 'academic_year_id': year_id,
            'term_name': 'Synthetic outside term', 'term_number': 1,
            'start_date': '1900-01-01', 'end_date': '1900-01-02',
        }).encode()
        status, raw = session._open(BASE + '/admin/api_education.php?action=save_term', data=body,
                                    headers={'Content-Type': 'application/x-www-form-urlencoded'})
        data = json.loads(raw.decode('utf-8'))
        self.assertEqual(status, 200)
        self.assertEqual(data['status'], 'error')
        self.assertIn('within the year', data['message'])
        self.assertNotIn(start, data['message'])
        self.assertNotIn(end, data['message'])
