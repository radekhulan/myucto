import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


HARNESS = Path(__file__).resolve().parents[1]


def php_binary():
    configured = os.environ.get('MYINVOICE_REGRESSION_PHP_BINARY', '').strip()
    candidate = configured or shutil.which('php.exe' if os.name == 'nt' else 'php')
    if not candidate:
        raise RuntimeError('regression_php_binary_required')
    return candidate


class RunnerExitTests(unittest.TestCase):
    def test_worker_status_and_exit_must_both_allow_success(self):
        with tempfile.TemporaryDirectory(prefix='myucto-runner-exit-synthetic-') as temporary:
            directory = Path(temporary)
            harness = directory / 'harness'
            harness.mkdir()
            for name in ['Safety.php', 'Network.php', 'Runner.php']:
                shutil.copyfile(HARNESS / name, harness / name)
            (harness / 'worker.php').write_text("""<?php
$request=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$case=$request['case']['kind'];
if($case==='missing')exit(2);
$result=in_array($case,['error_zero','error_nonzero'],true)?['status'=>'error','error'=>'synthetic_failure']:['status'=>'ok','data'=>['amount'=>'42.00']];
file_put_contents($request['response'],json_encode($result,JSON_THROW_ON_ERROR));
if($case==='output')echo 'synthetic unexpected stdout';
exit(in_array($case,['ok_nonzero','error_nonzero'],true)?7:0);
""", encoding='utf-8')
            probe = directory / 'probe.php'
            probe.write_text("""<?php
foreach(['Safety','Network','Runner'] as $name)require __DIR__.'/harness/'.$name.'.php';
$result=MyUctoRegression\\Runner::child(['action'=>'case','code_root'=>__DIR__,'case'=>['kind'=>$argv[1]]],__DIR__,$argv[1]);
echo json_encode($result,JSON_THROW_ON_ERROR);
""", encoding='utf-8')
            for scenario in ['ok', 'error_zero', 'error_nonzero', 'missing', 'output', 'ok_nonzero']:
                with self.subTest(scenario=scenario):
                    result = subprocess.run(
                        [php_binary(), str(probe), scenario], capture_output=True, timeout=10, check=False,
                    )
                    self.assertEqual(result.returncode, 0)
                    self.assertEqual(result.stderr, b'')
                    response = json.loads(result.stdout)
                    self.assertEqual(response['status'], 'ok' if scenario == 'ok' else 'error')
                    if scenario == 'ok_nonzero':
                        self.assertEqual(response['error'], 'worker_exit_failed')


if __name__ == '__main__':
    unittest.main(verbosity=2)
