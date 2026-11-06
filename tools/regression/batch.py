"""Bounded four-lane coordinator for immutable regression captures."""

import concurrent.futures as futures
import datetime as dt
import hashlib
import json
import math
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import sys
import tempfile
import threading
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from owned_process import OwnedProcessCancelled, run_owned


HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent.resolve()
BRIDGE = HERE / 'bridge.php'
COMPARATOR = HERE / 'Comparator.php'
COMPARATOR_HASH = 'd687f0ca0a4b65a4ff78b581aef1165f8a3eaa8081b31161579387c2980da487'
WORKERS = 4
OUTER_SECONDS = 150
FORMAT = 'myucto-regression-batch-2'
CONFIG_FIELDS = {'code_root', 'database', 'as_of', 'execution_date', 'dataset_id', 'source_hashes'}
_PHP = None


def fail(code):
    raise RuntimeError(code)


def digest(path):
    value = hashlib.sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1048576), b''):
            value.update(block)
    return value.hexdigest()


def packed(value):
    return json.dumps(
        value, ensure_ascii=False, separators=(',', ':'), allow_nan=False,
    ).encode('utf-8')


def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            fail('duplicate_json_key')
        result[key] = value
    return result


def load(path):
    return json.loads(
        Path(path).read_bytes(),
        object_pairs_hook=unique_pairs,
        parse_constant=lambda _: fail('nonfinite_json'),
    )


def create(path, value):
    with Path(path).open('xb') as handle:
        handle.write(packed(value))
        handle.flush()
        os.fsync(handle.fileno())


def today():
    try:
        timezone = ZoneInfo('Europe/Prague')
    except ZoneInfoNotFoundError:
        fail('regression_timezone_data_required')
    return dt.datetime.now(timezone).date().isoformat()


def parse_date(value):
    if type(value) is not str:
        fail('invalid_date')
    try:
        parsed = dt.date.fromisoformat(value)
    except ValueError:
        fail('invalid_date')
    if parsed.isoformat() != value:
        fail('invalid_date')
    return value


def is_inside(path, root):
    try:
        path.relative_to(root)
        return True
    except ValueError:
        return False


def outside(path, roots):
    candidate = Path(path)
    if not candidate.is_absolute():
        fail('absolute_output_path_required')
    if candidate.exists():
        resolved = candidate.resolve(strict=True)
    else:
        if candidate.name in ['', '.', '..']:
            fail('existing_output_parent_required')
        try:
            parent = candidate.parent.resolve(strict=True)
        except OSError:
            fail('existing_output_parent_required')
        if not parent.is_dir():
            fail('existing_output_parent_required')
        resolved = parent / candidate.name
    for root in roots:
        canonical = Path(root).resolve(strict=True)
        if is_inside(resolved, canonical):
            fail('output_inside_code_root')
    ancestor = resolved if resolved.is_dir() else resolved.parent
    while True:
        if (ancestor / '.git').exists():
            fail('output_inside_git')
        if ancestor.parent == ancestor:
            break
        ancestor = ancestor.parent
    return resolved


def _relative_source_path(value):
    if type(value) is not str or '\\' in value:
        fail('invalid_source_path')
    parsed = PurePosixPath(value)
    if parsed.is_absolute() or value != parsed.as_posix() or any(part in ['', '.', '..'] for part in parsed.parts):
        fail('invalid_source_path')
    return parsed


def _sha(value, code):
    if type(value) is not str or re.fullmatch(r'[a-f0-9]{64}', value) is None:
        fail(code)
    return value


def _harness_paths():
    return sorted(
        [HERE / 'batch.py', HERE / 'bridge.php', HERE / 'owned_process.py', *HERE.glob('*.php')],
        key=lambda path: path.name,
    )


def harness_pins():
    pins = {f'tools/regression/{path.name}': digest(path) for path in _harness_paths()}
    if pins.get('tools/regression/Comparator.php') != COMPARATOR_HASH:
        fail('comparator_pin')
    return pins


def source_generation(pins):
    return hashlib.sha256(packed(pins)).hexdigest()


def resolve_php():
    global _PHP
    if _PHP is not None:
        return _PHP
    configured = os.environ.get('MYINVOICE_REGRESSION_PHP_BINARY', '').strip()
    candidate = configured or shutil.which('php.exe' if os.name == 'nt' else 'php')
    if not candidate:
        fail('regression_php_binary_required')
    with tempfile.TemporaryFile() as output:
        code = run_owned(
            [candidate, '-r', 'echo PHP_SAPI,PHP_EOL,PHP_VERSION;'],
            cwd=HERE,
            stdout=output,
            stderr=output,
            timeout=10,
        )
        output.seek(0)
        lines = output.read().decode('utf-8', 'strict').splitlines()
    if code != 0 or len(lines) != 2 or lines[0] != 'cli':
        fail('regression_php_cli_required')
    version = lines[1].split('.')
    if len(version) < 2 or not all(part.isdigit() for part in version[:2]) or tuple(map(int, version[:2])) < (8, 5):
        fail('regression_php_version')
    _PHP = candidate
    return candidate


def _clean_invocation(directory):
    for name in ['input.json', 'stdout.bin', 'stderr.bin']:
        path = directory / name
        if path.exists():
            path.unlink()
    native = directory / 'native'
    if native.exists():
        if not native.is_dir() or native.is_symlink():
            fail('invalid_native_runtime')
        shutil.rmtree(native)


def _invoke(request, directory, cancel_event=None):
    create(directory / 'input.json', request)
    code = None
    output_size = error_size = -1
    try:
        with (directory / 'stdout.bin').open('xb') as output, (directory / 'stderr.bin').open('xb') as error:
            code = run_owned(
                [resolve_php(), str(BRIDGE), str(directory / 'input.json')],
                cwd=directory,
                stdout=output,
                stderr=error,
                timeout=OUTER_SECONDS,
                cancel_event=cancel_event,
            )
        output_size = (directory / 'stdout.bin').stat().st_size
        error_size = (directory / 'stderr.bin').stat().st_size
        if code != 0 or output_size != 0 or error_size != 0:
            fail('bridge_failed')
        result = directory / 'result.json'
        load(result)
        return result
    finally:
        _clean_invocation(directory)


def _artifact_receipt(directory, planned):
    directory = Path(directory)
    if {item.name for item in directory.iterdir()} != {'result.json', 'metrics.json', 'receipt.json'}:
        fail('artifact_membership')
    receipt = load(directory / 'receipt.json')
    expected_keys = {'planned', 'sha256', 'metrics_sha256'}
    if set(receipt) != expected_keys or receipt['planned'] != planned:
        fail('receipt_mismatch')
    if receipt['sha256'] != digest(directory / 'result.json'):
        fail('receipt_mismatch')
    if receipt['metrics_sha256'] != digest(directory / 'metrics.json'):
        fail('metrics_hash_mismatch')
    metrics = load(directory / 'metrics.json')
    if set(metrics) != {'numeric_count'} or type(metrics['numeric_count']) is not int or metrics['numeric_count'] < 0:
        fail('invalid_metrics')
    response = load(directory / 'result.json')
    if not isinstance(response, dict) or response.get('status') not in ['ok', 'error']:
        fail('invalid_response_status')
    if response['status'] == 'ok' and 'data' not in response:
        fail('invalid_response_data')
    return receipt, response, metrics['numeric_count']


class Batch:
    def __init__(self, config):
        if not isinstance(config, dict) or not CONFIG_FIELDS.issubset(config) or set(config) - CONFIG_FIELDS - {'docker_host'}:
            fail('configuration_contract')
        for key in CONFIG_FIELDS - {'source_hashes'}:
            if type(config[key]) is not str:
                fail('configuration_contract')
        self.docker_host = None
        if 'docker_host' in config:
            host = config['docker_host']
            if type(host) is not str or re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]*', host) is None:
                fail('invalid_docker_test_host')
            if os.environ.get('MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST') != '1':
                fail('docker_test_host_not_authorized')
            self.docker_host = host
        root_value = Path(config['code_root'])
        if not root_value.is_absolute():
            fail('explicit_code_root_required')
        self.code_root = root_value.resolve(strict=True)
        if not self.code_root.is_dir() or not (self.code_root / 'api/vendor/autoload.php').is_file():
            fail('complete_code_root_required')
        if re.fullmatch(r'[a-z][a-z0-9_]*_[a-f0-9]{8,32}_test', config['database']) is None:
            fail('test_database_required')
        parse_date(config['as_of'])
        parse_date(config['execution_date'])
        if config['execution_date'] != today():
            fail('execution_day_changed')
        _sha(config['dataset_id'], 'dataset_sha256_required')
        if not isinstance(config['source_hashes'], dict) or not config['source_hashes']:
            fail('source_manifest_required')
        pins = {}
        for value, expected in config['source_hashes'].items():
            source = _relative_source_path(value)
            expected = _sha(expected, 'invalid_source_hash')
            path = self.code_root.joinpath(*source.parts)
            if not path.is_file() or not is_inside(path.resolve(strict=True), self.code_root):
                fail('source_missing')
            if digest(path) != expected:
                fail('source_changed')
            pins[source.as_posix()] = expected
        self.source_pins = dict(sorted(pins.items()))
        self.harness_pins = harness_pins()
        self.config = config
        self.roots = [ROOT, self.code_root]
        self.identity = {
            'format': FORMAT,
            'as_of': config['as_of'],
            'execution_date': config['execution_date'],
            'dataset_id': config['dataset_id'],
            'database_sha256': hashlib.sha256(config['database'].encode('utf-8')).hexdigest(),
            'source_hashes': self.source_pins,
            'source_generation': source_generation(self.source_pins),
            'harness_hashes': self.harness_pins,
            'harness_generation': source_generation(self.harness_pins),
            'workers': WORKERS,
            'outer_seconds': OUTER_SECONDS,
        }
        if self.docker_host is not None:
            self.identity['docker_host_sha256'] = hashlib.sha256(self.docker_host.encode('utf-8')).hexdigest()

    def check(self, full=False):
        if self.docker_host is not None and os.environ.get('MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST') != '1':
            fail('docker_test_host_not_authorized')
        if today() != self.config['execution_date']:
            fail('execution_day_changed')
        current_harness = harness_pins()
        if current_harness != self.harness_pins:
            fail('harness_source_changed')
        if full:
            for relative, expected in self.source_pins.items():
                source = PurePosixPath(relative)
                path = self.code_root.joinpath(*source.parts)
                if not path.is_file() or digest(path) != expected:
                    fail('source_changed')

    def request(self, action, case=None):
        request = {
            'code_root': str(self.code_root),
            'database': self.config['database'],
            'as_of': self.config['as_of'],
            'execution_date': self.config['execution_date'],
            'action': action,
        }
        if self.docker_host is not None:
            request['docker_host'] = self.docker_host
        if case is not None:
            request['case'] = case
        return request

    def invoke(self, action, directory, case=None, cancel_event=None):
        result = _invoke(self.request(action, case), directory, cancel_event)
        self.check()
        return result

    def capture(self, destination):
        destination = outside(destination, self.roots)
        self.check(full=True)
        if destination.exists():
            if not destination.is_dir() or packed(load(destination / 'identity.json')) != packed(self.identity):
                fail('resume_identity_mismatch')
        else:
            destination.mkdir(mode=0o700)
            create(destination / 'identity.json', self.identity)
        lock = destination / 'coordinator.lock'
        handle = lock.open('x')
        try:
            return self._capture(destination)
        finally:
            handle.close()
            lock.unlink()

    def _capture(self, destination):
        allowed = {'identity.json', 'coordinator.lock', 'discovery', 'plan.json', 'cases', 'index.jsonl', 'summary.json'}
        if any(item.name not in allowed for item in destination.iterdir()):
            fail('unexpected_capture_artifact')
        discovery = destination / 'discovery'
        preflight_plan = {
            'action': 'preflight',
            'source_generation': self.identity['source_generation'],
            'execution_date': self.config['execution_date'],
        }
        if not discovery.exists():
            discovery.mkdir()
            self.invoke('preflight', discovery)
            create(discovery / 'receipt.json', {
                'planned': preflight_plan,
                'sha256': digest(discovery / 'result.json'),
                'metrics_sha256': digest(discovery / 'metrics.json'),
            })
        _, preflight, _ = _artifact_receipt(discovery, preflight_plan)
        if preflight['status'] != 'ok':
            fail('preflight_failed')
        data = preflight.get('data')
        if not isinstance(data, dict):
            fail('manifest_incomplete')
        manifest = data.get('cases')
        coverage = data.get('coverage')
        if not isinstance(manifest, dict) or not manifest or not isinstance(coverage, dict) or coverage.get('manifest_complete') is not True:
            fail('manifest_incomplete')
        manifest_hash = hashlib.sha256(packed(manifest)).hexdigest()
        plan = {'manifest_sha256': manifest_hash, 'count': len(manifest), 'coverage': coverage}
        plan_file = destination / 'plan.json'
        if plan_file.exists():
            if load(plan_file) != plan:
                fail('plan_changed')
        else:
            create(plan_file, plan)
        cases = destination / 'cases'
        cases.mkdir(exist_ok=True)
        planned_names = {f'{ordinal:08d}' for ordinal in range(len(manifest))}
        if any(item.name not in planned_names or not item.is_dir() for item in cases.iterdir()):
            fail('unexpected_case_artifact')
        stopped = threading.Event()

        def planned(ordinal, lane, case_id, spec):
            return {
                'ordinal': ordinal,
                'lane': lane,
                'case_id': case_id,
                'manifest_sha256': manifest_hash,
                'case_sha256': hashlib.sha256(packed(spec)).hexdigest(),
            }

        def run_lane(lane):
            for ordinal, (case_id, spec) in enumerate(manifest.items()):
                if ordinal % WORKERS != lane:
                    continue
                if stopped.is_set():
                    return
                self.check()
                expected = planned(ordinal, lane, case_id, spec)
                directory = outside(cases / f'{ordinal:08d}', self.roots)
                if directory.exists():
                    _artifact_receipt(directory, expected)
                    continue
                directory.mkdir()
                self.invoke('case', directory, case=spec, cancel_event=stopped)
                create(directory / 'receipt.json', {
                    'planned': expected,
                    'sha256': digest(directory / 'result.json'),
                    'metrics_sha256': digest(directory / 'metrics.json'),
                })
                _artifact_receipt(directory, expected)

        def lane(number):
            try:
                run_lane(number)
            except OwnedProcessCancelled:
                if stopped.is_set():
                    return
                raise
            except BaseException:
                stopped.set()
                raise

        with futures.ThreadPoolExecutor(max_workers=WORKERS) as pool:
            jobs = [pool.submit(lane, number) for number in range(WORKERS)]
            try:
                for job in futures.as_completed(jobs):
                    job.result()
            except BaseException:
                stopped.set()
                for job in jobs:
                    job.cancel()
                raise
        errors = numeric = 0
        temporary_index = destination / ('index-' + os.urandom(6).hex() + '.jsonl')
        with temporary_index.open('xb') as index:
            for ordinal, (case_id, spec) in enumerate(manifest.items()):
                expected = planned(ordinal, ordinal % WORKERS, case_id, spec)
                receipt, response, count = _artifact_receipt(cases / f'{ordinal:08d}', expected)
                errors += response['status'] != 'ok'
                numeric += count
                index.write(packed(receipt) + b'\n')
            index.flush()
            os.fsync(index.fileno())
        self.check(full=True)
        summary = {
            'status': 'ok' if errors == 0 and numeric > 0 else 'error',
            'count': len(manifest),
            'errors': errors,
            'numeric_count': numeric,
            'manifest_sha256': manifest_hash,
            'coverage_complete': errors == 0 and numeric > 0,
            'index_sha256': digest(temporary_index),
        }
        final_index = destination / 'index.jsonl'
        if final_index.exists():
            if digest(final_index) != digest(temporary_index):
                fail('index_changed')
            temporary_index.unlink()
        else:
            temporary_index.rename(final_index)
        summary_file = destination / 'summary.json'
        if summary_file.exists():
            if load(summary_file) != summary:
                fail('summary_changed')
        else:
            create(summary_file, summary)
        return summary


def _validate_identity(identity):
    required = {
        'format', 'as_of', 'execution_date', 'dataset_id', 'database_sha256', 'source_hashes',
        'source_generation', 'harness_hashes', 'harness_generation', 'workers', 'outer_seconds',
    }
    if not isinstance(identity, dict) or not required.issubset(identity) or set(identity) - required - {'docker_host_sha256'} or identity['format'] != FORMAT:
        fail('invalid_capture_identity')
    parse_date(identity['as_of'])
    parse_date(identity['execution_date'])
    if identity['execution_date'] != today():
        fail('execution_day_changed')
    _sha(identity['dataset_id'], 'invalid_capture_identity')
    _sha(identity['database_sha256'], 'invalid_capture_identity')
    if 'docker_host_sha256' in identity:
        _sha(identity['docker_host_sha256'], 'invalid_capture_identity')
    if identity['workers'] != WORKERS or identity['outer_seconds'] != OUTER_SECONDS:
        fail('invalid_capture_identity')
    for field in ['source_hashes', 'harness_hashes']:
        if not isinstance(identity[field], dict) or not identity[field]:
            fail('invalid_capture_identity')
        for path, value in identity[field].items():
            _relative_source_path(path)
            _sha(value, 'invalid_capture_identity')
    if source_generation(identity['source_hashes']) != identity['source_generation']:
        fail('source_generation_changed')
    current = harness_pins()
    if current != identity['harness_hashes'] or source_generation(current) != identity['harness_generation']:
        fail('harness_source_changed')


def validate_capture(path):
    path = outside(path, [ROOT])
    if not path.is_dir():
        fail('capture_directory_required')
    if {item.name for item in path.iterdir()} != {
        'identity.json', 'discovery', 'plan.json', 'cases', 'index.jsonl', 'summary.json',
    }:
        fail('capture_membership')
    identity = load(path / 'identity.json')
    _validate_identity(identity)
    preflight_plan = {
        'action': 'preflight',
        'source_generation': identity['source_generation'],
        'execution_date': identity['execution_date'],
    }
    _, preflight, _ = _artifact_receipt(path / 'discovery', preflight_plan)
    if preflight['status'] != 'ok':
        fail('preflight_failed')
    data = preflight.get('data')
    if not isinstance(data, dict) or not isinstance(data.get('cases'), dict) or not data['cases']:
        fail('manifest_incomplete')
    manifest = data['cases']
    coverage = data.get('coverage')
    if not isinstance(coverage, dict) or coverage.get('manifest_complete') is not True:
        fail('manifest_incomplete')
    manifest_hash = hashlib.sha256(packed(manifest)).hexdigest()
    plan = load(path / 'plan.json')
    if plan != {'manifest_sha256': manifest_hash, 'count': len(manifest), 'coverage': coverage}:
        fail('invalid_plan')
    summary = load(path / 'summary.json')
    if not isinstance(summary, dict) or summary.get('status') != 'ok' or summary.get('coverage_complete') is not True:
        fail('capture_failed')
    expected_names = {f'{ordinal:08d}' for ordinal in range(len(manifest))}
    cases = path / 'cases'
    if not cases.is_dir() or {item.name for item in cases.iterdir()} != expected_names:
        fail('case_membership')
    errors = numeric = 0
    with (path / 'index.jsonl').open('rb') as index:
        for ordinal, (case_id, spec) in enumerate(manifest.items()):
            expected = {
                'ordinal': ordinal,
                'lane': ordinal % WORKERS,
                'case_id': case_id,
                'manifest_sha256': manifest_hash,
                'case_sha256': hashlib.sha256(packed(spec)).hexdigest(),
            }
            receipt, response, count = _artifact_receipt(cases / f'{ordinal:08d}', expected)
            if index.readline() != packed(receipt) + b'\n':
                fail('index_membership')
            errors += response['status'] != 'ok'
            numeric += count
        if index.read(1):
            fail('extra_index_membership')
    expected_summary = {
        'status': 'ok' if errors == 0 and numeric > 0 else 'error',
        'count': len(manifest),
        'errors': errors,
        'numeric_count': numeric,
        'manifest_sha256': manifest_hash,
        'coverage_complete': errors == 0 and numeric > 0,
        'index_sha256': digest(path / 'index.jsonl'),
    }
    if summary != expected_summary:
        fail('capture_summary_changed')
    return path, identity, manifest, plan, summary


def compare(left_path, right_path, destination):
    left_path, left_identity, left_manifest, left_plan, left_summary = validate_capture(left_path)
    right_path, right_identity, right_manifest, right_plan, right_summary = validate_capture(right_path)
    for field in ['dataset_id', 'as_of', 'execution_date']:
        if left_identity[field] != right_identity[field]:
            fail('capture_identity_' + field)
    if packed(left_manifest) != packed(right_manifest) or packed(left_plan) != packed(right_plan):
        fail('capture_manifest_or_coverage')
    destination = outside(destination, [ROOT])
    if destination.exists():
        fail('comparison_output_exists')
    destination.mkdir(mode=0o700)
    errors = different = numeric = 0
    for ordinal in range(len(left_manifest)):
        directory = destination / f'{ordinal:08d}'
        directory.mkdir()
        request = {
            'action': 'compare',
            'pair': [
                str(left_path / 'cases' / f'{ordinal:08d}' / 'result.json'),
                str(right_path / 'cases' / f'{ordinal:08d}' / 'result.json'),
            ],
        }
        result = load(_invoke(request, directory))
        if set(directory.iterdir()) != {directory / 'result.json'}:
            fail('comparison_artifact_membership')
        if not isinstance(result, dict) or type(result.get('equal')) is not bool:
            fail('invalid_comparator_result')
        if type(result.get('numeric_count')) is not int or result['numeric_count'] < 0:
            fail('invalid_comparator_result')
        if 'errors' in result and not isinstance(result['errors'], list):
            fail('invalid_comparator_result')
        if not isinstance(result.get('differences'), list):
            fail('invalid_comparator_result')
        errors += bool(result.get('errors'))
        different += not result['equal']
        numeric += result['numeric_count']
    if numeric != left_summary['numeric_count'] or numeric != right_summary['numeric_count']:
        errors += 1
    result = {
        'status': 'ok' if errors == 0 and different == 0 and numeric > 0 else 'error',
        'cases': len(left_manifest),
        'numeric_count': numeric,
        'errors': errors,
        'different_cases': different,
        'manifest_sha256': left_plan['manifest_sha256'],
    }
    create(destination / 'summary.json', result)
    return result


def main():
    if len(sys.argv) == 4 and sys.argv[1] == 'capture':
        result = Batch(load(sys.argv[2])).capture(sys.argv[3])
    elif len(sys.argv) == 5 and sys.argv[1] == 'compare':
        result = compare(*sys.argv[2:])
    else:
        fail('usage_capture_config_destination_or_compare_left_right_destination')
    print(json.dumps(result, separators=(',', ':')))
    return 0 if result['status'] == 'ok' else 2


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as error:
        if str(error) == 'regression_timezone_data_required':
            print('Chybí IANA časová data. Nainstalujte tools/regression/requirements.txt.', file=sys.stderr)
        print('{"status":"error","error":"regression_batch_failed"}')
        raise SystemExit(2)
