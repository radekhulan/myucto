import ctypes
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

TESTS = Path(__file__).resolve().parent
sys.path.insert(0, str(TESTS.parent))
import batch
import owned_process


class OwnedProcessTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path(tempfile.mkdtemp(prefix='myucto-watchdog-synthetic-'))

    def command(self):
        marker = self.directory / 'pids.json'
        script = self.directory / 'tree.py'
        script.write_text("import json,os,subprocess,sys,time\nfrom pathlib import Path\n"
                          "child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])\n"
                          "Path(sys.argv[1]).write_text(json.dumps([os.getpid(),child.pid]))\ntime.sleep(60)\n")
        return [sys.executable, str(script), str(marker)], marker

    def invoke(self, command, timeout=5):
        with (self.directory/'out').open('ab') as out, (self.directory/'err').open('ab') as err:
            return owned_process.run_owned(command, cwd=self.directory, stdout=out, stderr=err, timeout=timeout)

    def assert_stopped(self, marker):
        import json
        self.assertTrue(marker.is_file())
        pids=json.loads(marker.read_text())
        self.assertEqual(len(pids),2)
        for pid in pids:
            if os.name == 'nt':
                api=ctypes.WinDLL('kernel32',use_last_error=True)
                api.OpenProcess.argtypes=[ctypes.c_ulong,ctypes.c_int,ctypes.c_ulong]
                api.OpenProcess.restype=ctypes.c_void_p
                api.WaitForSingleObject.argtypes=[ctypes.c_void_p,ctypes.c_ulong]
                api.WaitForSingleObject.restype=ctypes.c_ulong
                api.CloseHandle.argtypes=[ctypes.c_void_p]
                handle=api.OpenProcess(0x100000,False,pid)
                if handle:
                    try:self.assertEqual(api.WaitForSingleObject(handle,1000),0)
                    finally:api.CloseHandle(handle)
            else:
                deadline=time.monotonic()+2
                while time.monotonic()<deadline:
                    try:os.kill(pid,0)
                    except ProcessLookupError:break
                    stat=Path(f'/proc/{pid}/stat')
                    if stat.is_file() and stat.read_text().split()[2]=='Z':break
                    time.sleep(.01)
                else:self.fail(f'owned process {pid} remained active')

    def test_exit_status_is_preserved(self):
        self.assertEqual(self.invoke([sys.executable,'-c','raise SystemExit(7)']),7)
        self.assertEqual(self.invoke([sys.executable,'-c','pass']),0)

    def test_timeout_kills_owned_descendants_and_preserves_other_process(self):
        command,marker=self.command()
        unrelated=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])
        try:
            start=time.monotonic()
            with self.assertRaisesRegex(owned_process.OwnedProcessTimeout,'owned_process_timeout'):
                self.invoke(command,timeout=1)
            self.assertLess(time.monotonic()-start,6)
            self.assert_stopped(marker)
            self.assertIsNone(unrelated.poll())
        finally:
            unrelated.terminate();unrelated.wait(timeout=5)

    @unittest.skipIf(os.name=='nt','POSIX process-group ownership')
    def test_successful_leader_exit_kills_surviving_descendants(self):
        marker=self.directory/'leader-exit-pids.json'
        script=self.directory/'leader-exit.py'
        script.write_text("import json,os,subprocess,sys,time\nfrom pathlib import Path\n"
                          "child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])\n"
                          "Path(sys.argv[1]).write_text(json.dumps([os.getpid(),child.pid]))\n")
        self.assertEqual(self.invoke([sys.executable,str(script),str(marker)]),0)
        self.assert_stopped(marker)

    @unittest.skipIf(os.name=='nt','POSIX process-group ownership')
    def test_exception_after_leader_exit_kills_surviving_descendants(self):
        marker=self.directory/'leader-exit-error-pids.json'
        script=self.directory/'leader-exit-error.py'
        script.write_text("import json,os,subprocess,sys\nfrom pathlib import Path\n"
                          "child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])\n"
                          "Path(sys.argv[1]).write_text(json.dumps([os.getpid(),child.pid]))\n")
        def interrupt(*_):
            deadline=time.monotonic()+5
            while not marker.exists() and time.monotonic()<deadline:time.sleep(.01)
            self.assertTrue(marker.exists())
            time.sleep(.05)
            raise KeyboardInterrupt()
        with patch('select.select',side_effect=interrupt):
            with self.assertRaises(KeyboardInterrupt):
                self.invoke([sys.executable,str(script),str(marker)])
        self.assert_stopped(marker)

    @unittest.skipUnless(os.name=='nt','Windows job ownership semantics')
    def test_coordinator_crash_closes_job_and_kills_descendants(self):
        command,marker=self.command()
        controller=self.directory/'controller.py'
        controller.write_text("import os,sys,threading,time\nfrom pathlib import Path\n"
                              "sys.path.insert(0,sys.argv[1])\nfrom owned_process import run_owned\n"
                              "def crash():\n while not Path(sys.argv[2]).exists():time.sleep(.01)\n os._exit(19)\n"
                              "threading.Thread(target=crash,daemon=True).start()\n"
                              "with open(os.devnull,'wb') as output:run_owned(sys.argv[3:],cwd=Path(sys.argv[2]).parent,stdout=output,stderr=output,timeout=10)\n")
        process=subprocess.Popen([sys.executable,str(controller),str(Path(batch.__file__).parent),str(marker),*command],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        out,err=process.communicate(timeout=10)
        self.assertEqual(process.returncode,19)
        self.assertEqual((out,err),(b'',b''))
        self.assert_stopped(marker)

    @unittest.skipUnless(os.name=='nt','Windows job ownership handshake')
    def test_failed_assignment_never_launches_command(self):
        marker=self.directory/'unlaunched'
        with patch.object(owned_process._WindowsJob,'assign',side_effect=RuntimeError('owned_job_assignment_failed')):
            with self.assertRaisesRegex(RuntimeError,'owned_job_assignment_failed'):
                self.invoke([sys.executable,'-c',f'from pathlib import Path;Path({str(marker)!r}).touch()'])
        self.assertFalse(marker.exists())


    def test_event_cancellation_cleans_tree_and_preserves_other_process(self):
        command, marker = self.command()
        cancelled = __import__('threading').Event()
        unrelated = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
        def cancel_after_start():
            deadline = time.monotonic() + 5
            while not marker.exists() and time.monotonic() < deadline:
                time.sleep(.01)
            self.assertTrue(marker.exists())
            cancelled.set()

        trigger = __import__('threading').Thread(target=cancel_after_start, daemon=True)
        try:
            trigger.start()
            started = time.monotonic()
            with self.assertRaisesRegex(owned_process.OwnedProcessCancelled, 'owned_process_cancelled'):
                with (self.directory / 'cancel-out').open('ab') as out, (self.directory / 'cancel-err').open('ab') as err:
                    owned_process.run_owned(command, cwd=self.directory, stdout=out, stderr=err,
                                            timeout=10, cancel_event=cancelled)
            self.assertLess(time.monotonic() - started, 1.5)
            self.assert_stopped(marker)
            self.assertIsNone(unrelated.poll())
        finally:
            unrelated.terminate()
            unrelated.wait(timeout=5)

    @unittest.skipUnless(os.name=='nt','Windows job cancellation')
    def test_cancellation_cleans_tree_and_is_not_success(self):
        command,marker=self.command()
        original=subprocess.Popen.wait
        first=True
        def interrupt(process,*args,**kwargs):
            nonlocal first
            if first:
                first=False
                deadline=time.monotonic()+5
                while not marker.exists() and time.monotonic()<deadline:time.sleep(.01)
                raise KeyboardInterrupt()
            return original(process,*args,**kwargs)
        with patch.object(subprocess.Popen,'wait',interrupt):
            with self.assertRaises(KeyboardInterrupt):self.invoke(command)
        self.assert_stopped(marker)

    def test_batch_rejects_bridge_nonzero_without_response(self):
        code_root=self.directory/'code-root'
        (code_root/'api/vendor').mkdir(parents=True)
        (code_root/'api/vendor/autoload.php').write_text('<?php',encoding='utf-8')
        (code_root/'source.php').write_text('<?php return 500;',encoding='utf-8')
        config={'code_root':str(code_root),'database':'synthetic_12345678_test','as_of':'2026-10-17',
                'execution_date':batch.today(),'dataset_id':'a'*64,
                'source_hashes':{'source.php':batch.digest(code_root/'source.php')}}
        driver=batch.Batch(config)
        batch.resolve_php()
        with patch.object(batch,'run_owned',return_value=7):
            with self.assertRaisesRegex(RuntimeError,'bridge_failed'):
                invocation=self.directory/'invocation'
                invocation.mkdir()
                driver.invoke('case',invocation,case={'kind':'synthetic','args':[1]})
        self.assertFalse((invocation/'receipt.json').exists())


if __name__=='__main__':
    unittest.main(verbosity=2)
