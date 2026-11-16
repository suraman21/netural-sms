"""Real multipart upload against a temporary test-only PHP server, no app DB."""
import io
import os
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

try:
    import requests
    from PIL import Image, PngImagePlugin
except ImportError:
    requests = None

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which('php')
HAS_GD = bool(PHP and subprocess.run([PHP, '-r', "exit(extension_loaded('gd') ? 0 : 1);"], capture_output=True).returncode == 0)

@unittest.skipUnless(PHP and HAS_GD and requests, 'PHP GD, Pillow and requests required')
class SchoolPrivatePhotoUpload(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='sms-photo-fixture-')
        root = Path(cls.temp.name)
        (root/'web').mkdir(); (root/'private').mkdir()
        (root/'web'/'fixture.php').write_text(r'''<?php
        define('MEMBER_PRIVATE_STORAGE_PATH', getenv('FIXTURE_PRIVATE_ROOT'));
        require getenv('FIXTURE_MEMBER_FILE_SERVICE');
        use App\Services\MemberFileService as F;
        header('Content-Type: application/json');
        if (($_POST['action'] ?? '') === 'resolve') {
            $path = F::resolvePrivatePath($_POST['uri'] ?? '');
            echo json_encode(['exists'=>$path !== null]); exit;
        }
        if (($_POST['action'] ?? '') === 'discard') {
            F::discard($_POST['uri'] ?? ''); echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(F::storeRequestUpload('school_student_photo'));
        ''')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        env = dict(os.environ, FIXTURE_PRIVATE_ROOT=str(root/'private'),
                   FIXTURE_MEMBER_FILE_SERVICE=str(ROOT/'admin/backend/services/MemberFileService.php'))
        cls.process = subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(root/'web')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                requests.get(cls.base+'/fixture.php', timeout=.25); break
            except requests.RequestException:
                if cls.process.poll() is not None: raise RuntimeError('PHP fixture exited')
                time.sleep(.05)
        else:
            cls.tearDownClass(); raise RuntimeError('PHP fixture did not start')
        cls.root = root

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        try: cls.process.wait(timeout=5)
        except subprocess.TimeoutExpired: cls.process.kill(); cls.process.wait()
        cls.temp.cleanup()

    def post(self, data=None, upload=None):
        files = {'school_student_photo': upload} if upload else None
        response = requests.post(self.base+'/fixture.php', data=data or {}, files=files, timeout=10)
        self.assertEqual(response.status_code,200)
        return response.json()

    def test_private_photo_validation_reencode_and_discard(self):
        original = io.BytesIO(); info = PngImagePlugin.PngInfo(); info.add_text('sensitive', 'REMOVE-THIS-METADATA')
        Image.new('RGB',(8,8),'red').save(original, format='PNG', pnginfo=info)
        payload = original.getvalue()+b'APPENDED-PAYLOAD'
        result = self.post(upload=('photo.png',payload,'image/png'))
        self.assertIsNone(result['error'],result)
        uri = result['path']
        self.assertRegex(uri,r'^private://members/student_photos/[a-f0-9]{32}\.png$')
        target = self.root/'private'/'members'/'student_photos'/uri.rsplit('/',1)[1]
        self.assertTrue(target.is_file()); self.assertEqual(target.stat().st_mode & 0o777,0o600)
        saved=target.read_bytes(); self.assertNotIn(b'REMOVE-THIS-METADATA',saved); self.assertNotIn(b'APPENDED-PAYLOAD',saved)
        self.assertTrue(self.post({'action':'resolve','uri':uri})['exists'])
        self.assertFalse(self.post({'action':'resolve','uri':uri.replace('student_photos/', 'student_photos/../')})['exists'])
        self.assertFalse(self.post({'action':'resolve','uri':uri[:-4]+'.pdf'})['exists'])
        self.assertEqual(requests.get(self.base+'/student_photos/'+target.name,timeout=5).status_code,404)
        self.assertTrue(self.post({'action':'discard','uri':uri})['ok'])
        self.assertFalse(target.exists())
        self.assertIsNone(self.post()['path'])
        self.assertIsNotNone(self.post(upload=('bad.png',b'not an image','image/png'))['error'])
        self.assertIsNotNone(self.post(upload=('test.pdf',b'%PDF-1.4\n%%EOF','application/pdf'))['error'])
        wide = io.BytesIO(); Image.new('RGB',(8001,1)).save(wide,format='PNG')
        self.assertIn('dimensions',self.post(upload=('wide.png',wide.getvalue(),'image/png'))['error'])
        self.assertEqual(list((self.root/'private'/'members'/'student_photos').glob('*')),[])

    def test_unsafe_storage_locations_fail_closed(self):
        def upload_to(private_root):
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
            env = dict(os.environ, FIXTURE_PRIVATE_ROOT=str(private_root),
                       FIXTURE_MEMBER_FILE_SERVICE=str(ROOT/'admin/backend/services/MemberFileService.php'))
            server = subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(self.root/'web')],
                                      env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            url = f'http://127.0.0.1:{port}/fixture.php'
            try:
                for _ in range(100):
                    try: requests.get(url,timeout=.25); break
                    except requests.RequestException:
                        if server.poll() is not None: self.fail('PHP fixture exited')
                        time.sleep(.05)
                else: self.fail('PHP fixture did not start')
                png = io.BytesIO(); Image.new('RGB',(4,4),'blue').save(png,format='PNG')
                result = requests.post(url,files={'school_student_photo':('x.png',png.getvalue(),'image/png')},timeout=10).json()
                self.assertIsNone(result['path'],result)
                self.assertIn('not safely configured',result['error'])
            finally:
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired: server.kill(); server.wait()
        inside = self.root/'web'/'unsafe-private'
        upload_to(inside)
        insecure = self.root/'insecure'/'members'/'student_photos'
        insecure.mkdir(parents=True, mode=0o700); insecure.chmod(0o755)
        upload_to(self.root/'insecure')
        link = self.root/'linked'/'members'/'student_photos'
        link.parent.mkdir(parents=True)
        target = self.root/'outside-target'; target.mkdir(mode=0o700)
        link.symlink_to(target, target_is_directory=True)
        upload_to(self.root/'linked')
        self.assertEqual(list(target.iterdir()),[])
        self.assertEqual(list(insecure.iterdir()),[])

    def test_failed_file_chmod_does_not_leave_successful_upload(self):
        script = self.root/'web'/'chmod_failure.php'
        script.write_text(r'''<?php
        namespace App\Services { function chmod($path, $permissions) { return false; } }
        namespace {
            define('MEMBER_PRIVATE_STORAGE_PATH', getenv('FIXTURE_PRIVATE_ROOT'));
            require getenv('FIXTURE_MEMBER_FILE_SERVICE');
            header('Content-Type: application/json');
            echo json_encode(\App\Services\MemberFileService::storeRequestUpload('school_student_photo'));
        }
        ''')
        png = io.BytesIO(); Image.new('RGB',(4,4),'blue').save(png,format='PNG')
        response = requests.post(self.base+'/chmod_failure.php',files={'school_student_photo':('x.png',png.getvalue(),'image/png')},timeout=10)
        self.assertEqual(response.status_code,200)
        result = response.json()
        self.assertIsNone(result['path'])
        self.assertIn('permissions could not be verified',result['error'])
        photos = self.root/'private'/'members'/'student_photos'
        self.assertEqual(list(photos.glob('*')),[])

    def test_reencoding_unavailable_fails_closed(self):
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        env = dict(os.environ, FIXTURE_PRIVATE_ROOT=str(self.root/'private'),
                   FIXTURE_MEMBER_FILE_SERVICE=str(ROOT/'admin/backend/services/MemberFileService.php'))
        server = subprocess.Popen([PHP,'-n','-d','extension=fileinfo','-S',f'127.0.0.1:{port}','-t',str(self.root/'web')],
                                  env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        base = f'http://127.0.0.1:{port}/fixture.php'
        try:
            for _ in range(100):
                try:
                    requests.get(base, timeout=.25); break
                except requests.RequestException:
                    if server.poll() is not None: self.fail('No-GD PHP fixture exited')
                    time.sleep(.05)
            else: self.fail('No-GD PHP fixture did not start')
            png = io.BytesIO(); Image.new('RGB',(4,4),'blue').save(png,format='PNG')
            response = requests.post(base,files={'school_student_photo':('x.png',png.getvalue(),'image/png')},timeout=10)
            self.assertEqual(response.status_code,200)
            result = response.json()
            self.assertIsNone(result['path'])
            self.assertIn('processing is unavailable',result['error'])
        finally:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill(); server.wait()
