import unittest, tempfile
from pathlib import Path
from contextlib import redirect_stdout
from io import StringIO
from build_runtime import build, FILES

class RuntimeSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.src = self.root / 'source'
        for name in FILES:
            p = self.src / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b'synthetic allowlisted content')
        self.output = self.root / 'output'
    def run_build(self, sha='a'*40):
        with redirect_stdout(StringIO()): build(self.src, self.output, sha)
    def test_secrets_dependencies_and_extra_files_never_copied(self):
        forbidden = ['config/.env.php','config/db_connection.php','.env',
                     'Public/vendor/autoload.php','Public/TCPDF/tcpdf.php',
                     'Public/Uploads/user.xlsx','backup.sql','.ftp-deploy-sync-state.json',
                     'Public/deployment_test.php','.github/workflows/runtime.yml']
        for name in forbidden:
            p = self.src / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b'excluded synthetic sentinel')
        self.run_build()
        for name in forbidden: self.assertFalse((self.output / name).exists())
    def test_symlink_file_rejected(self):
        p = self.src / 'config/auth.php'
        p.unlink()
        target = self.root / 'private'
        target.write_bytes(b'synthetic sentinel')
        p.symlink_to(target)
        with self.assertRaises(ValueError): self.run_build()
    def test_source_nested_destination_rejected(self):
        self.output = self.src / 'artifact'
        with self.assertRaises(ValueError): self.run_build()
    def test_nonempty_destination_preserved_and_rejected(self):
        self.output.mkdir()
        sentinel = self.output / 'user.txt'
        sentinel.write_text('keep')
        with self.assertRaises(ValueError): self.run_build()
        self.assertEqual(sentinel.read_text(), 'keep')
    def test_invalid_source_sha_rejected(self):
        with self.assertRaises(ValueError): self.run_build('main')

if __name__ == '__main__': unittest.main()
