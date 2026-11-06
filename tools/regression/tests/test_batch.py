import ctypes
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfoNotFoundError


TESTS = Path(__file__).resolve().parent
sys.path.insert(0, str(TESTS.parent))
import batch


TEMPORARY = tempfile.TemporaryDirectory(prefix='myucto-batch-synthetic-')
BASE = Path(TEMPORARY.name)
CODE_ROOT = BASE / 'code-root'
(CODE_ROOT / 'api/vendor').mkdir(parents=True)
(CODE_ROOT / 'api/vendor/autoload.php').write_text('<?php', encoding='utf-8')
(CODE_ROOT / 'synthetic-source.php').write_text('<?php return 500;', encoding='utf-8')


class Fixture(batch.Batch):
    count = 41
    failure = -1
    active = peak = launches = 0
    mutex = threading.Lock()

    def invoke(self, action, directory, case=None, cancel_event=None):
        self.check()
        if action == 'compare':
            raise AssertionError('compare uses the fixed bridge directly')
        batch.create(
            directory / 'input.json',
            {'action': action, 'case': case, 'count': self.count, 'failure': self.failure},
        )
        with self.mutex:
            self.active += 1
            self.peak = max(self.peak, self.active)
            self.launches += 1
        try:
            result = subprocess.run(
                [batch.resolve_php(), str(TESTS / 'synthetic.php'), str(directory / 'input.json')],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=10,
                check=False,
            )
            if result.returncode or result.stdout or result.stderr:
                batch.fail('fixture_process_failed')
        finally:
            with self.mutex:
                self.active -= 1
            (directory / 'input.json').unlink(missing_ok=True)
        self.check()
        return directory / 'result.json'


def config():
    return {
        'code_root': str(CODE_ROOT),
        'database': 'synthetic_12345678_test',
        'as_of': '2026-10-17',
        'execution_date': batch.today(),
        'dataset_id': 'a' * 64,
        'source_hashes': {'synthetic-source.php': batch.digest(CODE_ROOT / 'synthetic-source.php')},
    }


class SchedulerTests(unittest.TestCase):
    def make(self, name, count=41, failure=-1):
        fixture = Fixture(config())
        fixture.count = count
        fixture.failure = failure
        destination = BASE / name
        result = fixture.capture(destination)
        return fixture, destination, result

    def test_01_complete_disjoint_bounded_resume_and_compare(self):
        fixture, path, result = self.make('complete')
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(result['count'], 41)
        self.assertGreater(fixture.peak, 1)
        self.assertLessEqual(fixture.peak, 4)
        receipts = [batch.load(item / 'receipt.json')['planned'] for item in sorted((path / 'cases').iterdir())]
        self.assertEqual([receipt['ordinal'] for receipt in receipts], list(range(41)))
        self.assertEqual(len({receipt['case_id'] for receipt in receipts}), 41)
        self.assertTrue(all(receipt['lane'] == receipt['ordinal'] % 4 for receipt in receipts))
        before = fixture.launches
        self.assertEqual(fixture.capture(path), result)
        self.assertEqual(fixture.launches, before)
        compared = batch.compare(path, path, BASE / 'comparison-equal')
        self.assertEqual(compared['status'], 'ok')

    def test_02_failures_stay_failed(self):
        fixture, path, result = self.make('failure', count=9, failure=3)
        self.assertEqual(result['status'], 'error')
        self.assertEqual(result['errors'], 1)
        before = fixture.launches
        self.assertEqual(fixture.capture(path)['errors'], 1)
        self.assertEqual(fixture.launches, before)
        with self.assertRaises(RuntimeError):
            batch.compare(path, path, BASE / 'comparison-failed')

    def test_03_hash_tamper_rejected(self):
        fixture, path, _ = self.make('tamper', count=2)
        (path / 'cases/00000000/result.json').write_text(
            '{"status":"ok","data":{"amount":"999"}}', encoding='utf-8',
        )
        with self.assertRaises(RuntimeError):
            fixture.capture(path)

    def test_04_incomplete_receipt_is_not_retried(self):
        fixture, path, _ = self.make('partial', count=2)
        (path / 'cases/00000001/receipt.json').unlink()
        before = fixture.launches
        with self.assertRaises(RuntimeError):
            fixture.capture(path)
        self.assertEqual(fixture.launches, before)

    def test_05_extra_and_duplicate_membership_rejected(self):
        fixture, path, _ = self.make('extra', count=2)
        (path / 'cases/99999999').mkdir()
        with self.assertRaises(RuntimeError):
            fixture.capture(path)
        duplicate = BASE / 'duplicate.json'
        duplicate.write_text('{"a":1,"a":2}', encoding='utf-8')
        with self.assertRaises(RuntimeError):
            batch.load(duplicate)

    def test_06_identity_date_source_and_paths_fail_closed(self):
        bad = config()
        bad['execution_date'] = '2000-01-01'
        with self.assertRaises(RuntimeError):
            Fixture(bad)
        bad = config()
        bad['source_hashes']['synthetic-source.php'] = '0' * 64
        with self.assertRaises(RuntimeError):
            Fixture(bad)
        with self.assertRaises(RuntimeError):
            batch.outside(batch.ROOT / 'private/no-output.json', [batch.ROOT])
        git = BASE / 'fake-git'
        git.mkdir()
        (git / '.git').write_text('gitdir: synthetic', encoding='utf-8')
        with self.assertRaises(RuntimeError):
            batch.outside(git / 'output', [])
        fixture, path, _ = self.make('identity', count=1)
        with patch.object(batch, 'today', return_value='2000-01-01'):
            with self.assertRaisesRegex(RuntimeError, 'execution_day_changed'):
                batch.validate_capture(path)
        changed = config()
        changed['dataset_id'] = 'b' * 64
        with self.assertRaises(RuntimeError):
            Fixture(changed).capture(path)

    def test_07_metrics_and_index_tamper_rejected(self):
        fixture, path, _ = self.make('metrics', count=1)
        (path / 'cases/00000000/metrics.json').write_text('{"numeric_count":999}', encoding='utf-8')
        with self.assertRaises(RuntimeError):
            fixture.capture(path)
        fixture, path, _ = self.make('index', count=2)
        with (path / 'index.jsonl').open('ab') as index:
            index.write(b'{}\n')
        with self.assertRaises(RuntimeError):
            batch.validate_capture(path)

    def test_08_exact_comparator_no_tolerance_and_identifier_types(self):
        left = BASE / 'decimal-left.json'
        right = BASE / 'decimal-right.json'
        batch.create(left, {'status': 'ok', 'data': {'amount': '1.000000000000000001', 'original_doc_number': '001'}})
        batch.create(right, {'status': 'ok', 'data': {'amount': '1.000000000000000002', 'original_doc_number': '1'}})
        directory = BASE / 'exact-decimal'
        directory.mkdir()
        result = batch.load(batch._invoke({'action': 'compare', 'pair': [str(left), str(right)]}, directory))
        self.assertFalse(result['equal'])
        self.assertEqual(result['numeric_count'], 1)
        self.assertEqual({item['kind'] for item in result['differences']}, {'number', 'value_or_type'})

    def test_09_large_ordinal_partition_is_disjoint(self):
        count = 102459
        lanes = [set(range(lane, count, 4)) for lane in range(4)]
        self.assertEqual(set.union(*lanes), set(range(count)))
        self.assertEqual(sum(map(len, lanes)), count)
        self.assertEqual([len(lane) for lane in lanes], [25615, 25615, 25615, 25614])

    def test_10_fatal_lane_cannot_emit_success(self):
        class Broken(Fixture):
            def invoke(self, action, directory, case=None, cancel_event=None):
                if action == 'case' and case['ordinal'] == 0:
                    raise RuntimeError('synthetic_outer_timeout')
                return super().invoke(action, directory, case, cancel_event)

        driver = Broken(config())
        driver.count = 41
        destination = BASE / 'fatal-stop'
        with self.assertRaisesRegex(RuntimeError, 'synthetic_outer_timeout'):
            driver.capture(destination)
        self.assertLessEqual(driver.launches, 5)
        self.assertFalse((destination / 'summary.json').exists())

    def test_11_persistent_receipts_contain_no_absolute_roots(self):
        _, path, _ = self.make('portable-receipt', count=2)
        raw = b''.join(
            item.read_bytes()
            for item in path.rglob('*')
            if item.is_file()
        )
        self.assertNotIn(str(batch.ROOT).encode('utf-8'), raw)
        self.assertNotIn(str(BASE).encode('utf-8'), raw)
        identity = batch.load(path / 'identity.json')
        self.assertNotIn('code_root', identity)
        self.assertNotIn('database', identity)

    def test_12_comparator_pin_is_exact(self):
        self.assertEqual(batch.digest(batch.COMPARATOR), batch.COMPARATOR_HASH)

    def test_13_explicit_docker_host_is_forwarded_and_pinned(self):
        with patch.dict(os.environ, {'MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST': '1'}):
            driver = batch.Batch({**config(), 'docker_host': 'db'})
            self.assertEqual(driver.request('preflight')['docker_host'], 'db')
            self.assertEqual(driver.request('case', {'id': 'synthetic'})['docker_host'], 'db')
            other = batch.Batch({**config(), 'docker_host': 'test-db'})
            self.assertNotEqual(driver.identity, other.identity)
            self.assertNotIn('docker_host', driver.identity)
            self.assertNotIn('docker_host', batch.Batch(config()).request('preflight'))

    def test_14_docker_host_requires_explicit_opt_in_and_plain_hostname(self):
        with patch.dict(os.environ, {'MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST': '0'}):
            with self.assertRaisesRegex(RuntimeError, 'docker_test_host_not_authorized'):
                batch.Batch({**config(), 'docker_host': 'db'})
        with patch.dict(os.environ, {'MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST': '1'}):
            for host in [None, '', [], 'https://db', 'db/other', 'db:3306', 'db\n', ' db']:
                with self.subTest(host=host), self.assertRaisesRegex(RuntimeError, 'invalid_docker_test_host'):
                    batch.Batch({**config(), 'docker_host': host})
            driver = batch.Batch({**config(), 'docker_host': 'db'})
        with patch.dict(os.environ, {'MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST': '0'}):
            with self.assertRaisesRegex(RuntimeError, 'docker_test_host_not_authorized'):
                driver.check()

    def test_15_docker_capture_can_be_validated_and_compared(self):
        with patch.dict(os.environ, {'MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST': '1'}):
            driver = Fixture({**config(), 'docker_host': 'db'})
            driver.count = 2
            path = BASE / 'docker-capture'
            self.assertEqual(driver.capture(path)['status'], 'ok')
        self.assertEqual(batch.validate_capture(path)[1]['docker_host_sha256'], driver.identity['docker_host_sha256'])
        self.assertEqual(batch.compare(path, path, BASE / 'docker-comparison')['status'], 'ok')
        identity = batch.load(path / 'identity.json')
        identity['docker_host_sha256'] = 'not-a-hash'
        (path / 'identity.json').write_bytes(batch.packed(identity))
        with self.assertRaisesRegex(RuntimeError, 'invalid_capture_identity'):
            batch.validate_capture(path)


    def test_16_missing_timezone_data_fails_early_with_install_hint(self):
        with patch.object(batch, 'ZoneInfo', side_effect=ZoneInfoNotFoundError('Europe/Prague')):
            with self.assertRaisesRegex(RuntimeError, 'regression_timezone_data_required'):
                batch.today()
        requirements = TESTS.parent / 'requirements.txt'
        self.assertEqual(requirements.read_text(encoding='utf-8'), 'tzdata==2026.2\n')
        self.assertIn('requirements.txt', (TESTS.parent / 'BATCH.md').read_text(encoding='utf-8'))

    def test_17_keyboard_interrupt_cancels_owned_lane_and_preserves_foreign_process(self):
        marker = BASE / 'interrupt-owned-pids.json'
        script = BASE / 'interrupt-owned-tree.py'
        script.write_text(
            "import json,os,subprocess,sys,time\nfrom pathlib import Path\n"
            "child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])\n"
            "Path(sys.argv[1]).write_text(json.dumps([os.getpid(),child.pid]))\n"
            "time.sleep(60)\n",
            encoding='utf-8',
        )

        class InterruptFixture(Fixture):
            count = 1

            def invoke(self, action, directory, case=None, cancel_event=None):
                if action == 'preflight':
                    result = directory / 'result.json'
                    batch.create(result, {
                        'status': 'ok',
                        'data': {
                            'cases': {'interrupt': {'ordinal': 0}},
                            'coverage': {'manifest_complete': True, 'complete': False},
                        },
                    })
                    batch.create(directory / 'metrics.json', {'numeric_count': 0})
                    return result
                with (directory / 'stdout.bin').open('xb') as output, (directory / 'stderr.bin').open('xb') as error:
                    arguments = {
                        'cwd': directory,
                        'stdout': output,
                        'stderr': error,
                        'timeout': 3,
                    }
                    if cancel_event is not None:
                        arguments['cancel_event'] = cancel_event
                    batch.run_owned([sys.executable, str(script), str(marker)], **arguments)
                raise AssertionError('interrupt fixture unexpectedly completed')

        def assert_stopped(pid):
            if os.name == 'nt':
                api = ctypes.WinDLL('kernel32', use_last_error=True)
                api.OpenProcess.argtypes = [ctypes.c_ulong, ctypes.c_int, ctypes.c_ulong]
                api.OpenProcess.restype = ctypes.c_void_p
                api.WaitForSingleObject.argtypes = [ctypes.c_void_p, ctypes.c_ulong]
                api.WaitForSingleObject.restype = ctypes.c_ulong
                api.CloseHandle.argtypes = [ctypes.c_void_p]
                handle = api.OpenProcess(0x100000, False, pid)
                if handle:
                    try:
                        self.assertEqual(api.WaitForSingleObject(handle, 1000), 0)
                    finally:
                        api.CloseHandle(handle)
                return
            deadline = time.monotonic() + 2
            while time.monotonic() < deadline:
                try:
                    os.kill(pid, 0)
                except ProcessLookupError:
                    return
                stat = Path(f'/proc/{pid}/stat')
                if stat.is_file() and stat.read_text(encoding='utf-8').split()[2] == 'Z':
                    return
                time.sleep(.01)
            self.fail(f'owned process {pid} remained active')

        foreign = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
        try:
            def interrupt_coordinator(_jobs):
                deadline = time.monotonic() + 5
                while not marker.exists() and time.monotonic() < deadline:
                    time.sleep(.01)
                self.assertTrue(marker.exists())
                raise KeyboardInterrupt()

            started = time.monotonic()
            with patch.object(batch.futures, 'as_completed', side_effect=interrupt_coordinator):
                with self.assertRaises(KeyboardInterrupt):
                    InterruptFixture(config()).capture(BASE / 'interrupt-capture')
            self.assertLess(time.monotonic() - started, 1.5)
            for pid in json.loads(marker.read_text(encoding='utf-8')):
                assert_stopped(pid)
            self.assertIsNone(foreign.poll())
        finally:
            foreign.terminate()
            foreign.wait(timeout=5)


if __name__ == '__main__':
    try:
        unittest.main(verbosity=2)
    finally:
        TEMPORARY.cleanup()
